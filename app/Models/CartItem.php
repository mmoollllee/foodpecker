<?php

namespace App\Models;

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
        return $this->belongsTo(Product::class);
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
        if ($this->quantity_mode === QuantityMode::Exact) {
            return number_format((float) $this->exact_quantity, 2, ',', '.').' '.($this->product?->unit ?? '');
        }

        return number_format((float) $this->min_quantity, 2, ',', '.').'–'.
            number_format((float) $this->max_quantity, 2, ',', '.').' '.($this->product?->unit ?? '');
    }
}
