# Bulk Buying Platform — Projektübersicht

## Einleitung und Werte

Diese Open-Source-Plattform ermöglicht es Gruppen, gemeinsam Lebensmittel und Grundversorgungsgüter in größeren Mengen möglichst direkt beim Erzeuger zu kaufen und dabei von besseren Preisen zu profitieren. Wo bestellt wird — beim Erzeuger, auf dem Hof, in der Mühle oder beim Händler —, heißt in der Plattform einheitlich **Lieferant**.

Die Plattform basiert auf vier Grundwerten:

- **Zusammenarbeit**: Moderatoren starten Runden, jeder kann mitbestellen und Vorschläge machen, Entscheidungen werden transparent mit allen Beteiligten getroffen.
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

- **Owner**: Ersteller der Gruppe. Kann die Owner-Rolle an ein anderes Mitglied übergeben (sobald es zustimmt) oder die Gruppe löschen. Hat alle Berechtigungen.
- **Moderator**: Kann Produkte und Lieferanten erstellen und bearbeiten, Runden einleiten, neue Teilnehmer einladen und deren Rollen zuweisen.
- **Participant**: Kann einkaufen und Warenkörbe füllen. Sieht alle anderen Warenkörbe. Hat keine Verwaltungsrechte.

Alle Mitglieder können sehen, was andere in ihren Warenkörben haben — volle Transparenz innerhalb der Gruppe.

### Profil

Bei der Registrierung gibt jede Person ihre **Handynummer**, ihren **Wohnort mit PLZ** und die **Anzahl Personen in ihrem Haushalt** an, für die sie mit einkauft. Im eigenen Profil lassen sich diese Angaben ändern, ein **Profilfoto** hochladen und optional ein **Spitzname** angeben — wie man genannt werden möchte. Die Mitglieder einer Gruppe sehen gegenseitig Foto und Kontaktdaten — praktisch für Absprachen und die Abholung. Moderatoren und Owner können mehrere Personen auf einmal einladen, indem sie die E-Mail-Adressen durch Komma getrennt eingeben. Wer den Einladungslink hat, kann beitreten — auch mit einem Konto unter einer anderen E-Mail-Adresse; die Einladung wandert dann auf diese Adresse.

## Lieferanten und Produkte

### Lieferanten

Lieferanten — Erzeuger, Höfe, Mühlen oder Händler — können von allen Moderatoren angelegt werden, auch direkt beim Anlegen eines Produkts. Je Lieferant kann entschieden werden, ob er für andere Gruppen freigegeben wird oder nicht:

- **Privat**: Lieferant ist nur im eigenen Tenant sichtbar
- **Öffentlich**: Lieferant wird mit anderen Tenants geteilt und kann von allen Gruppen verwendet werden
- Versandkosten-Hinweise

### Produkte

