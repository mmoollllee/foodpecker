<?php

namespace App\Models;

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

    public function describe(): string
    {
        $verb = match ($this->action) {
            'created' => 'angelegt',
            'updated' => 'aktualisiert',
            'deleted' => 'gelöscht',
            'phase_changed' => 'Phase geändert',
            'note_added' => 'Notiz hinzugefügt',
            'invited' => 'eingeladen',
            'joined' => 'beigetreten',
            'voted' => 'abgestimmt',
            'proposal_published' => 'Vorschlag veröffentlicht',
            'payment_marked' => 'Zahlung markiert',
            'pickup_marked' => 'Abholung markiert',
            default => $this->action,
        };

        $subjectShort = $this->subject_type ? class_basename($this->subject_type) : '—';

        return $verb.' · '.$subjectShort.' #'.$this->subject_id;
    }
}
