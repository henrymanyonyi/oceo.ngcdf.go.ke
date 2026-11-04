<?php

use App\Enums\ActivityStatus;
use App\Enums\ReviewOffice;
use App\Livewire\Activities\Form;
use App\Livewire\Memos\Compose;
use App\Livewire\Memos\Team;
use App\Models\Activity;
use App\Models\County;
use App\Models\Department;
use App\Services\ActivityLifecycle;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
});

it('opens the memo builder with every step on one page, the later ones waiting for the first save', function () {
    $this->actingAs($this->hod)->get(route('memos.create'))->assertOk()
        ->assertSeeInOrder(['About the activity', 'Reference, dates and budget', 'Venue and location', 'Team', 'Costs', 'Review and submit'])
        ->assertSee('Continue to step 2')
        ->assertSee('Opens when every field in step 1 is filled in.')
        ->assertDontSee('Save draft and continue')
        ->assertSee('Opens when you save the draft in step 2.');
});

it('opens step 2 only when every field about the activity is filled in', function () {
    $about = memoAbout();

    $form = Livewire::actingAs($this->hod)->test(Form::class, ['embedded' => true])
        ->set('activity_category_id', $about['activity_category_id'])
        ->call('continueToDates')
        ->assertHasErrors(['title', 'activity_type_id', 'purpose', 'justification', 'expected_outputs', 'risks', 'target_audience', 'notes'])
        ->assertSet('aboutDone', false)
        ->assertNotDispatched('about-done');

    // Spaces alone are not an answer.
    $form->fill([...$about, 'notes' => '   '])->call('continueToDates')->assertHasErrors(['notes'])->assertSet('aboutDone', false);

    $form->set('notes', $about['notes'])->call('continueToDates')
        ->assertHasNoErrors()
        ->assertSet('aboutDone', true)
        ->assertDispatched('about-done')
        ->assertSee('Save draft and continue');

    // The same rule holds on save, so step 1 cannot be skipped.
    Livewire::actingAs($this->hod)->test(Form::class, ['embedded' => true])
        ->set('aboutDone', true)
        ->fill(['title' => 'x', 'purpose' => 'y', 'activity_category_id' => $about['activity_category_id'], 'start_date' => today()->addDays(20)->toDateString(), 'source_reference' => 'REF/1'])
        ->call('save')
        ->assertHasErrors(['justification', 'expected_outputs', 'risks', 'target_audience', 'notes', 'activity_type_id']);
    expect(Activity::query()->count())->toBe(0);
});

it('saves the first two steps as a draft without leaving the page, then keeps saving it', function () {
    $form = Livewire::actingAs($this->hod)->test(Form::class, ['embedded' => true])
        ->fill(memoAbout(['title' => 'ICT clinics, North Rift']))
        ->call('continueToDates')
        ->set('start_date', today()->addDays(20)->toDateString())
        ->set('end_date', today()->addDays(22)->toDateString())
        ->set('source_reference', 'NG-CDF BOARD/ICT/009/2026')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect();

    $memo = Activity::sole();
    $form->assertDispatched('memo-saved', id: $memo->id, created: true)
        ->assertSet('activity.id', $memo->id);

    // The team changes the memo's totals elsewhere on the page; the next save is not "someone else's change".
    $this->travel(1)->minutes();
    $memo->touch();
    $form->dispatch('team-changed')
        ->set('title', 'ICT clinics, North Rift and Western')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('memo-saved', id: $memo->id, created: false);

    expect(Activity::sole())->title->toBe('ICT clinics, North Rift and Western')->days->toBe(3)->status->toBe(ActivityStatus::Draft);
});

it('opens the venue, team, costs and review steps once the draft exists, and adds a location in place', function () {
    $memo = departmentMemo($this->hod);
    $county = County::query()->firstOrFail();

    Livewire::actingAs($this->hod)->test(Compose::class)
        ->assertDontSee($memo->reference)
        ->dispatch('memo-saved', id: $memo->id, created: true)
        ->assertSet('activity.id', $memo->id)
        ->call('addLocation')->assertHasErrors('locCounty')
        ->set('locCounty', (string) $county->id)->set('locVenue', 'Eldoret town hall')
        ->call('addLocation')
        ->assertHasNoErrors()
        ->assertDispatched('memo-changed')
        ->assertSee('Eldoret town hall')
        ->assertSee('Submit memo');

    expect($memo->locations()->where('county_id', $county->id)->exists())->toBeTrue();

    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $memo, 'embedded' => true])
        ->assertSee('Add officers')->assertSee('Add cost line')->assertDontSee('Back to the memo');
});

it('submits from the last step and moves on to the memo page', function () {
    $memo = departmentMemo($this->hod);

    Livewire::actingAs($this->hod)->test(Compose::class, ['activity' => $memo])
        ->call('openSubmit')->assertSet('showSubmit', true)
        ->set('route', 'review')->set('office', ReviewOffice::ChiefOfStaff->value)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('memos.show', $memo));

    expect($memo->fresh()->status)->toBe(ActivityStatus::UnderReview);
});

it('keeps the builder to memos the Head may still change, and sends the Office of the CEO to its own form', function () {
    $memo = departmentMemo($this->hod);
    app(ActivityLifecycle::class)->submitForReview($memo, $this->hod, ReviewOffice::ChiefOfStaff);

    $this->actingAs($this->hod)->get(route('memos.edit', $memo))->assertForbidden();
    $this->actingAs(ceo())->get(route('memos.create'))->assertRedirect(route('activities.create'));
});
