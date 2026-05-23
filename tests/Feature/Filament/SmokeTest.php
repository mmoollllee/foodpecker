<?php

use App\Filament\Pages\Members;
use App\Filament\Resources\Manufacturers\Pages\ManageManufacturers;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Rounds\Pages\ListRounds;
use App\Filament\Resources\Rounds\Pages\ViewRound;
use App\Models\Round;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $marie = User::where('email', 'marie@foodpecker.test')->first();
    $this->actingAs($marie);
    Filament::setTenant($marie->currentGroup);
    Filament::setCurrentPanel('global');
    Filament::bootCurrentPanel();
});

it('Bestellrunden-Liste lädt ohne Fehler', function () {
    Livewire::test(ListRounds::class)->assertOk();
});

it('aktive Runde lässt sich als Dashboard öffnen', function () {
    $activeRoundId = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail()->id;
    Livewire::test(ViewRound::class, ['record' => $activeRoundId])
        ->assertOk()
        ->assertSee('Frühjahr-Bestellung 2026')
        ->assertSee('Vorschlag A')
        ->assertSee('Vorschlag B');
});

it('Hersteller-Seite zeigt öffentliche und private', function () {
    Livewire::test(ManageManufacturers::class)
        ->assertOk()
        ->assertSee('Spielberger Mühle')
        ->assertSee('Hofladen Brandenburg');
});

it('Produkte-Seite zeigt alle 5 Verpackungs-Szenarien', function () {
    Livewire::test(ManageProducts::class)
        ->assertOk()
        ->assertSee('Bio Dinkelmehl Type 630')
        ->assertSee('Bio Basmati Reis')
        ->assertSee('Düsseldorfer Senf scharf')
        ->assertSee('Spaghetti N. 5 (Bronzeziehung)')
        ->assertSee('Spirelli aus Hartweizen');
});

it('Mitglieder-Seite listet Members und Einladungen', function () {
    Livewire::test(Members::class)
        ->assertOk()
        ->assertSee('Marie Kerres')
        ->assertSee('Tobias Hartmann')
        ->assertSee('sandra@foodpecker.test');
});
