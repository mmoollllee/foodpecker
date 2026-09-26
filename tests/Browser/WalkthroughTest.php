<?php

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PickupDate;
use App\Models\Product;
use App\Models\Round;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Groups\OwnerTransfer;
use App\Services\Proposals\ProposalBuilder;
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

    // Tenant dashboard — personal tasks first, then the one running round, then the group figures
    $page = visit('/g/speisekammer-schoeneberg')
        ->assertSee('Speisekammer Schöneberg')
        ->assertSee('Was steht für dich an?')
        ->assertSee('Aktuelle Bestellrunde')
        ->assertSee('Frühjahr-Bestellung 2026')
        ->assertSee('Abgeschlossene Runden')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '03-dashboard', fullPage: true);

    // Lieferanten
    $page->navigate('/g/speisekammer-schoeneberg/suppliers')
        ->assertSee('Lieferanten')
        ->assertSee('Spielberger Mühle')
        ->assertSee('Hofladen Brandenburg')
        ->assertSee('Senfwerk Düsseldorf')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '04-suppliers');

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
        ->assertSee('Aktuell')
        ->assertSee('Historie')
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

it('opens the running round with its phases, proposals and overview', function () {
    $this->actingAs($this->marie);

    $activeRoundId = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail()->id;

    $page = visit("/g/speisekammer-schoeneberg/rounds/{$activeRoundId}")
        ->assertSee('Frühjahr-Bestellung 2026')
        ->assertSee('Ablauf')
        ->assertSee('läuft gerade')
        ->assertSee('Vorschlag A')
        ->assertSee('Vorschlag B')
        ->assertSee('👍')
        ->assertSee('👎')
        ->assertSee('Verlauf & Benachrichtigungen')
        ->assertSee('Übersicht')
        ->assertSee('Teilnehmer')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '08-round-detail-overview', fullPage: true);

    // Clicking a phase shows what matters in it
    $page->click('button[aria-pressed]:has-text("Zahlung")')
        ->wait(1)
        ->assertSee('Alle überweisen ihren Anteil an den Lead')
        ->assertSee('kommt noch')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '08b-round-phase-payment', fullPage: true);

    // Back to the proposals, the reason of a thumbs down shows on hover
    $page->click('button[aria-pressed]:has-text("Bestätigung")')
        ->wait(1)
        ->hover('span[x-tooltip]:has-text("👎")')
        ->wait(1)
        ->assertScript("document.querySelector('[data-tippy-root]')?.innerText.includes('Ein halber 50-kg-Sack ist mir zu viel') ?? false", true)
        ->assertNoJavaScriptErrors()
        ->screenshot(fullPage: false, filename: '09-round-detail-proposals');
});

it('adds a product nobody ordered yet from below the carts table', function () {
    $this->actingAs($this->marie);

    $activeRoundId = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail()->id;

    // Frühjahr-Bestellung ist in Phase Finalizing, deswegen erst auf eine Runde in
    // Shopping wechseln — wir nehmen die abgeschlossene 2025er nicht (locked),
    // sondern setzen die aktive Runde testweise zurück:
    Round::where('id', $activeRoundId)->update(['phase' => 'shopping']);

    $page = visit("/g/speisekammer-schoeneberg/rounds/{$activeRoundId}")
        ->wait(1)
        ->press('Produkt hinzufügen')
        ->wait(1)
        ->assertSee('Für wen?')
        ->assertSee('Produkt')
        ->assertSee('Mengenangabe')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '10-cart-item-modal', fullPage: true);
});

it('changes a quantity with a click into the carts table', function () {
    $this->actingAs($this->marie);

    $round = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail();
    $round->update(['phase' => 'shopping']);

    visit("/g/speisekammer-schoeneberg/rounds/{$round->id}")
        ->assertSee('Klick auf eine Menge, um sie zu ändern')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '10b-carts-in-shopping-phase', fullPage: true)
        ->click('button[aria-label="Menge von Tobias ändern"] >> nth=0')
        ->wait(1)
        ->assertSee('Warenkorb von Tobias Hartmann')
        ->assertSee('Gebindegrößen & Preise')
        ->assertSee('Mengenangabe')
        ->assertSee('Entfernen')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '10c-edit-quantity-modal');
});

