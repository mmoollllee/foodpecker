<?php

namespace App\Models;

use App\Concerns\HasActivities;
use App\Concerns\HasAttachments;
use App\Concerns\HasNotes;
use App\Enums\PackagingStrategy;
use App\Enums\Visibility;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasActivities, HasAttachments, HasFactory, HasNotes;

    protected $fillable = [
        'group_id',
        'manufacturer_id',
        'created_by_user_id',
        'name',
        'slug',
        'visibility',
        'unit',
        'packaging_strategy',
        'description',
        'estimated_price_cents',
        'estimated_price_per_unit',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'packaging_strategy' => PackagingStrategy::class,
            'estimated_price_per_unit' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $p): void {
            if (blank($p->slug)) {
                $p->slug = Str::slug($p->name).'-'.Str::lower(Str::random(4));
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('sort_order')->orderBy('package_amount');
    }

    public function defaultTier(): ?PriceTier
    {
        return $this->priceTiers()->first();
    }

    public function priceObservations(): HasMany
    {
        return $this->hasMany(PriceObservation::class)->orderByDesc('observed_on');
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

    public function formattedEstimatedPrice(): ?string
    {
        if ($this->estimated_price_cents === null) {
            return null;
        }

        return number_format($this->estimated_price_cents / 100, 2, ',', '.').' €';
    }

    public function packagingSummary(): string
    {
        $tiers = $this->priceTiers->map(fn (PriceTier $t) => $t->label);

        if ($tiers->isEmpty()) {
            return $this->packaging_strategy?->getLabel() ?? '—';
        }

        return $tiers->implode(' · ');
    }
}
