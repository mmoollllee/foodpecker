<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person gets of a proposal position and pays for it.
 */
class ProposalAllocation extends Model
{
    protected $fillable = [
        'proposal_item_id',
        'user_id',
        'quantity',
        'share_cents',
        'is_manual',
        'package_counts',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'share_cents' => 'integer',
            'is_manual' => 'boolean',
            'package_counts' => 'array',
        ];
    }

    public function proposalItem(): BelongsTo
    {
        return $this->belongsTo(ProposalItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The person's own packages ("2 × 250 g Packung") for products that go
     * out in whole packages, otherwise the packages the portion comes from.
     */
    public function describePackages(ProposalItem $item): string
    {
        if (blank($this->package_counts)) {
            return $item->describePackages();
        }

        return collect($this->package_counts)
            ->map(fn (int $count, int $packageId): string => $count.' × '.($item->packages->firstWhere('id', $packageId)?->label ?? 'Packung'))
            ->implode(', ');
    }
}