it('rendert die abgeschlossene Runde mit Zahlungen und Abholungen', function () {
    $this->actingAs($this->marie);

    $completedRoundId = Round::where('title', 'Spätsommer-Bestellung 2025')->firstOrFail()->id;

    $page = visit("/g/speisekammer-schoeneberg/rounds/{$completedRoundId}")
        ->assertSee('Spätsommer-Bestellung 2025')
        ->assertSee('Die Runde ist abgeschlossen')
        ->click('button[aria-pressed]:has-text("Zahlung")')
        ->wait(1)
        ->assertSee('Bezahlt')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '11-completed-round', fullPage: true);
});

it('shows somebody who still has to pay the lead\'s account with a GiroCode', function () {
    $aylin = User::where('email', 'aylin@foodpecker.test')->firstOrFail();
    $round = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail();
    $proposal = $round->proposals()->where('title', 'like', 'Vorschlag A%')->firstOrFail();
    $proposal->update(['status' => ProposalStatus::Chosen]);
    $round->update(['chosen_proposal_id' => $proposal->id, 'phase' => RoundPhase::Payment]);
    Payment::create(['round_id' => $round->id, 'user_id' => $aylin->id, 'amount_cents' => 4250]);
    $this->actingAs($aylin);

    visit("/g/speisekammer-schoeneberg/rounds/{$round->id}")
        ->assertSee('DE33 1002 0500 0001 2345 67')
        ->assertSee('Frühjahr-Bestellung 2026 – Aylin Yıldız')
        ->assertVisible('img[alt^="GiroCode"]')
        ->click('dd:has-text("DE33") button')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '11b-payment-with-girocode', fullPage: true);
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
        ->assertSee('Eckdaten')
        ->assertSee('Sortiment')
        ->assertSee('Zeitplan')
        ->assertSee('Abholung')
        ->assertSee('Finanzen')
        ->assertSee('Titel der Runde')
        ->assertSee('Aufwandsentschädigung für den Lead')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '13-round-wizard-step1', fullPage: true);
});

it('Produkt-Wizard öffnet sich als Modal mit vier Schritten', function () {
    $this->actingAs($this->marie);

    $page = visit('/g/speisekammer-schoeneberg/products')
        ->press('Produkt anlegen')
        ->wait(1)
        ->assertSee('Stammdaten')
        ->assertSee('Verteilung')
        ->assertSee('Gebinde & Preise')
        ->assertSee('Lieferant')
        ->assertSee('Produktname')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '14-product-wizard-step1', fullPage: true);
});

