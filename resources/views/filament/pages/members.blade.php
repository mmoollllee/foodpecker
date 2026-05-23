@php
    /** @var \App\Models\Group|null $group */
    /** @var \Illuminate\Support\Collection $members */
    /** @var \Illuminate\Support\Collection $invitations */
    /** @var bool $canManage */
@endphp

<x-filament-panels::page>
    <x-filament::section heading="Mitglieder">
        <x-slot name="description">Wer ist in dieser Gruppe und welche Rolle hat er?</x-slot>

        @if ($members->isEmpty())
            <p class="text-sm text-gray-500">Noch keine Mitglieder.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-white/10">
                        <tr class="text-left">
                            <th class="py-2 pr-4 font-semibold">Name</th>
                            <th class="py-2 px-3 font-semibold">E-Mail</th>
                            <th class="py-2 px-3 font-semibold">Rolle</th>
                            <th class="py-2 px-3 font-semibold">Beigetreten</th>
                            <th class="py-2 px-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($members as $member)
                        @php
                            $role = $group?->roleOf($member);
                            $isOwner = $group?->owner_id === $member->id;
                        @endphp
                        <tr>
                            <td class="py-2 pr-4">
                                <div class="font-medium">{{ $member->fullName() }}</div>
                            </td>
                            <td class="py-2 px-3 text-gray-600 dark:text-gray-300">{{ $member->email }}</td>
                            <td class="py-2 px-3">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-amber-500/10 text-amber-700 dark:text-amber-300' => $role === \App\Enums\GroupRole::Owner,
                                    'bg-sky-500/10 text-sky-700 dark:text-sky-300' => $role === \App\Enums\GroupRole::Moderator,
                                    'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300' => $role === \App\Enums\GroupRole::Participant,
                                ])>{{ $role?->getLabel() ?? '—' }}</span>
                            </td>
                            <td class="py-2 px-3 text-gray-500">
                                {{ $member->membership?->joined_at?->format('d.m.Y') ?? '—' }}
                            </td>
                            <td class="py-2 px-3 text-right">
                                @if ($canManage && ! $isOwner)
                                    <button wire:click="removeMember({{ $member->id }})"
                                            wire:confirm="Wirklich aus der Gruppe entfernen?"
                                            class="text-xs text-rose-600 hover:text-rose-700 dark:text-rose-400 underline">
                                        entfernen
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Offene Einladungen">
        <x-slot name="description">
            Einladungen verfallen nach 14 Tagen. Du kannst eine Einladung neu verschicken (neuer Token & Ablaufzeit).
        </x-slot>

        @if ($invitations->isEmpty())
            <p class="text-sm text-gray-500">Aktuell keine offenen Einladungen.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-white/10">
                        <tr class="text-left">
                            <th class="py-2 pr-4 font-semibold">E-Mail</th>
                            <th class="py-2 px-3 font-semibold">Rolle</th>
                            <th class="py-2 px-3 font-semibold">Eingeladen von</th>
                            <th class="py-2 px-3 font-semibold">Ablauf</th>
                            <th class="py-2 px-3 font-semibold">Status</th>
                            <th class="py-2 px-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($invitations as $inv)
                        <tr>
                            <td class="py-2 pr-4">{{ $inv->email }}</td>
                            <td class="py-2 px-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs bg-sky-500/10 text-sky-700 dark:text-sky-300">
                                    {{ $inv->role->getLabel() }}
                                </span>
                            </td>
                            <td class="py-2 px-3 text-gray-600 dark:text-gray-300">{{ $inv->invitedBy?->fullName() ?? '—' }}</td>
                            <td class="py-2 px-3 text-gray-500">{{ $inv->expires_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td class="py-2 px-3">
                                @if ($inv->isExpired())
                                    <span class="rounded-full bg-rose-500/10 px-2 py-0.5 text-xs text-rose-700 dark:text-rose-300">abgelaufen</span>
                                @else
                                    <span class="rounded-full bg-amber-500/10 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-300">offen</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-right space-x-3">
                                @if ($canManage)
                                    <button wire:click="resendInvitation({{ $inv->id }})"
                                            class="text-xs text-sky-600 hover:text-sky-700 underline">
                                        nochmal senden
                                    </button>
                                    <button wire:click="withdrawInvitation({{ $inv->id }})"
                                            wire:confirm="Einladung wirklich zurückziehen?"
                                            class="text-xs text-rose-600 hover:text-rose-700 underline">
                                        zurückziehen
                                    </button>
                                @endif
                                <a href="{{ $inv->acceptUrl() }}" target="_blank"
                                   class="text-xs text-gray-500 hover:text-gray-700 underline">
                                    Link kopieren
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
