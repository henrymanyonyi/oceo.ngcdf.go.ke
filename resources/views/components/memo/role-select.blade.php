{{--
    A participant's role (App\Enums\ParticipantRole), and the role in words when Other (specify) is
    chosen. Binds the component's `role` and `roleOther`. $prefix labels each choice ("Add as ...").
--}}
@props(['id' => 'role', 'prefix' => ''])
@use('App\Support\Ui')
@use('App\Enums\ParticipantRole')
<div {{ $attributes->merge(['class' => 'space-y-3']) }} x-data="{ role: $wire.entangle('role') }">
    <x-ui.field label="Role" :for="$id" error="role" required>
        <select id="{{ $id }}" x-model="role" class="{{ Ui::CONTROL }} appearance-none pr-8">
            @foreach (ParticipantRole::cases() as $r)<option value="{{ $r->value }}">{{ $prefix }}{{ $r->label() }}</option>@endforeach
        </select>
    </x-ui.field>
    <div x-show="role === '{{ ParticipantRole::Other->value }}'" x-cloak>
        <x-ui.field label="Specify the role" :for="$id.'-other'" error="roleOther" required>
            <input id="{{ $id }}-other" type="text" wire:model="roleOther" maxlength="100" class="{{ Ui::CONTROL }}" placeholder="e.g. Resource person">
        </x-ui.field>
    </div>
</div>
