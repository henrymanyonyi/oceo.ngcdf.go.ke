<?php

namespace App\Livewire\Concerns;

use App\Enums\CostMethod;
use App\Enums\CostScope;
use App\Enums\TravelMode;
use App\Models\ActivityCost;
use App\Models\CostCategory;
use App\Services\DsaCalculator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * How a cost line's amount is entered, shared by every page that adds or edits one (the Head of
 * Department's team page, the Office of the CEO's activity page and an officer's delegated memo link):
 * per person (cost for one person × people), per person per day (× days too, 10 November 2026) or a
 * fixed amount for the whole activity. The amount itself is worked out by ActivityEditor, never here.
 * Fields: resources/views/components/memo/cost-method.blade.php.
 *
 * In a cost popup (10 November 2026): one "Cost type" list in which travel comes by its mode (Travel:
 * air, road by Board vehicle, road by public transport), so there is no separate travel-mode field;
 * choosing a type presets how it is calculated from the type's setting (per person or for the activity);
 * and "Add another line" keeps the line typed and clears the form for the next, so several lines (meals,
 * travel, conference package...) are saved together, all or none. The page provides validatedCostLine().
 */
trait EntersCostLines
{
    public string $costMethod = 'fixed';

    public string $costUnitRate = '';

    public string $costPeople = '';

    public string $costDays = '';

    public string $costAmount = '';

    /** The "Cost type" choice: a cost type's id, or "id|mode" for travel by a mode. */
    public string $costType = '';

    /** @var list<array{payload: array<string, mixed>, label: string, amount: int}> lines typed in this popup, waiting to be saved together */
    public array $costQueue = [];

    private const AMOUNT_PATTERN = '/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/';

