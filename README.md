# Dahua Personenerkennung / Außenlicht-Automatik für IP-Symcon 9

Version **0.3.2**.

## Zweck

Für **jede Dahua-Kamera wird eine eigene Instanz** angelegt. Eine Instanz kann wahlweise:

1. nur eine zuverlässige Boolean-Variable **Person erkannt** bereitstellen oder
2. zusätzlich ein Licht mit astronomischer Nachtfreigabe steuern.

Der Dahua-Parser ist nicht auf ein einzelnes Kameramodell festgelegt. Er verarbeitet insbesondere die im Projekt vorhandenen Familien:

- Dahua TiOC, z. B. `DH-IPC-PDW3849-A180-AS-PV`
- Dahua WizMind `IPC-HFW5442E-ZE`

Die Kamera selbst muss die entsprechende SMD-/IVS-Personenerkennung aktiviert haben.

## Dahua-Verbindung

Pro Instanz werden eingetragen:

- Kamera-IP / Host
- HTTP-Port, normalerweise `80`
- Benutzername
- Passwort

Jede Modulinstanz erzeugt bei Bedarf einen eigenen Client Socket. Dadurch können mehrere Kameras mit unterschiedlichen IP-Adressen parallel betrieben werden, ohne sich eine Socket-Konfiguration zu teilen.

Der Eventstream wird direkt von der Kamera geöffnet:

`/cgi-bin/eventManager.cgi?action=attach&codes=[All]&heartbeat=5`

Der Digest-Handshake berücksichtigt das Dahua-Verhalten, dass nach der ersten `401`-Challenge die TCP-Verbindung geschlossen werden kann. Der authentifizierte Request wird deshalb über eine frische Verbindung gesendet.

Unterstützte Digest-Algorithmen:

- MD5
- MD5-sess
- SHA-256
- SHA-256-sess

Nach wiederholt abgewiesenem Login stoppt das Modul weitere Versuche, damit ein falsches Passwort nicht unnötig oft versucht wird. Nach Korrektur genügt **Dahua-Verbindung neu anstoßen** oder Speichern/Übernehmen.

## Universelle Personenerkennung

Version 0.3.2 verarbeitet sowohl direkte SMD-Ereignisse als auch IVS-Ereignisse.

Direkt erkannt werden u. a.:

- `SmartMotionHuman`
- `HumanDetection`
- `HumanBodyDetection`

Bei IVS-Ereignissen wird die Objektklassifizierung ausgewertet, z. B.:

- `CrossLineDetection` + `ObjectType: Human`
- `CrossRegionDetection` + `ObjectType: Human`
- Human-/Person-/Pedestrian-Klassifizierung innerhalb verschachtelter `Object`- oder `Objects[]`-Daten

### Mehrzeilige IVS-Daten

Dahua kann `data={...}` über mehrere Zeilen und mehrere TCP-Chunks verteilen. Der Parser sammelt das Ereignis bis zum vollständigen JSON-Ende und wertet es erst dann aus. Multipart-Boundaries, Heartbeats und beliebige TCP-Chunk-Grenzen werden dabei toleriert.

### START/STOP sicher nachführen

Ein IVS-`START` kann die Human-Klassifizierung enthalten, während der zugehörige `STOP` je nach Firmware nur noch Code, Index oder IDs liefert. Deshalb führt das Modul aktive Human-Ereignisse intern mit:

- Eventcode
- Index
- EventID, soweit vorhanden
- RuleID, soweit vorhanden
- GroupID, soweit vorhanden
- ObjectID, soweit vorhanden

Ein passender `STOP` beendet dadurch ein zuvor erkanntes Human-Ereignis auch dann, wenn im STOP selbst kein `ObjectType=Human` mehr enthalten ist.

Explizit als `Vehicle`, `Animal` usw. klassifizierte STOP-Ereignisse löschen keinen parallelen Human-Zustand.

### Mehrere parallele Regeln

Mehrere Human-IVS-Ereignisse können gleichzeitig aktiv sein. **Person erkannt** bleibt TRUE, solange mindestens ein Human-Ereignis aktiv ist.

### PULSE

Human-`PULSE` wird fünf Sekunden gehalten. Dadurch ist die Boolean-Variable sichtbar und eine optionale Lichtautomatik kann sicher reagieren, auch wenn kein separates STOP folgt.

