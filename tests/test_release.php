<?php

declare(strict_types=1);

function failRelease(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$library = json_decode((string) file_get_contents($root . '/library.json'), true);
$module = json_decode((string) file_get_contents($root . '/AussenlichtAutomatik2/module.json'), true);
$source = (string) file_get_contents($root . '/AussenlichtAutomatik2/module.php');
$form = (string) file_get_contents($root . '/AussenlichtAutomatik2/form.json');

if (($library['version'] ?? '') !== '0.3.2' || (int) ($library['build'] ?? 0) < 9) {
    failRelease('library.json ist nicht auf 0.3.2/build>=9');
}
if (($library['id'] ?? '') !== '{E5E78612-3E17-4E92-9D0C-A2B0F8E1B690}') {
    failRelease('Library-GUID wurde unerwartet verändert');
}
if (($module['id'] ?? '') !== '{5E08D4EE-9727-4682-A23E-E8625EB2337E}') {
    failRelease('Modul-GUID wurde unerwartet verändert');
}
if (($module['prefix'] ?? '') !== 'ALA2') {
    failRelease('Prefix wurde unerwartet verändert');
}
foreach ([
    "require_once dirname(__DIR__) . '/libs/DahuaHumanTracker.php';",
    "RegisterAttributeString('ActiveHumanEvents', '{}')",
    "RegisterTimer('PersonStateTimer'",
    "HasAction(\$commandVar)",
    "RequestAction(\$commandVar, \$on)",
    "return ((float) GetValue(\$varID)) > 0.0;",
    "if (\$light && \$this->ReadAttributeBoolean('AutoOwned'))",
    "Automatik AUS angefordert; warte auf echte Intensity=0",
    'bereits gesendet; warte auf echte Intensity-Rückmeldung',
    "RegisterAttributeInteger('RegisteredCommandID', 0)",
    "private function clearSelfCommand(): void"
] as $needle) {
    if (!str_contains($source, $needle)) {
        failRelease('Release-Code fehlt: ' . $needle);
    }
}
foreach (['192.168.107.110', '48535', '44137'] as $legacyHardcode) {
    if (str_contains($source, $legacyHardcode)) {
        failRelease('Unerwünschter Terrassen-Hardcode im Modul: ' . $legacyHardcode);
    }
}
if (!str_contains($form, 'IPC-HFW5442E-ZE') || !str_contains($form, 'CrossRegionDetection')) {
    failRelease('Formular dokumentiert die neue universelle IPC-/IVS-Auswertung nicht');
}

echo "OK: Release-Metadaten, GUIDs und universelle Konfiguration geprüft\n";
