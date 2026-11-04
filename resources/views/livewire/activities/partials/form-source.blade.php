@use('App\Support\Ui')
                <div class="space-y-4">
                    @if ($office)
                        <x-ui.field label="Requesting department" for="dept" error="organising_department_id" required>
                            <select id="dept" wire:model.live="organising_department_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                        </x-ui.field>
                        <x-ui.field label="Head of Department" for="hod" error="responsible_hod_id" hint="When you prepare the memo on a Head of Department's behalf. You are recorded as the preparer.">
                            <select id="hod" wire:model.live="responsible_hod_id" class="{{ $sel }}" @disabled($heads->isEmpty())>
                                <option value="">{{ $heads->isEmpty() ? 'No account for this department: type the name below' : 'Choose…' }}</option>
                                @foreach ($heads as $h)<option value="{{ $h->id }}">{{ $h->name }}{{ $h->is_active ? '' : ' (inactive)' }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        @if ($responsible_hod_id === '')
                            <x-ui.field label="Head of Department's name" for="hod-name" error="responsible_hod_name">
                                <input id="hod-name" type="text" wire:model="responsible_hod_name" class="{{ Ui::CONTROL }}" maxlength="255" placeholder="e.g. Head of ICT">
                            </x-ui.field>
                        @endif
                    @else
                        <div>
                            <p class="{{ Ui::LABEL }}">Requesting department</p>
                            <p class="text-sm font-semibold text-slate-700">{{ $departments->firstWhere('id', (int) $organising_department_id)?->name ?? auth()->user()->department?->name }}</p>
                            <p class="text-[11px] text-slate-400 mt-1">You are recorded as the responsible Head of Department.</p>
                        </div>
                    @endif
                    @if ($office)
                        <x-ui.field label="Memo number" for="ref" error="source_reference">
                            <input id="ref" type="text" wire:model="source_reference" class="{{ Ui::CONTROL }}" maxlength="100" placeholder="e.g. NGCDF/PPME/12/2026">
                        </x-ui.field>
                        <x-ui.field label="Date received" for="recv" error="source_received_on">
                            <input id="recv" type="date" wire:model="source_received_on" class="{{ Ui::CONTROL }}">
                        </x-ui.field>
                    @else
                        <x-ui.field label="Memo reference" for="ref" error="source_reference" required hint="Printed as REF NO on the memo.">
                            <input id="ref" type="text" wire:model="source_reference" class="{{ Ui::CONTROL }}" maxlength="100" required placeholder="e.g. NG-CDF BOARD/ICT/004/2026">
                        </x-ui.field>
                        <x-ui.field label="Date issued" for="issued" error="issued_on" required hint="Printed as DATE on the memo.">
                            <input id="issued" type="date" wire:model="issued_on" class="{{ Ui::CONTROL }}" required>
                        </x-ui.field>
                    @endif
                    @unless ($activity)
                        <x-ui.field :label="$office ? 'Source document' : 'Supporting document (optional)'" for="memo" error="memo" :hint="$office ? 'Memo, concept note or terms of reference (PDF, Word or image, up to 10 MB). Required before submitting to the CEO.' : 'The system generates the memo for you. Attach anything that supports it, such as an invitation letter. PDF, Word or image, up to 10 MB.'">
                            <input id="memo" type="file" wire:model="memo" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold">
                            <div wire:loading wire:target="memo" class="text-[11px] text-slate-400 mt-1">Uploading…</div>
                        </x-ui.field>
                    @endunless
                </div>
