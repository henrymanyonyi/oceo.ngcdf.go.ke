{{--
    The one-page memo builder's frame (Memos\Compose for a Head of Department, MemoRequest for an officer on a
    delegated link): the step rail on wide screens, a row of step chips on small ones, and the steps (the slot).
    $steps: list of [id, number, title, done, locked]. The rail slot adds a card under the steps.
    Listens for the browser event builder-go {id} to scroll to a step.
--}}
@props(['steps'])
@use('App\Support\Ui')
@php $done = collect($steps)->where('done', true)->count(); @endphp
<div {{ $attributes->merge(['class' => 'grid grid-cols-1 xl:grid-cols-[15rem_minmax(0,1fr)] gap-6']) }}
        x-data="{
            active: 'step-details',
            {{-- Staff pages scroll only the page body (PAKA-RANGI: the header stays put); the public page scrolls the window. --}}
            box() { return this.$root.closest('.overflow-y-auto') },
            go(id) {
                const el = document.getElementById(id), box = this.box();
                if (! el) return;
                if (box) box.scrollTo({ top: el.getBoundingClientRect().top - box.getBoundingClientRect().top + box.scrollTop - 16, behavior: 'smooth' });
                else window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 76, behavior: 'smooth' });
            },
            spy() {
                const box = this.box();
                const top = (box ? box.getBoundingClientRect().top : 0) + 140;
                let current = 'step-details';
                document.querySelectorAll('[data-step]').forEach(el => { if (el.getBoundingClientRect().top <= top) current = el.dataset.step });
                if (this.active !== current) {
                    this.active = current;
                    const chip = this.$root.querySelector(`[data-chip='${current}']`);
                    if (chip) chip.parentElement.parentElement.scrollLeft = chip.offsetLeft - 16;
                }
            },
        }"
        x-on:builder-go.window="setTimeout(() => go($event.detail.id), 150)"
        x-init="
            (box() ?? window).addEventListener('scroll', () => spy(), { passive: true });
            if (location.hash) setTimeout(() => go(location.hash.slice(1)), 200);
        ">

        {{-- Progress: a rail on wide screens, a row of chips on small ones. --}}
        <nav aria-label="Memo steps" class="xl:hidden sticky top-0 z-20 -mx-1 px-1 py-2.5 bg-slate-50/95 backdrop-blur border-b border-slate-200">
            <ol class="relative flex gap-2 overflow-x-auto">
                @foreach ($steps as $step)
                    <li class="shrink-0">
                        <button type="button" data-chip="{{ $step['id'] }}" x-on:click="go('{{ $step['id'] }}')" class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[12px] font-semibold transition"
                            :class="active === '{{ $step['id'] }}' ? 'border-emerald-500 bg-emerald-50 text-emerald-800' : '{{ $step['locked'] ? 'border-slate-200 bg-white text-slate-400' : 'border-slate-200 bg-white text-slate-600' }}'">
                            @if ($step['done'])<i class="fa-solid fa-circle-check text-emerald-600"></i>@else<span class="font-numeric">{{ $step['number'] }}</span>@endif
                            {{ $step['title'] }}
                        </button>
                    </li>
                @endforeach
            </ol>
        </nav>

        <aside class="hidden xl:block">
            <div class="sticky top-0 space-y-4">
                <div class="{{ Ui::CARD }} p-4">
                    <div class="flex items-center justify-between mb-2">
                        <p class="{{ Ui::MICRO }}">Progress</p>
                        <p class="text-[12px] font-semibold text-slate-600 font-numeric">{{ $done }} of {{ count($steps) }}</p>
                    </div>
                    <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($steps) }}" aria-valuenow="{{ $done }}" aria-label="Steps complete">
                        <div class="h-full bg-emerald-500 rounded-full transition-all duration-500" style="width: {{ round($done / count($steps) * 100) }}%"></div>
                    </div>
                    <ol class="mt-4 space-y-0.5">
                        @foreach ($steps as $step)
                            <li>
                                <button type="button" x-on:click="go('{{ $step['id'] }}')" class="w-full flex items-center gap-2.5 rounded-lg px-2 py-2 text-left transition"
                                    :class="active === '{{ $step['id'] }}' ? 'bg-emerald-50' : 'hover:bg-slate-50'">
                                    <span @class([
                                        'w-6 h-6 shrink-0 rounded-full flex items-center justify-center text-[11px] font-bold font-numeric',
                                        'bg-emerald-600 text-white' => $step['done'],
                                        'border border-slate-200 text-slate-400' => ! $step['done'] && $step['locked'],
                                        'border-2 border-emerald-500 text-emerald-700' => ! $step['done'] && ! $step['locked'],
                                    ])>@if ($step['done'])<i class="fa-solid fa-check text-[10px]"></i>@else{{ $step['number'] }}@endif</span>
                                    <span class="text-[13px] leading-tight {{ $step['locked'] ? 'text-slate-400' : 'text-slate-700' }}" :class="active === '{{ $step['id'] }}' && 'font-semibold text-emerald-800'">{{ $step['title'] }}</span>
                                    @if ($step['locked'])<i class="fa-solid fa-lock text-[9px] text-slate-300 ml-auto" aria-label="Opens after the first save"></i>@endif
                                </button>
                            </li>
                        @endforeach
                    </ol>
                </div>

                {{ $rail ?? '' }}
            </div>
        </aside>

        <div class="min-w-0 flex flex-col gap-10 pb-16">
            {{ $slot }}
        </div>
    </div>
