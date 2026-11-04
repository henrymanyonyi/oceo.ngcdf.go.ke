@use('App\Support\Ui')
                <p class="text-[12px] text-slate-500 mb-3">Add a section when the standard memo does not cover something, for example a programme, the statutory basis or a previous visit. Sections follow Key risks in the memo, numbered in order.</p>
                <div class="space-y-3">
                    @foreach ($memo_sections as $i => $section)
                        <div class="rounded-lg border border-slate-200 p-3 space-y-3" wire:key="section-{{ $i }}">
                            <div class="flex items-start gap-2">
                                <x-ui.field label="Section heading" for="sec-h-{{ $i }}" error="memo_sections.{{ $i }}.heading" required class="flex-1">
                                    <input id="sec-h-{{ $i }}" type="text" wire:model="memo_sections.{{ $i }}.heading" maxlength="150" class="{{ Ui::CONTROL }}" placeholder="e.g. Programme of the visit">
                                </x-ui.field>
                                <button type="button" wire:click="removeSection({{ $i }})" class="{{ Ui::BTN_ICON_DANGER }} mt-6" aria-label="Remove this section"><i class="fa-solid fa-xmark text-xs"></i></button>
                            </div>
                            <x-ui.field label="Section text" for="sec-b-{{ $i }}" error="memo_sections.{{ $i }}.body" required>
                                <textarea id="sec-b-{{ $i }}" wire:model="memo_sections.{{ $i }}.body" rows="3" class="{{ Ui::CONTROL }}"></textarea>
                            </x-ui.field>
                        </div>
                    @endforeach
                </div>
                @if (count($memo_sections) < 10)
                    <button type="button" wire:click="addSection" class="{{ Ui::BTN_TINT }} mt-3"><i class="fa-solid fa-plus text-[9px]"></i>Add a section</button>
                @endif
