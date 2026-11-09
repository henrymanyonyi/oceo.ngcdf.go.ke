<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Models\ReportReceipt;
use App\Models\User;
use App\Support\WorkingDays;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Report compliance before a new memo is submitted (November 2026). A department with an
 * activity report past its due date (completed, due date passed, no report in or the report
 * returned for correction: the rule of Activity::isReportOverdue) cannot formally submit a new
 * memo until the report is in. Drafting and saving are never blocked, and only the first
 * submission of a memo is checked (a returned memo is resubmitted freely). The CEO alone may
 * let one memo through with a reason, recorded on the memo and in the audit trail.
 */
class ReportCompliance
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * The department's activities whose reports are overdue, oldest due date first.
     *
     * @return Collection<int, Activity>
     */
    public function outstandingFor(Activity $memo): Collection
    {
        if (! $memo->organising_department_id) {
            return collect();
        }

        return Activity::query()
            ->where('organising_department_id', $memo->organising_department_id)
            ->whereKeyNot($memo->id)
            ->status(ActivityStatus::Completed)
            ->whereNotNull('report_due_on')
            ->whereDate('report_due_on', '<', today())
            ->where(fn ($q) => $q->whereDoesntHave('report')->orWhereHas('report', fn ($r) => $r->where('status', ReportReceipt::STATUS_RETURNED)))
            ->with(['organisingDepartment', 'report'])
            ->orderBy('report_due_on')
            ->get();
    }

    /**
     * The overdue reports as the submit popup and the error list show them.
     *
     * @return list<array{id: int, title: string, reference: string, department: ?string, start: string, end: string, due: string, days_overdue: int, status: string}>
     */
    public function describe(Activity $memo): array
    {
        return $this->outstandingFor($memo)->map(fn (Activity $a) => [
            'id' => $a->id,
            'title' => $a->title,
            'reference' => $a->memoReference() ?? $a->reference,
            'department' => $a->organisingDepartment?->name,
            'start' => $a->start_date->format('d M Y'),
            'end' => $a->end_date->format('d M Y'),
            'due' => $a->report_due_on->format('d M Y'),
            'days_overdue' => WorkingDays::between($a->report_due_on, today()),
            'status' => $a->report?->isReturned() ? 'Report returned for correction' : 'Report not submitted',
        ])->values()->all();
    }

    /** Whether this memo's first formal submission is subject to the check. */
    public function applies(Activity $memo): bool
    {
        return in_array($memo->status, [ActivityStatus::Draft, ActivityStatus::WithHod], true)
            && $memo->memo_number === null
            && $memo->compliance_override_at === null;
    }

    public function isBlocked(Activity $memo): bool
    {
        return $this->applies($memo) && $this->outstandingFor($memo)->isNotEmpty();
    }

    /** Called by ActivityLifecycle at every first submission, whatever the route or the screen. */
    public function assertMaySubmit(Activity $memo): void
    {
        if (! $this->applies($memo)) {
            return;
        }

        $outstanding = $this->outstandingFor($memo);
        if ($outstanding->isEmpty()) {
            return;
        }

        throw new ActivityWorkflowException(sprintf(
            'This memo cannot be submitted yet: the department has %d overdue activity %s (%s). Submit %s first; the memo stays saved as a draft.',
            $outstanding->count(),
            $outstanding->count() === 1 ? 'report' : 'reports',
            $outstanding->map(fn (Activity $a) => ($a->memoReference() ?? $a->reference).', due '.$a->report_due_on->format('d M Y'))->implode('; '),
            $outstanding->count() === 1 ? 'that report' : 'those reports',
        ));
    }

    /** The CEO lets this one memo be submitted despite overdue reports. Reason required; recorded and audited. */
    public function override(Activity $memo, string $reason, User $user): bool
    {
        if (! $user->can('compliance.override')) {
            throw new AuthorizationException('Only the CEO can allow a memo through while reports are overdue.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ActivityWorkflowException('Give the reason for allowing this memo through.');
        }
        if (! $this->applies($memo)) {
            throw new ActivityWorkflowException('This memo is not held back by overdue reports.');
        }

        DB::transaction(function () use ($memo, $reason, $user) {
            Activity::query()->whereKey($memo->id)->lockForUpdate()->first();
            $outstanding = $this->outstandingFor($memo)->map(fn (Activity $a) => $a->reference)->values()->all();
            $memo->forceFill(['compliance_override_by' => $user->id, 'compliance_override_reason' => $reason, 'compliance_override_at' => now()])->saveQuietly();
            $this->audit->record($memo, 'compliance_override', ['overdue_reports' => $outstanding], ['reason' => $reason]);
        });

        return true;
    }
}
