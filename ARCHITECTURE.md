# Foodpecker — Architektur

> Stand: 2026-05-23 — frühe Prototyp-Phase. Entscheidungen sind dokumentiert,
> aber bewusst unterspezifiziert: lieber 80 % Lösung mit klarer Begründung als
> 100 % Theorie ohne Code.

Diese Datei begleitet die Implementierung in diesem Repo. Die fachliche Wahrheit
steht in [`docs/concept.md`](docs/concept.md); hier wird festgehalten, **wie**
das Konzept technisch abgebildet ist und **warum** ich mich an den jeweiligen
Weggabelungen so entschieden habe.

---

## 1. Tech-Stack & Versionen

| Komponente   | Version | Anmerkung |
|--------------|---------|-----------|
| PHP          | 8.3     | Constructor Property Promotion, Enums, readonly |
| Laravel      | 13.11   | Models nutzen Attribute (`#[Fillable]`, `#[Hidden]`) |
| Filament     | 5.6     | native Tenancy, Schemas-API |
| Livewire     | 4.3     | für Filament-Komponenten |
| Pest         | 4.7     | Domain- und Feature-Tests |
| SQLite       | (dev)   | für lokale Entwicklung; produktionsfähig auf MySQL/Postgres |
| TailwindCSS  | 4       | Filament-Theme |

Keine neuen Composer-Pakete in diesem Prototyp — wir bleiben bei dem, was Boost
installiert hat. Plugin-Empfehlungen siehe Ende dieses Dokuments.

---

## 2. Multi-Tenancy

**Entscheidung**: Filaments **native Tenancy** über `Panel::tenant(Group::class)`,
Single-DB mit `group_id`-Spalte auf allen tenant-eigenen Tabellen. Tenant-Slug
in der URL als Pfad-Präfix (`/g/{group_slug}/...`), nicht als Subdomain — das
passt zur lokalen Herd-Entwicklung und ist im Prototyp leichter zu debuggen.

