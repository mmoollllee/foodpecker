<?php

use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Enums\VoteValue;
use App\Filament\Pages\MyCart;
use App\Filament\Pages\MyOrders;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Round;
use App\Models\User;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use App\Services\Rounds\CartService;
use App\Services\Rounds\OrderHistory;
use Database\Seeders\DemoSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
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
        ->mountAction('addItem')
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
        ->mountAction('addItem')
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
        ->mountAction('addItem')
        ->setActionData([
            'product_id' => $reis->id,
            'quantity_mode' => QuantityMode::Exact->value,
            // exact_quantity bewusst leer
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['exact_quantity']);
});

it('suggests what somebody received in the last completed round', function () {
    // Filament would assign new rounds to the demo group booted in beforeEach().
    Filament::setTenant(null);

    ['group' => $group, 'round' => $previous, 'lead' => $lead, 'members' => [$anna]] = roundScenario(1, RoundPhase::Negotiating);
    $rice = productWithTier($group, packageAmount: 10, priceCents: 2800);
    CartItem::factory()->for($previous)->exact(10)->create(['user_id' => $anna->id, 'product_id' => $rice->id]);

    $proposal = app(ProposalBuilder::class)->createFromCarts($previous, $lead, ['title' => 'P']);
    $workflow = app(ProposalWorkflow::class);
    $workflow->publish($proposal, $lead);
    $previous->update(['phase' => RoundPhase::Finalizing]);
    $workflow->vote($proposal->items()->first(), $anna, VoteValue::Up);
    $workflow->choose($proposal->fresh(), $lead);
    $previous->update(['phase' => RoundPhase::Completed]);

    $current = Round::factory()->for($group)->create(['lead_user_id' => $lead->id]);
    actingInGroup($anna, $group);

    Livewire::test(MyCart::class)
        ->assertSee('Letztes Mal ('.$previous->title.') hast du bekommen')
        ->callAction('copyPreviousOrder')
        ->assertNotified('1 Artikel übernommen.');

    expect((float) $current->cartItems()->where('user_id', $anna->id)->value('exact_quantity'))->toBe(10.0);
});

it('does not let people change the cart items of others', function () {
    $saraItem = CartItem::where('round_id', $this->round->id)
        ->where('user_id', User::where('email', 'sara@foodpecker.test')->value('id'))
        ->firstOrFail();

    Livewire::test(MyCart::class)
        ->assertActionHidden(TestAction::make('removeItem')->arguments(['item' => $saraItem->id]))
        ->call('mountAction', 'removeItem', ['item' => $saraItem->id])
        ->call('callMountedAction');

    expect($saraItem->fresh())->not->toBeNull();
});

it('keeps new people out once the round is full', function () {
    Filament::setTenant(null);

    ['group' => $group, 'round' => $round, 'members' => [$anna, $ben]] = roundScenario(2);
    $round->update(['max_participants' => 3]);

    $late = User::factory()->create();
    $group->members()->attach($late->id, ['role' => 'participant']);

    actingInGroup($late, $group);

    Livewire::test(MyCart::class)
        ->assertSee('Die Runde ist voll (maximal 3 Teilnehmer).')
        ->assertActionHidden('addItem');

    expect(fn () => app(CartService::class)->save($round, $late, [
        'product_id' => productWithTier($group)->id,
        'quantity_mode' => 'exact',
        'exact_quantity' => 1,
    ]))->toThrow(ValidationException::class, 'Die Runde ist voll (maximal 3 Teilnehmer).');
});

it('keeps the own cart visible but read-only once shopping is over', function () {
    $this->round->update(['phase' => RoundPhase::Finalizing]);
    $item = CartItem::where('round_id', $this->round->id)->where('user_id', $this->tobias->id)->with('product')->firstOrFail();

    Livewire::test(MyCart::class)
        ->assertSee('Der Einkauf ist vorbei')
        ->assertSee($item->product->name)
        ->assertActionHidden('addItem')
        ->assertActionHidden(TestAction::make('editItem')->arguments(['item' => $item->id]))
        ->assertActionHidden(TestAction::make('removeItem')->arguments(['item' => $item->id]));
});

it('says so while no round is running', function () {
    Round::query()->update(['phase' => RoundPhase::Completed]);

    Livewire::test(MyCart::class)
        ->assertSee('Gerade läuft keine Bestellrunde.')
        ->assertActionHidden('addItem');
});

it('lists the own past orders with frozen prices', function () {
    $marie = User::where('email', 'marie@foodpecker.test')->firstOrFail();
    $completed = Round::where('title', 'Spätsommer-Bestellung 2025')->firstOrFail();
    actingInGroup($marie, $completed->group);

    $orders = app(OrderHistory::class)->ordersFor($marie, $completed->group);

    expect($orders->pluck('round.id'))->toContain($completed->id);

    Livewire::test(MyOrders::class)
        ->assertSee('Spätsommer-Bestellung 2025')
        ->assertSee('Bio Dinkelmehl Type 630')
        ->assertSee('Bezahlt');
});
