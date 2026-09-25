<?php

namespace Database\Seeders;

use App\Enums\GroupRole;
use App\Enums\NotificationKind;
use App\Enums\PackagingStrategy;
use App\Enums\PaymentStatus;
use App\Enums\ProductCategory;
use App\Enums\ProposalStatus;
use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Enums\Visibility;
use App\Enums\VoteValue;
use App\Models\Activity;
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
use App\Models\ProposalVote;
use App\Models\Round;
use App\Models\RoundParticipant;
use App\Models\User;
use App\Services\Distribution\PackageSpec;
use App\Services\Money\OrderCalculator;
use App\Services\Notifications\DraftBuilder;
use App\Services\Proposals\ProposalBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🪶 Foodpecker Demo-Daten werden angelegt …');

        // ---------------- Users ----------------
        $marie = $this->makeUser('Marie', 'Kerres', 'marie@foodpecker.test', '+49 171 2345678', '10827', 3);
        $tobias = $this->makeUser('Tobias', 'Hartmann', 'tobias@foodpecker.test', '+49 160 9876543', '12305', 4, nickname: 'Tobi');
        $sara = $this->makeUser('Sara', 'Bauer', 'sara@foodpecker.test', '+49 176 5550123', '10823', 2);
        $linus = $this->makeUser('Linus', 'Vogel', 'linus@foodpecker.test', '+49 152 3334455', '12157', 5, nickname: 'Lino');
        $aylin = $this->makeUser('Aylin', 'Yıldız', 'aylin@foodpecker.test', '+49 157 7788990', '10827', 1);
        $jonas = $this->makeUser('Jonas', 'Hofmann', 'jonas@foodpecker.test', '+49 170 1122334', '10781', 2);
        $kira = $this->makeUser('Kira', 'Lehmann', 'kira@foodpecker.test', '+49 179 4455667', '12307', 3);

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

        foreach ([$schoeneberg, $lichtenrade, $familie] as $group) {
            $this->backdateFounding($group);
        }

        $marie->update(['current_group_id' => $schoeneberg->id]);
        $tobias->update(['current_group_id' => $schoeneberg->id]);

        // ---------------- Hersteller ----------------
        $spielberger = Manufacturer::create([
            'group_id' => $schoeneberg->id,
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

        // Owned by another group — Schöneberg may use it, but not change it.
        $pastaItalia = Manufacturer::create([
            'group_id' => $lichtenrade->id,
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
            'group_id' => $schoeneberg->id,
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
            'group_id' => $schoeneberg->id,
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
            'group_id' => $schoeneberg->id,
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
            'group_id' => $schoeneberg->id,
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
            'group_id' => $schoeneberg->id,
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
            'group_id' => $lichtenrade->id,
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
            'group_id' => $schoeneberg->id,
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
            'group_id' => $schoeneberg->id,
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
            'group_id' => $schoeneberg->id,
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

        // ---------------- Beschreibungen nachpflegen + SVG-Demo-Bilder anlegen ----------------
        $descriptions = [
            $polenta->id => 'Bramata-Polenta, fein gemahlen aus Bio-Mais. Wird beim Kochen wunderbar cremig — perfekt zu geschmortem Gemüse oder mit Käse als Beilage. 12 Monate haltbar.',
            $hafer->id => 'Großblättrige, kernige Bio-Haferflocken. Ideal für klassisches Müsli, Porridge und zum Backen. Etwas süß, lange sättigend.',
            $spirelli->id => 'Klassische Spiralen aus 100 % Hartweizen, in 5 kg Kartons mit dichten Innenbeuteln. Kochzeit ca. 8 Minuten, bissfest auch nach 10 Minuten.',
        ];
        foreach ($descriptions as $productId => $text) {
            Product::where('id', $productId)->update(['description' => $text]);
        }

        foreach ([$dinkelmehl, $reis, $senf, $spaghetti, $spirelli, $polenta, $hafer, $kartoffeln] as $product) {
            $product->refresh();
            $path = $this->makeProductImage($product);
            $product->forceFill(['image_path' => $path])->save();
        }

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

        $completedPickupDate = PickupDate::create([
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

        // Votes: every stakeholder approved every item they receive a share of
        foreach ($chosenProposal->items()->with('allocations')->get() as $item) {
            foreach ($item->stakeholderIds() as $voterId) {
                ProposalVote::create([
                    'proposal_item_id' => $item->id,
                    'user_id' => $voterId,
                    'value' => VoteValue::Up->value,
                ]);
            }
        }

        // Payments + Pickups + Preis-Beobachtungen — amounts as the calculator computes them
        $completedTotals = app(OrderCalculator::class)->calculate($chosenProposal->fresh(['items.allocations', 'round.participants']));

        foreach ($completedTotals->perParticipant as $index => $share) {
            Payment::create([
                'round_id' => $completed->id,
                'user_id' => $share->userId,
                'amount_cents' => $share->subtotalCents(),
                'round_up_donation_cents' => $index % 2 === 0 ? 50 : 0,
                'status' => PaymentStatus::Paid,
                'paid_at' => Carbon::parse('2025-10-04 12:00')->addHours($index),
            ]);
            Pickup::create([
                'round_id' => $completed->id,
                'user_id' => $share->userId,
                'pickup_date_id' => $completedPickupDate->id,
                'picked_up_at' => Carbon::parse('2025-10-22 19:00')->addMinutes($index * 15),
            ]);
        }

        foreach ($chosenProposal->items as $item) {
            PriceObservation::create([
                'product_id' => $item->product_id,
                'price_tier_id' => $item->price_tier_id,
                'round_id' => $completed->id,
                'group_id' => $schoeneberg->id,
                'observed_price_cents' => $item->package_price_cents,
                'package_amount' => $item->package_amount,
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

        // Votes — Vorschlag A: alle Betroffenen stimmen zu, nur Jonas blockiert den Reis.
        // Genau der Fall, für den der Lead ihn ausschließen und neu abstimmen lassen kann.
        foreach ($proposalA->items()->with('allocations')->get() as $item) {
            foreach ($item->stakeholderIds() as $voterId) {
                $blocks = $voterId === $jonas->id && $item->product_id === $reis->id;

                ProposalVote::create([
                    'proposal_item_id' => $item->id,
                    'user_id' => $voterId,
                    'value' => ($blocks ? VoteValue::Down : VoteValue::Up)->value,
                    'reason' => $blocks ? 'Ein halber 50-kg-Sack ist mir zu viel, ich will höchstens 5 kg.' : null,
                ]);
            }
        }

        // Vorschlag B: erst ein Teil hat abgestimmt, Linus ist beim Mehl dagegen.
        foreach ($proposalB->items()->with('allocations')->get() as $index => $item) {
            foreach ($item->stakeholderIds() as $voterId) {
                if ($voterId === $aylin->id) {
                    continue; // hat noch nicht abgestimmt
                }

                $blocks = $voterId === $linus->id && $item->product_id === $dinkelmehl->id;

                ProposalVote::create([
                    'proposal_item_id' => $item->id,
                    'user_id' => $voterId,
                    'value' => ($blocks ? VoteValue::Down : VoteValue::Up)->value,
                    'reason' => $blocks ? 'Ein 50-kg-Sack Mehl ist für uns zu viel Lagerfläche.' : null,
                ]);
            }
        }

        // Aktivitäten-Stream
        $this->backdate($active->logActivity('phase_changed', ['from' => 'negotiating', 'to' => 'finalizing']), $active->phase_changed_at);
        $this->backdate($active->logActivity('proposal_published', ['proposal_id' => $proposalA->id, 'title' => $proposalA->title]), $proposalA->published_at);
        $this->backdate($active->logActivity('proposal_published', ['proposal_id' => $proposalB->id, 'title' => $proposalB->title]), $proposalB->published_at);
        $proposalA->logActivity('created', ['title' => $proposalA->title]);
        $proposalB->logActivity('created', ['title' => $proposalB->title]);

        $announcement = app(DraftBuilder::class)->buildDraft($active, NotificationKind::ProposalReady, $marie);
        $announcement->forceFill([
            'sent_at' => Carbon::parse('2026-05-23 17:00'),
            'sent_by_user_id' => $marie->id,
            'recipient_count' => 6,
        ])->save();

        // ---------------- Shopping-Runde (Sommer 2026, Phase shopping) ----------------
        // A group runs one round at a time — in Schöneberg that is the spring
        // order, so this one belongs to Lichtenrade: Tobias leads, Kira has a
        // full cart, Marie just _one_ product.
        $shopping = Round::create([
            'group_id' => $lichtenrade->id,
            'lead_user_id' => $tobias->id,
            'title' => 'Sommer-Bestellung 2026',
            'phase' => RoundPhase::Shopping,
            'shopping_deadline' => '2026-06-15',
            'negotiation_deadline' => '2026-06-22',
            'finalization_deadline' => '2026-07-01',
            'payment_deadline' => '2026-07-08',
            'expected_delivery' => '2026-07-22',
            'pickup_location' => 'Hofgemeinschaft Lichtenrade · Scheune am Kirchhainer Damm',
            'max_participants' => 8,
            'lead_fee_percent' => 2.5,
            'platform_fee_percent' => 1.0,
            'phase_changed_at' => Carbon::parse('2026-05-20 09:00'),
            'description' => 'Großeinkauf für Sommer + Spätsommer. Bitte bis zum 15.06. die Warenkörbe füllen, danach hole ich Hersteller-Preise ein.',
        ]);

        PickupDate::create(['round_id' => $shopping->id, 'scheduled_at' => '2026-07-23 18:00', 'location' => 'Scheune']);
        PickupDate::create(['round_id' => $shopping->id, 'scheduled_at' => '2026-07-25 10:00', 'location' => 'Scheune']);

        foreach ([$tobias, $kira, $marie] as $u) {
            RoundParticipant::create(['round_id' => $shopping->id, 'user_id' => $u->id]);
        }

        $this->cartExact($shopping, $marie, $dinkelmehl, 3);

        $this->cartExact($shopping, $tobias, $dinkelmehl, 8);
        $this->cartExact($shopping, $tobias, $reis, 15);
        $this->cartFlex($shopping, $tobias, $polenta, 5, 10);
        $this->cartExact($shopping, $tobias, $spirelli, 3);
        $this->cartExact($shopping, $tobias, $senf, 6);

        $this->cartFlex($shopping, $kira, $dinkelmehl, 2, 6);
        $this->cartFlex($shopping, $kira, $reis, 8, 20);
        $this->cartExact($shopping, $kira, $hafer, 8);
        $this->cartExact($shopping, $kira, $senf, 4);
        $this->cartFlex($shopping, $kira, $spaghetti, 2, 5);

        $this->backdate($shopping->logActivity('phase_changed', ['from' => 'draft', 'to' => 'shopping']), $shopping->phase_changed_at);

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

    /**
     * Erzeugt ein einfaches SVG-Demo-Bild für ein Produkt und legt es unter
     * storage/app/public/products/{slug}.svg ab. Gibt den relativen image_path zurück.
     */
    private function makeProductImage(Product $product): string
    {
        $palette = match ($product->category) {
            ProductCategory::Grains => ['#fbbf24', '#b45309', '🌾'],
            ProductCategory::Flours => ['#fde68a', '#92400e', '🥖'],
            ProductCategory::Pasta => ['#fb923c', '#9a3412', '🍝'],
            ProductCategory::Legumes => ['#a3e635', '#3f6212', '🫘'],
            ProductCategory::Condiments => ['#fb7185', '#9f1239', '🍯'],
            ProductCategory::Oils => ['#34d399', '#065f46', '🫒'],
            ProductCategory::Produce => ['#86efac', '#166534', '🥔'],
            ProductCategory::Sweeteners => ['#f9a8d4', '#9d174d', '🍬'],
            ProductCategory::Spices => ['#f87171', '#991b1b', '🌶️'],
            ProductCategory::Dairy => ['#7dd3fc', '#075985', '🧀'],
            ProductCategory::Beverages => ['#93c5fd', '#1e40af', '🥤'],
            default => ['#d4d4d8', '#52525b', '📦'],
        };

        [$bgFrom, $bgTo, $emoji] = $palette;
        $initials = htmlspecialchars($product->initials(), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars(Str::limit($product->name, 28), ENT_QUOTES, 'UTF-8');

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600" width="600" height="600">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$bgFrom}"/>
      <stop offset="100%" stop-color="{$bgTo}"/>
    </linearGradient>
    <pattern id="d" width="40" height="40" patternUnits="userSpaceOnUse">
      <circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.18)"/>
    </pattern>
  </defs>
  <rect width="600" height="600" fill="url(#g)"/>
  <rect width="600" height="600" fill="url(#d)"/>
  <text x="300" y="280" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif" font-size="240" font-weight="700" fill="rgba(255,255,255,0.92)">{$emoji}</text>
  <text x="300" y="430" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif" font-size="48" font-weight="700" letter-spacing="2" fill="rgba(255,255,255,0.95)">{$initials}</text>
  <text x="300" y="500" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif" font-size="24" fill="rgba(255,255,255,0.8)">{$name}</text>
</svg>
SVG;

        $path = 'products/'.$product->slug.'.svg';
        Storage::disk('public')->put($path, $svg);

        return $path;
    }

    private function makeUser(string $first, string $last, string $email, string $phone, string $postalCode, int $householdSize, ?string $nickname = null): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => $last,
            'nickname' => $nickname,
            'name' => $first.' '.$last,
            'email' => $email,
            'phone' => $phone,
            'postal_code' => $postalCode,
            'city' => 'Berlin',
            'household_size' => $householdSize,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ]);
    }

    /**
     * Demo groups were founded when their first member joined — not when
     * the seeder ran.
     */
    /**
     * Demo events happened back then, not when seeding — so the history
     * lists them in the right order next to the notifications.
     */
    private function backdate(Activity $activity, ?Carbon $at): void
    {
        if ($at !== null) {
            $activity->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    private function backdateFounding(Group $group): void
    {
        $foundedAt = Carbon::parse($group->members()->min('group_user.joined_at'));

        $group->members()->updateExistingPivot($group->owner_id, ['joined_at' => $foundedAt]);
        $group->activities()->where('action', 'created')->update(['created_at' => $foundedAt, 'updated_at' => $foundedAt]);
    }

    private function attach(Group $group, User $user, GroupRole $role): void
    {
        // Owners are already members (see Group::ensureOwnerMembership()).
        $group->members()->syncWithoutDetaching([
            $user->id => [
                'role' => $role->value,
                'joined_at' => now()->subDays(rand(30, 360)),
            ],
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

        $builder = app(ProposalBuilder::class);

        foreach ($items as [$product, $tier]) {
            if (! $tier instanceof PriceTier) {
                continue;
            }

            $cartItems = $round->cartItems()->with(['product', 'user'])->where('product_id', $product->id)->get();

            if ($cartItems->isNotEmpty()) {
                $builder->createItem($proposal, $product, PackageSpec::fromTier($tier), $cartItems);
            }
        }

        return $proposal;
    }
}
