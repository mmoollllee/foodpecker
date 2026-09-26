<?php

namespace App\Filament\Concerns;

use App\Models\Attachment;
use App\Models\Note;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Notes and documents on a round, supplier or product. On shared
 * suppliers and products every group sees them — people from other
 * groups are shown without their name.
 */
trait InteractsWithNotesAndDocuments
{
    use ResolvesAuthorNames;

    /**
     * @var array<int, string>
     */
    protected static array $acceptedDocumentTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/plain',
        'text/csv',
        'message/rfc822',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * The round, supplier or product the notes belong to.
     */
    abstract protected function annotatedRecord(): Model;

    abstract protected function refreshAnnotations(): void;

    public function addNoteAction(): Action
    {
        return Action::make('addNote')
            ->label('Notiz schreiben')
            ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => $this->annotatingUser()->can('create', [Note::class, $this->annotatedRecord()]))
            ->modalHeading('Notiz')
            ->modalDescription(fn (): string => $this->isShared($this->annotatedRecord())
                ? 'Alle Foodpecker-Gruppen, die hier bestellen, sehen die Notiz — ohne deinen Namen, wenn sie nicht zu deiner Gruppe gehören.'
                : 'Alle Mitglieder deiner Gruppe sehen die Notiz.')
            ->schema([
                Textarea::make('body')
                    ->label('Notiz')
                    ->required()
                    ->maxLength(5000)
                    ->rows(5),
            ])
            ->action(function (array $data): void {
                $record = $this->annotatedRecord();

                $record->notes()->create([
                    'user_id' => $this->annotatingUser()->id,
                    'group_id' => Filament::getTenant()?->getKey(),
                    'body' => $data['body'],
                ]);

                $record->logActivity('note_added');
                $this->refreshAnnotations();
            });
    }

    public function deleteNoteAction(): Action
    {
        return Action::make('deleteNote')
            ->label('Löschen')
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => ($note = $this->noteFromArguments($arguments)) !== null
                && $this->annotatingUser()->can('delete', $note))
            ->requiresConfirmation()
            ->modalHeading('Notiz löschen?')
            ->action(function (array $arguments): void {
                $this->noteFromArguments($arguments)?->delete();
                $this->refreshAnnotations();
            });
    }

    public function addAttachmentAction(): Action
    {
        return Action::make('addAttachment')
            ->label('Dokument hochladen')
            ->icon(Heroicon::OutlinedPaperClip)
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => $this->annotatingUser()->can('create', [Attachment::class, $this->annotatedRecord()]))
            ->modalHeading('Dokumente hochladen')
            ->modalDescription('Preislisten, Bestellbestätigungen, Rechnungen oder Fotos — PDF, Bilder, Office-Dateien, bis 10 MB pro Datei.')
            ->schema([
                FileUpload::make('files')
                    ->label('Dateien')
                    ->multiple()
                    ->maxFiles(10)
                    ->disk('local')
                    ->directory(fn (): string => 'attachments/'.(Filament::getTenant()?->getKey() ?? 'shared'))
                    ->visibility('private')
                    ->preserveFilenames(false)
                    ->storeFileNamesIn('names')
                    ->acceptedFileTypes(static::$acceptedDocumentTypes)
                    ->maxSize(10240)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $record = $this->annotatedRecord();
                $names = $data['names'] ?? [];

                foreach ((array) $data['files'] as $path) {
                    $record->attachments()->create([
                        'uploaded_by_user_id' => $this->annotatingUser()->id,
                        'group_id' => Filament::getTenant()?->getKey(),
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => $names[$path] ?? basename($path),
                        'mime_type' => Storage::disk('local')->mimeType($path) ?: null,
                        'size_bytes' => Storage::disk('local')->size($path),
                    ]);
                }

                $record->logActivity('attachment_added', ['names' => array_values($names)]);

                Notification::make()->title('Dokumente gespeichert.')->success()->send();
                $this->refreshAnnotations();
            });
    }

    public function deleteAttachmentAction(): Action
    {
        return Action::make('deleteAttachment')
            ->label('Löschen')
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => ($attachment = $this->attachmentFromArguments($arguments)) !== null
                && $this->annotatingUser()->can('delete', $attachment))
            ->requiresConfirmation()
            ->modalHeading('Dokument löschen?')
            ->action(function (array $arguments): void {
                $this->attachmentFromArguments($arguments)?->delete();
                $this->refreshAnnotations();
            });
    }

    /**
     * @return Collection<int, Note>
     */
    public function visibleNotes(): Collection
    {
        return $this->annotatedRecord()->notes()
            ->with('user')
            ->when(! $this->isShared($this->annotatedRecord()), fn ($query) => $query->where('group_id', Filament::getTenant()?->getKey()))
            ->get();
    }

    /**
     * @return Collection<int, Attachment>
     */
    public function visibleAttachments(): Collection
    {
        return $this->annotatedRecord()->attachments()
            ->with('uploadedBy')
            ->when(! $this->isShared($this->annotatedRecord()), fn ($query) => $query->where('group_id', Filament::getTenant()?->getKey()))
            ->get();
    }

    protected function isShared(Model $record): bool
    {
        return ($record instanceof Supplier || $record instanceof Product) && $record->isPublic();
    }

    protected function annotatingUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function noteFromArguments(array $arguments): ?Note
    {
        return $this->visibleNotes()->firstWhere('id', (int) ($arguments['note'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function attachmentFromArguments(array $arguments): ?Attachment
    {
        return $this->visibleAttachments()->firstWhere('id', (int) ($arguments['attachment'] ?? 0));
    }
}
