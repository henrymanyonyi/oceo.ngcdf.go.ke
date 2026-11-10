@use('App\Support\Ui')
{{--
    "Officer not on the list?" for a team picker (App\Livewire\Concerns\AddsMissingOfficers): add one officer
    to the staff list, or import a list in the staff template. An import from here only adds who is missing;
    officers already on the list are never changed. Only the CEO and the System Administrator open the staff list.
--}}
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $columns = \App\Services\StaffImporter::COLUMNS;
@endphp

<x-ui.modal show="showStaffForm" :open="$showStaffForm" icon="fa-id-badge" title="Add an officer to the staff list" subtitle="Use the details on the officer's HR record. Search by name or PF number first: they may already be on the list under another department.">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <x-ui.field label="PF number" for="ns-pf" error="staffNumber" required><input id="ns-pf" type="text" wire:model="staffNumber" maxlength="30" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
        <x-ui.field label="Name" for="ns-n" error="staffName" required><input id="ns-n" type="text" wire:model="staffName" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-ui.field label="Department" for="ns-d" error="staffDepartmentId" required><select id="ns-d" wire:model="staffDepartmentId" class="{{ $sel }}"><option value="">Choose…</option>@foreach (\App\Models\Department::query()->active()->ordered()->get(['id', 'name']) as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Designation" for="ns-des" error="staffDesignationId"><select id="ns-des" wire:model="staffDesignationId" class="{{ $sel }}"><option value="">Choose…</option>@foreach (\App\Models\Designation::query()->ordered()->get(['id', 'name']) as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Job grade" for="ns-g" error="staffJobGrade" hint="The scale from the HR register (1 to 10); it sets the officer's DSA rate."><input id="ns-g" type="text" wire:model="staffJobGrade" maxlength="20" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-ui.field label="Duty station" for="ns-o" error="staffOfficeId"><select id="ns-o" wire:model="staffOfficeId" class="{{ $sel }}"><option value="">Choose…</option>@foreach (\App\Models\Office::query()->ordered()->get(['id', 'name']) as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></x-ui.field>
    </div>
    <x-slot:footer>
        <button type="button" wire:click="$set('showStaffForm', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
        <button type="button" wire:click="saveStaff" wire:loading.attr="disabled" wire:target="saveStaff" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Add to staff list</button>
    </x-slot:footer>
</x-ui.modal>

<x-ui.modal show="showStaffImport" :open="$showStaffImport" icon="fa-file-import" title="Import officers missing from the list" subtitle="Officers not yet on the staff list are added and ticked for the team. Officers already on the list are left exactly as they are. Nothing is changed or deleted.">
    <p class="text-[13px] text-slate-600">Columns (first row): <span class="font-numeric text-slate-800">{{ implode(', ', $columns) }}</span>. Name is required; PF Number is required for permanent staff. Department and Duty Station must match the lists in the system. Use <span class="font-semibold">Check file</span> to see what would be added before saving anything.</p>
    <x-ui.field label="File" for="mi-f" error="staffFile" required hint="Excel or CSV, up to 2 MB."><input id="mi-f" type="file" wire:model="staffFile" accept=".xlsx,.xls,.csv" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold"></x-ui.field>
    @if ($staffImportCheck)
        <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-3"><p class="text-[13px] text-emerald-800">{{ $staffImportCheck }}</p></div>
    @endif
    @if ($staffImportErrors)
        <div class="rounded-lg bg-red-50 border border-red-200 p-3 max-h-40 overflow-y-auto"><p class="text-[13px] font-semibold text-red-700 mb-1">Nothing was imported. Fix these rows:</p>@foreach ($staffImportErrors as $e)<p class="text-[12px] text-red-700">{{ $e }}</p>@endforeach</div>
    @endif
    <x-slot:footer>
        <button type="button" wire:click="$set('showStaffImport', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
        <button type="button" wire:click="checkStaffImport" wire:loading.attr="disabled" wire:target="checkStaffImport,importStaff,staffFile" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-list-check text-xs"></i><span wire:loading.remove wire:target="checkStaffImport">Check file</span><span wire:loading wire:target="checkStaffImport">Checking…</span></button>
        <button type="button" wire:click="importStaff" wire:loading.attr="disabled" wire:target="checkStaffImport,importStaff,staffFile" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-upload text-xs"></i><span wire:loading.remove wire:target="importStaff">Import</span><span wire:loading wire:target="importStaff">Importing…</span></button>
    </x-slot:footer>
</x-ui.modal>
