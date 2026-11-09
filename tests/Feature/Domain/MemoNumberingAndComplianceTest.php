<?php

use App\Enums\ActivityStatus;
use App\Enums\ReviewOffice;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Activities\Show as ActivityShow;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\ReportReceipt;
use App\Services\ActivityLifecycle;
use App\Services\AppSettings;
use App\Services\MemoDocument;
use App\Services\MemoNumbering;
use App\Services\ReportCompliance;
use App\Support\FinancialYear;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hr = Department::query()->where('name', 'Human Resources')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
    $this->fy = FinancialYear::for(today())->shortLabel();
});

/** A memo of the department that is complete, with its report due date passed and no report in. */
function overdueActivity(Department $department, array $attributes = []): Activity
{
    $activity = departmentMemo(headOfDepartment($department));
    $activity->forceFill(array_merge(['status' => ActivityStatus::Completed, 'report_due_on' => today()->subDays(3)], $attributes))->saveQuietly();

    return $activity;
}

describe('automatic memo references', function () {
    it('numbers a memo on first submission from its department sequence and keeps the number through a return', function () {
        $memo = departmentMemo($this->hod);
        expect($memo->memo_number)->toBeNull();

        $this->lifecycle->submitForReview($memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);
        $number = $memo->fresh()->memo_number;
        expect($number)->toBe("NG-CDF BOARD/ICT/001/{$this->fy}");

        $this->lifecycle->returnForCorrection($memo->fresh(), 'Add the venue cost.', assistant());
        $this->lifecycle->submitForReview($memo->fresh(), $this->hod);

        expect($memo->fresh()->memo_number)->toBe($number)
            ->and(DB::table('memo_sequences')->where('scope_id', $this->ict->id)->value('last_number'))->toBe(1);
    });

    it('keeps independent sequences per department', function () {
        $hrHead = headOfDepartment($this->hr);
        $a = departmentMemo($this->hod);
        $b = departmentMemo($hrHead);
        $c = departmentMemo($this->hod);

        $this->lifecycle->submitForReview($a, $this->hod);
        $this->lifecycle->submitDirectToCeo($b, $hrHead);
        $this->lifecycle->submitForReview($c, $this->hod);

        expect($a->fresh()->memo_number)->toBe("NG-CDF BOARD/ICT/001/{$this->fy}")
            ->and($c->fresh()->memo_number)->toBe("NG-CDF BOARD/ICT/002/{$this->fy}")
            ->and($b->fresh()->memo_number)->toBe("NG-CDF BOARD/HR/001/{$this->fy}");
    });

    it('never assigns a second number to a memo that already has one', function () {
        $memo = departmentMemo($this->hod);
        $numbering = app(MemoNumbering::class);

        $first = $numbering->assign($memo);
        expect($numbering->assign($memo->fresh()))->toBe($first)
            ->and(DB::table('memo_sequences')->where('scope_id', $this->ict->id)->value('last_number'))->toBe(1);
    });

    it('rejects a duplicate reference at the database', function () {
        $a = departmentMemo($this->hod);
        $b = departmentMemo($this->hod);
        $a->forceFill(['memo_number' => 'NG-CDF BOARD/ICT/001/2026-27'])->saveQuietly();

        expect(fn () => $b->forceFill(['memo_number' => 'NG-CDF BOARD/ICT/001/2026-27'])->saveQuietly())
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('follows the configured format', function () {
        app(AppSettings::class)->set('memo_reference_format', 'NGCDF/{unit}/{fy}/{seq}');
        $memo = departmentMemo($this->hod);

        $this->lifecycle->submitForReview($memo, $this->hod);

        expect($memo->fresh()->memo_number)->toBe("NGCDF/ICT/{$this->fy}/001");
    });

    it('does not let the Head type the reference any more, and prints the given number', function () {
        $memo = departmentMemo($this->hod, ['source_reference' => null]);
        expect(collect(app(MemoDocument::class)->blockingIssues($memo))->implode(' '))->not->toContain('REF NO');

        $this->lifecycle->submitForReview($memo, $this->hod);
        expect($memo->fresh()->memoReference())->toBe("NG-CDF BOARD/ICT/001/{$this->fy}");
    });
});

describe('report compliance before submission', function () {
    it('blocks the first submission while the department has an overdue report, on every route', function () {
        overdueActivity($this->ict);
        $memo = departmentMemo($this->hod);

        expect(fn () => $this->lifecycle->submitForReview($memo, $this->hod))->toThrow(ActivityWorkflowException::class, 'overdue activity report');
        expect(fn () => $this->lifecycle->submitDirectToCeo($memo->fresh(), $this->hod))->toThrow(ActivityWorkflowException::class, 'overdue activity report');

        expect($memo->fresh())->status->toBe(ActivityStatus::Draft)->memo_number->toBeNull();
    });

    it('blocks the Office of the CEO forwarding a department draft it prepared', function () {
        overdueActivity($this->ict);
        $cos = chiefOfStaff();
        $memo = capturedActivity($cos, ['organising_department_id' => $this->ict->id]);

        expect(fn () => $this->lifecycle->forward($memo, $cos))->toThrow(ActivityWorkflowException::class, 'overdue activity report');
    });

    it('checks departments independently and ignores reports not yet due', function () {
        overdueActivity($this->hr);
        overdueActivity($this->ict, ['report_due_on' => today()->addDays(2)]);
        overdueActivity($this->ict, ['report_due_on' => today()]);
        $memo = departmentMemo($this->hod);

        $this->lifecycle->submitForReview($memo, $this->hod);

        expect($memo->fresh()->status)->toBe(ActivityStatus::UnderReview);
    });

    it('counts a returned report as outstanding and a submitted one as in', function () {
        $late = overdueActivity($this->ict);
        $receipt = ReportReceipt::create(['activity_id' => $late->id, 'received_on' => today(), 'outputs_achieved' => 'Done']);
        $receipt->forceFill(['status' => ReportReceipt::STATUS_RETURNED])->save();
        $memo = departmentMemo($this->hod);

        expect(app(ReportCompliance::class)->describe($memo))->toHaveCount(1)
            ->and(app(ReportCompliance::class)->describe($memo)[0]['status'])->toBe('Report returned for correction');

        $receipt->forceFill(['status' => ReportReceipt::STATUS_SUBMITTED])->save();
        $this->lifecycle->submitForReview($memo, $this->hod);

        expect($memo->fresh()->status)->toBe(ActivityStatus::UnderReview);
    });

    it('does not hold back a resubmission of a memo already registered', function () {
        $memo = departmentMemo($this->hod);
        $this->lifecycle->submitForReview($memo, $this->hod);
        $this->lifecycle->returnForCorrection($memo->fresh(), 'Fix the dates.', assistant());
        overdueActivity($this->ict);

        $this->lifecycle->submitForReview($memo->fresh(), $this->hod);

        expect($memo->fresh()->status)->toBe(ActivityStatus::UnderReview);
    });

    it('lets only the CEO allow one memo through, with a recorded and audited reason', function () {
        overdueActivity($this->ict);
        $memo = departmentMemo($this->hod);
        $compliance = app(ReportCompliance::class);

        expect(fn () => $compliance->override($memo, 'Urgent', $this->hod))->toThrow(AuthorizationException::class)
            ->and(fn () => $compliance->override($memo, 'Urgent', chiefOfStaff()))->toThrow(AuthorizationException::class)
            ->and(fn () => $compliance->override($memo, '  ', ceo()))->toThrow(ActivityWorkflowException::class);

        $ceo = ceo();
        $this->actingAs($ceo);
        $compliance->override($memo, 'Presidential directive, cannot wait.', $ceo);

        expect($memo->fresh())->compliance_override_by->toBe($ceo->id)->compliance_override_reason->toBe('Presidential directive, cannot wait.')
            ->and(AuditLog::query()->where('event', 'compliance_override')->exists())->toBeTrue();

        $this->lifecycle->submitForReview($memo->fresh(), $this->hod);
        expect($memo->fresh()->status)->toBe(ActivityStatus::UnderReview);

        // Another memo of the same department is still held back.
        expect(fn () => $this->lifecycle->submitForReview(departmentMemo($this->hod), $this->hod))->toThrow(ActivityWorkflowException::class);
    });

    it('lets the CEO allow a memo from its page', function () {
        overdueActivity($this->ict);
        $memo = departmentMemo($this->hod);

        Livewire::actingAs(ceo())->test(ActivityShow::class, ['activity' => $memo])
            ->assertSee('overdue activity report')
            ->set('reason', 'Board directive')
            ->call('overrideCompliance')
            ->assertHasNoErrors();

        expect($memo->fresh()->compliance_override_at)->not->toBeNull();
    });
});
