{{--
    How a cost line's amount is worked out (App\Livewire\Concerns\EntersCostLines): per person
    (cost for one person × number of people) or a fixed amount for the whole activity. The total
    is shown as it is typed; the saved amount is worked out on the server (ActivityEditor).
    $headcount fills the number of people when Per person is chosen and it is still empty.
--}}
@props(['method' => 'fixed', 'headcount' => 0])
@use('App\Support\Ui')
@use('App\Enums\CostMethod')
<div {{ $attributes->merge(['class' => 'space-y-3']) }} x-data="{
        method: $wire.entangle('costMethod'),
        rate: $wire.entangle('costUnitRate'),
        people: $wire.entangle('costPeople'),
        get total() {
            const r = parseFloat(String(this.rate || '').replace(/[,\s]/g, ''));
            const n = parseInt(this.people, 10);
            return isNaN(r) || isNaN(n) ? null : Math.round(r * 100) * n / 100;
        },
        format(v) { return v === null ? '—' : v.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    }" x-init="$watch('method', m => { if (m === '{{ CostMethod::PerPerson->value }}' && ! people && {{ (int) $headcount }} > 0) people = '{{ (int) $headcount }}' })">
    <fieldset>
        <legend class="{{ Ui::LABEL }}">How it is calculated <span class="text-red-500">*</span></legend>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-1">
            @foreach (CostMethod::cases() as $m)
                <label class="flex items-start gap-2.5 rounded-lg border px-3 py-2 cursor-pointer transition" :class="method === '{{ $m->value }}' ? 'border-emerald-300 bg-emerald-50/60' : 'border-slate-200 hover:bg-slate-50'">
                    <input type="radio" value="{{ $m->value }}" x-model="method" class="mt-0.5 border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="min-w-0">
                        <span class="block text-[13px] font-semibold text-slate-700">{{ $m->label() }}</span>
                        <span class="block text-[11px] text-slate-500">{{ $m === CostMethod::PerPerson ? 'Cost for one person × number of people' : 'One amount for the whole activity' }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('costMethod')<p class="text-xs text-red-600 mt-1" role="alert">{{ $message }}</p>@enderror
    </fieldset>

    <div x-show="method === '{{ CostMethod::PerPerson->value }}'" x-cloak class="space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <x-ui.field label="Cost per person (KES)" for="c-rate" error="costUnitRate" required>
                <input id="c-rate" type="text" inputmode="decimal" x-model="rate" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 4,000.00">
            </x-ui.field>
            <x-ui.field label="Number of people" for="c-people" error="costPeople" required :hint="$headcount ? 'The team has '.$headcount.'.' : null">
                <input id="c-people" type="number" min="1" max="100000" x-model="people" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 10">
            </x-ui.field>
        </div>
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 flex items-center justify-between gap-3" aria-live="polite">
            <span class="text-[12px] text-emerald-800 font-numeric" x-text="(rate || '0') + ' × ' + (people || '0')"></span>
            <span class="text-[13px] font-bold text-emerald-800 font-numeric">KES <span x-text="format(total)"></span></span>
        </div>
    </div>

    <div x-show="method === '{{ CostMethod::Fixed->value }}'" x-cloak>
        <x-ui.field label="Total for the activity (KES)" for="c-a" error="costAmount" required hint="Used as it is, whatever the number of people.">
            <input id="c-a" type="text" inputmode="decimal" wire:model="costAmount" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 50,000.00">
        </x-ui.field>
    </div>
</div>
