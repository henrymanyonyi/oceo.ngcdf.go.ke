<?php

use App\Livewire\Activities\Form;
use App\Livewire\Activities\Show;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\Department;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('survives clearing the completion date and explains the error', function ($value) {
    Livewire::actingAs(chiefOfStaff())->test(Form::class)
        ->set('start_date', today()->toDateString())
        ->set('end_date', $value)
        ->assertOk()
        ->call('save')
        ->assertHasErrors('end_date');
})->with(['blank' => '', 'before the start' => fn () => today()->subDay()->toDateString()]);

it('works out the days and nights from the two dates as they change', function () {
    $page = Livewire::actingAs(chiefOfStaff())->test(Form::class)
        ->set('start_date', '2026-11-10')
        ->assertSet('end_date', '2026-11-10')
        ->assertSee('1 day · 0 nights')
        ->assertSee('Same-day activity');

    $page->set('end_date', '2026-11-11')->assertSee('2 days · 3 nights')
        ->set('end_date', '2026-11-13')->assertSee('4 days · 5 nights')
        ->set('end_date', '2026-11-09')->assertSee('The completion date is before the start date.')
        // A later start never leaves the completion date behind it.
        ->set('end_date', '2026-11-13')->set('start_date', '2026-11-20')->assertSet('end_date', '2026-11-20');
});

it('saves the days from the planned start and expected completion dates', function () {
    $cos = chiefOfStaff();

    Livewire::actingAs($cos)->test(Form::class)
        ->set('title', 'Monitoring visit')
        ->set('purpose', 'Verify')
        ->set('approval_request', 'This is to seek your authority for the activity.')
        ->set('activity_category_id', (string) ActivityCategory::query()->value('id'))
        ->set('organising_department_id', (string) Department::query()->value('id'))
        ->set('start_date', '2026-11-10')
        ->set('end_date', '2026-11-13')
        ->call('save')
        ->assertHasNoErrors();

    $activity = Activity::sole();
    expect($activity->days)->toBe(4)->and($activity->nights)->toBe(5)
        ->and($activity->end_date->toDateString())->toBe('2026-11-13');
});

it('asks for the category in words when Other (specify) is chosen, and shows it', function () {
    $other = ActivityCategory::query()->where('requires_detail', true)->sole();
    expect(ActivityCategory::query()->where('name', 'Policy and Management')->exists())->toBeTrue();

    $page = Livewire::actingAs(chiefOfStaff())->test(Form::class)
        ->set('title', 'Board induction')
        ->set('purpose', 'Induct new members')
        ->set('approval_request', 'This is to seek your authority for the activity.')
        ->set('activity_category_id', (string) $other->id)
        ->assertSee('Specify the category')
        ->set('organising_department_id', (string) Department::query()->value('id'))
        ->set('start_date', today()->addDays(5)->toDateString())
        ->set('end_date', today()->addDays(5)->toDateString())
        ->call('save')
        ->assertHasErrors('category_other');

    $page->set('category_other', 'Board induction')->call('save')->assertHasNoErrors();

    expect(Activity::sole()->categoryLabel())->toBe('Other: Board induction');
});

it('survives clearing the postpone and extension day fields', function () {
    Storage::fake('local');
    $activity = capturedActivity(chiefOfStaff());

    Livewire::actingAs(chiefOfStaff())->test(Show::class, ['activity' => $activity])
        ->set('newDays', '')
        ->set('extraDays', '')
        ->assertOk()
        ->set('showExtend', true)
        ->set('reason', 'Test')
        ->call('extend')
        ->assertHasErrors('extraDays');
});
