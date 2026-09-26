<?php

use App\Enums\GroupRole;
use App\Enums\RoundPhase;
use App\Enums\Visibility;
use App\Filament\Pages\Tenancy\EditGroupProfile;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Mail\GroupDissolvedMail;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\CartItem;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Note;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\Round;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Groups\GroupDissolution;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Mail::fake();

    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->owner, 'members' => $this->members] = roundScenario(2);

    $this->otherOwner = User::factory()->create();
    $this->otherGroup = Group::factory()->create(['owner_id' => $this->otherOwner->id]);
    $this->otherRound = Round::factory()->for($this->otherGroup)->inPhase(RoundPhase::Shopping)->create(['lead_user_id' => $this->otherOwner->id]);
});

/**
 * A document with a real file on the fake disk.
 */
function attachDocument(Round|Product|Supplier $record, Group $group, User $user): Attachment
{
    $path = 'attachments/'.$group->id.'/'.fake()->uuid().'.pdf';
    Storage::disk('local')->put($path, 'PDF');

    return $record->attachments()->create([
        'uploaded_by_user_id' => $user->id,
        'group_id' => $group->id,
        'disk' => 'local',
        'path' => $path,
        'original_name' => 'rechnung.pdf',
        'size_bytes' => 3,
    ]);
}

it('deletes rounds, memberships, invitations and the documents of the group', function () {
    $product = productWithTier($this->group);
    CartItem::factory()->for($this->round)->for($this->members[0])->for($product)->create();
    $invitation = GroupInvitation::factory()->for($this->group)->create();
    $this->round->notes()->create(['user_id' => $this->owner->id, 'group_id' => $this->group->id, 'body' => 'Abholung bei Anna.']);
    $document = attachDocument($this->round, $this->group, $this->owner);

    app(GroupDissolution::class)->dissolve($this->group, $this->owner);

    expect(Group::find($this->group->id))->toBeNull()
        ->and(Round::withoutGlobalScopes()->find($this->round->id))->toBeNull()
        ->and(CartItem::count())->toBe(0)
        ->and(GroupInvitation::find($invitation->id))->toBeNull()
        ->and(Note::count())->toBe(0)
        ->and(Attachment::count())->toBe(0)
        ->and(User::find($this->members[0]->id))->not->toBeNull()
        ->and($this->members[0]->groups()->count())->toBe(0);

    Storage::disk('local')->assertMissing($document->path);
});

it('deletes private catalog entries nobody else uses, including their files', function () {
    $product = productWithTier($this->group);
    $product->update(['image_path' => 'products/reis.svg']);
    Storage::disk('public')->put('products/reis.svg', '<svg/>');
    $document = attachDocument($product, $this->group, $this->owner);
    PriceObservation::create(['product_id' => $product->id, 'group_id' => $this->group->id, 'round_id' => $this->round->id, 'observed_price_cents' => 3000, 'package_amount' => 10, 'observed_on' => now()]);

    app(GroupDissolution::class)->dissolve($this->group, $this->owner);

    expect(Product::withTrashed()->find($product->id))->toBeNull()
        ->and(Supplier::find($product->supplier_id))->toBeNull()
        ->and(PriceObservation::count())->toBe(0)
        ->and(Activity::where('subject_type', Product::class)->where('subject_id', $product->id)->exists())->toBeFalse();

    Storage::disk('public')->assertMissing('products/reis.svg');
    Storage::disk('local')->assertMissing($document->path);
});

it('hands shared catalog entries over to the community', function () {
    $product = productWithTier($this->group);
    $product->supplier->update(['visibility' => Visibility::Public]);
    $product->update(['visibility' => Visibility::Public]);
    PriceObservation::create(['product_id' => $product->id, 'group_id' => $this->group->id, 'observed_price_cents' => 3000, 'package_amount' => 10, 'observed_on' => now()]);

    app(GroupDissolution::class)->dissolve($this->group, $this->owner);

    $product = Product::find($product->id);

    expect($product)->not->toBeNull()
        ->and($product->group_id)->toBeNull()
        ->and($product->priceTiers)->toHaveCount(1)
        ->and($product->priceObservations()->count())->toBe(1)
        ->and($product->supplier->group_id)->toBeNull()
        ->and($product->activities()->where('action', 'ownership_released')->first()?->describeAction())
        ->toContain('gehört jetzt allen Gruppen');

    // Moderators of any group may maintain it now.
    actingInGroup($this->otherOwner, $this->otherGroup);
    expect($this->otherOwner->can('update', $product))->toBeTrue();
});

