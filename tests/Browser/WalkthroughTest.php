<?php

use App\Models\PickupDate;
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

    // Tenant-Dashboard — sollte jetzt Stats + aktive Runden + Aufgaben zeigen
    $page = visit('/g/speisekammer-schoeneberg')
        ->assertSee('Speisekammer Schöneberg')
        ->assertSee('Aktive Runden')
        ->assertSee('Aktive Bestellrunden')
        ->assertSee('Was steht für dich an?')
        ->assertSee('Frühjahr-Bestellung 2026')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '03-dashboard', fullPage: true);

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

it('öffnet die aktive Runde, zeigt die Tab-Navigation und Voting-Buttons', function () {
    $this->actingAs($this->marie);

    $activeRoundId = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail()->id;

    $page = visit("/g/speisekammer-schoeneberg/rounds/{$activeRoundId}")
        ->assertSee('Frühjahr-Bestellung 2026')
        ->assertSee('Phase: Bestätigung')
        // Tab-Navigation
        ->assertSee('Übersicht')
        ->assertSee('Warenkörbe')
        ->assertSee('Vorschläge')
        ->assertSee('Zahlungen & Abholungen')
        ->assertSee('Aktivitäten & Benachrichtigungen')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '08-round-detail-overview', fullPage: true);

    // Vorschläge sichtbar nach Klick auf Tab
    $page->click('Vorschläge')
        ->wait(1)
        ->assertSee('Vorschlag A')
        ->assertSee('Vorschlag B')
        ->assertSee('👍')
        ->assertSee('👎')
        ->screenshot(filename: '09-round-detail-proposals', fullPage: true);
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
        ->click('Zahlungen & Abholungen')
        ->wait(1)
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

it('Bestellrunden-Wizard zeigt fünf Schritte inklusive Sortiment-Auswahl', function () {
    $this->actingAs($this->marie);

    $page = visit('/g/speisekammer-schoeneberg/rounds/create')
        ->wait(1)
        ->assertSee('Neue Bestellrunde starten')
        ->assertSee('Worum geht\'s?')
        ->assertSee('Sortiment')
        ->assertSee('Zeitplan')
        ->assertSee('Abholung')
        ->assertSee('Finanzen')
        ->assertSee('Titel der Runde')
        ->assertSee('Lead — wer koordiniert die Runde?')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '13-round-wizard-step1', fullPage: true);
});

it('Produkt-Wizard öffnet sich als Modal mit drei Schritten', function () {
    $this->actingAs($this->marie);

    $page = visit('/g/speisekammer-schoeneberg/products')
        ->press('Produkt anlegen')
        ->wait(1)
        ->assertSee('Stammdaten')
        ->assertSee('Verpackungs-Logik')
        ->assertSee('Preisstaffeln')
        ->assertSee('Hersteller')
        ->assertSee('Produktname')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '14-product-wizard-step1', fullPage: true);
});

it('Eckdaten bearbeiten lädt Lead und Pickup-Termine vor und speichert sie ohne Verlust', function () {
    $this->actingAs($this->marie);
    $activeRoundId = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail()->id;
    $pickupCountBefore = PickupDate::where('round_id', $activeRoundId)->count();
    expect($pickupCountBefore)->toBeGreaterThanOrEqual(3); // aus dem Seeder

    $page = visit("/g/speisekammer-schoeneberg/rounds/{$activeRoundId}")
        ->press('Eckdaten bearbeiten')
        ->wait(1)
        // Lead-Feld muss vorbefüllt sein
        ->assertSee('Marie Kerres')
        // Pickup-Termine müssen im Repeater sichtbar sein (mind. einer)
        ->assertSee('Abholtermine')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '18-edit-round-prefilled', fullPage: true);

    // Speichern → Modal schließt, Werte bleiben in der DB
    $page->press('Speichern')
        ->wait(2);

    $round = Round::with('pickupDates')->find($activeRoundId);
    expect($round->lead_user_id)->toBe($this->marie->id);
    expect($round->pickupDates->count())->toBe($pickupCountBefore);
});

it('Draft-Runde ist nur für den Lead sichtbar und zeigt den "Bestellrunde starten"-Button', function () {
    $marie = $this->marie;
    $tobias = User::where('email', 'tobias@foodpecker.test')->firstOrFail();
    $group = $marie->ownedGroups()->firstOrFail();

    $draft = Round::create([
        'group_id' => $group->id,
        'lead_user_id' => $marie->id,
        'title' => 'Test-Draft Sommer 2026',
        'phase' => 'draft',
        'pickup_location' => 'Hauptstraße 42, 10827 Berlin',
        'lead_fee_percent' => 2.5,
        'platform_fee_percent' => 1.0,
    ]);
    PickupDate::create([
        'round_id' => $draft->id,
        'scheduled_at' => now()->addDays(20),
        'location' => 'Speisekammer',
    ]);

    // Marie sieht den Draft + "Bestellrunde starten"
    $this->actingAs($marie);
    visit("/g/speisekammer-schoeneberg/rounds/{$draft->id}")
        ->assertSee('Test-Draft Sommer 2026')
        ->assertSee('Entwurf')
        ->assertSee('Bestellrunde starten')
        ->assertDontSee('Phase wechseln')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '16-round-draft-as-lead', fullPage: true);

    // Tobias darf den Draft nicht öffnen — sollte 404 oder Redirect bekommen
    $this->actingAs($tobias);
    $tobiasView = visit("/g/speisekammer-schoeneberg/rounds/{$draft->id}");
    $tobiasView->assertDontSee('Test-Draft Sommer 2026')
        ->screenshot(filename: '17-round-draft-other-user');
});

it('Mein-Warenkorb-Seite listet aktive Bestellrunden und öffnet Modal ohne BindingError', function () {
    $this->actingAs($this->marie);

    // Frühjahr-Runde temporär in Shopping-Phase setzen für sinnvolle Demo
    Round::where('title', 'Frühjahr-Bestellung 2026')->update(['phase' => 'shopping']);

    $page = visit('/g/speisekammer-schoeneberg/my-cart')
        ->assertSee('Mein Warenkorb')
        ->assertSee('Frühjahr-Bestellung 2026')
        ->assertSee('Bio Dinkelmehl Type 630')
        ->assertSee('Artikel hinzufügen')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '15-my-cart', fullPage: true);

    // Modal öffnet sauber — Regression-Test für BindingResolutionException beim Schema-Closure
    $page->press('Artikel hinzufügen')
        ->wait(1)
        ->assertSee('Mengenangabe')
        ->assertSee('Produkt')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '19-my-cart-add-modal', fullPage: true);
});

it('smoke-tested die Hauptseiten parallel auf JS-Fehler (schneller Sanity-Check)', function () {
    $this->actingAs($this->marie);

    $base = '/g/speisekammer-schoeneberg';

    $pages = visit([
        $base,
        "{$base}/manufacturers",
        "{$base}/products",
        "{$base}/rounds",
        "{$base}/rounds/create",
        "{$base}/my-cart",
        "{$base}/members",
    ]);

    $pages->assertNoJavaScriptErrors();
});
