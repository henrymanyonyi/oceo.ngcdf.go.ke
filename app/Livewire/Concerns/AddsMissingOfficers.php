<?php

namespace App\Livewire\Concerns;

use App\Models\Staff;
use App\Rules\DsaGrade;
use App\Services\StaffImporter;
use App\Services\StaffRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * "Officer not on the list?" in a team picker (November 2026): only the CEO and the System
 * Administrator open the staff list, so whoever builds a team adds a missing officer here, one by
 * one or by importing a list in the staff template. An import from here only adds who is missing:
 * it never changes or deactivates anyone already on the list (StaffImporter $addOnly). Needs
 * `staff.add`; StaffRecords and StaffImporter check it again. View: livewire/partials/missing-officers.
 * The using component uses Livewire's WithFileUploads and RunsActions, and implements
 * missingOfficersAdded() to put the new officers in its picker.
 */
trait AddsMissingOfficers
{
    public bool $showStaffForm = false;

    public bool $showStaffImport = false;

    public string $staffNumber = '';

    public string $staffName = '';

    public string $staffDepartmentId = '';

    public string $staffDesignationId = '';

    public string $staffJobGrade = '';

    public string $staffOfficeId = '';

    public $staffFile = null;

    /** @var list<string> */
    public array $staffImportErrors = [];

    public ?string $staffImportCheck = null;

    public function openStaffForm(): void
    {
        abort_unless(Auth::user()->can('staff.add'), 403);
        $this->resetErrorBag();
        $this->reset(['staffNumber', 'staffName', 'staffDesignationId', 'staffJobGrade', 'staffOfficeId']);
        $this->staffDepartmentId = (string) (Auth::user()->department_id ?? '');
        $this->showStaffForm = true;
    }

    public function saveStaff(StaffRecords $records): void
    {
        $data = $this->validate([
            'staffNumber' => ['required', 'string', 'max:30', Rule::unique('staff', 'staff_number')],
            'staffName' => ['required', 'string', 'max:255'],
            'staffDepartmentId' => ['required', 'exists:departments,id'],
            'staffDesignationId' => ['nullable', 'exists:designations,id'],
            'staffJobGrade' => ['nullable', 'string', 'max:20', new DsaGrade],
            'staffOfficeId' => ['nullable', 'exists:offices,id'],
        ], ['staffNumber.unique' => 'An officer with this PF number is already on the staff list. Search for them instead.'],
            ['staffNumber' => 'PF number', 'staffName' => 'name', 'staffDepartmentId' => 'department', 'staffJobGrade' => 'job grade', 'staffOfficeId' => 'duty station']);

        $staff = $this->attempt(fn () => $records->save([
            'staff_number' => $data['staffNumber'],
            'name' => $data['staffName'],
            'department_id' => $data['staffDepartmentId'],
            'designation_id' => $data['staffDesignationId'] ?: null,
            'job_grade' => $data['staffJobGrade'] ?: null,
            'office_id' => $data['staffOfficeId'] ?: null,
        ], Auth::user()));

        if ($staff instanceof Staff) {
            $this->showStaffForm = false;
            $this->missingOfficersAdded([$staff->id], $staff->staff_number);
            $this->toast("{$staff->name} added to the staff list and ticked. Press Add to team.");
        }
    }

    public function openStaffImport(): void
    {
        abort_unless(Auth::user()->can('staff.add'), 403);
        $this->resetErrorBag();
        $this->reset(['staffFile', 'staffImportErrors', 'staffImportCheck']);
        $this->showStaffImport = true;
    }

    /** What the import would add, without saving anything. */
    public function checkStaffImport(StaffImporter $importer): void
    {
        abort_unless(Auth::user()->can('staff.add'), 403);
        $this->validate(['staffFile' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('activities.max_list_kb')]], [], ['staffFile' => 'file']);

        $result = $importer->import($this->staffFile->getRealPath(), Auth::user(), dryRun: true, addOnly: true);
        $this->staffImportErrors = $result['errors'];
        $this->staffImportCheck = $result['errors'] === []
            ? "Ready: {$result['created']} new ".($result['created'] === 1 ? 'officer' : 'officers').' would be added'
                .($result['skipped'] ? "; {$result['skipped']} already on the list are left as they are" : '').'. Nothing has been saved yet.'
            : null;
    }

    public function importStaff(StaffImporter $importer): void
    {
        abort_unless(Auth::user()->can('staff.add'), 403);
        $this->validate(['staffFile' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('activities.max_list_kb')]], [], ['staffFile' => 'file']);

        $before = Staff::query()->max('id') ?? 0;
        $result = $importer->import($this->staffFile->getRealPath(), Auth::user(), addOnly: true);
        $this->staffImportErrors = $result['errors'];
        $this->staffImportCheck = null;

        if ($result['errors'] === []) {
            $this->reset(['showStaffImport', 'staffFile']);
            $added = Staff::query()->where('id', '>', $before)->where('created_by', Auth::id())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->missingOfficersAdded($added, null);
            $this->toast("{$result['created']} added to the staff list".($added !== [] ? ' and ticked. Press Add to team.' : '.')
                .($result['skipped'] ? " {$result['skipped']} already on the list were left as they are." : ''));
        }
    }

    /**
     * Puts the officers just added in the picker, ticked.
     *
     * @param  list<int>  $staffIds
     */
    abstract protected function missingOfficersAdded(array $staffIds, ?string $search): void;
}
