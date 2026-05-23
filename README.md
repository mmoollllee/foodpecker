# 🪶 Foodpecker

**Hamstern Pro**
> Eine Open-Source-Plattform für kollektive Sammelbestellungen direkt beim Hersteller.

---

## Was ist Foodpecker?

Foodpecker hilft Freundesgruppen, Lebensmittel und Grundversorgungsgüter (Reis, Mais, Nudeln, Senf, …) gemeinsam in großen Mengen direkt beim Hersteller zu bestellen. Durch das Bündeln der Mengen werden Großgebinde- und Palettenpreise erreichbar. Gut für große Speisekammern.

Der Name ist abgeleitet vom englischen *Woodpecker*, dem **[Eichelspecht](https://www.youtube.com/watch?v=3CWGne2eGf4)**, der gemeinsam mit seinem Schwarm tausende Eicheln in einem Speicherbaum einlagert und über sie wacht, beschafft und verwaltet eine Foodpecker-Gruppe ihre Vorräte kollektiv.

## Grundwerte

- **Zusammenarbeit** — Jeder kann eine Bestellrunde starten, Vorschläge machen und mitgestalten. Entscheidungen sind transparent und für alle sichtbar.
- **Fairness** — Niemand wird zu Mengen gezwungen, die er nicht möchte. Verpackungsbeschränkungen werden gemeinsam gelöst.
- **Flexibilität** — Zeitpläne lassen sich verschieben, Rollen mit Zustimmung übergeben. Das System passt sich an reale Gruppen an.
- **Vertrauen** — Nur für Freunde gedacht! Konflikte werden persönlich geklärt; die Plattform ist nur ein Hilfsmittel und Excel Ersatz.

## Kernfunktionen

- **Multi-Tenancy** — Mehrere Gruppen nutzen eine Plattform, jede in ihrem eigenen Raum. Hersteller und Produkte können optional zwischen Gruppen geteilt werden.
- **Geteilte Hersteller & Produkte** — Öffentliche Hersteller und Produkte stehen allen Gruppen zur Verfügung. Echte Bestellpreise fließen als Richtwerte zurück in die Produktdaten.
- **Flexible Verpackungslogik** — Feste Paketgrößen, Mengenstaffeln mit Preisvorteil, teilbare Paletten, nicht teilbare Packungen und abwiegbare Großgebinde.
- **Kollaborative Bestellrunden** — Jeder kann eine Runde mit definiertem Zeitplan (Einkauf → Verhandlung → Bestätigung → Zahlung → Lieferung → Abholung) starten.
- **Flexible Warenkörbe** — Pro Artikel entweder eine exakte Menge oder eine flexible Spanne („zwischen 1 und 3 kg") für faire Aufteilung.
- **Konsens-basierte Bestellung** — Der Lead schlägt eine finale Bestellung vor, Teilnehmer stimmen mit Daumen hoch/runter ab. Der Lead soll nur einstimmig bestätigte Bestellungen tatsächlich platzieren.
- **Manuelle, editierbare Benachrichtigungen** — Das System erzeugt einen Änderungs-Entwurf, den der Lead vor dem Versand anpassen kann. Kein E-Mail-Spam.
- **Aktivitäten-Stream & Notizen** — Wer hat wann was geändert? Notizen zu Herstellern und abgeschlossenen Runden helfen, aus Erfahrungen zu lernen.
- **Historie** — Vergangene Bestellungen mit eingefrorenen Preisen als Referenz für die nächste Runde.
- **Faires Finanzmodell** — Aufwandsentschädigung (in %) für den Lead, ~1% Spende an die Foodpecker Organisation/Verein (tbd).

## Drei Rollen pro Gruppe

| Rolle | Berechtigungen |
|-------|----------------|
| **Owner** | Voller Zugriff, kann die Gruppe löschen |
| **Moderator** | Produkte & Hersteller verwalten, Runden starten, einladen, Rollen vergeben |
| **Participant** | Einkaufen, Warenkörbe füllen, alle Warenkörbe einsehen |

## Tech-Stack

- **[Laravel](https://laravel.com)** — PHP-Backend
- **[Filament](https://filamentphp.com)** — Admin-Panel & UI
- **Lizenz** — Open Source (siehe [LICENSE](LICENSE))

## Status

🚧 **In Konzeption / früher Entwicklung.** Das Feature-Konzept steht (siehe [`docs/concept.md`](docs/concept.md)). Mitwirkende sind herzlich willkommen. Achtung: Hier wird gevibecoded oder wie das heißt.

## Mitmachen

Beiträge, Ideen und Feedback sind willkommen! Da das Projekt noch in der frühen Phase ist:

1. Lies die [docs](docs/)
2. Öffne ein Issue, um Features oder Konzeptfragen zu diskutieren
3. Fork das Repo und stelle einen Pull Request für Code-Beiträge
