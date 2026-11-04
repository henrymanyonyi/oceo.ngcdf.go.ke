<?php

namespace App\Livewire\Concerns;

use App\Enums\CostMethod;
use App\Models\ActivityCost;

/**
 * How a cost line's amount is entered (4 November 2026), shared by every page
 * that adds or edits one (the Head of Department's team page, the Office of the
 * CEO's activity page and an officer's delegated memo link): per person, the
 * cost for one person and the number of people; or a fixed amount for the whole
 * activity. The amount itself is worked out by ActivityEditor, never here.
 * Fields: resources/views/components/memo/cost-method.blade.php.
 */
trait EntersCostLines
{
    public string $costMethod = 'fixed';

    public string $costUnitRate = '';

    public string $costPeople = '';

    public string $costAmount = '';

    private const AMOUNT_PATTERN = '/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/';

    /** @return array<string, list<string>> */
    protected function costMethodRules(): array
    {
        $perPerson = $this->costMethod === CostMethod::PerPerson->value;

        return [
            'costMethod' => ['required', 'in:'.implode(',', array_column(CostMethod::cases(), 'value'))],
            'costUnitRate' => $perPerson ? ['required', 'regex:'.self::AMOUNT_PATTERN, 'not_regex:/^[0,.]+$/'] : ['nullable'],
            'costPeople' => $perPerson ? ['required', 'integer', 'min:1', 'max:100000'] : ['nullable'],
            'costAmount' => $perPerson ? ['nullable'] : ['required', 'regex:'.self::AMOUNT_PATTERN],
        ];
    }

    /** @return array<string, string> */
    protected function costMethodMessages(): array
    {
        return [
            'costAmount.regex' => 'Enter an amount such as 12,500.00.',
            'costUnitRate.regex' => 'Enter an amount such as 4,000.00.',
            'costUnitRate.not_regex' => 'Enter the cost per person, more than KES 0.',
        ];
    }

    /** @return array<string, string> */
    protected function costMethodAttributes(): array
    {
        return ['costAmount' => 'amount', 'costUnitRate' => 'cost per person', 'costPeople' => 'number of people', 'costMethod' => 'how it is calculated'];
    }

    /**
     * What ActivityEditor::savePlannedCost() needs to work out the amount.
     *
     * @return array{calculation_method: string, unit_rate: ?string, quantity: ?int, estimated_amount: ?string}
     */
    protected function costMethodData(): array
    {
        $perPerson = $this->costMethod === CostMethod::PerPerson->value;

        return [
            'calculation_method' => $this->costMethod,
            'unit_rate' => $perPerson ? $this->costUnitRate : null,
            'quantity' => $perPerson ? (int) $this->costPeople : null,
            'estimated_amount' => $perPerson ? null : $this->costAmount,
        ];
    }

    /** Fill the fields from a line being edited, keeping the way it was calculated. */
    protected function fillCostMethod(ActivityCost $line): void
    {
        $this->costMethod = $line->method()->value;
        $this->costUnitRate = $line->method() === CostMethod::PerPerson && $line->unit_rate !== null ? (string) $line->unit_rate : '';
        $this->costPeople = (string) ($line->people() ?? '');
        $this->costAmount = (string) $line->estimated_amount;
    }

    protected function resetCostMethod(): void
    {
        $this->reset(['costMethod', 'costUnitRate', 'costPeople', 'costAmount']);
    }
}
