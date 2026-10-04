# Außenlicht Automatik 2 – IP-Symcon 9 / Dahua / Terrasse / LCN

Version **0.1.2**.

## Ziel

Terrassenlicht automatisch über die Dahua-Kamera **JV Terrasse** schalten.

Die Freigabe ist jetzt bewusst **nicht mehr von Helligkeit / Dunkelheit abhängig**. Andere eingeschaltete Lampen können die Automatik damit nicht beeinflussen.

Die Einschaltbedingung lautet exakt:

**Person erkannt UND (nach Sonnenuntergang ODER vor Sonnenaufgang) = Licht EIN**

Zusätzlich gilt:

- ab Sonnenuntergang besteht Nachtfreigabe,
- ab Sonnenaufgang ist die Nachtfreigabe beendet,
- solange nachts eine Person erkannt wird, bleibt das Automatiklicht EIN,
- erst **180 Sekunden nach durchgehendem Ende** der Personenerkennung wird ausgeschaltet,
- erneute Personenerkennung beendet den laufenden Nachlauf sofort,
- manuell eingeschaltetes Licht wird **niemals** durch die Automatik ausgeschaltet,
- manuelle/GT8-Bedienung hat Vorrang,
- wenn Sonnenaufgang erreicht wird, wird nur ein von der Automatik selbst eingeschaltetes Licht wieder ausgeschaltet.

## Fest eingetragene Projektwerte

- Dahua JV Terrasse: `192.168.107.110`
- LCNLight Instanz EG Terrasse: `26459`
- LCNLight Status / Schaltvariable: `48535`
- native LCN-Ausgangsinstanz: `35859`
- native Statusrückmeldung Ausgang 1: `44137`
- native Intensität Ausgang 1: `39418` (für diese Automatik nicht erforderlich)
- Dahua TiOC Alarmkameras: `12388`
- `40957` ist nur der String-Status von JV Terrasse und wird **nicht** als Personenerkennung verwendet.

## Dahua

Das Modul verbindet sich direkt per HTTP/Digest mit der Kamera und öffnet:

`/cgi-bin/eventManager.cgi?action=attach&codes=[All]&heartbeat=5`

Für die Lichtautomatik werden nur Personenereignisse ausgewertet:

- `SmartMotionHuman`
- sowie Dahua-IVS-Ereignisse, deren Nutzdaten ein Objekt vom Typ `Human` enthalten.

Andere Ereignisse werden ignoriert. Optional können im Symcon-Debug alle Dahua-Ereignisse mitgeschrieben werden.

## Nachtfreigabe

Im Konfigurationsformular werden aus der vorhandenen IP-Symcon-Instanz **Location Control** genau diese beiden Integer-Zeitvariablen ausgewählt:

- `Sonnenaufgang`
- `Sonnenuntergang`

Die frühere Booleanvariable `Ist es Tag` wird ab Version 0.1.2 **nicht mehr ausgewertet**.

Auch eine Helligkeits-, Dämmerungs- oder Dunkelheitsvariable wird nicht benutzt.

Location Control kann einen erreichten Sonnenzeitpunkt bereits auf den entsprechenden Zeitpunkt des nächsten Tages weitersetzen. Die Auswertung berücksichtigt deshalb die **vollständigen Zeitstempel** und nicht nur Helligkeit oder einen einfachen Uhrzeitvergleich:

- ist das nächste bevorstehende Sonnenereignis **Sonnenaufgang** → Nachtfreigabe aktiv,
- ist das nächste bevorstehende Sonnenereignis **Sonnenuntergang** → Tag / keine Einschaltfreigabe.

Damit bleibt die Logik auch dann korrekt, wenn sich Sonnenaufgang oder Sonnenuntergang von einem Tag zum nächsten um einige Minuten verschieben. Das Modul besitzt zusätzlich einen internen Grenzzeit-Timer. Dadurch wird die Freigabe exakt beim Wechsel Sonnenaufgang/Sonnenuntergang neu bewertet.

### Verhalten an den Grenzen

**Sonnenuntergang:**

- Nachtfreigabe wird aktiv.
- Falls zu diesem Zeitpunkt bereits eine Personenerkennung läuft und das Licht AUS ist, darf die Automatik einschalten.

**Sonnenaufgang:**

- Nachtfreigabe wird beendet.
- Ein manuell eingeschaltetes Licht bleibt unangetastet.
- Ein Licht, das nachweislich von der Automatik eingeschaltet wurde, wird ausgeschaltet, auch wenn die Personenerkennung noch aktiv ist. Damit bleibt die gewünschte UND-Bedingung erhalten.

