<?php

use App\Models\Product;
use Database\Seeders\DemoSeeder;

beforeEach(function () {
    $this->seed(DemoSeeder::class);
});

it('rendert die Product-Card mit Hersteller, Kategorie und Preisstaffeln', function () {
    $reis = Product::with('manufacturer', 'priceTiers')
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
        ->toContain('Mengenstaffel')       // Verpackungs-Strategie
        ->toContain('10 kg Sack')
        ->toContain('25 kg Sack')
        ->toContain('50 kg Sack')
        ->toContain('teilbar');
});

it('zeigt im Compact-Modus keine Preisstaffeln', function () {
    $reis = Product::with('manufacturer', 'priceTiers')
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

it('hat passende Badges für nicht-teilbare Produkte', function () {
    $spaghetti = Product::with('manufacturer', 'priceTiers')
        ->where('name', 'Spaghetti N. 5 (Bronzeziehung)')
        ->firstOrFail();

    $html = view('components.foodpecker.product-card', [
        'product' => $spaghetti,
    ])->render();

    expect($html)->toContain('nicht teilbar')
        ->toContain('Mehrere Größen, nicht teilbar')
        ->toContain('Teigwaren');
});
