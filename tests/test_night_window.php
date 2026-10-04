<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/NightWindow.php';

date_default_timezone_set('Europe/Berlin');

function failNight(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function ts(string $value): int
{
    $ts = strtotime($value);
    if ($ts === false) {
        failNight('Ungültige Testzeit: ' . $value);
    }
    return $ts;
}

$cases = [
    // Vor Sonnenaufgang: beide nächsten Ereignisse liegen heute; Sonnenaufgang kommt zuerst.
    ['2026-10-04 06:00:00', '2026-10-04 07:30:00', '2026-10-04 18:50:00', true, 'vor Sonnenaufgang'],
    // Exakt am Sonnenaufgang: Sonnenaufgang gilt nicht mehr als zukünftiges Ereignis -> Tag.
    ['2026-10-04 07:30:00', '2026-10-04 07:30:00', '2026-10-04 18:50:00', false, 'exakt Sonnenaufgang vor Neuberechnung'],
    // Location Control hat Sonnenaufgang bereits auf morgen weitergesetzt.
    ['2026-10-04 12:00:00', '2026-10-05 07:31:00', '2026-10-04 18:50:00', false, 'Tag nach Neuberechnung Sonnenaufgang'],
    // Exakt am Sonnenuntergang: nur Sonnenaufgang liegt noch in der Zukunft -> Nacht.
    ['2026-10-04 18:50:00', '2026-10-05 07:31:00', '2026-10-04 18:50:00', true, 'exakt Sonnenuntergang vor Neuberechnung'],
    // Nach Sonnenuntergang hat Location Control auch Sunset auf morgen gesetzt.
    ['2026-10-04 20:00:00', '2026-10-05 07:31:00', '2026-10-05 18:48:00', true, 'Nacht nach Neuberechnung Sonnenuntergang'],
    // Frühling: morgiger Sonnenuntergang ist später als heutiger. Die absolute Reihenfolge
    // verhindert den Fehler, den ein reiner H:i:s-Vergleich direkt nach Sunset machen würde.
    ['2026-04-04 20:01:00', '2026-04-05 06:50:00', '2026-04-05 20:02:00', true, 'Frühling nach Sonnenuntergang'],
];

foreach ($cases as [$now, $sunrise, $sunset, $expected, $label]) {
    $actual = NightWindow::isNight(ts($now), ts($sunrise), ts($sunset));
    if ($actual !== $expected) {
        failNight($label . ': erwartet ' . ($expected ? 'Nacht' : 'Tag'));
    }
}

$next = NightWindow::nextBoundary(
    ts('2026-10-04 12:00:00'),
    ts('2026-10-05 07:31:00'),
    ts('2026-10-04 18:50:00')
);
if ($next === null || date('Y-m-d H:i:s', $next) !== '2026-10-04 18:50:00') {
    failNight('Nächste Grenze tagsüber muss Sonnenuntergang sein');
}

$next = NightWindow::nextBoundary(
    ts('2026-10-04 20:00:00'),
    ts('2026-10-05 07:31:00'),
    ts('2026-10-05 18:48:00')
);
if ($next === null || date('Y-m-d H:i:s', $next) !== '2026-10-05 07:31:00') {
    failNight('Nächste Grenze nachts muss Sonnenaufgang sein');
}

echo "OK: Nachtfenster-Tests bestanden\n";
