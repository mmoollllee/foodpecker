<?php

use App\Enums\GroupRole;
use App\Enums\Visibility;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\ProductsRelationManager;
use App\Models\Group;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);

    $this->participant = User::factory()->create();
    $this->group->members()->attach($this->participant->id, ['role' => GroupRole::Participant->value]);

    $this->otherOwner = User::factory()->create();
    $this->otherGroup = Group::factory()->create(['owner_id' => $this->otherOwner->id]);

    $this->supplier = Supplier::factory()->create(['group_id' => $this->group->id, 'visibility' => Visibility::Public]);
    $this->product = productWithTier($this->group);
    $this->product->update(['supplier_id' => $this->supplier->id, 'name' => 'Bio Basmati Reis', 'visibility' => Visibility::Public]);

    $this->newProductData = [
        'name' => 'Hofkäse',
        'unit' => 'kg',
        'visibility' => Visibility::Public->value,
        'is_portioned' => '1',
        'portion_size' => 0.5,
        'priceTiers' => [['label' => '5 kg Laib', 'package_amount' => 5, 'price_cents' => '42,50', 'min_order_packages' => 1]],
    ];
});

it('shows the products on the supplier page', function () {
    actingInGroup($this->participant, $this->group);

    Livewire::test(ViewSupplier::class, ['record' => $this->supplier->id])
        ->assertSeeLivewire(ProductsRelationManager::class);
});

it('lists the products of the supplier the group may see', function () {
    $sharedByOtherGroup = Product::factory()->create(['group_id' => $this->otherGroup->id, 'supplier_id' => $this->supplier->id, 'visibility' => Visibility::Public]);
    $privateOfOtherGroup = Product::factory()->create(['group_id' => $this->otherGroup->id, 'supplier_id' => $this->supplier->id, 'visibility' => Visibility::Private]);
    $ofOtherSupplier = productWithTier($this->group);

    actingInGroup($this->owner, $this->group);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->supplier, 'pageClass' => ViewSupplier::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$this->product, $sharedByOtherGroup])
        ->assertCanNotSeeTableRecords([$privateOfOtherGroup, $ofOtherSupplier])
        ->assertTableColumnExists('priceTiers.label')
        ->assertTableFilterHidden('supplier_id');
});

it('creates products for the supplier from its page', function () {
    $undoRepeaterFake = Repeater::fake();
    actingInGroup($this->owner, $this->group);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->supplier, 'pageClass' => ViewSupplier::class])
        ->mountAction(TestAction::make('create')->table())
        ->assertSchemaStateSet(['supplier_id' => $this->supplier->id])
        ->assertFormFieldDisabled('supplier_id')
        ->setActionData($this->newProductData)
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $product = Product::where('name', 'Hofkäse')->firstOrFail();

    expect($product->supplier_id)->toBe($this->supplier->id)
        ->and($product->group_id)->toBe($this->group->id)
        ->and($product->created_by_user_id)->toBe($this->owner->id)
        ->and($product->visibility)->toBe(Visibility::Public)
        ->and($product->priceTiers()->first()->price_cents)->toBe(4250);

    $undoRepeaterFake();
});

it('only allows public products of a public supplier from its page', function () {
    $undoRepeaterFake = Repeater::fake();
    $private = Supplier::factory()->create(['group_id' => $this->group->id, 'visibility' => Visibility::Private]);
    actingInGroup($this->owner, $this->group);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $private, 'pageClass' => ViewSupplier::class])
        ->callAction(TestAction::make('create')->table(), data: $this->newProductData)
        ->assertHasActionErrors(['visibility']);

    expect(Product::where('name', 'Hofkäse')->exists())->toBeFalse();

    $undoRepeaterFake();
});

it('edits, archives and restores products on the supplier page', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->supplier, 'pageClass' => ViewSupplier::class])
        ->callAction(TestAction::make('edit')->table($this->product), data: ['name' => 'Bio Basmati Reis Vollkorn'])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('delete')->table($this->product))
        ->assertHasNoActionErrors()
        ->assertCanNotSeeTableRecords([$this->product]);

    expect($this->product->fresh())
        ->name->toBe('Bio Basmati Reis Vollkorn')
        ->visibility->toBe(Visibility::Public)
        ->trashed()->toBeTrue();

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->supplier, 'pageClass' => ViewSupplier::class])
        ->filterTable('trashed', true)
        ->callAction(TestAction::make('restore')->table($this->product->fresh()))
        ->assertHasNoActionErrors();

    expect($this->product->fresh()->trashed())->toBeFalse();
});

it('only lets the owning group change the products', function () {
    actingInGroup($this->participant, $this->group);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->supplier, 'pageClass' => ViewSupplier::class])
        ->assertCanSeeTableRecords([$this->product])
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($this->product))
        ->assertActionHidden(TestAction::make('delete')->table($this->product));

    actingInGroup($this->otherOwner, $this->otherGroup);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->supplier, 'pageClass' => ViewSupplier::class])
        ->assertCanSeeTableRecords([$this->product])
        ->assertActionVisible(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($this->product))
        ->assertActionHidden(TestAction::make('delete')->table($this->product));
});