it('lets the first group that maintains a community entry take it over', function () {
    $product = productWithTier($this->group);
    $product->supplier->update(['visibility' => Visibility::Public]);
    $product->update(['visibility' => Visibility::Public]);

    app(GroupDissolution::class)->dissolve($this->group, $this->owner);

    actingInGroup($this->otherOwner, $this->otherGroup);

    Livewire::test(ManageProducts::class)
        ->callAction(TestAction::make('edit')->table($product), data: ['name' => 'Bio-Reis, neu gepflegt'])
        ->assertHasNoActionErrors();

    expect($product->fresh())
        ->name->toBe('Bio-Reis, neu gepflegt')
        ->group_id->toBe($this->otherGroup->id)
        ->visibility->toBe(Visibility::Public);
});

it('keeps private products another group already ordered, archived', function () {
    $product = productWithTier($this->group);
    $cartItem = CartItem::factory()->for($this->otherRound)->for($this->otherOwner)->for($product)->create();

    app(GroupDissolution::class)->dissolve($this->group, $this->owner);

    $product = Product::withTrashed()->find($product->id);

    expect($product)->not->toBeNull()
        ->and($product->trashed())->toBeTrue()
        ->and($product->group_id)->toBeNull()
        ->and($product->supplier)->not->toBeNull()
        ->and($cartItem->fresh()->product->is($product))->toBeTrue();
});

it('keeps a private supplier while another group still has products of it', function () {
    $supplier = Supplier::factory()->create(['group_id' => $this->group->id]);
    $foreignProduct = Product::factory()->create(['group_id' => $this->otherGroup->id, 'supplier_id' => $supplier->id]);

    app(GroupDissolution::class)->dissolve($this->group, $this->owner);

    expect(Supplier::find($supplier->id)?->group_id)->toBeNull()
        ->and(Product::find($foreignProduct->id))->not->toBeNull();
});

it('keeps notes on shared entries without names and drops the private ones', function () {
    $shared = productWithTier($this->otherGroup);
    $shared->update(['visibility' => Visibility::Public]);
    $shared->notes()->create(['user_id' => $this->members[0]->id, 'group_id' => $this->group->id, 'body' => 'Schmeckt super im Risotto.']);

    $private = productWithTier($this->group);
    CartItem::factory()->for($this->otherRound)->for($this->otherOwner)->for($private)->create();
    $private->notes()->create(['user_id' => $this->owner->id, 'group_id' => $this->group->id, 'body' => 'Nur für uns.']);

    app(GroupDissolution::class)->dissolve($this->group, $this->owner);

    expect(Note::pluck('body')->all())->toBe(['Schmeckt super im Risotto.']);

    actingInGroup($this->otherOwner, $this->otherGroup);

    Livewire::test(ViewProduct::class, ['record' => $shared->id])
        ->assertSee('Schmeckt super im Risotto.')
        ->assertSee('Mitglied einer anderen Gruppe')
        ->assertDontSee($this->members[0]->fullName());
});

it('refuses while money or goods are on their way', function (RoundPhase $phase) {
    $this->round->update(['phase' => $phase]);

    expect(fn () => app(GroupDissolution::class)->dissolve($this->group, $this->owner))
        ->toThrow(ValidationException::class, 'Geld oder Ware unterwegs');

    expect(Group::find($this->group->id))->not->toBeNull();
})->with(GroupDissolution::BLOCKING_PHASES);

it('allows dissolving with finished or cancelled rounds', function () {
    $this->round->update(['phase' => RoundPhase::Completed]);
    Round::factory()->for($this->group)->inPhase(RoundPhase::Cancelled)->create(['lead_user_id' => $this->owner->id]);

    app(GroupDissolution::class)->dissolve($this->group, $this->owner);

    expect(Group::find($this->group->id))->toBeNull()
        ->and(Round::withoutGlobalScopes()->where('group_id', $this->group->id)->count())->toBe(0);
});

it('lets only the owner dissolve the group', function () {
    $moderator = $this->members[0];
    $this->group->members()->updateExistingPivot($moderator->id, ['role' => GroupRole::Moderator->value]);

    expect(fn () => app(GroupDissolution::class)->dissolve($this->group, $moderator))
        ->toThrow(ValidationException::class, 'Nur der Owner');
});

