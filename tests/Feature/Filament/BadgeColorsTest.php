<?php

use App\Enums\GroupRole;
use App\Enums\PaymentStatus;
use App\Enums\ProductCategory;
use App\Enums\ProposalStatus;
use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Enums\Visibility;
use App\Enums\VoteValue;
use Filament\Facades\Filament;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Facades\FilamentColor;

/**
 * Badges in colors the panel does not know render without any color, e.g.
 * the phase "Bestätigung" as a black outline.
 */
it('registers every color the enums use for their badges', function (string $enum) {
    Filament::setCurrentPanel('global');
    Filament::bootCurrentPanel();

    $colors = collect($enum::cases())
        ->map(fn (HasColor $case): string|array|null => $case->getColor())
        ->filter(fn (string|array|null $color): bool => is_string($color))
        ->unique()
        ->values()
        ->all();

    expect(array_keys(FilamentColor::getColors()))->toContain(...$colors);
})->with([
    GroupRole::class,
    PaymentStatus::class,
    ProductCategory::class,
    ProposalStatus::class,
    QuantityMode::class,
    RoundPhase::class,
    Visibility::class,
    VoteValue::class,
]);
