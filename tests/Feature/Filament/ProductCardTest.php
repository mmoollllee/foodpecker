<?php

use App\Models\Product;
use Database\Seeders\DemoSeeder;

beforeEach(function () {
    $this->seed(DemoSeeder::class);
});

it('shows supplier, category, distribution and packages on the product card', function () {
    $reis = Product::with('supplier', 'priceTiers')
        ->where('name', 'Bio Basmati Reis')
        ->firstOrFail();

    $html = view('components.foodpecker.product-card', [
        'product' => $reis,
        'compact' => false,
        'showDescription' => true,
        'showPricing' => true,
    ])->render();

    expect($html)->toContain('Bio Basmati Reis')
        ->toContain('Spielberger Mühle')
        ->toContain('Getreide')           // Kategorie
        ->toContain('in Portionen zu 0,5 kg')
        ->toContain('10 kg Sack')
        ->toContain('25 kg Sack')
        ->toContain('50 kg Sack')
        ->toContain('Art.-Nr. SM-BAS-25');
});

it('zeigt im Compact-Modus keine Preisstaffeln', function () {
    $reis = Product::with('supplier', 'priceTiers')
        ->where('name', 'Bio Basmati Reis')
        ->firstOrFail();

    $html = view('components.foodpecker.product-card', [
        'product' => $reis,
        'compact' => true,
    ])->render();

    expect($html)->toContain('Bio Basmati Reis')
        ->toContain('Spielberger Mühle')
        ->not->toContain('Gebindegrößen & Preise');
});

it('rendert nichts wenn product null ist', function () {
    $html = view('components.foodpecker.product-card', [
        'product' => null,
    ])->render();

    expect(trim($html))->toBe('');
});

it('shows products that go out in whole packages as such', function () {
    $spaghetti = Product::with('supplier', 'priceTiers')
        ->where('name', 'Spaghetti N. 5 (Bronzeziehung)')
        ->firstOrFail();

    $html = view('components.foodpecker.product-card', [
        'product' => $spaghetti,
    ])->render();

    expect($html)->toContain('nur ganze Packungen')
        ->toContain('Teigwaren');
});
