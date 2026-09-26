# 🪶 Foodpecker

**Hamstern Pro**
> Eine Open-Source-Plattform für kollektive Sammelbestellungen — möglichst direkt beim Erzeuger.

---

## Was ist Foodpecker?

Foodpecker hilft Freundesgruppen, Lebensmittel und Grundversorgungsgüter (Reis, Mais, Nudeln, Senf, …) gemeinsam in großen Mengen möglichst direkt beim Erzeuger zu bestellen. Durch das Bündeln der Mengen werden Großgebinde- und Palettenpreise erreichbar. Gut für große Speisekammern.

Der Name ist abgeleitet vom englischen *Woodpecker*, dem **[Eichelspecht](https://www.youtube.com/watch?v=3CWGne2eGf4)**, der gemeinsam mit seinem Schwarm tausende Eicheln in einem Speicherbaum einlagert und über sie wacht, beschafft und verwaltet eine Foodpecker-Gruppe ihre Vorräte kollektiv.

## Grundwerte

- **Zusammenarbeit** — Moderatoren starten Bestellrunden, alle können mitbestellen, Gegenvorschläge machen und mitgestalten. Entscheidungen sind transparent und für alle sichtbar.
- **Fairness** — Niemand wird zu Mengen gezwungen, die er nicht möchte. Verpackungsbeschränkungen werden gemeinsam gelöst.
- **Flexibilität** — Zeitpläne lassen sich verschieben, Rollen mit Zustimmung übergeben. Das System passt sich an reale Gruppen an.
- **Vertrauen** — Nur für Freunde gedacht! Konflikte werden persönlich geklärt; die Plattform ist nur ein Hilfsmittel und Excel Ersatz.

## Kernfunktionen

- **Multi-Tenancy** — Mehrere Gruppen nutzen eine Plattform, jede in ihrem eigenen Raum. Lieferanten und Produkte können optional zwischen Gruppen geteilt werden.
- **Geteilte Lieferanten & Produkte** — Öffentliche Lieferanten und Produkte stehen allen Gruppen zur Verfügung. Echte Bestellpreise fließen als Richtwerte zurück in die Produktdaten; die von den Lieferanten bestätigten Preise übernimmt der Lead nach der Bestellung mit einem Klick ins Sortiment.
- **Flexible Verpackungslogik** — Gebindegrößen lassen sich kombinieren (17 kg = 10 + 5 + 1 + 1 kg); Produkte werden in Portionen abgewogen oder in ganzen Packungen ausgegeben.
- **Kollaborative Bestellrunden** — Moderatoren starten Runden mit Zeitplan (Einkauf → Anpassung → Bestätigung → Zahlung → Bestellung → Lieferung → Abholung), alle Mitglieder machen mit. Pro Gruppe läuft immer eine Runde; das Dashboard zeigt zuerst, was für dich ansteht, und dann diese Runde.
- **Einkaufen wie im Shop** — Das Sortiment lässt sich durchstöbern und filtern. Pro Artikel entweder eine exakte Menge oder eine flexible Spanne („zwischen 1 und 3 kg"); der voraussichtliche Preis steht schon beim Eintippen da.
- **Anpassung mit wenig Klicks** — Der Bestellvorschlag entsteht automatisch. Pro Lieferant: Anfrage mit einem Klick öffnen, nachfassen, Preise, nicht lieferbare Gebinde und Versand eintragen — der Vorschlag rechnet sich neu. Mengen pro Person lassen sich von Hand runden.
- **Konsens-basierte Bestellung** — Alle, die ein Produkt bestellt haben, stimmen pro Position mit Daumen hoch/runter ab — oder stimmen allem auf einmal zu. Jeder Vorschlag ist eine Tabelle mit einer Spalte pro Person; neue Versionen und Gegenvorschläge zeigen, was sich gegenüber dem Original geändert hat. Nur einstimmig bestätigte Bestellungen können gewählt werden; scheitern mehrere Vorschläge an einzelnen Personen, kann der Lead sie als letzten Ausweg in einer neuen Version ausschließen.
- **Manuelle, editierbare Benachrichtigungen** — Das System erzeugt einen Änderungs-Entwurf, den der Lead direkt im Phasenwechsel anpassen und verschicken kann. Kein E-Mail-Spam.
- **Mails an Lieferanten** — Preisanfrage, Erinnerung und Bestellung werden aus der Runde vorformuliert, mit Artikelnummern; der Lead schickt sie aus seinem eigenen Mailprogramm und hakt pro Lieferant „bestellt“ und „angekommen“ ab.
- **Bezahlen per GiroCode** — Hat der Lead seine Bankverbindung hinterlegt, sieht jeder Empfänger, IBAN, Betrag und Verwendungszweck zum Kopieren — und einen GiroCode für die Banking-App.
- **Packliste** — Für die Ausgabe eine druckbare Liste pro Person in der Reihenfolge der Abholtermine, dazu pro Produkt die Aufteilung der Gebinde.
- **Lead-Übergabe mit Zustimmung** — Der Lead kann die Runde abgeben, sobald die neue Person zustimmt; die Aufwandsentschädigung wandert mit.
- **Mitglieder & Profile** — Einladungen gehen an mehrere Adressen auf einmal (durch Komma getrennt). Bei der Registrierung gibt jede Person Handynummer, Wohnort und die Größe ihres Haushalts an; im Profil kommt ein Foto dazu. Die Mitglieder einer Gruppe sehen gegenseitig ihre Kontaktdaten.
- **Aktivitäten-Stream, Notizen & Dokumente** — Wer hat wann was geändert? Notizen und Dokumente (Preislisten, Bestellbestätigungen) zu Runden, Lieferanten und Produkten helfen, aus Erfahrungen zu lernen — bei geteilten Lieferanten auch gruppenübergreifend.
- **Historie** — „Meine Bestellungen“ zeigt vergangene Bestellungen mit eingefrorenen Preisen; im Warenkorb lassen sich die Mengen der letzten Runde übernehmen.
- **Faires Finanzmodell** — Aufwandsentschädigung (in %) für den Lead, ~1% Beitrag an die Foodpecker Organisation/Verein (tbd), freiwillig erhöhbar durch Aufrunden der eigenen Summe.

## Drei Rollen pro Gruppe

| Rolle | Berechtigungen |
|-------|----------------|
| **Owner** | Voller Zugriff, entfernt Mitglieder, übergibt die Owner-Rolle (mit Zustimmung), kann die Gruppe auflösen |
| **Moderator** | Produkte & Lieferanten verwalten, Runden anlegen, einladen, Rollen vergeben |
| **Participant** | Einkaufen, alle Warenkörbe einsehen, abstimmen, Gegenvorschläge machen, Gruppe verlassen |

Dazu kommt pro Runde der **Lead**: Er wechselt die Phasen, trägt die
Rückmeldungen der Lieferanten ein, wählt die einstimmig bestätigte Bestellung
und hakt Zahlungen ab.
Scheitern mehrere Vorschläge an einzelnen Personen, kann er sie als letzten
Ausweg beim Vorbereiten einer neuen Version ausschließen, damit niemand eine
Bestellung dauerhaft blockiert (Regeln: [`docs/concept.md`](docs/concept.md)).

## Tech-Stack

- **[Laravel](https://laravel.com)** — PHP-Backend
- **[Filament](https://filamentphp.com)** — Admin-Panel & UI
- **Lizenz** — Open Source (siehe [LICENSE](LICENSE))

## Status

🚧 **Prototyp, bereit für einen ersten Testlauf.** Eine Bestellrunde lässt sich
vom Entwurf bis zur Abholung durchspielen — mit Rollen und Rechten,
Konsens-Abstimmung, verhandelten Preisen, echten Benachrichtigungs-Mails und
Deployment auf Plesk. Architektur-Entscheidungen sind dokumentiert (siehe
[`ARCHITECTURE.md`](ARCHITECTURE.md)). Fachliche Grundlage:
[`docs/concept.md`](docs/concept.md). Achtung: Hier wird gevibecoded oder wie das heißt.

## Lokale Entwicklung

Voraussetzungen: PHP 8.3, Composer, Node, [Laravel Herd](https://herd.laravel.com).
Das Repo läuft erwartet unter `http://foodpecker.test`.

```bash
composer install
npm install
npm run build
php artisan migrate:fresh --seed
```

Anschließend `http://foodpecker.test` öffnen — die Login-Maske ist im
Dev-Modus mit den Demo-Credentials vorausgefüllt:

> **E-Mail:** `marie@foodpecker.test`
> **Passwort:** `password`

Mails (z. B. Einladungen) gehen im Dev-Modus in `storage/logs/laravel.log`.

### Tests

Domänen-, Rechte- und Filament-Tests:

```bash
php artisan test --compact
```

Browser-Walkthrough in einem echten Headless-Chrome (Pest 4 + Playwright).
Einmalig muss der zur installierten Playwright-Version passende Browser geladen
werden (`npx playwright install chromium`):

```bash
vendor/bin/pest tests/Browser
vendor/bin/pest tests/Browser --headed   # sichtbares Fenster zum Zuschauen
vendor/bin/pest tests/Browser --debug    # pausiert bei Fehler, öffnet Browser
```

Bei jedem Run werden Screenshots der wichtigsten Seiten unter
[`tests/Browser/Screenshots/`](tests/Browser/Screenshots/) abgelegt (gitignored).

## Deployment (Plesk)

Deployt wird mit [`mmoollllee/laravel-deployer`](https://github.com/mmoollllee/laravel-deployer).
Die Server-Daten stehen in der lokalen `.env` (Block „Deployment“ in
`.env.example`), dann:

```bash
php artisan deploy
```

Auf dem Server braucht die `.env` mindestens `APP_ENV=production`,
`APP_DEBUG=false`, `APP_URL=https://…`, einen SMTP-Zugang und ggf.
`TRUSTED_PROXIES`. Ob Mails rausgehen, prüft `php artisan app:send-test-mail`.
Demo-Daten werden auf Servern nicht angelegt. Details: `ARCHITECTURE.md` §18.

## Erster Testlauf mit Freunden

1. Auf dem Server registrieren und eine Gruppe gründen (du wirst Owner).
2. Unter „Lieferanten“ und „Produkte“ das Sortiment anlegen — pro Produkt, ob
   es in Portionen oder ganzen Packungen verteilt wird, und pro Gebindegröße
   Preis in Euro und gern die Artikelnummer.
3. „Bestellrunden → Neue Runde starten“: Titel, Aufwandsentschädigung, Zeitplan
   und Abholort eintragen, dann „Bestellrunde starten“. Abholtermine kommen
   später dazu, sobald der Liefertermin absehbar ist.
4. Unter „Mitglieder & Einladungen“ einladen — mehrere Adressen durch Komma
   getrennt. Kommt keine Mail an, „Link kopieren“ und per Messenger schicken.
5. Alle füllen „Mein Warenkorb“. Danach „Weiter zu: Anpassung“ — der
   Bestellvorschlag entsteht von selbst. Pro Lieferant „Anfrage öffnen“, die
   Antwort eintragen („Rückmeldung speichern“), Mengen bei Bedarf runden und
   oben rechts „Zur Abstimmung stellen“.
6. Stimmen alle Betroffenen zu, „Als finale Bestellung wählen“ (startet die
   Zahlung — mit der IBAN im Profil unter „Bankverbindung“ bekommen alle einen
   GiroCode), Zahlungen abhaken, pro Lieferant bestellen und abhaken,
   Abholtermine festlegen, Lieferungen abhaken, mit der Packliste ausgeben,
   Abholung abhaken.

## Mitmachen

Beiträge, Ideen und Feedback sind willkommen! Da das Projekt noch in der frühen Phase ist:

1. Lies die [docs](docs/)
2. Öffne ein Issue, um Features oder Konzeptfragen zu diskutieren
3. Fork das Repo und stelle einen Pull Request für Code-Beiträge
