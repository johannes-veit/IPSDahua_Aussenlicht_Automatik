# Dahua Personenerkennung / Außenlicht-Automatik für IP-Symcon 9

Version **0.2.0**.

## Konzept

Das Modul ist ab 0.2.0 nicht mehr auf die Terrasse festgelegt. Für **jede Dahua-Kamera wird eine eigene Instanz** angelegt. Jede Instanz kann in zwei Betriebsarten verwendet werden:

1. **Nur Personenerkennung**
   - direkter Dahua-Eventstream per HTTP/Digest
   - `codes=[All]`
   - sichtbare Boolean-Variable **Person erkannt**
   - keine Sonnenzeiten erforderlich
   - keine Lichtvariablen erforderlich
   - garantiert keine Lichtbefehle

2. **Personenerkennung + Lichtautomatik**
   - zusätzlich Location-Control-Variablen **Sonnenaufgang** und **Sonnenuntergang**
   - zusätzliche Licht-Schaltvariable mit Aktion, z. B. `LCNLight → Status`
   - separate echte Boolean-Rückmeldung des Lichtzustands
   - sichtbare Boolean-Variable **Nachtfreigabe**
   - Schaltlogik: **Person erkannt UND Nachtfreigabe = Licht EIN**

Die Betriebsart wird über **„Personenerkennung zusätzlich für Lichtautomatik verwenden“** gewählt.

## Pro Kamera eine Instanz

Beispiele:

- `Dahua Personenerkennung – JV Terrasse`
- `Dahua Personenerkennung – JV Hof Garage`
- `Dahua Personenerkennung – Lagerplatz rechts`

Die Instanz selbst kann im Objektbaum entsprechend der Kamera benannt werden. IP-Adresse, Port, Benutzername und Passwort werden je Instanz separat eingetragen.

## Dahua-Personenerkennung

Das Modul verbindet sich mit:

`/cgi-bin/eventManager.cgi?action=attach&codes=[All]&heartbeat=5`

Als Person gelten:

- `SmartMotionHuman`
- Dahua-IVS-Ereignisse, deren Nutzdaten ein Objekt vom Typ `Human` enthalten

Normale Bewegung `VideoMotion` wird **nicht** als Person interpretiert.

Der Digest-Handshake berücksichtigt das Dahua-Verhalten, nach der ersten `401`-Challenge die TCP-Verbindung zu schließen. Der authentifizierte Eventstream wird deshalb über eine frische TCP-Verbindung aufgebaut.

## Sichtbare Statusvariablen

### Person erkannt

Immer sichtbar. `TRUE`, solange der Dahua-Eventstream eine aktive Personenerkennung meldet.

### Nachtfreigabe

Nur bei aktivierter Lichtautomatik sichtbar. `TRUE`, wenn der aktuelle Zeitpunkt astronomisch nach Sonnenuntergang bzw. vor Sonnenaufgang liegt.

Es wird **keine Helligkeits-/Dunkelheitsvariable** ausgewertet. Andere Lampen können die Freigabe deshalb nicht verfälschen.

## Optionale Lichtautomatik

Ist **„Personenerkennung zusätzlich für Lichtautomatik verwenden“** ausgeschaltet, wird ausschließlich die Personenvariable gepflegt. Sonnenzeiten, Licht-Schaltvariable, Rückmeldung und Nachlauf werden vollständig ignoriert.

Ist die Option eingeschaltet, werden benötigt:

- `Location Control → Sonnenaufgang`
- `Location Control → Sonnenuntergang`
- Boolean-Schaltvariable mit Standardaktion, z. B. `LCNLight → Status`
- separate echte Boolean-Rückmeldung des Lichtzustands
- Nachlaufzeit, standardmäßig 180 Sekunden

### Schaltregel

**Person erkannt UND Nachtfreigabe → Licht EIN**

Nach Ende der Personenerkennung startet der Nachlauf. Eine neue Erkennung während des Nachlaufs verwirft den laufenden Ausschalt-Timer.

### Vorrang manueller Bedienung

- Ist das Licht vor der Erkennung bereits EIN, übernimmt die Automatik kein Eigentum und schaltet es später nicht AUS.
- Nur ein nachweislich von der Automatik eingeschaltetes Licht darf automatisch ausgeschaltet werden.
- Eine externe/manuelle Änderung der echten Rückmeldung verwirft das Automatik-Eigentum.
- Wird während einer laufenden Personenerkennung manuell ausgeschaltet, schaltet die Automatik bis zum Ende dieser Erkennung nicht sofort wieder ein.
- Sonnenaufgang beendet die Nachtfreigabe; nur automatik-eigenes Licht wird ausgeschaltet.

## Bestehende Terrasse nach Update von 0.1.3

Die bestehende Terrasseninstanz bleibt kompatibel. Die neue Eigenschaft **Lichtautomatik verwenden** ist aus Kompatibilitätsgründen standardmäßig aktiviert.

Für die bisherige Terrasse bleiben die vorhandenen Zuordnungen bestehen. Nach dem Update einmal die Instanz öffnen und **Übernehmen**.

Bei **neu angelegten Kamera-Instanzen**:

- für reine Personenerkennung die Lichtautomatik deaktivieren,
- Kamera-IP/Benutzer/Passwort eintragen,
- Übernehmen.

Für eine neue Kamera mit Lichtsteuerung zusätzlich die Sonnen- und Lichtvariablen auswählen.

## Sicherheit bei ApplyChanges / Update

`ApplyChanges()` sendet **keinen Lichtbefehl**. Wird die Lichtautomatik deaktiviert, werden Lichttimer und internes Automatik-Eigentum verworfen, ohne einen AUS- oder EIN-Befehl zu senden.

## Projektbeispiel Terrasse

Die bisherige Terrasseninstanz kann weiterhin verwendet werden mit:

- Dahua JV Terrasse `192.168.107.110`
- vorhandener LCNLight-Statusvariable für EG Terrasse als Schaltvariable
- echter nativer LCN-Rückmeldung für Ausgang 1
- 180 s Nachlauf

Die festen IDs sind ab 0.2.0 jedoch **keine Modulvorgabe mehr**. Neue Instanzen starten ohne fest hinterlegte Licht-IDs oder Kamera-IP.
