<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A time window in which the participants can pick up their goods.
 */
class PickupDate extends Model
{
    protected $fillable = [
        'round_id',
        'scheduled_at',
        'ends_at',
        'location',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function pickups(): HasMany
    {
        return $this->hasMany(Pickup::class);
    }

    /**
     * E.g. "Sa. 22.06.2026 10:00–12:00".
     */
    public function label(): string
    {
        $start = $this->scheduled_at;

        if ($this->ends_at === null) {
            return $start->translatedFormat('D d.m.Y H:i');
        }

        return $this->ends_at->isSameDay($start)
            ? $start->translatedFormat('D d.m.Y H:i').'–'.$this->ends_at->format('H:i')
            : $start->translatedFormat('D d.m. H:i').' – '.$this->ends_at->translatedFormat('D d.m.Y H:i');
    }

    /**
     * Short form for chips, e.g. "22.06. 10:00–12:00".
     */
    public function shortLabel(): string
    {
        $start = $this->scheduled_at;

        return $this->ends_at !== null && $this->ends_at->isSameDay($start)
            ? $start->format('d.m. H:i').'–'.$this->ends_at->format('H:i')
            : $start->format('d.m. H:i');
    }
}
