@use('App\Support\Ui')
    {{-- Add officers --}}
    <x-ui.modal show="showAdd" :open="$showAdd" size="large" icon="fa-user-plus" title="Add officers" subtitle="Tick the officers taking part. If someone is not on the list, add them to the staff list here.">
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex items-center flex-1">
                <i class="fa-solid fa-magnifying-glass absolute left-3 text-gray-400 text-xs pointer-events-none"></i>
                <input type="search" wire:model.live.debounce.300ms="staffSearch" placeholder="Search name or PF number…" aria-label="Search staff" class="{{ Ui::CONTROL }} pl-9">
            </div>
            <select wire:model.live="staffDepartment" class="{{ $sel }} sm:w-56" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
        </div>
        <x-memo.role-select id="add-role" class="sm:grid sm:grid-cols-2 sm:gap-3 sm:space-y-0" />
        @error('selectedStaff')<p class="text-xs text-red-600" role="alert">{{ $message }}</p>@enderror
        @php
            $selectable = $candidates->reject(fn ($st) => isset($unavailable[$st->id]))->pluck('id');
            $shownTicked = $selectable->intersect($selectedIds)->count();
            $allShown = $selectable->isNotEmpty() && $shownTicked === $selectable->count();
            $leftOut = $candidates->count() - $selectable->count();
        @endphp
        @if ($selectable->isNotEmpty())
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 rounded-lg bg-slate-50 border border-slate-200">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" wire:click="toggleShown" @checked($allShown)
                        x-data x-effect="$el.indeterminate = {{ $shownTicked > 0 && ! $allShown ? 'true' : 'false' }}"
                        class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="text-[13px] font-semibold text-slate-700">{{ $allShown ? 'Clear all shown' : 'Select all shown' }}</span>
                </label>
                <span class="text-[12px] text-slate-500 font-numeric">{{ count($selectedIds) }} selected</span>
                @if ($leftOut > 0)<span class="text-[12px] text-slate-400">· {{ $leftOut }} not available {{ $leftOut === 1 ? 'is' : 'are' }} left out</span>@endif
                @if ($candidates->count() >= 100)<span class="text-[12px] text-amber-700">· Only the first 100 are shown; search or pick a department to narrow the list.</span>@endif
            </div>
        @endif
        <div class="border border-slate-200 rounded-lg divide-y divide-slate-100 max-h-[45vh] overflow-y-auto">
            @forelse ($candidates as $st)
                @php
                    $ticked = in_array($st->id, $selectedIds);
                    $busy = collect($unavailable[$st->id] ?? []);
                    $maybe = collect($pending[$st->id] ?? []);
                @endphp
                <div class="px-3 py-2.5 {{ $busy->isNotEmpty() ? 'bg-slate-50' : ($ticked ? 'bg-emerald-50/50' : '') }}" wire:key="cand-{{ $st->id }}">
                    <label class="flex items-start gap-3 {{ $busy->isNotEmpty() ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                        <input type="checkbox" value="{{ $st->id }}" wire:model.live="selectedStaff" class="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" @disabled($busy->isNotEmpty() && ! $ticked)>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[13px] font-medium text-slate-700">{{ $st->name }} <span class="text-[11px] text-slate-400 font-numeric">{{ $st->staff_number }}</span></span>
                            <span class="block text-[11px] text-slate-400">{{ $st->designation?->name ?? 'No designation' }} · {{ $st->department?->name ?? 'No department' }} · grade {{ $st->job_grade ?: 'not set' }}</span>
                            @if ($busy->isNotEmpty())
                                <span class="block text-[12px] text-red-600"><i class="fa-solid fa-ban mr-1"></i>Not available until {{ $busy->max('to')->format('d M Y') }}: on another approved activity ({{ $busy->map(fn ($b) => Ui::dateRange($b['from'], $b['to']))->unique()->implode(', ') }}).</span>
                            @elseif ($maybe->isNotEmpty())
                                <span class="block text-[12px] text-amber-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Also on a memo awaiting decision on these dates. If that memo is approved first, they will not be available.</span>
                            @endif
                        </span>
                    </label>
                </div>
            @empty
                <p class="px-3 py-6 text-center text-[13px] text-slate-400">No officer matches. Check the spelling, or add the officer to the staff list.</p>
            @endforelse
        </div>
        <button type="button" wire:click="openStaffForm" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-id-badge text-[9px]"></i>Officer not on the list? Add them to the staff list</button>
        <x-slot:footer>
            <button type="button" wire:click="$set('showAdd', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addStaff" wire:loading.attr="disabled" wire:target="addStaff" class="{{ Ui::BTN_PRIMARY }}" @disabled($selectedIds === [])><i class="fa-solid fa-user-plus text-xs"></i>Add {{ count($selectedIds) ?: '' }} to team</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Add an officer missing from the staff list --}}
    <x-ui.modal show="showStaffForm" :open="$showStaffForm" icon="fa-id-badge" title="Add an officer to the staff list" subtitle="Use the details on the officer's HR record. You can correct officers you add from the Staff list page.">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-ui.field label="PF number" for="ns-pf" error="staffNumber" required><input id="ns-pf" type="text" wire:model="staffNumber" maxlength="30" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
            <x-ui.field label="Name" for="ns-n" error="staffName" required><input id="ns-n" type="text" wire:model="staffName" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Department" for="ns-d" error="staffDepartmentId" required><select id="ns-d" wire:model="staffDepartmentId" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Designation" for="ns-des" error="staffDesignationId"><select id="ns-des" wire:model="staffDesignationId" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($designations as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Job grade" for="ns-g" error="staffJobGrade" hint="The scale from the HR register (1 to 10); it sets the officer's DSA rate."><input id="ns-g" type="text" wire:model="staffJobGrade" maxlength="20" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Duty station" for="ns-o" error="staffOfficeId"><select id="ns-o" wire:model="staffOfficeId" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($offices as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></x-ui.field>
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showStaffForm', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveStaff" wire:loading.attr="disabled" wire:target="saveStaff" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Add to staff list</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- A group by number, without names --}}
    <x-ui.modal show="showGroup" :open="$showGroup" size="slim" icon="fa-people-group" title="Add a group by number" subtitle="Officers who take part without being named, for example 10 Fund Account Managers. DSA is their scale's rate for each of them.">
        <x-ui.field label="Who they are" for="gr-l" error="groupLabel" required><input id="gr-l" type="text" wire:model="groupLabel" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="e.g. Fund Account Managers (FAMs)"></x-ui.field>
        <div class="grid grid-cols-2 gap-3">
            <x-ui.field label="Number" for="gr-n" error="groupCount" required><input id="gr-n" type="number" min="1" max="500" wire:model="groupCount" class="{{ Ui::CONTROL }} font-numeric" placeholder="10"></x-ui.field>
            <x-ui.field label="DSA scale" for="gr-g" error="groupGrade" required><select id="gr-g" wire:model="groupGrade" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($grades as $g)<option value="{{ $g }}">{{ $g === 'INTERN' ? 'INTERN (attachees)' : 'Scale '.$g }}</option>@endforeach</select></x-ui.field>
        </div>
        <x-memo.role-select id="gr-r" />
        <p class="text-[12px] text-slate-500">Nobody is named, so availability and field days are not checked for them.</p>
        <x-slot:footer>
            <button type="button" wire:click="$set('showGroup', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addGroup" wire:loading.attr="disabled" wire:target="addGroup" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-people-group text-xs"></i>Add</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- External participant --}}
    <x-ui.modal show="showExternal" :open="$showExternal" size="medium" icon="fa-user-tie" :title="$externalId ? 'Change the external participant' : 'Add an external participant'" subtitle="Someone who is not NG-CDF Board staff, such as an invited representative or a consultant.">
        <x-memo.external-fields :activity="$a" :categories="$externalCategories" :grades="$grades" :rateUnits="$rateUnits" :dsaUnit="$dsaUnit" />
        <x-slot:footer>
            <button type="button" wire:click="$set('showExternal', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addExternal" wire:loading.attr="disabled" wire:target="addExternal" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>{{ $externalId ? 'Save' : 'Add' }}</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Remove from the team --}}
    <x-ui.modal show="showRemove" :open="$showRemove" size="alert" tone="red" icon="fa-user-minus" :title="'Remove '.($selected?->displayName() ?? 'this person').' from the team?'" subtitle="Their DSA and any cost lines for them are removed too.">
        <x-slot:footer>
            <button type="button" wire:click="$set('showRemove', false)" class="{{ Ui::BTN_NEUTRAL }}">Keep</button>
            <button type="button" wire:click="removeParticipant" wire:loading.attr="disabled" wire:target="removeParticipant" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-user-minus text-xs"></i>Remove</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- One person's days --}}
    <x-ui.modal show="showDays" :open="$showDays" size="slim" icon="fa-calendar-days" :title="'Days for '.($selected?->displayName() ?? '')" :subtitle="'The activity runs '.Ui::dateRange($a->start_date, $a->end_date).'.'">
        <div class="grid grid-cols-2 gap-4">
            <x-ui.field label="First day" for="d-s" error="daysStart" required><input id="d-s" type="date" wire:model.live="daysStart" min="{{ $a->start_date->toDateString() }}" max="{{ $a->end_date->toDateString() }}" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Last day" for="d-e" error="daysEnd" required><input id="d-e" type="date" wire:model.live="daysEnd" min="{{ $daysStart ?: $a->start_date->toDateString() }}" max="{{ $a->end_date->toDateString() }}" class="{{ Ui::CONTROL }}"></x-ui.field>
        </div>
        <x-memo.days-summary :start="$daysStart" :end="$daysEnd" :activity="$a" />
        <x-slot:footer>
            <button type="button" wire:click="$set('showDays', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveDays" wire:loading.attr="disabled" wire:target="saveDays" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save days</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- A planned cost line --}}
    <x-ui.modal show="showCost" :open="$showCost" size="medium" icon="fa-coins" :title="$costId ? 'Edit cost line' : 'Add a cost line'" subtitle="For travel, venue, meals, materials and other costs. DSA and external participants' payments are calculated for you.">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <x-ui.field label="Cost type" for="c-cat" error="costCategoryId" required><select id="c-cat" wire:model="costCategoryId" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($costCategories as $cc)<option value="{{ $cc->id }}">{{ $cc->name }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="For" for="c-p" error="costParticipantId"><select id="c-p" wire:model="costParticipantId" class="{{ $sel }}"><option value="">The whole activity</option>@foreach ($a->participants as $p)<option value="{{ $p->id }}">{{ $p->displayName() }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Travel mode" for="c-t" error="costTravelMode"><select id="c-t" wire:model="costTravelMode" class="{{ $sel }}"><option value="">Not travel</option>@foreach ($travelModes as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Description" for="c-d" error="costDescription"><input id="c-d" type="text" wire:model="costDescription" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="e.g. Conference room hire, one day"></x-ui.field>
        </div>
        <x-memo.cost-method :headcount="$a->staff_count + $a->external_count" />
        <x-slot:footer>
            <button type="button" wire:click="$set('showCost', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveCost" wire:loading.attr="disabled" wire:target="saveCost" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save</button>
        </x-slot:footer>
    </x-ui.modal>
