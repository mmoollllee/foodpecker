<?php

namespace App\Models;

use App\Concerns\HasActivities;
use App\Concerns\HasAttachments;
use App\Concerns\HasNotes;
use App\Enums\Visibility;
use Database\Factories\ManufacturerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Manufacturer extends Model
{
    /** @use HasFactory<ManufacturerFactory> */
    use HasActivities, HasAttachments, HasFactory, HasNotes;

    protected $fillable = [
        'group_id',
        'created_by_user_id',
        'name',
        'slug',
        'visibility',
        'website',
        'contact_email',
        'contact_phone',
        'address',
        'shipping_notes',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $m): void {
            if (blank($m->slug)) {
                $m->slug = Str::slug($m->name).'-'.Str::lower(Str::random(4));
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function isPublic(): bool
    {
        return $this->visibility === Visibility::Public;
    }

    public function scopeVisibleTo(Builder $query, ?Group $group): Builder
    {
        return $query->where(function (Builder $q) use ($group): void {
            $q->where('visibility', Visibility::Public);
            if ($group) {
                $q->orWhere('group_id', $group->id);
            }
        });
    }
}
