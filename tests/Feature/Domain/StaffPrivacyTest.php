<?php

use App\Livewire\Memos\Board;
use App\Livewire\Memos\Team;
use App\Livewire\StaffList;
use App\Models\Department;
use App\Models\Staff;
use App\Models\User;
use App\Services\DelegatedMemos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hr = Department::query()->where('name', 'Human Resources')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
    $this->own = Staff::factory()->create(['name' => 'Own Officer', 'department_id' => $this->ict->id, 'email' => 'own@ngcdf.go.ke']);
    $this->other = Staff::factory()->create(['name' => 'Other Officer', 'department_id' => $this->hr->id, 'email' => 'other.secret@ngcdf.go.ke']);
});

it('gives the full directory, with email search, to the CEO and the System Administrator only', function (string $role) {
    Livewire::actingAs(User::factory()->{$role}()->create())->test(StaffList::class)
        ->assertSee('Own Officer')->assertSee('Other Officer')
        ->set('search', 'other.secret')->assertSee('Other Officer');
})->with(['ceo', 'admin']);

it('shows a Head of Department only their own department and the officers they added', function () {
    $added = Staff::factory()->create(['name' => 'Added Elsewhere', 'department_id' => $this->hr->id, 'created_by' => $this->hod->id]);

    Livewire::actingAs($this->hod)->test(StaffList::class)
        ->assertSee('Own Officer')->assertSee('Added Elsewhere')->assertDontSee('Other Officer')
        // A department in the URL, a name search or an email search never opens another department.
        ->set('department_id', (string) $this->hr->id)->assertDontSee('Other Officer')
        ->set('search', 'Other Officer')->assertDontSee('Other Officer')
        ->set('search', 'other.secret')->assertDontSee('Other Officer');

    $this->actingAs($this->hod)->get(route('staff.index', ['department_id' => $this->hr->id, 'search' => 'Other']))
        ->assertOk()->assertDontSee('Other Officer');
});

it('lets the Chief of Staff and the Assistant find an officer by name or PF number, never browse or search by email', function (string $role) {
    Livewire::actingAs(User::factory()->{$role}()->create())->test(StaffList::class)
        ->assertDontSee('Own Officer')->assertDontSee('Other Officer')->assertSee('Search for an officer')
        ->set('department_id', (string) $this->hr->id)->assertDontSee('Other Officer')
        ->set('search', 'O')->assertDontSee('Other Officer')
        ->set('search', 'other.secret')->assertDontSee('Other Officer')
        ->set('search', 'Other Off')->assertSee('Other Officer')->assertDontSee('Own Officer');
})->with(['chiefOfStaff', 'assistant']);

it('refuses the staff list to an account without a staff permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('staff.index'))->assertForbidden();
});

it('lets a Head browse only their own department when building a team, and find others by name', function () {
    $memo = departmentMemo($this->hod);

    $team = Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $memo])->set('showAdd', true);

    $team->assertSee('Own Officer')->assertDontSee('Other Officer')
        ->set('staffDepartment', (string) $this->hr->id)->assertDontSee('Other Officer')
        ->set('staffSearch', 'other.secret')->assertDontSee('Other Officer')
        ->set('staffSearch', 'Other Off')->assertSee('Other Officer');
});

it('lets a Head delegate a memo only to an officer of their own department, in the component and the service', function () {
    Livewire::actingAs($this->hod)->test(Board::class)
        ->call('openRequest')
        ->set('requestSearch', 'Other Off')->assertDontSee('Other Officer')
        ->set('requestSearch', 'Own Off')->assertSee('Own Officer');

    expect(fn () => Livewire::actingAs($this->hod)->test(Board::class)->call('openRequest')->call('pickRequestStaff', $this->other->id))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DelegatedMemos::class)->request($this->hod, $this->other, null, 7))
        ->toThrow(AuthorizationException::class);
});
