@use('App\Support\Ui')
                @php $locked = $activity && ! $activity->status->isFreelyEditable(); @endphp
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.field label="Planned start date" for="start" error="start_date" required>
                        <input id="start" type="date" wire:model.live="start_date" class="{{ Ui::CONTROL }}" @disabled($locked)>
                    </x-ui.field>
                    <x-ui.field label="Expected completion date" for="end" error="end_date" required>
                        <input id="end" type="date" wire:model.live="end_date" min="{{ $start_date }}" class="{{ Ui::CONTROL }}" @disabled($locked)>
                    </x-ui.field>
                    <div class="rounded-lg border px-3 py-2.5 sm:col-span-2 {{ $datesBackwards ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50' }}" aria-live="polite">
                        <p class="{{ Ui::MICRO }}">Total days · total nights</p>
                        @if ($datesBackwards)
                            <p class="text-[13px] font-semibold text-red-700">The completion date is before the start date.</p>
                        @elseif ($period)
                            <p class="text-[13px] font-bold text-emerald-800 font-numeric">{{ $period->days }} {{ Str::plural('day', $period->days) }} · {{ $period->nights() }} {{ Str::plural('night', $period->nights()) }}</p>
                            <p class="text-[11px] text-emerald-700">{{ $period->departure() ? 'Nights include the departure night, '.Ui::date($period->departure()).'.' : 'Same-day activity: no night away.' }}</p>
                        @else
                            <p class="text-[13px] text-slate-500">Choose both dates.</p>
                        @endif
                    </div>
                </div>
                @if ($locked)
                    <p class="text-[11px] text-amber-700 mt-3"><i class="fa-solid fa-circle-info mr-1"></i>Dates of a submitted or approved activity are changed with Postpone or Extend on the activity page, so the change is recorded.</p>
                @endif
