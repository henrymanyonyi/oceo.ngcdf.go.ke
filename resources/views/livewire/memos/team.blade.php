@use('App\Support\Ui')
@use('App\Support\Money')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $staffTeam = $a->participants->where('is_external', false);
    $externals = $a->participants->where('is_external', true);
    $manual = $a->costs->where('is_computed', false);
    $dsa = $a->costs->where('is_computed', true);
@endphp

{{-- One real root element: Livewire must not take the @if marker for the root when the builder page refreshes. --}}
<div>
@if ($embedded)
    {{-- Steps 4 and 5 of the one-page memo builder (Memos\Compose). --}}
    <div class="flex flex-col gap-8">
        <x-memo.step id="step-team" number="4" title="Team" line="The officers and others taking part. DSA is worked out from each officer's job grade and nights away." :done="$a->participants->isNotEmpty()">
            @include('livewire.memos.partials.team-notices')
            @include('livewire.memos.partials.team-card')
        </x-memo.step>
        <x-memo.step id="step-costs" number="5" title="Costs" line="Travel, venue, meals and other costs. DSA and external participants' payments appear here by themselves." :done="$a->costs->isNotEmpty()">
            @include('livewire.memos.partials.costs-card')
        </x-memo.step>
        @include('livewire.memos.partials.team-modals')
    </div>
@else
<x-ui.page icon="fa-users" title="Team and costs" :subtitle="$a->reference.' · '.$a->title.' · '.Ui::dateRange($a->start_date, $a->end_date)">
    <x-slot:pills><x-ui.status :status="$a->status" /></x-slot:pills>
    <x-slot:actions>
        <a href="{{ route('memos.show', $a) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Back to the memo</a>
        @if ($editable)<a href="{{ route('memos.edit', $a) }}#step-team" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-list-check text-xs"></i>Open in the memo builder</a>@endif
    </x-slot:actions>

    <x-slot:stats>
        <x-ui.stat :index="0" icon="fa-users" label="Officers" :value="$a->staff_count" :sub="$a->external_count ? '+'.$a->external_count.' external' : 'staff on the team'" />
        <x-ui.stat :index="1" icon="fa-calendar-days" label="Days · nights" :value="$a->days.' · '.$a->nights" :sub="Ui::dateRange($a->start_date, $a->end_date)" tone="blue" />
        <x-ui.stat :index="2" icon="fa-coins" label="Planned cost" :value="Money::format(Money::toCents($a->estimated_total))" money sub="DSA and the cost lines below" tone="amber" />
    </x-slot:stats>


    @include('livewire.memos.partials.team-notices')
    @include('livewire.memos.partials.team-card')
    @include('livewire.memos.partials.costs-card')
    @include('livewire.memos.partials.team-modals')
</x-ui.page>
@endif
</div>
