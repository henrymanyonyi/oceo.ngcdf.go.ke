{{--
    How the Head of Department sends the memo on (App\Enums\SubmissionRoute, 4 November 2026): through
    the COS/ACOS office for review, then to the CEO; or directly to the CEO. Binds `route`, and the office
    property named by $officeModel for the review route. $current is the chosen route; $locked shows a
    memo returned for correction, which goes back along the route it came.
--}}
@props(['current', 'offices', 'officeModel' => 'office', 'locked' => false, 'reviewOffice' => null])
@use('App\Support\Ui')
@use('App\Enums\SubmissionRoute')
@php $current = $current instanceof SubmissionRoute ? $current : (SubmissionRoute::tryFrom((string) $current) ?? SubmissionRoute::Review); @endphp
@if ($locked)
    <div class="rounded-lg border {{ $current === SubmissionRoute::Direct ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-sky-200 bg-sky-50 text-sky-800' }} px-3 py-2.5 text-[13px]">
        <i class="fa-solid {{ $current->icon() }} mr-1"></i>
        @if ($current === SubmissionRoute::Direct)
            It goes back <strong>directly to the CEO</strong>, who returned it.
        @else
            It goes back to the <strong>{{ $reviewOffice?->label() ?? 'reviewer' }}</strong>, who returned it.
        @endif
    </div>
@else
    <fieldset>
        <legend class="{{ Ui::LABEL }}">Route <span class="text-red-500" aria-hidden="true">*</span></legend>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-1">
            @foreach (SubmissionRoute::cases() as $r)
                <label class="flex items-start gap-2.5 rounded-lg border px-3 py-2.5 cursor-pointer transition {{ $current === $r ? 'border-emerald-300 bg-emerald-50/60' : 'border-slate-200 hover:bg-slate-50' }}">
                    <input type="radio" wire:model.live="route" value="{{ $r->value }}" class="mt-0.5 border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="min-w-0">
                        <span class="block text-[13px] font-semibold text-slate-700"><i class="fa-solid {{ $r->icon() }} text-[11px] mr-1 text-slate-400"></i>{{ $r === SubmissionRoute::Review ? 'Forward to COS/ACOS' : 'Forward directly to the CEO' }}</span>
                        <span class="block text-[11px] text-slate-500">{{ $r->description() }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('route')<p class="text-xs text-red-600 mt-1" role="alert">{{ $message }}</p>@enderror
    </fieldset>
    @if ($current === SubmissionRoute::Review)
        <x-ui.field label="Send it to" for="route-office" :error="$officeModel" required>
            <select id="route-office" wire:model.live="{{ $officeModel }}" class="{{ Ui::CONTROL }} appearance-none pr-8">
                @foreach ($offices as $o)<option value="{{ $o->value }}">{{ $o->label() }}</option>@endforeach
            </select>
        </x-ui.field>
        <p class="text-[13px] text-slate-600">The reviewer forwards it to the CEO for decision, or returns it to you for correction. You cannot change it while it is being reviewed.</p>
    @else
        <p class="text-[13px] text-slate-600">It goes straight into the CEO's queue for decision; the Chief of Staff and the Assistant are not involved. If the CEO returns it, it comes back to you and you resubmit it to the CEO.</p>
    @endif
@endif
