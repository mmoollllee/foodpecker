<?php

use App\Enums\QuantityMode;
use App\Filament\Pages\MyCart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Round;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DemoSeeder::class);

    $this->tobias = User::where('email', 'tobias@foodpecker.test')->firstOrFail();
    $this->round = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail();
    $this->round->update(['phase' => 'shopping']);

    $this->actingAs($this->tobias);
    Filament::setTenant($this->round->group);
    Filament::setCurrentPanel('global');
    Filament::bootCurrentPanel();
});

it('speichert Artikel mit exakter Menge per Add-Modal', function () {
    $polenta = Product::where('name', 'Bio Polenta grob')->firstOrFail();
    CartItem::where('round_id', $this->round->id)->where('user_id', $this->tobias->id)->where('product_id', $polenta->id)->delete();

    Livewire::test(MyCart::class)
        ->mountAction('addItem', ['round_id' => $this->round->id])
        ->setActionData([
            'product_id' => $polenta->id,
            'quantity_mode' => QuantityMode::Exact->value,
            'exact_quantity' => 4.5,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $item = CartItem::where('round_id', $this->round->id)
        ->where('user_id', $this->tobias->id)
        ->where('product_id', $polenta->id)
        ->first();

    expect($item)->not->toBeNull();
    expect((float) $item->exact_quantity)->toBe(4.5);
    expect($item->quantity_mode)->toBe(QuantityMode::Exact);
});

it('speichert Artikel mit flexibler Spanne per Add-Modal', function () {
    $hafer = Product::where('name', 'Bio Haferflocken kernig')->firstOrFail();
    CartItem::where('round_id', $this->round->id)->where('user_id', $this->tobias->id)->where('product_id', $hafer->id)->delete();

    Livewire::test(MyCart::class)
        ->mountAction('addItem', ['round_id' => $this->round->id])
        ->setActionData([
            'product_id' => $hafer->id,
            'quantity_mode' => QuantityMode::Flexible->value,
            'min_quantity' => 3,
            'max_quantity' => 8,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $item = CartItem::where('round_id', $this->round->id)
        ->where('user_id', $this->tobias->id)
        ->where('product_id', $hafer->id)
        ->first();

    expect($item)->not->toBeNull();
    expect((float) $item->min_quantity)->toBe(3.0);
    expect((float) $item->max_quantity)->toBe(8.0);
    expect($item->quantity_mode)->toBe(QuantityMode::Flexible);
});

it('zeigt Validierungsfehler, wenn quantity_mode=exact aber exact_quantity leer ist', function () {
    $reis = Product::where('name', 'Bio Basmati Reis')->firstOrFail();

    Livewire::test(MyCart::class)
        ->mountAction('addItem', ['round_id' => $this->round->id])
        ->setActionData([
            'product_id' => $reis->id,
            'quantity_mode' => QuantityMode::Exact->value,
            // exact_quantity bewusst leer
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['exact_quantity']);
});
