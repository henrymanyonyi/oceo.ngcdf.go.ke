{{--
    One person's days and payable nights for the first and last day chosen in a "Days for …" popup
    (App\Support\DsaPeriod: the departure night too for two days or more, none for one day).
--}}
@props(['start', 'end', 'activity'])
@use('App\Support\Ui')
@use('App\Support\DsaPeriod')
@php
    $from = $start ? \Illuminate\Support\Carbon::parse($start) : null;
    $to = $end ? \Illuminate\Support\Carbon::parse($end) : null;
    $valid = $from && $to && $to->gte($from);
    $period = $valid ? DsaPeriod::between($from, $to) : null;
    $outside = $valid && ($from->lt($activity->start_date) || $to->gt($activity->end_date));
@endphp
<div class="rounded-lg border px-3 py-2.5 {{ $outside || ($from && $to && ! $valid) ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }}" aria-live="polite">
    <p class="{{ Ui::MICRO }}">Days · payable nights</p>
    @if ($period)
        <p class="text-[13px] font-bold font-numeric {{ $outside ? 'text-amber-800' : 'text-emerald-800' }}">
            {{ $period->days }} {{ Str::plural('day', $period->days) }} · {{ $period->nights() }} {{ Str::plural('night', $period->nights()) }}
            <span class="font-normal text-slate-500">of {{ $activity->days }} activity days</span>
        </p>
        @if ($outside)<p class="text-[11px] text-amber-800 mt-0.5">Outside the activity, {{ Ui::dateRange($activity->start_date, $activity->end_date) }}.</p>@endif
    @elseif ($from && $to)
        <p class="text-[12px] text-amber-800">The last day is before the first day.</p>
    @else
        <p class="text-[13px] font-bold text-slate-500">—</p>
    @endif
</div>
