@use('App\Support\Ui')
    <x-ui.card title="Team" icon="fa-users" :count="$a->participants->count()">
        @if ($editable)
            <div class="flex flex-wrap items-center gap-2 px-5 py-3 border-b border-slate-100">
                <button type="button" wire:click="$set('showAdd', true)" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-user-plus text-xs"></i>Add officers</button>
                <button type="button" wire:click="openExternal" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-user-tie text-xs"></i>Add external participant</button>
                <button type="button" wire:click="$set('showGroup', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-people-group text-xs"></i>Add a group by number</button>
            </div>
        @endif
        @if ($a->participants->isEmpty())
            <x-ui.empty icon="fa-user-plus" title="No one on the team yet" line="Add the officers taking part. If an officer is not on the staff list, you can add them from the same window." class="!py-10" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-slate-100"><tr>
                        <th class="{{ Ui::TH }}">Name</th><th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }}">Job grade</th><th class="{{ Ui::TH }}">Role</th><th class="{{ Ui::TH }}">Days</th><th class="{{ Ui::TH }}"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($staffTeam->concat($externals) as $p)
                            <tr wire:key="p-{{ $p->id }}">
                                <td class="{{ Ui::TD }}">
                                    <p class="font-medium text-slate-700">{{ $p->displayName() }}</p>
                                    @if ($p->is_external)
                                        <p class="text-[11px] text-slate-400">External{{ $p->external_designation ? ' · '.$p->external_designation : '' }}{{ $p->external_organisation ? ' · '.$p->external_organisation : '' }}</p>
                                        @if ($p->hasPaymentRate())<p class="text-[11px] text-emerald-700"><i class="fa-solid fa-money-bill-wave mr-1"></i>{{ $p->rateLabel() }}</p>@endif
                                    @else<p class="text-[11px] text-slate-400 font-numeric">{{ $p->staff?->staff_number }}</p>@endif
                                    @if (in_array($p->id, $overlapping))
                                        @if ($p->conflict_reason)
                                            <p class="text-[12px] text-slate-500 mt-0.5"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Also on another approved activity on these dates. Reason: {{ $p->conflict_reason }}</p>
                                        @else
                                            <p class="text-[12px] text-red-600 mt-0.5"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Not available on these days: on another approved activity. Remove them, change their days, or ask the Office of the CEO to release them.</p>
                                        @endif
                                    @endif
                                </td>
                                <td class="{{ Ui::TD }}">{{ $p->is_external || $p->isGroup() ? '—' : ($p->department?->name ?? '—') }}</td>
                                <td class="{{ Ui::TD }} font-numeric">{!! $p->is_external ? '—' : ($p->job_grade ? e($p->job_grade) : '<span class="text-amber-600">not set</span>') !!}</td>
                                <td class="{{ Ui::TD }}">{{ $p->roleLabel() }}</td>
                                <td class="{{ Ui::TD }} font-numeric whitespace-nowrap">{{ $p->plannedDays($a) }}{{ $p->isPartial($a) ? ' ('.Ui::dateRange($p->startOn($a), $p->endOn($a)).')' : '' }}</td>
                                <td class="{{ Ui::TD }} text-right whitespace-nowrap">
                                    @if ($editable)
                                        @if ($p->is_external)
                                            <button type="button" wire:click="openExternal({{ $p->id }})" class="{{ Ui::BTN_TINT }}">Details and rate</button>
                                        @elseif ($a->days > 1)
                                            <button type="button" wire:click="openDays({{ $p->id }})" class="{{ Ui::BTN_TINT }}">Days</button>
                                        @endif
                                        <button type="button" wire:click="confirmRemove({{ $p->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove {{ $p->displayName() }}"><i class="fa-solid fa-xmark text-xs"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
