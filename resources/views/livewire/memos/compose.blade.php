@use('App\Support\Ui')
@use('App\Support\Money')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $blocking = collect($issues)->where('blocking', true);
    $returned = $a?->status === \App\Enums\ActivityStatus::ReturnedToHod;
@endphp

<x-ui.page :icon="$a ? 'fa-pen-to-square' : 'fa-plus'" :title="$a ? $a->title : 'Create activity memo'"
    :subtitle="$a ? $a->reference.' · '.Ui::dateRange($a->start_date, $a->end_date).' · saved as you go' : 'Work down the page, one step at a time. The first save creates the draft; only your department sees it until you submit.'">
    @if ($a)
        <x-slot:pills><x-ui.status :status="$a->status" /></x-slot:pills>
    @endif
    <x-slot:actions>
        <a href="{{ $a ? route('memos.show', $a) : route('memos.index') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>{{ $a ? 'Memo page' : 'Memos' }}</a>
        @if ($a)
            <a href="{{ route('memos.document', $a) }}" target="_blank" rel="noopener" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-pdf text-xs text-red-600"></i>Preview memo</a>
        @endif
    </x-slot:actions>

    <x-memo.builder :steps="$steps">
        <x-slot:rail>
                @if ($a)
                    <div class="{{ Ui::CARD }} p-4 space-y-3">
                        <p class="{{ Ui::MICRO }}">This memo so far</p>
                        <dl class="grid grid-cols-2 gap-3">
                            <div><dt class="text-[11px] text-slate-400">Days · nights</dt><dd class="text-[14px] font-bold text-slate-800 font-numeric">{{ $a->days }} · {{ $a->nights }}</dd></div>
                            <div><dt class="text-[11px] text-slate-400">Team</dt><dd class="text-[14px] font-bold text-slate-800 font-numeric">{{ $a->participants->sum(fn ($p) => $p->people()) }}</dd></div>
                            <div class="col-span-2"><dt class="text-[11px] text-slate-400">Planned cost</dt><dd class="text-[16px] font-bold text-emerald-700 font-numeric"><span class="text-[10px] text-slate-400 mr-0.5">KES</span>{{ Money::format(Money::toCents($a->estimated_total)) }}</dd></div>
                        </dl>
                        <a href="{{ route('memos.document', $a) }}" target="_blank" rel="noopener" class="{{ Ui::BTN_TINT }} w-full justify-center"><i class="fa-solid fa-file-pdf text-[10px]"></i>Preview the generated memo</a>
                    </div>
                @else
                    <p class="px-1 text-[12px] text-slate-400 leading-relaxed"><i class="fa-solid fa-circle-info mr-1"></i>Steps 3 to 6 open once you save the draft in step 2.</p>
                @endif
        </x-slot:rail>

            {{-- Steps 1 and 2 --}}
            <livewire:activities.form :activity="$a" :embedded="true" wire:key="memo-details" />

            {{-- Step 3 --}}
            <x-memo.step id="step-place" number="3" title="Venue and location" line="Where the activity takes place: region, county, constituency and venue, all required. Add a line for each place the activity goes to." :done="$steps[2]['done']" :locked="! $a">
                @if (! $a)
                    <x-memo.step-locked />
                @else
                    <x-ui.card title="Locations" icon="fa-location-dot" :count="$a->locations->count()">
                        @if ($a->locations->isNotEmpty())
                            <ul class="divide-y divide-slate-100">
                                @foreach ($a->locations as $loc)
                                    <li class="flex items-center gap-3 px-5 py-3" wire:key="loc-{{ $loc->id }}">
                                        <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0"><i class="fa-solid fa-location-dot text-xs"></i></span>
                                        <span class="flex-1 min-w-0 text-[13px] text-slate-700">{{ $loc->label() }}@unless ($loc->county_id)<span class="block text-[11px] text-amber-700">No county: DSA is not worked out for this location.</span>@endunless</span>
                                        <button type="button" wire:click="removeLocation({{ $loc->id }})" wire:loading.attr="disabled" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove {{ $loc->label() }}"><i class="fa-solid fa-xmark text-xs"></i></button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <form wire:submit="addLocation" class="p-5 {{ $a->locations->isNotEmpty() ? 'border-t border-slate-100 bg-slate-50/50' : '' }}">
                            <p class="text-[12px] font-semibold text-slate-600 mb-3">{{ $a->locations->isEmpty() ? 'Add the venue and location' : 'Add another location' }}</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                <x-ui.field label="Region" for="l-r" error="locRegion" required><select id="l-r" wire:model.live="locRegion" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($regions as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select></x-ui.field>
                                <x-ui.field label="County" for="l-c" error="locCounty" required><select id="l-c" wire:model.live="locCounty" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($counties as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                                <x-ui.field label="Constituency" for="l-k" error="locConstituency" required><select id="l-k" wire:model.live="locConstituency" class="{{ $sel }}" @disabled($constituencies->isEmpty())><option value="">{{ $locCounty ? 'Any' : 'County first' }}</option>@foreach ($constituencies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                                <x-ui.field label="Venue" for="l-v" error="locVenue" required><input id="l-v" type="text" wire:model="locVenue" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="e.g. NGCDF Board HQ"></x-ui.field>
                            </div>
                            <div class="mt-3 flex justify-end">
                                <button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="addLocation"><i class="fa-solid fa-plus text-xs"></i>Add location</button>
                            </div>
                        </form>
                    </x-ui.card>
                @endif
            </x-memo.step>

            {{-- Steps 4 and 5 --}}
            @if ($a)
                <livewire:memos.team :activity="$a" :embedded="true" wire:key="memo-team-{{ $a->id }}" />
            @else
                <x-memo.step id="step-team" number="4" title="Team" line="The officers and others taking part." :locked="true"><x-memo.step-locked /></x-memo.step>
                <x-memo.step id="step-costs" number="5" title="Costs" line="Travel, venue, meals and other costs." :locked="true"><x-memo.step-locked /></x-memo.step>
            @endif

            {{-- Step 6 --}}
            <x-memo.step id="step-review" number="6" title="Review and submit" line="Check what is still needed, attach any supporting document, preview the memo and send it." :done="$steps[5]['done']" :locked="! $a">
                @if (! $a)
                    <x-memo.step-locked />
                @else
                    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                        <x-ui.card :title="$blocking->isEmpty() ? 'Ready to submit' : 'Before you submit'" icon="{{ $blocking->isEmpty() ? 'fa-circle-check' : 'fa-list-check' }}" class="lg:col-span-3" padded>
                            @if ($issues === [])
                                <p class="text-[13px] text-emerald-700"><i class="fa-solid fa-circle-check mr-1"></i>Nothing is missing. Preview the memo, then submit it.</p>
                            @else
                                <ul class="space-y-2">
                                    @foreach ($issues as $issue)
                                        <li class="flex items-start gap-2 text-[13px] {{ $issue['blocking'] ? 'text-red-700' : 'text-amber-700' }}">
                                            <i class="fa-solid {{ $issue['blocking'] ? 'fa-circle-xmark' : 'fa-triangle-exclamation' }} mt-0.5"></i>
                                            <span>{{ $issue['message'] }}@if ($issue['blocking'])<span class="text-[10px] font-bold uppercase tracking-wide ml-1.5">Needed</span>@endif</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <p class="text-[12px] text-slate-500 mt-4 pt-4 border-t border-slate-100">The system writes the memo from what you entered, with Annex A (budget breakdown), Annex B (detailed budget) and Annex C (participant itinerary).</p>
                        </x-ui.card>

                        <x-ui.card title="Supporting documents" icon="fa-paperclip" :count="$a->documents->count()" class="lg:col-span-2" padded>
                            <div class="space-y-1.5">
                                @forelse ($a->documents as $d)
                                    <a href="{{ route('documents.show', $d) }}" class="flex items-center gap-2 px-3 py-2 border border-slate-100 rounded-lg hover:bg-slate-50 text-[13px] text-slate-700" wire:key="doc-{{ $d->id }}"><i class="fa-solid fa-file text-emerald-600"></i><span class="truncate flex-1">{{ $d->original_name }}</span></a>
                                @empty
                                    <p class="text-[12px] text-slate-400">Optional, for example an invitation letter.</p>
                                @endforelse
                            </div>
                            <form wire:submit="uploadDocument" class="mt-3 space-y-2">
                                <x-ui.field label="Attach a document" for="cdoc" error="document" hint="PDF, Word, Excel or image, up to 10 MB.">
                                    <input id="cdoc" type="file" wire:model="document" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold">
                                    <div wire:loading wire:target="document" class="text-[11px] text-slate-400 mt-1">Uploading…</div>
                                </x-ui.field>
                                <button type="submit" class="{{ Ui::BTN_TINT }}" wire:loading.attr="disabled" wire:target="document,uploadDocument"><i class="fa-solid fa-paperclip text-[9px]"></i>Attach</button>
                            </form>
                        </x-ui.card>
                    </div>

                    @if ($budget)
                        <x-ui.card padded>
                            <x-memo.budget-position :position="$budget" />
                        </x-ui.card>
                    @endif

                    <div class="rounded-xl border {{ $blocking->isEmpty() ? 'border-emerald-200 bg-gradient-to-r from-emerald-50 to-white' : 'border-slate-200 bg-white' }} px-5 py-4 flex flex-col sm:flex-row sm:items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-[14px] font-bold text-slate-800">{{ $blocking->isEmpty() ? 'Everything needed is in place.' : $blocking->count().' '.Str::plural('item', $blocking->count()).' still needed before you can submit.' }}</p>
                            <p class="text-[12px] text-slate-500">
                                @if ($withHod) An officer prepared this memo: forward, return or decline it on the memo page.
                                @else It stays a draft until you submit it and choose the route: through the COS/ACOS office, or directly to the CEO.
                                @endif
                            </p>
                        </div>
                        <a href="{{ route('memos.document', $a) }}" target="_blank" rel="noopener" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-pdf text-xs text-red-600"></i>Preview memo</a>
                        @if ($withHod)
                            <a href="{{ route('memos.show', $a) }}" wire:navigate class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-share text-xs"></i>Forward on the memo page</a>
                        @else
                            <button type="button" wire:click="openSubmit" class="{{ Ui::BTN_PRIMARY }}" @disabled($blocking->isNotEmpty())><i class="fa-solid fa-paper-plane text-xs"></i>{{ $returned ? ($a->isDirectToCeo() ? 'Resubmit to the CEO' : 'Resubmit for review') : 'Submit memo' }}</button>
                        @endif
                    </div>
                @endif
            </x-memo.step>
    </x-memo.builder>

    @if ($a)
        @include('livewire.memos.partials.submit-modal')
    @endif
</x-ui.page>
