# Tests

Aus dem Repository-Root ausführen:

```bash
php tests/test_parser.php
php tests/test_human_tracker.php
php tests/test_night_window.php
php tests/test_status_variables.php
php tests/test_flexible_mode.php
php tests/test_light_io.php
```

Geprüft werden insbesondere:

- Dahua `SmartMotionHuman`
- mehrzeilige `CrossLineDetection`-/`CrossRegionDetection`-IVS-Daten
- TCP-Chunk-Trennung mitten im JSON
- Human in verschachtelten `Object`-/`Objects[]`-Strukturen
- Nicht-Human-Klassifizierung
- START/STOP mit und ohne Dahua-IDs
- mehrere parallele Human-Ereignisse
- MD5 und SHA-256 Digest Auth
- astronomische Nachtfensterlogik
- sichtbare read-only Variablen `Person erkannt` / `Nachtfreigabe`
- Betriebsart nur Personenerkennung
- Boolean-Lichtschaltvariable mit Aktion
- Integer/Float-Intensity als echte Rückmeldung

- `test_runtime_simulation.php`: Mock-IP-Symcon-Laufzeitsimulation für Person/Nacht/Nachlauf/Manual Override/Toggle-Sicherheit/I-O-Wechsel.
- `test_parser_chunk_fuzz.php`: Parserprüfung über Byte-, alle Einzel-Split- und deterministische Zufalls-TCP-Chunkgrenzen.
