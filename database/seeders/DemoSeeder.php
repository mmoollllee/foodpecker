<?php

namespace Database\Seeders;

use App\Enums\GroupRole;
use App\Enums\PackagingStrategy;
use App\Enums\PaymentStatus;
use App\Enums\ProductCategory;
use App\Enums\ProposalStatus;
use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Enums\Visibility;
use App\Enums\VoteValue;
use App\Models\CartItem;
use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\Note;
use App\Models\OrderProposal;
use App\Models\Payment;
use App\Models\Pickup;
use App\Models\PickupDate;
use App\Models\PriceObservation;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProposalAllocation;
use App\Models\ProposalItem;
use App\Models\ProposalVote;
use App\Models\Round;
use App\Models\RoundParticipant;
use App\Models\User;
use App\Services\Distribution\Distributor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🪶 Foodpecker Demo-Daten werden angelegt …');

        // ---------------- Users ----------------
        $marie = $this->makeUser('Marie', 'Kerres', 'marie@foodpecker.test');
        $tobias = $this->makeUser('Tobias', 'Hartmann', 'tobias@foodpecker.test');
        $sara = $this->makeUser('Sara', 'Bauer', 'sara@foodpecker.test');
        $linus = $this->makeUser('Linus', 'Vogel', 'linus@foodpecker.test');
        $aylin = $this->makeUser('Aylin', 'Yıldız', 'aylin@foodpecker.test');
        $jonas = $this->makeUser('Jonas', 'Hofmann', 'jonas@foodpecker.test');
        $kira = $this->makeUser('Kira', 'Lehmann', 'kira@foodpecker.test');

        // ---------------- Gruppen ----------------
        $schoeneberg = Group::create([
            'name' => 'Speisekammer Schöneberg',
            'slug' => 'speisekammer-schoeneberg',
            'owner_id' => $marie->id,
            'description' => 'Sieben Haushalte in Berlin-Schöneberg, die viermal im Jahr gemeinsam Vorräte bestellen.',
            'contact_email' => 'speisekammer@foodpecker.test',
        ]);

        $lichtenrade = Group::create([
            'name' => 'Hofgemeinschaft Lichtenrade',
            'slug' => 'hofgemeinschaft-lichtenrade',
            'owner_id' => $tobias->id,
            'description' => 'Selbstversorger-Initiative mit eigenem Lager und mehreren Großbestellungen pro Jahr.',
            'contact_email' => 'lichtenrade@foodpecker.test',
        ]);

        $familie = Group::create([
            'name' => 'Familie Müller & Friends',
            'slug' => 'familie-mueller-friends',
            'owner_id' => $linus->id,
            'description' => 'Drei Familien, ein Speicher.',
        ]);

        // ---------------- Mitgliedschaften ----------------
        $this->attach($schoeneberg, $marie, GroupRole::Owner);
        $this->attach($schoeneberg, $tobias, GroupRole::Moderator);
        $this->attach($schoeneberg, $sara, GroupRole::Participant);
        $this->attach($schoeneberg, $linus, GroupRole::Participant);
        $this->attach($schoeneberg, $aylin, GroupRole::Participant);
        $this->attach($schoeneberg, $jonas, GroupRole::Participant);

        $this->attach($lichtenrade, $tobias, GroupRole::Owner);
        $this->attach($lichtenrade, $kira, GroupRole::Moderator);
        $this->attach($lichtenrade, $marie, GroupRole::Participant);

        $this->attach($familie, $linus, GroupRole::Owner);
        $this->attach($familie, $sara, GroupRole::Moderator);

        $marie->update(['current_group_id' => $schoeneberg->id]);
        $tobias->update(['current_group_id' => $schoeneberg->id]);

        // ---------------- Hersteller ----------------
        $spielberger = Manufacturer::create([
            'group_id' => null,
            'visibility' => Visibility::Public,
            'name' => 'Spielberger Mühle',
            'slug' => 'spielberger-muehle',
            'website' => 'https://spielberger-muehle.de',
            'contact_email' => 'kontakt@spielberger-muehle.de',
            'address' => "Heinrich-Heine-Str. 1\n74366 Kirchheim/Neckar",
            'shipping_notes' => 'Mindestbestellwert 250 €. Versand per Spedition, freie Lieferung ab einer Palette.',
            'description' => 'Bio-Mühle in Württemberg. Sehr freundlicher Kontakt.',
            'created_by_user_id' => $marie->id,
        ]);

        $pastaItalia = Manufacturer::create([
            'group_id' => null,
            'visibility' => Visibility::Public,
            'name' => 'Pasta Italia Genossenschaft',
            'slug' => 'pasta-italia',
            'website' => 'https://pasta-italia.coop',
            'contact_email' => 'export@pasta-italia.coop',
            'address' => "Via dei Mulini 42\n75100 Matera, IT",
            'shipping_notes' => 'Lieferung 6–8 Wochen, ab 2 Paletten kostenlos. Englisch geht problemlos.',
            'created_by_user_id' => $tobias->id,
        ]);

        $senfwerk = Manufacturer::create([
            'group_id' => null,
            'visibility' => Visibility::Public,
            'name' => 'Senfwerk Düsseldorf',
            'slug' => 'senfwerk-duesseldorf',
            'website' => 'https://senfwerk-duesseldorf.de',
            'contact_email' => 'orders@senfwerk-duesseldorf.de',
            'shipping_notes' => 'Liefert nur palettenweise (1 Palette = 96 Gläser).',
            'description' => 'Familienbetrieb, dritte Generation. Brennend gerne mal scharf.',
            'created_by_user_id' => $tobias->id,
        ]);

        $hofladen = Manufacturer::create([
            'group_id' => $schoeneberg->id,
            'visibility' => Visibility::Private,
            'name' => 'Hofladen Brandenburg',
            'slug' => 'hofladen-brandenburg',
            'contact_email' => 'hofladen@bb.test',
            'contact_phone' => '+49 30 1234567',
            'address' => "Dorfstr. 7\n14913 Niedergörsdorf",
            'shipping_notes' => 'Persönliche Abholung am Hof, Versand auf Anfrage.',
            'description' => 'Privater Kontakt von Tobias — exklusive Speisekammer-Lieferungen.',
            'created_by_user_id' => $tobias->id,
        ]);

        $nudelschmiede = Manufacturer::create([
            'group_id' => null,
            'visibility' => Visibility::Public,
            'name' => 'Nudelschmiede Süd',
            'slug' => 'nudelschmiede-sued',
            'website' => 'https://nudelschmiede-sued.de',
            'shipping_notes' => 'Mindestbestellung 100 kg, Lieferung in 5 kg Kartons.',
            'created_by_user_id' => $marie->id,
        ]);

        // ---------------- Produkte mit allen 5 Verpackungs-Szenarien ----------------

        // Szenario 1 — Feste Paketgröße: Dinkelmehl 25/50 kg (teilbar)
        $dinkelmehl = Product::create([
            'manufacturer_id' => $spielberger->id,
            'group_id' => null,
            'visibility' => Visibility::Public,
            'name' => 'Bio Dinkelmehl Type 630',
            'slug' => 'bio-dinkelmehl-630',
            'unit' => 'kg',
            'category' => ProductCategory::Flours,
            'packaging_strategy' => PackagingStrategy::Tiered,
            'estimated_price_cents' => 4800,
            'description' => 'Hell vermahlen, hervorragend zum Brotbacken und für Pasta. Sehr beliebt.',
            'created_by_user_id' => $marie->id,
        ]);
        PriceTier::create(['product_id' => $dinkelmehl->id, 'label' => '25 kg Sack', 'package_amount' => 25.0, 'price_cents' => 4800, 'is_divisible' => true, 'divisible_step' => 0.5, 'sort_order' => 1]);
        PriceTier::create(['product_id' => $dinkelmehl->id, 'label' => '50 kg Sack', 'package_amount' => 50.0, 'price_cents' => 8400, 'is_divisible' => true, 'divisible_step' => 0.5, 'sort_order' => 2]);

        // Szenario 2 — Mengenstaffel mit Preisvorteil: Bio-Reis 10/25/50 kg
        $reis = Product::create([
            'manufacturer_id' => $spielberger->id,
            'visibility' => Visibility::Public,
            'name' => 'Bio Basmati Reis',
            'slug' => 'bio-basmati-reis',
            'unit' => 'kg',
            'category' => ProductCategory::Grains,
            'packaging_strategy' => PackagingStrategy::Tiered,
            'estimated_price_cents' => 5500,
            'description' => 'Bio-Reis aus fairer Kooperation, lange Körner.',
            'created_by_user_id' => $marie->id,
        ]);
        PriceTier::create(['product_id' => $reis->id, 'label' => '10 kg Sack', 'package_amount' => 10.0, 'price_cents' => 2800, 'is_divisible' => true, 'divisible_step' => 0.5, 'sort_order' => 1]);
        PriceTier::create(['product_id' => $reis->id, 'label' => '25 kg Sack', 'package_amount' => 25.0, 'price_cents' => 5500, 'is_divisible' => true, 'divisible_step' => 0.5, 'sort_order' => 2]);
        PriceTier::create(['product_id' => $reis->id, 'label' => '50 kg Sack', 'package_amount' => 50.0, 'price_cents' => 9500, 'is_divisible' => true, 'divisible_step' => 0.5, 'sort_order' => 3]);

        // Szenario 3 — Palette mit teilbaren Einheiten: Senf (12er-Schritte, einzeln verteilbar)
        $senf = Product::create([
            'manufacturer_id' => $senfwerk->id,
            'visibility' => Visibility::Public,
            'name' => 'Düsseldorfer Senf scharf',
            'slug' => 'duesseldorfer-senf-scharf',
            'unit' => 'glas',
            'category' => ProductCategory::Condiments,
            'packaging_strategy' => PackagingStrategy::PaletteDivisible,
            'estimated_price_cents' => 360,
            'description' => '250 g Gläser. Verkauft nur palettenweise — 1 Palette = 8 × 12er-Karton.',
            'created_by_user_id' => $tobias->id,
        ]);
        PriceTier::create(['product_id' => $senf->id, 'label' => '12er Karton (1/8 Palette)', 'package_amount' => 12, 'price_cents' => 3900, 'is_divisible' => true, 'divisible_step' => 1, 'sort_order' => 1]);

        // Szenario 4a — Mehrere feste, nicht teilbare Packungen: Spaghetti 250 g / 2 kg
        $spaghetti = Product::create([
            'manufacturer_id' => $pastaItalia->id,
            'visibility' => Visibility::Public,
            'name' => 'Spaghetti N. 5 (Bronzeziehung)',
            'slug' => 'spaghetti-bronze',
            'unit' => 'kg',
            'category' => ProductCategory::Pasta,
            'packaging_strategy' => PackagingStrategy::MultiSizeIndivisible,
            'estimated_price_cents' => 320,
            'description' => 'Aus 100 % Hartweizen. 250-g-Packungen praktisch für Singles, 2-kg-Beutel für große Haushalte.',
            'created_by_user_id' => $tobias->id,
        ]);
        PriceTier::create(['product_id' => $spaghetti->id, 'label' => '250 g Packung', 'package_amount' => 0.25, 'price_cents' => 110, 'is_divisible' => false, 'sort_order' => 1]);
        PriceTier::create(['product_id' => $spaghetti->id, 'label' => '2 kg Beutel', 'package_amount' => 2.0, 'price_cents' => 740, 'is_divisible' => false, 'sort_order' => 2]);

        // Szenario 4b — Großgebinde mit individueller Abwiegung: Spirelli 5 kg Karton
        $spirelli = Product::create([
            'manufacturer_id' => $nudelschmiede->id,
            'visibility' => Visibility::Public,
            'name' => 'Spirelli aus Hartweizen',
            'slug' => 'spirelli-hartweizen',
            'unit' => 'kg',
            'category' => ProductCategory::Pasta,
            'packaging_strategy' => PackagingStrategy::BulkWeighable,
            'estimated_price_cents' => 1850,
            'description' => 'Klassische Spiralen, in 5 kg Kartons. Innerhalb des Kartons frei abwiegbar.',
            'created_by_user_id' => $marie->id,
        ]);
        PriceTier::create(['product_id' => $spirelli->id, 'label' => '5 kg Karton', 'package_amount' => 5.0, 'price_cents' => 1850, 'is_divisible' => true, 'divisible_step' => 0.1, 'sort_order' => 1]);

        // Bonus-Produkte
        $polenta = Product::create([
            'manufacturer_id' => $spielberger->id,
            'visibility' => Visibility::Public,
            'name' => 'Bio Polenta grob',
            'slug' => 'bio-polenta-grob',
            'unit' => 'kg',
            'category' => ProductCategory::Grains,
            'packaging_strategy' => PackagingStrategy::Tiered,
            'estimated_price_cents' => 2600,
            'created_by_user_id' => $marie->id,
        ]);
        PriceTier::create(['product_id' => $polenta->id, 'label' => '10 kg Sack', 'package_amount' => 10, 'price_cents' => 2600, 'is_divisible' => true, 'divisible_step' => 0.5, 'sort_order' => 1]);
        PriceTier::create(['product_id' => $polenta->id, 'label' => '25 kg Sack', 'package_amount' => 25, 'price_cents' => 5800, 'is_divisible' => true, 'divisible_step' => 0.5, 'sort_order' => 2]);

        $hafer = Product::create([
            'manufacturer_id' => $spielberger->id,
            'visibility' => Visibility::Public,
            'name' => 'Bio Haferflocken kernig',
            'slug' => 'bio-haferflocken-kernig',
            'unit' => 'kg',
            'category' => ProductCategory::Grains,
            'packaging_strategy' => PackagingStrategy::Tiered,
            'estimated_price_cents' => 2200,
            'created_by_user_id' => $marie->id,
        ]);
        PriceTier::create(['product_id' => $hafer->id, 'label' => '15 kg Sack', 'package_amount' => 15, 'price_cents' => 2200, 'is_divisible' => true, 'divisible_step' => 0.25, 'sort_order' => 1]);
        PriceTier::create(['product_id' => $hafer->id, 'label' => '25 kg Sack', 'package_amount' => 25, 'price_cents' => 3300, 'is_divisible' => true, 'divisible_step' => 0.25, 'sort_order' => 2]);

        $kartoffeln = Product::create([
            'manufacturer_id' => $hofladen->id,
            'group_id' => $schoeneberg->id,
            'visibility' => Visibility::Private,
            'name' => 'Kartoffeln (festkochend)',
            'slug' => 'kartoffeln-festkochend',
            'unit' => 'kg',
            'category' => ProductCategory::Produce,
            'packaging_strategy' => PackagingStrategy::BulkWeighable,
            'estimated_price_cents' => 1800,
            'description' => 'Nur für die Schöneberger Speisekammer, exklusiver Brandenburger Lieferant.',
            'created_by_user_id' => $tobias->id,
        ]);
        PriceTier::create(['product_id' => $kartoffeln->id, 'label' => '25 kg Sack', 'package_amount' => 25, 'price_cents' => 4500, 'is_divisible' => true, 'divisible_step' => 0.5, 'sort_order' => 1]);

        // Notiz an Hersteller
        Note::create([
            'notable_type' => Manufacturer::class,
            'notable_id' => $senfwerk->id,
            'user_id' => $tobias->id,
            'group_id' => $schoeneberg->id,
            'title' => 'Versandtermin im November war knapp',
            'body' => 'Senfwerk war im November 2025 etwas spät — die Lieferung kam erst drei Wochen nach Bestellung. Nächstes Mal entsprechend früh bestellen und Pufferzeit einplanen ✌️',
        ]);

        // ---------------- Abgeschlossene Runde (Historie) ----------------
        $completed = Round::create([
            'group_id' => $schoeneberg->id,
            'lead_user_id' => $marie->id,
            'title' => 'Spätsommer-Bestellung 2025',
            'phase' => RoundPhase::Completed,
            'shopping_deadline' => '2025-09-15',
            'negotiation_deadline' => '2025-09-22',
            'finalization_deadline' => '2025-09-29',
            'payment_deadline' => '2025-10-06',
            'expected_delivery' => '2025-10-20',
            'pickup_location' => 'Hauptstraße 42, 10827 Berlin · Hinterhof, Keller links',
            'max_participants' => 8,
            'lead_fee_percent' => 2.5,
            'platform_fee_percent' => 1.0,
            'phase_changed_at' => Carbon::parse('2025-10-25 14:00'),
        ]);

        PickupDate::create([
            'round_id' => $completed->id,
            'scheduled_at' => '2025-10-22 18:00',
            'location' => 'Schöneberger Speisekammer',
            'notes' => 'Bitte Helfer mitbringen, sind ein paar 50-kg-Säcke.',
        ]);

        foreach ([$marie, $tobias, $sara, $linus, $aylin] as $u) {
            RoundParticipant::create(['round_id' => $completed->id, 'user_id' => $u->id]);
        }

        // Cart-Items für die abgeschlossene Runde
        $this->cartExact($completed, $marie, $dinkelmehl, 5);
        $this->cartFlex($completed, $marie, $reis, 5, 10);
        $this->cartExact($completed, $tobias, $dinkelmehl, 8);
        $this->cartExact($completed, $tobias, $reis, 10);
        $this->cartFlex($completed, $sara, $dinkelmehl, 3, 6);
        $this->cartExact($completed, $sara, $senf, 6);
        $this->cartFlex($completed, $linus, $reis, 5, 12);
        $this->cartExact($completed, $linus, $senf, 4);
        $this->cartFlex($completed, $aylin, $dinkelmehl, 4, 10);
        $this->cartExact($completed, $aylin, $senf, 2);

        // Gewählter Vorschlag mit Allokationen
        $chosenProposal = $this->buildProposal(
            round: $completed,
            proposer: $marie,
            title: 'Finale Bestellung Spätsommer',
            description: 'Reis als 50-kg-Sack, das spart 0,11 €/kg.',
            status: ProposalStatus::Chosen,
            publishedAt: Carbon::parse('2025-09-25 10:00'),
            items: [
                [$dinkelmehl, $dinkelmehl->priceTiers->where('package_amount', 25.0)->first()],
                [$reis, $reis->priceTiers->where('package_amount', 50.0)->first()],
                [$senf, $senf->priceTiers->first()],
            ],
            shippingCents: 4900,
        );

        $completed->update(['chosen_proposal_id' => $chosenProposal->id]);

        // Votes (alle Daumen hoch)
        foreach ($chosenProposal->items as $item) {
            foreach ([$marie, $tobias, $sara, $linus, $aylin] as $voter) {
                ProposalVote::create([
                    'proposal_item_id' => $item->id,
                    'user_id' => $voter->id,
                    'value' => VoteValue::Up->value,
                ]);
            }
        }

        // Payments + Pickups + Preis-Beobachtungen
        foreach ([$marie, $tobias, $sara, $linus, $aylin] as $u) {
            Payment::create([
                'round_id' => $completed->id,
                'user_id' => $u->id,
                'amount_cents' => 4500 + ($u->id * 200),
                'round_up_donation_cents' => $u->id % 2 ? 50 : 0,
                'status' => PaymentStatus::Paid,
                'paid_at' => Carbon::parse('2025-10-04 12:00')->addHours($u->id),
            ]);
            Pickup::create([
                'round_id' => $completed->id,
                'user_id' => $u->id,
                'picked_up_at' => Carbon::parse('2025-10-22 19:00')->addMinutes($u->id * 15),
            ]);
        }

        foreach ($chosenProposal->items as $item) {
            PriceObservation::create([
                'product_id' => $item->product_id,
                'price_tier_id' => $item->price_tier_id,
                'round_id' => $completed->id,
                'group_id' => $schoeneberg->id,
                'observed_price_cents' => $item->priceTier->price_cents,
                'package_amount' => $item->priceTier->package_amount,
                'observed_on' => '2025-10-04',
            ]);
        }

        // Notizen an die abgeschlossene Runde (im Sinne von "lernen aus jeder Runde")
        Note::create([
            'notable_type' => Round::class,
            'notable_id' => $completed->id,
            'user_id' => $linus->id,
            'group_id' => $schoeneberg->id,
            'body' => 'Die Spaghetti waren beim Bronze-Spaghetti diesmal nicht ganz so gut wie versprochen — etwas zu hart. Vielleicht nächstes Mal andere Marke.',
        ]);
        Note::create([
            'notable_type' => Round::class,
            'notable_id' => $completed->id,
            'user_id' => $aylin->id,
            'group_id' => $schoeneberg->id,
            'body' => 'Hat geklappt mit heißem Wasser kochen, dann werden sie weich 🙂',
        ]);

        // ---------------- Aktive Runde (Frühjahr 2026, Phase finalizing) ----------------
        $active = Round::create([
            'group_id' => $schoeneberg->id,
            'lead_user_id' => $marie->id,
            'title' => 'Frühjahr-Bestellung 2026',
            'phase' => RoundPhase::Finalizing,
            'shopping_deadline' => '2026-05-15',
            'negotiation_deadline' => '2026-05-22',
            'finalization_deadline' => '2026-05-31',
            'payment_deadline' => '2026-06-07',
            'expected_delivery' => '2026-06-21',
            'pickup_location' => 'Hauptstraße 42, 10827 Berlin · Hinterhof, Keller links',
            'max_participants' => 8,
            'lead_fee_percent' => 2.5,
            'platform_fee_percent' => 1.0,
            'phase_changed_at' => Carbon::parse('2026-05-22 09:00'),
            'description' => 'Diesmal mit Spirelli und Polenta — die Nudelschmiede hat ein neues Spiralen-Sortiment.',
        ]);

        PickupDate::create(['round_id' => $active->id, 'scheduled_at' => '2026-06-22 18:00', 'location' => 'Speisekammer, Keller links']);
        PickupDate::create(['round_id' => $active->id, 'scheduled_at' => '2026-06-24 18:30', 'location' => 'Speisekammer, Keller links']);
        PickupDate::create(['round_id' => $active->id, 'scheduled_at' => '2026-06-26 11:00', 'location' => 'Speisekammer, Keller links', 'notes' => 'Samstags-Termin für die ohne Tageszeit-Flexibilität.']);

        foreach ([$marie, $tobias, $sara, $linus, $aylin, $jonas] as $u) {
            RoundParticipant::create(['round_id' => $active->id, 'user_id' => $u->id]);
        }

        // Cart-Items, alle 5 Szenarien sichtbar:
        $this->cartExact($active, $marie, $dinkelmehl, 4);
        $this->cartFlex($active, $marie, $reis, 5, 10);
        $this->cartExact($active, $marie, $senf, 4);
        $this->cartExact($active, $marie, $spirelli, 2);
        $this->cartExact($active, $marie, $spaghetti, 2);

        $this->cartExact($active, $tobias, $dinkelmehl, 10);
        $this->cartExact($active, $tobias, $reis, 12);
        $this->cartFlex($active, $tobias, $polenta, 3, 6);
        $this->cartExact($active, $tobias, $spaghetti, 4);

        $this->cartFlex($active, $sara, $dinkelmehl, 3, 8);
        $this->cartExact($active, $sara, $senf, 3);
        $this->cartFlex($active, $sara, $spirelli, 1, 3);
        $this->cartExact($active, $sara, $hafer, 5);

        $this->cartFlex($active, $linus, $reis, 5, 15);
        $this->cartExact($active, $linus, $spaghetti, 6);
        $this->cartFlex($active, $linus, $hafer, 4, 8);

        $this->cartFlex($active, $aylin, $dinkelmehl, 4, 8);
        $this->cartExact($active, $aylin, $senf, 2);
        $this->cartFlex($active, $aylin, $spirelli, 2, 4);

        $this->cartFlex($active, $jonas, $reis, 3, 10);
        $this->cartExact($active, $jonas, $polenta, 5);
        $this->cartExact($active, $jonas, $spaghetti, 1.5);

        // Vorschläge — zwei konkurrierende
        $proposalA = $this->buildProposal(
            round: $active,
            proposer: $marie,
            title: 'Vorschlag A — 50-kg-Reis',
            description: 'Reis als 50-kg-Sack. Spart Geld, aber wir müssen ihn aufteilen.',
            status: ProposalStatus::Published,
            publishedAt: Carbon::parse('2026-05-23 10:00'),
            items: [
                [$dinkelmehl, $dinkelmehl->priceTiers->where('package_amount', 25.0)->first()],
                [$reis, $reis->priceTiers->where('package_amount', 50.0)->first()],
                [$senf, $senf->priceTiers->first()],
                [$spaghetti, $spaghetti->priceTiers->where('package_amount', 2.0)->first()],
                [$spirelli, $spirelli->priceTiers->first()],
                [$polenta, $polenta->priceTiers->where('package_amount', 10.0)->first()],
                [$hafer, $hafer->priceTiers->where('package_amount', 15.0)->first()],
            ],
            shippingCents: 7900,
        );

        $proposalB = $this->buildProposal(
            round: $active,
            proposer: $tobias,
            title: 'Vorschlag B — Reis kleiner, Mehl größer',
            description: 'Reis als 25 kg, dafür Mehl als 50 kg — wir verteilen Reis und Mehl jeweils auf zwei Familien.',
            status: ProposalStatus::Published,
            publishedAt: Carbon::parse('2026-05-23 16:00'),
            items: [
                [$dinkelmehl, $dinkelmehl->priceTiers->where('package_amount', 50.0)->first()],
                [$reis, $reis->priceTiers->where('package_amount', 25.0)->first()],
                [$senf, $senf->priceTiers->first()],
                [$spaghetti, $spaghetti->priceTiers->where('package_amount', 2.0)->first()],
                [$spirelli, $spirelli->priceTiers->first()],
                [$polenta, $polenta->priceTiers->where('package_amount', 10.0)->first()],
                [$hafer, $hafer->priceTiers->where('package_amount', 15.0)->first()],
            ],
            shippingCents: 7900,
        );

        // Votes — Mischmasch
        $voters = [$marie, $tobias, $sara, $linus, $aylin, $jonas];
        foreach ($proposalA->items as $idx => $item) {
            foreach ($voters as $i => $voter) {
                $val = ($idx + $i) % 7 === 0 ? VoteValue::Down : VoteValue::Up;
                ProposalVote::create([
                    'proposal_item_id' => $item->id,
                    'user_id' => $voter->id,
                    'value' => $val->value,
                    'reason' => $val === VoteValue::Down ? 'Mir wäre eine kleinere Menge lieber, mein Keller ist eh schon voll.' : null,
                ]);
            }
        }
        foreach ($proposalB->items as $idx => $item) {
            foreach ($voters as $i => $voter) {
                $val = ($idx * 3 + $i) % 5 === 0 ? VoteValue::Down : VoteValue::Up;
                ProposalVote::create([
                    'proposal_item_id' => $item->id,
                    'user_id' => $voter->id,
                    'value' => $val->value,
                ]);
            }
        }

        // Aktivitäten-Stream
        $active->logActivity('phase_changed', ['from' => 'negotiating', 'to' => 'finalizing']);
        $active->logActivity('proposal_published', ['proposal_id' => $proposalA->id]);
        $active->logActivity('proposal_published', ['proposal_id' => $proposalB->id]);
        $proposalA->logActivity('created');
        $proposalB->logActivity('created');

        // ---------------- Shopping-Runde (Sommer 2026, Phase shopping) ----------------
        // Tobias ist Lead; alle anderen haben schon gefüllte Warenkörbe.
        // Marie hat erst _ein_ Produkt drin — passt zur Aufgabenliste auf dem Dashboard.
        $shopping = Round::create([
            'group_id' => $schoeneberg->id,
            'lead_user_id' => $tobias->id,
            'title' => 'Sommer-Bestellung 2026',
            'phase' => RoundPhase::Shopping,
            'shopping_deadline' => '2026-06-15',
            'negotiation_deadline' => '2026-06-22',
            'finalization_deadline' => '2026-07-01',
            'payment_deadline' => '2026-07-08',
            'expected_delivery' => '2026-07-22',
            'pickup_location' => 'Hauptstraße 42, 10827 Berlin · Hinterhof, Keller links',
            'max_participants' => 8,
            'lead_fee_percent' => 2.5,
            'platform_fee_percent' => 1.0,
            'phase_changed_at' => Carbon::parse('2026-05-20 09:00'),
            'description' => 'Großeinkauf für Sommer + Spätsommer. Bitte bis zum 15.06. die Warenkörbe füllen, danach hole ich Hersteller-Preise ein.',
        ]);

        PickupDate::create(['round_id' => $shopping->id, 'scheduled_at' => '2026-07-23 18:00', 'location' => 'Speisekammer, Keller links']);
        PickupDate::create(['round_id' => $shopping->id, 'scheduled_at' => '2026-07-25 18:30', 'location' => 'Speisekammer, Keller links']);

        foreach ([$marie, $tobias, $sara, $linus, $aylin, $jonas] as $u) {
            RoundParticipant::create(['round_id' => $shopping->id, 'user_id' => $u->id]);
        }

        // Marie: erst _ein_ Produkt → Aufgabe „Warenkorb füllen" bleibt sichtbar
        $this->cartExact($shopping, $marie, $dinkelmehl, 3);

        // Andere haben bereits substanzielle Warenkörbe
        $this->cartExact($shopping, $tobias, $dinkelmehl, 8);
        $this->cartExact($shopping, $tobias, $reis, 15);
        $this->cartFlex($shopping, $tobias, $polenta, 5, 10);
        $this->cartExact($shopping, $tobias, $spirelli, 3);
        $this->cartExact($shopping, $tobias, $senf, 6);

        $this->cartFlex($shopping, $sara, $dinkelmehl, 2, 6);
        $this->cartExact($shopping, $sara, $hafer, 8);
        $this->cartExact($shopping, $sara, $senf, 4);
        $this->cartFlex($shopping, $sara, $spaghetti, 2, 5);

        $this->cartFlex($shopping, $linus, $reis, 8, 20);
        $this->cartExact($shopping, $linus, $spaghetti, 6);
        $this->cartFlex($shopping, $linus, $hafer, 3, 8);
        $this->cartExact($shopping, $linus, $polenta, 5);

        $this->cartFlex($shopping, $aylin, $dinkelmehl, 3, 6);
        $this->cartExact($shopping, $aylin, $senf, 3);
        $this->cartFlex($shopping, $aylin, $spirelli, 2, 4);
        $this->cartExact($shopping, $aylin, $reis, 5);

        $this->cartFlex($shopping, $jonas, $reis, 4, 12);
        $this->cartExact($shopping, $jonas, $polenta, 3);
        $this->cartFlex($shopping, $jonas, $hafer, 2, 5);

        $shopping->logActivity('phase_changed', ['from' => 'draft', 'to' => 'shopping']);

        // ---------------- Offene Einladung ----------------
        $schoeneberg->invitations()->create([
            'email' => 'sandra@foodpecker.test',
            'role' => GroupRole::Participant->value,
            'invited_by_user_id' => $marie->id,
        ]);

        $this->command->info('✅ Demo-Daten angelegt.');
        $this->command->newLine();
        $this->command->info('   Demo-Login:');
        $this->command->info('     E-Mail:    marie@foodpecker.test');
        $this->command->info('     Passwort:  password');
        $this->command->info('   Weitere Test-Users (alle Passwort "password"):');
        $this->command->info('     tobias@foodpecker.test  (Moderator in Schöneberg, Owner in Lichtenrade)');
        $this->command->info('     sara@foodpecker.test    (Teilnehmer in Schöneberg, Moderator in Familie)');
        $this->command->info('     linus@foodpecker.test   (Teilnehmer in Schöneberg, Owner in Familie)');
        $this->command->info('     aylin@foodpecker.test   (Teilnehmer in Schöneberg)');
        $this->command->info('     jonas@foodpecker.test   (Teilnehmer in Schöneberg)');
    }

    private function makeUser(string $first, string $last, string $email): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => $last,
            'name' => $first.' '.$last,
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ]);
    }

    private function attach(Group $group, User $user, GroupRole $role): void
    {
        $group->members()->attach($user->id, [
            'role' => $role->value,
            'joined_at' => now()->subDays(rand(30, 360)),
        ]);
    }

    private function cartExact(Round $round, User $user, Product $product, float $qty): void
    {
        CartItem::create([
            'round_id' => $round->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity_mode' => QuantityMode::Exact->value,
            'exact_quantity' => $qty,
        ]);
    }

    private function cartFlex(Round $round, User $user, Product $product, float $min, float $max): void
    {
        CartItem::create([
            'round_id' => $round->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity_mode' => QuantityMode::Flexible->value,
            'min_quantity' => $min,
            'max_quantity' => $max,
        ]);
    }

    /**
     * @param  array<int, array{0: Product, 1: PriceTier|null}>  $items
     */
    private function buildProposal(
        Round $round,
        User $proposer,
        string $title,
        ?string $description,
        ProposalStatus $status,
        ?Carbon $publishedAt,
        array $items,
        int $shippingCents,
    ): OrderProposal {
        $proposal = OrderProposal::create([
            'round_id' => $round->id,
            'proposed_by_user_id' => $proposer->id,
            'title' => $title,
            'description' => $description,
            'status' => $status->value,
            'shipping_cents' => $shippingCents,
            'published_at' => $publishedAt,
        ]);

        $distributor = app(Distributor::class);

        foreach ($items as [$product, $tier]) {
            if (! $tier instanceof PriceTier) {
                continue;
            }
            $cartItems = $round->cartItems()->where('product_id', $product->id)->get();
            if ($cartItems->isEmpty()) {
                continue;
            }

            $result = $distributor->compute($cartItems, $tier);
            if ($result->packagesOrdered <= 0) {
                continue;
            }

            $proposalItem = ProposalItem::create([
                'proposal_id' => $proposal->id,
                'product_id' => $product->id,
                'price_tier_id' => $tier->id,
                'packages_ordered' => $result->packagesOrdered,
                'total_price_cents' => $result->totalPriceCents,
                'notes' => $result->feasible ? null : implode("\n", $result->notes),
            ]);

            foreach ($result->allocations as $alloc) {
                ProposalAllocation::create([
                    'proposal_item_id' => $proposalItem->id,
                    'user_id' => $alloc->userId,
                    'quantity' => round($alloc->allocatedQuantity, 3),
                    'share_cents' => $alloc->shareCents,
                ]);
            }
        }

        return $proposal;
    }
}
