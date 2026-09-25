@php
    use App\Models\Activity;
    use App\Models\NotificationDraft;
    use Illuminate\Support\Str;

    /** @var \App\Models\Round $round */
    $round = $this->getRound();
    $drafts = $this->canManage() ? $round->notificationDrafts->reject(fn (NotificationDraft $draft): bool => $draft->isSent()) : collect();

    // What happened and the notifications sent about it, newest first.
    $entries = $round->activities()->with('user')->limit(50)->get()
        ->map(fn (Activity $activity): array => ['at' => $activity->created_at, 'activity' => $activity])
        ->concat($round->notificationDrafts
            ->filter(fn (NotificationDraft $draft): bool => $draft->isSent())
            ->map(fn (NotificationDraft $draft): array => ['at' => $draft->sent_at, 'notification' => $draft]))
        ->sortByDesc('at')
        ->values();
    $visibleEntries = 8;
    $markdown = fn (string $body) => Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
@endphp

<x-filament::section heading="Verlauf & Benachrichtigungen">
    <x-slot name="description">Was in der Runde passiert ist — mit den Benachrichtigungen, die der Lead verschickt hat. Foodpecker verschickt nichts automatisch.</x-slot>
    <x-slot name="afterHeader">
        <x-foodpecker.action :action="$this->generateNotificationAction" />
    </x-slot>

    @foreach ($drafts as $draft)
        <div class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h4 class="text-sm font-medium">Entwurf: {{ $draft->subject }}</h4>
                <div class="flex items-center gap-2">
                    <x-foodpecker.action :action="($this->deleteDraftAction)(['draft' => $draft->id])" />
                    <x-foodpecker.action :action="($this->sendDraftAction)(['draft' => $draft->id])" />
                </div>
            </div>
            <div class="mt-1 text-xs text-gray-500">
                Vorbereitet von {{ $draft->preparedBy?->fullName() ?? '—' }}{{ $draft->generated_at ? ' · '.$draft->generated_at->diffForHumans() : '' }} — noch nicht verschickt
            </div>
            <div class="prose prose-sm mt-2 max-h-64 max-w-none overflow-auto rounded border border-gray-200 bg-white/50 p-2 dark:prose-invert dark:border-white/10 dark:bg-black/20">
                {!! $markdown($draft->body) !!}
            </div>
        </div>
    @endforeach

    @if ($entries->isEmpty())
        <p class="text-sm text-gray-500">Noch nichts passiert.</p>
    @else
        <div x-data="{ showAll: false }">
            <ul class="space-y-2 text-sm">
                @foreach ($entries as $index => $entry)
                    <li @if ($index >= $visibleEntries) x-show="showAll" x-cloak @endif class="flex items-baseline gap-3">
                        <span class="whitespace-nowrap text-xs tabular-nums text-gray-400">{{ $entry['at']->format('d.m.Y H:i') }}</span>
                        @isset($entry['notification'])
                            @php $notification = $entry['notification']; @endphp
                            <details class="min-w-0 flex-1 text-gray-700 dark:text-gray-200">
                                <summary class="cursor-pointer">
                                    <strong>{{ $notification->sentBy?->fullName() ?? 'Lead' }}</strong> · 📨 Benachrichtigung{{ $notification->recipient_count ? ' an '.$notification->recipient_count.' Personen' : '' }}: „{{ $notification->subject }}“
                                </summary>
                                <div class="prose prose-sm mt-2 max-h-64 max-w-none overflow-auto rounded border border-gray-200 p-2 dark:prose-invert dark:border-white/10">
                                    {!! $markdown($notification->body) !!}
                                </div>
                            </details>
                        @else
                            <span class="text-gray-700 dark:text-gray-200">
                                <strong>{{ $entry['activity']->user ? $this->authorName($entry['activity']->user, $entry['activity']->group_id) : 'System' }}</strong> · {{ $entry['activity']->describeAction() }}
                            </span>
                        @endisset
                    </li>
                @endforeach
            </ul>

            @if ($entries->count() > $visibleEntries)
                <button
                    type="button"
                    x-on:click="showAll = ! showAll"
                    x-text="showAll ? 'Weniger anzeigen' : @js('Alle '.$entries->count().' Einträge anzeigen')"
                    class="mt-3 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                ></button>
            @endif
        </div>
    @endif
</x-filament::section>
