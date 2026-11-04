<?php

namespace App\Livewire\Concerns;

use App\Enums\EmploymentType;
use App\Enums\ExternalCategory;
use App\Enums\ParticipantRole;
use App\Enums\RateUnit;
use App\Models\ActivityParticipant;
use App\Rules\DsaGrade;

/**
 * An external participant's details, own days and payment rate, and a participant's role with its
 * words for Other (specify) (4 November 2026). Shared by the Head of Department's team page and the
 * Office of the CEO's activity page; the component has an `$activity` and a `$role`. The rules for the
 * payment itself live in App\Services\ActivityEditor. Fields: components/memo/external-fields, role-select.
 */
trait EntersExternalParticipants
{
    /** The role in words, when Other (specify) is chosen. */
    public string $roleOther = '';

    // External participant: added, or changed when $externalId is set.
    public ?int $externalId = null;

    public string $externalName = '';

    public string $externalOrganisation = '';

    public string $externalDesignation = '';

    public string $externalCategory = 'other';

    public string $externalStart = '';

    public string $externalEnd = '';

    /** '' no payment, 'dsa' a DSA scale's rate, 'custom' an amount typed in. */
    public string $externalRateSource = '';

    public string $externalRateGrade = '';

    public string $externalRateAmount = '';

    public string $externalRateUnit = 'night';

    protected const EXTERNAL_FIELDS = ['externalId', 'externalName', 'externalOrganisation', 'externalDesignation', 'externalCategory', 'externalStart', 'externalEnd',
        'externalRateSource', 'externalRateGrade', 'externalRateAmount', 'externalRateUnit'];

    protected const EXTERNAL_ATTRIBUTES = ['externalName' => 'name', 'externalOrganisation' => 'organisation', 'externalDesignation' => 'designation', 'externalStart' => 'start date',
        'externalEnd' => 'completion date', 'externalRateGrade' => 'DSA scale', 'externalRateAmount' => 'rate', 'externalRateUnit' => 'rate unit', 'roleOther' => 'role'];

    protected function roleRule(): string
    {
        return 'in:'.implode(',', array_column(ParticipantRole::cases(), 'value'));
    }

    /** @return array<string, list<string>> the role, and its words for Other (specify) */
    protected function roleRules(): array
    {
        return [
            'role' => ['required', $this->roleRule()],
            'roleOther' => $this->role === ParticipantRole::Other->value ? ['required', 'string', 'max:100', 'regex:/\S/'] : ['nullable', 'string', 'max:100'],
        ];
    }

    protected function fillExternal(ActivityParticipant $p): void
    {
        $this->externalId = $p->id;
        $this->externalName = (string) $p->external_name;
        $this->externalOrganisation = (string) $p->external_organisation;
        $this->externalDesignation = (string) $p->external_designation;
        $this->externalCategory = $p->external_category?->value ?? 'other';
        $this->externalStart = $p->startOn($this->activity)->toDateString();
        $this->externalEnd = $p->endOn($this->activity)->toDateString();
        $this->externalRateSource = (string) $p->rate_source;
        $this->externalRateGrade = $p->rate_source === ActivityParticipant::RATE_DSA ? (string) $p->job_grade : '';
        $this->externalRateAmount = $p->rate_amount !== null ? (string) $p->rate_amount : '';
        $this->externalRateUnit = $p->rate_unit?->value ?? 'night';
        $this->role = $p->role->value;
        $this->roleOther = (string) $p->role_other;
    }

    /** @return array<string, list<string>> */
    protected function externalRules(): array
    {
        return [
            'externalName' => ['required', 'string', 'max:255'],
            'externalOrganisation' => ['nullable', 'string', 'max:255'],
            'externalDesignation' => ['nullable', 'string', 'max:255'],
            'externalCategory' => ['required', 'in:'.implode(',', array_column(ExternalCategory::cases(), 'value'))],
            // Blank: the whole activity.
            'externalStart' => ['nullable', 'date', 'after_or_equal:'.$this->activity->start_date->toDateString(), 'before_or_equal:'.$this->activity->end_date->toDateString()],
            'externalEnd' => ['nullable', 'date', 'after_or_equal:'.($this->externalStart ?: $this->activity->start_date->toDateString()), 'before_or_equal:'.$this->activity->end_date->toDateString()],
            'externalRateSource' => ['nullable', 'in:'.ActivityParticipant::RATE_DSA.','.ActivityParticipant::RATE_CUSTOM],
            'externalRateGrade' => $this->externalRateSource === ActivityParticipant::RATE_DSA ? ['required', new DsaGrade(EmploymentType::Attachee)] : ['nullable'],
            'externalRateAmount' => $this->externalRateSource === ActivityParticipant::RATE_CUSTOM ? ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/', 'not_regex:/^[0,.]+$/'] : ['nullable'],
            'externalRateUnit' => $this->externalRateSource === ActivityParticipant::RATE_CUSTOM ? ['required', 'in:'.implode(',', array_column(RateUnit::cases(), 'value'))] : ['nullable'],
            ...$this->roleRules(),
        ];
    }

    /** @return array<string, string> */
    protected function externalMessages(): array
    {
        return [
            'externalStart.after_or_equal' => 'The start date must be within the activity.',
            'externalStart.before_or_equal' => 'The start date must be within the activity.',
            'externalEnd.after_or_equal' => 'The completion date cannot be before the start date.',
            'externalEnd.before_or_equal' => 'The completion date must be within the activity.',
            'externalRateAmount.regex' => 'Enter an amount such as 5,000.00.',
            'externalRateAmount.not_regex' => 'Enter the rate, more than KES 0.',
        ];
    }

    /** @return array<string, mixed> */
    protected function externalData(): array
    {
        return [
            'external_name' => $this->externalName,
            'external_organisation' => $this->externalOrganisation ?: null,
            'external_designation' => $this->externalDesignation ?: null,
            'external_category' => ExternalCategory::from($this->externalCategory),
            'start_date' => $this->externalStart ?: null,
            'end_date' => $this->externalEnd ?: null,
            'rate_source' => $this->externalRateSource ?: null,
            'rate_grade' => $this->externalRateGrade,
            'rate_amount' => $this->externalRateAmount,
            'rate_unit' => $this->externalRateUnit,
            'role_other' => $this->roleOther,
        ];
    }
}
