@php
    /** @var string|null $notesDescription */
    $notes = $this->visibleNotes();
    $attachments = $this->visibleAttachments();
@endphp

<div class="space-y-4">
    <x-filament::section heading="Notizen">
        @if (! empty($notesDescription))
            <x-slot name="description">{{ $notesDescription }}</x-slot>
        @endif
        <x-slot name="afterHeader">
            <x-foodpecker.action :action="$this->addNoteAction" />
        </x-slot>

        @forelse ($notes as $note)
            <div class="border-b border-gray-100 py-2 text-sm last:border-0 dark:border-white/5">
                <div class="flex items-center justify-between gap-2 text-xs text-gray-500">
                    <span><strong class="text-gray-700 dark:text-gray-200">{{ $this->authorName($note->user, $note->group_id) }}</strong> · {{ $note->created_at->format('d.m.Y H:i') }}</span>
                    <x-foodpecker.action :action="($this->deleteNoteAction)(['note' => $note->id])" />
                </div>
                @if ($note->title)
                    <div class="mt-1 font-medium">{{ $note->title }}</div>
                @endif
                <p class="mt-1 whitespace-pre-line">{{ $note->body }}</p>
            </div>
        @empty
            <p class="text-sm text-gray-500">Noch keine Notizen.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Dokumente">
        <x-slot name="description">Preislisten, Bestellbestätigungen, Rechnungen, Fotos.</x-slot>
        <x-slot name="afterHeader">
            <x-foodpecker.action :action="$this->addAttachmentAction" />
        </x-slot>

        @forelse ($attachments as $attachment)
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 text-sm last:border-0 dark:border-white/5">
                <div class="min-w-0">
                    <a href="{{ $attachment->url() }}" class="font-medium text-primary-600 underline hover:text-primary-500 dark:text-primary-400">📎 {{ $attachment->original_name }}</a>
                    <div class="text-xs text-gray-500">
                        {{ $attachment->humanSize() }} · {{ $this->authorName($attachment->uploadedBy, $attachment->group_id) }} · {{ $attachment->created_at->format('d.m.Y') }}
                    </div>
                </div>
                <x-foodpecker.action :action="($this->deleteAttachmentAction)(['attachment' => $attachment->id])" />
            </div>
        @empty
            <p class="text-sm text-gray-500">Noch keine Dokumente.</p>
        @endforelse
    </x-filament::section>
</div>