Jedes Produkt hat:
- Name und Lieferant
- Maßeinheit (Kilogramm, Stück, Glas, etc.)
- **Verteilung**: in Portionen einer festen Größe (z. B. 0,5 kg aus dem Sack, 1 Glas aus dem Karton) oder nur ganze Packungen ([Szenarien](#verpackungs-szenarien))
- **Gebindegrößen** mit [(prognostiziertem) Preis](#preise-als-richtwerte), optional Mindestbestellmenge und Artikelnummer des Lieferanten
- Sichtbarkeit: privat oder öffentlich

Nur wenn der Lieferant bereits **öffentlich** ist, können dessen Produkte ebenfalls öffentlich erstellt werden. Bei privaten Lieferanten sind auch alle Produkte automatisch privat.

### Preise als Richtwerte

Der prognostizierte Preis sind die Preise der Gebindegrößen. Daneben listet das System die Preise aus tatsächlich abgeschlossenen Bestellungen als Richtwerte. So entsteht über die Zeit ein realistisches Bild der tatsächlichen Preise der Lieferanten, das allen teilnehmenden Gruppen bei der Planung hilft.

Sobald die finale Bestellung steht, kann der Lead die Preise, die die Lieferanten in der Runde bestätigt haben, mit einem Klick ins Sortiment übernehmen — nur für Produkte, die er ändern darf, und ohne Gebinde, die nicht lieferbar waren. Vorher nicht: Bis zur Wahl zeigt der Unterschied zum Listenpreis der Gruppe, was die Lieferanten geändert haben.

### Aktivitäten-Stream

Produkte und Lieferanten haben jeweils einen **Aktivitäten-Stream**, der dokumentiert:
- Wer hat wann was geändert oder aktualisiert
- Notizen (evntl. mit Dateianhängen) von Moderatoren oder Leads, z.B.:
  > „Lieferant XY war am XX.XX. nicht besonders gut auf uns zu sprechen. Nächstes Mal besonders freundlich und vorsichtig anfragen ✌️"

Diese Notizen und Dokumente sind besonders bei öffentlichen Lieferanten wertvoll, da andere Gruppen von den Erfahrungen profitieren können.

Auch die Gruppe selbst führt einen **Verlauf**: wer beigetreten, ausgetreten oder entfernt wurde, wer welche Rolle bekommen hat und wer die Gruppe übernommen hat. So lässt sich nachvollziehen, wer was geändert hat.

### Verpackungs-Szenarien

Das System muss verschiedene Verpackungslogiken unterstützen. Die Gebindegrößen eines Produkts lassen sich dabei **kombinieren**: 17 kg Mehl können 1 × 10 kg, 1 × 5 kg und 2 × 1 kg sein. Das System wählt die Kombination mit dem niedrigsten Preis pro Einheit, die zu den Wünschen passt — flexible Wünsche nehmen dafür lieber etwas mehr. Ist eine größere Kombination insgesamt günstiger — etwa ein 25-kg-Sack statt sieben 3-kg-Kartons —, nimmt das System diese; was übrig bleibt, wird anteilig mitbezahlt und kostet trotzdem weniger. Der Lead kann die Anzahl je Größe von Hand festlegen.

**Szenario 1 — Feste Paketgrößen (z.B. Dinkelmehl):**
Dinkelmehl wird ausschließlich in 25 kg oder 50 kg Säcken gekauft. Die kollektive Bestellmenge muss ein Vielfaches dieser Sackgrößen sein. Eines Sack kann individuell aufgeteilt werden (z.B. jemand nimmt 500 g, jemand anderes 5 kg,...).

**Szenario 2 — Mengenstaffel mit Preisvorteil (z.B. Reis):**
Reis wird in 10 kg, 25 kg oder 50 kg Säcken gekauft. Je größer der Sack, desto günstiger der Kilopreis für alle. Das System kombiniert die Größen so, dass es für die Gruppe am günstigsten wird; alle zahlen denselben Mischpreis pro kg.

**Szenario 3 — Palettenweise mit teilbaren Einheiten (z.B. Senf):**
Senf wird in Gläsern auf Paletten gekauft. Die Gesamtbestellung muss in 12er-Schritten (Gläser) erreicht werden. Paletten können aber in einzelne Gläser an die Teilnehmer aufgeteilt werden.

**Szenario 4a — Mehrere feste Packungsgrößen, nicht teilbar (z.B. Spaghetti):**
Spaghetti gibt es in 250 g Packungen oder in 2 kg Packungen. Jede Packung geht an genau eine Person — die Packungen selbst sind nicht teilbar. Teilnehmer können zwischen den verschiedenen Größen wählen; sonst bekommt jede Person die Packungen, die zu ihrem Wunsch passen (2,5 kg = 1 × 2 kg + 2 × 250 g), und zahlt genau diese.

**Szenario 4b — Großgebinde mit individueller Abwiegung (z.B. Spirelli):**
Spirelli kommen in 5 kg Kartons. Die kollektive Bestellung muss in 5 kg Schritten erfolgen, aber innerhalb eines Kartons können die Nudeln individuell abgewogen und aufgeteilt werden.

## Runden — Der Bestellzyklus

Eine Runde ist ein kompletter Bestellzyklus mit klar definierten Phasen.

### Runde öffnen

Jeder Moderator kann eine neue Runde starten. Pro Gruppe läuft immer nur eine Runde: Die nächste lässt sich schon als Entwurf vorbereiten und starten, sobald die laufende abgeschlossen oder abgebrochen ist. Dabei werden folgende Parameter und Phasen mit voraussichtlichen Deadlines definiert:

- **Einkaufsphase**: ca. 2 Wochen
- **Anpassungsphase**: Lead fragt Preise und Versandkosten bei den Lieferanten an, basierend auf den potentiellen Mengen, und trägt ihre Rückmeldungen ein. Der Bestellvorschlag entsteht dabei automatisch. ca. 5 Werktage
- **Finalisierungs- / Bestätigungsphase**: Die finale Bestellung muss intern von allen Teilnehmern abgesegnet werden. Teilnehmer können Gegenvorschläge erstellen. z.B. 1 Woche
- **Zahlungsphase**: ca. 1 Woche zum Bezahlen (an Lead außerhalb des Systems)
- **Bestellung bei den Lieferanten**: Nach Zahlungseingang aller Teilnehmer
- **Wartezeit auf Lieferung**: Anhand Bestellbestätigung
- **Abholtermine**: 1–3 Zeitfenster (z. B. Samstag 10–12 Uhr), zu denen die Abholung beim Lead möglich ist. Sie können auch später festgelegt werden, sobald der Liefertermin absehbar ist — spätestens bevor die Abholung beginnt.
- **Abholort**: Muss definiert werden
- **Maximale Teilnehmerzahl**: Optional, falls der Abholort beispielsweiße nur für max. 5 Personen ausgelegt ist.
- **Aufwandsentschädigung des Leads**: In Prozent der Bestellsumme (siehe unten)

Alle Termine können bei Bedarf verschoben werden (z.B. wenn der Lieferant langsamer ist oder die Lieferung sich verzögert, oder jemand noch keine Rückmeldung gegeben hat und der Lead noch warten möchte).

### Runden-Lead

Der Ersteller der Runde ist der initiale Lead. Der Lead:
- Holt die Preise und Versandkosten bei den Lieferanten ein
- Schlägt die finale Bestellung vor
- Kann den Lead-Status an jemand anderen übergeben (mit Zustimmung der anderen Person)
- Entscheidet, welche Bestellversion tatsächlich bei den Lieferanten aufgegeben wird
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

Mengen gibt es nur in ganzen Portionen des Produkts — ganze Gläser, ganze 350-g-Packungen, beim Sack etwa halbe Kilo. So bekommt niemand ein halbes Glas oder eine angebrochene Packung.

Diese Angaben helfen dem Lead und dem System, eine faire Aufteilung vorzuschlagen, welche die Verpackungsbeschränkungen einhält.

Eingekauft wird wie in einem Onlineshop: Das Sortiment der Runde lässt sich nach Kategorie und Lieferant durchstöbern und durchsuchen. Schon beim Eintippen der Menge zeigt das System den **voraussichtlichen Preis** — berechnet mit den Gebinden, die die Gruppe mit ihren aktuellen Wünschen bestellen würde, inklusive Aufwandsentschädigung und Vereinsbeitrag; der Versand kommt erst mit den Rückmeldungen der Lieferanten dazu. Bei flexiblen Wünschen erscheint eine Spanne. Wo im Gebinde noch Platz ist, zeigt das Sortiment das an („noch 3 Glas frei im Gebinde“).

## Bestellprozess im Detail

### 1. Einkaufsphase (ca. 2 Wochen)
Teilnehmer füllen ihre Warenkörbe mit exakten oder flexiblen Mengenangaben. Alle sehen die Warenkörbe der anderen — mit voraussichtlichen Preisen pro Person und Produkt. Eine Übersichtsseite zeigt die Anzahl der Teilnehmer, die kummulierten Mengen, den Zeitplan und die übrigen Rahmenbedingungen der Bestellrunde.

### 2. Anpassungsphase
Mit dem Ende des Einkaufs entsteht der Bestellvorschlag automatisch aus den Warenkörben. Der Lead fragt pro Lieferant mit einer vorformulierten Mail (inklusive Artikelnummern) Preise und Versandkosten an, fasst bei Bedarf nach und trägt die Rückmeldung pro Lieferant ein: bestätigte Preise pro Gebinde, Gebinde, die nicht lieferbar sind, und die Versandkosten. Diese Rückmeldung gilt für alle Vorschläge der Runde; der Vorschlag rechnet sich bei jeder Änderung neu. Der Lead kann die Mengen pro Person von Hand anpassen (z. B. auf- oder abrunden), die Anzahl der Gebinde festlegen und den Vorschlag dann in einem Schritt zur Abstimmung stellen.

### 3. Finalisierungs- / Bestätigungsphase
Der Lead schlägt eine finale Bestellversion vor, die:
- Alle (eventuell frisch aktualisierten) Verpackungsbeschränkungen einhält
- Die individuellen Wünsche und Flexibilitätsangaben fair berücksichtigt
- Die von den Lieferanten bestätigten Preise inkl. Versandkosten enthält — pro Lieferant, verteilt im Verhältnis zum Warenwert, den jemand von diesem Lieferanten bekommt
- Die Aufwandsentschädigung, den Vereinsbeitrag und ggf. Spenden ausweist

**Abstimmung:**
Jeder Teilnehmer gibt pro Bestellposition einen Daumen hoch oder Daumen runter:
- **Daumen hoch**: Akzeptiert den Teil des Vorschlags
- **Daumen runter**: Lehnt den Teil des Vorschlags ab – Begründung notwendig!

**Mehrere Versionen:**
Mehrere Bestellversionen können parallel existieren. Jeder Teilnehmer kann mehreren Versionen gleichzeitig zustimmen. Jeder Teilnehmer kann Bestellversionen vorschlagen: Ein Gegenvorschlag ist eine Kopie eines Vorschlags mit denselben Preisen und Korrekturen, die sich anpassen lässt. Wer mit allem einverstanden ist, stimmt mit einem Klick allen eigenen Positionen zu.

Jede neue Version und jeder Gegenvorschlag zeigt, was sich gegenüber dem kopierten Vorschlag geändert hat: neue und entfallene Produkte, andere Gebinde und Preise, wer wie viel mehr oder weniger bekommt, Versand pro Lieferant und was jede Person am Ende zahlt — die eigenen Änderungen hervorgehoben.

**Entscheidung des Leads:**
Der Lead sieht alle Versionen mit den jeweiligen Zustimmungen und wählt eine aus. Es können nur Versionen platziert werden, denen alle vorgesehenen Teilnehmer einstimmig zugestimmt haben. Der Lead kann nicht über die Entscheidung eines Teilnehmers hinweggehen, aber einzelne Teilnehmer aus der Bestellung ausschließen.

**Konsens-Regel (verbindlich, so setzt die Plattform sie durch):**
1. Über eine Position entscheiden die **Betroffenen** — alle, die das Produkt bestellt haben, auch wenn der Vorschlag ihnen nichts davon gibt. So kann niemand still übergangen werden. Andere Teilnehmer dürfen ihre Meinung abgeben, können aber keine Position blockieren, die sie nicht bestellt haben.
2. Eine Position ist angenommen, wenn **alle Betroffenen Daumen hoch** gegeben haben. Ein Daumen runter braucht immer eine Begründung.
3. Ein Vorschlag ist **einstimmig**, wenn jede seiner Positionen angenommen ist und keine ausgeschlossene Person mehr darin vorkommt.
4. Nur ein einstimmiger Vorschlag kann als finale Bestellung gewählt werden — vom Lead (ersatzweise vom Gruppen-Owner) in der Bestätigungsphase. Mit der Wahl beginnt die Zahlungsphase; ohne gewählten einstimmigen Vorschlag gibt es keine.
5. Ein zur Abstimmung freigegebener Vorschlag ändert sich nicht mehr. Änderungen bedeuten eine neue Version, über die neu abgestimmt wird.

**Ausschluss einzelner Teilnehmer (letzter Ausweg):**
Führen mehrere Vorschläge nicht zum Ergebnis, weil einzelne Personen nicht zustimmen oder sich nicht melden, darf der Lead sie aus der Bestellung ausschließen — damit niemand eine Bestellung dauerhaft blockieren kann:
1. Das geht nur beim Vorbereiten eines neuen Vorschlags, also in der Anpassungs- und Bestätigungsphase, bevor bezahlt wird: Der Lead erstellt eine **neue Version** und schließt im Entwurf, **vor der Freigabe zur Abstimmung**, eine Person aus. Zur Wahl steht nur, wer einem freigegebenen Vorschlag nicht zugestimmt oder nicht abgestimmt hat. Der Lead selbst kann nicht ausgeschlossen werden.
2. Ein **Grund ist Pflicht** und für alle in der Runde sichtbar, ebenso wer wann ausgeschlossen hat.
3. Die Person **bleibt Mitglied der Gruppe** und kann an späteren Runden teilnehmen. Ihr Warenkorb bleibt sichtbar, zählt aber nicht mehr, und sie kann nicht mehr abstimmen.
4. Der Entwurf wird mit den bestätigten Preisen **ohne die Person neu berechnet**; Mengen, die von Hand gesetzt wurden, bleiben. Freigegebene Vorschläge, in denen sie noch etwas bekommt, werden **zurückgezogen** — auch eine bereits gewählte finale Bestellung; die zugehörigen offenen Zahlungen entfallen.
5. Weil sich Mengen und Versandanteile für alle ändern, **stimmen alle verbleibenden Betroffenen über die neue Version ab**.
6. Solange noch nicht bezahlt wird, kann der Lead den Ausschluss rückgängig machen; Entwürfe werden dann wieder mit der Person berechnet. Zurückgezogene Vorschläge bleiben zurückgezogen.

### 4. Zahlungsphase
Teilnehmer zahlen den Lead außerhalb der Plattform (Banküberweisung, bar, etc.). Hat der Lead seine Bankverbindung hinterlegt (im Profil oder direkt in der Zahlungsphase), sieht jeder Empfänger, IBAN, seinen Betrag und einen Verwendungszweck („Runde – Name“) zum Kopieren — dazu einen GiroCode, den die Banking-App scannt. Die Benachrichtigung zur Zahlung nennt die Bankverbindung ebenfalls. Der Lead markiert im System, wer bezahlt hat und wer nicht.

### 5. Bestellung bei den Lieferanten
Erst wenn alle Teilnehmer der finalen Bestellversion bezahlt haben, gibt der Lead die Bestellung tatsächlich bei den Lieferanten auf — mit vorformulierten Mails, die Gebinde, Artikelnummern, Preise und Versand nennen. Pro Lieferant hakt er ab, wann bestellt ist; alle sehen den Stand („Bestellt 2/3“).

### 6. Wartezeit auf Lieferung
Variabel je nach Lieferant. Der Lead hakt pro Lieferant ab, wann die Ware angekommen ist, und kann Status-Updates an die Gruppe schicken. Offene Bestellungen und Lieferungen halten die Runde nicht auf, erscheinen aber beim Phasenwechsel als Hinweis und auf dem Dashboard des Leads.

### 7. Abholung
Es gibt 1–3 Zeitfenster, in denen die Teilnehmer ihre Ware beim Lead bzw. am definierten Abholort abholen können. Der Lead legt sie fest, spätestens bevor die Abholung beginnt; den Abholort gibt er schon beim Start der Runde an. Der Lead oder jeder Teilnehmer für sich markiert im System, wer die Ware bereits abgeholt hat.

Für die Ausgabe gibt es eine **Packliste** zum Drucken: pro Person, in der Reihenfolge der Abholtermine, was sie bekommt (mit Kästchen zum Abhaken), und darunter pro Produkt, wie die Gebinde aufgeteilt werden.

## Historie und Notizen

Nach Abschluss einer Runde landen die Bestellungen in der Historie:
- Jeder Teilnehmer sieht seine eigenen vergangenen Bestellungen mit Mengen und Preisen
- Die Preise sind nach Bestellung eingefroren und werden nicht mehr durch spätere Preisänderungen aktualisiert
- Die tatsächlich gezahlten Preise fließen als Richtwerte zurück in die Produktdaten (siehe [Preise als Richtwerte](#preise-als-richtwerte))
- Bei weiteren Runden können Teilnehmer ihre vorherigen Mengen als Referenz nutzen
- Leads können Dokumente, Preislisten, Emails, Notizen, Bestellbestätigungen,... hochladen und der Bestellrunde hinterlegen und diese auch als Notiz zu Produkten oder Lieferanten ergänzen.

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
3. Wenn der Lead fertig ist, klickt er auf „Benachrichtigung senden" — oder schreibt die Nachricht direkt im Dialog des Phasenwechsels
4. Das System generiert automatisch einen Entwurf je nach Arbeitsschritt, der zusammenfasst, was sich seit der letzten Benachrichtigung geändert hat
5. Der Lead kann den Entwurf bearbeiten und ergänzen
6. Erst dann wird die E-Mail an alle relevanten Teilnehmer geschickt

Das verhindert E-Mail-Spam und stellt sicher, dass Benachrichtigungen aussagekräftig und mit menschlichem Kontext versehen sind.

## Email-Vorlagen

Auch für die Kommunikation mit Lieferanten werden für die verschiedenen Aktionen Emails generiert: die erste Preisanfrage, eine Erinnerung, wenn die Antwort ausbleibt, und die Bestellung — jeweils mit den Artikelnummern des Lieferanten, so dass nicht aus Versehen wichtige Eckpunkte vergessen werden.
