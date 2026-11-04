@use('App\Support\Ui')
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.field label="Title" for="title" error="title" required class="sm:col-span-2">
                        <input id="title" type="text" wire:model="title" class="{{ Ui::CONTROL }}" placeholder="e.g. FY2026/27 Proposal Review, Baringo" maxlength="255">
                    </x-ui.field>
                    <x-ui.field label="Category" for="cat" error="activity_category_id" required>
                        <select id="cat" wire:model.live="activity_category_id" class="{{ $sel }}">
                            <option value="">Choose…</option>
                            @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->optionLabel() }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    @if ($needsCategoryDetail)
                        <x-ui.field label="Specify the category" for="cat-other" error="category_other" required>
                            <input id="cat-other" type="text" wire:model="category_other" class="{{ Ui::CONTROL }}" maxlength="150" placeholder="e.g. Partnership review">
                        </x-ui.field>
                    @endif
                    <x-ui.field label="Activity type" for="type" error="activity_type_id" :required="$fullAbout && $types->isNotEmpty()">
                        <select id="type" wire:model="activity_type_id" class="{{ $sel }}" @disabled($types->isEmpty())>
                            <option value="">{{ $activity_category_id ? 'Choose…' : 'Choose a category first' }}</option>
                            @foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field label="Purpose (objective)" for="purpose" error="purpose" required class="sm:col-span-2" hint="What the activity is for, in a sentence or two.">
                        <textarea id="purpose" wire:model="purpose" rows="3" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                    <x-ui.field label="Introduction and justification" for="justification" error="justification" :required="$fullAbout" class="sm:col-span-2" hint="The narration: background, why the activity is needed now, and any letter or Act it responds to. Section 1.0 of the memo.">
                        <textarea id="justification" wire:model="justification" rows="5" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                    <x-ui.field label="Expected outcomes and deliverables" for="outputs" error="expected_outputs" :required="$fullAbout" class="sm:col-span-2" hint="What the department will deliver; compared with the back-to-office report.">
                        <textarea id="outputs" wire:model="expected_outputs" rows="2" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                    <x-ui.field label="Key risks to be mitigated" for="risks" error="risks" :required="$fullAbout" class="sm:col-span-2" hint="Section 3.0 of the memo: what could go wrong if the activity does not take place, or during it.">
                        <textarea id="risks" wire:model="risks" rows="3" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                    <x-ui.field label="Participants and stakeholders" for="audience" error="target_audience" :required="$fullAbout" class="sm:col-span-2" hint="Who takes part or is targeted, e.g. ICT staff and invited departmental representatives.">
                        <input id="audience" type="text" wire:model="target_audience" class="{{ Ui::CONTROL }}" maxlength="255">
                    </x-ui.field>
                    <x-ui.field label="Notes" for="notes" error="notes" :required="$fullAbout" class="sm:col-span-2" hint="Venue, purpose or any other reference detail.">
                        <textarea id="notes" wire:model="notes" rows="2" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                </div>
