@use('App\Support\Ui')
<x-ui.page icon="fa-shield-halved" title="Verify a signature" subtitle="Type the verification code printed under a signature on a memo. OCEO checks who signed, when, and whether the memo still reads exactly as it did when they signed.">
    <div class="max-w-2xl space-y-4">
        <div class="{{ Ui::CARD }} p-5">
            <x-ui.field label="Verification code" for="vc" hint="For example OCEO-AB12-CD34.">
                <input id="vc" type="text" wire:model.live.debounce.500ms="code" class="{{ Ui::CONTROL }} font-numeric uppercase tracking-wider" autocomplete="off">
            </x-ui.field>
        </div>

        @if ($result)
            @php $e = $result['event']; $a = $e->activity; @endphp
            <div class="{{ Ui::CARD }} p-5 space-y-3">
                @if ($result['valid'] && ! $result['superseded_by'])
                    <p class="px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm"><i class="fa-solid fa-circle-check mr-1"></i><strong>Valid.</strong> This signature stands for the memo as it reads now.</p>
                @elseif ($result['valid'])
                    <p class="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm"><i class="fa-solid fa-circle-info mr-1"></i><strong>Genuine, but signed again later.</strong> The memo is unchanged, and a later signing for the same step is the one on the current memo.</p>
                @else
                    <p class="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm"><i class="fa-solid fa-triangle-exclamation mr-1"></i><strong>Not valid for the memo as it reads now.</strong> The signing is genuine, but the memo has been changed since. A copy showing this signature for the changed memo is not authentic.</p>
                @endif
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div><dt class="{{ Ui::MICRO }}">Memo</dt><dd class="font-numeric">{{ $a->memoReference() ?? $a->reference }}</dd><dd class="text-slate-600">{{ $a->title }}</dd></div>
                    <div><dt class="{{ Ui::MICRO }}">Signed as</dt><dd>{{ $e->action->blockLabel() }}</dd></div>
                    <div><dt class="{{ Ui::MICRO }}">Officer</dt><dd class="font-semibold">{{ $e->signer_name }}</dd><dd class="text-slate-600">{{ $e->designation }}</dd></div>
                    <div><dt class="{{ Ui::MICRO }}">When</dt><dd class="font-numeric">{{ $e->signed_at->format('d M Y, H:i:s') }}</dd></div>
                    <div><dt class="{{ Ui::MICRO }}">Confirmed with</dt><dd>{{ $e->auth_method === 'password_and_code' ? 'Password and a one-time code to the officer\'s own email and mobile' : 'Signed-in session' }}</dd></div>
                    <div><dt class="{{ Ui::MICRO }}">Signature image</dt><dd>{{ $e->user_signature_id ? 'Verified signature on file' : 'None registered at the time (name only)' }}</dd></div>
                </dl>
                @if ($a->isReadableBy(auth()->user()))
                    <p class="text-[12px]"><a href="{{ route('memos.document', $a) }}" target="_blank" rel="noopener" class="font-semibold text-emerald-700 hover:underline">Open the memo as it reads now</a></p>
                @endif
            </div>
        @elseif ($searched && strlen(trim($code)) >= 8)
            <p class="px-3 py-2 rounded-lg bg-slate-100 border border-slate-200 text-slate-700 text-sm"><i class="fa-solid fa-circle-xmark mr-1"></i>No memo you can open has this code. Check it, or ask the Office of the CEO.</p>
        @endif
    </div>
</x-ui.page>
