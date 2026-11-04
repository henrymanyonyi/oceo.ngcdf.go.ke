<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\CostMethod;
use App\Enums\ParticipantRole;
use App\Enums\ParticipationStatus;
use App\Enums\RateUnit;
use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Models\ActivityAmendment;
use App\Models\ActivityCost;
use App\Models\ActivityLocation;
use App\Models\ActivityParticipant;
use App\Models\CostCategory;
use App\Models\DsaRate;
use App\Models\Staff;
use App\Models\User;
use App\Support\DsaPeriod;
use App\Support\Money;
use App\Support\WorkingDays;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creating and changing an activity's details, locations, participants and
 * planned costs. Before submission everything is freely editable; after
 * submission or approval, changes to people and planned cost are recorded as
 * amendments and may send the activity back to the CEO (PT-07, BR-10).
 */
class ActivityEditor
{
    public function __construct(
        private ActivityLifecycle $lifecycle,
        private ActivityCosting $costing,
        private DsaCalculator $dsa,
        private ParticipantChecks $checks,
        private AppSettings $settings,
    ) {}

    /** The memo an officer is changing through a Head of Department's delegated link, inside asPreparer(). */
    private ?int $preparing = null;

    /**
     * Runs the callback as the officer preparing this one memo through the Head of Department's delegated
     * link (App\Services\DelegatedMemos): the usual rules for that Head's department apply to this memo
     * only, and every change is audited under the officer's name. Nothing else is opened to them.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function asPreparer(Activity $activity, string $preparerName, Closure $callback): mixed
    {
        $previous = $this->preparing;
        $this->preparing = $activity->id;

        try {
            return AuditLogger::actingAs($preparerName, $callback);
        } finally {
            $this->preparing = $previous;
        }
    }

    /**
     * PL-01 / PL-02 / CD-05 / CD-06.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $user, ?string $actorName = null): Activity
    {
        if ($user && ! $user->can('activities.manage')) {
            // A Head of Department prepares memos for their own department, as the responsible head.
            $this->authorize($user, 'memos.originate');

            if (! $user->department_id) {
                throw new AuthorizationException('Your account is not linked to a department. Ask the System Administrator.');
            }

            $attributes = array_merge($attributes, [
                'organising_department_id' => $user->department_id,
                'responsible_hod_id' => $user->id,
                'responsible_hod_name' => $user->name,
            ]);
        }

        return DB::transaction(function () use ($attributes, $user, $actorName) {
            $activity = new Activity($this->withDates($attributes));
            $activity->created_by = $user?->id;
            $activity->forceFill($this->flags($activity->start_date, today()));
            $activity->save();

            $this->lifecycle->begin($activity, $user, $actorName);

            return $activity;
        });
    }

    /**
     * Edit the details of an activity. Date changes after submission go
     * through ActivityLifecycle::postpone()/extend() instead.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Activity $activity, array $attributes, User $user): Activity
    {
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);

        if (! $user->can('activities.manage')) {
            // The department and responsible head stay as they are.
            unset($attributes['organising_department_id'], $attributes['responsible_hod_id'], $attributes['responsible_hod_name']);
        }

        $attributes = $this->withDates($attributes + ['start_date' => $activity->start_date, 'days' => $activity->days]);

        if (! $activity->status->isFreelyEditable()
            && ($activity->start_date->toDateString() !== Carbon::parse($attributes['start_date'])->toDateString() || (int) $attributes['days'] !== $activity->days)) {
            throw new ActivityWorkflowException('Use Postpone or Extend to change the dates of a submitted or approved activity.');
        }

        DB::transaction(function () use ($activity, $attributes) {
            $datesChanged = $activity->start_date->toDateString() !== Carbon::parse($attributes['start_date'])->toDateString()
                || (int) $attributes['days'] !== $activity->days;

            $activity->fill($attributes);
            if ($activity->status->isFreelyEditable()) {
                $activity->forceFill($this->flags($activity->start_date, $activity->created_at ?? today()));
            }
            $activity->save();

            if ($datesChanged) {
                $this->dsa->recalculate($activity);
            }
        });

        return $activity;
    }

    /** @param  array{region_id?: ?int, county_id?: ?int, constituency_id?: ?int, venue?: ?string}  $data */
    public function addLocation(Activity $activity, array $data, User $user): ActivityLocation
    {
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);

