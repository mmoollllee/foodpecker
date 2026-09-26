# Foodpecker — Architektur

> Stand: 2026-09-26 — Prototyp nach dem ersten Testlauf überarbeitet. Entscheidungen
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
| Datumsfelder | dev     | [`codewithkyrian/filament-date-range`](https://github.com/mmoollllee/filament-date-range) aus dem eigenen Fork (Branch `feat/l13+performance`, wie in nest): Abholtermine als Zeitfenster, Zeitplan im Einzeldatum-Modus (`singleDate()`), Daten auch eintippbar (`editableInputs()`, global in `AppServiceProvider`) |

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

**Sichtbarkeits-Modell für Lieferanten/Produkte**:

- `group_id` = **Eigentümer-Gruppe**, `visibility` = teilen ja/nein. Nur nach
  dem Auflösen einer Gruppe ist `group_id` leer: Der Eintrag gehört dann allen.
  Die erste Gruppe, deren Moderator ihn bearbeitet, übernimmt ihn.
- `visibility=private` → nur in der eigenen Gruppe sichtbar
- `visibility=public` → alle Gruppen können es lesen und bestellen; **ändern
  dürfen nur Moderatoren der Eigentümer-Gruppe** (Policies)
- Produkte sind nur öffentlich, wenn ihr Lieferant öffentlich ist. Wird ein
  Lieferant privat, werden seine Produkte mit privat; solange andere Gruppen
  Produkte für ihn haben, muss er öffentlich bleiben.
- `Supplier`/`Product` sind deshalb **nicht** strikt tenant-scoped: ihre
  Filament-Resourcen setzen `protected static bool $isScopedToTenant = false`
  und filtern über `visibleTo($group)`
- Produkte werden **archiviert** (Soft Delete) statt gelöscht; endgültig löschen
  geht nur, solange kein Warenkorb, Vorschlag oder Preis-Beobachtung darauf zeigt.
  Lieferanten lassen sich nur ohne Produkte löschen.
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
  Gruppe sehen konnte: private Lieferanten/Produkte (mit Bild), Notizen,
  Dokumente, Verlauf und Preis-Historie an privaten Einträgen. Dateien werden
  erst nach dem Commit entfernt.
- **Bleiben** und gehören danach allen (`group_id = null`, bis eine andere
  Gruppe sie pflegt und damit übernimmt): geteilte Lieferanten und Produkte mit
  ihren Notizen, Dokumenten und Preisen — ohne Namen. Private
  Produkte, die eine andere Gruppe schon im Warenkorb, in einem Vorschlag, im
  Sortiment oder in der Preis-Historie hat, bleiben **archiviert** erhalten;
  Lieferanten bleiben, solange noch ein Produkt auf sie zeigt.
- Die Konten der Mitglieder bleiben; auf Wunsch bekommen sie eine Mail
  (`GroupDissolvedMail`). Der Owner landet danach in seiner nächsten Gruppe
  bzw. beim Gründen einer neuen.

**Spätere Erweiterungs-Option** für tenant-übergreifendes Teilen ohne Public-Flag
(z. B. "Gruppe A teilt diesen Lieferanten exklusiv mit Gruppe B"): Pivot-Tabelle
`group_supplier` mit `is_shared`-Flag — bewusst **nicht** in dieser Phase.

**„Hersteller“ heißen jetzt „Lieferanten“** — Code und Datenbank eingeschlossen
(`Supplier`, Tabelle `suppliers`, `products.supplier_id`). Die Migration
benennt um und zieht die polymorphen Typen von Notizen, Dokumenten und
Aktivitäten nach.

---

## 3. Datenmodell
Übersicht der zentralen Entitäten und ihrer Beziehungen. Detailliertes
Migrations-Schema im Code (`database/migrations/`).

```
User ──┬──< GroupUser (pivot, role) >── Group ─< GroupInvitation
       │                                  │
       │                                  ├──< Supplier ──< Product ──< PriceTier (Gebindegröße)
       │                                  │    (group_id = Eigentümer, visibility, Product: soft deletes, portion_size)
       │                                  │
       │                                  └──< Round ──┬──< RoundParticipant (removed + Grund = ausgeschlossen)
       │                                               ├──< PickupDate (Zeitfenster von–bis)
       └──< CartItem >──── Round                       ├──< RoundSupplier (angefragt, Rückmeldung, Versand, bestellt, angekommen)
                                                       ├──< RoundPackagePrice (bestätigter Preis, lieferbar)
                                                       ├──< OrderProposal (based_on → kopierter Vorschlag) ──< ProposalItem (ein Produkt)
                                                       │                       ├─< ProposalItemPackage (Gebinde-Snapshot × Anzahl)
                                                       │                       ├─< ProposalAllocation (Menge, von Hand?, eigene Packungen)
                                                       │                       └─< ProposalVote
                                                       ├──< Payment (pro Teilnehmer)
                                                       ├──< Pickup  (pro Teilnehmer, gewähltes Zeitfenster)
                                                       └──< NotificationDraft

Note        (polymorph: Supplier | Product | Round)
Attachment  (polymorph: Round | Supplier | Product; Dateien auf der privaten Disk)
Activity    (polymorph: Round | Supplier | Product | OrderProposal | Group)
PriceObservation (tatsächlich gezahlte Gebindepreise, pro Produkt)
```

**Geld** ist konsequent in **Integer-Cent** modelliert (Spalten `*_cents`).
Eingegeben wird aber in Euro: die Formular-Komponente `MoneyInput` wandelt
„12,50“ in 1250 Cent und zurück (`Money::parse()` / `Money::toInputString()`).
Prozente sind `decimal(5,2)` und werden im `Money`-Service angewendet.

**Mengen** sind `decimal(12,3)` mit Einheit am Produkt (`ProductUnit`-Enum:
`kg`, `g`, `l`, `ml`, `stk`, `glas`, `pkg`).

**Vorschlagspositionen speichern ihre Gebinde als Snapshot**
(`proposal_item_packages`: Bezeichnung, Inhalt, bestätigter und Listenpreis,
Artikelnummer, Mindestbestellmenge, Anzahl). Gelöschte Gebindegrößen machen
alte Vorschläge nicht kaputt (`price_tier_id` wird dann `null`).

**Lieferanten-Rückmeldung pro Runde**: `round_suppliers` merkt sich, wann der
Lead angefragt hat, wann die Antwort kam und was der Versand kostet;
`round_package_prices` hält bestätigte Preise (leer = Listenpreis) und nicht
lieferbare Gebinde. Beides gilt für alle Entwürfe der Runde
(`RoundPriceBook`); freigegebene Vorschläge behalten ihren Stand — auch den
Versand pro Lieferant (`order_proposals.shipping_by_supplier`). Steht die
finale Bestellung, übernimmt `CatalogPrices` die bestätigten Preise auf
Wunsch ins Sortiment — vorher würde das den Unterschied zum Listenpreis
verwischen, den die Benachrichtigung zur Abstimmung zeigt. Nach der
Bestellung hakt der Lead pro Lieferant „bestellt“ und „angekommen“ ab
(`SupplierOrders`); offene Haken sind Hinweise beim Phasenwechsel, keine
Voraussetzung.

**Versionen**: Neue Versionen und Gegenvorschläge merken sich den kopierten
Vorschlag (`order_proposals.based_on_proposal_id`). `ProposalComparison`
vergleicht beide pro Produkt (Gebinde, Preise, Mengen pro Person), dazu
Versand und Summen pro Person.

**Bankverbindung**: IBAN, Kontoinhaber und BIC stehen am Benutzer
(`users.iban` ohne Leerzeichen, geprüft mit `App\Rules\Iban`). Wer an einen
Lead zahlt, sieht sie in der Zahlungsphase mit einem GiroCode
(`App\Services\Money\GiroCode`, EPC-QR Version 002 über
`chillerlan/php-qrcode`, das Filament mitbringt).

---

## 4. Verpackungs-Logik
**Konzeptuelle Pflicht**: Alle Szenarien aus dem Concept müssen mit demselben
Modell darstellbar sein.

| Feld | Bedeutung |
|---|---|
| `products.unit` | Basiseinheit (`ProductUnit`) |
| `products.portion_size` | Portionsgröße beim Aufteilen, z. B. 0,5 kg oder 1 Glas — `null` heißt: nur ganze Packungen |
| `price_tiers` | 1..n Gebindegrößen: `label`, `package_amount`, `price_cents` (pro Gebinde), `min_order_packages`, `article_number`, `sort_order` |

Die frühere Auswahl „Verpackungs-Logik“ und der „Geschätzte Preis pro
Standard-Gebinde“ sind entfallen: Sie wirkten auf keine Berechnung bzw.
doppelten die Gebindepreise. Wie ein Produkt verteilt wird, entscheidet allein
die Portionsgröße; im Formular heißt das „In Portionen — abwiegen oder
abzählen“ oder „Nur ganze Packungen“.

| Concept-Szenario        | Portionsgröße | Gebinde |
|-------------------------|---------------|---------|
| 1 Dinkelmehl            | 0,5 kg        | 1, 5, 25, 50 kg — kombinierbar |
| 2 Reis 10/25/50 kg      | 0,5 kg        | 3 Größen |
| 3 Senf-Palette          | 1 Glas        | 12er-Karton |
| 4a Spaghetti 250 g/2 kg | —             | 2 Größen, jede Person bekommt ganze Packungen |
| 4b Spirelli 5 kg Karton | 0,1 kg        | 1 Größe |

**Gebinde kombinieren** (`PackageMixer`): Gesucht wird die Kombination mit dem
niedrigsten Preis pro Einheit, deren Menge zwischen der Summe der
Mindest- und der Höchstwünsche liegt — flexible Wünsche nehmen lieber etwas
mehr, wenn es dadurch für alle günstiger wird. Eine größere Kombination
gewinnt, wenn sie insgesamt weniger kostet (ein 25-kg-Sack statt sieben
3-kg-Kartons) — der Überhang wird anteilig mitbezahlt und ist trotzdem
billiger. Passt keine, gewinnt die günstigste, bei gleichem Preis die mit dem
kleineren Überhang. Technisch ist das ein
Rucksackproblem über ein Raster aus dem größten gemeinsamen Teiler der
Gebindegrößen (in Tausendstel der Einheit); Mindestbestellmengen werden über
alle Kombinationen „Größe genutzt / nicht genutzt“ abgedeckt. Ab einem sehr
feinen Raster fällt der Mixer auf die Größe mit dem niedrigsten Preis pro
Einheit zurück. Der Lead kann die Anzahl je Größe festlegen
(`ProposalItem.packages_fixed`); nicht lieferbare Größen fallen heraus.

---

## 5. Faire Aufteilung — Distribution-Algorithmus
`App\Services\Distribution\Distributor::distribute(array $demands, array $options, ?float $portionSize, …)`
→ `DistributionResult { mix, allocations[], notes[], overhang, shortfall }`

Eingaben sind `Demand`s (Warenkorb-Wunsch, ggf. mit von Hand gesetzter Menge)
und `PackageOption`s (Gebindegröße mit bestätigtem oder Listenpreis).

**In Portionen** (Portionsgröße gesetzt):

1. Gebinde-Mix für die Summe der Wünsche bestimmen (oder die von Hand
   festgelegten Anzahlen nehmen). Von Hand gesetzte Mengen zählen dabei als
   exakter Wunsch.
2. Von Hand gesetzte Mengen werden unverändert zugeteilt.
3. Exakte Wünsche und Mindestmengen der übrigen Personen werden zugeteilt, der
   Rest proportional zur Flexibilität verteilt — gerundet auf die Portionsgröße
   oder eine gröbere Rundung (`ProposalItem.rounding_step`, „Mengen runden“),
   Rundungsreste an den größten Spielraum. Wünsche, Handmengen und gröbere
   Rundungen sind immer ganze Portionen (`CartService`,
   `ProposalBuilder::setAllocation()`), damit kein Glas und keine Packung
   geteilt werden muss.
4. Reicht die Menge nicht, werden die Mindestmengen anteilig gekürzt.
5. Was niemand innerhalb seines Maximums will, bleibt als **Überhang** übrig
   und wird mitbezahlt. Mehr von Hand verteilt als bestellt ist eine
   **Fehlmenge** — dann kann der Vorschlag nicht zur Abstimmung.
6. Alle zahlen denselben Mischpreis: Die Preisanteile werden cent-genau
   proportional zu den Mengen verteilt.

**Ganze Packungen** (keine Portionsgröße): Jede Person bekommt die Packungen,
die zu ihrem Wunsch passen (bevorzugte Größe aus dem Warenkorb oder die
günstigste Kombination) und zahlt genau diese. Mindestbestellmengen gelten
für die ganze Bestellung; zusätzliche Packungen werden anteilig mitbezahlt.

**Diese Version ist explizit nicht optimal** — deterministisch,
nachvollziehbar, getestet. Der Vorschlagende korrigiert Mengen, Gebinde und
Rundung pro Position (`ProposalBuilder::setAllocation()`,
`setPackageCounts()`, `setRounding()`); Handkorrekturen bleiben bei jeder
Neuberechnung erhalten, auch beim Ausschluss einer Person.

Tests in `tests/Feature/Distribution/` und `tests/Feature/Proposals/ProposalBuilderTest.php`.

---

## 6. Geld-Modell
Alle Beträge intern **Integer Cent**. Ein einzelner Service kapselt Logik:

```php
App\Services\Money\OrderCalculator::calculate(OrderProposal $proposal): ProposalTotals
```

Komponenten:

- **Warenwert** = Σ der Positionen (Gebinde × bestätigter Preis)
- **Versand** pro Lieferant (`shipping_by_supplier`), verteilt im Verhältnis
  zum Warenwert, den jemand von diesem Lieferanten bekommt
- **Lead-Honorar** = `Σ warenwert × lead_fee_percent` → anteilig zu den Bestellsummen
- **Vereinsbeitrag** = `Σ warenwert × platform_fee_percent`, Default 1 %
- **Aufrund-Spende**: In der Zahlungsphase rundet jede Person ihren eigenen
  Anteil auf volle 1 € oder 10 € auf oder wählt einen eigenen Gesamtbetrag
  (`RoundUpDonation`). Der Lead sieht, was er insgesamt an den Verein
  weiterleitet (Vereinsbeitrag + Spenden).

Rundung: Prozente über Basispunkte (`intdiv`), Rundungsreste an den letzten
Teilnehmer — reproduzierbar, kein Floating-Point. Zahlungen und Abholungen
bekommen nur Personen, die tatsächlich etwas bekommen. Beim Wählen der
finalen Bestellung legt `ProposalWorkflow::choose()` sie an.

**Schätzungen** (`PriceEstimator`): Im Einkauf rechnet dieselbe Verteilung mit
den aktuellen Wünschen der Gruppe — pro Person und Produkt, inklusive
Beiträgen, ohne Versand. Beim Eintippen einer Menge schätzt `estimateWish()`
den Preis mit den Wünschen aller anderen, bei flexiblen Wünschen als Spanne.
In der Anpassung zeigt der Entwurf pro Person die Abweichung zur Schätzung mit
Listenpreisen.

Tests: `tests/Feature/Money/`, `tests/Feature/Estimates/`.

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
   Wer den Link hat, kommt rein (`GroupInvitation::acceptAs()`):
   - **Eingeloggt** → Beitritt, weiter in die Gruppe. Läuft das Konto unter
     einer anderen Adresse, wandert die Einladung auf diese Adresse
   - **Nicht eingeloggt + Konto mit der eingeladenen Adresse** → Login, danach
     zurück zum Link (`intended`) und Beitritt
   - **Nicht eingeloggt + kein solches Konto** → Registrierung mit
     vorbefüllter, änderbarer E-Mail; nach dem Anlegen automatischer Beitritt.
     Wer schon ein Konto unter einer anderen Adresse hat, meldet sich oben an
     und kommt über `intended` zum Link zurück
   - **Schon angenommen** → wer schon Mitglied ist, landet in der Gruppe; alle
     anderen bekommen einen Hinweis
4. Fehler (ungültig, abgelaufen, schon angenommen) erscheinen als
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
| `RoundPolicy` | Runden sehen alle Mitglieder, Entwürfe nur ihr Lead. **Anlegen**: Owner + Moderatoren — wer anlegt, ist Lead. **Lead-Aktionen** (`manage`): Lead, ersatzweise Gruppen-Owner. **Löschen**: nur Entwürfe und abgebrochene Runden. `shop`: Einkaufsphase, nicht ausgeschlossen, Teilnehmer-Limit. `propose`: Lead in Anpassung/Bestätigung, alle aktiven Teilnehmer in der Bestätigung (Gegenvorschläge). `vote`: aktive Teilnehmer. |
| `ProductPolicy` / `SupplierPolicy` | Ändern, archivieren, wiederherstellen nur Moderatoren der Eigentümer-Gruppe; endgültig löschen nur Unbestelltes; keine Massen-Löschung |
| `GroupPolicy` | Gruppen-Einstellungen: Owner + Moderatoren. Einladen/Rollen: Owner + Moderatoren. **Mitglieder entfernen, Owner-Rolle übergeben und Gruppe auflösen: nur der Owner.** Verlassen: alle außer dem Owner. |
| `NotePolicy` / `AttachmentPolicy` | Notizen und Dokumente schreiben alle Mitglieder, löschen dürfen Autor:in, Moderatoren bzw. der Lead. An Runden bleiben sie in der Gruppe; an **geteilten** Lieferanten/Produkten sehen sie alle Gruppen — Personen anderer Gruppen ohne Namen. |

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
| draft          | shopping         | Lead           | Abholort gesetzt, keine andere laufende Runde in der Gruppe — Abholtermine dürfen noch fehlen |
| shopping       | negotiating      | Lead           | mind. 1 Warenkorb (ohne Ausgeschlossene); der Bestellvorschlag entsteht dabei automatisch |
| negotiating    | shopping         | Lead           | Rücksprung für Korrekturen |
| negotiating    | finalizing       | Lead           | „Zur Abstimmung stellen“: der Entwurf des Leads geht ohne Fehlmenge zur Abstimmung (sonst mind. 1 freigegebener Vorschlag) |
| finalizing     | negotiating      | Lead           | Rücksprung; eine gewählte finale Bestellung wird aufgehoben |
| finalizing     | payment          | Lead           | **einstimmiger** Vorschlag als finale Bestellung gewählt — „Als finale Bestellung wählen“ wechselt gleich mit |
| payment        | ordering         | Lead           | **alle Zahlungen bezahlt oder erlassen** |
| ordering       | delivery         | Lead           | manuell, sobald die Bestellung bei den Lieferanten raus ist |
| delivery       | pickup           | Lead           | manuell, sobald Ware da — mind. 1 Abholtermin |
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

- `ConsensusChecker` bewertet einen Vorschlag: pro Position zählen die
  **Betroffenen** — alle, die das Produkt bestellt haben (eine Zuteilung,
  auch mit 0), außer ausgeschlossenen Personen. Eine Position ist angenommen,
  wenn alle Betroffenen 👍 gegeben haben; einstimmig ist ein Vorschlag, wenn
  alle Positionen angenommen sind und keine ausgeschlossene Person darin noch
  etwas bekommt. Liefert außerdem, wer noch fehlt und wer mit welcher
  Begründung blockiert.
- `ProposalWorkflow` kapselt die Zustandswechsel: freigeben (nur Entwürfe mit
  Positionen und ohne Fehlmenge), zurückziehen, abstimmen (nur freigegebene
  Vorschläge, nur aktive Teilnehmer, 👎 nur mit Begründung), wählen (nur
  einstimmig, nur in der Bestätigungsphase; legt Zahlungen und Abholungen an)
  und Wahl aufheben. Die Oberfläche verbindet „Zur Abstimmung stellen“ mit dem
  Wechsel in die Bestätigung und „Als finale Bestellung wählen“ mit dem
  Wechsel in die Zahlung.
- `ParticipantExclusion` schließt Teilnehmer aus — als letzter Ausweg beim
  Vorbereiten einer neuen Version: Die Aktion steckt im „⋯“-Menü eines
  Entwurfs (Phasen Anpassung und Bestätigung) und bietet nur an, wer einem
  freigegebenen Vorschlag nicht zugestimmt oder nicht abgestimmt hat
  (`candidatesFor()`). Grund Pflicht, Lead nie. Alle Entwürfe werden ohne die
  Person neu berechnet; freigegebene Vorschläge, in denen sie etwas bekommt,
  werden zurückgezogen, eine gewählte finale Bestellung wird aufgehoben.
  Wieder aufnehmen (in der Teilnehmerliste der Übersicht) geht bis zur
  Bestätigung (auch nach einem Rücksprung in den Einkauf).
- `ProposalBuilder::recalculate()` bringt einen Entwurf auf den Stand der
  aktiven Warenkörbe und der Lieferanten-Rückmeldungen: Handkorrekturen
  bleiben, Produkte ohne Nachfrage fallen weg, neue kommen dazu.
  `createNewVersion()` kopiert einen Vorschlag samt Korrekturen — das ist auch
  der Gegenvorschlag in der Bestätigung.

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
- `DraftBuilder::compose()` fasst die `Activity`-Einträge seit dem letzten
  Versand in lesbaren Sätzen zusammen („Marie: Phase Einkauf → Anpassung“);
  vor der Abstimmung zusätzlich, was die Lieferanten geändert haben (Preise,
  nicht lieferbare Gebinde, Versand)
- Jeder Phasenwechsel (auch Start, Abbruch und „Als finale Bestellung
  wählen“) zeigt den passenden Text direkt im Dialog: anpassen, Empfänger
  wählen, „Phase wechseln“ — der Wechsel und der Versand sind ein Schritt.
  Außerhalb von Phasenwechseln erzeugt „Benachrichtigung vorbereiten“ einen
  Entwurf, den der Lead speichern oder verschicken kann
- `DraftSender` verschickt eine `RoundNotificationMail` pro Empfänger, mit dem
  Lead als Reply-To. Empfänger: im Entwurf und in der Einkaufsphase alle
  Gruppenmitglieder, danach die aktiven Teilnehmer der Runde (umschaltbar)

Fällt eine Adresse beim Versand aus, gehen die übrigen trotzdem raus; der
Entwurf gilt als verschickt, der Lead sieht, wen es nicht erreicht hat — so
kann ein zweiter Klick niemanden doppelt anschreiben.

Lokal läuft der `log`-Mailer (alles landet in `storage/logs/laravel.log`) — die
Oberfläche weist darauf hin. Produktiv per `MAIL_MAILER=smtp`; prüfen mit
`php artisan app:send-test-mail`.

**Mails an Lieferanten** (Konzept „Email-Vorlagen“): `SupplierMailComposer`
formuliert pro Lieferant die **Preisanfrage** (erwartete Mengen, Gebinde mit
Artikelnummern, Wunsch-Liefertermin), das **Nachfassen**, wenn die Antwort
ausbleibt, und die **Bestellung** (Gebinde der finalen Bestellung mit
Artikelnummern, bestätigten Preisen, Versand und Lieferadresse). In der
Anpassung öffnet „Anfrage öffnen“ die Mail mit einem Klick im Mailprogramm und
vermerkt den Lieferanten als angefragt; „Text anpassen“ zeigt sie vorher an.
Die Plattform verschickt nichts an Dritte, Antworten landen direkt beim Lead.
Die Rückmeldung trägt der Lead auf der Lieferanten-Karte ein
(`SupplierFeedback::record()`); alle Entwürfe rechnen sich danach neu.

---

## 12. Aktivitäten-Stream & Notizen

**Aktivitäten-Stream**: eigene Tabelle `activities`, befüllt über
`$model->logActivity(string $action, array $props = [])` (Trait
`HasActivities`). `Activity::describeAction()` macht daraus deutsche Sätze.

Lieferanten und Produkte protokollieren Anlegen, Änderungen (mit Feldnamen),
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
Übersicht) und auf den Detailseiten von Lieferanten und Produkten.
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
  (`->label('Lieferant')`)

