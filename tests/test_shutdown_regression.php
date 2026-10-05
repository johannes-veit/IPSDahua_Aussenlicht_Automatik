<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../AussenlichtAutomatik2/module.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: module.php nicht lesbar\n");
    exit(1);
}

$checks = [
    'Eigentum vor EIN-Request' => "\$this->WriteAttributeBoolean('AutoOwned', true);",
    'verspätetes EIN behält Eigentum' => "if (\$light && \$this->ReadAttributeBoolean('AutoOwned'))",
    'AUS wartet auf echte Intensity' => "Automatik AUS angefordert; warte auf echte Intensity=0",
    'temporär fehlende Rückmeldung wird wiederholt' => "echte LCN-Intensity momentan nicht verfügbar; erneute Prüfung in 15 s",
    'SelfCommand-Fenster über Konstante' => "self::LIGHT_CONFIRM_SECONDS",
    'Doppelbefehl-Sperre' => 'bereits gesendet; warte auf echte Intensity-Rückmeldung'
];

foreach ($checks as $label => $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "FAIL: {$label} fehlt\n");
        exit(1);
    }
}

// Der alte Fehler darf nicht mehr vorkommen: nach erfolgreichem AUS-Request
// sofort AutoOwned=false setzen, obwohl die Hardware-Rückmeldung noch EIN sein kann.
$offStart = strpos($source, 'public function OffTimer(): void');
$offEnd = strpos($source, 'private function beginHandshake(): void', $offStart ?: 0);
$off = ($offStart !== false && $offEnd !== false) ? substr($source, $offStart, $offEnd - $offStart) : '';
if (strpos($off, "if (\$this->switchLight(false)) {\n            \$this->WriteAttributeBoolean('AutoOwned', false);") !== false) {
    fwrite(STDERR, "FAIL: alter Sofortverlust von AutoOwned nach AUS ist noch vorhanden\n");
    exit(1);
}

if (strpos($off, "if (\$light === false)") === false || strpos($off, "WriteAttributeBoolean('AutoOwned', false)") === false) {
    fwrite(STDERR, "FAIL: echte Intensity=0 bestätigt AUS nicht\n");
    exit(1);
}

echo "OK: Ausschalt-/Eigentumsregression bestanden\n";
