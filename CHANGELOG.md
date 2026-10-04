# Changelog

## 0.1.3 – 2026-10-04

- zwei sichtbare read-only Statusvariablen direkt unter der Instanz ergänzt: **Person erkannt** und **Nachtfreigabe**
- `Person erkannt` folgt dem internen Dahua-Personenstatus START/STOP
- `Nachtfreigabe` zeigt die tatsächlich verwendete astronomische Freigabe aus Sonnenaufgang/Sonnenuntergang
- beide Variablen werden bei bestehenden Instanzen beim Update automatisch angelegt und sofort synchronisiert
- Schaltlogik, 180-s-Nachlauf, LCN-Pfad und Dahua-Digest-Verbindung bleiben unverändert

## 0.1.2

- Dahua-Digest-Handshake korrigiert: Die Kamera beantwortet die erste Anfrage mit `401` und `Connection: close`. Der authentifizierte Request wird jetzt ausdrücklich über eine **frische TCP-Verbindung** gesendet.
- Client Socket wird für den Digest-Übergang kontrolliert geschlossen und neu geöffnet; dadurch bleibt er nach erfolgreichem HTTP-200-Eventstream dauerhaft aktiv.
- Watchdog kann einen fehlerhaften Client Socket kontrolliert neu aufbauen.
- Schutz gegen Passwort-Endlosschleifen: Ein authentifizierter 401 ohne `stale=true` stoppt weitere Loginversuche bis Speichern/Neuverbinden.
- Diagnose-Buttons korrigiert: statisches Modulformular verwendet `$id` statt `$_IPS['TARGET']`.
- `.gitattributes` ergänzt; Textdateien bleiben auf LF.

## 0.1.1 – 2026-10-04

- Dunkelheits-/Helligkeitsfreigabe vollständig aus der aktiven Schaltlogik entfernt
- Booleanvariable `Ist es Tag` wird nicht mehr ausgewertet
- neue Nachtfreigabe ausschließlich über Location Control `Sonnenaufgang` und `Sonnenuntergang`
- Logik: `Person erkannt UND (nach Sonnenuntergang ODER vor Sonnenaufgang)`
- eigener Grenzzeit-Timer für den Wechsel Sonnenaufgang/Sonnenuntergang
- Person bereits bei Sonnenuntergang aktiv: Automatik darf ab Sonnenuntergang einschalten
- Sonnenaufgang beendet die Freigabe; nur automatik-eigenes Licht wird ausgeschaltet
- manuell eingeschaltetes Licht bleibt auch bei Sonnenaufgang unangetastet
- Update von 0.1.0 behält alte Properties nur unsichtbar zur Kompatibilität; sie haben keine Wirkung mehr
- keine LCN-Hardwarebefehle in `ApplyChanges()`

## 0.1.0 – 2026-10-04

- erste eigenständige IP-Symcon-9-Modulversion
- Dahua JV Terrasse direkt per HTTP-Digest-Eventstream
- `codes=[All]`, Heartbeat 5 s
- Personenerkennung über `SmartMotionHuman` und Human-IVS-Daten
- ursprüngliche Freigabe über vorhandene Location-Control-Booleanvariable
- Terrasse schalten über vorhandene LCNLight-Statusvariable `48535`
- echte LCN-Rückmeldung über `44137`
- 180-s-Nachlauf
- erneute Erkennung setzt Nachlauf zurück
- Automatik-Eigentum verhindert Ausschalten manuell eingeschalteter Beleuchtung
- GT8/manuelle Zustandsänderung hat Vorrang
- keine zusätzlichen sichtbaren Statusvariablen
- keine LCN-Hardwarebefehle in `ApplyChanges()`
