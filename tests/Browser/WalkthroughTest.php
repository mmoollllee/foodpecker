<?php

use App\Models\Round;
use App\Models\User;
use Database\Seeders\DemoSeeder;

/**
 * Vollständiger Walk-Through durch das Foodpecker-Panel in einem echten
 * Headless-Chrome.
 *
 * Diese Suite läuft die wichtigsten Seiten als Demo-Owner (Marie) ab,
 * macht von jedem Schritt einen Screenshot und schlägt fehl, sobald eine
 * Seite einen JavaScript-Fehler wirft.
 *
 * Lokal: `vendor/bin/pest tests/Browser`
 * Mit sichtbarem Browser zum Debuggen: `vendor/bin/pest tests/Browser --headed`
 *
 * Screenshots landen unter `tests/Browser/Screenshots/` (gitignored).
 */
beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $this->marie = User::where('email', 'marie@foodpecker.test')->firstOrFail();
});

it('lädt die Login-Seite mit deutschem UI', function () {
    $page = visit('/login');

    $page->assertSee('Foodpecker')
        ->assertSee('Anmelden')
        ->assertSee('E-Mail')
        ->assertSee('Passwort')
        ->assertPresent('input[type=email]')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '01-login');
});

it('führt einen vollständigen Login-Flow durch (echte Form, kein actingAs)', function () {
    $page = visit('/login')
        ->fill('input[type=email]', 'marie@foodpecker.test')
        ->fill('input[type=password]', 'password')
        ->press('Anmelden');

    $page->wait(1)
        ->assertPathContains('/g/speisekammer-schoeneberg')
        ->assertSee('Speisekammer Schöneberg')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '02-dashboard-after-login');
});

it('klickt sich durch alle Resource-Seiten und prüft Konsolen-Fehler', function () {
    $this->actingAs($this->marie);

    // Tenant-Dashboard
    $page = visit('/g/speisekammer-schoeneberg')
        ->assertSee('Speisekammer Schöneberg')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '03-dashboard');

    // Hersteller
    $page->navigate('/g/speisekammer-schoeneberg/manufacturers')
        ->assertSee('Hersteller')
        ->assertSee('Spielberger Mühle')
        ->assertSee('Hofladen Brandenburg')
        ->assertSee('Senfwerk Düsseldorf')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '04-manufacturers');

    // Produkte — sollten alle fünf Verpackungs-Szenarien sichtbar sein
    $page->navigate('/g/speisekammer-schoeneberg/products')
        ->assertSee('Produkte')
        ->assertSee('Bio Dinkelmehl Type 630')
        ->assertSee('Bio Basmati Reis')
        ->assertSee('Düsseldorfer Senf scharf')
        ->assertSee('Spaghetti N. 5 (Bronzeziehung)')
        ->assertSee('Spirelli aus Hartweizen')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '05-products');

    // Bestellrunden Liste
    $page->navigate('/g/speisekammer-schoeneberg/rounds')
        ->assertSee('Bestellrunden')
        ->assertSee('Frühjahr-Bestellung 2026')
        ->assertSee('Aktiv') // Tab-Label
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '06-rounds-list');

    // Mitglieder & Einladungen
    $page->navigate('/g/speisekammer-schoeneberg/members')
        ->assertSee('Mitglieder')
        ->assertSee('Marie Kerres')
        ->assertSee('Tobias Hartmann')
        ->assertSee('sandra@foodpecker.test') // offene Einladung
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '07-members');
});

it('öffnet die aktive Runde als Dashboard und interagiert mit ihr', function () {
    $this->actingAs($this->marie);

    $activeRoundId = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail()->id;

    $page = visit("/g/speisekammer-schoeneberg/rounds/{$activeRoundId}")
        ->assertSee('Frühjahr-Bestellung 2026')
        ->assertSee('Phase: Bestätigung')
        ->assertSee('Vorschlag A')
        ->assertSee('Vorschlag B')
        ->assertSee('Warenkörbe der Teilnehmer')
        ->assertSee('Bestellvorschläge')
        ->assertSee('Bio Dinkelmehl Type 630')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '08-round-detail-overview', fullPage: true);

    // Daumen-hoch / runter Buttons sollten sichtbar sein
    $page->assertSee('👍')
        ->assertSee('👎')
        ->screenshot(filename: '09-round-detail-voting');
});

it('öffnet das "Artikel hinzufügen"-Modal über den Header', function () {
    $this->actingAs($this->marie);

    $activeRoundId = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail()->id;

    // Frühjahr-Bestellung ist in Phase Finalizing, deswegen erst auf eine Runde in
    // Shopping wechseln — wir nehmen die abgeschlossene 2025er nicht (locked),
    // sondern setzen die aktive Runde testweise zurück:
    Round::where('id', $activeRoundId)->update(['phase' => 'shopping']);

    $page = visit("/g/speisekammer-schoeneberg/rounds/{$activeRoundId}")
        ->wait(1)
        ->press('Artikel hinzufügen')
        ->wait(1)
        ->assertSee('Teilnehmer')
        ->assertSee('Produkt')
        ->assertSee('Mengenangabe')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '10-cart-item-modal', fullPage: true);
});

it('rendert die abgeschlossene Runde mit Zahlungen und Abholungen', function () {
    $this->actingAs($this->marie);

    $completedRoundId = Round::where('title', 'Spätsommer-Bestellung 2025')->firstOrFail()->id;

    $page = visit("/g/speisekammer-schoeneberg/rounds/{$completedRoundId}")
        ->assertSee('Spätsommer-Bestellung 2025')
        ->assertSee('Phase: Abgeschlossen')
        ->assertSee('Zahlungen')
        ->assertSee('Abholungen')
        ->assertSee('Bezahlt')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '11-completed-round', fullPage: true);
});

it('lässt sich mit dem Tenant-Switcher zur zweiten Gruppe wechseln', function () {
    $this->actingAs($this->marie);

    $page = visit('/g/hofgemeinschaft-lichtenrade')
        ->assertSee('Hofgemeinschaft Lichtenrade')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '12-second-tenant');
});

it('smoke-tested die Hauptseiten parallel auf JS-Fehler (schneller Sanity-Check)', function () {
    $this->actingAs($this->marie);

    $base = '/g/speisekammer-schoeneberg';

    $pages = visit([
        $base,
        "{$base}/manufacturers",
        "{$base}/products",
        "{$base}/rounds",
        "{$base}/members",
    ]);

    $pages->assertNoJavaScriptErrors();
});
