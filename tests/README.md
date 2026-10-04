# Tests

Ausführen mit PHP CLI:

```bash
php tests/test_parser.php
php tests/test_night_window.php
```

Geprüft werden:

- Dahua `SmartMotionHuman`
- Human-Objekt in IVS-Daten
- normales `VideoMotion` wird nicht fälschlich als Person gewertet
- Digest-Authentifizierung gegen Referenz-Testvektor
- Nachtfreigabe vor Sonnenaufgang / nach Sonnenuntergang
- exakte Grenzwerte Sonnenaufgang und Sonnenuntergang
- nächster Sonnen-Grenzzeitpunkt für den internen Timer
