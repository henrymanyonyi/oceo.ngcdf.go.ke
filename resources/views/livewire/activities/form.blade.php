@use('App\Support\Ui')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $back = match (true) {
        ! $office => $activity ? route('memos.show', $activity) : route('memos.index'),
        default => $activity ? route('activities.show', $activity) : route('activities.index'),
    };
@endphp

{{-- One real root element: Livewire must not take the @if marker for the root when the builder page refreshes. --}}
<div>
@if ($embedded)
    {{-- The first two steps of the one-page memo builder (Memos\Compose). --}}
    <form wire:submit="save" class="flex flex-col gap-8">
        <x-memo.step id="step-details" number="1" title="About the activity" line="What the activity is and why it is needed. This is the body of the memo; every field is needed." :done="$aboutDone">
            <x-ui.card title="The activity" icon="fa-clipboard-list" padded>
                @include('livewire.activities.partials.form-about')
            </x-ui.card>
            <details class="group {{ \App\Support\Ui::CARD }}" @if ($memo_sections !== []) open @endif>
                <summary class="cursor-pointer list-none flex items-center justify-between gap-3 px-5 py-3 text-[13px] font-semibold text-slate-600 hover:bg-slate-50">
                    <span><i class="fa-solid fa-list-ol text-[11px] text-slate-400 mr-2"></i>More memo sections <span class="font-normal text-slate-400">(optional{{ $memo_sections !== [] ? ', '.count($memo_sections).' added' : '' }})</span></span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition group-open:rotate-180"></i>
                </summary>
                <div class="px-5 pb-5 pt-1 border-t border-slate-100">
                    @include('livewire.activities.partials.form-sections')
                </div>
            </details>
            @unless ($aboutDone)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3">
                    <p class="text-[12px] text-emerald-900"><i class="fa-solid fa-circle-info mr-1.5"></i>Fill in every field above to go on to step 2.</p>
                    <button type="button" wire:click="continueToDates" wire:loading.attr="disabled" wire:target="continueToDates" class="{{ Ui::BTN_PRIMARY }}">Continue to step 2<i class="fa-solid fa-arrow-down text-xs"></i></button>
                </div>
            @endunless
        </x-memo.step>

        <x-memo.step id="step-dates" number="2" title="Reference, dates and budget" line="The memo's reference and date, when the activity runs, and the budget it is charged to." :done="(bool) $activity" :locked="! $aboutDone">
            @if (! $aboutDone)
                <x-memo.step-locked message="Opens when every field in step 1 is filled in." />
            @else
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                <x-ui.card title="Dates" icon="fa-calendar" padded>
                    @include('livewire.activities.partials.form-dates')
                </x-ui.card>
                <x-ui.card title="Reference" icon="fa-envelope-open-text" padded>
                    @include('livewire.activities.partials.form-source')
                </x-ui.card>
            </div>
            <x-ui.card title="Budget" icon="fa-coins" padded>
                @include('livewire.activities.partials.form-costing')
            </x-ui.card>
            @endif
        </x-memo.step>

        @if ($aboutDone)
        {{-- Saving keeps the Head on the page; the first save creates the draft and opens the next steps. --}}
        <div class="sticky bottom-0 z-10 -mx-1 px-1 pb-1">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white/95 backdrop-blur px-4 py-3 shadow-lg shadow-slate-900/5 sm:ml-11">
                <p class="text-[12px] text-slate-500" aria-live="polite">
                    <span wire:dirty class="text-amber-700"><i class="fa-solid fa-circle text-[7px] mr-1.5 align-middle"></i>Unsaved changes</span>
                    <span wire:dirty.remove>
                        @if ($activity)<i class="fa-solid fa-cloud-arrow-up text-emerald-600 mr-1.5"></i>Draft {{ $activity->reference }} · saved
                        @else<i class="fa-solid fa-circle-info text-slate-400 mr-1.5"></i>Save the draft to add the venue, team and costs.
                        @endif
                    </span>
                </p>
                <button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="save,memo">
                    <i class="fa-solid fa-floppy-disk text-xs"></i><span wire:loading.remove wire:target="save">{{ $activity ? 'Save changes' : 'Save draft and continue' }}</span><span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>
        @endif
    </form>
@else
<x-ui.page icon="{{ $activity ? 'fa-pen' : 'fa-plus' }}" :title="$activity ? 'Edit '.$activity->reference : ($office ? 'Record activity' : 'Create activity memo')"
    :subtitle="$office ? 'Capture the activity from the departmental memo, work plan or request for approval.' : 'Describe the activity your department intends to carry out. It stays a draft, visible only to your department, until you submit it for review.'">
    <x-slot:actions>
        <a href="{{ $back }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Back</a>
    </x-slot:actions>

    <form wire:submit="save" class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="xl:col-span-2 space-y-4">
            <x-ui.card title="The activity" icon="fa-clipboard-list" padded>
                @include('livewire.activities.partials.form-about')
            </x-ui.card>

            <x-ui.card title="More memo sections" icon="fa-list-ol" padded>
                @include('livewire.activities.partials.form-sections')
            </x-ui.card>

            <x-ui.card title="Dates" icon="fa-calendar" padded>
                @include('livewire.activities.partials.form-dates')
            </x-ui.card>

            @if (! $activity || $activity->locations()->doesntExist())
                <x-ui.card :title="$office ? 'First location' : 'Venue and location'" icon="fa-location-dot" padded>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.field label="Region" for="region" error="region_id">
                            <select id="region" wire:model.live="region_id" class="{{ $sel }}"><option value="">Any</option>@foreach ($regions as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
                        </x-ui.field>
                        <x-ui.field label="County" for="county" error="county_id" hint="Sets the DSA destination category.">
                            <select id="county" wire:model.live="county_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($counties as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                        </x-ui.field>
                        <x-ui.field label="Constituency" for="const" error="constituency_id">
                            <select id="const" wire:model.live="constituency_id" class="{{ $sel }}" @disabled($constituencies->isEmpty())><option value="">{{ $county_id ? 'Any' : 'Choose a county first' }}</option>@foreach ($constituencies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                        </x-ui.field>
                        <x-ui.field label="Venue" for="venue" error="venue">
                            <input id="venue" type="text" wire:model="venue" class="{{ Ui::CONTROL }}" maxlength="255">
                        </x-ui.field>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-3">{{ $office ? 'More locations can be added on the activity page.' : 'For example, venue: NGCDF Board headquarters.' }}</p>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card title="Source" icon="fa-envelope-open-text" padded>
                @include('livewire.activities.partials.form-source')
            </x-ui.card>

            <x-ui.card title="Costing" icon="fa-coins" padded>
                @include('livewire.activities.partials.form-costing')
            </x-ui.card>

            <div class="flex justify-end gap-2">
                <a href="{{ $back }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}">Cancel</a>
                <button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="save,memo">
                    <i class="fa-solid fa-floppy-disk text-xs"></i><span wire:loading.remove wire:target="save">{{ $activity ? 'Save changes' : 'Save draft' }}</span><span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>
    </form>
</x-ui.page>

@endif
</div>
