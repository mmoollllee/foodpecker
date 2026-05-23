# Bulk Buying Platform — Projektübersicht

## Einleitung und Werte

Diese Open-Source-Plattform ermöglicht es Gruppen, gemeinsam Lebensmittel und Grundversorgungsgüter in größeren Mengen direkt beim Hersteller zu kaufen und dabei von besseren Preisen zu profitieren.

Die Plattform basiert auf vier Grundwerten:

- **Zusammenarbeit**: Jeder kann eine Runde starten, jeder kann Vorschläge machen, Entscheidungen werden transparent mit allen Beteiligten getroffen.
- **Fairness**: Niemand wird zu Mengen gezwungen, denen er nicht zustimmt. Verpackungsbeschränkungen werden gemeinsam und gerecht gelöst.
- **Flexibilität**: Zeitpläne können verschoben werden, Rollen können (mit Zustimmung) übergeben werden, das System passt sich an, wie reale Gruppen tatsächlich funktionieren.
- **Vertrauen**: Wer bei einer Gruppe mitmacht, ist ein Freund. Konflikte und Probleme werden außerhalb der Plattform persönlich geklärt und abgewickelt. Die Plattform unterstützt diesen Prozess durch Transparenz und Historie, aber sie ersetzt nicht das persönliche Gespräch.

## Architektur

Das System ist Multi-Tenant aufgebaut: Verschiedene Gruppen nutzen eine gemeinsame Plattform, haben aber eigene isolierte Räume. Produkte und Preise **können** tenant-übergreifend geteilt werden, müssen es aber nicht.

Technologie-Stack:
- **Backend**: Laravel
- **Admin-Panel / UI**: FilamentPHP
- **Lizenz**: Open Source

## Rollen und Berechtigungen

Es gibt drei Rollen pro Tenant:

- **Owner**: Ersteller der Gruppe. Kann die Gruppe löschen. Hat alle Berechtigungen.
- **Moderator**: Kann Produkte und Hersteller erstellen und bearbeiten, Runden einleiten, neue Teilnehmer einladen und deren Rollen zuweisen.
- **Participant**: Kann einkaufen und Warenkörbe füllen. Sieht alle anderen Warenkörbe. Hat keine Verwaltungsrechte.

Alle Mitglieder können sehen, was andere in ihren Warenkörben haben — volle Transparenz innerhalb der Gruppe.

## Hersteller und Produkte

### Hersteller

Hersteller können von allen Moderatoren angelegt werden. Je Hersteller kann entschieden werden, ob dieser für andere Gruppen freigegeben wird oder nicht:

- **Privat**: Hersteller ist nur im eigenen Tenant sichtbar
- **Öffentlich**: Hersteller wird mit anderen Tenants geteilt und kann von allen Gruppen verwendet werden
- Versandkosten-Hinweise

### Produkte

