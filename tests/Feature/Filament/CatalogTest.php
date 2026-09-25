<?php

use App\Enums\GroupRole;
use App\Enums\Visibility;
use App\Filament\Pages\Tenancy\EditGroupProfile;
use App\Filament\Resources\Manufacturers\Pages\ManageManufacturers;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\CartItem;
use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Round;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);

    $this->participant = User::factory()->create();
    $this->group->members()->attach($this->participant->id, ['role' => GroupRole::Participant->value]);

    $this->manufacturer = Manufacturer::factory()->create(['group_id' => $this->group->id, 'visibility' => Visibility::Public]);
    $this->product = productWithTier($this->group);
    $this->product->update(['manufacturer_id' => $this->manufacturer->id, 'visibility' => Visibility::Public]);
});

it('archives ordered products instead of deleting them', function () {
    $round = Round::factory()->for($this->group)->create();
    $cartItem = CartItem::factory()->for($round)->create(['user_id' => $this->owner->id, 'product_id' => $this->product->id]);

    actingInGroup($this->owner, $this->group);

    Livewire::test(ManageProducts::class)
        ->callAction(TestAction::make('delete')->table($this->product))
        ->assertHasNoActionErrors();

    expect($this->product->fresh()->trashed())->toBeTrue()
        ->and($cartItem->fresh()->product->name)->toBe($this->product->name)
        ->and(Product::visibleTo($this->group)->whereKey($this->product->id)->exists())->toBeFalse();

    Livewire::test(ManageProducts::class)
        ->filterTable('trashed', true)
        ->assertActionHidden(TestAction::make('forceDelete')->table($this->product->fresh()))
        ->callAction(TestAction::make('restore')->table($this->product->fresh()));

    expect($this->product->fresh()->trashed())->toBeFalse();
});

it('keeps a shared product with its group when it is made private', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(ManageProducts::class)
        ->callAction(TestAction::make('edit')->table($this->product), data: ['visibility' => Visibility::Private->value])
        ->assertHasNoActionErrors();

    expect($this->product->fresh()->visibility)->toBe(Visibility::Private)
        ->and($this->product->fresh()->group_id)->toBe($this->group->id)
        ->and(Product::visibleTo($this->group)->whereKey($this->product->id)->exists())->toBeTrue();
});

it('only lets the owning group change shared products', function () {
    $otherOwner = User::factory()->create();
    $otherGroup = Group::factory()->create(['owner_id' => $otherOwner->id]);

    actingInGroup($otherOwner, $otherGroup);

    Livewire::test(ManageProducts::class)
        ->assertCanSeeTableRecords([$this->product])
        ->assertActionHidden(TestAction::make('edit')->table($this->product))
        ->assertActionHidden(TestAction::make('delete')->table($this->product));
});

it('keeps participants out of catalog changes and group settings', function () {
    actingInGroup($this->participant, $this->group);

    Livewire::test(ManageProducts::class)
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($this->product));

    Livewire::test(ManageManufacturers::class)
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($this->manufacturer));

    expect(EditGroupProfile::canView($this->group))->toBeFalse();
});

it('only allows public products of public manufacturers', function () {
    $undoRepeaterFake = Repeater::fake();
    $private = Manufacturer::factory()->create(['group_id' => $this->group->id, 'visibility' => Visibility::Private]);
    actingInGroup($this->owner, $this->group);

    $data = [
        'manufacturer_id' => $private->id,
        'name' => 'Hofkäse',
        'unit' => 'kg',
        'visibility' => Visibility::Public->value,
        'packaging_strategy' => 'bulk_weighable',
        'priceTiers' => [['label' => '5 kg Laib', 'package_amount' => 5, 'price_cents' => '42,50', 'min_order_packages' => 1, 'is_divisible' => true, 'divisible_step' => 0.5]],
    ];

    Livewire::test(ManageProducts::class)
        ->callAction('create', data: $data)
        ->assertHasActionErrors(['visibility']);

    Livewire::test(ManageProducts::class)
        ->callAction('create', data: [...$data, 'visibility' => Visibility::Private->value])
        ->assertHasNoActionErrors();

    $product = Product::where('name', 'Hofkäse')->firstOrFail();

    expect($product->group_id)->toBe($this->group->id)
        ->and($product->created_by_user_id)->toBe($this->owner->id)
        ->and($product->priceTiers()->first()->price_cents)->toBe(4250)
        ->and(ProductForm::prepareForSave($data)['visibility'])->toBe(Visibility::Private->value);

    $undoRepeaterFake();
});

