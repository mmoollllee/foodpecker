<?php

namespace App\Filament\Pages\Tenancy;

use App\Filament\Pages\Members;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\GroupDissolution;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

class EditGroupProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Gruppen-Einstellungen';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stammdaten')
                ->schema([
                    TextInput::make('name')
                        ->label('Gruppenname')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->label('Slug (URL-Kürzel)')
                        ->required()
                        ->alphaDash()
                        ->maxLength(255)
                        ->unique('groups', 'slug', ignoreRecord: true)
                        ->helperText('Achtung: Ändert alle Links zur Gruppe, auch bereits verschickte.'),
                    TextInput::make('contact_email')
                        ->label('Kontakt-E-Mail')
                        ->email(),
                    Textarea::make('description')
                        ->label('Beschreibung')
                        ->rows(4),
                ])->columns(2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->dissolveGroupAction(),
        ];
    }

    /**
     * Only the owner may dissolve the group — after seeing what goes away
     * and typing the group name.
     */
    public function dissolveGroupAction(): Action
    {
        return Action::make('dissolveGroup')
            ->label('Gruppe auflösen')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => $this->currentUser()->can('delete', $this->getGroup()))
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalHeading(fn (): string => 'Gruppe „'.$this->getGroup()->name.'“ auflösen?')
            ->modalContent(fn (): View => view('filament.pages.tenancy.dissolve-group', [
                'consequences' => $this->dissolutionConsequences(),
                'blockingReasons' => app(GroupDissolution::class)->blockingReasons($this->getGroup()),
                'transferUrl' => $this->getGroup()->members()->count() > 1 ? Members::getUrl() : null,
            ]))
            ->schema(fn (): array => $this->canBeDissolved() ? [
                TextInput::make('confirmation')
                    ->label('Zum Bestätigen den Gruppennamen eintippen')
                    ->placeholder($this->getGroup()->name)
                    ->required()
                    ->in([$this->getGroup()->name])
                    ->validationMessages(['in' => 'Der Name stimmt nicht mit dem Gruppennamen überein.']),
                Toggle::make('notify_members')
                    ->label('Mitglieder per E-Mail informieren')
                    ->default(true),
            ] : [])
            ->modalSubmitAction(fn (Action $action): Action|false => $this->canBeDissolved() ? $action : false)
            ->modalSubmitActionLabel('Endgültig auflösen')
            ->modalCancelActionLabel(fn (): string => $this->canBeDissolved() ? 'Abbrechen' : 'Schließen')
            ->action(function (array $data, Action $action): void {
                $group = $this->getGroup();
                $name = $group->name;

                try {
                    $result = app(GroupDissolution::class)->dissolve($group, $this->currentUser(), (bool) ($data['notify_members'] ?? false));
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Die Gruppe kann noch nicht aufgelöst werden.')
                        ->body(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Die Gruppe „'.$name.'“ wurde aufgelöst.')
                    ->body($result['failed'] > 0
                        ? sprintf('%d Mitglieder konnten nicht per E-Mail benachrichtigt werden.', $result['failed'])
                        : null)
                    ->success()
                    ->persistent()
                    ->send();

                $this->redirect(route('filament.global.tenant'));
            });
    }

    /**
     * What dissolving deletes and what stays for the other groups, as
     * sentences for the confirmation modal.
     *
     * @return array{deleted: array<int, string>, kept: array<int, string>}
     */
    protected function dissolutionConsequences(): array
    {
        $preview = app(GroupDissolution::class)->preview($this->getGroup());

        $deleted = [];

        if ($preview['rounds'] > 0) {
            $deleted[] = $this->counted($preview['rounds'], 'Bestellrunde', 'Bestellrunden').' mit Warenkörben, Vorschlägen, Zahlungen, Notizen und Dokumenten';
        }

        $deleted[] = $this->counted($preview['members'], 'Mitgliedschaft', 'Mitgliedschaften').' und alle offenen Einladungen — die Konten selbst bleiben bestehen';

        if ($catalog = $this->catalogCount($preview['deleted_products'], $preview['deleted_manufacturers'])) {
            $deleted[] = $catalog.', die nur eure Gruppe nutzt';
        }

        $kept = [];

        if ($catalog = $this->catalogCount($preview['kept_products'], $preview['kept_manufacturers'])) {
            $kept[] = $catalog.', die ihr geteilt habt oder die andere Gruppen schon bestellt haben — sie gehören danach allen Gruppen';
        }

        $kept[] = 'Notizen, Dokumente und Preise an geteilten Einträgen — ohne eure Namen';

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    private function catalogCount(int $products, int $manufacturers): ?string
    {
        $parts = array_filter([
            $products > 0 ? $this->counted($products, 'Produkt', 'Produkte') : null,
            $manufacturers > 0 ? $this->counted($manufacturers, 'Hersteller', 'Hersteller') : null,
        ]);

        return $parts === [] ? null : implode(' und ', $parts);
    }

    private function counted(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    protected function canBeDissolved(): bool
    {
        return app(GroupDissolution::class)->blockingReasons($this->getGroup()) === [];
    }

    protected function getGroup(): Group
    {
        /** @var Group $group */
        $group = $this->tenant;

        return $group;
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
