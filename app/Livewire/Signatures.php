<?php

namespace App\Livewire;

use App\Livewire\Concerns\RunsActions;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\SignatureRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Signatures (`signatures.verify`: the System Administrator and the CEO): who has drawn a signature and
 * who still has to, and deactivation of a signature that must no longer be used (lost device, suspicion).
 * Officers draw their own (App\Services\SignatureRegistry); nobody draws or changes another's.
 */
#[Layout('layouts.admin')]
#[Title('Signatures')]
class Signatures extends Component
{
    use RunsActions;

    public bool $showRevoke = false;

    public ?int $signatureId = null;

    public string $note = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('signatures.verify'), 403);
    }

    public function openRevoke(int $id): void
    {
        $this->signatureId = UserSignature::query()->active()->findOrFail($id)->id;
        $this->note = '';
        $this->resetErrorBag();
        $this->showRevoke = true;
    }

    public function revoke(SignatureRegistry $registry): void
    {
        $this->validate(['note' => ['required', 'string', 'max:500']], [], ['note' => 'reason']);
        $signature = UserSignature::query()->findOrFail($this->signatureId);

        if ($this->attempt(fn () => $registry->revoke($signature, Auth::user(), $this->note)) !== null) {
            $this->toast('Signature deactivated. The officer draws a new one before they next sign.');
            $this->reset(['showRevoke', 'signatureId', 'note']);
        }
    }

    public function render(SignatureRegistry $registry): View
    {
        $active = UserSignature::query()->active()->with('user.roles')->latest('submitted_at')->get();
        $signers = User::query()->where('is_active', true)->with('roles')->orderBy('name')->get()
            ->filter(fn (User $u) => $registry->maySign($u));

        return view('livewire.signatures', [
            'active' => $active,
            'missing' => $signers->whereNotIn('id', $active->pluck('user_id'))->values(),
            'selected' => $this->signatureId ? UserSignature::query()->with('user')->find($this->signatureId) : null,
        ]);
    }
}