it('Eckdaten bearbeiten lädt die Pickup-Termine vor und speichert sie ohne Verlust', function () {
    $this->actingAs($this->marie);
    $activeRoundId = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail()->id;
    $pickupCountBefore = PickupDate::where('round_id', $activeRoundId)->count();
    expect($pickupCountBefore)->toBeGreaterThanOrEqual(3); // aus dem Seeder

    $page = visit("/g/speisekammer-schoeneberg/rounds/{$activeRoundId}")
        ->press('Eckdaten bearbeiten')
        ->wait(1)
        ->assertSee('Lead-Rolle übergeben')
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

it('lets the lead type a deadline into the date field', function () {
    $this->actingAs($this->marie);
    $round = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail();

    visit("/g/speisekammer-schoeneberg/rounds/{$round->id}")
        ->press('Eckdaten bearbeiten')
        ->wait(1)
        ->fill('input[id$="shopping_deadline"]', '12.5.26')
        ->keys('input[id$="shopping_deadline"]', 'Tab')
        ->assertValue('input[id$="shopping_deadline"]', '12.05.2026')
        ->press('Speichern')
        ->wait(2)
        ->assertNoJavaScriptErrors();

    expect($round->fresh()->shopping_deadline->toDateString())->toBe('2026-05-12');
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

it('shows the cart of the running round and opens the add modal without a BindingError', function () {
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
        ->screenshot(filename: '19-my-cart-add-modal', fullPage: true)
        ->click('Flexibel')
        ->wait(1)
        ->assertSee('Mindestens')
        ->assertSee('Höchstens')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '19b-my-cart-add-modal-flexible');
});

it('Produkt-Ansehen-Modal zeigt die Product-Card mit Preisstaffeln', function () {
    $this->actingAs($this->marie);

    $page = visit('/g/speisekammer-schoeneberg/products')
        ->press('Ansehen')
        ->wait(1)
        ->assertSee('Gebindegrößen & Preise')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '20-product-card-modal', fullPage: true);
});

it('smoke-tested die Hauptseiten parallel auf JS-Fehler (schneller Sanity-Check)', function () {
    $this->actingAs($this->marie);

    $base = '/g/speisekammer-schoeneberg';

    $pages = visit([
        $base,
        "{$base}/suppliers",
        "{$base}/products",
        "{$base}/rounds",
        "{$base}/rounds/create",
        "{$base}/my-cart",
        "{$base}/my-orders",
        "{$base}/members",
    ]);

    $pages->assertNoJavaScriptErrors();
});

it('shows a round to a participant without any lead actions', function () {
    $jonas = User::where('email', 'jonas@foodpecker.test')->firstOrFail();
    $activeRoundId = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail()->id;

    $this->actingAs($jonas);

    visit("/g/speisekammer-schoeneberg/rounds/{$activeRoundId}?phase=finalizing")
        ->assertSee('Vorschlag A')
        ->assertSee('👎')
        ->assertDontSee('Weiter zu:')
        ->assertDontSee('Eckdaten bearbeiten')
        ->assertDontSee('Zur Abstimmung freigeben')
        ->assertDontSee('Zurückziehen')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '21-round-as-participant', fullPage: true);
});

it('lets the lead exclude a blocking participant while preparing a new version', function () {
    $round = Round::where('title', 'Frühjahr-Bestellung 2026')->firstOrFail();
    $proposalA = $round->proposals()->where('title', 'Vorschlag A — 50-kg-Reis')->firstOrFail();
    app(ProposalBuilder::class)->createNewVersion($proposalA, $this->marie);

    $this->actingAs($this->marie);

    visit("/g/speisekammer-schoeneberg/rounds/{$round->id}?phase=finalizing")
        ->assertSee('Vorschlag A — 50-kg-Reis (Version 2)')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '22-round-draft-as-lead', fullPage: true)
        ->click('[aria-label="Mehr zum Entwurf"]')
        ->click('Person ausschließen …')
        ->wait(1)
        ->assertSee('Person aus der Bestellung ausschließen?')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '22b-exclude-from-draft');
});

it('shows supplier and product pages with notes, documents and price history', function () {
    $this->actingAs($this->marie);

    $supplier = Supplier::where('name', 'Senfwerk Düsseldorf')->firstOrFail();
    $product = Product::where('name', 'Bio Dinkelmehl Type 630')->firstOrFail();

    visit("/g/speisekammer-schoeneberg/suppliers/{$supplier->id}")
        ->assertSee('Senfwerk Düsseldorf')
        ->assertSee('Versandtermin im November war knapp')
        ->assertSee('Dokument hochladen')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '23-supplier-page', fullPage: true);

    visit("/g/speisekammer-schoeneberg/products/{$product->id}")
        ->assertSee('Gebindegrößen & Preise')
        ->assertSee('Tatsächlich bezahlte Preise')
        ->assertSee('eure Gruppe')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '24-product-page', fullPage: true);
});

it('shows the personal order history', function () {
    $this->actingAs($this->marie);

    visit('/g/speisekammer-schoeneberg/my-orders')
        ->assertSee('Meine Bestellungen')
        ->assertSee('Spätsommer-Bestellung 2025')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '25-my-orders', fullPage: true);
});

it('shows the adjustment phase with the supplier cards and the draft', function () {
    $linus = User::where('email', 'linus@foodpecker.test')->firstOrFail();
    $round = Round::where('title', 'Herbst-Bestellung 2026')->firstOrFail();
    $this->actingAs($linus);

    visit("/g/familie-mueller-friends/rounds/{$round->id}")
        ->assertSee('Zur Abstimmung stellen')
        ->assertSee('Rückmeldungen 1/3')
        ->assertSee('Spielberger Mühle')
        ->assertSee('Rückmeldung speichern')
        ->assertSee('Nachfassen')
        ->assertSee('Bestellvorschlag')
        ->assertSee('Mengen runden')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '28-adjustment-phase', fullPage: true);
});