## LCN / manuelle Bedienung

Die Automatik steuert **nicht direkt den LCN-Ausgang**. Sie ruft `RequestAction()` auf der vorhandenen LCNLight-Statusvariable `48535` auf. Damit wird derselbe vorhandene LCN-/TS-Pfad benutzt wie die bereits eingerichtete Lichtbedienung.

Die echte Rückmeldung erfolgt separat über `44137`.

Sicherheitslogik:

1. Ist das Licht beim Personen-START bereits EIN, übernimmt die Automatik **kein Eigentum**. Es wird später nicht ausgeschaltet.
2. Schaltet die Automatik nachts selbst von AUS auf EIN, merkt sie sich diesen Zustand intern.
3. Nur in diesem Fall darf sie nach Nachlauf oder Sonnenaufgang wieder AUS schalten.
4. Eine echte LCN-Zustandsänderung außerhalb des erwarteten Automatikbefehls verwirft dieses Eigentum sofort.
5. Wird während laufender Personenerkennung manuell AUS geschaltet, bleibt die Automatik bis zum Ende dieser Erkennung gesperrt und schaltet nicht sofort wieder ein.

## Update von 0.1.0

Die Modul- und Bibliotheks-GUIDs bleiben unverändert.

Nach dem Update:

1. Instanz **Außenlicht Automatik 2** öffnen.
2. Unter **Nachtfreigabe – Sonnenaufgang / Sonnenuntergang** die Location-Control-Variable `Sonnenaufgang` auswählen.
3. Die Location-Control-Variable `Sonnenuntergang` auswählen.
4. Übernehmen.
5. Die alte Einstellung `Ist es Tag` ist nicht mehr sichtbar und wird nicht mehr ausgewertet.

`ApplyChanges()` sendet weiterhin **keinen LCN-Lichtbefehl**.

## Installation

1. ZIP entpacken.
2. Den gesamten Inhalt in das vorhandene GitHub-Repository kopieren bzw. die vorhandenen Dateien ersetzen.
3. Commit und Push.
4. In IP-Symcon 9 das Modul aktualisieren.
5. In der Instanz Dahua-Benutzername und Passwort prüfen.
6. `Location Control → Sonnenaufgang` und `Location Control → Sonnenuntergang` auswählen.
7. Prüfen, dass als Schaltvariable `48535` und als echte Rückmeldung `44137` ausgewählt sind.
8. Übernehmen.

## Empfohlener Funktionstest

1. Tagsüber vor die Kamera laufen → Person darf erkannt werden, Licht bleibt AUS.
2. Sonnenuntergang testweise mit passenden Location-Control-Zeitwerten simulieren bzw. nachts testen → Person START schaltet Licht EIN.
3. Person STOP → 180-s-Nachlauf startet.
4. Innerhalb der 180 s erneut Person START → Nachlauf wird verworfen.
5. Licht manuell/GT8 einschalten und danach Person erkennen → Automatik darf dieses Licht später nicht ausschalten.
6. Während laufender Personenerkennung ein von der Automatik eingeschaltetes Licht manuell AUS schalten → bis zum Ende dieser Erkennung kein automatisches Wiedereinschalten.
7. Sonnenaufgang erreichen → ein von der Automatik eingeschaltetes Licht wird ausgeschaltet; manuell eingeschaltetes Licht bleibt EIN.

## Kein Hardwarebefehl bei Update/ApplyChanges

`ApplyChanges()` richtet ausschließlich Nachrichten, Timer und die Dahua-Verbindung ein. Es wird dabei **kein LCN-Lichtbefehl** gesendet. Falls nach einem Neustart bereits Tageszeit ist und noch ein gespeichertes Automatik-Eigentum besteht, erfolgt eine notwendige Korrektur erst über einen nachgelagerten Timer.


## Dahua-Digest-Verbindungsablauf (0.1.2)

Dahua beendet die erste, noch nicht authentifizierte HTTP-Verbindung nach der Digest-Challenge mit `401 Unauthorized` und `Connection: close`. Deshalb verwendet das Modul bewusst zwei TCP-Verbindungen:

1. neue Verbindung → unauthentifizierter GET → Digest-Challenge,
2. Client Socket schließen/neu öffnen,
3. authentifizierter GET mit der Challenge → `HTTP 200`,
4. dieselbe zweite Verbindung bleibt anschließend als `codes=[All]`-Eventstream offen.

Ein authentifizierter 401 mit falschen Zugangsdaten wird **nicht** endlos wiederholt. Das verhindert unnötige Fehlversuche bzw. eine mögliche Sperre des Dahua-Benutzers.
