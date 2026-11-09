@use('App\Support\Ui')
@use('App\Support\Money')
{{--
    The cost-line popup's fields (App\Livewire\Concerns\EntersCostLines), on the Head's team page and the
    Office of the CEO's activity page: one Cost type list (travel by its mode), who it is for, a description
    and how it is calculated; and, for new lines, "Add another line", which keeps this line and clears the
    form for the next. Everything listed is saved together with Save.
--}}
@props(['participants', 'headcount' => 0, 'days' => 0, 'options' => [], 'queue' => [], 'editing' => false, 'wholeLabel' => 'The whole activity'])
@php $sel = Ui::CONTROL.' appearance-none pr-8'; @endphp
<div class="space-y-4">
    @if ($queue !== [])
        <div class="rounded-lg border border-slate-200 divide-y divide-slate-100">
            <p class="px-3 py-2 {{ Ui::MICRO }}">Lines to save ({{ count($queue) }})</p>
            @foreach ($queue as $i => $q)
                <div class="px-3 py-2 flex items-center gap-3 text-[13px]" wire:key="cq-{{ $i }}">
                    <span class="flex-1 min-w-0 text-slate-700">{{ $q['label'] }}</span>
                    <span class="font-numeric font-semibold text-slate-800">KES {{ Money::format($q['amount']) }}</span>
                    <button type="button" wire:click="unqueueCostLine({{ $i }})" class="{{ Ui::BTN_ICON }} hover:!text-red-600" aria-label="Remove this line"><i class="fa-solid fa-xmark text-xs"></i></button>
                </div>
            @endforeach
            <p class="px-3 py-2 flex justify-between text-[13px] font-semibold text-slate-800"><span>Total of the lines listed</span><span class="font-numeric">KES {{ Money::format(array_sum(array_column($queue, 'amount'))) }}</span></p>
        </div>
        <p class="text-[12px] text-slate-500">{{ $editing ? '' : 'Fill in another line below, or press Save to save the lines listed.' }}</p>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <x-ui.field label="Cost type" for="cl-type" error="costCategoryId" required hint="Travel is chosen by how people travel.">
            <select id="cl-type" wire:model.live="costType" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($options as $o)<option value="{{ $o['value'] }}">{{ $o['label'] }}</option>@endforeach</select>
        </x-ui.field>
        <x-ui.field label="For" for="cl-for" error="costParticipantId">
            <select id="cl-for" wire:model="costParticipantId" class="{{ $sel }}"><option value="">{{ $wholeLabel }}</option>@foreach ($participants as $p)<option value="{{ $p->id }}">{{ $p->displayName() }}</option>@endforeach</select>
        </x-ui.field>
        <x-ui.field label="Description" for="cl-d" error="costDescription" class="sm:col-span-2"><input id="cl-d" type="text" wire:model="costDescription" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="e.g. Lunch and two teas, or Conference room hire"></x-ui.field>
    </div>
    <x-memo.cost-method :headcount="$headcount" :days="$days" />

    @unless ($editing)
        <button type="button" wire:click="queueCostLine" wire:loading.attr="disabled" wire:target="queueCostLine" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-plus text-[9px]"></i>Add another line</button>
    @endunless
</div>
