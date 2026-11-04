@use('App\Support\Ui')
@use('App\Support\Money')
                <div class="space-y-4">
                    <x-ui.field label="Estimated budget (KES)" for="requested" error="requested_budget" :hint="$office ? 'The department\'s own estimate. The costed plan comes from the team and cost lines.' : ($embedded ? 'Your own estimate. The costed plan comes from the team and cost lines further down; allowances (DSA) are calculated for you.' : 'Your own estimate. After saving, add the team and cost lines under Team and costs; allowances (DSA) are calculated for you.')">
                        <input id="requested" type="text" inputmode="decimal" wire:model="requested_budget" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 250000">
                    </x-ui.field>
                    <x-ui.field label="Budget line" for="budget" error="budget_line_id" :hint="$budgetLines->isEmpty() ? 'No approved budget for this department and year yet.' : null">
                        <select id="budget" wire:model="budget_line_id" class="{{ $sel }}"><option value="">None</option>@foreach ($budgetLines as $b)<option value="{{ $b->id }}">{{ $b->label() }}</option>@endforeach</select>
                    </x-ui.field>
                    @if ($budget)
                        @php $kes = fn (?int $c) => $c === null ? '—' : 'KES '.\App\Support\Money::format($c); @endphp
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-[12px] text-slate-600 space-y-1" aria-live="polite">
                            <p class="{{ Ui::MICRO }}">{{ $budget['department']?->name }} budget · FY {{ $budget['financial_year'] }}</p>
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
