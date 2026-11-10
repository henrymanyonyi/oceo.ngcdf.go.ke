@use('App\Support\Ui')
<x-ui.page icon="fa-signature" title="Signatures" subtitle="Officers draw their own signature on the signature pad, in their Profile or the first time they sign. A signature is put on a memo only when its owner signs that memo in OCEO with their password and a code. Deactivate one that must no longer be used.">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <section class="{{ Ui::CARD }} lg:col-span-2">
            <div class="px-5 py-3 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-700">In use ({{ $active->count() }})</h2></div>
            @forelse ($active as $s)
                <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-4" wire:key="a-{{ $s->id }}">
                    <img src="{{ route('signatures.image', $s) }}" alt="Signature of {{ $s->user->name }}" class="h-12 w-36 object-contain bg-white border border-slate-100 rounded">
                    <div class="flex-1 min-w-0">
                        <p class="text-[13px] font-semibold text-slate-700 truncate">{{ $s->user->name }}</p>
                        <p class="text-[11px] text-slate-500">{{ $s->user->roleLabel() }} · drawn {{ $s->submitted_at->format('d M Y, H:i') }}</p>
                    </div>
                    <button type="button" wire:click="openRevoke({{ $s->id }})" class="{{ Ui::BTN_ICON }} hover:!text-red-600" aria-label="Deactivate {{ $s->user->name }}'s signature"><i class="fa-solid fa-ban text-xs"></i></button>
                </div>
            @empty
                <x-ui.empty icon="fa-signature" title="None yet" line="Signatures appear here once officers draw them." />
            @endforelse
        </section>

        <section class="{{ Ui::CARD }}">
            <div class="px-5 py-3 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-700">Still to draw one ({{ $missing->count() }})</h2></div>
            @forelse ($missing as $u)
                <div class="px-5 py-2.5 border-b border-slate-100" wire:key="m-{{ $u->id }}">
                    <p class="text-[13px] font-medium text-slate-700">{{ $u->name }}</p>
                    <p class="text-[11px] text-slate-500">{{ $u->roleLabel() }}</p>
                </div>
            @empty
                <p class="px-5 py-6 text-center text-[13px] text-slate-400">Everyone who signs memos has a signature.</p>
            @endforelse
        </section>
    </div>

    <x-ui.modal show="showRevoke" :open="$showRevoke && $selected !== null" size="alert" tone="red" icon="fa-ban" title="Deactivate this signature?"
        :subtitle="$selected ? $selected->user->name.'. It is not used on anything they sign from now on; they draw a new one before they next sign. What it already signed keeps its record.' : null">
        <x-ui.field label="Reason" for="sg-note" error="note" required><textarea id="sg-note" wire:model="note" rows="2" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showRevoke', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="revoke" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-ban text-xs"></i>Deactivate</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
