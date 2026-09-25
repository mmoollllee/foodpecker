<?php

namespace App\Models;

use Database\Factories\PriceTierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceTier extends Model
{
    /** @use HasFactory<PriceTierFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'label',
        'package_amount',
        'min_order_packages',
        'price_cents',
        'is_divisible',
        'divisible_step',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'package_amount' => 'decimal:3',
            'divisible_step' => 'decimal:3',
            'is_divisible' => 'boolean',
            'price_cents' => 'integer',
            'min_order_packages' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (self $tier) => $tier->logChange('added'));
        static::updated(fn (self $tier) => $tier->logChange('changed'));
        static::deleted(fn (self $tier) => $tier->logChange('removed'));
    }

    /**
     * Price changes show up in the product's activity stream.
     */
    private function logChange(string $change): void
    {
        $this->product()->withTrashed()->first()?->logActivity('tier_changed', [
            'label' => $this->label,
            'change' => $change,
            'price_cents' => $this->price_cents,
        ]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function pricePerUnit(): float
    {
        if ((float) $this->package_amount === 0.0) {
            return 0.0;
        }

        return ($this->price_cents / 100) / (float) $this->package_amount;
    }

    public function formattedPricePerUnit(): string
    {
        return number_format($this->pricePerUnit(), 2, ',', '.').' € / '.$this->product?->unitLabel();
    }

    public function formattedPrice(): string
    {
        return number_format($this->price_cents / 100, 2, ',', '.').' €';
    }

    public function effectiveStep(): float
    {
        return (float) ($this->divisible_step ?? $this->package_amount);
    }
}