### Streamverlust

Bei verlorenem Eventstream werden aktive Human-Zustände verworfen. So kann `Person erkannt` durch einen verpassten STOP nicht dauerhaft TRUE hängen. Hat die Automatik das Licht selbst eingeschaltet, beginnt in diesem Fall der normale Nachlauf.

## Sichtbare Statusvariablen

### Person erkannt

Immer sichtbar und nur lesbar.

- FALSE = aktuell kein aktives Human-Ereignis
- TRUE = mindestens ein aktives Human-Ereignis bzw. Human-PULSE aktiv

### Nachtfreigabe

Nur für die Lichtautomatik relevant und bei reiner Personenerkennung ausgeblendet.

- TRUE = nach Sonnenuntergang bzw. vor Sonnenaufgang
- FALSE = Tag

Es wird **keine Helligkeits-/Dunkelheitsvariable** verwendet.

## Betriebsart: nur Personenerkennung

Option **Personenerkennung zusätzlich für Lichtautomatik verwenden = AUS**.

Dann werden keine Sonnen- oder Lichtvariablen benötigt und das Modul sendet garantiert keinen Lichtbefehl.

## Betriebsart: Personenerkennung + Lichtautomatik

Zusätzlich auswählen:

### Location Control

- **Sonnenaufgang**
- **Sonnenuntergang**

Schaltfreigabe:

**Person erkannt UND Nachtfreigabe = Licht darf EIN**

### Licht schalten – Status

Hier wird eine **Boolean-Variable mit Aktion** ausgewählt.

Geeignet sind z. B.:

- `LCNLight → Status`
- direkt die schaltbare Boolean-Statusvariable eines nativen LCN-Ausgangs

Das Modul schaltet ausschließlich über:

`RequestAction(<Statusvariable>, true/false)`

Es schreibt nicht direkt in die Variable.

### Echte Rückmeldung – Intensität

Hier wird die echte native Ausgangsrückmeldung ausgewählt:

- Integer oder Float
- `0 = AUS`
- jeder Wert `> 0 = EIN`

Typischerweise ist dies die native LCN-Variable **Intensity / Intensität** des tatsächlichen Ausgangs. Diese Variable wird ausschließlich gelesen und niemals beschrieben.

Damit sind Befehl und Istzustand bewusst getrennt:

- **Boolean Status mit Aktion → Schaltbefehl**
- **Integer/Float Intensität → realer Istzustand**

## Manueller Vorrang

- Licht war vor der Personenerkennung bereits EIN → kein Automatik-Eigentum; später kein automatisches AUS.
- Nur Licht, das die Automatik selbst eingeschaltet hat, darf sie wieder ausschalten.
- Eine manuelle/externe AUS-Änderung der echten Intensitätsrückmeldung verwirft das Automatik-Eigentum. Eine verspätete EIN-Rückmeldung nach einem eigenen Automatik-EIN bestätigt dagegen das Automatiklicht und darf das Eigentum nicht löschen.
- Wird während laufender Personenerkennung manuell AUS geschaltet, bleibt die Automatik bis zum vollständigen Ende aller aktuellen Human-Ereignisse gesperrt.
- Eine neue Person während des Nachlaufs stoppt den Ausschalt-Timer wieder.
- Sonnenaufgang entzieht die Freigabe; nur automatik-eigenes Licht wird ausgeschaltet.
- `ApplyChanges()` sendet keinen Lichtbefehl.

## Nachlauf

Standard: **180 Sekunden** nach vollständigem Ende der Personenerkennung.

Sind mehrere Human-Ereignisse gleichzeitig aktiv, beginnt der Nachlauf erst, wenn alle beendet sind.

### Ausschalten / Nachlauf – seit 0.3.2 gegen Doppel-Toggle abgesichert

Beim Ablauf des Nachlaufs gilt die **echte LCN-Intensity** als alleinige Abschlussbestätigung:

