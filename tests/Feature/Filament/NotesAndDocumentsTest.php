<?php

use App\Enums\GroupRole;
use App\Enums\Visibility;
use App\Filament\Resources\Manufacturers\Pages\ViewManufacturer;
use App\Filament\Resources\Manufacturers\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Models\Attachment;
use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\Note;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->owner = User::factory()->create();
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);
    $this->participant = User::factory()->create(['first_name' => 'Pia', 'last_name' => 'Teil']);
    $this->group->members()->attach($this->participant->id, ['role' => GroupRole::Participant->value]);

    $this->manufacturer = Manufacturer::factory()->create(['group_id' => $this->group->id, 'visibility' => Visibility::Public, 'name' => 'Mühle']);
    $this->product = productWithTier($this->group);
    $this->product->update(['manufacturer_id' => $this->manufacturer->id, 'visibility' => Visibility::Public]);

    $this->otherOwner = User::factory()->create();
    $this->otherGroup = Group::factory()->create(['owner_id' => $this->otherOwner->id]);
});

it('lets every member write notes about a manufacturer', function () {
    actingInGroup($this->participant, $this->group);

    Livewire::test(ViewManufacturer::class, ['record' => $this->manufacturer->id])
        ->assertSeeLivewire(ProductsRelationManager::class)
        ->callAction('addNote', data: ['body' => 'Sehr freundlicher Kontakt, liefert schnell.'])
        ->assertHasNoActionErrors()
        ->assertSee('Sehr freundlicher Kontakt, liefert schnell.');

    expect(Note::where('notable_id', $this->manufacturer->id)->value('group_id'))->toBe($this->group->id)
        ->and($this->manufacturer->activities()->where('action', 'note_added')->exists())->toBeTrue();
});

it('shares notes on public manufacturers with other groups without names', function () {
    $this->manufacturer->notes()->create(['user_id' => $this->participant->id, 'group_id' => $this->group->id, 'body' => 'Im Winter früher bestellen.']);

    actingInGroup($this->otherOwner, $this->otherGroup);

    Livewire::test(ViewManufacturer::class, ['record' => $this->manufacturer->id])
        ->assertSee('Im Winter früher bestellen.')
        ->assertSee('Mitglied einer anderen Gruppe')
        ->assertDontSee('Pia Teil')
        ->assertActionHidden(TestAction::make('deleteNote')->arguments(['note' => $this->manufacturer->notes()->first()->id]));
});

it('keeps notes on private manufacturers inside the group', function () {
    $private = Manufacturer::factory()->create(['group_id' => $this->group->id, 'visibility' => Visibility::Private]);
    $private->notes()->create(['user_id' => $this->participant->id, 'group_id' => $this->group->id, 'body' => 'Nur für uns.']);

    actingInGroup($this->otherOwner, $this->otherGroup);

    Livewire::test(ViewManufacturer::class, ['record' => $private->id]);
})->throws(ModelNotFoundException::class);

it('stores uploaded documents privately and hands them out only to allowed people', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(ViewProduct::class, ['record' => $this->product->id])
        ->callAction('addAttachment', data: [
            'files' => [UploadedFile::fake()->create('preisliste.pdf', 120, 'application/pdf')],
        ])
        ->assertHasNoActionErrors()
        ->assertSee('preisliste.pdf');

    $attachment = Attachment::firstOrFail();

    Storage::disk('local')->assertExists($attachment->path);
    expect($attachment->original_name)->toBe('preisliste.pdf')
        ->and($attachment->group_id)->toBe($this->group->id);

    $this->actingAs($this->participant)->get($attachment->url())->assertDownload('preisliste.pdf');

    // Shared product: other groups may read the document as well.
    $this->actingAs($this->otherOwner)->get($attachment->url())->assertDownload('preisliste.pdf');

    $this->product->update(['visibility' => Visibility::Private]);

    $this->actingAs($this->otherOwner)->get($attachment->url())->assertForbidden();

    auth()->logout();
    $this->get($attachment->url())->assertRedirect(Filament\Facades\Filament::getPanel('global')->getLoginUrl());
});

it('deletes the file together with the document', function () {
    actingInGroup($this->owner, $this->group);

    Livewire::test(ViewProduct::class, ['record' => $this->product->id])
        ->callAction('addAttachment', data: ['files' => [UploadedFile::fake()->create('rechnung.pdf', 50, 'application/pdf')]]);

    $attachment = Attachment::firstOrFail();

    Livewire::test(ViewProduct::class, ['record' => $this->product->id])
        ->callAction(TestAction::make('deleteAttachment')->arguments(['attachment' => $attachment->id]));

    expect(Attachment::count())->toBe(0);
    Storage::disk('local')->assertMissing($attachment->path);
});

it('keeps a history of changes to products', function () {
    actingInGroup($this->owner, $this->group);

    $this->product->update(['description' => 'Neu beschrieben']);
    $this->product->priceTiers()->first()->update(['price_cents' => 3300]);
    $this->product->delete();

    $actions = $this->product->activities()->get()->map->describeAction();

    expect($actions)->toContain('Geändert: Beschreibung')
        ->and($actions)->toContain('Preisstaffel „10 kg“ geändert (33,00 €)')
        ->and($actions)->toContain('archiviert');

    Livewire::test(ViewProduct::class, ['record' => $this->product->id])
        ->assertSee('Preisstaffel „10 kg“ geändert (33,00 €)');
});

it('attributes activities on shared entries to the group of the person acting', function () {
    $this->otherOwner->update(['first_name' => 'Olga', 'last_name' => 'Fremd']);
    actingInGroup($this->otherOwner, $this->otherGroup);

    Livewire::test(ViewProduct::class, ['record' => $this->product->id])
        ->callAction('addNote', data: ['body' => 'Gute Qualität, kaum Bruch.'])
        ->assertHasNoActionErrors();

    expect($this->product->activities()->where('action', 'note_added')->value('group_id'))->toBe($this->otherGroup->id);

    actingInGroup($this->owner, $this->group);

    Livewire::test(ViewProduct::class, ['record' => $this->product->id])
        ->assertSee('Gute Qualität, kaum Bruch.')
        ->assertSee('Mitglied einer anderen Gruppe')
        ->assertDontSee('Olga Fremd');
});