    /** @return array<string, list<string>> */
    protected function costMethodRules(): array
    {
        $method = CostMethod::tryFrom($this->costMethod);

        return [
            'costMethod' => ['required', 'in:'.implode(',', array_column(CostMethod::cases(), 'value'))],
            'costUnitRate' => $method?->isPerPerson() ? ['required', 'regex:'.self::AMOUNT_PATTERN, 'not_regex:/^[0,.]+$/'] : ['nullable'],
            'costPeople' => $method?->isPerPerson() ? ['required', 'integer', 'min:1', 'max:100000'] : ['nullable'],
            'costDays' => $method === CostMethod::PerPersonPerDay ? ['required', 'integer', 'min:1', 'max:366'] : ['nullable'],
            'costAmount' => $method?->isPerPerson() ? ['nullable'] : ['required', 'regex:'.self::AMOUNT_PATTERN],
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
        return ['costAmount' => 'amount', 'costUnitRate' => 'cost per person', 'costPeople' => 'number of people', 'costDays' => 'number of days', 'costMethod' => 'how it is calculated', 'costType' => 'cost type'];
    }

    /**
     * What ActivityEditor::savePlannedCost() needs to work out the amount.
     *
     * @return array{calculation_method: string, unit_rate: ?string, quantity: ?int, days: ?int, estimated_amount: ?string}
     */
    protected function costMethodData(): array
    {
        $method = CostMethod::tryFrom($this->costMethod) ?? CostMethod::Fixed;

        return [
            'calculation_method' => $method->value,
            'unit_rate' => $method->isPerPerson() ? $this->costUnitRate : null,
            'quantity' => $method->isPerPerson() ? (int) $this->costPeople : null,
            'days' => $method === CostMethod::PerPersonPerDay ? (int) $this->costDays : null,
            'estimated_amount' => $method->isPerPerson() ? null : $this->costAmount,
        ];
    }

    /** Fill the fields from a line being edited, keeping the way it was calculated. */
    protected function fillCostMethod(ActivityCost $line): void
    {
        $this->costMethod = $line->method()->value;
        $this->costUnitRate = $line->method()->isPerPerson() && $line->unit_rate !== null ? (string) $line->unit_rate : '';
        $this->costPeople = (string) ($line->people() ?? '');
        $this->costDays = (string) ($line->days ?? '');
        $this->costAmount = (string) $line->estimated_amount;
        $this->costType = $line->cost_category_id.($line->travel_mode && $line->travel_mode !== TravelMode::None ? '|'.$line->travel_mode->value : '');
    }

    protected function resetCostMethod(): void
    {
        $this->reset(['costMethod', 'costUnitRate', 'costPeople', 'costDays', 'costAmount', 'costType']);
    }

    // ── One cost-type list, travel by mode ───────────────────────────

    /**
     * The "Cost type" list: every cost type that is typed in (DSA and external allowances are worked out),
     * with travel offered once per mode.
     *
     * @return list<array{value: string, label: string}>
     */
    public function costTypeOptions(): array
    {
        return CostCategory::query()->active()->whereNotIn('code', DsaCalculator::COMPUTED_CODES)->ordered()->get(['id', 'name', 'code'])
            ->flatMap(fn (CostCategory $c) => $c->code === 'travel'
                ? collect(TravelMode::cases())->reject(fn (TravelMode $m) => $m === TravelMode::None)
                    ->map(fn (TravelMode $m) => ['value' => $c->id.'|'.$m->value, 'label' => $c->name.': '.$m->label()])
                : [['value' => (string) $c->id, 'label' => $c->name]])
            ->values()->all();
    }

    /** Choosing a cost type sets the type and travel mode, and (for a new line) how it is usually calculated. */
    public function updatedCostType(string $value): void
    {
        [$category, $mode] = array_pad(explode('|', $value, 2), 2, '');
        $this->costCategoryId = $category;
        $this->costTravelMode = TravelMode::tryFrom($mode)?->value ?? '';

        if (! $this->costId && $category !== '') {
            $perPerson = CostCategory::query()->find((int) $category)?->default_scope === CostScope::Participant;
            $this->costMethod = ($perPerson ? CostMethod::PerPerson : CostMethod::Fixed)->value;
        }
    }

    // ── Several lines in one popup ───────────────────────────────────

    /** "Add another line": keeps the line typed (checked as if saved) and clears the form for the next one. */
    public function queueCostLine(): void
    {
        if ($this->costId) {
            return;
        }
        $payload = $this->validatedCostLine();
        if ($payload === null) {
            return;
        }

        $this->costQueue[] = ['payload' => $payload, 'label' => $this->costLineLabel($payload), 'amount' => $this->costLineCents($payload)];
        $this->reset(['costCategoryId', 'costParticipantId', 'costDescription', 'costTravelMode']);
        $this->resetCostMethod();
        $this->resetErrorBag();
    }

    public function unqueueCostLine(int $index): void
    {
        unset($this->costQueue[$index]);
        $this->costQueue = array_values($this->costQueue);
    }

    /**
     * Every line to save from the popup: those added with "Add another line", and the one in the form if it
     * has been started (or if nothing else is there). Null when the form's line has errors.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function costLinesToSave(): ?array
    {
        $lines = array_column($this->costQueue, 'payload');
        if ($this->costCategoryId !== '' || $lines === []) {
            $payload = $this->validatedCostLine();
            if ($payload === null) {
                return null;
            }
            $lines[] = $payload;
        }

        return $lines;
    }

    /**
     * Saves the lines together: if one is refused, none is kept.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  callable(array<string, mixed>): mixed  $save
     * @return list<mixed> what each save returned
     */
    protected function saveCostLines(array $lines, callable $save): array
    {
        return DB::transaction(fn () => array_map($save, $lines));
    }

    /** @param array<string, mixed> $payload */
    private function costLineLabel(array $payload): string
    {
        $option = collect($this->costTypeOptions())->firstWhere('value', $payload['cost_category_id'].(filled($payload['travel_mode'] ?? null) ? '|'.$payload['travel_mode'] : ''));
        $method = CostMethod::tryFrom((string) $payload['calculation_method']) ?? CostMethod::Fixed;

        return ($option['label'] ?? 'Cost').(filled($payload['description'] ?? null) ? ': '.$payload['description'] : '').' · '.match ($method) {
            CostMethod::Fixed => 'for the activity',
            CostMethod::PerPerson => $payload['unit_rate'].' × '.$payload['quantity'].' people',
            CostMethod::PerPersonPerDay => $payload['unit_rate'].' × '.$payload['quantity'].' people × '.$payload['days'].' days',
        };
    }

    /** @param array<string, mixed> $payload */
    private function costLineCents(array $payload): int
    {
        $method = CostMethod::tryFrom((string) $payload['calculation_method']) ?? CostMethod::Fixed;

        return $method->isPerPerson()
            ? Money::toCents(str_replace(',', '', (string) $payload['unit_rate'])) * (int) $payload['quantity'] * ($method === CostMethod::PerPersonPerDay ? (int) $payload['days'] : 1)
            : Money::toCents(str_replace(',', '', (string) $payload['estimated_amount']));
    }

    /**
     * The line in the form, checked, as ActivityEditor::savePlannedCost() takes it; null (with errors shown)
     * when something is missing. Provided by the page, which knows its own team.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function validatedCostLine(): ?array;
}
