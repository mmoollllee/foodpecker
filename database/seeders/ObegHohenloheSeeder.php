<?php

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Enums\Visibility;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The organic wholesaler OBEG Hohenlohe with the products of its price
 * list 2022 (valid from 1 May 2022). It needs no demo data, so it can run on
 * a server too, and does nothing when the catalog is already there.
 *
 * Supplier and products are public and belong to no group: every group can
 * order from OBEG, and the moderators of every group may maintain it — like
 * entries a dissolved group leaves to the community.
 *
 * The list's prices are net case prices; they are stored gross — 7 % VAT on
 * food, 19 % on drinks — with the deposit of returnable crates included. The
 * list's deposit table names beer and juice crates only; the breweries'
 * spritzers and lemonades are taken to come in their beer crates. A
 * product the list sells in several sizes — a 25 kg sack and a carton of
 * 12 × 1 kg — becomes one product with several packages, so a proposal can
 * combine them. Staples are ordered by the kilogram and shared in portions
 * of their smallest bag; a single sack, bucket or bag is weighed out.
 * Everything else is counted by the jar, piece or pack and shared one at a
 * time.
 *
 * Left out: honey (not available, no price) and the farm supplies (milking
 * machine cleaner, pallet wrap).
 */
class ObegHohenloheSeeder extends Seeder
{
    /**
     * VAT on food — coffee, tea and milk included — in percent.
     */
    private const VAT_FOOD = 7;

    /**
     * VAT on drinks and their deposit, in percent.
     */
    private const VAT_DRINKS = 19;

    /**
     * Net deposit of a crate with 6 juice bottles, in cents.
     */
    private const JUICE_CRATE_DEPOSIT = 240;

    /**
     * Net deposit of a Neumarkter Lammsbräu crate with 10 bottles, in cents.
     */
    private const LAMMSBRAEU_CRATE_DEPOSIT = 230;

    /**
     * Net deposit of an Engel crate with 15 bottles, in cents.
     */
    private const ENGEL_CRATE_DEPOSIT = 375;

    /**
     * Net deposit of a Härtsfelder crate with 20 bottles, in cents.
     */
    private const HAERTSFELDER_CRATE_DEPOSIT = 310;

    public function run(): void
    {
        if (Supplier::where('slug', 'obeg-hohenlohe')->exists()) {
            $this->command?->warn('OBEG Hohenlohe ist schon angelegt. Nichts zu tun.');

            return;
        }

        DB::transaction(function (): void {
            $obeg = Supplier::create([
                'group_id' => null,
                'visibility' => Visibility::Public,
                'name' => 'OBEG Hohenlohe',
                'slug' => 'obeg-hohenlohe',
                'website' => 'https://www.obeg.de',
                'contact_email' => 'info@obeg.de',
                'contact_phone' => '+49 7935 551300',
                'address' => "Zell 3\n74575 Schrozberg",
                'shipping_notes' => 'Mindestbestellwert 500 € Warenwert netto, kleinere Mengen nur nach Absprache. Frachtkostenbeteiligung zzgl. MwSt.: unter 500 € Warenwert netto 85 €, ab 500 € 60 €, ab 750 € 30 €, ab 1.000 € frachtfrei. Geliefert wird nach Tourenplan, bestellt spätestens zwei Tage vor dem Liefertermin. Das Pfand für Flaschen und Kisten ist in den Preisen enthalten; Europaletten kosten 21,42 € Pfand.',
                'description' => 'OBEG Hohenlohe GmbH & Co. KG (DE-ÖKO-006): Bioland-Getreide aus der Region, selbst gelagert und aufbereitet, dazu Bio-Trockensortiment, Backzutaten und Getränke. Preise brutto nach der Großhandels-Preisliste 2022 (gültig ab 01.05.2022): Listenpreis zzgl. 7 % MwSt. auf Lebensmittel und 19 % auf Getränke, bei Kisten inklusive Pfand. Zahlung innerhalb von 8 Tagen, 2 % Rabatt bei Bankeinzug. Getreide gibt es auf Anfrage auch in 10- oder 5-kg-Gebinden, mit einem Abpackaufschlag von brutto rund 39 bzw. 43 Cent/kg.',
            ]);

            foreach ($this->catalog() as $section) {
                [$category, $description, $products] = $section;
                $vat = $section[3] ?? self::VAT_FOOD;
                $deposit = $section[4] ?? 0;
                $depositNote = $deposit > 0
                    ? 'Inklusive '.number_format($this->gross($deposit, $vat) / 100, 2, ',', '.').' € Pfand für Kiste und Flaschen.'
                    : null;

                foreach ($products as $row) {
                    [$name, $unit, $portionSize, $packages] = $row;

                    $product = Product::create([
                        'supplier_id' => $obeg->id,
                        'group_id' => null,
                        'visibility' => Visibility::Public,
                        'name' => $name,
                        'slug' => Str::slug($name, language: 'de'),
                        'unit' => $unit,
                        'portion_size' => $portionSize,
                        'category' => $category,
                        'description' => implode(' ', array_filter([$row[4] ?? null, $description, $depositNote])),
                    ]);

                    foreach ($packages as $index => [$label, $amount, $articleNumber, $netCents]) {
                        $product->priceTiers()->create([
                            'label' => $label,
                            'article_number' => $articleNumber,
                            'package_amount' => $amount,
                            'price_cents' => $this->gross($netCents + $deposit, $vat),
                            'sort_order' => $index + 1,
                        ]);
                    }
                }
            }
        });
    }

    /**
     * A net amount plus VAT, rounded to the cent.
     */
    private function gross(int $netCents, int $vatPercent): int
    {
        return (int) round($netCents * (100 + $vatPercent) / 100);
    }