it('lets the lead record a supplier\'s answer and round an amount in the draft', function () {
    $linus = User::where('email', 'linus@foodpecker.test')->firstOrFail();
    $round = Round::where('title', 'Herbst-Bestellung 2026')->firstOrFail();
    $senfwerk = Supplier::where('slug', 'senfwerk-duesseldorf')->firstOrFail();
    $this->actingAs($linus);

    visit("/g/familie-mueller-friends/rounds/{$round->id}")
        ->fill('section[aria-label="Senfwerk Düsseldorf"] input[aria-label="Versandkosten"]', '9,50')
        ->click('section[aria-label="Senfwerk Düsseldorf"] button:has-text("Rückmeldung speichern")')
        ->wait(1)
        ->assertSee('Rückmeldung von Senfwerk Düsseldorf gespeichert.')
        ->assertSee('Rückmeldungen 2/3')
        ->fill('input[aria-label="Menge für Aylin"] >> nth=0', '9')
        ->keys('input[aria-label="Menge für Aylin"] >> nth=0', 'Tab')
        ->wait(1)
        ->assertSee('von Hand')
        ->assertNoJavaScriptErrors();

    expect($round->roundSuppliers()->where('supplier_id', $senfwerk->id)->value('shipping_cents'))->toBe(950);
});

it('lets the owner dissolve a group from the group settings', function () {
    $linus = User::where('email', 'linus@foodpecker.test')->firstOrFail();
    $this->actingAs($linus);

    $page = visit('/g/familie-mueller-friends/profile')
        ->assertSee('Gruppen-Einstellungen')
        ->press('Gruppe auflösen')
        ->wait(1)
        ->assertSee('Das wird gelöscht')
        ->assertSee('Das lässt sich nicht rückgängig machen.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '26-dissolve-group-modal');

    $page->fill('input[id$="confirmation"]', 'Familie Müller & Friends')
        ->press('Endgültig auflösen')
        ->wait(2)
        ->assertPathContains('/g/speisekammer-schoeneberg')
        ->assertSee('wurde aufgelöst')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '27-after-dissolving-group');

    expect(Group::where('slug', 'familie-mueller-friends')->exists())->toBeFalse();
});

it('lets a member take over a group they were asked to own', function () {
    $tobias = User::where('email', 'tobias@foodpecker.test')->firstOrFail();
    $group = Group::where('slug', 'speisekammer-schoeneberg')->firstOrFail();

    app(OwnerTransfer::class)->request($group, $tobias, $this->marie);

    $this->actingAs($tobias);

    $page = visit('/g/speisekammer-schoeneberg/members')
        ->assertSee('Marie Kerres möchte dir die Owner-Rolle übergeben.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '28-owner-transfer-request');

    $page->press('Owner-Rolle übernehmen')
        ->wait(1)
        ->press('Übernehmen')
        ->wait(2)
        ->assertSee('Du bist jetzt Owner der Gruppe.')
        ->assertSee('Owner-Rolle von Marie Kerres an Tobias Hartmann übergeben')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '29-owner-transfer-accepted', fullPage: true);

    expect($group->fresh()->owner_id)->toBe($tobias->id);
});

it('shows the personal profile and the members with their contact details', function () {
    $this->actingAs($this->marie);

    visit('/profile')
        ->assertSee('Profilfoto')
        ->assertSee('Handynummer')
        ->assertSee('Personen im Haushalt')
        ->assertSee('Zugangsdaten')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '30-profile', fullPage: true);

    visit('/g/speisekammer-schoeneberg/members')
        ->assertSee('+49 171 2345678')
        ->assertSee('10827 Berlin')
        ->assertSee('Haushalte mit zusammen')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '31-members-with-contact-details', fullPage: true);
});

it('asks for mobile number, location and household size when registering', function () {
    visit('/register')
        ->assertSee('Handynummer')
        ->assertSee('PLZ')
        ->assertSee('Wohnort')
        ->assertSee('Personen im Haushalt')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: '32-register', fullPage: true);
});
