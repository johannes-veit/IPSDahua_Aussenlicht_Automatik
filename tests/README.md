# Tests

Aus dem Repository-Root bzw. diesem Ordner ausführen:

```bash
php tests/test_parser.php
php tests/test_night_window.php
php tests/test_status_variables.php
php tests/test_flexible_mode.php
```

Geprüft werden insbesondere:

- Dahua `SmartMotionHuman` START/STOP/PULSE und Human-IVS-Erkennung
- normales `VideoMotion` wird nicht als Person klassifiziert
- Digest-Testvektor
- astronomische Nachtfensterlogik
- sichtbare read-only Variablen `Person erkannt` / `Nachtfreigabe`
- neue Betriebsart `nur Personenerkennung` ohne Licht-/Sonnenpflicht
- neue Instanzen enthalten keine fest verdrahteten Terrassen-Licht-IDs
