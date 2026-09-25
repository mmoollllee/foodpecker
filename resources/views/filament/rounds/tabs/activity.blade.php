@php
    /** @var \App\Models\Round $round */
    $round = $this->getRound();
    $activities = $round->activities()->with('user')->limit(50)->get();
@endphp

<div class="space-y-4">
    @include('filament.partials.notes-and-documents', [
        'notesDescription' => 'Erfahrungen festhalten — z. B. „Lieferung war drei Wochen zu spät, nächstes Mal früher bestellen“.',
    ])

    @if ($this->canManage() || $round->notificationDrafts->whereNotNull('sent_at')->isNotEmpty())
        <x-filament::section heading="Benachrichtigungen">
            <x-slot name="description">Foodpecker verschickt nichts automatisch: Der Lead erzeugt einen Entwurf, passt ihn an und schickt ihn dann per Mail.</x-slot>

            @forelse ($round->notificationDrafts as $draft)
                @continue(! $draft->isSent() && ! $this->canManage())
                <div @class([
                    'mb-3 rounded-lg border p-3',
                    'border-emerald-500/30 bg-emerald-500/5' => $draft->isSent(),
                    'border-amber-500/30 bg-amber-500/5' => ! $draft->isSent(),
                ])>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-sm font-medium">{{ $draft->subject }}</h4>
                        <div class="flex items-center gap-2">
                            @if ($draft->isSent())
                                <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-700 dark:text-emerald-300">
                                    versendet {{ $draft->sent_at->format('d.m. H:i') }}{{ $draft->recipient_count ? ' an '.$draft->recipient_count.' Personen' : '' }}
                                </span>
                            @else
                                <x-foodpecker.action :action="($this->deleteDraftAction)(['draft' => $draft->id])" />
                                <x-foodpecker.action :action="($this->sendDraftAction)(['draft' => $draft->id])" />
                            @endif
                        </div>
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        Vorbereitet von {{ $draft->preparedBy?->fullName() ?? '—' }}
                        @if ($draft->generated_at)
                            · {{ $draft->generated_at->diffForHumans() }}
                        @endif
                    </div>
                    <div class="prose prose-sm mt-2 max-h-64 max-w-none overflow-auto rounded border border-gray-200 bg-white/50 p-2 dark:prose-invert dark:border-white/10 dark:bg-black/20">
                        {!! \Illuminate\Support\Str::markdown($draft->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Noch keine Benachrichtigungen. Über „Weitere Aktionen → Benachrichtigung vorbereiten“ erzeugt der Lead einen Entwurf.</p>
            @endforelse
        </x-filament::section>
    @endif

    <x-filament::section heading="Verlauf">
        <x-slot name="description">Wer hat wann was geändert? Die letzten 50 Einträge.</x-slot>

        @include('filament.partials.activities', ['activities' => $activities])
    </x-filament::section>
</div>
