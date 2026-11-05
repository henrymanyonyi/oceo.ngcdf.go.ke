@use('App\Support\Ui')
@use('App\Support\Money')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; $file = 'block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold'; @endphp

<div class="space-y-4">
    @if ($done)
        <div class="{{ Ui::CARD }} p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-emerald-50 flex items-center justify-center mx-auto mb-3"><i class="fa-solid fa-check text-2xl text-emerald-600"></i></div>
            <h1 class="text-xl font-bold text-slate-800">Submitted</h1>
            <p class="text-sm text-slate-600 mt-1">{{ $done }}</p>
        </div>
    @elseif (! $usable)
        <div class="{{ Ui::CARD }} p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-slate-50 flex items-center justify-center mx-auto mb-3"><i class="fa-solid fa-link-slash text-2xl text-slate-300"></i></div>
            <h1 class="text-xl font-bold text-slate-800">This link is not available</h1>
            <p class="text-sm text-slate-500 mt-1">{{ $link?->isCompleted() ? 'The memo has been submitted to your Head of Department. If they return it to you, a new link will be sent.' : 'It may have expired or been withdrawn. Ask your Head of Department for a new one.' }}</p>
        </div>
    @elseif (! $verified)
        <div class="{{ Ui::CARD }} p-8 max-w-lg mx-auto">
            <div class="w-14 h-14 rounded-2xl bg-emerald-50 flex items-center justify-center mb-3"><i class="fa-solid fa-shield-halved text-2xl text-emerald-600"></i></div>
            <h1 class="text-xl font-bold text-slate-800">Confirm it is you</h1>
            <p class="text-sm text-slate-600 mt-1">The Head of {{ $link->department?->name ?? 'your department' }} has asked you to prepare an activity memo. To open it, we send a six-digit code to the email and mobile number HR holds for you.</p>
            @if ($codeSentTo !== [])
                <p class="text-[13px] text-emerald-700 mt-3"><i class="fa-solid fa-paper-plane mr-1"></i>Code sent to {{ implode(' and ', $codeSentTo) }}. It works for {{ \App\Services\DelegatedMemos::CODE_MINUTES }} minutes.</p>
                <form wire:submit="verify" class="mt-4 flex gap-2 items-start">
                    <x-ui.field label="Code" for="mr-code" error="code" class="flex-1"><input id="mr-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" wire:model="code" class="{{ Ui::CONTROL }} font-numeric tracking-widest"></x-ui.field>
                    <button type="submit" class="{{ Ui::BTN_PRIMARY }} mt-5" wire:loading.attr="disabled" wire:target="verify">Open the memo</button>
                </form>
                <button type="button" wire:click="sendCode" class="mt-3 text-[12px] font-semibold text-emerald-700 hover:underline">Send another code</button>
            @else
                @error('code')<p class="text-xs text-red-600 mt-3" role="alert">{{ $message }}</p>@enderror
                <button type="button" wire:click="sendCode" class="mt-4 {{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="sendCode"><i class="fa-solid fa-paper-plane text-xs"></i>Send me a code</button>
            @endif
        </div>
    @else
        @php $blocking = collect($issues)->where('blocking', true); @endphp
        <div>
            <h1 class="text-xl font-bold text-slate-800">{{ $memo ? $memo->reference.' · '.$memo->title : 'Prepare an activity memo' }}</h1>
            <p class="text-sm text-slate-500">For the Head of {{ $link->department?->name }}. Work down the page one step at a time; your work is saved as a draft as you go. Submit it to your Head of Department when it is ready. This link works until {{ $link->expires_at->format('d M Y') }}.</p>
            @if ($link->instructions)<div class="mt-3 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-[13px] text-emerald-900 whitespace-pre-line"><p class="font-semibold mb-0.5">From your Head of Department</p>{{ $link->instructions }}</div>@endif
            @if ($returned && $memo->status_reason)<div class="mt-3 px-4 py-3 rounded-lg bg-orange-50 border border-orange-200 text-[13px] text-orange-800 whitespace-pre-line" role="alert"><p class="font-semibold"><i class="fa-solid fa-reply mr-1"></i>Returned to you. Please change:</p>{{ $memo->status_reason }}</div>@endif
            @error('form')<div class="mt-3 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-[13px] text-red-700" role="alert">{{ $message }}</div>@enderror
        </div>

        @if (! $editable)
            <div class="px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-sm"><i class="fa-solid fa-lock mr-1"></i>This memo is {{ strtolower($memo->status->label()) }}. It can no longer be changed here.</div>
        @else
            <x-memo.builder :steps="$steps">
                <x-slot:rail>
                    @if ($memo)
                        <div class="{{ Ui::CARD }} p-4 space-y-3">
                            <p class="{{ Ui::MICRO }}">This memo so far</p>
                            <dl class="grid grid-cols-2 gap-3">
                                <div><dt class="text-[11px] text-slate-400">Days · nights</dt><dd class="text-[14px] font-bold text-slate-800 font-numeric">{{ $memo->days }} · {{ $memo->nights }}</dd></div>
                                <div><dt class="text-[11px] text-slate-400">Team</dt><dd class="text-[14px] font-bold text-slate-800 font-numeric">{{ $memo->participants->sum(fn ($p) => $p->people()) }}</dd></div>
                                <div class="col-span-2"><dt class="text-[11px] text-slate-400">Planned cost</dt><dd class="text-[16px] font-bold text-emerald-700 font-numeric"><span class="text-[10px] text-slate-400 mr-0.5">KES</span>{{ Money::format($memo->costs->sum(fn ($c) => Money::toCents($c->estimated_amount))) }}</dd></div>
                            </dl>
                            <a href="{{ route('memo-request.preview', $token) }}" target="_blank" rel="noopener" class="{{ Ui::BTN_TINT }} w-full justify-center"><i class="fa-solid fa-file-pdf text-[10px]"></i>Preview the memo</a>
                        </div>
                    @else
                        <p class="px-1 text-[12px] text-slate-400 leading-relaxed"><i class="fa-solid fa-circle-info mr-1"></i>Steps 3 to 5 open once you save the draft in step 2.</p>
                    @endif
                </x-slot:rail>

                <form wire:submit="save" class="flex flex-col gap-10">
                    <x-memo.step id="step-details" number="1" title="About the activity" line="What the activity is and why it is needed. This is the body of the memo; the fields marked * are needed." :done="$aboutDone">
                        <x-ui.card title="The activity" icon="fa-clipboard-list" padded>
                            @include('livewire.activities.partials.form-about')
                        </x-ui.card>
                        @unless ($aboutDone)
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3">
                                <p class="text-[12px] text-emerald-900"><i class="fa-solid fa-circle-info mr-1.5"></i>Fill in the fields marked * to go on to step 2.</p>
                                <button type="button" wire:click="continueToDates" wire:loading.attr="disabled" wire:target="continueToDates" class="{{ Ui::BTN_PRIMARY }}">Continue to step 2<i class="fa-solid fa-arrow-down text-xs"></i></button>
                            </div>
                        @endunless
                    </x-memo.step>

                    <x-memo.step id="step-dates" number="2" title="Dates, venue and budget" line="When and where the activity takes place, and your estimate of what it costs." :done="(bool) $memo" :locked="! $aboutDone">
                        @if (! $aboutDone)
                            <x-memo.step-locked message="Opens when the required fields in step 1 are filled in." />
                        @else
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                                <x-ui.card title="Dates" icon="fa-calendar" padded>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <x-ui.field label="Planned start date" for="mr-sd" error="start_date" required><input id="mr-sd" type="date" wire:model.live="start_date" min="{{ today()->toDateString() }}" class="{{ Ui::CONTROL }}"></x-ui.field>
                                        <x-ui.field label="Expected completion date" for="mr-ed" error="end_date" required><input id="mr-ed" type="date" wire:model.live="end_date" min="{{ $start_date ?: today()->toDateString() }}" class="{{ Ui::CONTROL }}"></x-ui.field>
                                        <div class="sm:col-span-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5" aria-live="polite">
                                            <p class="{{ Ui::MICRO }}">Total days · total nights</p>
                                            @if ($period)
                                                <p class="text-[13px] font-bold text-emerald-800 font-numeric">{{ $period->days }} {{ Str::plural('day', $period->days) }} · {{ $period->nights() }} {{ Str::plural('night', $period->nights()) }}</p>
                                                <p class="text-[11px] text-emerald-700">{{ $period->departure() ? 'Nights include the departure night, '.Ui::date($period->departure()).'.' : 'Same-day activity: no night away.' }}</p>
                                            @else
                                                <p class="text-[13px] text-slate-500">Choose both dates.</p>
                                            @endif
                                            <span wire:loading wire:target="end_date,start_date" class="text-[11px] text-slate-400">Recalculating…</span>
                                        </div>
                                    </div>
                                </x-ui.card>
                                <x-ui.card title="Venue and location" icon="fa-location-dot" padded>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <x-ui.field label="County" for="mr-co" error="county_id" hint="Needed for allowances (DSA) when officers travel."><select id="mr-co" wire:model.live="county_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($counties as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                                        <x-ui.field label="Constituency" for="mr-k" error="constituency_id"><select id="mr-k" wire:model="constituency_id" class="{{ $sel }}" @disabled($constituencies->isEmpty())><option value="">{{ $county_id ? 'Any' : 'County first' }}</option>@foreach ($constituencies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                                        <x-ui.field label="Venue" for="mr-v" error="venue" required class="sm:col-span-2"><input id="mr-v" type="text" wire:model="venue" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="e.g. NGCDF Board headquarters"></x-ui.field>
                                    </div>
                                </x-ui.card>
                            </div>
                            <x-ui.card title="Budget" icon="fa-coins" padded>
                                <x-ui.field label="Estimated budget (KES)" for="mr-b" error="requested_budget" hint="Your own estimate. The costed plan comes from the team and cost lines further down; allowances (DSA) are calculated for you."><input id="mr-b" type="text" inputmode="decimal" wire:model="requested_budget" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 250000"></x-ui.field>
                            </x-ui.card>
                        @endif
                    </x-memo.step>

                    @if ($aboutDone)
                        <div class="sticky bottom-0 z-10 -mx-1 px-1 pb-1">
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white/95 backdrop-blur px-4 py-3 shadow-lg shadow-slate-900/5 sm:ml-11">
                                <p class="text-[12px] text-slate-500" aria-live="polite">
                                    <span wire:dirty class="text-amber-700"><i class="fa-solid fa-circle text-[7px] mr-1.5 align-middle"></i>Unsaved changes</span>
                                    <span wire:dirty.remove>
                                        @if ($memo)<i class="fa-solid fa-cloud-arrow-up text-emerald-600 mr-1.5"></i>Draft {{ $memo->reference }} · saved
                                        @else<i class="fa-solid fa-circle-info text-slate-400 mr-1.5"></i>Save the draft to add the team and costs.
                                        @endif
                                    </span>
                                </p>
                                <button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-floppy-disk text-xs"></i><span wire:loading.remove wire:target="save">{{ $memo ? 'Save changes' : 'Save draft and continue' }}</span><span wire:loading wire:target="save">Saving…</span></button>
                            </div>
                        </div>
                    @endif
                </form>

                <x-memo.step id="step-team" number="3" title="Team" line="The officers taking part. Allowances (DSA) are worked out from each officer's job grade and nights away." :done="$steps[2]['done']" :locked="! $memo">
                    @if (! $memo)
                        <x-memo.step-locked />
                    @else
                        <x-ui.card title="Team" icon="fa-users" :count="$memo->participants->count()">
                            @if ($memo->participants->isNotEmpty())
                                <ul class="divide-y divide-slate-100 border-b border-slate-100">
                                    @foreach ($memo->participants as $p)
                                        <li class="px-5 py-3 flex items-center gap-3 text-[13px]" wire:key="mp-{{ $p->id }}">
                                            <span class="w-8 h-8 rounded-full bg-emerald-50 text-emerald-700 flex items-center justify-center text-[11px] font-bold shrink-0">{{ Str::of($p->displayName())->explode(' ')->take(2)->map(fn ($w) => Str::substr($w, 0, 1))->implode('') }}</span>
                                            <span class="flex-1 min-w-0"><span class="block font-semibold text-slate-700">{{ $p->displayName() }}</span><span class="block text-[11px] text-slate-400">{{ $p->roleLabel() }}{{ $p->staff?->department ? ' · '.$p->staff->department->name : '' }}</span></span>
                                            <button type="button" wire:click="removeParticipant({{ $p->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove {{ $p->displayName() }}"><i class="fa-solid fa-xmark text-xs"></i></button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <div class="p-5 {{ $memo->participants->isNotEmpty() ? 'bg-slate-50/50' : '' }}">
                                <p class="text-[12px] font-semibold text-slate-600 mb-3">{{ $memo->participants->isEmpty() ? 'Add the officers taking part' : 'Add more officers' }}</p>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <div class="relative flex items-center flex-1">
                                        <i class="fa-solid fa-magnifying-glass absolute left-3 text-gray-400 text-xs pointer-events-none"></i>
                                        <input type="search" wire:model.live.debounce.300ms="staffSearch" class="{{ Ui::CONTROL }} pl-9" placeholder="Search the staff list by name or PF number…" aria-label="Search staff">
                                    </div>
                                    <div class="flex flex-col sm:flex-row gap-2 shrink-0" x-data="{ role: $wire.entangle('role') }">
                                        <select x-model="role" class="{{ $sel }} sm:w-40" aria-label="Role">@foreach ($roles as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select>
                                        <input x-show="role === 'other'" x-cloak type="text" wire:model="roleOther" maxlength="100" class="{{ Ui::CONTROL }} sm:w-40" placeholder="Specify the role" aria-label="Specify the role">
                                    </div>
                                    <button type="button" wire:click="addOfficers" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="addOfficers"><i class="fa-solid fa-user-plus text-xs"></i>Add to team</button>
                                </div>
                                @error('picked')<p class="text-xs text-red-600 mt-1" role="alert">{{ $message }}</p>@enderror
                                @error('roleOther')<p class="text-xs text-red-600 mt-1" role="alert">{{ $message }}</p>@enderror
                                @if ($matches->isNotEmpty())
                                    <ul class="mt-2 max-h-56 overflow-y-auto border border-slate-200 rounded-lg divide-y divide-slate-100 bg-white">
                                        @foreach ($matches as $s)
                                            <li wire:key="ms-{{ $s->id }}"><label class="flex items-center gap-3 px-3 py-2 text-[13px] cursor-pointer hover:bg-emerald-50">
                                                <input type="checkbox" value="{{ $s->id }}" wire:model="picked" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                                <span><span class="font-semibold text-slate-700">{{ $s->name }}</span> <span class="text-slate-400">· {{ $s->designation?->name ?? 'No designation' }} · {{ $s->department?->name ?? 'No department' }}</span></span>
                                            </label></li>
                                        @endforeach
                                    </ul>
                                @endif
                                @foreach ($warnings as $w)<p class="text-[12px] text-amber-700 mt-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>{{ $w }}</p>@endforeach
                                <p class="text-[11px] text-slate-400 mt-2">An officer already on another approved activity on these days cannot be added.</p>
                            </div>
                        </x-ui.card>
                    @endif
                </x-memo.step>

                <x-memo.step id="step-costs" number="4" title="Costs" line="Travel, venue, meals and other costs. Allowances (DSA) appear here by themselves." :done="$steps[3]['done']" :locked="! $memo">
                    @if (! $memo)
                        <x-memo.step-locked />
                    @else
                        <x-ui.card title="Planned costs" icon="fa-coins" :count="'KES '.Money::format($memo->costs->sum(fn ($c) => Money::toCents($c->estimated_amount)))">
                            @if ($memo->costs->isNotEmpty())
                                <ul class="divide-y divide-slate-100 border-b border-slate-100">
                                    @foreach ($memo->costs as $c)
                                        <li class="px-5 py-3 flex items-center justify-between gap-3 text-[13px]" wire:key="mc-{{ $c->id }}">
                                            <span class="min-w-0"><span class="font-semibold text-slate-700">{{ $c->category?->name }}</span>@if ($c->is_computed)<x-ui.badge classes="bg-emerald-50 text-emerald-700" class="ml-1">Calculated</x-ui.badge>@endif
                                                <span class="block text-[11px] text-slate-400">{{ $c->participant?->displayName() ?? 'Whole activity' }}{{ $c->description ? ' · '.$c->description : '' }}{{ $c->calculationLabel() ? ' · '.$c->calculationLabel() : '' }}</span></span>
                                            <span class="flex items-center gap-2 shrink-0"><x-ui.money :value="Money::toCents($c->estimated_amount)" />@unless ($c->is_computed)<button type="button" wire:click="removeCost({{ $c->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove cost line"><i class="fa-solid fa-xmark text-xs"></i></button>@endunless</span>
                                        </li>
                                    @endforeach
                                    <li class="px-5 py-3 flex justify-between text-[13px] font-semibold bg-slate-50"><span>Total</span><x-ui.money :value="$memo->costs->sum(fn ($c) => Money::toCents($c->estimated_amount))" /></li>
                                </ul>
                            @endif
                            <div class="p-5 {{ $memo->costs->isNotEmpty() ? 'bg-slate-50/50' : '' }}">
                                <p class="text-[12px] font-semibold text-slate-600 mb-3">Add a cost line</p>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-start">
                                    <x-ui.field label="Cost type" for="mc-c" error="costCategory" required><select id="mc-c" wire:model="costCategory" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($costCategories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                                    <x-ui.field label="For" for="mc-p" error="costParticipant"><select id="mc-p" wire:model="costParticipant" class="{{ $sel }}"><option value="">The whole activity</option>@foreach ($memo->participants as $p)<option value="{{ $p->id }}">{{ $p->displayName() }}</option>@endforeach</select></x-ui.field>
                                    <x-ui.field label="Description" for="mc-d" error="costDescription"><input id="mc-d" type="text" wire:model="costDescription" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
                                </div>
                                <x-memo.cost-method class="mt-3" :headcount="$memo->staff_count + $memo->external_count" />
                                <div class="flex justify-end mt-3"><button type="button" wire:click="addCost" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="addCost"><i class="fa-solid fa-plus text-xs"></i>Add cost line</button></div>
                            </div>
                        </x-ui.card>
                    @endif
                </x-memo.step>

                <x-memo.step id="step-review" number="5" title="Check and submit" line="Check what is still needed, attach any supporting document, preview the memo and send it to your Head of Department." :done="$steps[4]['done']" :locked="! $memo">
                    @if (! $memo)
                        <x-memo.step-locked />
                    @else
                        <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                            <x-ui.card :title="$blocking->isEmpty() ? 'Ready to submit' : 'Before you submit'" icon="{{ $blocking->isEmpty() ? 'fa-circle-check' : 'fa-list-check' }}" class="lg:col-span-3" padded>
                                @if ($issues === [])
                                    <p class="text-[13px] text-emerald-700"><i class="fa-solid fa-circle-check mr-1"></i>Nothing is missing. Preview the memo, then submit it.</p>
                                @else
                                    <ul class="space-y-2">
                                        @foreach ($issues as $issue)
                                            <li class="flex items-start gap-2 text-[13px] {{ $issue['blocking'] ? 'text-red-700' : 'text-amber-700' }}"><i class="fa-solid {{ $issue['blocking'] ? 'fa-circle-xmark' : 'fa-triangle-exclamation' }} mt-0.5"></i><span>{{ $issue['message'] }}@if ($issue['blocking'])<span class="text-[10px] font-bold uppercase tracking-wide ml-1.5">Needed</span>@endif</span></li>
                                        @endforeach
                                    </ul>
                                @endif
                                <p class="text-[12px] text-slate-500 mt-4 pt-4 border-t border-slate-100">The system writes the memo in the Board's format from what you entered.</p>
                            </x-ui.card>
                            <x-ui.card title="Supporting documents" icon="fa-paperclip" :count="$memo->documents->count()" class="lg:col-span-2" padded>
                                <div class="space-y-1.5 mb-3">
                                    @forelse ($memo->documents as $d)
                                        <p class="flex items-center gap-2 px-3 py-2 border border-slate-100 rounded-lg text-[13px] text-slate-700"><i class="fa-solid fa-file text-emerald-600"></i><span class="truncate">{{ $d->original_name }}</span></p>
                                    @empty
                                        <p class="text-[12px] text-slate-400">Optional, for example an invitation letter.</p>
                                    @endforelse
                                </div>
                                <x-ui.field label="Attach a document" for="md-f" error="document" hint="PDF, Word, Excel or image, up to 10 MB."><input id="md-f" type="file" wire:model="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" class="{{ $file }}"><div wire:loading wire:target="document" class="text-[11px] text-slate-400 mt-1">Uploading…</div></x-ui.field>
                            </x-ui.card>
                        </div>

                        <div class="rounded-xl border {{ $blocking->isEmpty() ? 'border-emerald-200 bg-gradient-to-r from-emerald-50 to-white' : 'border-slate-200 bg-white' }} px-5 py-4">
                            @if ($confirmSubmit)
                                <p class="text-[14px] font-bold text-slate-800">Submit it to your Head of Department?</p>
                                <p class="text-[12px] text-slate-500">You cannot change it after this unless they return it to you.</p>
                                <div class="flex flex-wrap gap-2 mt-3">
                                    <button type="button" wire:click="$set('confirmSubmit', false)" class="{{ Ui::BTN_NEUTRAL }}">Not yet</button>
                                    <button type="button" wire:click="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="submit"><i class="fa-solid fa-paper-plane text-xs"></i>Submit to the Head of Department</button>
                                </div>
                            @else
                                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[14px] font-bold text-slate-800">{{ $blocking->isEmpty() ? 'Everything needed is in place.' : $blocking->count().' '.Str::plural('item', $blocking->count()).' still needed before you can submit.' }}</p>
                                        <p class="text-[12px] text-slate-500">Your Head of Department reviews it and sends it on, returns it to you with comments, or declines it.</p>
                                    </div>
                                    <a href="{{ route('memo-request.preview', $token) }}" target="_blank" rel="noopener" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-pdf text-xs text-red-600"></i>Preview the memo</a>
                                    <button type="button" wire:click="$set('confirmSubmit', true)" class="{{ Ui::BTN_PRIMARY }}" @disabled($blocking->isNotEmpty())><i class="fa-solid fa-paper-plane text-xs"></i>Submit to the Head of Department</button>
                                </div>
                            @endif
                        </div>
                    @endif
                </x-memo.step>
            </x-memo.builder>
        @endif
    @endif
</div>