it('keeps a manufacturer public while other groups order from it', function () {
    $otherOwner = User::factory()->create();
    $otherGroup = Group::factory()->create(['owner_id' => $otherOwner->id]);
    Product::factory()->create(['group_id' => $otherGroup->id, 'manufacturer_id' => $this->manufacturer->id]);

    actingInGroup($this->owner, $this->group);

    Livewire::test(ManageManufacturers::class)
        ->callAction(TestAction::make('edit')->table($this->manufacturer), data: ['visibility' => Visibility::Private->value])
        ->assertHasActionErrors(['visibility']);

    expect($this->manufacturer->fresh()->visibility)->toBe(Visibility::Public);
});

it('keeps a manufacturer of a dissolved group public while groups order from it', function () {
    $otherOwner = User::factory()->create();
    $otherGroup = Group::factory()->create(['owner_id' => $otherOwner->id]);
    $this->manufacturer->forceFill(['group_id' => null])->saveQuietly();
    Product::factory()->create(['group_id' => $otherGroup->id, 'manufacturer_id' => $this->manufacturer->id, 'visibility' => Visibility::Public]);

    actingInGroup($this->owner, $this->group);

    Livewire::test(ManageManufacturers::class)
        ->callAction(TestAction::make('edit')->table($this->manufacturer), data: ['visibility' => Visibility::Private->value])
        ->assertHasActionErrors(['visibility']);

    expect($this->manufacturer->fresh()->visibility)->toBe(Visibility::Public);
});

it('makes the products of a manufacturer private together with it', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(ManageManufacturers::class)
        ->callAction(TestAction::make('edit')->table($this->manufacturer), data: ['visibility' => Visibility::Private->value])
        ->assertHasNoActionErrors();

    expect($this->product->fresh()->visibility)->toBe(Visibility::Private);
});

it('keeps a curated round curated when all of its products are archived', function () {
    $round = Round::factory()->for($this->group)->create();
    $round->availableProducts()->attach($this->product->id);

    $this->product->delete();

    expect($round->availableProductsForCart()->exists())->toBeFalse();
});

it('deletes the image together with a product deleted for good', function () {
    Storage::fake('public');
    Storage::disk('public')->put('products/reis.svg', '<svg/>');

    $unordered = productWithTier($this->group);
    $unordered->update(['image_path' => 'products/reis.svg']);
    $unordered->delete();

    Storage::disk('public')->assertExists('products/reis.svg');

    actingInGroup($this->owner, $this->group);

    Livewire::test(ManageProducts::class)
        ->filterTable('trashed', false)
        ->callAction(TestAction::make('forceDelete')->table($unordered))
        ->assertHasNoActionErrors();

    expect(Product::withTrashed()->find($unordered->id))->toBeNull();
    Storage::disk('public')->assertMissing('products/reis.svg');
});

it('removes a replaced image, but not one another product still shows', function () {
    Storage::fake('public');
    Storage::disk('public')->put('products/alt.svg', '<svg/>');
    Storage::disk('public')->put('products/geteilt.svg', '<svg/>');

    $this->product->update(['image_path' => 'products/alt.svg']);
    $this->product->update(['image_path' => 'products/geteilt.svg']);

    Storage::disk('public')->assertMissing('products/alt.svg');

    $twin = productWithTier($this->group);
    $twin->update(['image_path' => 'products/geteilt.svg']);
    $twin->forceDelete();

    Storage::disk('public')->assertExists('products/geteilt.svg');
});
