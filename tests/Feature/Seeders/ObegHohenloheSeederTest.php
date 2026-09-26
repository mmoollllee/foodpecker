<?php

use App\Enums\ProductUnit;
use App\Models\Group;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Supplier;
use Database\Seeders\ObegHohenloheSeeder;
use Filament\Facades\Filament;

it('seeds the OBEG price list as a catalog every group can order from and maintain', function () {
    $this->seed();

    $obeg = Supplier::where('slug', 'obeg-hohenlohe')->firstOrFail();
    $schoeneberg = Group::where('slug', 'speisekammer-schoeneberg')->firstOrFail();
    $this->actingAs($schoeneberg->owner);
    Filament::setTenant($schoeneberg);

    expect(Product::visibleTo($schoeneberg)->whereBelongsTo($obeg)->count())->toBe(334);
    expect($schoeneberg->owner->can('update', $obeg))->toBeTrue();
});

it('offers every size of a staple as a package of one product at its gross price', function () {
    $this->seed(ObegHohenloheSeeder::class);

    $gruenkern = Product::where('name', 'Grünkern')->firstOrFail();

    expect($gruenkern->unit)->toBe(ProductUnit::Kilogram);
    expect($gruenkern->portionSize())->toBe(0.5);
    expect($gruenkern->priceTiers->map(fn (PriceTier $tier): array => [$tier->label, (float) $tier->package_amount, $tier->article_number, $tier->price_cents])->all())
        ->toBe([
            ['Karton 12 × 500 g', 6.0, 'ogk0', 3492],
            ['Karton 12 × 1 kg', 12.0, 'ogk1', 5817],
            ['25 kg Sack', 25.0, 'ogks', 9710],
        ]);
});

it('stores drinks at their gross price with the crate deposit included', function (string $name, int $grossCents) {
    $this->seed(ObegHohenloheSeeder::class);

    $drink = Product::where('name', $name)->firstOrFail();

    expect($drink->priceTiers->sole()->price_cents)->toBe($grossCents);
})->with([
    'beer: 19 % VAT on price and crate deposit' => ['Lammsbräu Öko-Edelpils', 1083],
    'wine in a carton: 19 % VAT, no deposit' => ['Riesling trocken', 3699],
    'milk: 7 % VAT like food' => ['H-Milch 3,5 %', 2131],
]);

it('leaves the catalog alone when it has been seeded before', function () {
    $this->seed(ObegHohenloheSeeder::class);

    $this->artisan('db:seed', ['--class' => ObegHohenloheSeeder::class])
        ->expectsOutputToContain('OBEG Hohenlohe ist schon angelegt')
        ->assertSuccessful();

    expect(Supplier::where('name', 'OBEG Hohenlohe')->count())->toBe(1);
    expect(Product::count())->toBe(334);
});
