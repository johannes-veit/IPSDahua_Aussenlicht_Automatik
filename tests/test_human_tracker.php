<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DahuaHumanTracker.php';

function failTracker(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function ev(string $code, string $action, int $index, bool $human, $eventId = null, $ruleId = null, $groupId = null, $objectId = null): array
{
    return compact('code', 'action', 'index', 'human', 'eventId', 'ruleId', 'groupId', 'objectId');
}

$active = [];
$active = DahuaHumanTracker::apply($active, ev('CrossLineDetection', 'Start', 0, true, 10021, 4, 20, 20530), 100);
if (count($active) !== 1) {
    failTracker('Human-START wurde nicht gespeichert');
}

// STOP ohne Human-Daten, aber mit EventID muss trotzdem beenden.
$active = DahuaHumanTracker::apply($active, ev('CrossLineDetection', 'Stop', 0, false, 10021), 101);
if ($active !== []) {
    failTracker('STOP ohne Human-Klassifizierung hat bekannten Human-START nicht beendet');
}

// Zwei parallele Regeln mit EventIDs: nur die passende darf beendet werden.
$active = [];
$active = DahuaHumanTracker::apply($active, ev('CrossRegionDetection', 'Start', 0, true, 111, 1, 10), 200);
$active = DahuaHumanTracker::apply($active, ev('CrossRegionDetection', 'Start', 0, true, 222, 2, 20), 201);
if (count($active) !== 2) {
    failTracker('Parallele Human-Ereignisse wurden nicht getrennt gespeichert');
}
$active = DahuaHumanTracker::apply($active, ev('CrossRegionDetection', 'Stop', 0, false, 111, 1, 10), 202);
if (count($active) !== 1) {
    failTracker('STOP einer Regel hat falsche Anzahl aktiver Events hinterlassen');
}
$remaining = reset($active);
if (($remaining['eventId'] ?? null) !== 222) {
    failTracker('Falsches paralleles Human-Ereignis wurde entfernt');
}

// Firmware-Variante STOP nur Code/Index: alle nicht weiter unterscheidbaren Events beenden.
$active = DahuaHumanTracker::apply($active, ev('CrossRegionDetection', 'Stop', 0, false), 203);
if ($active !== []) {
    failTracker('Generischer STOP Code/Index hat Human-Zustand hängen lassen');
}

// SmartMotionHuman ohne IDs muss über Code/Index funktionieren.
$active = DahuaHumanTracker::apply([], ev('SmartMotionHuman', 'Start', 0, true), 300);
$active = DahuaHumanTracker::apply($active, ev('SmartMotionHuman', 'Stop', 0, true), 301);
if ($active !== []) {
    failTracker('SmartMotionHuman START/STOP ohne IDs funktioniert nicht');
}

echo "OK: Human-Event-Tracker bestanden\n";
