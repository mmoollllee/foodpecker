@php
    use App\Enums\GroupRole;

    /** @var \App\Models\Group $group */
    /** @var \App\Models\User|null $pendingOwner */
    /** @var \Illuminate\Support\Collection $members */
    /** @var string $householdSummary */
    /** @var \Illuminate\Support\Collection $invitations */
    /** @var bool $canInvite */
    /** @var \Illuminate\Support\Collection $activities */
@endphp

<x-filament-panels::page>
    @if ($pendingOwner)
        <div class="rounded-lg bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
            @if ($pendingOwner->is(auth()->user()))
                <p class="font-semibold">{{ $group->owner?->fullName() ?? 'Der Owner' }} möchte dir die Owner-Rolle übergeben.</p>
                <p class="mt-1">Nimm oben an oder lehne ab. Bis dahin bleibt alles, wie es ist.</p>
            @else
                <p class="font-semibold">Owner-Übergabe an {{ $pendingOwner->fullName() }} angefragt.</p>
                <p class="mt-1">Seit {{ $group->owner_transfer_requested_at?->format('d.m.Y H:i') }} — sie gilt erst, wenn {{ $pendingOwner->first_name }} zustimmt.</p>
            @endif
        </div>
    @endif

    <x-filament::section heading="Mitglieder">
        <x-slot name="description">
            {{ $householdSummary }}
            Kontaktdaten sehen nur Mitglieder der Gruppe. Owner und Moderatoren laden ein und vergeben Rollen, nur der Owner kann Mitglieder entfernen und die Owner-Rolle übergeben.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-white/10">
                    <tr class="text-left">
                        <th class="py-2 pr-4 font-semibold">Name</th>
                        <th class="px-3 py-2 font-semibold">Kontakt</th>
                        <th class="px-3 py-2 font-semibold">Wohnort</th>
                        <th class="px-3 py-2 font-semibold">Haushalt</th>
                        <th class="px-3 py-2 font-semibold">Rolle</th>
                        <th class="px-3 py-2 font-semibold">Beigetreten</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($members as $member)
                        @php $role = $group->owner_id === $member->id ? GroupRole::Owner : $member->membership?->role; @endphp
                        <tr>
                            <td class="py-2 pr-4">
                                <div class="flex items-center gap-3">
                                    <img src="{{ filament()->getUserAvatarUrl($member) }}" alt="" width="32" height="32" loading="lazy" class="size-8 shrink-0 rounded-full object-cover">
                                    <div>
                                        <span class="block font-medium">{{ $member->fullName() }}</span>
                                        @if (filled($member->nickname))
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">„{{ $member->nickname }}“</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                                <a href="mailto:{{ $member->email }}" class="block hover:underline">{{ $member->email }}</a>
                                @if ($member->telephoneUri())
                                    <a href="{{ $member->telephoneUri() }}" class="block whitespace-nowrap text-gray-500 hover:underline dark:text-gray-400">{{ $member->phone }}</a>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $member->location() ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-600 dark:text-gray-300">
                                {{ $member->household_size ? $member->household_size.' '.($member->household_size === 1 ? 'Person' : 'Personen') : '—' }}
                            </td>
                            <td class="px-3 py-2">
                                @if ($role instanceof GroupRole)
                                    <x-foodpecker.action :action="($this->toggleRoleAction)(['member' => $member->id, 'role' => $role->value])">
                                        <x-filament::badge :color="$role->getColor()" size="sm">{{ $role->getLabel() }}</x-filament::badge>
                                    </x-foodpecker.action>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-500">{{ $member->membership?->joined_at?->format('d.m.Y') ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                <x-foodpecker.action :action="($this->removeMemberAction)(['member' => $member->id])" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @if ($canInvite)
        <x-filament::section heading="Offene Einladungen">
            <x-slot name="description">
                Einladungen gelten {{ config('foodpecker.invitations.expires_after_days') }} Tage. Solange noch keine Mails verschickt werden, kopierst du den Link und schickst ihn selbst, z. B. per Messenger.
            </x-slot>

            @if ($invitations->isEmpty())
                <p class="text-sm text-gray-500">Aktuell keine offenen Einladungen.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 dark:border-white/10">
                            <tr class="text-left">
                                <th class="py-2 pr-4 font-semibold">E-Mail</th>
                                <th class="px-3 py-2 font-semibold">Rolle</th>
                                <th class="px-3 py-2 font-semibold">Eingeladen von</th>
                                <th class="px-3 py-2 font-semibold">Gültig bis</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($invitations as $invitation)
                                <tr>
                                    <td class="py-2 pr-4">{{ $invitation->email }}</td>
                                    <td class="px-3 py-2">
                                        <x-filament::badge color="info" size="sm">{{ $invitation->role->getLabel() }}</x-filament::badge>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $invitation->invitedBy?->fullName() ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        @if ($invitation->isExpired())
                                            <x-filament::badge color="danger" size="sm">abgelaufen</x-filament::badge>
                                        @else
                                            <span class="text-gray-500">{{ $invitation->expires_at?->format('d.m.Y H:i') ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center justify-end gap-3">
                                            @unless ($invitation->isExpired())
                                                <button
                                                    type="button"
                                                    x-data="{ copied: false }"
                                                    x-on:click="
                                                        const url = @js($invitation->acceptUrl());
                                                        if (navigator.clipboard && window.isSecureContext) {
                                                            navigator.clipboard.writeText(url).then(() => { copied = true; setTimeout(() => copied = false, 2000) });
                                                        } else {
                                                            window.prompt('Einladungslink kopieren (Strg/Cmd + C):', url);
                                                        }
                                                    "
                                                    class="text-xs font-medium text-primary-600 underline hover:text-primary-500 dark:text-primary-400"
                                                >
                                                    <span x-show="! copied">Link kopieren</span>
                                                    <span x-show="copied" x-cloak>✓ kopiert</span>
                                                </button>
                                            @endunless
                                            <x-foodpecker.action :action="($this->resendInvitationAction)(['invitation' => $invitation->id])" />
                                            <x-foodpecker.action :action="($this->withdrawInvitationAction)(['invitation' => $invitation->id])" />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section heading="Verlauf" collapsible>
        <x-slot name="description">Wer ist dazugekommen oder gegangen, wer hat Rollen vergeben, Einstellungen geändert oder die Gruppe übergeben?</x-slot>

        @include('filament.partials.activities', ['activities' => $activities])
    </x-filament::section>
</x-filament-panels::page>
