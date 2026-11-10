<?php

namespace App\Livewire;

use App\Services\AccessLogger;
use App\Services\Signing;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Verify a signature on a printed or downloaded memo (App\Services\Signing::verify): type the code under
 * the signature to see who signed, as what, when, how they confirmed it, and whether the memo still reads
 * as it did then (its content is hashed again now). Signed-in users only, and only for memos they may
 * read: the system is confidential, so an unknown code and a memo you may not see look the same.
 */
#[Layout('layouts.admin')]
#[Title('Verify a signature')]
class VerifySignature extends Component
{
    #[Url(except: '')]
    public string $code = '';

    public function mount(?string $code = null): void
    {
        $this->code = strtoupper(trim((string) ($code ?? $this->code)));
    }

    public function render(Signing $signing, AccessLogger $log): View
    {
        $result = null;
        $code = strtoupper(trim($this->code));

        if ($code !== '') {
            $found = $signing->verify($code);
            if ($found && $found['event']->activity->isReadableBy(Auth::user())) {
                $result = $found;
                $log->log(AccessLogger::VIEW, $found['event']->activity, ['what' => 'signature verification', 'code' => $code]);
            } else {
                $log->log('signature_verification_failed', null, ['code' => mb_substr($code, 0, 30)]);
            }
        }

        return view('livewire.verify-signature', ['result' => $result, 'searched' => $code !== '']);
    }
}
