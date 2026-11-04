{{--
    One numbered step of the one-page memo builder (Memos\Compose): an anchor for the step rail,
    a numbered heading that turns into a tick when the step is complete, and the step's cards.
--}}
@props(['id', 'number', 'title', 'line' => null, 'done' => false, 'locked' => false])
<section id="{{ $id }}" data-step="{{ $id }}" {{ $attributes->merge(['class' => 'scroll-mt-4']) }}>
    <div class="flex items-start gap-3 mb-3">
        <span @class([
            'w-8 h-8 shrink-0 rounded-full flex items-center justify-center text-[13px] font-bold font-numeric ring-4',
            'bg-emerald-600 text-white ring-emerald-100' => $done,
            'bg-white text-slate-400 border border-slate-200 ring-slate-50' => ! $done && $locked,
            'bg-white text-emerald-700 border-2 border-emerald-500 ring-emerald-50' => ! $done && ! $locked,
        ]) aria-hidden="true">
            @if ($done)<i class="fa-solid fa-check text-xs"></i>@else{{ $number }}@endif
        </span>
        <div class="min-w-0 pt-0.5">
            <h2 class="text-[15px] font-bold leading-tight {{ $locked ? 'text-slate-400' : 'text-slate-800' }}">
                <span class="sr-only">Step {{ $number }}: </span>{{ $title }}
                @if ($done)<span class="sr-only">(complete)</span>@endif
            </h2>
            @if ($line)<p class="text-[12px] text-slate-500 mt-0.5">{{ $line }}</p>@endif
        </div>
    </div>
    <div class="sm:pl-11 flex flex-col gap-4">{{ $slot }}</div>
</section>
