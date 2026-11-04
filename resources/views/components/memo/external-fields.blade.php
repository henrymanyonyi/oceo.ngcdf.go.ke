{{--
    An external participant's details, days and payment rate (App\Services\ActivityEditor::addExternal /
    updateExternal). The rate is a DSA scale's standard rate ($dsaUnit: per night, or per day on the
    "days" basis) or a custom amount per night, per day or once; the payment is worked out as a
    computed cost line from it, with the same payable-nights rule as staff DSA.
--}}
@props(['activity', 'categories', 'grades', 'rateUnits', 'dsaUnit'])
@use('App\Support\Ui')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $min = $activity->start_date->toDateString();
    $max = $activity->end_date->toDateString();
@endphp
<div class="space-y-4" x-data="{
        source: $wire.entangle('externalRateSource'),
        start: $wire.entangle('externalStart'),
        end: $wire.entangle('externalEnd'),
        get days() { const s = this.start || '{{ $min }}', e = this.end || '{{ $max }}'; if (e < s) return null; return Math.round((Date.parse(e) - Date.parse(s)) / 86400000) + 1; },
        {{-- The same rule as App\Support\DsaPeriod: the departure night too for two days or more, none for one day. --}}
        get nights() { const d = this.days; return d === null ? null : (d >= 2 ? d + 1 : 0); },
    }">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <x-ui.field label="Name" for="ex-n" error="externalName" required class="sm:col-span-2"><input id="ex-n" type="text" wire:model="externalName" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-ui.field label="Designation or description" for="ex-d" error="externalDesignation"><input id="ex-d" type="text" wire:model="externalDesignation" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="e.g. External consultant"></x-ui.field>
        <x-ui.field label="Organisation" for="ex-o" error="externalOrganisation"><input id="ex-o" type="text" wire:model="externalOrganisation" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-ui.field label="Category" for="ex-c" error="externalCategory" required><select id="ex-c" wire:model="externalCategory" class="{{ $sel }}">@foreach ($categories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach</select></x-ui.field>
        <x-memo.role-select id="ex-r" />
    </div>

    <fieldset class="rounded-lg border border-slate-200 p-3 space-y-3">
        <legend class="{{ Ui::MICRO }} px-1">Participation</legend>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <x-ui.field label="Planned start date" for="ex-s" error="externalStart"><input id="ex-s" type="date" x-model="start" min="{{ $min }}" max="{{ $max }}" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Expected completion date" for="ex-e" error="externalEnd"><input id="ex-e" type="date" x-model="end" :min="start || '{{ $min }}'" max="{{ $max }}" class="{{ Ui::CONTROL }}"></x-ui.field>
            <div class="sm:col-span-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 flex items-center justify-between gap-3" aria-live="polite">
                <p class="{{ Ui::MICRO }}">Days · nights</p>
                <p class="text-[13px] font-bold text-slate-700 font-numeric" x-text="days === null ? '—' : days + (days === 1 ? ' day' : ' days') + ' · ' + nights + (nights === 1 ? ' night' : ' nights')"></p>
            </div>
        </div>
        <p class="text-[11px] text-slate-400">Within the activity, {{ Ui::dateRange($activity->start_date, $activity->end_date) }}; blank means the whole activity. Nights include the departure night for two days or more; a same-day visit has none.</p>
    </fieldset>

    <fieldset class="rounded-lg border border-slate-200 p-3 space-y-3">
        <legend class="{{ Ui::MICRO }} px-1">Payment</legend>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            @foreach (['' => ['No payment', 'Not paid by the Board'], 'dsa' => ['DSA scale rate', 'The standard rate of a scale, '.strtolower($dsaUnit->label())], 'custom' => ['Custom rate', 'An amount you enter']] as $value => [$title, $line])
                <label class="flex items-start gap-2 rounded-lg border px-3 py-2 cursor-pointer" :class="source === '{{ $value }}' ? 'border-emerald-300 bg-emerald-50/60' : 'border-slate-200 hover:bg-slate-50'">
                    <input type="radio" value="{{ $value }}" x-model="source" class="mt-0.5 border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    <span><span class="block text-[13px] font-semibold text-slate-700">{{ $title }}</span><span class="block text-[11px] text-slate-500">{{ $line }}</span></span>
                </label>
            @endforeach
        </div>
        <div x-show="source === 'dsa'" x-cloak>
            <x-ui.field label="DSA scale" for="ex-g" error="externalRateGrade" required :hint="'Paid '.strtolower($dsaUnit->label()).' at the scale\'s rate on the start date.'">
                <select id="ex-g" wire:model="externalRateGrade" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($grades as $g)<option value="{{ $g }}">{{ $g === 'INTERN' ? 'INTERN' : 'Scale '.$g }}</option>@endforeach</select>
            </x-ui.field>
        </div>
        <div x-show="source === 'custom'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <x-ui.field label="Rate (KES)" for="ex-a" error="externalRateAmount" required><input id="ex-a" type="text" inputmode="decimal" wire:model="externalRateAmount" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 5,000.00"></x-ui.field>
            <x-ui.field label="Paid" for="ex-u" error="externalRateUnit" required><select id="ex-u" wire:model="externalRateUnit" class="{{ $sel }}">@foreach ($rateUnits as $u)<option value="{{ $u->value }}">{{ $u->label() }}</option>@endforeach</select></x-ui.field>
        </div>
        <p x-show="source !== ''" x-cloak class="text-[11px] text-slate-500">The payment is worked out for you (rate × nights or days) and added to the planned costs as one line, so do not add it again as a cost line.</p>
    </fieldset>
</div>