Jedes Produkt hat:
- Name und Hersteller
- Maßeinheit (Kilogramm, Stück, Glas, etc.)
- [Verpackungsbeschränkungen](#verpackungs-szenarien)
- [(prognostizierten) Preis](#preise-als-richtwerte)
- Sichtbarkeit: privat oder öffentlich

Nur wenn der Hersteller bereits **öffentlich** ist, können dessen Produkte ebenfalls öffentlich erstellt werden. Bei privaten Herstellern sind auch alle Produkte automatisch privat.

### Preise als Richtwerte

Neben dem eingepflegten, prognostizierten Preis listet das System auch die Preise aus tatsächlich abgeschlossenen Bestellungen als Richtwerte. So entsteht über die Zeit ein realistisches Bild der tatsächlichen Hersteller-Preise, das allen teilnehmenden Gruppen bei der Planung hilft.

### Aktivitäten-Stream

Produkte und Hersteller haben jeweils einen **Aktivitäten-Stream**, der dokumentiert:
- Wer hat wann was geändert oder aktualisiert
- Notizen (evntl. mit Dateianhängen) von Moderatoren oder Leads, z.B.:
  > „Hersteller XY war am XX.XX. nicht besonders gut auf uns zu sprechen. Nächstes Mal besonders freundlich und vorsichtig anfragen ✌️"

Diese Notizen und Dokumente sind besonders bei öffentlichen Herstellern wertvoll, da andere Gruppen von den Erfahrungen profitieren können.

### Verpackungs-Szenarien

Das System muss verschiedene Verpackungslogiken unterstützen:

**Szenario 1 — Feste Paketgrößen (z.B. Dinkelmehl):**
Dinkelmehl wird ausschließlich in 25 kg oder 50 kg Säcken gekauft. Die kollektive Bestellmenge muss ein Vielfaches dieser Sackgrößen sein. Eines Sack kann individuell aufgeteilt werden (z.B. jemand nimmt 500 g, jemand anderes 5 kg,...).

**Szenario 2 — Mengenstaffel mit Preisvorteil (z.B. Reis):**
Reis wird in 10 kg, 25 kg oder 50 kg Säcken gekauft. Je größer der Sack, desto günstiger der Kilopreis für alle. Das System zeigt die Optionen mit den jeweiligen Preisen an, damit die Gruppe entscheiden kann, welche Größe wirtschaftlich am sinnvollsten ist.

**Szenario 3 — Palettenweise mit teilbaren Einheiten (z.B. Senf):**
Senf wird in Gläsern auf Paletten gekauft. Die Gesamtbestellung muss in 12er-Schritten (Gläser) erreicht werden. Paletten können aber in einzelne Gläser an die Teilnehmer aufgeteilt werden.

**Szenario 4a — Mehrere feste Packungsgrößen, nicht teilbar (z.B. Spaghetti):**
Spaghetti gibt es in 250 g Packungen oder in 2 kg Packungen. Jede Packung geht an genau eine Person — die Packungen selbst sind nicht teilbar. Teilnehmer können aber zwischen den verschiedenen Größen wählen.

**Szenario 4b — Großgebinde mit individueller Abwiegung (z.B. Spirelli):**
Spirelli kommen in 5 kg Kartons. Die kollektive Bestellung muss in 5 kg Schritten erfolgen, aber innerhalb eines Kartons können die Nudeln individuell abgewogen und aufgeteilt werden.

## Runden — Der Bestellzyklus

Eine Runde ist ein kompletter Bestellzyklus mit klar definierten Phasen.

### Runde öffnen

Jeder Moderator kann eine neue Runde starten. Dabei werden folgende Parameter und Phasen mit voraussichtlichen Deadlines definiert:

- **Einkaufsphase**: ca. 2 Wochen
- **Verhandlungsphase**: Lead holt aktuelle Preise und Konditionen von Herstellern, basierend auf den potentiellen Mengen. ca. 5 Werktage
- **Finalisierungs- / Bestätigungsphase**: Die finale Bestellung muss intern von allen Teilnehmern abgesegnet werden. Teilnehmer können Gegenvorschläge erstellen. z.B. 1 Woche
- **Zahlungsphase**: ca. 1 Woche zum Bezahlen (an Lead außerhalb des Systems)
- **Bestellung beim Hersteller**: Nach Zahlungseingang aller Teilnehmer
- **Wartezeit auf Lieferung**: Anhand Bestellbestätigung
- **Abholtermine**: 1–3 feste Termine, zu denen die Abholung beim Lead möglich ist
- **Abholort**: Muss definiert werden
- **Maximale Teilnehmerzahl**: Optional, falls der Abholort beispielsweiße nur für max. 5 Personen ausgelegt ist.
- **Aufwandsentschädigung des Leads**: In Prozent der Bestellsumme (siehe unten)

Alle Termine können bei Bedarf verschoben werden (z.B. wenn der Hersteller langsamer ist oder die Lieferung sich verzögert, oder jemand noch keine Rückmeldung gegeben hat und der Lead noch warten möchte).

### Runden-Lead

Der Ersteller der Runde ist der initiale Lead. Der Lead:
- Koordiniert die Verhandlungen mit dem Hersteller
- Schlägt die finale Bestellung vor
- Kann den Lead-Status an jemand anderen übergeben (mit Zustimmung der anderen Person)
- Entscheidet, welche Bestellversion tatsächlich beim Hersteller aufgegeben wird
- Markiert eingegangene Zahlungen als erledigt oder ausstehend
- Markiert die Abholungen der einzelnen Teilnehmer
- Verwaltet die Abholtermine

Der Lead kann Teilnehmer aus einer Runde entfernen. Zum Beispiel wenn diese keinem finalen Bestell-Vorschlag zustimmen. Entfernte Teilnehmer bleiben Mitglied der Gruppe und können an folgenden Runden teilnehmen. Nur der Gruppen-Owner kann jemanden aus der Gruppe entfernen (und jeder kann austreten).

## Finanzielles Modell

Jede Bestellrunde enthält drei finanzielle Komponenten zusätzlich zum reinen Warenwert:

### Aufwandsentschädigung Lead

Der Lead bekommt für das Management der Bestellrunde eine **Aufwandsentschädigung in Prozent** der Bestellsumme. Der Prozentsatz wird beim Öffnen der Runde festgelegt.

**Wichtig**: Die Aufwandsentschädigung steht immer demjenigen Lead zu, der die Bestellrunde **zu Ende bringt** — nicht zwangsläufig dem ursprünglichen Ersteller. Wird der Lead-Status während der Runde übergeben (mit Zustimmung), wandert auch der Anspruch auf die Aufwandsentschädigung mit.

### Beitrag an den Plattform-Verein

**1 % der Bestellsumme** soll an den Plattformbetreiber gehen. Dies finanziert den Betrieb, die Weiterentwicklung und die Infrastruktur der Open-Source-Plattform. Diese Spende geht zunächst an den Gruppen-Lead, der Gruppen-Lead überweist sie kollektiv an den Plattformbetreiber.
Der 1% Betrag kann von jedem Teilnehmer freiwillig erhöht werden. Eine bequeme Möglichkeit ist die **„Bestellsumme aufrunden"-Funktion**, mit der Teilnehmer ihren persönlichen Anteil auf den nächsten vollen Euro oder die nächsten vollen 10 Euro (oder einen frei gewählten Betrag) aufrunden können.

## Warenkörbe und Flexibilität

Jeder Teilnehmer fügt Artikel mit einer von zwei Mengenangaben in seinen Warenkorb:

- **Exakte Menge**: „Ich will genau Menge X"
- **Flexible Spanne**: „Ich bin flexibel zwischen Menge X und Y"

Beispiele:
- „Ich möchte genau 2 Gläser Senf"
- „Ich bin flexibel zwischen 1 und 3 kg Mais"

Diese Angaben helfen dem Lead und dem System, eine faire Aufteilung vorzuschlagen, welche die Verpackungsbeschränkungen einhält.

## Bestellprozess im Detail

### 1. Einkaufsphase (ca. 2 Wochen)
Teilnehmer füllen ihre Warenkörbe mit exakten oder flexiblen Mengenangaben. Alle sehen die Warenkörbe der anderen. Eine Übersichtsseite zeigt die Anzahl der Teilnehmer, die kummulierten Mengen, den Zeitplan und die übrigen Rahmenbedingungen der Bestellrunde.

### 2. Verhandlungsphase
Der Lead verhandelt mit dem Hersteller basierend auf den potentiellen Gesamtmengen und holt finale Preise ein.

### 3. Finalisierungs- / Bestätigungsphase
Der Lead schlägt eine finale Bestellversion vor, die:
- Alle (eventuell frisch aktualisierten) Verpackungsbeschränkungen einhält
- Die individuellen Wünsche und Flexibilitätsangaben fair berücksichtigt
- Die endgültigen verhandelten Preise inkl. Versandkosten (gleichmäßig aufgeteilt) enthält
- Die Aufwandsentschädigung, den Vereinsbeitrag und ggf. Spenden ausweist

**Abstimmung:**
Jeder Teilnehmer gibt pro Bestellposition einen Daumen hoch oder Daumen runter:
- **Daumen hoch**: Akzeptiert den Teil des Vorschlags
- **Daumen runter**: Lehnt den Teil des Vorschlags ab – Begründung notwendig!

**Mehrere Versionen:**
Mehrere Bestellversionen können parallel existieren. Jeder Teilnehmer kann mehreren Versionen gleichzeitig zustimmen. Jeder Teilnehmer kann Bestellversionen vorschlagen.

**Entscheidung des Leads:**
Der Lead sieht alle Versionen mit den jeweiligen Zustimmungen und wählt eine aus. Es können nur Versionen platziert werden, denen alle vorgesehenen Teilnehmer einstimmig zugestimmt haben. Der Lead kann nicht über die Entscheidung eines Teilnehmers hinweggehen, aber einzelne Teilnehmer aus der Bestellung ausschließen.

### 4. Zahlungsphase
Teilnehmer zahlen den Lead außerhalb der Plattform (Banküberweisung, bar, etc.). Der Lead markiert im System, wer bezahlt hat und wer nicht.

### 5. Bestellung beim Hersteller
Erst wenn alle Teilnehmer der finalen Bestellversion bezahlt haben, gibt der Lead die Bestellung tatsächlich beim Hersteller auf.

### 6. Wartezeit auf Lieferung
Variabel je nach Hersteller. Der Lead kann Status-Updates an die Gruppe schicken.

### 7. Abholung
Es gibt 1–3 feste Termine, zu denen die Teilnehmer ihre Ware beim Lead bzw. am definierten Abholort abholen können. Der Lead definiert diese Termine und den Abholort. Der Lead oder jeder Teilnehmer für sich markiert im System, wer die Ware bereits abgeholt hat.

## Historie und Notizen

Nach Abschluss einer Runde landen die Bestellungen in der Historie:
- Jeder Teilnehmer sieht seine eigenen vergangenen Bestellungen mit Mengen und Preisen
- Die Preise sind nach Bestellung eingefroren und werden nicht mehr durch spätere Preisänderungen aktualisiert
- Die tatsächlich gezahlten Preise fließen als Richtwerte zurück in die Produktdaten (siehe [Preise als Richtwerte](#preise-als-richtwerte))
- Bei weiteren Runden können Teilnehmer ihre vorherigen Mengen als Referenz nutzen
- Leads können Dokumente, Preislisten, Emails, Notizen, Bestellbestätigungen,... hochladen und der Bestellrunde hinterlegen und diese auch als Notiz zu Produkten oder Herstellern ergänzen.

### Nachträgliche Notizen

Im Sinne des Werts „Vertrauen" gibt es **keinen formalen Rückzahlungs- oder Konfliktlösungs-Workflow** in der Plattform. Konflikte werden persönlich außerhalb der Plattform geklärt.

Die Plattform unterstützt diesen Prozess aber durch **Notizen an Bestellrunden**:
- Jeder Teilnehmer kann nachträglich Notizen zu einer abgeschlossenen Bestellrunde hinzufügen
- Diese Notizen sind in der Historie sichtbar
- Beispiel: „Lieferung war 3 Wochen verspätet, beim nächsten Mal früher bestellen" oder „Qualität der Spaghetti war diesmal nicht gut. Total hart." + Antwort von anderem Teilnehmer "Schonmal mit heißen Wasser kochen probiert?".

So lernt die Gruppe aus jeder Runde, und beim Start der nächsten Runde sieht man auf einen Blick, was beim letzten Mal schiefgelaufen ist und worauf man achten sollte.

## Benachrichtigungen

Alle Benachrichtigungen werden per E-Mail über die Plattform verschickt und sind **manuell ausgelöst** — nicht automatisch bei jeder Änderung.

**Workflow:**
1. Der Lead bearbeitet Produkte, Preise, Mengen oder den Zeitplan
2. Änderungen werden gespeichert, aber **nicht sofort** und einzeln an Teilnehmer als Benachrichtigung versendet
3. Wenn der Lead fertig ist, klickt er auf „Benachrichtigung senden"
4. Das System generiert automatisch einen Entwurf je nach Arbeitsschritt, der zusammenfasst, was sich seit der letzten Benachrichtigung geändert hat
5. Der Lead kann den Entwurf bearbeiten und ergänzen
6. Erst dann wird die E-Mail an alle relevanten Teilnehmer geschickt

Das verhindert E-Mail-Spam und stellt sicher, dass Benachrichtigungen aussagekräftig und mit menschlichem Kontext versehen sind.

## Email-Vorlagen

Auch für die Kommunikation mit Herstellern sollen für die verschiedenen Aktionen Emails generiert werden. Zum Beispiel für die erste Preisanfrage und für die Bestellung, so dass nicht aus versehen wichtige Eckpunkte vergessen werden.