**Tenant-Modell heißt `Group`** — semantisch passend zum Konzept ("Gruppe von
Freunden bestellt gemeinsam"). Nicht `Team`, nicht `Workspace`. Slugs sind
eindeutig und werden aus dem Gruppennamen abgeleitet.

**Sichtbarkeits-Modell für Hersteller/Produkte**:

- Felder `group_id` (nullable) + `visibility` (`private` | `public`)
- `visibility=private` → `group_id` muss gesetzt sein → nur in dieser Gruppe sichtbar
- `visibility=public` → `group_id = null` → über alle Gruppen geteilt (lesen + benutzen);
  bearbeiten dürfen nur Moderatoren der Gruppe, die das Objekt erstellt hat
  (`created_by_group_id` als Eigentums-Anker bleibt erhalten, auch wenn es publik gemacht wurde)
- Damit ist `Manufacturer`/`Product` **nicht** strikt tenant-scoped: ihre Filament-Resourcen
  setzen `protected static bool $isScopedToTenant = false` und filtern manuell

Inspiriert vom Vorgehen in `~/Herd/nest.kuckuck.cam` (Tenant + `tenant_user`-Pivot
mit Rollenenum, signed-URL-Einladungen, `users.current_tenant_id` für Komfort).
Übernommen wurden vor allem:

- **Pivot-Tabelle `group_user`** mit `role`-Spalte (enum)
- **`GroupRole`-Enum** mit `can(string $permission): bool`-Methode (keine Spatie-Permissions)
- **Signed-URL-Einladungen** mit 64-Zeichen-Token + `expires_at`
- **`User::currentGroup`** für UX (zuletzt benutzte Gruppe merken)

**Spätere Erweiterungs-Option** für tenant-übergreifendes Teilen ohne Public-Flag
(z. B. "Gruppe A teilt diesen Hersteller exklusiv mit Gruppe B"): Pivot-Tabelle
`group_manufacturer` mit `is_shared`-Flag — bewusst **nicht** in dieser Phase.

---

## 3. Datenmodell

Übersicht der zentralen Entitäten und ihrer Beziehungen. Detailliertes
Migrations-Schema im Code (`database/migrations/`).

```
User ──┬──< GroupUser (pivot, role) >── Group ─< GroupInvitation
       │                                  │
       │                                  ├──< Manufacturer ──< Product ──< PriceTier
       │                                  │       (group_id nullable, visibility)
       │                                  │
       │                                  └──< Round ──┬──< RoundParticipant
       │                                               ├──< PickupDate
       └──< CartItem >──── Round                       ├──< OrderProposal ──< ProposalItem
                                                       │                       └─< ProposalAllocation
                                                       ├──< Vote (auf ProposalItem)
                                                       ├──< Payment (pro Teilnehmer)
                                                       └──< Pickup    (pro Teilnehmer)

Note     (polymorph: Manufacturer | Product | Round)
Attachment (polymorph: Note | Round | Manufacturer)
Activity (polymorph: Manufacturer | Product | Round)
```

**Geld** ist konsequent in **Integer-Cent** modelliert (Spalten `*_cents`). Beträge,
die als Anteil ausgewiesen werden (Versandanteil, Lead-Honorar, Vereinsbeitrag,
Aufrunden-Spende) bleiben Cent. Prozente sind `decimal(5,2)` und werden im
`Money`-Service angewendet (Rundung **HALF_UP** auf Cent).

**Mengen** sind `decimal(12,3)` mit Einheit (`unit`) am Produkt — das deckt
`kg`, `Stück`, `Glas`, `Liter` ab, ohne dass wir je in Gramm rechnen müssen.

---

## 4. Verpackungs-Logik

**Konzeptuelle Pflicht**: Alle fünf Szenarien aus dem Concept müssen mit dem
selben Modell darstellbar sein. Entwurf:

| Produkt-Feld | Bedeutung |
|---|---|
| `unit` | Basiseinheit am Produkt (`kg`, `glas`, `stk`, `l`, ...) |
| `packaging_strategy` | Enum: `fixed`, `tiered`, `palette_divisible`, `multi_size_indivisible`, `bulk_weighable` |
| `divisible_step` | Numerische Schrittweite, in der innerhalb eines Gebindes verteilt werden darf (`null` = nicht teilbar; `1.0` = ganze Einheit; `0.1` = abwiegen) |
| `relations` | `priceTiers()` — 1..n Preisstaffeln/Gebindeoptionen |

**`PriceTier`**:

| Spalte | Bedeutung |
|---|---|
| `label` | "25 kg Sack", "12er-Palette", "2 kg Packung" |
| `package_amount` | Menge eines Gebindes (z. B. 25.0 kg, 12 Glas, 2 kg) |
| `min_order_packages` | Mindest-Bestellmenge (Anzahl Gebinde, meist 1) |
| `price_cents` | Preis **pro Gebinde** (nicht pro Einheit) |
| `is_divisible` | Darf das Gebinde innerhalb der Gruppe aufgeteilt werden? |
| `sort_order` | Reihenfolge für die UI |

Mapping zu den Szenarien:

| Concept-Szenario       | strategy                  | tiers | divisible | step |
|------------------------|---------------------------|-------|-----------|------|
| 1 Dinkelmehl 25/50 kg  | `tiered`                  | 2     | ja        | 0.5  |
| 2 Reis 10/25/50 kg     | `tiered`                  | 3     | ja        | 0.5  |
| 3 Senf-Palette         | `palette_divisible`       | 1     | ja        | 1    |
| 4a Spaghetti 250 g/2 kg| `multi_size_indivisible`  | 2     | nein      | —    |
| 4b Spirelli 5 kg Karton| `bulk_weighable`          | 1     | ja        | 0.1  |

Die Strategy ist primär **UI-Hint** — die eigentliche Distribution arbeitet mit
`divisible_step` und `is_divisible` pro Tier.

---

## 5. Faire Aufteilung — Distribution-Algorithmus

`App\Services\Distribution\Distributor::compute(Collection $cartItems, PriceTier $tier)`
→ `DistributionResult { packagesOrdered, allocations[], leftover, infeasible? }`

**Ziel**: Die flexiblen Warenkorb-Spannen so in konkrete Einheiten umrechnen,
dass die Gesamtmenge ein Vielfaches der Gebindegrenze ist.

**Ablauf**:

1. **Aggregate**: `totalMin = Σ exact + Σ min`, `totalMax = Σ exact + Σ max`
2. **Wähle Gesamtmenge**: kleinstes Vielfaches von `tier.package_amount`, das
   - `≥ totalMin` ist (kein Teilnehmer bekommt weniger als sein Min)
   - `≤ totalMax` bleibt (sonst sind alle Maxima überschritten → wir markieren `infeasible=true`,
     aber liefern trotzdem einen Vorschlag aus, der Maxima nur knapp überschreitet)
3. **Allokation, divisible Tier**:
   - Exakte Anforderungen werden **unverändert** zugeteilt
   - Restmenge wird auf flexible Teilnehmer **proportional zu ihrer Spanne** verteilt
   - Einzelallokation wird auf `divisible_step` gerundet (Bankers-Rounding, Floor),
     Restkorrektur (Rundungs-Verluste/-Gewinne) gehen an den Teilnehmer mit der
     größten verbleibenden Spanne
4. **Allokation, nicht-divisible Tier**:
   - Gebinde-Anzahl pro Teilnehmer ist ganze Zahl
   - Greedy: erst exakte Anforderungen in ganzen Gebinden zuteilen, dann flexible
     auffüllen bis Gesamtgebinde-Multiplikator erreicht ist
   - Wenn nicht alle Maxima respektiert werden können → `infeasible=true`

**Diese Version ist explizit nicht optimal** (es gibt formal NP-harte Fälle).
Sie ist deterministisch, nachvollziehbar, getestet — und für die meisten
Gruppensituationen ausreichend. Der Lead kann jederzeit hand-editieren.

Tests in `tests/Feature/Distribution/DistributorTest.php`.

---

## 6. Geld-Modell

Alle Beträge intern **Integer Cent**. Ein einzelner Service kapselt Logik:

```php
App\Services\Money\OrderCalculator
    ::calculateProposal(OrderProposal $proposal): ProposalTotals
```

Komponenten:

- **Warenwert** = Σ `(proposal_items.packages_ordered × tier.price_cents)`
- **Versand** wird vom Lead pro Vorschlag als Cent-Betrag eingegeben → gleichmäßig
  auf Teilnehmer aufgeteilt (`shipping_share_cents`) — _gleichmäßig_, nicht
  nach Anteil. Begründung: Versand pro Paket, das ankommt, ist meist fix; die
  Konzept-Beschreibung "Versandkosten gleichmäßig aufgeteilt" stützt das.
- **Lead-Honorar** = `Σ warenwert × (lead_fee_percent / 100)` → wird an Teilnehmer
  anteilig zu ihren Bestellsummen weitergegeben
- **Vereinsbeitrag** = `Σ warenwert × (platform_fee_percent / 100)`, Default 1.00 %
- **Aufrund-Spende**: pro Teilnehmer, optional, zum `payment.amount_cents` addiert

Runden: alle prozentualen Anteile werden mit `intdiv((amount × bps), 10_000)`
gerechnet (basispunkte) → reproduzierbar, kein Floating-Point.

Tests: `tests/Feature/Money/OrderCalculatorTest.php`.

---

## 7. Registrierung & Einladungen

**Grundregel**: Foodpecker ist nur nach **Registrierung** nutzbar, kein
anonymer Zugang. Eine **Gruppe** kann jeder selbst gründen — damit wird man
automatisch ihr Owner. **Beitritt zu einer fremden Gruppe** ist ausschließlich
per E-Mail-Einladung möglich.

**Flow** (orientiert an `nest.kuckuck.cam`):

1. Owner/Moderator öffnet im Mitglieder-Tab "Mitglied einladen" → Modal mit
   E-Mail + Rolle (`Moderator` | `Participant`)
2. `GroupInvitationService::invite()` legt `GroupInvitation` an
   - `token` = 64 Zeichen `Str::random()` (im Model-Boot-Event)
   - `expires_at` = jetzt + 14 Tage (konfigurierbar)
3. `InvitationMail` enthält **signed URL** zur Accept-Route:
   `URL::signedRoute('invitations.accept', ['token' => ...], $expires_at)`
4. Route `GET /einladung/{token}/akzeptieren` (signed middleware):
   - **Eingeloggt + gleiche E-Mail** → `$invitation->accept($user)` + Redirect ins Panel
   - **Eingeloggt + andere E-Mail** → 403-Hinweis "bitte mit korrekter Mail einloggen"
   - **Nicht eingeloggt + Account existiert** → Login mit `?intended=invitation_token`
   - **Nicht eingeloggt + kein Account** → Registrierung mit vorbefüllter E-Mail
     (read-only) und Token in Session
5. Bei Registrierung wird nach Erstellung des Users automatisch
   `$invitation->accept($user)` aufgerufen → Pivot mit korrekter Rolle wird angelegt

**Akzeptierte Einladungen** sind unique pro `(group_id, email)`; abgelaufene
können erneut versendet (re-tokenized) werden.

---

## 8. Rollen & Berechtigungen

Drei Rollen pro Gruppe: `Owner`, `Moderator`, `Participant` (Enum `GroupRole`).
Eine Person kann in unterschiedlichen Gruppen unterschiedliche Rollen haben —
die Rolle steht auf dem Pivot `group_user.role`.

**Warum keine `spatie/laravel-permission`?**

- Wir haben drei feste Rollen, nicht user-definierte Rollen mit beliebigen Permissions
- Permissions sind tenant-scoped (eine Rolle gilt _in einer Gruppe_)
- Das Pattern aus `nest.kuckuck.cam` mit Enum + `can()`-Methode bewährt sich
  und reduziert Abhängigkeiten
- Filament-Policies + ein dünner `User::canInGroup(Group $g, string $perm)`-Helper
  sind ausreichend

**Permission-Strings** (Beispielauswahl):

| Permission                     | Owner | Moderator | Participant |
|--------------------------------|:-----:|:---------:|:-----------:|
| `group:delete`                 | ✓     |           |             |
| `group:update`                 | ✓     | ✓         |             |
| `group:invite`                 | ✓     | ✓         |             |
| `group:manage-members`         | ✓     | ✓         |             |
| `manufacturer:create`          | ✓     | ✓         |             |
| `product:create`               | ✓     | ✓         |             |
| `round:create`                 | ✓     | ✓         |             |
| `round:advance-phase`          | ✓     | ✓ (Lead)  |             |
| `cart:write` (eigener Korb)    | ✓     | ✓         | ✓           |
| `cart:view-all`                | ✓     | ✓         | ✓           |
| `proposal:create`              | ✓     | ✓         | ✓           |
| `proposal:vote`                | ✓     | ✓         | ✓           |

Filament-Policies (`ManufacturerPolicy`, `ProductPolicy`, `RoundPolicy`, ...)
delegieren an `$user->canInGroup($currentTenant, $permission)`.

---

## 9. Phasen-State-Machine

Eine `Round` durchläuft Phasen in fester Reihenfolge. Übergänge werden in einem
zentralen Service geprüft, kein externes Paket.

```
draft → shopping → negotiating → finalizing → payment → ordering → delivery → pickup → completed
                                                              \                            ↑
                                                               cancelled ────────────────┘
```

**Allowed Transitions**:

| Von            | Nach             | Wer                  | Bedingung |
|----------------|------------------|----------------------|-----------|
| draft          | shopping         | Lead                 | mind. 1 Pickup-Datum, Abholort gesetzt |
| shopping       | negotiating      | Lead                 | mind. 1 Teilnehmer mit Warenkorb |
| negotiating    | shopping         | Lead                 | (Rück-Sprung erlaubt für Korrekturen) |
| negotiating    | finalizing       | Lead                 | mind. 1 publizierter Vorschlag |
| finalizing     | payment          | Lead                 | mind. 1 Vorschlag einstimmig akzeptiert + ausgewählt |
| payment        | ordering         | Lead                 | alle Zahlungen `paid` |
| ordering       | delivery         | Lead                 | manuell, sobald Bestellung beim Hersteller raus |
| delivery       | pickup           | Lead                 | manuell, sobald Ware da |
| pickup         | completed        | Lead                 | alle Abholungen erledigt oder manuell |
| (jede ≠ done)  | cancelled        | Lead oder Owner      | mit Begründung |

Implementierung: `App\Services\Rounds\PhaseTransitioner` mit `canTransition()`
und `transition()`-Methoden. Übergänge erzeugen einen `Activity`-Eintrag.

Tests: `tests/Feature/Rounds/PhaseTransitionerTest.php`.

---

## 10. Benachrichtigungen

**Konzept-Anforderung**: kein automatischer E-Mail-Spam — manueller Versand mit
auto-generiertem, editierbarem Entwurf, der seit der letzten Benachrichtigung
gemachten Änderungen zusammenfasst.

**Umsetzung**:

- Tabelle `notification_drafts` (id, round_id, kind, body, generated_at, sent_at, sent_by_user_id)
- "Kind" ist ein Enum: `shopping_open`, `negotiation_started`, `proposal_ready`,
  `payment_due`, `order_placed`, `pickup_dates`, `custom`
- Tabelle `activities` dient als **Diff-Quelle**: Beim Generieren eines Entwurfs
  werden alle `Activity`-Einträge dieser Runde seit dem letzten gesendeten
  Draft kompakt zusammengefasst (Service `App\Services\Notifications\DraftBuilder`)
- Der Lead öffnet ein Modal "Benachrichtigung vorbereiten" → bekommt einen
  vorgenerierten Markdown-Text → bearbeitet ihn → Send → `Mail::to(...)` an alle
  RoundParticipants

Im Prototyp wird für E-Mails der `log`-Mailer benutzt (alles landet in
`storage/logs/laravel.log`) — produktiv per `MAIL_MAILER=smtp` einsetzbar.

---

## 11. Aktivitäten-Stream & Notizen

**Aktivitäten-Stream**: eigene Tabelle `activities` (id, group_id, user_id,
subject_type, subject_id, action, properties JSON, created_at). Wird im Code
über `$model->logActivity(string $action, array $props = [])` Trait befüllt
(`HasActivities`). Spatie-Activitylog wäre ein Upgrade-Pfad, aber für diesen
Prototyp wäre es Overkill.

**Notizen**: polymorphes Modell `Note` (`notable_type`, `notable_id`, body, user_id).
Notizen können an Hersteller, Produkte, Runden hängen. Anhänge per Polymorph-Relation.

**Anhänge**: `attachments`-Tabelle mit `attachable_type`, `attachable_id`,
`original_name`, `path`, `mime_type`, `size_bytes`. Standard-Disk im Prototyp:
`local` (`storage/app/private`). Anhänge sind **nicht öffentlich** zugänglich;
Download über signed temporäre Routes.

---

## 12. Lokalisierung

**UI-Sprache**: durchgängig Deutsch. Vorgehen:

- `APP_LOCALE=de` und `APP_FALLBACK_LOCALE=en` in `.env`
- Filament v5 liefert seine eigenen deutschen Übersetzungen (Buttons, Filter,
  Notifications) automatisch mit
- Domänenspezifische Labels (Spaltennamen, Form-Labels, Section-Titel) werden
  inline auf Deutsch geschrieben (`->label('Hersteller')`)
- Translation-Files (`lang/de/...`) werden später bei Bedarf herausgezogen;
  ihre Vorbereitung im Code stört den Prototyp nicht

`APP_FAKER_LOCALE=de_DE` für die Demo-Daten.

---

## 13. Verzeichnis-Struktur (Domänen-Code)

```
app/
├── Enums/
│   ├── GroupRole.php
│   ├── ProductVisibility.php
│   ├── PackagingStrategy.php
│   ├── RoundPhase.php
│   ├── ProposalStatus.php
│   ├── VoteValue.php
│   └── PaymentStatus.php
├── Models/                       (Eloquent + Relations)
├── Policies/
├── Services/
│   ├── Distribution/Distributor.php
│   ├── Money/OrderCalculator.php
│   ├── Rounds/PhaseTransitioner.php
│   ├── Invitations/GroupInvitationService.php
│   └── Notifications/DraftBuilder.php
├── Filament/
│   └── Resources/   (ManufacturerResource, ProductResource, RoundResource, ...)
├── Filament/Pages/
│   ├── Tenancy/RegisterGroup.php
│   ├── Tenancy/EditGroupProfile.php
│   └── RoundDetail.php           (das eine Dashboard-artige Page)
├── Http/Controllers/
│   └── InvitationController.php  (Accept-Route außerhalb Panel)
├── Mail/
│   └── GroupInvitationMail.php
└── Concerns/                     (HasActivities, HasAttachments, BelongsToGroup)
```

---

## 14. Vorzeigbare Demo

Nach `php artisan migrate --seed`:

- **3 Gruppen**: "Speisekammer Schöneberg", "Hofgemeinschaft Lichtenrade",
  "Familie Müller & Friends"
- **6 Demo-Nutzer** mit unterschiedlichen Rollen-Konstellationen
- **Hersteller** (privat + öffentlich): "Spielberger Mühle" (öffentl.),
  "Bio-Pasta Italia" (öffentl.), "Hofladen Brandenburg" (privat),
  "Senfwerk Düsseldorf" (öffentl.)
- **Produkte** decken alle fünf Verpackungs-Szenarien ab: Dinkelmehl, Reis,
  Senf, Spaghetti, Spirelli, Polenta, Hafer
- **2 Runden**:
  - 1 abgeschlossene Runde "Spätsommer-Bestellung 2025" mit eingefrorenen Preisen,
    Zahlungen, Abholungen, Notizen
  - 1 aktive Runde "Frühjahr-Bestellung 2026" in Phase `finalizing` mit zwei
    konkurrierenden Vorschlägen, ersten Abstimmungen

**Login-Daten** werden in der Seeder-Konsole ausgegeben und sind auf der
Login-Seite vorausgefüllt:

> `marie@foodpecker.test` / `password`

---

## 14a. Autonomes Smoke- & Browser-Testing

Das Projekt nutzt das **Pest 4 Browser-Plugin** (`pestphp/pest-plugin-browser`)
mit Playwright + Headless-Chromium. Damit lässt sich die App in einem
echten Browser durchklicken — keine Mocks, keine reine DOM-Stubs.

### Was die Suite testet

[`tests/Browser/WalkthroughTest.php`](tests/Browser/WalkthroughTest.php)
spielt acht Szenarien durch:

1. Login-Seite rendert mit deutschem UI
2. Vollständiger Login-Flow über die echte Form (kein `actingAs`-Shortcut)
3. Klick-Tour durch Dashboard, Hersteller, Produkte, Bestellrunden, Mitglieder
4. Aktive Runde als Dashboard mit Warenkorb-Matrix, Vorschlägen, Voting-Buttons
5. Modal "Artikel hinzufügen" öffnet sich und zeigt das richtige Form-Schema
6. Abgeschlossene Runde mit Zahlungen, Abholungen, eingefrorenen Preisen
7. Tenant-Wechsel zur zweiten Gruppe
8. Parallel-Smoke-Check aller Hauptseiten auf JavaScript-Fehler

Jeder Schritt prüft `assertNoJavaScriptErrors()` und legt einen
**Screenshot** unter `tests/Browser/Screenshots/` ab (`.gitignored`). Damit
findet die Suite tatsächliche Layout-/JS-Bugs, nicht nur Logik-Fehler.

### Laufen lassen

```bash
vendor/bin/pest tests/Browser              # Headless, ~10 s
vendor/bin/pest tests/Browser --headed     # sichtbarer Browser
vendor/bin/pest tests/Browser --debug      # pausiert bei erstem Fehler
vendor/bin/pest tests/Browser --parallel   # parallel auf mehreren Cores
```

### Voraussetzungen

Werden einmalig per `composer install && npm install && npx playwright install`
gesetzt. Im CI (GitHub Actions) reicht zusätzlich:

```yaml
- uses: actions/setup-node@v4
  with: { node-version: lts/* }
- run: npm ci
- run: npx playwright install --with-deps
```

### Visual Regression (optional, später)

Pest 4 bringt `assertScreenshotMatches()` mit — Pixel-Diff gegen ein
Baseline-Bild. Aktuell nicht aktiviert, weil ein erster, lauffähiger
Smoke-Walkthrough wertvoller ist als spröde Pixel-Diffs. Sobald die UI
stabilisiert ist, lässt sich das pro Seite ergänzen:

```php
$page->assertScreenshotMatches();
```

## 15. Plugin-Empfehlungen für später

Im Prototyp **nicht installiert**, aber für den Produktiv-Einsatz interessant:

- **bezhansalleh/filament-shield** — falls die Permission-Matrix wächst und
  Spatie-Permissions doch sinnvoll werden
- **awcodes/filament-curator** — Asset/Media-Library, schöner als unsere
  selbstgebaute `attachments`-Tabelle
- **dotswan/filament-laravel-pulse** — Health-Dashboard direkt im Panel
- **filament/spatie-laravel-activitylog-plugin** — Activity-Stream im UI
- **z3d0x/filament-fabricator** — Custom Pages mit dem Schema-Builder zusammenklicken
- **leandrocfe/filament-apex-charts** — Hübsche Charts für das Runden-Dashboard
- **stephenjude/filament-jetstream** oder **filament/spatie-laravel-tags-plugin** —
  Tags an Notizen/Produkten
- **filament/spatie-laravel-translatable-plugin** — wenn das Konzept eines Tages
  zweisprachig wird

---

## 16. Bewusst _nicht_ getan

- **Kein Sub-Domain-Routing** für Tenants. Pfad-Routing (`/g/{slug}`) ist im
  Prototyp einfacher zu testen und für Herd-Setup praktischer.
- **Kein Vue/React-SPA-Frontend.** Filament reicht für 100 % der Verwaltungs-UI.
- **Keine PWA, kein Push.** Manuelle E-Mails sind Konzept-Anforderung.
- **Kein Konfliktlösungs-Workflow.** Konzept sagt explizit: "Vertrauen, Konflikte
  außerhalb der Plattform". Notizen an abgeschlossene Runden reichen.
- **Kein Echtzeit-Update.** Polling/Refresh ist OK; Pusher etc. später.
- **Kein automatischer Stripe/Banking-Anschluss.** Zahlung läuft außerhalb,
  Lead hakt manuell ab — Konzept-Anforderung.
- **Keine Soft-Deletes** auf den meisten Tabellen. Wenn nötig, leicht
  nachzurüsten; im Prototyp KISS.

---

## 17. Offene Punkte / Bewusst lose gelassen

- **Vergangene Bestellungen → Richtwerte ins Produkt zurückfließen**: schon
  möglich (Tabelle `price_observations`), aber die UI dazu ist im Prototyp nur
  rudimentär. Anzeige im Hersteller-Detail als Mini-Spalte.
- **Lead-Übergabe mit Zustimmung**: minimal als Action im Round-Header gebaut,
  aber kein zwei-stufiger Workflow (Vorschlag → Annahme). Lead kann Lead-Status
  übergeben, der neue Lead bekommt eine Notification.
- **Hersteller-E-Mail-Vorlagen** (Concept §"Email-Vorlagen"): rudimentäre
  Stub-Action am Hersteller-Detail (Modal mit zwei Vorlagen "Preisanfrage" und
  "Bestellung"), kein vollständiger Editor.
- **Aktivitäten/Notiz-Sichtbarkeit über Gruppen hinweg**: Notizen an _öffentlichen_
  Herstellern/Produkten sind für alle Gruppen sichtbar (Konzept-Anforderung).
  Private Notizen aktuell nicht modelliert — gehört in ein späteres Feature.
