<?php

use App\Enums\GroupRole;
use App\Enums\Visibility;
use App\Filament\Resources\Manufacturers\Pages\ViewManufacturer;
use App\Filament\Resources\Manufacturers\RelationManagers\ProductsRelationManager;
use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\Product;
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

    $this->manufacturer = Manufacturer::factory()->create(['group_id' => $this->group->id, 'visibility' => Visibility::Public]);
    $this->product = productWithTier($this->group);
    $this->product->update(['manufacturer_id' => $this->manufacturer->id, 'name' => 'Bio Basmati Reis', 'visibility' => Visibility::Public]);

    $this->newProductData = [
        'name' => 'Hofkäse',
        'unit' => 'kg',
        'visibility' => Visibility::Public->value,
        'packaging_strategy' => 'bulk_weighable',
        'priceTiers' => [['label' => '5 kg Laib', 'package_amount' => 5, 'price_cents' => '42,50', 'min_order_packages' => 1, 'is_divisible' => true, 'divisible_step' => 0.5]],
    ];
});

it('shows the products on the manufacturer page', function () {
    actingInGroup($this->participant, $this->group);

    Livewire::test(ViewManufacturer::class, ['record' => $this->manufacturer->id])
        ->assertSeeLivewire(ProductsRelationManager::class);
});

it('lists the products of the manufacturer the group may see', function () {
    $sharedByOtherGroup = Product::factory()->create(['group_id' => $this->otherGroup->id, 'manufacturer_id' => $this->manufacturer->id, 'visibility' => Visibility::Public]);
    $privateOfOtherGroup = Product::factory()->create(['group_id' => $this->otherGroup->id, 'manufacturer_id' => $this->manufacturer->id, 'visibility' => Visibility::Private]);
    $ofOtherManufacturer = productWithTier($this->group);

    actingInGroup($this->owner, $this->group);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->manufacturer, 'pageClass' => ViewManufacturer::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$this->product, $sharedByOtherGroup])
        ->assertCanNotSeeTableRecords([$privateOfOtherGroup, $ofOtherManufacturer])
        ->assertTableColumnExists('priceTiers.label')
        ->assertTableFilterHidden('manufacturer_id');
});

it('creates products for the manufacturer from its page', function () {
    $undoRepeaterFake = Repeater::fake();
    actingInGroup($this->owner, $this->group);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->manufacturer, 'pageClass' => ViewManufacturer::class])
        ->mountAction(TestAction::make('create')->table())
        ->assertSchemaStateSet(['manufacturer_id' => $this->manufacturer->id])
        ->assertFormFieldDisabled('manufacturer_id')
        ->setActionData($this->newProductData)
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $product = Product::where('name', 'Hofkäse')->firstOrFail();

    expect($product->manufacturer_id)->toBe($this->manufacturer->id)
        ->and($product->group_id)->toBe($this->group->id)
        ->and($product->created_by_user_id)->toBe($this->owner->id)
        ->and($product->visibility)->toBe(Visibility::Public)
        ->and($product->priceTiers()->first()->price_cents)->toBe(4250);

    $undoRepeaterFake();
});

it('only allows public products of a public manufacturer from its page', function () {
    $undoRepeaterFake = Repeater::fake();
    $private = Manufacturer::factory()->create(['group_id' => $this->group->id, 'visibility' => Visibility::Private]);
    actingInGroup($this->owner, $this->group);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $private, 'pageClass' => ViewManufacturer::class])
        ->callAction(TestAction::make('create')->table(), data: $this->newProductData)
        ->assertHasActionErrors(['visibility']);

    expect(Product::where('name', 'Hofkäse')->exists())->toBeFalse();

    $undoRepeaterFake();
});

it('edits, archives and restores products on the manufacturer page', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->manufacturer, 'pageClass' => ViewManufacturer::class])
        ->callAction(TestAction::make('edit')->table($this->product), data: ['name' => 'Bio Basmati Reis Vollkorn'])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('delete')->table($this->product))
        ->assertHasNoActionErrors()
        ->assertCanNotSeeTableRecords([$this->product]);

    expect($this->product->fresh())
        ->name->toBe('Bio Basmati Reis Vollkorn')
        ->visibility->toBe(Visibility::Public)
        ->trashed()->toBeTrue();

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->manufacturer, 'pageClass' => ViewManufacturer::class])
        ->filterTable('trashed', true)
        ->callAction(TestAction::make('restore')->table($this->product->fresh()))
        ->assertHasNoActionErrors();

    expect($this->product->fresh()->trashed())->toBeFalse();
});

it('only lets the owning group change the products', function () {
    actingInGroup($this->participant, $this->group);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->manufacturer, 'pageClass' => ViewManufacturer::class])
        ->assertCanSeeTableRecords([$this->product])
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($this->product))
        ->assertActionHidden(TestAction::make('delete')->table($this->product));

    actingInGroup($this->otherOwner, $this->otherGroup);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->manufacturer, 'pageClass' => ViewManufacturer::class])
        ->assertCanSeeTableRecords([$this->product])
        ->assertActionVisible(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($this->product))
        ->assertActionHidden(TestAction::make('delete')->table($this->product));
});