    /**
     * The price list in its order, section by section: the category, what
     * all products of the section have in common, the products, and for
     * drinks their VAT and the net deposit per package in cents. A product is
     * its name, unit, portion size, packages and an optional note; a package
     * is its label, its content in the product's unit, the article number
     * and the net case price of the list in cents.
     *
     * @return list<array{0: ProductCategory, 1: string, 2: list<array{0: string, 1: string, 2: float|int, 3: list<array{0: string, 1: float|int, 2: string, 3: int}>, 4?: string}>, 3?: int, 4?: int}>
     */
    private function catalog(): array
    {
        return [
            // ---------------- Cereals, oilseeds and mill products (pages 3–5) ----------------
            [ProductCategory::Grains, 'Bioland-Getreide aus der Region, von der OBEG gelagert und aufbereitet.', [
                ['Dinkel', 'kg', 1, [['Karton 12 × 1 kg', 12, 'odi1', 3156], ['25 kg Sack', 25, 'odis', 4325]]],
                ['Fünfkornmischung', 'kg', 1, [['Karton 12 × 1 kg', 12, 'ofk1', 3108], ['25 kg Sack', 25, 'ofks', 4225]], 'Mischung aus Dinkel, Weizen, Roggen, Nacktgerste und Nackthafer.'],
                ['Grünkern', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'ogk0', 3264], ['Karton 12 × 1 kg', 12, 'ogk1', 5436], ['25 kg Sack', 25, 'ogks', 9075]]],
                ['Nacktgerste', 'kg', 1, [['Karton 12 × 1 kg', 12, 'ong1', 3060], ['25 kg Sack', 25, 'ongs', 4125]]],
                ['Nackthafer', 'kg', 1, [['Karton 12 × 1 kg', 12, 'onh1', 2940], ['25 kg Sack', 25, 'onhs', 3875]]],
                ['Waldstaudenroggen', 'kg', 0.5, [['25 kg Sack', 25, 'owsrs', 4125]]],
                ['Roggen', 'kg', 1, [['Karton 12 × 1 kg', 12, 'oro1', 2076], ['25 kg Sack', 25, 'oros', 2075]]],
                ['Weizen', 'kg', 1, [['Karton 12 × 1 kg', 12, 'owe1', 2232], ['25 kg Sack', 25, 'owes', 2400]]],
                ['Einkorn', 'kg', 1, [['Karton 12 × 1 kg', 12, 'oek1', 3744], ['25 kg Sack', 25, 'oeks', 5550]]],
                ['Emmer', 'kg', 1, [['Karton 12 × 1 kg', 12, 'oem1', 3720], ['25 kg Sack', 25, 'oems', 5500]]],
                ['Hartweizen', 'kg', 1, [['Karton 12 × 1 kg', 12, 'ohw1', 2760], ['25 kg Sack', 25, 'ohws', 3500]]],
            ]],
            [ProductCategory::Grains, 'Ersatz für Kamut. Der Sack ist Bioland-Getreide der OBEG, die 1-kg-Packungen sind Bio (kbA).', [
                ['Khorasan Urweizen (Urmut)', 'kg', 1, [['Karton 12 × 1 kg', 12, 'kbka1', 3636], ['25 kg Sack', 25, 'ouwes', 5325]]],
            ]],
            [ProductCategory::Grains, 'Bioland bzw. demeter, Herkunft Deutschland.', [
                ['Buchweizen', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'obw0', 3468], ['Karton 12 × 1 kg', 12, 'obw1', 5844], ['25 kg Sack', 25, 'obws', 9925]]],
                ['Leinsamen', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'ols0', 3036], ['Karton 12 × 1 kg', 12, 'ols1', 4980], ['25 kg Sack', 25, 'olss', 8125]]],
                ['Sonnenblumenkerne', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'osb0', 3300], ['Karton 12 × 1 kg', 12, 'osb1', 5520], ['25 kg Sack', 25, 'osbs', 9250]]],
                ['Amaranth', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'demam0', 3540], ['25 kg Sack', 25, 'demams', 10250]]],
                ['Kürbiskerne dunkelgrün', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'oküd0', 6708], ['25 kg Sack', 25, 'oküdds', 23450]]],
                ['Hirse', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'ohi0', 3144], ['Karton 12 × 1 kg', 12, 'ohi1', 5208], ['25 kg Sack', 25, 'ohis', 8600]]],
                ['Quinoa', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'oqui0', 5928], ['25 kg Sack', 25, 'oquis', 20175]]],
            ]],
            [ProductCategory::Legumes, 'Bioland bzw. demeter, Herkunft Deutschland.', [
                ['Linsen', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'oli0', 4140], ['Karton 12 × 1 kg', 12, 'oli1', 7200], ['25 kg Sack', 25, 'olis', 12750]]],
            ]],
            [ProductCategory::Grains, 'Bio (kbA). Importware: Herkunftsland und Preis können sich kurzfristig ändern.', [
                ['Langkornreis Vollkorn', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'kbre0', 2784], ['25 kg Sack', 25, 'kbres', 7100]], 'Herkunft Italien.'],
                ['Rundkornreis Vollkorn', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'kbrer0', 2616], ['25 kg Sack', 25, 'kbrers', 6400]], 'Herkunft Italien.'],
                ['Sesam', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'kbse0', 3360], ['Karton 12 × 1 kg', 12, 'kbse1', 5640], ['25 kg Sack', 25, 'kbses', 9500]], 'Herkunft Äthiopien.'],
                ['Haselnüsse', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'kbhn0', 7152], ['25 kg Sack', 25, 'kbhns', 25300]], 'Herkunft Türkei.'],
                ['Mohn', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'kbmo0', 5292], ['25 kg Sack', 25, 'kbmos', 17550]], 'Herkunft Türkei.'],
                ['Chia', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'kbchi0', 6348], ['25 kg Sack', 25, 'kbchis', 21950]], 'Herkunft Bolivien.'],
            ]],
            [ProductCategory::Grains, 'Bio (kbA).', [
                ['Mandeln', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'kbma0', 9576], ['25 kg Sack', 25, 'kbma', 35375]], 'Herkunft Italien.'],
            ]],
            [ProductCategory::Produce, 'Bio (kbA). Importware: Herkunftsland und Preis können sich kurzfristig ändern.', [
                ['Sultaninen', 'kg', 0.5, [['12,5 kg Karton', 12.5, 'kbsuk', 4200]], 'Herkunft Iran.'],
            ]],
            [ProductCategory::Produce, 'Bio (kbA) von Naturata.', [
                ['Rosinen', 'kg', 0.5, [['Karton 5 × 500 g', 2.5, 'kbro0', 2120]]],
            ]],
            [ProductCategory::Grains, 'Bioland, von der OBEG.', [
                ['Dinkelino (Dinkelreis)', 'kg', 0.5, [['Karton 6 × 500 g', 3, 'odr', 1728]]],
            ]],
            [ProductCategory::Grains, 'Bioland-Getreideflocken von der OBEG.', [
                ['Fünfkornflocken', 'kg', 0.5, [['25 kg Sack', 25, 'offs', 4650]]],
                ['Dinkelflocken', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'odf0', 3708], ['25 kg Sack', 25, 'odfs', 5925]]],
                ['Gerstenflocken', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'ogf0', 1836], ['25 kg Sack', 25, 'ogfs', 3150]]],
                ['Haferflocken, Großblatt', 'kg', 0.5, [['Karton 6 × 500 g', 3, 'ohfg0', 1200], ['25 kg Sack', 25, 'ohfgs', 4275]]],
                ['Haferflocken, Kleinblatt', 'kg', 0.5, [['Karton 6 × 500 g', 3, 'ohfk0', 1200], ['25 kg Sack', 25, 'ohfks', 4275]]],
                ['Roggenflocken', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'orf0', 1728], ['25 kg Sack', 25, 'orfs', 2675]]],
                ['Weizenflocken', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'owf0', 1752], ['25 kg Sack', 25, 'owfs', 2800]]],
            ]],
            [ProductCategory::Grains, 'Bio (kbA).', [
                ['Hirseflocken', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'kbhif0', 3336], ['20 kg Sack', 20, 'kbhifs20', 7520]]],
            ]],
            [ProductCategory::Flours, 'Bioland-Auszugsmehl von der OBEG.', [
                ['Dinkelmehl Type 630', 'kg', 1, [['Karton 12 × 1 kg', 12, 'odm61', 3456], ['Karton 4 × 2,5 kg', 10, 'odm625', 2440], ['Karton 2 × 5 kg', 10, 'odm65', 2280], ['25 kg Sack', 25, 'odm6s', 4950]]],
                ['Dinkelmehl Type 1050', 'kg', 1, [['Karton 12 × 1 kg', 12, 'odm11', 3444], ['Karton 4 × 2,5 kg', 10, 'odm125', 2432], ['Karton 2 × 5 kg', 10, 'odm15', 2270], ['25 kg Sack', 25, 'odm1s', 4925]]],
                ['Roggenmehl Type 1150', 'kg', 1, [['Karton 12 × 1 kg', 12, 'orm11', 2412], ['Karton 4 × 2,5 kg', 10, 'orm125', 1524], ['Karton 2 × 5 kg', 10, 'orm15', 1350], ['25 kg Sack', 25, 'orm1s', 2550]]],
                ['Weizenmehl Type 405', 'kg', 1, [['Karton 12 × 1 kg', 12, 'owm41', 2496], ['Karton 4 × 2,5 kg', 10, 'owm425', 1640], ['Karton 2 × 5 kg', 10, 'owm45', 1480], ['25 kg Sack', 25, 'owm4s', 2950]]],
                ['Weizenmehl Type 550', 'kg', 1, [['Karton 12 × 1 kg', 12, 'owm51', 2496], ['Karton 4 × 2,5 kg', 10, 'owm525', 1640], ['Karton 2 × 5 kg', 10, 'owm55', 1480], ['25 kg Sack', 25, 'owm5s', 2950]]],
                ['Weizenmehl Type 1050', 'kg', 1, [['Karton 12 × 1 kg', 12, 'owm11', 2484], ['Karton 4 × 2,5 kg', 10, 'owm125', 1632], ['Karton 2 × 5 kg', 10, 'owm15', 1470], ['25 kg Sack', 25, 'owm1s', 2925]]],
                ['Spätzlesmehl', 'kg', 1, [['Karton 12 × 1 kg', 12, 'osm1', 3444], ['25 kg Sack', 25, 'osms', 4925]]],
            ]],
            [ProductCategory::Flours, 'Von der OBEG.', [
                ['Hartweizengrieß, weiß', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'kbhgw0', 2100], ['25 kg Sack', 25, 'kbhgws', 4250]]],
                ['Hartweizengrieß, Vollkorn', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'kbhgv0', 1956], ['25 kg Sack', 25, 'kbhgvs', 3625]]],
                ['Dinkelgrieß', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'odgv0', 2676], ['25 kg Sack', 25, 'odgvs', 6625]]],
                ['Maisgrieß', 'kg', 0.5, [['25 kg Sack', 25, 'bomgs', 3775]]],
                ['Haferkleie', 'kg', 0.5, [['25 kg Sack', 25, 'ohks', 7975]]],
            ]],
            [ProductCategory::Flours, 'Eigene Bioland-Backzutat der OBEG, Anwendungstipps und Rezepte gibt es auf Nachfrage.', [
                ['Weizenkraft', 'kg', 0.1, [['1 kg Packung', 1, 'owkr1', 695]]],
                ['Dinkelkraft', 'kg', 0.1, [['1 kg Packung', 1, 'odkr1', 795]]],
                ['Süßlupinenmehl', 'kg', 0.1, [['500 g Packung', 0.5, 'kbslm0', 260]]],
                ['Aromamalzmehl hell', 'kg', 0.1, [['500 g Packung', 0.5, 'weamh0', 192]]],
            ]],

            // ---------------- Muesli, pasta, sauces and potato products (pages 6–7) ----------------
            [ProductCategory::Grains, 'Bioland, vom Kornkreis.', [
                ['Dinkel gepufft (Dinkelpops)', 'pkg', 1, [['Karton 10 × 200 g', 10, 'kkdp', 2640]], 'Mit Honig gesüßt.'],
            ]],
            [ProductCategory::Grains, 'Bio-Müsli von der Bohlsener Mühle.', [
                ['Schoko-Crunchy Müsli', 'pkg', 1, [['Karton 6 × 400 g', 6, 'bomüsc', 1350]]],
                ['Schoko-Aktiv Müsli', 'pkg', 1, [['Karton 6 × 500 g', 6, 'bomüst', 1794]]],
                ['Feine Früchte Müsli', 'pkg', 1, [['Karton 6 × 500 g', 6, 'bomüfm', 1794]]],
                ['Sieben Beeren Müsli', 'pkg', 1, [['Karton 6 × 450 g', 6, 'bomüsb', 1794]]],
                ['Joghurt-Zitrone Crunchy Müsli', 'pkg', 1, [['Karton 6 × 425 g', 6, 'bomüjz', 1590]]],
                ['Mandel-Nuss Crunchy Müsli', 'pkg', 1, [['Karton 6 × 425 g', 6, 'bomümn', 1590]]],
                ['Hafer Crunchy Müsli', 'pkg', 1, [['Karton 6 × 400 g', 6, 'bomüha', 1350]]],
            ]],
            [ProductCategory::Grains, 'Bio (kbA) von Eden.', [
                ['Choco-Balls', 'pkg', 1, [['Karton 12 × 375 g', 12, 'evcb', 3852]]],
                ['Cornflakes', 'pkg', 1, [['Karton 10 × 375 g', 10, 'evcf', 2690]]],
            ]],
            [ProductCategory::Flours, 'Bioland, von Werz.', [
                ['Polenta (Maisgrieß), glutenfrei', 'kg', 0.5, [['Karton 10 × 500 g', 5, 'wemg0', 1430]]],
            ]],
            [ProductCategory::Flours, 'Bio (kbA) von Werz.', [
                ['Braunhirse gemahlen, glutenfrei', 'kg', 0.5, [['Karton 5 × 500 g', 2.5, 'webhm', 1590]]],
                ['Buchweizen-Vollkornmehl, glutenfrei', 'kg', 1, [['Karton 5 × 1 kg', 5, 'webwm', 2510]]],
                ['4-Korn-Vollkornmehl, glutenfrei', 'kg', 1, [['Karton 5 × 1 kg', 5, 'wevkvm', 2325]]],
            ]],
            [ProductCategory::Grains, 'Bio (kbA) von Werz.', [
                ['Braunhirse ganz, keimfähig, glutenfrei', 'kg', 0.5, [['Karton 5 × 500 g', 2.5, 'webh', 1360]]],
            ]],
            [ProductCategory::Pasta, 'Bioland-Nudeln vom Kornkreis.', [
                ['Dinkel-Lupinen-Nudeln (Lupinelle)', 'kg', 0.35, [['Karton 8 × 350 g', 2.8, 'bindl', 2344]]],
                ['Linsen-Dinkel-Nudeln (Linselle)', 'kg', 0.35, [['Karton 8 × 350 g', 2.8, 'binld', 2344]]],
                ['Dinkel Spiralnudeln, ohne Ei, hell', 'kg', 0.5, [['Karton 10 × 500 g', 5, 'binsrdw', 2930]]],
                ['Dinkel Bandnudeln, ohne Ei, hell', 'kg', 0.5, [['Karton 6 × 500 g', 3, 'binbadw', 1758]]],
                ['Dinkel Spaghetti, ohne Ei, hell', 'kg', 0.5, [['Karton 10 × 500 g', 5, 'binspdw', 2670]]],
                ['Emmerlinge, ohne Ei, Vollkorn', 'kg', 0.4, [['Karton 8 × 400 g', 3.2, 'binem', 2672]]],
                ['Einkorn Glöckchen, Vollkorn', 'kg', 0.4, [['Karton 6 × 400 g', 2.4, 'bineg', 2004]]],
                ['Hartweizen Bauernspätzle, mit Ei, weiß', 'kg', 0.5, [['Karton 10 × 500 g', 5, 'binsäew', 3340]]],
                ['Hartweizen Bandnudel, mit Ei, weiß', 'kg', 0.5, [['Karton 10 × 500 g', 5, 'binbaew', 3340]]],
            ]],
            [ProductCategory::Pasta, 'Bioland-Nudeln vom Biolandhof Deeg, Weikersheim.', [
                ['Dinkel-Bandnudeln Vollkorn, ohne Ei', 'kg', 0.5, [['Karton 10 × 500 g', 5, 'debad', 2550]]],
                ['Dinkel-Jägerspätzle Vollkorn, ohne Ei', 'kg', 0.5, [['Karton 10 × 500 g', 5, 'dejäd', 2550]]],
                ['Dinkel-Spaghetti Vollkorn, ohne Ei', 'kg', 0.5, [['Karton 15 × 500 g', 7.5, 'despd', 4140]]],
                ['Dinkel-Spiralen Vollkorn, ohne Ei', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'desrd', 3060]]],
                ['Dinkel-Suppennudeln Vollkorn, ohne Ei', 'kg', 0.5, [['Karton 10 × 500 g', 5, 'desud', 2550]]],
                ['Dinkel-Rigatoni Vollkorn, ohne Ei', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'derid', 3060]]],
            ]],
            [ProductCategory::Pasta, 'Aus Hartweizengrieß, ohne Ei. Bio (kbA) von Byodo.', [
                ['Spaghetti semola', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'bysp', 1596], ['5 kg Großpackung', 5, 'bysp5', 1092]]],
                ['Spiralen semola', 'kg', 0.5, [['5 kg Großpackung', 5, 'bysr5', 1092]]],
                ['Hörnchen semola', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'byhö', 1992], ['5 kg Großpackung', 5, 'byhö5', 1124]]],
                ['Farfalle semola', 'kg', 0.5, [['5 kg Großpackung', 5, 'byfa5', 1124]]],
                ['Rigatoni semola', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'byri', 1992]]],
                ['Penne semola', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'bype', 1596]]],
                ['Volanti semola (gedrehte Hütchen)', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'byvo', 1992]]],
                ['Lasagneplatten', 'kg', 0.25, [['Karton 12 × 250 g', 3, 'bylp', 1992]]],
                ['Bunte Buchstabennudeln', 'kg', 0.25, [['Karton 12 × 250 g', 3, 'bybn', 1596]]],
                ['Filini semola (Suppennudeln)', 'kg', 0.25, [['Karton 12 × 250 g', 3, 'bysu', 1104]]],
            ]],
            [ProductCategory::Pasta, 'Aus Hartweizengrieß, ohne Ei. demeter, von Naturata.', [
                ['Spirelli hell', 'kg', 0.5, [['Karton 9 × 500 g', 4.5, 'nasrhw', 1899]]],
                ['Maccaroni lang', 'kg', 0.5, [['Karton 10 × 500 g', 5, 'namhw', 2110]]],
            ]],
            [ProductCategory::Pasta, 'Vollkorn-Nudeln für Allergiker, von Werz.', [
                // The list prints 18,08 €, the price of the buckwheat noodles below — 8 packs at 1,90 € make 15,20 €.
                ['Hirse Vollkorn-Nudeln, glutenfrei', 'kg', 0.2, [['Karton 8 × 200 g', 1.6, 'wehnv', 1520]]],
                ['Buchweizen Vollkorn-Nudeln, glutenfrei', 'kg', 0.2, [['Karton 8 × 200 g', 1.6, 'webwnv', 1808]]],
            ]],
            [ProductCategory::Condiments, 'Bio (kbA) von Byodo.', [
                ['Pesto Genovese', 'glas', 1, [['Karton 6 × 125 g', 6, 'bypg', 1344]]],
                ['Pesto Arrabiata', 'glas', 1, [['Karton 6 × 125 g', 6, 'bypa', 1344]]],
                ['Pesto Rosso', 'glas', 1, [['Karton 6 × 125 g', 6, 'bypr', 1344]]],
            ]],
            [ProductCategory::Condiments, 'Bio (kbA) von Eden.', [
                ['Vegetarische Bolognese', 'glas', 1, [['Karton 6 × 365 g', 6, 'bfvb', 1158]]],
                ['TomaTina Kinder-Tomatensauce', 'glas', 1, [['Karton 6 × 375 g', 6, 'bfts', 1158]]],
            ]],
            [ProductCategory::Legumes, 'Vom Biolandhof Klein, Wertheim.', [
                ['Tapas Lupinos', 'glas', 1, [['Karton 6 × 250 g', 6, 'otl', 1398]], 'Süßlupinen, eingelegt in Salzwasser.'],
                ['Grano Lupino', 'glas', 1, [['Karton 6 × 250 g', 6, 'ogl', 1398]], 'Süßlupinengranulat, eingelegt in Salzwasser.'],
                ['Falafel „Arabica“', 'pkg', 1, [['Karton 6 × 250 g', 6, 'bofa', 1848]], 'Mischung für Kichererbsenbratlinge.'],
            ]],
            [ProductCategory::Other, 'Bio (kbA) von Eden.', [
                ['Kartoffelpüree, locker und flockig', 'pkg', 1, [['Karton 10 × 160 g', 10, 'bfkp', 1850]]],
                ['Kartoffelknödel, halb & halb', 'pkg', 1, [['Karton 10 × 230 g', 10, 'bfkk', 2200]]],
            ]],

            // ---------------- Crispbread, snacks and sweets (page 8) ----------------
            [ProductCategory::Other, 'demeter, von Naturata.', [
                ['Vollkorn-Knäcke Delikatess', 'pkg', 1, [['Karton 12 × 250 g', 12, 'nakbd', 2028]]],
                ['Vollkorn-Knäcke Dinkel', 'pkg', 1, [['Karton 12 × 250 g', 12, 'nakbs', 2028]]],
            ]],
            [ProductCategory::Other, 'Bio (kbA) von Naturata.', [
                ['Erdnusskerne, geröstet und gesalzen', 'pkg', 1, [['Karton 8 × 125 g', 8, 'naeg', 1400]]],
            ]],
            [ProductCategory::Other, 'demeter-Knabbergebäck von Erdmannhauser.', [
                ['Dinkel-Grissini', 'pkg', 1, [['Karton 7 × 100 g', 7, 'ergd', 889]]],
                ['Käse-Grissini mit Emmentaler und Parmesan', 'pkg', 1, [['Karton 7 × 100 g', 7, 'ergk', 889]]],
                ['Pizza-Grissini', 'pkg', 1, [['Karton 7 × 100 g', 7, 'ergp', 889]]],
                ['Einkorn-Spritzgebäck', 'pkg', 1, [['Karton 6 × 200 g', 6, 'eresg', 1362]]],
                ['Vollkornsticks aus Dinkel', 'pkg', 1, [['Karton 12 × 100 g', 12, 'erst', 1380]]],
                ['Dinkel-Sesam Knusperbrezeln', 'pkg', 1, [['Karton 12 × 125 g', 12, 'ersb', 1728]]],
            ]],
            [ProductCategory::Other, 'Bio-Kekse von Wikana.', [
                // The list prints the price of 6 packs for these cartons of 12 — 12 packs at the listed unit price it is.
                ['Dinkel Butterkeks', 'pkg', 1, [['Karton 12 × 200 g', 12, 'kodbk', 1680]]],
                ['Dinkel Schoko Butterkeks', 'pkg', 1, [['Karton 12 × 200 g', 12, 'kosbk', 1992]]],
                ['Weizen Doppelkeks mit Kakaocreme', 'pkg', 1, [['Karton 12 × 330 g', 12, 'kodok', 1980]]],
            ]],
            [ProductCategory::Other, 'Bioland-Kekse von der Bohlsener Mühle.', [
                ['Crunch Keks (Schoko Kracher)', 'pkg', 1, [['Karton 6 × 150 g', 6, 'bock', 912]]],
                ['Dinkel-Kinderkeks', 'pkg', 1, [['Karton 6 × 150 g', 6, 'kokk', 912]]],
                ['Mini Cookie Schoko Orange', 'pkg', 1, [['Karton 6 × 125 g', 6, 'boscho', 912]]],
            ]],
            [ProductCategory::Other, 'Bio (kbA) von Byodo.', [
                ['Schokoreiswaffeln mit Vollmilchüberzug', 'pkg', 1, [['Karton 12 × 65 g', 12, 'byrws', 1272]]],
            ]],
            [ProductCategory::Other, 'Bio-Schokoriegel Fairetta von der GEPA.', [
                ['Haselnusswaffeln', 'stk', 1, [['Karton 25 × 20 g', 25, 'genw', 1850]]],
                ['Schoko Waffeln', 'stk', 1, [['Karton 25 × 30 g', 25, 'gesw', 2300]]],
                ['Vollmilchriegel gefüllt mit Milchcreme', 'stk', 1, [['Karton 20 × 37,5 g', 20, 'gesrm', 1800]]],
            ]],
            [ProductCategory::Other, 'Bio-Tafelschokolade (kbA) von der GEPA.', [
                ['Schokolade Vollmilch pur', 'stk', 1, [['Karton 10 × 100 g', 10, 'geschv', 1610]]],
                ['Schokolade Ganze Nuss', 'stk', 1, [['Karton 10 × 100 g', 10, 'geschn', 2050]]],
                ['Schokolade Mascobado Blanc', 'stk', 1, [['Karton 10 × 100 g', 10, 'geschw', 1790]], 'Weiße Schokolade.'],
                ['Schokolade Minze', 'stk', 1, [['Karton 10 × 100 g', 10, 'geschpm', 1790]], 'Gefüllte Bitterschokolade mit Mintcreme.'],
                ['Schokolade Praline', 'stk', 1, [['Karton 10 × 100 g', 10, 'geschpp', 2050]], 'Gefüllte Vollmilchschokolade.'],
                ['Schokolade Grand Noir Zartbitter', 'stk', 1, [['Karton 10 × 100 g', 10, 'geschz', 2060]], 'Mit 70 % Kakao.'],
            ]],
            [ProductCategory::Other, 'Bioland, Lisas Kesselchips.', [
                ['Chips Meersalz', 'pkg', 1, [['Karton 12 × 125 g', 12, 'likc', 2232]]],
                ['Chips Rosmarin & Meersalz', 'pkg', 1, [['Karton 12 × 125 g', 12, 'likcr', 2232]]],
                ['Chips Sauerrahm & Frühlingszwiebel', 'pkg', 1, [['Karton 12 × 125 g', 12, 'likcs', 2232]]],
                ['Chips Curry', 'pkg', 1, [['Karton 12 × 125 g', 12, 'likcc', 2232]]],
            ]],
            [ProductCategory::Other, 'Bio, von Kreßberger aus Schwäbisch Hall.', [
                ['Fruchtsaft-Gummi Apfel', 'pkg', 1, [['Karton 12 × 150 g', 12, 'krap', 2316]]],
                ['Fruchtsaft-Gummi Birne', 'pkg', 1, [['Karton 12 × 150 g', 12, 'krbi', 2316]]],
                ['Fruchtsaft-Gummi Kirsche', 'pkg', 1, [['Karton 12 × 150 g', 12, 'krki', 2316]]],
                ['Fruchtsaft-Gummi Holunder', 'pkg', 1, [['Karton 12 × 150 g', 12, 'krho', 2316]]],
                ['Fruchtsaft-Gummi Aronia', 'pkg', 1, [['Karton 12 × 150 g', 12, 'krar', 2316]]],
            ]],

            // ---------------- Sugar, syrup and spreads (page 9) ----------------
            [ProductCategory::Sweeteners, 'Bio (kbA) von der GEPA.', [
                ['Mascobado unraffinierter Vollrohrzucker', 'kg', 1, [['Karton 5 × 1 kg', 5, 'gezu1', 3430]]],
            ]],
            [ProductCategory::Sweeteners, 'Bio (kbA).', [
                ['Rohrohrzucker', 'kg', 0.5, [['25 kg Sack', 25, 'kbzus25', 4875]], 'Weißlicher Zucker.'],
            ]],
            [ProductCategory::Sweeteners, 'Bioland.', [
                ['Rübenzucker', 'kg', 1, [['Karton 10 × 1 kg', 10, 'szrz', 2550], ['25 kg Sack', 25, 'szrzs25', 5075]]],
            ]],
            [ProductCategory::Sweeteners, 'Bio (kbA) von Naturata.', [
                ['Puderzucker aus Rohrohrzucker', 'kg', 0.2, [['Karton 10 × 200 g', 2, 'napz', 1400]]],
                ['Ahornsirup Grad A', 'stk', 1, [['Karton 6 × 250 g', 6, 'naas', 2958]]],
            ]],
            [ProductCategory::Sweeteners, 'Aus Bioland-Rübenzucker, von Landmacher.', [
                ['Gelierzucker', 'kg', 0.5, [['Karton 10 × 500 g', 5, 'bvgz', 2140]]],
            ]],
            [ProductCategory::Sweeteners, 'Bio, von Arche.', [
                ['Fruchtgel', 'pkg', 1, [['Karton 18 × 22 g', 18, 'arfg', 1836]]],
                ['Fruchtgel (Großpackung)', 'kg', 0.1, [['1 kg Packung', 1, 'arfg1', 2335]]],
            ]],
            [ProductCategory::Condiments, 'Vegan. Bio (kbA) von Zwergenwiese.', [
                ['Meditom Aufstrich', 'glas', 1, [['Karton 6 × 160 g', 6, 'zwme', 1098]], 'Mediterranes Gemüse mit Tomate.'],
                ['Sendi Aufstrich', 'glas', 1, [['Karton 6 × 160 g', 6, 'zwse', 1098]], 'Mit Senf und Dill.'],
                ['Zwergen Streich Aufstrich', 'glas', 1, [['Karton 6 × 180 g', 6, 'zwzs', 1116]], 'Für Kinder.'],
                ['Mango Curry Aufstrich', 'glas', 1, [['Karton 6 × 180 g', 6, 'zwmc', 1116]]],
                ['Rote Bete-Meerrettich Aufstrich', 'glas', 1, [['Karton 6 × 180 g', 6, 'zwrb', 1116]]],
            ]],
            [ProductCategory::Condiments, 'Bio (kbA) von Rapunzel.', [
                ['Samba Haselnusscreme', 'glas', 1, [['Karton 6 × 250 g', 6, 'bfsa', 2490]]],
            ]],
            [ProductCategory::Condiments, 'Annes Feinste, Bio von Maintal.', [
                ['Erdbeer Konfitüre extra', 'glas', 1, [['Karton 6 × 225 g', 6, 'bifae', 1188]]],
                ['Himbeer Konfitüre extra', 'glas', 1, [['Karton 6 × 225 g', 6, 'bifah', 1254]]],
                ['Sauerkirsch Konfitüre extra', 'glas', 1, [['Karton 6 × 225 g', 6, 'bifas', 1188]]],
                ['Schwarze Johannisbeer Konfitüre extra', 'glas', 1, [['Karton 6 × 225 g', 6, 'bifaj', 1188]]],
                ['Aprikosen Konfitüre extra', 'glas', 1, [['Karton 6 × 225 g', 6, 'bifaa', 1188]]],
                ['Hagebutten Konfitüre extra', 'glas', 1, [['Karton 6 × 225 g', 6, 'bifaha', 1188]]],
                ['Heidelbeer Konfitüre extra', 'glas', 1, [['Karton 6 × 225 g', 6, 'bifahe', 1224]]],
                ['Holunderbeer Gelee extra', 'glas', 1, [['Karton 6 × 225 g', 6, 'bifaho', 1188]]],
            ]],

            // ---------------- Mustard, vinegar, oil and seasoning (pages 9–10) ----------------
            [ProductCategory::Condiments, 'Bioland, von Byodo.', [
                ['Senf mittelscharf', 'glas', 1, [['Karton 6 × 200 ml', 6, 'bysem', 792]]],
                ['Senf Dijon (scharf)', 'glas', 1, [['Karton 6 × 125 ml', 6, 'bysed', 990]]],
                ['Senf süß', 'glas', 1, [['Karton 6 × 200 ml', 6, 'bysesü', 1074]]],
                ['Feigen-Senf', 'glas', 1, [['Karton 6 × 125 ml', 6, 'bysef', 1164]], 'Passt z. B. zum Käse.'],
                ['Senf mittelscharf (Tube)', 'stk', 1, [['Karton 8 × 100 ml', 8, 'bysemt', 984]]],
                ['Senf mittelscharf (Eimer)', 'kg', 0.5, [['1 kg Eimer', 1, 'bysem1', 358], ['5 kg Eimer', 5, 'bysem5', 1680]]],
                ['Meerrettich', 'glas', 1, [['Karton 6 × 100 g', 6, 'bymr', 1302]], 'Kühl lagern (4–6 °C).'],
            ]],
            [ProductCategory::Oils, 'Bioland, von Burkhardt.', [
                ['Apfelessig', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'bhae', 1380]], '5 % Säure.'],
                ['Apfelessig (Kanister)', 'kg', 0.5, [['5 kg Kanister', 5, 'bhae5', 1231]], '5 % Säure.'],
                ['Apfelessig naturtrüb', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'bhaet', 1380]], '5 % Säure.'],
            ]],
            [ProductCategory::Oils, 'Bio (kbA) von Byodo.', [
                ['Aceto Balsamico', 'stk', 1, [['Karton 6 × 0,5 l', 6, 'bybe', 2154]], '6 % Säure.'],
                ['Bratöl aus High-Oleic-Sonnenblumen', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'byöbr', 2604]]],
                ['Bratolive', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'byöbro', 3996]]],
            ]],
            [ProductCategory::Oils, 'demeter, von Epikouros.', [
                ['Weißweinessig', 'stk', 1, [['Karton 6 × 0,5 l', 6, 'bywe', 1608]], '6 % Säure.'],
                ['Weißweinessig (Kanister)', 'kg', 0.5, [['5 kg Kanister', 5, 'bywe5', 1639]], '6 % Säure.'],
            ]],
            [ProductCategory::Oils, 'Bioland.', [
                ['Sonnenblumenöl nativ (Kanister)', 'l', 0.5, [['5 l Kanister', 5, 'osö5', 2164]]],
                ['Sonnenblumenöl hocherhitzbar', 'l', 0.5, [['10 l Bag-in-Box', 10, 'kbsö10bb', 4011]], 'Zum Braten, Frittieren und Einfetten.'],
            ]],
            [ProductCategory::Oils, 'demeter.', [
                ['Sonnenblumenöl nativ', 'stk', 1, [['Karton 6 × 0,5 l', 6, 'osö0', 2112]]],
            ]],
            [ProductCategory::Oils, 'Bio (kbA) aus Griechenland.', [
                ['Olivenöl nativ extra, mild', 'stk', 1, [['Karton 6 × 0,5 l', 6, 'byöop', 3630]]],
                ['Olivenöl nativ (Kanister)', 'l', 0.5, [['5 l Kanister', 5, 'kboö5', 3866]]],
            ]],
            [ProductCategory::Oils, 'Bioland, vom Kornkreis.', [
                ['Rapsöl nativ', 'stk', 1, [['Karton 4 × 0,25 l', 4, 'byör', 1600]]],
                ['Hanföl', 'stk', 1, [['Karton 4 × 0,25 l', 4, 'koöh', 3208]]],
            ]],
            [ProductCategory::Oils, 'Bio.', [
                ['Rapsöl nativ (Kanister)', 'l', 0.5, [['10 l Kanister', 10, 'kbrö10', 3608]]],
            ]],
            [ProductCategory::Condiments, '50 % Fett.', [
                ['Salatmayonnaise ohne Ei', 'glas', 1, [['Karton 6 × 250 ml', 6, 'bfsm', 1218]]],
            ]],
            [ProductCategory::Spices, 'Bio (kbA).', [
                ['Würzl flüssige Allzweckwürze', 'stk', 1, [['Karton 6 × 85 ml', 6, 'bfwüfh', 1014]]],
                ['Würzl klare Gemüsebrühe', 'kg', 0.25, [['Karton 7 × 250 g', 1.75, 'bfwün', 1477], ['10 kg Eimer', 10, 'bfwüe', 11575]], 'Im Nachfüllbeutel oder im Eimer.'],
            ]],
            [ProductCategory::Legumes, 'Bio, von Eden.', [
                ['Linseneintopf', 'stk', 1, [['Karton 5 × 400 g', 5, 'evle', 980]]],
                ['Erbseneintopf', 'stk', 1, [['Karton 5 × 400 g', 5, 'evee', 980]]],
            ]],

            // ---------------- Preserves, olives and tomato products (page 11) ----------------
            [ProductCategory::Produce, 'demeter, von Schweizer.', [
                ['Brechbohnen', 'glas', 1, [['Karton 6 × 370 ml', 6, 'schwbo', 684]]],
                ['Erbsen', 'glas', 1, [['Karton 6 × 370 ml', 6, 'schwe', 846]]],
                ['Erbsen mit Karotten', 'glas', 1, [['Karton 6 × 370 ml', 6, 'schwem', 846]]],
                ['Rote Bete in Scheiben', 'glas', 1, [['Karton 6 × 370 ml', 6, 'schwrb', 684]]],
                ['Rotkohl', 'glas', 1, [['Karton 6 × 720 ml', 6, 'schwrk', 804]]],
                ['Sauerkraut', 'glas', 1, [['Karton 6 × 720 ml', 6, 'schwsa', 768]]],
                ['Zuckermais', 'glas', 1, [['Karton 6 × 370 ml', 6, 'schwm', 888]]],
            ]],
            [ProductCategory::Produce, 'Bioland, von Laurer.', [
                ['Gewürzgurken', 'glas', 1, [['Karton 12 × 670 ml', 12, 'lagu', 2652]]],
                ['Paprika, eingelegt', 'glas', 1, [['Karton 12 × 520 ml', 12, 'lapa', 2652]]],
                ['Gurkentopf', 'glas', 1, [['Karton 6 × 1550 ml', 6, 'lagut', 2370]]],
                ['Peperoni scharf (Hot Pepinos)', 'glas', 1, [['Karton 6 × 180 ml', 6, 'lape', 1926]]],
                ['Peperoni mild', 'glas', 1, [['Karton 12 × 480 ml', 12, 'lapem', 2652]]],
                ['Sellerie in Streifen', 'glas', 1, [['Karton 12 × 320 ml', 12, 'lase', 1608]]],
            ]],
            [ProductCategory::Produce, 'Bio (kbA) von Schweizer.', [
                ['Apfelmark (Apfelmus)', 'glas', 1, [['Karton 6 × 370 ml', 6, 'schwam370', 726]]],
                ['Sauerkirschen', 'glas', 1, [['Karton 6 × 370 ml', 6, 'schwsk370', 1410]]],
            ]],
            [ProductCategory::Condiments, 'Bio (kbA) aus Griechenland.', [
                ['Schwarze Kalamata-Oliven, mit Stein', 'glas', 1, [['Karton 6 × 320 g', 6, 'kbos', 1806]], 'In Salzlake.'],
                ['Grüne Oliven, mit Stein', 'glas', 1, [['Karton 6 × 320 g', 6, 'kbog', 1488]]],
                ['Grüne Oliven, gefüllt mit rotem Paprika', 'glas', 1, [['Karton 6 × 320 g', 6, 'kbogp', 2112]]],
                ['Grüne Oliven, gefüllt mit Knoblauch', 'glas', 1, [['Karton 6 × 320 g', 6, 'kbogkn', 2076]]],
                ['Grüne Oliven, gefüllt mit Mandeln', 'glas', 1, [['Karton 6 × 320 g', 6, 'kbogm', 2154]]],
                ['Tomaten, getrocknet, in Olivenöl', 'glas', 1, [['Karton 6 × 235 g', 6, 'kbst', 2004]]],
            ]],
            [ProductCategory::Condiments, 'Bio (kbA).', [
                ['Tomatenmark (100 g)', 'glas', 1, [['Karton 12 × 100 g', 12, 'bftm', 1224]]],
                ['Tomatenmark (210 g)', 'glas', 1, [['Karton 6 × 210 g', 6, 'bftm2', 864]]],
                ['Tomatenmark (Tube)', 'stk', 1, [['Karton 12 × 150 g', 12, 'bftmt', 1440]]],
                ['Passata (passierte Tomaten)', 'stk', 1, [['Karton 6 × 680 g', 6, 'bfpa', 852]]],
                ['Tomaten-Ketchup (Eimer)', 'kg', 0.5, [['5 kg Eimer', 5, 'bytk5', 1868]]],
                ['Tomaten-Ketchup mit Agavendicksaft', 'stk', 1, [['Karton 6 × 500 ml', 6, 'bftk', 1602]], 'Ohne Kristallzucker.'],
                ['Gewürz-Ketchup', 'stk', 1, [['Karton 6 × 500 ml', 6, 'bygk', 1476]]],
                ['Hot-Ketchup', 'stk', 1, [['Karton 6 × 500 ml', 6, 'byhk', 1476]]],
            ]],
            [ProductCategory::Condiments, 'demeter.', [
                ['Geschälte Tomaten in Tomatensaft', 'glas', 1, [['Karton 6 × 660 g', 6, 'bftg', 1566]]],
            ]],

            // ---------------- Pudding, baking ingredients, spices and salt (pages 12–13) ----------------
            [ProductCategory::Other, 'Bio (kbA).', [
                ['Pudding Schokolade', 'pkg', 1, [['Karton 15 Beutel', 15, 'bvpus', 1050]]],
                ['Pudding Vanille', 'pkg', 1, [['Karton 15 Beutel', 15, 'bvpuv', 1050]]],
                ['Pudding Vanille (Großpackung)', 'kg', 0.1, [['1 kg Packung', 1, 'bypuv1', 1487]]],
                ['Vanille-Sauce', 'pkg', 1, [['Karton 20 Beutel', 20, 'bvvs', 1400]]],
            ]],
            [ProductCategory::Other, 'Bio (kbA) von Leckers.', [
                ['Zitronenschalen, gerieben und getrocknet', 'pkg', 1, [['Karton 10 × 15 g', 10, 'bvaz', 790]]],
                ['Orangenschalen, gerieben und getrocknet', 'pkg', 1, [['Karton 10 × 15 g', 10, 'bvao', 790]]],
                ['Reinweinstein-Backpulver', 'pkg', 1, [['Karton 12 × (4 × 21 g)', 12, 'lebp', 756]]],
                ['Reinweinstein-Backpulver (Großpackung)', 'kg', 0.1, [['1 kg Packung', 1, 'lebp1', 522]]],
                ['Gelatine', 'pkg', 1, [['Karton 20 × 12 Blatt', 20, 'lebg', 2660]], 'Vom Bio-Schwein.'],
                ['Bourbon Vanillezucker', 'pkg', 1, [['Karton 35 × (2 × 8 g)', 35, 'levz', 5635]]],
                ['Sahnestark', 'pkg', 1, [['Karton 15 × (4 × 8 g)', 15, 'less', 930]]],
                ['Tortenguss weiß', 'pkg', 1, [['Karton 10 × (2 × 15 g)', 10, 'letgw', 790]]],
            ]],
            [ProductCategory::Other, 'Von Leckers.', [
                ['Getreide-Trockenhefe (Bioreal)', 'pkg', 1, [['Karton 40 × 9 g', 40, 'leth', 1880]]],
            ]],
            [ProductCategory::Other, 'Bio (kbA) von Biovita.', [
                ['Sauerteig-Extrakt', 'pkg', 1, [['Karton 30 × 15 g', 30, 'bvst', 1620]]],
                ['Feine Speisestärke', 'pkg', 1, [['Karton 6 × 400 g', 6, 'bvss', 1266]]],
                ['Streusel Schoko', 'pkg', 1, [['Karton 12 × 50 g', 12, 'bvsts', 1428]]],
            ]],
            [ProductCategory::Spices, 'Bioland, von Berglandkräuter.', [
                ['Basilikum', 'pkg', 1, [['Karton 10 × 25 g', 10, 'beba', 2340]]],
                ['Brotgewürz', 'pkg', 1, [['Karton 10 × 50 g', 10, 'bebg', 2270]]],
                ['Brotgewürz (Großpackung)', 'kg', 0.05, [['1 kg Packung', 1, 'bebg1', 1665]]],
                ['Bruschettagewürz', 'pkg', 1, [['Karton 10 × 25 g', 10, 'bebr', 2540]]],
                ['Kräuter der Provence', 'pkg', 1, [['Karton 10 × 25 g', 10, 'bekp', 2340]]],
                ['Kümmel', 'pkg', 1, [['Karton 10 × 50 g', 10, 'bekü', 1940]]],
                ['Kümmel (Großpackung)', 'kg', 0.05, [['1 kg Packung', 1, 'bekü1', 1140]]],
                ['Majoran', 'pkg', 1, [['Karton 10 × 20 g', 10, 'bema', 2470]]],
            ]],
            [ProductCategory::Spices, 'Bio (kbA).', [
                ['Muskatnuss, ganz', 'pkg', 1, [['Karton 10 × 5 Stück', 10, 'bemug', 2070]]],
                ['Oregano', 'pkg', 1, [['Karton 10 × 20 g', 10, 'beor', 1540]]],
                ['Paprika edelsüß', 'pkg', 1, [['Karton 10 × 30 g', 10, 'bepa', 2340]]],
                ['Pfeffer weiß, gemahlen', 'pkg', 1, [['Karton 10 × 30 g', 10, 'bepf', 2810]]],
                ['Pfefferkörner schwarz', 'pkg', 1, [['Karton 10 × 50 g', 10, 'bepfg', 3340]]],
                ['Pizzagewürz', 'pkg', 1, [['Karton 10 × 30 g', 10, 'bepi', 2340]]],
                ['Pizzagewürz (Großpackung)', 'kg', 0.05, [['1 kg Packung', 1, 'bopi', 2415]]],
                ['Salatgewürz', 'pkg', 1, [['Karton 10 × 25 g', 10, 'besa', 2210]]],
                ['Zimt, gemahlen', 'pkg', 1, [['Karton 10 × 50 g', 10, 'bezi', 2270]]],
                ['Zimt, gemahlen (Großpackung)', 'kg', 0.05, [['1 kg Packung', 1, 'bozi1', 1595]]],
                ['Curry, englisch', 'pkg', 1, [['Karton 10 × 30 g', 10, 'becy', 2410]]],
                ['Lorbeerblätter', 'pkg', 1, [['Karton 10 × 7 g', 10, 'belo', 1540]]],
            ]],
            [ProductCategory::Spices, 'Gewürzmischung für Western Potatoes.', [
                ['Würzl Potato Fix Rosmarin-Knoblauch', 'pkg', 1, [['Karton 12 × 35 g', 12, 'bfwrk', 864]]],
            ]],
            [ProductCategory::Spices, 'Unraffiniert und ohne Rieselhilfe.', [
                ['Atlantik-Meersalz, fein', 'kg', 0.5, [['Karton 12 × 500 g', 6, 'byms0', 1008], ['25 kg Sack', 25, 'bymss', 1204]]],
            ]],
            [ProductCategory::Spices, 'Naturbelassen und unbehandelt.', [
                ['Deutsches Speise-Steinsalz', 'kg', 1, [['Karton 5 × 1 kg', 5, 'huds1', 790], ['25 kg Sack', 25, 'hudss', 1600]]],
                ['Deutsches Speise-Steinsalz (Streudose)', 'stk', 1, [['Karton 6 × 200 g', 6, 'huds2', 540]]],
            ]],
            [ProductCategory::Spices, 'Mit Bio-Kräutern (kbA), von Hurtig.', [
                ['Kräutersalz (Streudose)', 'stk', 1, [['Karton 6 × 200 g', 6, 'huks', 882]]],
            ]],
            [ProductCategory::Spices, 'Mit Bio-Kräutern (kbA), von Byodo.', [
                ['Kräutersalz (Nachfüllbeutel)', 'pkg', 1, [['Karton 6 × 500 g', 6, 'byksn', 1578]]],
            ]],

            // ---------------- Tea, coffee and cocoa (pages 13–14) ----------------
            [ProductCategory::Beverages, 'Bio (kbA) von der GEPA.', [
                ['Darjeeling First Flush Schwarztee', 'pkg', 1, [['Karton 5 × 100 g', 5, 'ged', 3935]], 'Zart, blumig-frisch.'],
                ['Ceylon Schwarztee', 'pkg', 1, [['Karton 5 × (20 × 2 g)', 5, 'geteb', 1525]], 'Im Teebeutel, vollmundig mit dezenter Malznote.'],
                ['Ceylon Grüntee', 'pkg', 1, [['Karton 5 × (20 × 2 g)', 5, 'gegt', 1525]], 'Im Teebeutel, erfrischend, mit hellgrüner Tasse.'],
                ['Früchtetee', 'pkg', 1, [['Karton 5 × (20 × 2 g)', 5, 'geftb', 1315]], 'Im Teebeutel, fruchtig mild und vollmundig.'],
                ['Rooibostee', 'pkg', 1, [['Karton 5 × (20 × 2 g)', 5, 'gertb', 1315]], 'Im Teebeutel, weich und natürlich mild.'],
            ]],
            [ProductCategory::Beverages, 'Bioland-Kräutertee von Berglandkräuter.', [
                ['Pfefferminztee', 'pkg', 1, [['Karton 10 × 30 g', 10, 'betpf', 2610]]],
                ['Kamillentee', 'pkg', 1, [['Karton 10 × 50 g', 10, 'betka', 2940]]],
                ['Kräutertee Ein Sommertag', 'pkg', 1, [['Karton 10 × 40 g', 10, 'betkr', 3480]]],
                ['Kräutertee Orangenminze', 'pkg', 1, [['Karton 10 × 30 g', 10, 'betom', 3080]]],
            ]],
            [ProductCategory::Beverages, 'demeter, von Naturata.', [
                ['Getreidekaffee zum Filtern', 'pkg', 1, [['Karton 6 × 500 g', 6, 'nagkb', 2334]]],
                ['Getreidekaffee instant', 'glas', 1, [['Karton 6 × 100 g', 6, 'nagki', 2358]]],
                ['Dinkelkaffee instant', 'glas', 1, [['Karton 6 × 75 g', 6, 'nadki', 2322]]],
            ]],
            [ProductCategory::Beverages, 'Bioland, vom Biolandhof Klein, Wertheim.', [
                ['Lupinenkaffee (Lupino, gemahlen)', 'pkg', 1, [['Karton 6 × 500 g', 6, 'olk', 2256]]],
            ]],
            [ProductCategory::Beverages, 'Naturland-Kaffee aus Mexiko von der GEPA.', [
                ['Café Organico, ganze Bohne', 'pkg', 1, [['Karton 6 × 250 g', 6, 'gekb', 3030]], 'Naturmild, mit Koffein.'],
                ['Café Organico, gemahlen', 'pkg', 1, [['Karton 6 × 250 g', 6, 'geka', 3030]], 'Naturmild, mit Koffein.'],
                ['Café Organico, gemahlen, entkoffeiniert', 'pkg', 1, [['Karton 6 × 250 g', 6, 'gekae', 3312]]],
            ]],
            [ProductCategory::Beverages, 'Bio-Espresso von der GEPA.', [
                ['Italienischer Espresso, extrafein gemahlen', 'pkg', 1, [['Karton 6 × 250 g', 6, 'geeg', 3648]]],
                ['Espresso Bohnen', 'pkg', 1, [['Karton 6 × 250 g', 6, 'geeb', 3570]]],
            ]],
            [ProductCategory::Beverages, 'Von der GEPA.', [
                ['Kagera Instant-Kaffee', 'glas', 1, [['Karton 12 × 100 g', 12, 'geik', 6756]]],
                ['Cocoba instant', 'pkg', 1, [['Karton 6 × 400 g', 6, 'geco', 2514]], 'Getränkepulver mit Honig.'],
                ['Trinkschokolade Premium', 'pkg', 1, [['Karton 6 × 250 g', 6, 'gets', 2358]]],
            ]],
            [ProductCategory::Beverages, 'Von Naturata.', [
                ['Kakaopulver, stark entölt', 'pkg', 1, [['Karton 10 × 125 g', 10, 'nakp', 1480]]],
            ]],

            // ---------------- Beer, wine and sparkling wine (page 14) ----------------
            [ProductCategory::Beverages, 'Bio (kbA) von der Engelbrauerei, Crailsheim.', [
                ['Engel Öko Kellerbier hell', 'stk', 1, [['Kiste 15 × 0,5 l', 15, 'enkb', 1455]]],
                ['Engel Öko Radler naturtrüb, alkoholfrei', 'stk', 1, [['Kiste 15 × 0,5 l', 15, 'enra', 1455]]],
            ], self::VAT_DRINKS, self::ENGEL_CRATE_DEPOSIT],
            [ProductCategory::Beverages, 'Bio (kbA) von Neumarkter Lammsbräu.', [
                ['Lammsbräu Öko-Dunkel', 'stk', 1, [['Kiste 10 × 0,5 l', 10, 'nldu', 870]]],
                ['Lammsbräu Öko-Hefeweizen hell', 'stk', 1, [['Kiste 10 × 0,5 l', 10, 'nlhh', 870]]],
                ['Lammsbräu Öko-Urstoff hell', 'stk', 1, [['Kiste 10 × 0,5 l', 10, 'nluh', 870]]],
                ['Lammsbräu Öko-Edelpils', 'stk', 1, [['Kiste 10 × 0,33 l', 10, 'nlep', 680]]],
                ['Lammsbräu Öko-Dinkel', 'stk', 1, [['Kiste 10 × 0,33 l', 10, 'nldb', 680]]],
                ['Lammsbräu Öko-Radler', 'stk', 1, [['Kiste 10 × 0,5 l', 10, 'nlra', 870]]],
                ['Lammsbräu Öko-Hefeweizen hell, alkoholfrei', 'stk', 1, [['Kiste 10 × 0,5 l', 10, 'nlah', 870]]],
                ['Lammsbräu Öko-Hefeweizen dunkel, alkoholfrei', 'stk', 1, [['Kiste 10 × 0,5 l', 10, 'nladh', 870]]],
                ['Lammsbräu Öko-Alkoholfrei', 'stk', 1, [['Kiste 10 × 0,33 l', 10, 'nlaf', 680]]],
                ['Lammsbräu Öko-Aktivmalz', 'stk', 1, [['Kiste 10 × 0,33 l', 10, 'nlma', 680]]],
            ], self::VAT_DRINKS, self::LAMMSBRAEU_CRATE_DEPOSIT],
            [ProductCategory::Beverages, 'Von der Familienbrauerei Härtsfelder, Dunstelkingen.', [
                ['Härtsfelder Öko-Krone Export', 'stk', 1, [['Kiste 20 × 0,5 l', 20, 'waex', 1880]]],
            ], self::VAT_DRINKS, self::HAERTSFELDER_CRATE_DEPOSIT],
            [ProductCategory::Beverages, 'Bioland, vom Weingut Seeber in St. Martin (Rheinpfalz).', [
                ['Riesling trocken', 'stk', 1, [['Karton 6 × 1 l', 6, 'sewrt', 3108]]],
                ['Riesling feinherb', 'stk', 1, [['Karton 6 × 1 l', 6, 'sewrh', 3108]]],
                ['Dornfelder Rotwein trocken', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'sewd', 2844]]],
                ['Dornfelder Rotwein feinherb', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'sewdh', 2844]]],
                ['Spätburgunder Rotwein trocken', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'sewsbr', 3492]]],
                ['Pfälzer Landwein rot', 'stk', 1, [['Karton 6 × 1 l', 6, 'sewl', 2418]]],
                ['Riesling Sekt extra trocken', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'sesert', 5220]]],
                ['Riesling Sekt extra trocken (Piccolo)', 'stk', 1, [['Karton 12 × 0,2 l', 12, 'sesertp', 3636]]],
            ], self::VAT_DRINKS],
            [ProductCategory::Beverages, 'ECO VIN, von der Stromberg Kellerei aus Württemberg.', [
                ['Cabernet Blanc trocken', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'stcb', 3816]]],
                ['Lemberger mit Trollinger trocken', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'stle', 3210]]],
                ['Regent trocken', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'stre', 3528]]],
                ['Schwarzriesling Rosé', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'stro', 3210]]],
                ['Soecco trocken', 'stk', 1, [['Karton 6 × 0,75 l', 6, 'stso', 3720]], 'Weißer Perlwein.'],
            ], self::VAT_DRINKS],

            // ---------------- Juices, soft drinks and milk (page 15) ----------------
            [ProductCategory::Beverages, 'Bio-Fruchtsaft von Beutelsbacher.', [
                ['Zitronensaft', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beuzi', 1926]]],
                ['Sauerkirschsaft', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beusn', 2214]]],
            ], self::VAT_DRINKS, self::JUICE_CRATE_DEPOSIT],
            [ProductCategory::Beverages, 'Bioland-Fruchtsaft von der OBEG.', [
                ['Apfelsaft', 'stk', 1, [['Kiste 6 × 1 l', 6, 'oas6', 888]]],
            ], self::VAT_DRINKS, self::JUICE_CRATE_DEPOSIT],
            [ProductCategory::Beverages, 'Bio (kbA) von EOS.', [
                ['Gemüsesaft, milchsauer', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beuge', 1122]]],
                ['Karottensaft, milchsauer', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beumö', 1074]]],
                ['Rote-Bete-Saft, milchsauer', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beuro', 1074]]],
                ['Sauerkrautsaft, milchsauer', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beusa', 1074]]],
                ['Tomatensaft', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beuto', 984]]],
                ['Traubensaft', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beutr', 1242]], '100 % Direktsaft.'],
                ['Orangensaft', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beuor', 1362]], '100 % Direktsaft.'],
                ['Grapefruitsaft', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beugr', 1452]], '100 % Direktsaft.'],
                ['Multivitaminsaft', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beumv', 1362]], '100 % Direktsaft.'],
                ['Johannisbeersaft', 'stk', 1, [['Kiste 6 × 0,7 l', 6, 'beujo', 1362]]],
            ], self::VAT_DRINKS, self::JUICE_CRATE_DEPOSIT],
            [ProductCategory::Beverages, 'Bio, von der Härtsfelder Brauerei.', [
                ['Apfel-Holunderschorle', 'stk', 1, [['Kiste 20 × 0,5 l', 20, 'häaho', 1440]]],
                ['Haldina Lemon', 'stk', 1, [['Kiste 20 × 0,5 l', 20, 'hälemk', 1320]]],
            ], self::VAT_DRINKS, self::HAERTSFELDER_CRATE_DEPOSIT],
            [ProductCategory::Beverages, 'Bio (kbA) von Neumarkter Lammsbräu.', [
                ['BioKristall Apfelsaftschorle', 'stk', 1, [['Kiste 10 × 0,33 l', 10, 'nlas', 680]]],
            ], self::VAT_DRINKS, self::LAMMSBRAEU_CRATE_DEPOSIT],
            [ProductCategory::Beverages, 'Limonade von now (Neumarkter Lammsbräu).', [
                ['now Lemon', 'stk', 1, [['Kiste 10 × 0,33 l', 10, 'nllemn', 680]]],
                ['now Black Cola', 'stk', 1, [['Kiste 10 × 0,33 l', 10, 'nlbc', 680]]],
                ['now Sunny Orange', 'stk', 1, [['Kiste 10 × 0,33 l', 10, 'nlson', 680]]],
                ['now Red Berry', 'stk', 1, [['Kiste 10 × 0,33 l', 10, 'nlrb', 680]]],
                ['now Orange Cola', 'stk', 1, [['Kiste 10 × 0,33 l', 10, 'nloc', 680]]],
            ], self::VAT_DRINKS, self::LAMMSBRAEU_CRATE_DEPOSIT],
            [ProductCategory::Dairy, 'Bioland, aus dem Schwarzwald.', [
                ['H-Milch 3,5 %', 'stk', 1, [['Karton 12 × 1 l', 12, 'ohm3,5', 1992]]],
            ]],
        ];
    }
}
