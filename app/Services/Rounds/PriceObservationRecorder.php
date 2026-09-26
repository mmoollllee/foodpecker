<?php

namespace App\Services\Rounds;

use App\Models\PriceObservation;
use App\Models\Round;

/**
 * Stores the prices of the final order as reference values on the
 * products, so the next round can plan with real prices.
 */
class PriceObservationRecorder
{
    /**
     * @return int Number of recorded observations.
     */
    public function record(Round $round): int
    {
        $proposal = $round->chosenProposal()->with('items.packages')->first();

        if ($proposal === null) {
            return 0;
        }

        PriceObservation::query()->where('round_id', $round->id)->delete();

        $recorded = 0;

        foreach ($proposal->items as $item) {
            foreach ($item->packages as $package) {
                if ((float) $package->package_amount <= 0) {
                    continue;
                }

                PriceObservation::create([
                    'product_id' => $item->product_id,
                    'price_tier_id' => $package->price_tier_id,
                    'round_id' => $round->id,
                    'group_id' => $round->group_id,
                    'observed_price_cents' => $package->price_cents,
                    'package_amount' => $package->package_amount,
                    'observed_on' => now()->toDateString(),
                ]);

                $recorded++;
            }
        }

        return $recorded;
    }
}
