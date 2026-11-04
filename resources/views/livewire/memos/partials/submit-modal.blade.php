@use('App\Support\Ui')
{{--
    The Submit / Resubmit popup (App\Livewire\Concerns\SubmitsMemo), on the memo page and the memo builder.
    Needs $a, $issues, $offices and $designation.
--}}
@php
    $returned = $a->status === \App\Enums\ActivityStatus::ReturnedToHod;
    $blocking = collect($issues)->where('blocking', true);
    $submitLabel = match (true) {
        $returned && $a->isDirectToCeo() => 'Resubmit to the CEO',
        $returned => 'Resubmit for review',
        default => 'Submit memo',
    };
@endphp
    <x-ui.modal show="showSubmit" :open="$showSubmit" size="medium" icon="fa-paper-plane" :title="$submitLabel.'?'" :subtitle="$a->reference.' · '.$a->title">
        <x-memo.route-choice :current="$route" :offices="$offices" :locked="$returned" :reviewOffice="$a->review_office" />
        <x-memo.signing-capacity :designation="$designation" />
        @if ($blocking->isNotEmpty())
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-[13px] text-red-800" role="alert">
                <p class="font-semibold mb-1">Complete these first:</p>
                <ul class="list-disc pl-5 space-y-0.5">@foreach ($blocking as $issue)<li>{{ $issue['message'] }}</li>@endforeach</ul>
            </div>
        @else
            <p class="text-[13px] text-slate-600"><a href="{{ route('memos.document', $a) }}" target="_blank" rel="noopener" class="font-semibold text-emerald-700 hover:underline">Preview the memo and annexes</a> once more before you send it.</p>
        @endif
        <x-ui.field :label="$returned ? 'What you changed (optional)' : ($route === 'direct' ? 'Note to the CEO (optional)' : 'Note to the reviewer (optional)')" for="s-note" error="note">
            <textarea id="s-note" wire:model="note" rows="3" class="{{ Ui::CONTROL }}" maxlength="2000"></textarea>
        </x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showSubmit', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" class="{{ Ui::BTN_PRIMARY }}" @disabled($blocking->isNotEmpty())><i class="fa-solid fa-paper-plane text-xs"></i><span wire:loading.remove wire:target="submit">{{ $returned ? $submitLabel : ($route === 'direct' ? 'Submit to the CEO' : 'Submit for review') }}</span><span wire:loading wire:target="submit">Sending…</span></button>
        </x-slot:footer>
    </x-ui.modal>
