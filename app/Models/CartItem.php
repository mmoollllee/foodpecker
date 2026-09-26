<?php

namespace App\Models;

use App\Enums\ProductUnit;
use App\Enums\QuantityMode;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $fillable = [
        'round_id',
        'user_id',
        'product_id',
        'preferred_price_tier_id',
        'quantity_mode',
        'exact_quantity',
        'min_quantity',
        'max_quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_mode' => QuantityMode::class,
            'exact_quantity' => 'decimal:3',
            'min_quantity' => 'decimal:3',
            'max_quantity' => 'decimal:3',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function preferredTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class, 'preferred_price_tier_id');
    }

    public function effectiveMin(): float
    {
        return (float) ($this->quantity_mode === QuantityMode::Exact
            ? $this->exact_quantity
            : $this->min_quantity);
    }

    public function effectiveMax(): float
    {
        return (float) ($this->quantity_mode === QuantityMode::Exact
            ? $this->exact_quantity
            : $this->max_quantity);
    }

    public function summary(): string
    {
        $unit = $this->product?->unitLabel() ?? '';

        if ($this->quantity_mode === QuantityMode::Exact) {
            return static::formatAmount((float) $this->exact_quantity, $unit);
        }

        return static::formatQuantity((float) $this->min_quantity).'–'.static::formatAmount((float) $this->max_quantity, $unit);
    }

    /**
     * German number format without superfluous decimals ("2,5" instead of "2,50").
     */
    public static function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, ',', '.'), '0'), ',');
    }

    /**
     * A quantity for an input field: decimal comma, no thousands separator
     * ("2500", "1,25") — so it reads back as the same number.
     */
    public static function toInputString(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, ',', ''), '0'), ',');
    }

    /**
     * A quantity with its unit, e.g. "0,5 kg", "1 Packung" or "3 Packungen".
     */
    public static function formatAmount(float $quantity, ?string $unit): string
    {
        $label = ProductUnit::tryFromShortLabel($unit ?? '')?->labelFor($quantity) ?? $unit;

        return trim(static::formatQuantity($quantity).' '.$label);
    }

    /**
     * From one quantity to another with the unit, e.g. "2–3 Packungen" — or
     * just one quantity when both are the same.
     */
    public static function formatRange(float $min, float $max, ?string $unit): string
    {
        return abs($max - $min) < 0.0005
            ? static::formatAmount($min, $unit)
            : static::formatQuantity($min).'–'.static::formatAmount($max, $unit);
    }
}
