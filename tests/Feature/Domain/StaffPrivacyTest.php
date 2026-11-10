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

it('refuses the staff list to everyone else, whatever the URL or the component call', function (string $role) {
    $user = $role === 'hod' ? $this->hod : User::factory()->{$role}()->create();

    $this->actingAs($user)->get(route('staff.index'))->assertForbidden();
    $this->actingAs($user)->get(route('staff.index', ['department_id' => $this->hr->id, 'search' => 'Other']))->assertForbidden();
    Livewire::actingAs($user)->test(StaffList::class)->assertForbidden();
})->with(['hod', 'chiefOfStaff', 'assistant']);

it('refuses the staff list to an account without a staff permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('staff.index'))->assertForbidden();
});

it('lists nobody in a Head\'s team picker until they search, then any officer by name or PF number, never by email', function () {
    $memo = departmentMemo($this->hod);

    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $memo])->set('showAdd', true)
        ->assertDontSee('Own Officer')->assertDontSee('Other Officer')->assertSee('Type at least two letters')
        ->set('staffDepartment', (string) $this->hr->id)->assertDontSee('Other Officer')
        ->set('staffDepartment', '')
        ->set('staffSearch', 'O')->assertDontSee('Other Officer')
        ->set('staffSearch', 'other.secret')->assertDontSee('Other Officer')
        ->set('staffSearch', 'Own Off')->assertSee('Own Officer')->assertDontSee('Other Officer')
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