- `Intensity > 0` → AUS wird per Boolean-Aktionsvariable angefordert
- `Intensity = 0` → AUS ist real bestätigt; erst dann endet das Automatik-Eigentum
- Rückmeldung vorübergehend nicht verfügbar → erneute **Prüfung** nach 15 s, auch nachts
- `RequestAction(false)` allein gilt **nicht** mehr als Beweis, dass die Hardware tatsächlich AUS ist
- wurde ein Schaltbefehl von `RequestAction()` angenommen, wird derselbe Zielbefehl bei fehlender Intensity **nicht erneut gesendet**; das verhindert bei LCN-KURZ-/Memory-/Toggle-Aktionen ein versehentliches Zurückschalten
- nach 60 s ohne echte EIN-Bestätigung wird `AutoOwned` sicher verworfen; ein später manuell eingeschaltetes Licht wird dadurch nicht fälschlich zum Automatiklicht
- ein überfälliges AUS bleibt als Pending-Ziel bestehen und wird nur weiter überwacht; kein blindes erneutes Toggle
- kommt während eines noch unbestätigten AUS eine neue Person, wird das alte AUS-Pending verworfen; nach dem nächsten Personenende darf wieder genau ein neuer AUS-Befehl erfolgen

Damit bleibt die Lichtsteuerung auch bei verzögerter oder fehlender LCN-Rückmeldung deterministisch und toggle-sicher.

## Selbstüberwachung

- Dahua-Heartbeat wird überwacht.
- Bei Streamverlust wird kontrolliert mit frischem Socket verbunden.
- Nachtfreigabe wird zusätzlich zyklisch geprüft.
- Personenstatus wird bei Streamverlust sicher zurückgesetzt.
- Automatik-Eigentum kann nach Neustart/Update nicht ohne Ausschaltzeitpunkt hängen bleiben.
- geänderte Sonnen-/Rückmeldevariablen werden sauber neu auf Symcon-Messages registriert.

## Diagnose

**Alle Dahua-Ereignisse im Debug ausgeben** zeigt für jedes geparste Event u. a.:

- Code
- Action
- Index
- Human ja/nein
- erkannte Klassifizierung
- EventID
- RuleID
- GroupID
- ObjectID

**Aktuellen Zustand ins Debug schreiben** zeigt zusätzlich:

- Client-Socket-ID und Status
- Streaming-/Authentifizierungszustand
- Alter des letzten Kamerapakets
- aktive Human-Ereignisse
- letzter Human-Event
- Person erkannt
- Nachtfreigabe
- Licht-Istzustand
- Automatik-Eigentum
- Nachlaufzeitpunkt

## Update

GUIDs, Modul-ID und Prefix bleiben unverändert. Vorhandene Instanzen werden weiterverwendet.

Nach dem GitHub-Update:

1. Modul in IP-Symcon aktualisieren.
2. vorhandene Instanz öffnen.
3. **Übernehmen**.
4. Client Socket prüfen: nach dem Digest-Aufbau muss er dauerhaft aktiv bleiben.
5. Bei einer neuen Kamerafamilie zunächst **Alle Dahua-Ereignisse im Debug ausgeben** aktivieren und einen realen Personentest durchführen.

## Tests

Enthaltene Regressionstests prüfen u. a.:

- einzeiliges `SmartMotionHuman`
- mehrzeilige IVS-JSON-Daten über mehrere Chunks
- Parser über **jede einzelne Byte-/Split-Grenze** sowie deterministische Zufalls-Chunkgrößen
- Human in `Objects[]`
- Vehicle darf nicht als Human gelten
- START/STOP mit und ohne Event-/Rule-IDs
- parallele Human-Ereignisse
- MD5- und SHA-256-Digest
- Nachtfenster
- sichtbare Statusvariablen
- reine Personenerkennung
- Boolean-Schaltvariable mit Aktion
- Integer/Float-Intensity als echte Rückmeldung


### Laufzeitsimulation 0.3.2

Ein eigener Mock-IP-Symcon-Laufzeittest simuliert zusätzlich:

- Person START/STOP und 180-s-Nachlauf
- verzögerte Intensity-Rückmeldung
- toggle-/Memory-basierte Schaltaktion, bei der ein doppelter AUS-Befehl das Licht wieder einschalten würde
- manuell bereits eingeschaltetes Licht
- manuelles AUS während laufender Personenerkennung
- Sonnenaufgang mit aktiver Person
- Wechsel der Licht-I/O-Konfiguration
- reinen Personenerkennungsmodus ohne Lichtbefehl
- Streamverlust
- überfälliges unbestätigtes EIN
- neue Person während eines noch unbestätigten AUS
