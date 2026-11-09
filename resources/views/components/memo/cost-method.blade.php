{{--
    How a cost line's amount is worked out (App\Livewire\Concerns\EntersCostLines): per person (cost for
    one person × number of people), per person per day (× number of days too) or a fixed amount for the
    whole activity. The total is shown as it is typed; the saved amount is worked out on the server
    (ActivityEditor). $headcount fills the number of people, and the activity's days the number of days,
    when they are still empty.
--}}
@props(['method' => 'fixed', 'headcount' => 0, 'days' => 0])
@use('App\Support\Ui')
@use('App\Enums\CostMethod')
<div {{ $attributes->merge(['class' => 'space-y-3']) }} x-data="{
        method: $wire.entangle('costMethod'),
        rate: $wire.entangle('costUnitRate'),
        people: $wire.entangle('costPeople'),
        days: $wire.entangle('costDays'),
        get perDay() { return this.method === '{{ CostMethod::PerPersonPerDay->value }}' },
        get total() {
            const r = parseFloat(String(this.rate || '').replace(/[,\s]/g, ''));
            const n = parseInt(this.people, 10);
            const d = this.perDay ? parseInt(this.days, 10) : 1;
            return isNaN(r) || isNaN(n) || isNaN(d) ? null : Math.round(r * 100) * n * d / 100;
        },
        format(v) { return v === null ? '—' : v.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    }" x-init="$watch('method', m => {
        if (m !== '{{ CostMethod::Fixed->value }}' && ! people && {{ (int) $headcount }} > 0) people = '{{ (int) $headcount }}';
        if (m === '{{ CostMethod::PerPersonPerDay->value }}' && ! days && {{ (int) $days }} > 0) days = '{{ (int) $days }}';
    })">
    <fieldset>
        <legend class="{{ Ui::LABEL }}">How it is calculated <span class="text-red-500">*</span></legend>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-1">
            @foreach (CostMethod::cases() as $m)
                <label class="flex items-start gap-2.5 rounded-lg border px-3 py-2 cursor-pointer transition" :class="method === '{{ $m->value }}' ? 'border-emerald-300 bg-emerald-50/60' : 'border-slate-200 hover:bg-slate-50'">
                    <input type="radio" value="{{ $m->value }}" x-model="method" class="mt-0.5 border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="min-w-0">
                        <span class="block text-[13px] font-semibold text-slate-700">{{ $m->label() }}</span>
                        <span class="block text-[11px] text-slate-500">{{ $m->hint() }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('costMethod')<p class="text-xs text-red-600 mt-1" role="alert">{{ $message }}</p>@enderror
    </fieldset>

    <div x-show="method !== '{{ CostMethod::Fixed->value }}'" x-cloak class="space-y-3">
        <div class="grid grid-cols-1 gap-3" :class="perDay ? 'sm:grid-cols-3' : 'sm:grid-cols-2'">
            <x-ui.field label="Cost per person (KES)" for="c-rate" error="costUnitRate" required>
                <input id="c-rate" type="text" inputmode="decimal" x-model="rate" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 4,000.00">
            </x-ui.field>
            <x-ui.field label="Number of people" for="c-people" error="costPeople" required :hint="$headcount ? 'The team has '.$headcount.'.' : null">
                <input id="c-people" type="number" min="1" max="100000" x-model="people" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 10">
            </x-ui.field>
            <div x-show="perDay" x-cloak>
                <x-ui.field label="Number of days" for="c-days" error="costDays" required :hint="$days ? 'The activity runs '.$days.' '.($days === 1 ? 'day' : 'days').'.' : null">
                    <input id="c-days" type="number" min="1" max="366" x-model="days" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 3">
                </x-ui.field>
            </div>
        </div>
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 flex items-center justify-between gap-3" aria-live="polite">
            <span class="text-[12px] text-emerald-800 font-numeric" x-text="(rate || '0') + ' × ' + (people || '0') + (perDay ? ' × ' + (days || '0') + ' days' : '')"></span>
            <span class="text-[13px] font-bold text-emerald-800 font-numeric">KES <span x-text="format(total)"></span></span>
        </div>
    </div>

    <div x-show="method === '{{ CostMethod::Fixed->value }}'" x-cloak>
        <x-ui.field label="Total for the activity (KES)" for="c-a" error="costAmount" required hint="Used as it is, whatever the number of people.">
            <input id="c-a" type="text" inputmode="decimal" wire:model="costAmount" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 50,000.00">
        </x-ui.field>
    </div>
</div>
