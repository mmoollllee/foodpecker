<?php

namespace App\Models;

use App\Enums\GroupRole;
use App\Enums\RoundPhase;
use App\Services\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'subject_type',
        'subject_id',
        'action',
        'properties',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Human readable sentence for the activity stream and notification
     * drafts, e.g. "Marie: Phase Einkauf → Verhandlung".
     */
    public function describe(): string
    {
        $who = $this->user?->first_name ?? 'System';

        return $who.': '.$this->describeAction();
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function fieldLabels(array $fields): string
    {
        $labels = [
            'name' => 'Name',
            'visibility' => 'Sichtbarkeit',
            'description' => 'Beschreibung',
            'estimated_price_cents' => 'Richtwert',
            'packaging_strategy' => 'Verpackung',
            'unit' => 'Einheit',
            'category' => 'Kategorie',
            'manufacturer_id' => 'Hersteller',
            'image_path' => 'Bild',
            'slug' => 'URL-Kürzel',
            'website' => 'Website',
            'contact_email' => 'E-Mail',
            'contact_phone' => 'Telefon',
            'address' => 'Adresse',
            'shipping_notes' => 'Versandhinweise',
            'group_id' => 'Eigentümer-Gruppe',
        ];

        return collect($fields)->map(fn (string $field): string => $labels[$field] ?? $field)->implode(', ');
    }

    public function describeAction(): string
    {
        $properties = $this->properties ?? [];
        $title = isset($properties['title']) ? '„'.$properties['title'].'“' : null;
        $reason = filled($properties['reason'] ?? null) ? ' (Grund: '.$properties['reason'].')' : '';

        return match ($this->action) {
            'created' => match ($this->subject_type) {
                OrderProposal::class => 'Vorschlag '.($title ?? '').' erstellt',
                Round::class => 'Runde '.($title ?? '').' angelegt',
                Manufacturer::class => 'Hersteller '.($title ?? '').' angelegt',
                Product::class => 'Produkt '.($title ?? '').' angelegt',
                Group::class => 'Gruppe '.($title ?? '').' gegründet',
                default => 'angelegt',
            },
            'updated' => $this->subject_type === Round::class
                ? 'Eckdaten der Runde aktualisiert'
                : 'Geändert: '.$this->fieldLabels($properties['fields'] ?? []),
            'archived' => 'archiviert',
            'ownership_released' => 'Die Gruppe „'.($properties['group'] ?? '?').'“ wurde aufgelöst — der Eintrag gehört jetzt allen Gruppen',
            'restored' => 'wiederhergestellt',
            'tier_changed' => sprintf(
                'Preisstaffel „%s“ %s%s',
                $properties['label'] ?? '?',
                match ($properties['change'] ?? null) {
                    'added' => 'hinzugefügt',
                    'removed' => 'entfernt',
                    default => 'geändert',
                },
                isset($properties['price_cents']) && ($properties['change'] ?? null) !== 'removed' ? ' ('.Money::format((int) $properties['price_cents']).')' : '',
            ),
            'attachment_added' => 'Dokument hochgeladen: '.implode(', ', $properties['names'] ?? []),
            'phase_changed' => sprintf(
                'Phase %s → %s%s',
                RoundPhase::tryFrom($properties['from'] ?? '')?->getLabel() ?? '?',
                RoundPhase::tryFrom($properties['to'] ?? '')?->getLabel() ?? '?',
                $reason,
            ),
            'proposal_published' => 'Vorschlag '.($title ?? '').' zur Abstimmung freigegeben',
            'proposal_withdrawn' => 'Vorschlag '.($title ?? '').' zurückgezogen'.$reason,
            'proposal_chosen' => 'Vorschlag '.($title ?? '').' als finale Bestellung gewählt',
            'participant_excluded' => ($properties['name'] ?? 'Jemand').' aus der Bestellung ausgeschlossen'.$reason,
            'participant_readmitted' => ($properties['name'] ?? 'Jemand').' wieder in die Bestellung aufgenommen',
            'lead_handover_requested' => 'Lead-Übergabe an '.($properties['to'] ?? '?').' angefragt',
            'lead_handed_over' => 'Lead-Rolle von '.($properties['from'] ?? '?').' an '.($properties['to'] ?? '?').' übergeben',
            'lead_handover_declined' => ($properties['to'] ?? 'Jemand').' hat die Lead-Übergabe abgelehnt',
            'lead_handover_cancelled' => 'Lead-Übergabe an '.($properties['to'] ?? '?').' zurückgezogen',
            'owner_transfer_requested' => 'Owner-Übergabe an '.($properties['to'] ?? '?').' angefragt',
            'owner_transferred' => 'Owner-Rolle von '.($properties['from'] ?? '?').' an '.($properties['to'] ?? '?').' übergeben',
            'owner_transfer_declined' => ($properties['to'] ?? 'Jemand').' hat die Owner-Übergabe abgelehnt',
            'owner_transfer_cancelled' => 'Owner-Übergabe an '.($properties['to'] ?? '?').' zurückgezogen',
            'member_joined' => 'der Gruppe als '.(GroupRole::tryFrom($properties['role'] ?? '')?->getLabel() ?? 'Mitglied').' beigetreten',
            'member_left' => 'hat die Gruppe verlassen',
            'member_removed' => ($properties['name'] ?? 'Jemand').' aus der Gruppe entfernt',
            'role_changed' => sprintf(
                'Rolle von %s: %s → %s',
                $properties['name'] ?? '?',
                GroupRole::tryFrom($properties['from'] ?? '')?->getLabel() ?? '?',
                GroupRole::tryFrom($properties['to'] ?? '')?->getLabel() ?? '?',
            ),
            'invitation_withdrawn' => 'Einladung an '.($properties['email'] ?? '?').' zurückgezogen',
            'invited' => ($properties['email'] ?? 'Jemand').' als '.(GroupRole::tryFrom($properties['role'] ?? '')?->getLabel() ?? 'Mitglied').' eingeladen',
            'note_added' => 'Notiz geschrieben',
            'payment_marked' => 'Zahlung von '.($properties['name'] ?? '?').' als „'.($properties['status'] ?? '?').'“ markiert',
            'pickup_marked' => ($properties['name'] ?? 'Jemand').' hat abgeholt',
            default => $this->action,
        };
    }
}
