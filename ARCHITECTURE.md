# Foodpecker — Architektur

> Stand: 2026-09-25 — Prototyp, bereit für einen ersten Testlauf. Entscheidungen
> sind dokumentiert, aber bewusst unterspezifiziert: lieber 80 % Lösung mit
> klarer Begründung als 100 % Theorie ohne Code.

Diese Datei begleitet die Implementierung in diesem Repo. Die fachliche Wahrheit
steht in [`docs/concept.md`](docs/concept.md); hier wird festgehalten, **wie**
das Konzept technisch abgebildet ist und **warum** ich mich an den jeweiligen
Weggabelungen so entschieden habe.

---

## 1. Tech-Stack & Versionen

| Komponente   | Version | Anmerkung |
|--------------|---------|-----------|
| PHP          | 8.3     | Constructor Property Promotion, Enums, readonly |
| Laravel      | 13      | |
| Filament     | 5       | native Tenancy, Schemas-API |
| Livewire     | 4       | für Filament-Komponenten |
| Pest         | 4       | Domain-, Livewire- und Browser-Tests |
| SQLite       | (dev)   | für lokale Entwicklung; produktionsfähig auf MySQL/Postgres |
| TailwindCSS  | 4       | Filament-Theme |
| Deployer     | 8       | mit [`mmoollllee/laravel-deployer`](https://github.com/mmoollllee/laravel-deployer) für Plesk |
| Profil       | 0.1     | [`mmoollllee/filament-user-profile`](https://github.com/mmoollllee/filament-user-profile): Profilseite, Profilfotos, Initialen-Avatare |

Weitere Composer-Pakete gibt es nur fürs Deployment (siehe §18).
Plugin-Empfehlungen siehe Ende dieses Dokuments.

---

## 2. Multi-Tenancy

**Entscheidung**: Filaments **native Tenancy** über `Panel::tenant(Group::class)`,
Single-DB mit `group_id`-Spalte auf allen tenant-eigenen Tabellen. Tenant-Slug
in der URL als Pfad-Präfix (`/g/{group_slug}/...`), nicht als Subdomain — das
passt zur lokalen Herd-Entwicklung und ist im Prototyp leichter zu debuggen.

**Tenant-Modell heißt `Group`** — semantisch passend zum Konzept ("Gruppe von
Freunden bestellt gemeinsam"). Nicht `Team`, nicht `Workspace`. Slugs sind
eindeutig und werden aus dem Gruppennamen abgeleitet.

Filament scoped die Modelle tenant-fähiger Resources (hier: `Round`) automatisch
auf die aktuelle Gruppe. Alles andere (Zahlungen, Vorschläge, Einladungen, …)
wird in den Seiten **immer über die Runde bzw. Gruppe aufgelöst**
(`$round->payments->firstWhere(...)`), nie über eine freie ID.

**Owner-Invariante**: `groups.owner_id` bestimmt den Owner, und der Owner ist
immer auch Mitglied mit Rolle `owner` im Pivot (`Group::ensureOwnerMembership()`
beim Anlegen, `OwnerTransfer` beim Übergeben). Mitgliedschaften müssen deshalb
nie Sonderfälle für den Owner behandeln.

**Sichtbarkeits-Modell für Hersteller/Produkte**:

- `group_id` = **Eigentümer-Gruppe**, `visibility` = teilen ja/nein. Nur nach
  dem Auflösen einer Gruppe ist `group_id` leer: Der Eintrag gehört dann allen.
  Die erste Gruppe, deren Moderator ihn bearbeitet, übernimmt ihn.
- `visibility=private` → nur in der eigenen Gruppe sichtbar
- `visibility=public` → alle Gruppen können es lesen und bestellen; **ändern
  dürfen nur Moderatoren der Eigentümer-Gruppe** (Policies)
- Produkte sind nur öffentlich, wenn ihr Hersteller öffentlich ist. Wird ein
  Hersteller privat, werden seine Produkte mit privat; solange andere Gruppen
  Produkte für ihn haben, muss er öffentlich bleiben.
- `Manufacturer`/`Product` sind deshalb **nicht** strikt tenant-scoped: ihre
  Filament-Resourcen setzen `protected static bool $isScopedToTenant = false`
  und filtern über `visibleTo($group)`
- Produkte werden **archiviert** (Soft Delete) statt gelöscht; endgültig löschen
  geht nur, solange kein Warenkorb, Vorschlag oder Preis-Beobachtung darauf zeigt.
  Hersteller lassen sich nur ohne Produkte löschen.
- Das **Produktbild** verschwindet beim endgültigen Löschen und beim Ersetzen —
  erst nach dem Commit und nur, wenn kein anderes Produkt dieselbe Datei zeigt.
  Archivierte Produkte behalten ihr Bild.

Inspiriert vom Vorgehen in `~/Herd/nest.kuckuck.cam` (Tenant + `tenant_user`-Pivot
mit Rollenenum, signed-URL-Einladungen, `users.current_tenant_id` für Komfort).

**Gruppe auflösen** (`GroupDissolution`, nur der Owner, in den
Gruppen-Einstellungen nach Eintippen des Gruppennamens): Bestellungen anderer
Gruppen dürfen dabei nicht kaputtgehen.

- Gesperrt, solange eine Runde in Zahlung, Bestellung, Lieferung oder Abholung
  ist — da sind Geld oder Ware unterwegs.
- **Gelöscht** werden Runden (samt Warenkörben, Vorschlägen, Zahlungen, Notizen,
  Dokumenten und Verlauf), Mitgliedschaften, Einladungen und alles, was nur die
  Gruppe sehen konnte: private Hersteller/Produkte (mit Bild), Notizen,
  Dokumente, Verlauf und Preis-Historie an privaten Einträgen. Dateien werden
  erst nach dem Commit entfernt.
- **Bleiben** und gehören danach allen (`group_id = null`, bis eine andere
  Gruppe sie pflegt und damit übernimmt): geteilte Hersteller und Produkte mit
  ihren Notizen, Dokumenten und Preisen — ohne Namen. Private
  Produkte, die eine andere Gruppe schon im Warenkorb, in einem Vorschlag, im
  Sortiment oder in der Preis-Historie hat, bleiben **archiviert** erhalten;
  Hersteller bleiben, solange noch ein Produkt auf sie zeigt.
- Die Konten der Mitglieder bleiben; auf Wunsch bekommen sie eine Mail
  (`GroupDissolvedMail`). Der Owner landet danach in seiner nächsten Gruppe
  bzw. beim Gründen einer neuen.

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
       │                                  │    (group_id = Eigentümer, visibility, Product: soft deletes)
       │                                  │
       │                                  └──< Round ──┬──< RoundParticipant (removed + Grund = ausgeschlossen)
       │                                               ├──< PickupDate
       └──< CartItem >──── Round                       ├──< OrderProposal ──< ProposalItem (Gebinde-Snapshot)
                                                       │                       ├─< ProposalAllocation
                                                       │                       └─< ProposalVote
                                                       ├──< Payment (pro Teilnehmer)
                                                       ├──< Pickup  (pro Teilnehmer, gewählter Abholtermin)
                                                       └──< NotificationDraft

Note        (polymorph: Manufacturer | Product | Round)
Attachment  (polymorph: Round | Manufacturer | Product; Dateien auf der privaten Disk)
Activity    (polymorph: Round | Manufacturer | Product | OrderProposal | Group)
PriceObservation (tatsächlich gezahlte Gebindepreise, pro Produkt)
```

**Geld** ist konsequent in **Integer-Cent** modelliert (Spalten `*_cents`).
Eingegeben wird aber in Euro: die Formular-Komponente `MoneyInput` wandelt
„12,50“ in 1250 Cent und zurück (`Money::parse()` / `Money::toInputString()`).
Prozente sind `decimal(5,2)` und werden im `Money`-Service angewendet.

**Mengen** sind `decimal(12,3)` mit Einheit am Produkt (`ProductUnit`-Enum:
`kg`, `g`, `l`, `ml`, `stk`, `glas`, `pkg`).

**Vorschlagspositionen speichern ihr Gebinde als Snapshot** (`tier_label`,
`package_amount`, `package_price_cents`, Teilbarkeit, Mindestmenge). So kann der
Lead verhandelte Preise nur für diese Runde eintragen, ohne die geteilten
Produktdaten zu ändern, und gelöschte Preisstaffeln machen alte Vorschläge
nicht kaputt (`price_tier_id` wird dann `null`).

---

## 4. Verpackungs-Logik

**Konzeptuelle Pflicht**: Alle fünf Szenarien aus dem Concept müssen mit dem
selben Modell darstellbar sein.

| Produkt-Feld | Bedeutung |
|---|---|
| `unit` | Basiseinheit am Produkt (`ProductUnit`) |
| `packaging_strategy` | Enum: `fixed`, `tiered`, `palette_divisible`, `multi_size_indivisible`, `bulk_weighable` |
| `relations` | `priceTiers()` — 1..n Preisstaffeln/Gebindeoptionen |

**`PriceTier`**:

| Spalte | Bedeutung |
|---|---|
| `label` | "25 kg Sack", "12er-Palette", "2 kg Packung" |
| `package_amount` | Menge eines Gebindes (z. B. 25.0 kg, 12 Glas, 2 kg) |
| `min_order_packages` | Mindest-Bestellmenge (Anzahl Gebinde, meist 1) |
| `price_cents` | Preis **pro Gebinde** (nicht pro Einheit) |
| `is_divisible` | Darf das Gebinde innerhalb der Gruppe aufgeteilt werden? |
| `divisible_step` | Schrittweite beim Aufteilen (`null` = ganzes Gebinde) |
| `sort_order` | Reihenfolge für die UI |

Mapping zu den Szenarien:

| Concept-Szenario       | strategy                  | tiers | divisible | step |
|------------------------|---------------------------|-------|-----------|------|
| 1 Dinkelmehl 25/50 kg  | `tiered`                  | 2     | ja        | 0.5  |
| 2 Reis 10/25/50 kg     | `tiered`                  | 3     | ja        | 0.5  |
| 3 Senf-Palette         | `palette_divisible`       | 1     | ja        | 1    |
| 4a Spaghetti 250 g/2 kg| `multi_size_indivisible`  | 2     | nein      | —    |
| 4b Spirelli 5 kg Karton| `bulk_weighable`          | 1     | ja        | 0.1  |

**Gebindewahl beim Vorschlag** (`ProposalBuilder`): Für teilbare Produkte wird
die Staffel mit dem niedrigsten Preis pro Einheit gewählt, die die Wünsche
erfüllt (sonst die mit dem kleinsten Überhang). Bei mehreren **nicht teilbaren**
Größen (Szenario 4a) wählt jede Person im Warenkorb ihre Packungsgröße (oder
„automatisch passend zur Menge“) — der Vorschlag bekommt dann eine Position pro
Größe. Beim Anpassen einer Position zeigt ein Vergleich, was jede Staffel für
die aktuelle Nachfrage bedeuten würde.

---

## 5. Faire Aufteilung — Distribution-Algorithmus

`App\Services\Distribution\Distributor::compute(Collection $cartItems, PriceTier|PackageSpec $package, ?int $packages = null)`
→ `DistributionResult { packagesOrdered, allocations[], feasible, notes[], unallocatedQuantity }`

**Ziel**: Die flexiblen Warenkorb-Spannen so in konkrete Einheiten umrechnen,
dass die Gesamtmenge ein Vielfaches der Gebindegröße ist.

**Ablauf**:

1. **Aggregate**: `totalMin = Σ exact + Σ min`, `totalMax = Σ exact + Σ max`
2. **Gesamtmenge**: kleinstes Vielfaches der Gebindegröße ≥ `totalMin` — oder
   die vom Lead vorgegebene Anzahl Gebinde (nach der Verhandlung)
3. **Allokation, teilbar**:
   - Exakte Anforderungen werden unverändert zugeteilt
   - Restmenge wird auf flexible Teilnehmer **proportional zu ihrer Spanne**
     verteilt, auf `divisible_step` gerundet, Rundungsrest an den größten Spielraum
   - Gibt der Lead **weniger** Gebinde vor als gewünscht, wird jede Mindestmenge
     anteilig gekürzt
4. **Allokation, nicht teilbar**: ganze Gebinde pro Person im Rahmen ihrer
   Spanne, greedy nach größtem freien Wunsch
5. **Rest**: Was niemand innerhalb seines Maximums haben will, bleibt als
   **Überhang** unverteilt, wird im Vorschlag angezeigt und über die Anteile
   mitbezahlt. Früher wurde es einer Person über ihr Maximum hinaus zugeschoben.

Die Preisanteile werden cent-genau proportional zu den zugeteilten Mengen
verteilt. **Diese Version ist explizit nicht optimal** — deterministisch,
nachvollziehbar, getestet. Der Lead passt Gebindegröße, Anzahl und Preis pro
Position an (`ProposalBuilder::updateItem()`).

Tests in `tests/Feature/Distribution/DistributorTest.php` und
`tests/Feature/Proposals/ProposalBuilderTest.php`.

---

## 6. Geld-Modell

Alle Beträge intern **Integer Cent**. Ein einzelner Service kapselt Logik:

```php
App\Services\Money\OrderCalculator::calculate(OrderProposal $proposal): ProposalTotals
```

Komponenten:

- **Warenwert** = Σ `proposal_items.total_price_cents` (mit verhandeltem Gebindepreis)
- **Versand** wird pro Vorschlag in Euro eingegeben → gleichmäßig auf die
  Beteiligten aufgeteilt (`shipping_share_cents`)
- **Lead-Honorar** = `Σ warenwert × lead_fee_percent` → anteilig zu den Bestellsummen
- **Vereinsbeitrag** = `Σ warenwert × platform_fee_percent`, Default 1 %
- **Aufrund-Spende**: In der Zahlungsphase rundet jede Person ihren eigenen
  Anteil auf volle 1 € oder 10 € auf oder wählt einen eigenen Gesamtbetrag
  (`RoundUpDonation`). Der Lead sieht, was er insgesamt an den Verein
  weiterleitet (Vereinsbeitrag + Spenden).

Rundung: Prozente über Basispunkte (`intdiv`), Rundungsreste an den letzten
Teilnehmer — reproduzierbar, kein Floating-Point. Beim Wählen der finalen
Bestellung legt `ProposalWorkflow::choose()` die Zahlungen und Abholungen an.

Tests: `tests/Feature/Money/`.

---

## 7. Registrierung & Einladungen

**Grundregel**: Foodpecker ist nur nach **Registrierung** nutzbar. Eine
**Gruppe** kann jeder selbst gründen — damit wird man automatisch ihr Owner.
**Beitritt zu einer fremden Gruppe** ist ausschließlich per Einladung möglich.

**Pflichtangaben bei der Registrierung**: Vor- und Nachname, E-Mail,
**Handynummer**, **PLZ und Wohnort** (4–5 Ziffern) und die **Zahl der Personen
im Haushalt**, für die jemand mit einkauft (1–20). Dieselben Felder
(`App\Filament\Forms\UserFields`) stehen im Profil und bleiben dort Pflicht.

**Flow**:

1. Owner/Moderator lädt auf „Mitglieder & Einladungen“ ein — auch **mehrere
   auf einmal**: Adressen durch Komma getrennt (Semikolon, Zeilenumbruch und
   „Name <adresse>“ aus dem Mailprogramm gehen auch), eine Rolle für alle,
   höchstens 50. Ungültige Adressen werden genannt, bevor irgendjemand
   eingeladen wird; Mitglieder werden übersprungen; scheitert eine Mail, gehen
   die übrigen trotzdem raus (`GroupInvitationService::inviteMany()`).
2. `GroupInvitationService::invite()` legt eine `GroupInvitation` an (64 Zeichen
   Token, gültig 14 Tage) und schickt eine **Markdown-Mail mit Button** zur
   signierten URL. Solange kein SMTP eingerichtet ist, kopiert man den Link
   über „Link kopieren“ und schickt ihn selbst.
3. Route `GET /einladung/{token}/akzeptieren` (`InvitationController`):
   - **Eingeloggt + gleiche E-Mail** → Beitritt, weiter in die Gruppe
   - **Eingeloggt + andere E-Mail** → Hinweis, mit der richtigen Adresse anmelden
   - **Nicht eingeloggt + Konto existiert** → Login, danach zurück zum Link
     (`intended`) und Beitritt
   - **Nicht eingeloggt + kein Konto** → Registrierung mit vorbefüllter,
     gesperrter E-Mail; nach dem Anlegen automatischer Beitritt
4. Fehler (ungültig, abgelaufen, falsche Adresse) erscheinen als
   Filament-Notification — auch auf der Login-Seite.

Die lokale Vorbelegung der Login-Maske mit Demo-Zugangsdaten entfällt, sobald
jemand über eine Einladung kommt.

**Profil** (`/profile`, Paket `mmoollllee/filament-user-profile`): Tab „Profil“
mit Foto, Namen, optionalem Spitznamen („Wie möchtest du genannt werden?“),
Handynummer, PLZ, Wohnort und Haushalt; Tab „Zugangsdaten“
mit E-Mail und Passwort (Filament fragt vorher das aktuelle Passwort ab). Weil
das Profil außerhalb jeder Gruppe liegt, nutzt es das schlichte Layout ohne
Navigation.

- **Profilfotos** liegen privat (`storage/app/private/profile-photos`) und
  kommen nur über `/profile-photos/{user}` heraus — an die Person selbst und an
  alle, die mit ihr eine Gruppe teilen (`AppServiceProvider`). Nur JPG, PNG und
  WebP (SVG könnte Skripte enthalten), im Browser rund zugeschnitten und
  verkleinert. Ersetzte Fotos werden nach dem Speichern gelöscht.
- **Ohne Foto** zeigt Filament Initialen auf einer Farbe aus dem Namen — lokal
  als SVG gezeichnet, auch für die Gruppen-Avatare. Filaments Standard würde
  jeden Namen an ui-avatars.com schicken.
- **Mitgliederliste**: Foto, Spitzname, E-Mail, Handynummer (als `tel:`-Link),
  Wohnort und Haushaltsgröße sehen alle Mitglieder der Gruppe, dazu die Summe
  („6 Haushalte mit zusammen 17 Personen“).

---

## 8. Rollen & Berechtigungen

Drei Rollen pro Gruppe: `Owner`, `Moderator`, `Participant` (Enum `GroupRole`
mit `can()`-Matrix). Zusätzlich gibt es pro Runde die Rolle **Lead**. Die Regeln
stecken in **Policies**, die Filament automatisch für Resourcen und Aktionen nutzt:

| Policy | Regel |
|---|---|
| `RoundPolicy` | Runden sehen alle Mitglieder, Entwürfe nur ihr Lead. **Anlegen**: Owner + Moderatoren — wer anlegt, ist Lead. **Lead-Aktionen** (`manage`): Lead, ersatzweise Gruppen-Owner. **Löschen**: nur Entwürfe und abgebrochene Runden. `shop`: Einkaufsphase, nicht ausgeschlossen, Teilnehmer-Limit. `propose`: Lead in Verhandlung/Bestätigung, alle aktiven Teilnehmer in der Bestätigung (Gegenvorschläge). `vote`: aktive Teilnehmer. |
| `ProductPolicy` / `ManufacturerPolicy` | Ändern, archivieren, wiederherstellen nur Moderatoren der Eigentümer-Gruppe; endgültig löschen nur Unbestelltes; keine Massen-Löschung |
| `GroupPolicy` | Gruppen-Einstellungen: Owner + Moderatoren. Einladen/Rollen: Owner + Moderatoren. **Mitglieder entfernen, Owner-Rolle übergeben und Gruppe auflösen: nur der Owner.** Verlassen: alle außer dem Owner. |
| `NotePolicy` / `AttachmentPolicy` | Notizen und Dokumente schreiben alle Mitglieder, löschen dürfen Autor:in, Moderatoren bzw. der Lead. An Runden bleiben sie in der Gruppe; an **geteilten** Herstellern/Produkten sehen sie alle Gruppen — Personen anderer Gruppen ohne Namen. |

**Lead-Übergabe**: Wer eine Runde anlegt, ist ihr Lead. Übergeben geht nur
mit Zustimmung: Der Lead (oder Owner) fragt an, die Person bekommt eine Mail und
nimmt auf der Rundenseite an oder lehnt ab (`LeadHandover`). Bis dahin bleibt
der bisherige Lead zuständig. Mit der Rolle wandert die Aufwandsentschädigung.

**Owner-Übergabe**: genauso nur mit Zustimmung. Der Owner fragt auf der
Mitglieder-Seite ein Mitglied an, die Person bekommt eine Mail und sieht die
Anfrage auf der Mitglieder-Seite und unter „Was steht für dich an?“
(`OwnerTransfer`). Bis zur Zustimmung bleibt der bisherige Owner zuständig,
danach ist er Moderator und kann die Gruppe verlassen. Verlässt die angefragte
Person die Gruppe oder wird entfernt, verfällt die Anfrage.

**Warum keine `spatie/laravel-permission`?** Drei feste Rollen, tenant-scoped,
Enum mit `can()` reicht und spart Abhängigkeiten.

**Serverseitig durchgesetzt**: Alle schreibenden Aktionen auf den Seiten sind
Filament-Actions mit `visible()`/`authorize()`. Filament weigert sich,
versteckte oder nicht autorisierte Actions zu mounten oder auszuführen — auch
bei manipulierten Requests. Datensätze aus Action-Argumenten werden immer über
die aktuelle Runde bzw. Gruppe aufgelöst.

**Achtung beim Rendern**: Eine von Hand im Blade platzierte Action
(`{{ ($this->xAction)([...]) }}`) rendert Filament auch dann, wenn sie
unsichtbar ist. Deshalb laufen alle solchen Stellen über die Komponente
`<x-foodpecker.action :action="…" />`, die nur sichtbare Actions ausgibt.

---

## 9. Phasen-State-Machine

```
draft → shopping → negotiating → finalizing → payment → ordering → delivery → pickup → completed
            ↑____________|  ↑___________|
(jede Phase bis ordering) → cancelled
```

| Von            | Nach             | Wer            | Bedingung |
|----------------|------------------|----------------|-----------|
| draft          | shopping         | Lead           | mind. 1 Abholtermin, Abholort gesetzt, keine andere laufende Runde in der Gruppe |
| shopping       | negotiating      | Lead           | mind. 1 Warenkorb (ohne Ausgeschlossene) |
| negotiating    | shopping         | Lead           | Rücksprung für Korrekturen |
| negotiating    | finalizing       | Lead           | mind. 1 zur Abstimmung freigegebener Vorschlag |
| finalizing     | negotiating      | Lead           | Rücksprung; eine gewählte finale Bestellung wird aufgehoben |
| finalizing     | payment          | Lead           | **einstimmiger** Vorschlag als finale Bestellung gewählt |
| payment        | ordering         | Lead           | **alle Zahlungen bezahlt oder erlassen** |
| ordering       | delivery         | Lead           | manuell, sobald Bestellung beim Hersteller raus |
| delivery       | pickup           | Lead           | manuell, sobald Ware da |
| pickup         | completed        | Lead           | manuell |
| (bis ordering) | cancelled        | Lead oder Owner| **Grund ist Pflicht** |

„Lead“ heißt immer: Lead oder ersatzweise Gruppen-Owner.

**Eine laufende Runde pro Gruppe**: Gestartete Runden „laufen“ bis zum
Abschluss oder Abbruch (`Round::running()`, `Group::runningRound()`). Eine
Gruppe hat höchstens eine davon — ein Entwurf lässt sich erst starten, wenn die
laufende Runde vorbei ist. Vorbereiten darf man ihn schon vorher. Das Dashboard
ist darauf zugeschnitten: zuerst „Was steht für dich an?“ (`MyTasks`), dann
„Aktuelle Bestellrunde“ (`CurrentRound`), zuletzt die Kennzahlen der Gruppe.
„Mein Warenkorb“ gehört immer zur laufenden Runde — änderbar im Einkauf,
danach nur noch lesbar. Die Runden-Liste kennt nur „Aktuell“ (laufende Runde
und eigene Entwürfe) und „Historie“ (abgeschlossen und abgebrochen).

Implementierung: `App\Services\Rounds\PhaseTransitioner` mit `nextPhase()`,
`previousPhase()`, `missingRequirements()` (für die Checkliste im
„Weiter zu: …“-Dialog) und `transition()`. Jeder Wechsel erzeugt einen
`Activity`-Eintrag. Beim Wechsel zu `ordering` werden die gezahlten
Gebindepreise als `PriceObservation` gespeichert — die Produktkarte zeigt sie
als „Zuletzt tatsächlich bezahlt“ (bei öffentlichen Produkten auch die anderer
Gruppen, anonym als „andere Gruppe“).

Tests: `tests/Feature/Rounds/PhaseTransitionerTest.php`.

---

## 10. Konsens & Ausschluss

Die fachliche Regel steht in [`docs/concept.md`](docs/concept.md) (Abschnitt
„Finalisierungs- / Bestätigungsphase“). Technisch:

- `ConsensusChecker` bewertet einen Vorschlag: pro Position zählen nur die
  **Betroffenen** (Personen mit Zuteilung > 0). Eine Position ist angenommen,
  wenn alle Betroffenen 👍 gegeben haben; einstimmig ist ein Vorschlag, wenn
  alle Positionen angenommen sind und keine ausgeschlossene Person darin
  vorkommt. Liefert außerdem, wer noch fehlt und wer mit welcher Begründung
  blockiert.
- `ProposalWorkflow` kapselt die Zustandswechsel: freigeben (nur Entwürfe mit
  Positionen), zurückziehen, abstimmen (nur freigegebene Vorschläge, nur aktive
  Teilnehmer, 👎 nur mit Begründung), wählen (nur einstimmig, nur in der
  Bestätigungsphase; legt Zahlungen und Abholungen an) und Wahl aufheben.
- `ParticipantExclusion` schließt Teilnehmer aus — als letzter Ausweg beim
  Vorbereiten einer neuen Version: Die Aktion steckt im „⋯“-Menü eines
  Entwurfs (Phasen Verhandlung und Bestätigung) und bietet nur an, wer einem freigegebenen
  Vorschlag nicht zugestimmt oder nicht abgestimmt hat (`candidatesFor()`).
  Grund Pflicht, Lead nie, nur in Verhandlung und Bestätigung. Entwürfe mit
  der Person werden ohne sie neu berechnet; freigegebene Vorschläge werden
  zurückgezogen, eine gewählte finale Bestellung wird aufgehoben. Wieder
  aufnehmen (in der Teilnehmerliste der Übersicht) geht bis zur Bestätigung
  (auch nach einem Rücksprung in den Einkauf) und rechnet die Entwürfe mit
  der Person neu.
- `ProposalBuilder::recalculate()` bringt einen Entwurf auf den Stand der
  aktiven Warenkörbe: Gebinde und verhandelte Preise bleiben, die Mengen werden
  neu verteilt, Produkte ohne Nachfrage fallen weg, neue kommen mit passender
  Gebindegröße dazu. `createNewVersion()` kopiert einen Vorschlag und rechnet
  ihn so neu — als Entwurf, der neu abgestimmt wird.

Tests: `tests/Feature/Rounds/ConsensusRuleTest.php`,
`tests/Feature/Rounds/ParticipantExclusionTest.php`.

---

## 11. Benachrichtigungen

**Konzept-Anforderung**: kein automatischer E-Mail-Spam — manueller Versand mit
auto-generiertem, editierbarem Entwurf, der seit der letzten Benachrichtigung
gemachten Änderungen zusammenfasst.

**Umsetzung**:

- Tabelle `notification_drafts` mit `kind` (Enum `NotificationKind`), Betreff,
  Text, `sent_at`, `sent_by_user_id`, `recipient_count`
- `DraftBuilder` fasst die `Activity`-Einträge seit dem letzten Versand in
  lesbaren Sätzen zusammen („Marie: Phase Einkauf → Verhandlung“)
- Nach jedem Phasenwechsel bietet die Seite direkt den passenden Entwurf an; der
  Lead passt Betreff und Text an und schickt ihn ab oder speichert ihn nur
- `DraftSender` verschickt eine `RoundNotificationMail` pro Empfänger, mit dem
  Lead als Reply-To. Empfänger: im Entwurf und in der Einkaufsphase alle
  Gruppenmitglieder, danach die aktiven Teilnehmer der Runde (umschaltbar)

Fällt eine Adresse beim Versand aus, gehen die übrigen trotzdem raus; der
Entwurf gilt als verschickt, der Lead sieht, wen es nicht erreicht hat — so
kann ein zweiter Klick niemanden doppelt anschreiben.

Lokal läuft der `log`-Mailer (alles landet in `storage/logs/laravel.log`) — die
Oberfläche weist darauf hin. Produktiv per `MAIL_MAILER=smtp`; prüfen mit
`php artisan app:send-test-mail`.

**Mails an Hersteller** (Konzept „Email-Vorlagen“): Auf der Rundenseite erzeugt
`ManufacturerMailComposer` pro Hersteller eine **Preisanfrage** (erwartete Mengen
aus den Warenkörben, bekannte Gebinde, Wunsch-Liefertermin) oder die
**Bestellung** (Positionen der finalen Bestellung mit verhandelten Preisen,
Lieferadresse). Der Lead passt den Text an und öffnet ihn in seinem
Mailprogramm oder kopiert ihn — die Plattform verschickt nichts an Dritte, und
Antworten landen direkt beim Lead.

---

## 12. Aktivitäten-Stream & Notizen

**Aktivitäten-Stream**: eigene Tabelle `activities`, befüllt über
`$model->logActivity(string $action, array $props = [])` (Trait
`HasActivities`). `Activity::describeAction()` macht daraus deutsche Sätze.

Hersteller und Produkte protokollieren Anlegen, Änderungen (mit Feldnamen),
Archivieren und geänderte Preisstaffeln.

Eine Aktivität gehört zur Gruppe der **handelnden Person** (aktueller Tenant),
nicht zur Eigentümer-Gruppe des Eintrags. Nur so bleiben Namen anderer Gruppen
auf geteilten Einträgen verborgen. Einträge ohne Gruppe (z. B. aus einer
aufgelösten Gruppe) erscheinen ebenfalls ohne Namen.

**Gruppen-Verlauf**: Auf der Mitglieder-Seite sieht jedes Mitglied, was sich an
der Gruppe geändert hat — Gründung, geänderte Einstellungen, Beitritte,
Austritte, Entfernungen, Rollenwechsel und Owner-Übergaben, jeweils mit Namen.
Einladungen nennen E-Mail-Adressen und erscheinen deshalb nur für Owner und
Moderatoren, wie die Liste offener Einladungen. Mitgliedschaften ändern sich
nur über `GroupMembership` und `GroupInvitation::accept()`, damit nichts am
Verlauf vorbeigeht. Wer handelt, geben die Services mit, wo sie es wissen
(`logActivity(..., $actor)`) — z. B. beim Beitritt über einen Einladungslink.

**Notizen und Dokumente**: polymorphe Modelle `Note` und `Attachment`, genutzt
über den Trait `InteractsWithNotesAndDocuments` auf der Rundenseite (in der
Übersicht) und auf den Detailseiten von Herstellern und Produkten.
Dateien liegen auf der **privaten** Disk (`storage/app/private/attachments/…`)
und werden nur über `GET /dokumente/{attachment}` nach Rechteprüfung
ausgeliefert (`AttachmentController`, `AttachmentPolicy`). Erlaubt sind PDF,
Bilder, Text/CSV, Mails und Office-Dateien bis 10 MB.

---

## 13. Lokalisierung

**UI-Sprache**: durchgängig Deutsch.

- `APP_LOCALE=de`, `APP_FALLBACK_LOCALE=en`
- Filament bringt seine deutschen Übersetzungen mit
- Laravels Validierungs-, Login- und Passwort-Meldungen liegen auf Deutsch in
  `lang/de/` (mit `:Attribute`, damit Feldnamen großgeschrieben erscheinen)
- Domänenspezifische Labels werden inline auf Deutsch geschrieben
  (`->label('Hersteller')`)

---

## 14. Verzeichnis-Struktur (Domänen-Code)

```
app/
├── Enums/                        GroupRole, RoundPhase, ProposalStatus, VoteValue,
│                                 PaymentStatus, Visibility, PackagingStrategy,
│                                 ProductCategory, ProductUnit, QuantityMode, NotificationKind
├── Models/                       (Eloquent + Relations + Scopes)
├── Policies/                     RoundPolicy, ProductPolicy, ManufacturerPolicy, GroupPolicy,
│                                 NotePolicy, AttachmentPolicy
├── Services/
│   ├── Distribution/             Distributor, PackageSpec, DistributionResult
│   ├── Money/                    Money, OrderCalculator
│   ├── Proposals/                ProposalBuilder, ProposalWorkflow
│   ├── Rounds/                   PhaseTransitioner, ConsensusChecker, ParticipantExclusion,
│   │                             LeadHandover, CartService, OrderHistory, RoundUpDonation,
│   │                             PriceObservationRecorder
│   ├── Groups/                   GroupDissolution, GroupMembership, OwnerTransfer
│   ├── Invitations/              GroupInvitationService
│   └── Notifications/            DraftBuilder, DraftSender, ManufacturerMailComposer
├── Filament/
│   ├── Concerns/                 InteractsWithNotesAndDocuments, ResolvesAuthorNames
│   ├── Forms/                    MoneyInput (+ MoneyStateCast), UserFields
│   ├── Resources/
│   │   ├── Rounds/               RoundResource, Schemas/RoundForm, Schemas/CartItemForm,
│   │   │                         Pages/ViewRound + Pages/Concerns/* (Aktionen nach Bereich)
│   │   ├── Products/             ProductResource, Schemas/ProductForm, Pages/ViewProduct
│   │   └── Manufacturers/        ManufacturerResource, Pages/ViewManufacturer,
│   │                             RelationManagers/ProductsRelationManager
│   ├── Pages/                    MyCart, MyOrders, Members, Auth/* (Login, Register,
│   │                             EditProfile), Tenancy/*
│   └── Widgets/                  MyTasks, CurrentRound, GroupStatsOverview
├── Http/Controllers/             InvitationController, AttachmentController (Routen außerhalb Panel)
├── Mail/                         GroupInvitationMail, GroupDissolvedMail, RoundNotificationMail,
│                                 LeadHandoverRequestedMail, OwnerTransferRequestedMail
└── Concerns/                     HasActivities, HasAttachments, HasNotes
```

Die Runden-Seite (`ViewRound`) hat drei Sektionen, jede Information steht nur
an einer Stelle:

- **Ablauf**: die Phasen als klickbare Schritte mit ihrem Datum
  (`?phase=payment`, auch vom Dashboard aus). Darunter steht alles, was in der
  gewählten Phase passiert, samt Aktionen (`resources/views/filament/rounds/phases/`):
  Einkauf die Warenkörbe — ein Klick auf eine Menge ändert sie, die eigene
  immer, als Lead auch die der anderen (`editCartItem`); die eigene Spalte
  steht schon vor der ersten Bestellung da, Produkte ohne Zeile kommen über
  „Produkt hinzufügen“ unter der Tabelle dazu —, Verhandlung und
  Bestätigung die Vorschläge, Zahlung die Zahlungen, Bestellung die
  Bestellliste je Hersteller mit vorformulierter Mail, Abholung Abholort,
  Termine und wer schon abgeholt hat. „Weiter zu …“ (bei Entwürfen
  „Bestellrunde starten“) steht immer oben rechts im Ablauf.
- **Verlauf & Benachrichtigungen**: eine Zeitleiste aus dem Aktivitäten-Stream
  und den verschickten Benachrichtigungen (Text aufklappbar); darüber sieht
  der Lead unversendete Entwürfe und erzeugt neue.
- **Übersicht**: Eckdaten (Beschreibung, Aufwandsentschädigung,
  Vereinsbeitrag), Teilnehmer (mit Ausschlüssen und „Wieder aufnehmen“),
  Notizen und Dokumente.

Jeder Vorschlag ist eine auf- und zuklappbare Tabelle (`partials/proposal.blade.php`,
der Zustand bleibt im Browser gespeichert): Positionen
als Zeilen, eine Spalte pro Person — die eigene zuerst, mit den
Abstimm-Buttons —, darunter Versand, Beiträge und Summe pro Person. Die
Begründung eines 👎 zeigt ein Tooltip am Daumen.

Bewusst doppelt: die Vorschläge in Verhandlung und Bestätigung (sie gehören zu
beiden Phasen) und die Prozentsätze der Beiträge in den Eckdaten und in der
Kostenzeile jedes Vorschlags (dort erklären sie die Beträge).

**Badge-Farben**: Die Enums färben ihre Badges mit Tailwind-Farbnamen (Phase
„Bestätigung“ `amber`, Kategorien, Owner-Rolle …). Filament kennt nur die
Farben, die das Panel registriert (`GlobalPanelProvider`), sonst bleibt eine
Badge farblos. Neue Farbnamen dort ergänzen — `BadgeColorsTest` prüft das.

---

## 15. Vorzeigbare Demo

Nach `php artisan migrate:fresh --seed` (nur lokal — auf Servern legt der
Seeder bewusst keine Demo-Daten mit bekannten Passwörtern an):

- **3 Gruppen**: "Speisekammer Schöneberg", "Hofgemeinschaft Lichtenrade",
  "Familie Müller & Friends"
- **7 Demo-Nutzer** mit unterschiedlichen Rollen-Konstellationen
- **Hersteller** (privat + öffentlich, u. a. einer, der einer anderen Gruppe gehört)
- **Produkte** für alle fünf Verpackungs-Szenarien
- **3 Runden**:
  - abgeschlossen: "Spätsommer-Bestellung 2025" mit Zahlungen, Abholungen,
    Preis-Beobachtungen, Notizen
  - in Bestätigung: "Frühjahr-Bestellung 2026", die laufende Runde in
    Schöneberg, mit zwei Vorschlägen — **Vorschlag A** ist einstimmig bis auf
    Jonas, der den Reis mit Begründung blockiert (Demo für den Ausschluss:
    „Neue Version“ erstellen und Jonas im Entwurf über „⋯“ ausschließen),
    **Vorschlag B** ist noch offen
  - im Einkauf: "Sommer-Bestellung 2026" in Lichtenrade (Lead Tobias, Marie
    macht mit)

**Login**: `marie@foodpecker.test` / `password` (lokal vorausgefüllt).

---

## 16. Tests

- **Feature-Tests** (`php artisan test --compact`): Domänenlogik, Regeln,
  Berechtigungen und die Filament-Seiten per Livewire — inklusive manipulierter
  Requests auf versteckte Aktionen.
- **Browser-Walkthrough** (`vendor/bin/pest tests/Browser`, Pest 4 + Playwright):
  klickt die Hauptseiten in echtem Headless-Chrome durch, prüft JavaScript-Fehler
  und legt Screenshots unter `tests/Browser/Screenshots/` ab (gitignored).
  Einmalig nötig: `npx playwright install chromium` passend zur installierten
  Playwright-Version.

---

## 17. Plugin-Empfehlungen für später

Im Prototyp **nicht installiert**, aber für den Produktiv-Einsatz interessant:

- **bezhansalleh/filament-shield** — falls die Permission-Matrix wächst
- **awcodes/filament-curator** — Asset/Media-Library für Anhänge
- **dotswan/filament-laravel-pulse** — Health-Dashboard direkt im Panel
- **filament/spatie-laravel-activitylog-plugin** — Activity-Stream im UI
- **leandrocfe/filament-apex-charts** — Charts für das Runden-Dashboard

---

## 18. Deployment (Plesk)

Deployt wird per [`mmoollllee/laravel-deployer`](https://github.com/mmoollllee/laravel-deployer)
(In-Place-`git pull` auf Plesk, Deployer 8). `deploy.php` liest die Server-Daten
aus der **lokalen** `.env` (`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`,
`DEPLOY_PHP`, `DEPLOY_SSH_KEY`), damit sie nicht im öffentlichen Repo stehen.

Plesk setzt beim Veröffentlichen der Filament-Assets (`public/css|js|fonts/filament`)
das Ausführbar-Bit. Damit Git sie deshalb nicht für geändert hält und `git pull`
blockiert, stellt `deploy.php` im Server-Repo `core.fileMode=false` ein.

Auf dem Server gehört in die `.env`: `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL=https://…` (daraus entstehen die Einladungslinks), SMTP-Zugang und —
falls ein Reverse Proxy davor sitzt — `TRUSTED_PROXIES`. Ist `APP_URL` https,
erzwingt die App https für alle generierten Links.

---

## 19. Bewusst _nicht_ getan

- **Kein Sub-Domain-Routing** für Tenants. Pfad-Routing (`/g/{slug}`) ist
  einfacher zu testen und für Herd praktischer.
- **Kein Vue/React-SPA-Frontend.** Filament reicht für die Verwaltungs-UI.
- **Keine PWA, kein Push.** Manuelle E-Mails sind Konzept-Anforderung.
- **Kein Konfliktlösungs-Workflow.** Konzept sagt: "Vertrauen, Konflikte
  außerhalb der Plattform". Notizen an Runden reichen; als letzter Ausweg bei
  Blockaden hilft der Ausschluss einzelner Teilnehmer (§10).
- **Kein Echtzeit-Update.** Refresh ist OK.
- **Kein automatischer Stripe/Banking-Anschluss.** Zahlung läuft außerhalb,
  Lead hakt manuell ab.
- **Soft Deletes nur für Produkte** — dort, wo Bestellungen auf Stammdaten zeigen.

---

## 20. Offene Punkte

- **Deployment-Daten**: `DEPLOY_*` in der lokalen `.env` und die Server-`.env`
  (SMTP, `APP_URL`, ggf. `TRUSTED_PROXIES`) muss jemand mit Serverzugang
  eintragen, danach `php artisan deploy`.
