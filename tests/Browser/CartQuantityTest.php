<?php

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Round;
use App\Models\User;
use Database\Seeders\DemoSeeder;

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    Round::where('title', 'Frühjahr-Bestellung 2026')->update(['phase' => 'shopping']);

    $this->marie = User::where('email', 'marie@foodpecker.test')->firstOrFail();
    $this->spirelli = Product::where('slug', 'spirelli-hartweizen')->firstOrFail();
    CartItem::where('user_id', $this->marie->id)->where('product_id', $this->spirelli->id)->delete();
});

it('lets the browser submit tenths and whole amounts of bulk goods', function (string $quantity) {
    $this->actingAs($this->marie);

    $page = visit('/g/speisekammer-schoeneberg/my-cart?'.http_build_query([
        'action' => 'addItem',
        'actionArguments' => ['product' => $this->spirelli->id],
    ]))
        ->fill('input[id$="exact_quantity"]', $quantity)
        ->press('In den Warenkorb');

    $page->assertSee('Artikel im Warenkorb gespeichert.')
        ->assertNoJavaScriptErrors();
    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->marie->id,
        'product_id' => $this->spirelli->id,
        'exact_quantity' => $quantity,
    ]);
})->with(['0.1', '5']);
