<?php

namespace App\Models;

use App\Concerns\HasActivities;
use App\Concerns\HasAttachments;
use App\Concerns\HasNotes;
use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Enums\Visibility;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasActivities, HasAttachments, HasFactory, HasNotes, SoftDeletes;

    protected $fillable = [
        'group_id',
        'supplier_id',
        'created_by_user_id',
        'name',
        'slug',
        'visibility',
        'unit',
        'category',
        'portion_size',
        'description',
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'unit' => ProductUnit::class,
            'category' => ProductCategory::class,
            'portion_size' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $p): void {
            if (blank($p->slug)) {
                $p->slug = Str::slug($p->name).'-'.Str::lower(Str::random(4));
            }
        });

        static::created(fn (self $product) => $product->logActivity('created', ['title' => $product->name]));

        static::updated(function (self $product): void {
            $fields = array_keys(Arr::except($product->getChanges(), ['updated_at', 'deleted_at', 'slug']));

            if ($fields !== []) {
                $product->logActivity('updated', ['fields' => $fields]);
            }
        });

        static::deleted(function (self $product): void {
            if (! $product->isForceDeleting()) {
                $product->logActivity('archived');
            }
        });

        static::restored(fn (self $product) => $product->logActivity('restored'));

        // A replaced or removed image is not needed anymore.
        static::updated(function (self $product): void {
            if ($product->wasChanged('image_path')) {
                $product->deleteImageFile($product->getOriginal('image_path'));
            }
        });

        static::forceDeleted(fn (self $product) => $product->deleteImageFile($product->image_path));
    }

    /**
     * Removes an image file once the change is committed — unless another
     * product still shows the same file. Archived products keep theirs.
     */
    private function deleteImageFile(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        $this->getConnection()->afterCommit(function () use ($path): void {
            if (static::withTrashed()->where('image_path', $path)->doesntExist()) {
                Storage::disk('public')->delete($path);
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('sort_order')->orderBy('package_amount');
    }

    public function priceObservations(): HasMany
    {
        return $this->hasMany(PriceObservation::class)->orderByDesc('observed_on')->orderByDesc('id');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function proposalItems(): HasMany
    {
        return $this->hasMany(ProposalItem::class);
    }

    /**
     * Whether carts, proposals or the price history point to this product —
     * then it can only be archived, not deleted for good.
     */
    public function isReferenced(): bool
    {
        return $this->cartItems()->exists()
            || $this->proposalItems()->exists()
            || $this->priceObservations()->exists();
    }

    public function isPublic(): bool
    {
        return $this->visibility === Visibility::Public;
    }

    /**
     * Prices paid in real orders: the group's own, plus those of other
     * groups for shared products.
     *
     * @return Collection<int, PriceObservation>
     */
    public function recentPriceObservations(?Group $group, int $limit = 3): Collection
    {
        return $this->priceObservations()
            ->with('group:id,name')
            ->when(! $this->isPublic(), fn (Builder $query) => $query->where('group_id', $group?->id))
            ->limit($limit)
            ->get();
    }

    /**
     * Short unit label for quantities, e.g. "kg" or "Glas".
     */
    public function unitLabel(): string
    {
        return $this->unit?->shortLabel() ?? '';
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

    /**
     * Whether packages of this product are shared out in portions — or
     * every package goes whole to one person.
     */
    public function isPortioned(): bool
    {
        return $this->portion_size !== null && (float) $this->portion_size > 0;
    }

    public function portionSize(): ?float
    {
        return $this->isPortioned() ? (float) $this->portion_size : null;
    }

    /**
     * Whether an amount comes in whole portions — nobody gets half a jar or
     * a torn pack. Products that go out in whole packages take any amount.
     */
    public function isWholePortions(float $quantity): bool
    {
        $portion = $this->portionSize();

        return $portion === null || abs($quantity / $portion - round($quantity / $portion)) < 0.0001;
    }

    /**
     * The nearest amount in whole portions, at least one portion.
     */
    public function toWholePortions(float $quantity): float
    {
        $portion = $this->portionSize();

        return $portion === null ? $quantity : round(max(1, round($quantity / $portion)) * $portion, 3);
    }

    /**
     * How the product is shared, e.g. "in Portionen zu 0,5 kg".
     */
    public function distributionLabel(): string
    {
        return $this->isPortioned()
            ? 'in Portionen zu '.CartItem::formatAmount((float) $this->portion_size, $this->unitLabel())
            : 'nur ganze Packungen';
    }

    public function packagingSummary(): string
    {
        $tiers = $this->priceTiers->map(fn (PriceTier $t) => $t->label);

        return $tiers->isEmpty() ? '—' : $tiers->implode(' · ');
    }

    /**
     * Liefert die öffentliche URL zum Produktbild — oder null, wenn keines hinterlegt ist.
     */
    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }

    /**
     * Initialen für die Fallback-Darstellung in der Product-Card (max. 2 Zeichen).
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name ?? ''));
        $initials = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }

        return $initials ?: '?';
    }
}