---

## 14. Verzeichnis-Struktur (Domänen-Code)

```
app/
├── Enums/                        GroupRole, RoundPhase, ProposalStatus, VoteValue,
│                                 PaymentStatus, Visibility, SupplierMailType,
│                                 ProductCategory, ProductUnit, QuantityMode, NotificationKind
├── Models/                       (Eloquent + Relations + Scopes)
├── Policies/                     RoundPolicy, ProductPolicy, SupplierPolicy, GroupPolicy,
│                                 NotePolicy, AttachmentPolicy
├── Services/
│   ├── Distribution/             Distributor, PackageMixer, PackageMix, PackageOption, Demand,
│   │                             DistributionResult, AllocationLine
│   ├── Estimates/                PriceEstimator, RoundEstimate, ProductEstimate
│   ├── Money/                    Money, OrderCalculator, GiroCode
│   ├── Proposals/                ProposalBuilder, ProposalWorkflow, ProposalComparison
│   │                             (+ ProposalChanges, ItemChange)
│   ├── Rounds/                   PhaseTransitioner, ConsensusChecker, ParticipantExclusion,
│   │                             LeadHandover, CartService, OrderHistory, RoundUpDonation,
│   │                             PriceObservationRecorder, SupplierFeedback, RoundPriceBook,
│   │                             SupplierOrders, CatalogPrices, PackingList
│   ├── Groups/                   GroupDissolution, GroupMembership, OwnerTransfer
│   ├── Invitations/              GroupInvitationService
│   └── Notifications/            DraftBuilder, DraftSender, SupplierMailComposer
├── Filament/
│   ├── Concerns/                 InteractsWithNotesAndDocuments, ResolvesAuthorNames
│   ├── Forms/                    MoneyInput (+ MoneyStateCast), UserFields
│   ├── Resources/
│   │   ├── Rounds/               RoundResource, Schemas/RoundForm, Schemas/CartItemForm,
│   │   │                         Pages/ViewRound + Pages/Concerns/* (Aktionen nach Bereich)
│   │   ├── Products/             ProductResource, Schemas/ProductForm, Pages/ViewProduct
│   │   └── Suppliers/            SupplierResource, Pages/ViewSupplier,
│   │                             RelationManagers/ProductsRelationManager
│   ├── Pages/                    MyCart, MyOrders, Members, Auth/* (Login, Register,
│   │                             EditProfile), Tenancy/*
│   └── Widgets/                  MyTasks, CurrentRound, GroupStatsOverview
├── Http/Controllers/             InvitationController, AttachmentController, PackingListController
│                                 (Routen außerhalb Panel)
├── Rules/                        Iban
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
  „Produkt hinzufügen“ unter der Tabelle dazu, voraussichtliche Preise pro
  Person und Produkt stehen dabei —, Anpassung die Lieferanten-Karten
  (anfragen, nachfassen, Rückmeldung eintragen) und den Entwurfs-Editor
  (`partials/draft-editor.blade.php`: pro Lieferant und Produkt die Gebinde,
  Mengen pro Person zum Ändern, Rundung, Abweichung zur Schätzung),
  Bestätigung die Vorschläge, Zahlung die Zahlungen mit Bankverbindung und
  GiroCode, Bestellung die Bestellliste je Lieferant mit vorformulierter Mail
  und dem Haken „bestellt“, Lieferung die Haken „angekommen“, Abholung
  Abholort, Zeitfenster und wer schon abgeholt hat. Ab der Lieferung gibt es
  die Packliste zum Drucken (`/runden/{round}/packliste`,
  `PackingListController`, `PackingList`). „Weiter zu …“ (bei Entwürfen
  „Bestellrunde starten“, in der Anpassung „Zur Abstimmung stellen“) steht
  immer oben rechts im Ablauf.
- **Verlauf & Benachrichtigungen**: eine Zeitleiste aus dem Aktivitäten-Stream
  und den verschickten Benachrichtigungen (Text aufklappbar); darüber sieht
  der Lead unversendete Entwürfe und erzeugt neue.
- **Übersicht**: Eckdaten (Beschreibung, Aufwandsentschädigung,
  Vereinsbeitrag), Teilnehmer (mit Ausschlüssen und „Wieder aufnehmen“),
  Notizen und Dokumente.

Entwürfe, die man selbst bearbeiten darf, erscheinen im Entwurfs-Editor; jeder
andere Vorschlag ist eine auf- und zuklappbare Tabelle (`partials/proposal.blade.php`,
der Zustand bleibt im Browser gespeichert): Positionen
als Zeilen, eine Spalte pro Person — die eigene zuerst, mit den
Abstimm-Buttons —, darunter Versand, Beiträge und Summe pro Person. Die
Begründung eines 👎 zeigt ein Tooltip am Daumen, „Allem zustimmen“ gibt
allen eigenen Positionen auf einmal ein 👍. Über jeder neuen Version steht,
was sie gegenüber dem kopierten Vorschlag ändert (`partials/proposal-changes.blade.php`).

„Mein Warenkorb“ zeigt den eigenen Warenkorb mit voraussichtlichen Preisen
und darunter das Sortiment der Runde zum Stöbern: Kacheln nach Kategorie,
Suche, Filter nach Lieferant, Preis pro Einheit bei der aktuellen Gesamtmenge
und freier Platz im Gebinde.

Bewusst doppelt: die Vorschläge in Anpassung und Bestätigung (sie gehören zu
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
- **Lieferanten** (privat + öffentlich, u. a. einer, der einer anderen Gruppe gehört)
- **Produkte** für alle Verpackungs-Szenarien, Dinkelmehl in vier
  kombinierbaren Gebindegrößen, Artikelnummern
- **4 Runden**:
  - abgeschlossen: "Spätsommer-Bestellung 2025" mit Zahlungen, Abholungen,
    Preis-Beobachtungen, Notizen
  - in Bestätigung: "Frühjahr-Bestellung 2026", die laufende Runde in
    Schöneberg, mit zwei Vorschlägen — **Vorschlag A** (Reis als 50-kg-Sack
    festgelegt) ist einstimmig bis auf Jonas, der den Reis mit Begründung
    blockiert (Demo für den Ausschluss: „Neue Version“ erstellen und Jonas im
    Entwurf über „⋯“ ausschließen), **Vorschlag B** ist noch offen
  - im Einkauf: "Sommer-Bestellung 2026" in Lichtenrade (Lead Tobias, Marie
    macht mit), noch ohne Abholtermine
  - in Anpassung: "Herbst-Bestellung 2026" bei Familie Müller & Friends (Lead
    Linus): Die Mühle hat mit Preisen und Versand geantwortet, das Senfwerk
    ist angefragt, Pasta Italia noch nicht

**Login**: `marie@foodpecker.test` / `password` (lokal vorausgefüllt), für die
Anpassung `linus@foodpecker.test`.

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
