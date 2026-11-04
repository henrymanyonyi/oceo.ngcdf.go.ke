@use('App\Support\Ui')
@use('App\Support\Money')
    <x-ui.card title="Planned costs" icon="fa-coins" :count="'KES '.Money::format(Money::toCents($a->estimated_total))">
        @if ($editable)
            <x-slot:actions><button type="button" wire:click="openCost" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-plus text-[9px]"></i>Add cost line</button></x-slot:actions>
        @endif
        @if ($a->costs->isEmpty())
            <x-ui.empty icon="fa-coins" title="No costs yet" line="DSA appears here once officers are on the team and the location has a county. Add travel, venue and other costs as cost lines." class="!py-10" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-slate-100"><tr>
                        <th class="{{ Ui::TH }}">Cost</th><th class="{{ Ui::TH }}">For</th><th class="{{ Ui::TH }}">Details</th><th class="{{ Ui::TH }}">Calculation</th><th class="{{ Ui::TH }} text-right">Amount (KES)</th><th class="{{ Ui::TH }}"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($dsa->concat($manual) as $c)
                            <tr wire:key="c-{{ $c->id }}">
                                <td class="{{ Ui::TD }}">{{ $c->category?->name }}@if ($c->is_computed)<x-ui.badge classes="bg-emerald-50 text-emerald-700" class="ml-1">Calculated</x-ui.badge>@endif</td>
                                <td class="{{ Ui::TD }}">{{ $c->participant?->displayName() ?? 'Whole activity' }}</td>
                                <td class="{{ Ui::TD }} max-w-xs break-words">{{ $c->description ?: ($c->travel_mode?->label() ?? '—') }}</td>
                                <td class="{{ Ui::TD }} whitespace-nowrap">
                                    @if ($c->is_computed)<span class="text-[12px] text-slate-500">Worked out</span>
                                    @else<span class="text-[12px] text-slate-600">{{ $c->method()->shortLabel() }}</span>@if ($c->calculationLabel())<span class="block text-[11px] text-slate-400 font-numeric">{{ $c->calculationLabel() }}</span>@endif
                                    @endif
                                </td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ Money::format(Money::toCents($c->estimated_amount)) }}</td>
                                <td class="{{ Ui::TD }} text-right whitespace-nowrap">
                                    @if ($editable && ! $c->is_computed)
                                        <button type="button" wire:click="openCost({{ $c->id }})" class="{{ Ui::BTN_ICON }}" aria-label="Edit cost line"><i class="fa-solid fa-pen text-xs"></i></button>
                                        <button type="button" wire:click="removeCost({{ $c->id }})" wire:loading.attr="disabled" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove cost line"><i class="fa-solid fa-xmark text-xs"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="px-5 py-3 text-[11px] text-slate-400 border-t border-slate-100">DSA is calculated from each officer's job grade, their nights away and the county, and an external participant's payment from their rate and days; both update when the team, days or location change. The Office of the CEO can review every figure.</p>
        @endif
    </x-ui.card>
