<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../AussenlichtAutomatik2/module.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: module.php nicht lesbar\n");
    exit(1);
}

$required = [
    "RegisterVariableBoolean('PersonDetected', 'Person erkannt'",
    "RegisterVariableBoolean('NightPermission', 'Nachtfreigabe'",
    "setPersonActive(true)",
    "setPersonActive(false)",
    'setStatusVariable(\'NightPermission\', $night)',
    "syncStatusVariables()"
];

foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "FAIL: Statusvariablen-Prüfung fehlt: {$needle}\n");
        exit(1);
    }
}

if (strpos($source, "EnableAction('PersonDetected')") !== false || strpos($source, "EnableAction('NightPermission')") !== false) {
    fwrite(STDERR, "FAIL: Diagnosevariablen dürfen keine Benutzeraktion haben\n");
    exit(1);
}

echo "OK: sichtbare Statusvariablen statisch geprüft\n";
