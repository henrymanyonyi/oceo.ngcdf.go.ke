@use('App\Support\Ui')
{{--
    "Confirm it is you" in the Submit, Forward and Approve popups (App\Livewire\Concerns\ConfirmsSigning,
    App\Services\Signing): the officer's password and a one-time code sent to their own email and mobile.
    Binds signPassword and signCode; the code is asked for with sendSigningCode($step, $activityId).
    Shown only when the step carries a signature and step-up is on.
--}}
@props(['step', 'action' => null, 'activityId' => null, 'sentTo' => []])
@if ($action && app(\App\Services\Signing::class)->stepUpRequired())
    @php $signature = app(\App\Services\SignatureRegistry::class)->activeFor(auth()->user()); @endphp
    <div class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-3 space-y-2">
        <p class="text-[13px] font-semibold text-slate-700"><i class="fa-solid fa-signature mr-1 text-emerald-700"></i>Authorise with your signature</p>
        @if ($signature)
            <div class="flex items-center gap-3">
                <img src="{{ route('signatures.image', $signature) }}" alt="Your signature" class="h-12 w-36 object-contain bg-white border border-slate-200 rounded">
                <p class="text-[12px] text-slate-600">You {{ $action->verb() }} this memo as <strong>{{ auth()->user()->name }}</strong>. This signature goes in the "{{ $action->blockLabel() }}" block with the time and a verification code. To change it, use your Profile.</p>
            </div>
        @else
            <p class="text-[12px] text-amber-800"><i class="fa-solid fa-circle-info mr-1"></i>You have no signature yet. Draw it below: it is saved as yours once your password and code are confirmed, and goes in the "{{ $action->blockLabel() }}" block of this memo.</p>
            <x-ui.field label="Your signature" for="sg-pad-{{ $step }}" error="signDrawing" required>
                <x-memo.signature-pad model="signDrawing" id="sg-pad-{{ $step }}" />
            </x-ui.field>
        @endif
        @php $mobile = auth()->user()->mobileNumber(); $args = "'".$step."'".($activityId ? ', '.(int) $activityId : ''); @endphp
        @if (! $mobile)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 space-y-2">
                <p class="text-[12px] text-amber-800"><i class="fa-solid fa-mobile-screen mr-1"></i>Your mobile number is not on your account or the staff list. The code is sent to your phone by SMS and to your email: give your mobile number and your password below, then send the code.</p>
                <x-ui.field label="Your mobile number" for="sg-ph-{{ $step }}" error="signPhone" required>
                    <input id="sg-ph-{{ $step }}" type="tel" wire:model="signPhone" maxlength="30" class="{{ Ui::CONTROL }} font-numeric sm:w-64" placeholder="e.g. 0712 345 678" autocomplete="tel">
                </x-ui.field>
            </div>
        @endif
        <div class="flex flex-wrap items-center gap-2">
            @if ($mobile)
                <button type="button" wire:click="sendSigningCode({{ $args }})" wire:loading.attr="disabled" wire:target="sendSigningCode" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-message text-[10px]"></i>{{ $sentTo === [] ? 'Send me a code' : 'Send a new code' }}</button>
                @if ($sentTo !== [])<span class="text-[11px] text-slate-500">Sent by SMS and email to {{ implode(' and ', $sentTo) }}. It works for {{ \App\Services\Signing::CODE_MINUTES }} minutes.</span>
                @else<span class="text-[11px] text-slate-500">By SMS to {{ \App\Support\MobileNumber::mask($mobile) }} and by email.</span>@endif
            @else
                <button type="button" wire:click="saveSigningPhone({{ $args }})" wire:loading.attr="disabled" wire:target="saveSigningPhone" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-floppy-disk text-[10px]"></i>Save my number and send the code</button>
                <span class="text-[11px] text-slate-500">Type your password below first.</span>
            @endif
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <x-ui.field label="Your password" for="sg-pw-{{ $step }}" error="signPassword" required>
                <input id="sg-pw-{{ $step }}" type="password" wire:model="signPassword" autocomplete="current-password" class="{{ Ui::CONTROL }}">
            </x-ui.field>
            <x-ui.field label="Code" for="sg-code-{{ $step }}" error="signCode" required>
                <input id="sg-code-{{ $step }}" type="text" inputmode="numeric" maxlength="6" wire:model="signCode" autocomplete="one-time-code" class="{{ Ui::CONTROL }} font-numeric tracking-widest">
            </x-ui.field>
        </div>
    </div>
@endif