        if (blank($data['region_id'] ?? null) && blank($data['county_id'] ?? null) && blank($data['constituency_id'] ?? null) && blank($data['venue'] ?? null)) {
            throw new ActivityWorkflowException('Choose a region, county, constituency or venue.');
        }

        $location = $activity->locations()->create($data);
        $this->dsa->recalculate($activity);

        return $location;
    }

    public function removeLocation(ActivityLocation $location, User $user): void
    {
        $activity = $location->loadMissing('activity')->activity;
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);
        $location->delete();
        $this->dsa->recalculate($activity);
    }

    /**
     * PT-01..PT-04 / PT-07. Adds staff (by id) to the activity.
     *
     * @param  list<int>  $staffIds
     * @param  array<int, string>  $conflictReasons  staff id => reason, required where a conflict exists
     * @return array{added: int, warnings: list<string>, returned: bool}
     */
    public function addStaff(Activity $activity, array $staffIds, ParticipantRole $role, User $user, array $conflictReasons = [], ?string $amendmentReason = null, ?string $roleOther = null): array
    {
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);
        $roleAttributes = $this->roleAttributes($role, $roleOther);

        $staff = Staff::query()->whereIn('id', $staffIds)->get()->keyBy('id');
        $existing = $activity->participants()->whereIn('staff_id', $staffIds)->pluck('staff_id')->all();
        $toAdd = $staff->except($existing);

        if ($toAdd->isEmpty()) {
            return ['added' => 0, 'warnings' => [], 'returned' => false];
        }

        $needsAmendment = $activity->status->requiresAmendment();
        if ($needsAmendment && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the team of a submitted or approved activity.');
        }

        $warnings = [];
        $returned = false;

        DB::transaction(function () use ($activity, $toAdd, $roleAttributes, $user, $conflictReasons, $needsAmendment, $amendmentReason, &$warnings, &$returned) {
            // Checked under a lock, so two people placing the same officer at once are taken in turn.
            $this->checks->lockStaff($toAdd->keys()->all());
            $busy = $this->checks->unavailableStaff($activity->start_date, $activity->end_date, $activity->id);
            foreach ($toAdd as $person) {
                if (isset($busy[$person->id])) {
                    $this->assertMayOverride($user, $person->name, $busy[$person->id], $conflictReasons[$person->id] ?? null);
                }
            }

            foreach ($toAdd as $person) {
                $participant = new ActivityParticipant([
                    'activity_id' => $activity->id,
                    'staff_id' => $person->id,
                    'is_external' => false,
                    'status' => ParticipationStatus::Nominated,
                    'conflict_reason' => $conflictReasons[$person->id] ?? null,
                ] + $roleAttributes);
                $participant->snapshotFrom($person)->save();

                $flags = $this->checks->fieldDayFlags($person, $activity);
                if ($flags['over_quarter'] || $flags['over_year']) {
                    $warnings[] = sprintf('%s would reach %d field days this quarter (limit %d) and %d this year (limit %d).', $person->name, $flags['quarter'], $flags['quarter_limit'], $flags['year'], $flags['year_limit']);
                }
            }

            $warnings = array_merge($warnings, $this->dsa->recalculate($activity));

            if ($needsAmendment) {
                $names = $toAdd->pluck('name')->implode(', ');
                $returned = $this->lifecycle->recordAmendment(
                    $activity, ActivityAmendment::KIND_PARTICIPANTS, "Added {$names}", (string) $amendmentReason, $user,
                    $toAdd->count() > $this->settings->int('tolerance_participant_changes') || $this->lifecycle->costBeyondTolerance($activity),
                    after: ['added' => $toAdd->pluck('id')->values()->all()],
                );
            }
        });

        return ['added' => $toAdd->count(), 'warnings' => $warnings, 'returned' => $returned];
    }

    /**
     * PT-03 / Q4: a non-staff participant, with their designation, their own days within the activity
     * (blank: the whole activity) and, optionally, a payment rate (4 November 2026): a DSA scale's
     * standard rate, or an amount typed in per night, per day or once. The payment is a computed cost
     * line (App\Services\DsaCalculator), so it is never typed in twice.
     *
     * @param  array{external_name: string, external_organisation?: ?string, external_category?: mixed, external_designation?: ?string, role_other?: ?string, start_date?: ?string, end_date?: ?string, rate_source?: ?string, rate_grade?: ?string, rate_amount?: ?string, rate_unit?: ?string}  $data
     */
    public function addExternal(Activity $activity, array $data, ParticipantRole $role, User $user, ?string $amendmentReason = null): ActivityParticipant
    {
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);

        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the team of a submitted or approved activity.');
        }

        $attributes = $this->externalAttributes($activity, $data, $role);

        return DB::transaction(function () use ($activity, $attributes, $user, $amendmentReason) {
            $participant = $activity->participants()->create($attributes + [
                'is_external' => true,
                'status' => ParticipationStatus::Nominated,
            ]);
            $this->dsa->recalculate($activity);

            if ($activity->status->requiresAmendment()) {
                $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_PARTICIPANTS, "Added external participant {$attributes['external_name']}", (string) $amendmentReason, $user,
                    $this->lifecycle->costBeyondTolerance($activity));
            }

            return $participant;
        });
    }

    /**
     * Change an external participant's details, days or payment rate. Their payment line follows.
     *
     * @param  array<string, mixed>  $data  as addExternal()
     * @return bool whether the change sent the activity back to the CEO
     */
    public function updateExternal(ActivityParticipant $participant, array $data, ParticipantRole $role, User $user, ?string $amendmentReason = null): bool
    {
        $activity = $participant->loadMissing('activity')->activity;
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);

        if (! $participant->is_external) {
            throw new ActivityWorkflowException('Only an external participant\'s details are changed here.');
        }
        if ($activity->status->isDelivered()) {
            throw new ActivityWorkflowException('An external participant\'s days and rate are fixed once the activity is completed.');
        }
        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the team of a submitted or approved activity.');
        }

        $attributes = $this->externalAttributes($activity, $data, $role);

        return DB::transaction(function () use ($participant, $activity, $attributes, $user, $amendmentReason) {
            $before = $activity->estimated_total;
            $participant->update($attributes);
            $this->dsa->recalculate($activity);

            return $activity->status->requiresAmendment()
                && $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_PARTICIPANTS, "Changed external participant {$attributes['external_name']}", (string) $amendmentReason, $user,
                    $this->lifecycle->costBeyondTolerance($activity), ['estimated_total' => $before], ['estimated_total' => $activity->estimated_total]);
        });
    }

    /**
     * Checked and normalised details of an external participant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function externalAttributes(Activity $activity, array $data, ParticipantRole $role): array
    {
        $name = trim((string) ($data['external_name'] ?? ''));
        if ($name === '') {
            throw new ActivityWorkflowException('Give the external participant\'s name.');
        }

        [$start, $end] = $this->ownDates($activity, $data['start_date'] ?? null, $data['end_date'] ?? null);
        $whole = $start->equalTo($activity->start_date) && $end->equalTo($activity->end_date);

        $rate = ['rate_source' => null, 'rate_amount' => null, 'rate_unit' => null, 'job_grade' => null];
        $source = (string) ($data['rate_source'] ?? '');
        if ($source === ActivityParticipant::RATE_DSA) {
            $grade = DsaRate::normaliseGrade((string) ($data['rate_grade'] ?? ''));
            if ($grade === null) {
                throw new ActivityWorkflowException('Choose the DSA scale they are paid at.');
            }
            if (! DsaRate::lookup($grade, $start)) {
                throw new ActivityWorkflowException("There is no DSA rate for scale {$grade} on ".$start->format('d M Y').'. Choose another scale or enter a custom rate.');
            }
            $rate = ['rate_source' => ActivityParticipant::RATE_DSA, 'rate_amount' => null, 'rate_unit' => RateUnit::forDsaBasis((string) $this->settings->get('dsa_basis')), 'job_grade' => $grade];
        } elseif ($source === ActivityParticipant::RATE_CUSTOM) {
            $unit = RateUnit::tryFrom((string) ($data['rate_unit'] ?? ''));
            $cents = $this->positiveCents($data['rate_amount'] ?? null, 'Enter the rate amount, more than KES 0.');
            if (! $unit) {
                throw new ActivityWorkflowException('Say whether the rate is paid per night, per day or once.');
            }
            $rate = ['rate_source' => ActivityParticipant::RATE_CUSTOM, 'rate_amount' => Money::fromCents($cents), 'rate_unit' => $unit, 'job_grade' => null];
        } elseif ($source !== '') {
            throw new ActivityWorkflowException('Choose a DSA scale rate, a custom rate, or no payment.');
        }

        return [
            'external_name' => mb_substr($name, 0, 255),
            'external_organisation' => filled($data['external_organisation'] ?? null) ? mb_substr(trim((string) $data['external_organisation']), 0, 255) : null,
            'external_designation' => filled($data['external_designation'] ?? null) ? mb_substr(trim((string) $data['external_designation']), 0, 255) : null,
            'external_category' => $data['external_category'] ?? null,
            'start_date' => $whole ? null : $start,
            'end_date' => $whole ? null : $end,
            'days_planned' => $whole ? null : (int) $start->diffInDays($end) + 1,
        ] + $rate + $this->roleAttributes($role, $data['role_other'] ?? null);
    }

    /**
     * A person's own first and last day, inside the activity. Blank means the activity's own date.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function ownDates(Activity $activity, mixed $start, mixed $end): array
    {
        $start = filled($start) ? Carbon::parse($start)->startOfDay() : $activity->start_date->copy();
        $end = filled($end) ? Carbon::parse($end)->startOfDay() : $activity->end_date->copy();

        if ($end->lt($start)) {
            throw new ActivityWorkflowException('The completion date cannot be before the start date.');
        }
        if ($start->lt($activity->start_date) || $end->gt($activity->end_date)) {
            throw new ActivityWorkflowException(sprintf('Dates must fall within the activity, %s to %s.', $activity->start_date->format('d M Y'), $activity->end_date->format('d M Y')));
        }

        return [$start, $end];
    }

    /**
     * The role, and the words for Other (specify), which are required.
     *
     * @return array{role: ParticipantRole, role_other: ?string}
     */
    private function roleAttributes(ParticipantRole $role, ?string $other): array
    {
        $other = trim((string) $other);
        if ($role->requiresDetail() && $other === '') {
            throw new ActivityWorkflowException('Say what their role is (Other).');
        }

        return ['role' => $role, 'role_other' => $role->requiresDetail() ? mb_substr($other, 0, 100) : null];
    }

    private function positiveCents(mixed $amount, string $message): int
    {
        try {
            $cents = Money::toCents(is_numeric($amount) || is_string($amount) ? (string) $amount : null);
        } catch (\InvalidArgumentException) {
            throw new ActivityWorkflowException($message);
        }
        if ($cents <= 0) {
            throw new ActivityWorkflowException($message);
        }

        return $cents;
    }

    /**
     * Officers added by number without their names, e.g. 10 Fund Account Managers (26 October 2026): a label,
     * the number and their DSA scale. DSA is the scale's rate × nights × the number; there is no availability
     * or field-day check, as nobody is named.
     *
     * @param  array{group_label: string, headcount: int|string, job_grade: string}  $data
     */
    public function addGroup(Activity $activity, array $data, ParticipantRole $role, User $user, ?string $amendmentReason = null): ActivityParticipant
    {
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);
        $roleAttributes = $this->roleAttributes($role, $data['role_other'] ?? null);

        $label = trim((string) $data['group_label']);
        $headcount = (int) $data['headcount'];
        $grade = DsaRate::normaliseGrade((string) $data['job_grade']);
        if ($label === '') {
            throw new ActivityWorkflowException('Say who they are, e.g. Fund Account Managers (FAMs).');
        }
        if ($headcount < 1 || $headcount > 500) {
            throw new ActivityWorkflowException('Give the number of officers, from 1 to 500.');
        }
        if ($grade === null) {
            throw new ActivityWorkflowException('Choose their DSA scale.');
        }
        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the team of a submitted or approved activity.');
        }

        return DB::transaction(function () use ($activity, $label, $headcount, $grade, $roleAttributes, $user, $amendmentReason) {
            $participant = $activity->participants()->create([
                'is_external' => false,
                'group_label' => mb_substr($label, 0, 255),
                'headcount' => $headcount,
                'job_grade' => $grade,
                'status' => ParticipationStatus::Nominated,
            ] + $roleAttributes);
            $this->dsa->recalculate($activity);

            if ($activity->status->requiresAmendment()) {
                $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_PARTICIPANTS, "Added {$headcount} × {$label}", (string) $amendmentReason, $user,
                    $headcount > $this->settings->int('tolerance_participant_changes') || $this->lifecycle->costBeyondTolerance($activity));
            }

            return $participant;
        });
    }

    public function removeParticipant(ActivityParticipant $participant, User $user, ?string $amendmentReason = null): bool
    {
        $activity = $participant->loadMissing('activity')->activity;
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);

        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the team of a submitted or approved activity.');
        }

        return DB::transaction(function () use ($participant, $activity, $user, $amendmentReason) {
            $name = $participant->displayName();
            $participant->delete();
            $this->costing->refresh($activity);

            if (! $activity->status->requiresAmendment()) {
                return false;
            }

            return $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_PARTICIPANTS, "Removed {$name}", (string) $amendmentReason, $user,
                $this->settings->int('tolerance_participant_changes') < 1);
        });
    }

    /**
     * The days one person takes part, within the activity's dates. Null dates
     * mean the whole activity. DSA follows the person's own nights. New days
     * that overlap another approved activity need a reason, as when adding (BR-04).
     *
     * @return bool whether the change sent the activity back to the CEO
     */
    public function setParticipantDates(ActivityParticipant $participant, ?Carbon $start, ?Carbon $end, User $user, ?string $amendmentReason = null, ?string $conflictReason = null): bool
    {
        $activity = $participant->loadMissing('activity')->activity;
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);

        if ($activity->status->isDelivered()) {
            throw new ActivityWorkflowException('Planned days are fixed once the activity is completed; record days attended instead.');
        }

        $start ??= $activity->start_date;
        $end ??= $activity->end_date;

        if ($start->lt($activity->start_date) || $end->gt($activity->end_date)) {
            throw new ActivityWorkflowException(sprintf('Dates must fall within the activity, %s to %s.', $activity->start_date->format('d M Y'), $activity->end_date->format('d M Y')));
        }

        if ($end->lt($start)) {
            throw new ActivityWorkflowException('The last day cannot be before the first day.');
        }

        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException("Give a reason for changing a participant's days on a submitted or approved activity.");
        }

        $conflictReason = trim((string) $conflictReason) ?: $participant->conflict_reason;
        if ($participant->staff_id && ($busy = $this->checks->unavailableStaff($start, $end, $activity->id)[$participant->staff_id] ?? null)) {
            $this->assertMayOverride($user, $participant->displayName(), $busy, $conflictReason);
        }

        $whole = $start->equalTo($activity->start_date) && $end->equalTo($activity->end_date);
        $before = ['start_date' => $participant->startOn($activity)->toDateString(), 'end_date' => $participant->endOn($activity)->toDateString(), 'days' => $participant->plannedDays($activity)];
        $days = (int) $start->diffInDays($end) + 1;

        return DB::transaction(function () use ($participant, $activity, $start, $end, $whole, $days, $before, $user, $amendmentReason, $conflictReason) {
            $participant->update([
                'start_date' => $whole ? null : $start,
                'end_date' => $whole ? null : $end,
                'days_planned' => $whole ? null : $days,
                'conflict_reason' => $conflictReason,
            ]);
            $this->dsa->recalculate($activity);

            if (! $activity->status->requiresAmendment()) {
                return false;
            }

            return $this->lifecycle->recordAmendment(
                $activity, ActivityAmendment::KIND_PARTICIPANTS,
                sprintf('%s: %s to %s (%d %s)', $participant->displayName(), $start->format('d M Y'), $end->format('d M Y'), $days, $days === 1 ? 'day' : 'days'),
                (string) $amendmentReason, $user, $this->lifecycle->costBeyondTolerance($activity),
                $before, ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'days' => $days],
            );
        });
    }

    /**
     * BR-04: record why someone stays on this activity although their days
     * overlap another approved one. Used when the overlap appeared after they
     * were added (a postponement, an extension, another activity approved).
     */
    public function explainConflict(ActivityParticipant $participant, string $reason, User $user): void
    {
        $activity = $participant->loadMissing('activity')->activity;
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);

        if (! $user->can('activities.manage')) {
            throw new AuthorizationException('Only the Office of the CEO can keep an officer on two overlapping activities. Choose someone else, or ask for the officer to be released from the other activity.');
        }

        if (trim($reason) === '') {
            throw new ActivityWorkflowException('Give the reason this person may be on both activities.');
        }

        if (! $participant->staff_id || $this->checks->conflicts($participant->staff_id, $participant->startOn($activity), $participant->endOn($activity), $activity->id)->isEmpty()) {
            throw new ActivityWorkflowException("{$participant->displayName()} has no overlap to explain.");
        }

        $participant->update(['conflict_reason' => trim($reason)]);
    }

    /**
     * EX-02: attendance, and days actually spent.
     */
    public function recordAttendance(ActivityParticipant $participant, ParticipationStatus $status, ?int $daysAttended, User $user): void
    {
        $this->authorize($user, 'activities.execute');

        $activity = $participant->loadMissing('activity')->activity;
        if (! in_array($activity->status, [ActivityStatus::InProgress, ActivityStatus::Completed, ActivityStatus::ReportReceived], true)) {
            throw new ActivityWorkflowException('Attendance is recorded once the activity has started.');
        }

        $participant->update([
            'status' => $status,
            'days_attended' => $status === ParticipationStatus::Attended ? ($daysAttended ?? $participant->plannedDays($activity)) : null,
        ]);
        $this->costing->refresh($activity);
    }

    /**
     * CB-02 / CB-03: a planned cost line (travel, venue, …). DSA lines and external participants'
     * payments are computed by DsaCalculator. A line is worked out per person (cost for one person ×
     * the number of people) or is a fixed amount for the activity (4 November 2026); the method is
     * kept, so editing the line shows it again.
     *
     * @param  array{cost_category_id: int, activity_participant_id?: ?int, description?: ?string, calculation_method?: ?string, unit_rate?: ?string, quantity?: int|string|null, estimated_amount?: ?string, travel_mode?: ?string}  $data
     * @return bool whether the change sent the activity back to the CEO
     */
    public function savePlannedCost(Activity $activity, array $data, User $user, ?ActivityCost $line = null, ?string $amendmentReason = null): bool
    {
        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);

        if ($activity->status->isDelivered()) {
            throw new ActivityWorkflowException('Planned costs are fixed once the activity is completed; record actual amounts instead.');
        }

        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the planned cost of a submitted or approved activity.');
        }

        if (CostCategory::query()->whereKey($data['cost_category_id'] ?? null)->value('code') === DsaCalculator::EXTERNAL_CATEGORY_CODE) {
            throw new ActivityWorkflowException('An external participant\'s allowance is worked out from the rate on their record. Set their rate on the team instead of adding a cost line.');
        }

        $data = $this->costAmounts($data);
        $before = $activity->estimated_total;

        return DB::transaction(function () use ($activity, $data, $user, $line, $amendmentReason, $before) {
            if ($line) {
                abort_if($line->is_computed, 422, 'Override a computed DSA amount instead of editing it.');
                $line->update($data);
            } else {
                $activity->costs()->create($data + ['recorded_by' => $user->id]);
            }
            $this->costing->refresh($activity);

            if (! $activity->status->requiresAmendment()) {
                return false;
            }

            return $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_COST,
                sprintf('Planned cost changed from %s to %s', Money::format(Money::toCents($before)), Money::format(Money::toCents($activity->estimated_total))),
                (string) $amendmentReason, $user, $this->lifecycle->costBeyondTolerance($activity),
                ['estimated_total' => $before], ['estimated_total' => $activity->estimated_total]);
        });
    }

    /**
     * The amount of a cost line from its method: per person = cost per person × number of people;
     * fixed = the amount entered, whatever the number of people.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function costAmounts(array $data): array
    {
        $method = CostMethod::tryFrom((string) ($data['calculation_method'] ?? '')) ?? CostMethod::Fixed;

        if ($method === CostMethod::PerPerson) {
            $unit = $this->positiveCents($data['unit_rate'] ?? null, 'Enter the cost per person, more than KES 0.');
            $people = filter_var($data['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            if ($people === false) {
                throw new ActivityWorkflowException('Enter the number of people, at least 1.');
            }

            return array_merge($data, [
                'calculation_method' => CostMethod::PerPerson,
                'unit_rate' => Money::fromCents($unit),
                'quantity' => $people,
                'estimated_amount' => Money::fromCents($unit * $people),
            ]);
        }

        return array_merge($data, [
            'calculation_method' => CostMethod::Fixed,
            'unit_rate' => null,
            'quantity' => null,
            'estimated_amount' => Money::fromCents(Money::toCents($data['estimated_amount'] ?? null)),
        ]);
    }

    public function removePlannedCost(ActivityCost $line, User $user, ?string $amendmentReason = null): bool
    {
        $activity = $line->loadMissing('activity')->activity;

        if ($line->is_computed) {
            throw new ActivityWorkflowException('Computed DSA follows the participants; remove the participant instead.');
        }

        $this->authorizeDetails($activity, $user);
        $this->assertEditable($activity);

        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the planned cost of a submitted or approved activity.');
        }

        return DB::transaction(function () use ($line, $activity, $user, $amendmentReason) {
            $before = $activity->estimated_total;
            $line->delete();
            $this->costing->refresh($activity);

            return $activity->status->requiresAmendment()
                && $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_COST, 'Removed a planned cost line', (string) $amendmentReason, $user, false,
                    ['estimated_total' => $before], ['estimated_total' => $activity->estimated_total]);
        });
    }

    /** FR-01: the actual amount from Finance returns. */
    public function recordActual(ActivityCost $line, ?string $amount, ?string $financeReference, User $user): void
    {
        $this->authorize($user, 'activities.execute');

        $line->loadMissing('activity');

        if (! $line->activity->status->isDelivered() && $line->activity->status !== ActivityStatus::InProgress) {
            throw new ActivityWorkflowException('Actual costs are recorded once the activity is under way.');
        }

        $line->update([
            'actual_amount' => blank($amount) ? null : Money::fromCents(Money::toCents($amount)),
            'finance_reference' => $financeReference ?: $line->finance_reference,
            'actual_recorded_by' => blank($amount) ? null : $user->id,
        ]);
        $this->costing->refresh($line->activity);
    }

    /**
     * BR-01: end date = start + days − 1; nights = the payable nights (App\Support\DsaPeriod).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withDates(array $attributes): array
    {
        $start = Carbon::parse($attributes['start_date']);
        $days = max(1, (int) ($attributes['days'] ?? 1));

        return array_merge($attributes, [
            'start_date' => $start->toDateString(),
            'days' => $days,
            'nights' => DsaPeriod::nightsFor($days),
            'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
        ]);
    }

    /**
     * CD-05: late notice, judged against the day the activity was recorded.
     * (CD-06 retrospective is set when the decision is recorded: see ActivityLifecycle.)
     *
     * @return array{is_late_notice: bool}
     */
    private function flags(Carbon $start, Carbon $recordedOn): array
    {
        $recordedOn = $recordedOn->copy()->startOfDay();

        return [
            'is_late_notice' => $start->gte($recordedOn) && WorkingDays::between($recordedOn, $start) < $this->settings->int('late_notice_working_days'),
        ];
    }

    /**
     * An officer already held by another approved activity on these days may
     * be placed only by the Office of the CEO, with a written reason. A Head
     * of Department is told the days they are held, not whose activity it is.
     *
     * @param  list<array{reference: string, from: Carbon, to: Carbon}>  $busy
     */
    private function assertMayOverride(User $user, string $name, array $busy, ?string $reason): void
    {
        if (! $user->can('activities.manage')) {
            throw new ActivityWorkflowException(ParticipantChecks::unavailableReason($name, $busy, false).' Choose someone else, or ask the Office of the CEO to release them from it first.');
        }

        if (blank($reason)) {
            throw new ActivityWorkflowException(ParticipantChecks::unavailableReason($name, $busy, true).' Release them from it first, or give a reason to proceed.');
        }
    }

    private function assertEditable(Activity $activity): void
    {
        if ($activity->status->isReadOnly()) {
            throw new ActivityWorkflowException("A {$activity->status->label()} activity is read only.");
        }
    }

    /**
     * The Office of the CEO edits any activity's details. A Head of
     * Department edits only their own department's memo, and only while it
     * is with them (a draft, or returned for correction).
     */
    public function authorizeDetails(Activity $activity, User $user): void
    {
        if ($user->can('activities.manage')) {
            // A memo sent directly to the CEO is not changed by the Chief of Staff or the Assistant before the decision.
            $this->lifecycle->assertNotReservedForCeo($activity, $user);

            return;
        }

        $this->authorize($user, 'memos.originate');

        if (! $activity->isVisibleTo($user)) {
            throw new AuthorizationException('This memo belongs to another department.');
        }

        // While the officer the Head asked to prepare it has it, only they change it (through asPreparer()).
        if ($activity->isWithPreparer()) {
            if ($this->preparing !== $activity->id) {
                throw new ActivityWorkflowException("{$activity->submitted_by_name} is preparing this memo. It comes to you for review when they submit it.");
            }

            return;
        }

        if (! $activity->status->isWithDepartment()) {
            throw new ActivityWorkflowException('This memo is with the Office of the CEO. It can be changed again only if it is returned to you for correction.');
        }
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not permitted to do this.');
        }
    }
}
