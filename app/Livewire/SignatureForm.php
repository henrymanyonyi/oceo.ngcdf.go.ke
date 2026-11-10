<?php

namespace App\Livewire;

use App\Livewire\Concerns\RunsActions;
use App\Services\SignatureRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Profile › Signature: the officer draws their signature on the pad and saves it with their password
 * (App\Services\SignatureRegistry). It is put on a memo only when they themselves submit, review or
 * approve it with their password and a code, and only for that version of the memo.
 */
class SignatureForm extends Component
{
    use RunsActions;

    /** The drawing from the pad, as a PNG data URI. */
    public string $drawing = '';

    public string $password = '';

    public bool $showPad = false;

    public bool $showRevoke = false;

    public string $revokeReason = '';

    public function save(SignatureRegistry $registry): void
    {
        $this->validate(['drawing' => ['required', 'string'], 'password' => ['required', 'string']], ['drawing.required' => 'Draw your signature on the pad first.'], ['password' => 'password']);

        if ($this->attempt(fn () => $registry->saveWithPassword(Auth::user(), $this->drawing, $this->password)) !== null) {
            $this->reset(['drawing', 'password', 'showPad']);
            $this->toast('Signature saved. It is put on what you sign in OCEO from now on.');
        }
        $this->password = '';
    }

    /**
     * The officer deletes their signature: it is no longer used, and they are asked to draw one the next time
     * they sign. Memos it already signed keep it (their signing records and verification codes stay valid).
     */
    public function revoke(SignatureRegistry $registry): void
    {
        $this->validate(['revokeReason' => ['nullable', 'string', 'max:500']], [], ['revokeReason' => 'reason']);
        $signature = $registry->activeFor(Auth::user());
        abort_unless($signature !== null, 404);

        $reason = trim($this->revokeReason) !== '' ? 'Deleted by the officer: '.trim($this->revokeReason) : 'Deleted by the officer';
        if ($this->attempt(fn () => $registry->revoke($signature, Auth::user(), $reason)) !== null) {
            $this->reset(['showRevoke', 'revokeReason', 'showPad', 'drawing', 'password']);
            $this->toast('Signature deleted. You will be asked to draw one the next time you sign a memo.');
        }
    }

    public function render(SignatureRegistry $registry): View
    {
        $user = Auth::user();

        return view('livewire.signature-form', [
            'maySign' => $registry->maySign($user),
            'active' => $registry->activeFor($user),
        ]);
    }
}
