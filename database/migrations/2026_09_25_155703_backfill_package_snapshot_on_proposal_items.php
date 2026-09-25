<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Copies the package data of the referenced price tier into proposal
     * items that were created before the snapshot columns existed.
     */
    public function up(): void
    {
        DB::table('proposal_items')
            ->whereNull('package_amount')
            ->whereNotNull('price_tier_id')
            ->orderBy('id')
            ->each(function (object $item): void {
                $tier = DB::table('price_tiers')->find($item->price_tier_id);

                if ($tier === null) {
                    return;
                }

                DB::table('proposal_items')->where('id', $item->id)->update([
                    'tier_label' => $tier->label,
                    'package_amount' => $tier->package_amount,
                    'package_price_cents' => $tier->price_cents,
                    'is_divisible' => $tier->is_divisible,
                    'divisible_step' => $tier->divisible_step,
                    'min_order_packages' => $tier->min_order_packages,
                ]);
            });
    }

    public function down(): void
    {
        //
    }
};