it('informs the former members by mail', function () {
    $result = app(GroupDissolution::class)->dissolve($this->group, $this->owner);

    expect($result)->toBe(['notified' => 2, 'failed' => 0]);

    Mail::assertSent(GroupDissolvedMail::class, 2);
    Mail::assertSent(GroupDissolvedMail::class, fn (GroupDissolvedMail $mail): bool => $mail->hasTo($this->members[0]->email)
        && $mail->groupName === $this->group->name);
    Mail::assertNotSent(GroupDissolvedMail::class, fn (GroupDissolvedMail $mail): bool => $mail->hasTo($this->owner->email));
});

it('dissolves silently when asked to', function () {
    app(GroupDissolution::class)->dissolve($this->group, $this->owner, notifyMembers: false);

    Mail::assertNothingSent();
});

it('lets the owner dissolve the group from the settings after typing its name', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(EditGroupProfile::class)
        ->mountAction('dissolveGroup')
        ->assertMountedActionModalSee(['Das wird gelöscht', '1 Bestellrunde', '3 Mitgliedschaften', 'übergib stattdessen die Owner-Rolle', 'Das lässt sich nicht rückgängig machen.'])
        ->assertMountedActionModalDontSee('0 Produkte')
        ->setActionData(['confirmation' => 'Falscher Name'])
        ->callMountedAction()
        ->assertHasActionErrors(['confirmation' => 'in']);

    expect(Group::find($this->group->id))->not->toBeNull();

    Livewire::test(EditGroupProfile::class)
        ->callAction('dissolveGroup', data: ['confirmation' => $this->group->name, 'notify_members' => true])
        ->assertHasNoActionErrors()
        ->assertNotified('Die Gruppe „'.$this->group->name.'“ wurde aufgelöst.')
        ->assertRedirect(route('filament.global.tenant'));

    expect(Group::find($this->group->id))->toBeNull();
    Mail::assertSent(GroupDissolvedMail::class, 2);
});

it('explains why the group can not be dissolved yet', function () {
    $this->round->update(['phase' => RoundPhase::Payment]);
    actingInGroup($this->owner, $this->group);

    Livewire::test(EditGroupProfile::class)
        ->mountAction('dissolveGroup')
        ->assertMountedActionModalSee(['Gerade geht das noch nicht.', $this->round->title])
        ->assertMountedActionModalDontSee('Endgültig auflösen')
        ->callMountedAction()
        ->assertNotified('Die Gruppe kann noch nicht aufgelöst werden.');

    expect(Group::find($this->group->id))->not->toBeNull();
});

it('hides the action from moderators', function () {
    $moderator = $this->members[0];
    $this->group->members()->updateExistingPivot($moderator->id, ['role' => GroupRole::Moderator->value]);
    actingInGroup($moderator, $this->group);

    Livewire::test(EditGroupProfile::class)
        ->assertActionHidden('dissolveGroup');
});

it('sends the owner to another of their groups afterwards', function () {
    $this->otherGroup->members()->attach($this->owner->id, ['role' => GroupRole::Participant->value]);

    app(GroupDissolution::class)->dissolve($this->group, $this->owner);
    Filament::setTenant(null);

    $this->actingAs($this->owner->fresh())
        ->get(route('filament.global.tenant'))
        ->assertRedirect(Filament::getPanel('global')->getUrl($this->otherGroup));
});

it('previews which catalog entries go away and which stay', function () {
    $shared = productWithTier($this->group);
    $shared->supplier->update(['visibility' => Visibility::Public]);
    $shared->update(['visibility' => Visibility::Public]);
    productWithTier($this->group);

    expect(app(GroupDissolution::class)->preview($this->group))->toBe([
        'rounds' => 1,
        'members' => 3,
        'deleted_products' => 1,
        'kept_products' => 1,
        'deleted_suppliers' => 1,
        'kept_suppliers' => 1,
    ]);

    actingInGroup($this->owner, $this->group);

    Livewire::test(EditGroupProfile::class)
        ->mountAction('dissolveGroup')
        ->assertMountedActionModalSee([
            '1 Produkt und 1 Lieferant, die nur eure Gruppe nutzt',
            '1 Produkt und 1 Lieferant, die ihr geteilt habt',
        ]);
});
