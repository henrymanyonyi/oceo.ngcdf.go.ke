@use('App\Support\Ui')
<x-action-section>
    <x-slot name="title">Signature</x-slot>
    <x-slot name="description">Your signature for the memos you sign in OCEO, drawn on the signature pad. It is put on a memo only when you yourself submit, review or approve it with your password and a code, and only for that version of the memo.</x-slot>
    <x-slot name="content">
        @if (! $maySign)
            <p class="text-sm text-gray-600">Your account does not sign memos.</p>
        @else
            <div class="space-y-4 max-w-xl">
                <div class="rounded-lg border border-slate-200 p-3">
                    <p class="{{ Ui::LABEL }}">Your signature</p>
                    @if ($active)
                        <img src="{{ route('signatures.image', $active) }}" alt="Your signature" class="h-20 max-w-full object-contain bg-white">
                        <p class="text-[11px] text-slate-500 mt-1">Saved {{ $active->submitted_at->format('d M Y, H:i') }}.</p>
                    @else
                        <p class="text-sm text-slate-600"><i class="fa-solid fa-circle-info mr-1 text-slate-400"></i>You have no signature saved. Draw it here now, or later: you are asked for it the next time you submit, forward or approve a memo.</p>
                    @endif
                </div>

                @if ($showPad)
                    <form wire:submit="save" class="space-y-3">
                        <x-ui.field :label="$active ? 'Draw your new signature' : 'Draw your signature'" for="pf-sig" error="drawing" required>
                            <x-memo.signature-pad model="drawing" id="pf-sig" />
                        </x-ui.field>
                        <x-ui.field label="Your password" for="pf-sig-pw" error="password" required hint="To confirm it is you saving your signature.">
                            <input id="pf-sig-pw" type="password" wire:model="password" autocomplete="current-password" class="{{ Ui::CONTROL }} sm:w-72">
                        </x-ui.field>
                        <div class="flex gap-2">
                            <button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-signature text-xs"></i>Save signature</button>
                            <button type="button" wire:click="$set('showPad', false)" class="{{ Ui::BTN_NEUTRAL }}">{{ $active ? 'Cancel' : 'Later' }}</button>
                        </div>
                    </form>
                @else
                    <div class="flex gap-2">
                        <button type="button" wire:click="$set('showPad', true)" class="{{ $active ? Ui::BTN_NEUTRAL : Ui::BTN_PRIMARY }}"><i class="fa-solid fa-pen-nib text-xs"></i>{{ $active ? 'Draw a new signature' : 'Draw my signature now' }}</button>
                        @if ($active)
                            <button type="button" wire:click="$set('showRevoke', true)" class="{{ Ui::BTN_NEUTRAL }} hover:!text-red-600"><i class="fa-solid fa-trash-can text-xs"></i>Delete signature</button>
                        @endif
                    </div>
                @endif
            </div>

            <x-ui.modal show="showRevoke" :open="$showRevoke" size="alert" tone="red" icon="fa-trash-can" title="Delete your signature?" subtitle="It is no longer used, and you are asked to draw one the next time you sign a memo. Memos you have already signed keep your signature and their verification codes.">
                <x-ui.field label="Reason (optional)" for="sig-r" error="revokeReason"><textarea id="sig-r" wire:model="revokeReason" rows="2" class="{{ Ui::CONTROL }}" placeholder="e.g. I want to draw it again"></textarea></x-ui.field>
                <x-slot:footer>
                    <button type="button" wire:click="$set('showRevoke', false)" class="{{ Ui::BTN_NEUTRAL }}">Keep it</button>
                    <button type="button" wire:click="revoke" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-trash-can text-xs"></i>Delete signature</button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </x-slot>
</x-action-section>
