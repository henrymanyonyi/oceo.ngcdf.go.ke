@use('App\Support\Ui')
@use('App\Support\Money')
                <div class="space-y-4">
                    <x-ui.field :label="$regionalMemo ? 'Workplan activity' : 'Budget line'" for="budget" error="budget_line_id" :required="$regionalMemo"
                        :hint="$budgetLines->isEmpty() ? ($regionalMemo ? 'Your region has no approved workplan for this year yet.' : 'No approved budget for this department and year yet.') : ($regionalMemo ? 'The activity in your region\'s approved workplan that this memo carries out. Choosing it fills in the title, purpose and outputs you have not written yet.' : null)">
                        <select id="budget" wire:model="budget_line_id" class="{{ $sel }}"><option value="">None</option>@foreach ($budgetLines as $b)<option value="{{ $b->id }}">{{ $b->label() }}</option>@endforeach</select>
                    </x-ui.field>
                    @if ($budget)
                        @php $kes = fn (?int $c) => $c === null ? '—' : 'KES '.\App\Support\Money::format($c); @endphp
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-[12px] text-slate-600 space-y-1" aria-live="polite">
                            <p class="{{ Ui::MICRO }}">{{ ($budget['region'] ?? null)?->name ?? $budget['department']?->name }} {{ isset($budget['region']) ? 'workplan' : 'budget' }} · FY {{ $budget['financial_year'] }}</p>
                            @if ($budget['allocation'] === null)
                                <p class="text-amber-700">No allocation is recorded for this year yet.</p>
                            @else
                                <p class="flex justify-between"><span>Allocation</span><span class="font-numeric">{{ $kes($budget['allocation']) }}</span></p>
                                <p class="flex justify-between"><span>Spent</span><span class="font-numeric">{{ $kes($budget['spent']) }}</span></p>
                                <p class="flex justify-between"><span>Committed</span><span class="font-numeric">{{ $kes($budget['committed']) }}</span></p>
                                <p class="flex justify-between font-semibold {{ $budget['balance'] < 0 ? 'text-red-600' : 'text-slate-800' }}"><span>Available balance</span><span class="font-numeric">{{ $kes($budget['balance']) }}</span></p>
                            @endif
                            @if ($budget['pending_count'])<p class="text-purple-700">{{ $kes($budget['pending']) }} more is awaiting decision.</p>@endif
                        </div>
                    @endif
                    @if ($office)
                    <label class="flex items-start gap-2.5 text-[13px] text-slate-600">
                        <input type="checkbox" wire:model="count_externals_in_per_head" class="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                        <span>Count external participants in cost per head<span class="block text-[11px] text-slate-400">Staff-only per-head cost is always shown as well.</span></span>
                    </label>
                    @endif
                </div>
