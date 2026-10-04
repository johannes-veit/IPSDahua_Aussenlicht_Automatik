<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DahuaEventParser.php';
require_once __DIR__ . '/../libs/DahuaDigest.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$carry = '';
$events = DahuaEventParser::feed("Content-Type: text/plain\r\n\r\nCode=SmartMotionHuman;action=Start;index=0\r\n", $carry);
if (count($events) !== 1 || !$events[0]['human'] || strtolower($events[0]['action']) !== 'start') {
    fail('SmartMotionHuman START wurde nicht erkannt');
}

$carry = '';
$part1 = "--myboundary\r\nContent-Type: text/plain\r\n\r\nCode=CrossLineDetection;action=Start;index=0;data={\r\n"
    . '"Class":"Normal","EventID":10021,"GroupID":20,"RuleID":4,"Object":{"ObjectID":20530,';
$events = DahuaEventParser::feed($part1, $carry);
if ($events !== []) {
    fail('Unvollständiges mehrzeiliges IVS-Ereignis wurde zu früh ausgegeben');
}
$part2 = '"ObjectType":"Human","Action":"Appear"},"Name":"Rule 1"}' . "\r\n--myboundary\r\n";
$events = DahuaEventParser::feed($part2, $carry);
if (count($events) !== 1 || !$events[0]['human']) {
    fail('Mehrzeiliges Human-IVS-Ereignis wurde nicht erkannt');
}
if ($events[0]['eventId'] !== 10021 || $events[0]['ruleId'] !== 4 || $events[0]['groupId'] !== 20 || $events[0]['objectId'] !== 20530) {
    fail('IVS-IDs wurden nicht korrekt extrahiert');
}

$carry = '';
$events = DahuaEventParser::feed(
    "Code=CrossRegionDetection;action=Start;index=0;data={\"Objects\":[{\"ObjectType\":\"Vehicle\"},{\"ObjectType\":\"Human\"}]}\r\n",
    $carry
);
if (count($events) !== 1 || !$events[0]['human']) {
    fail('Human in Objects[] wurde nicht erkannt');
}

$carry = '';
$events = DahuaEventParser::feed(
    "Code=CrossRegionDetection;action=Start;index=0;data={\"Object\":{\"Type\":\"Human\"}}\r\n",
    $carry
);
if (count($events) !== 1 || !$events[0]['human'] || strtolower((string) $events[0]['classification']) !== 'human') {
    fail('Object.Type=Human wurde nicht erkannt');
}

$carry = '';
$events = DahuaEventParser::feed(
    "Code=CrossRegionDetection;action=Stop;index=0;data={\"Object\":{\"Type\":\"Vehicle\"}}\r\n",
    $carry
);
if (count($events) !== 1 || $events[0]['human'] || strtolower((string) $events[0]['classification']) !== 'vehicle') {
    fail('Object.Type=Vehicle wurde nicht als explizite Nicht-Human-Klassifizierung erkannt');
}

$carry = '';
$events = DahuaEventParser::feed(
    "Code=CrossRegionDetection;action=Stop;index=0;data={\"Object\":{\"ObjectType\":\"Vehicle\"}}\r\n",
    $carry
);
if (count($events) !== 1 || $events[0]['human'] || strtolower((string) $events[0]['classification']) !== 'vehicle') {
    fail('Vehicle-STOP wurde falsch klassifiziert');
}

$carry = '';
$events = DahuaEventParser::feed(
    "Code=CrossLineDetection;action=Stop;index=0;data={\"Type\":\"Normal\"}\r\n",
    $carry
);
if (count($events) !== 1 || $events[0]['classification'] !== null) {
    fail('Generisches Type-Feld darf nicht als explizite Objektklassifizierung gelten');
}

$carry = '';
$events = DahuaEventParser::feed("Code=CrossLineDetection;action=Stop;index=0\r\n", $carry);
if (count($events) !== 1 || $events[0]['human'] || $events[0]['classification'] !== null) {
    fail('STOP ohne Datainfo wurde nicht korrekt geparst');
}

$carry = '';
$events = DahuaEventParser::feed(
    "Code=SmartMotionHuman;action=Start;index=0\r\n--myboundary\r\nCode=SmartMotionHuman;action=Stop;index=0\r\n",
    $carry
);
if (count($events) !== 2 || strtolower($events[0]['action']) !== 'start' || strtolower($events[1]['action']) !== 'stop') {
    fail('Zwei Events in einem TCP-Chunk wurden nicht getrennt verarbeitet');
}

$carry = '';
$events = DahuaEventParser::feed("--myboundary\r\nContent-Type: text/plain\r\n\r\nCo", $carry);
if ($events !== []) {
    fail('Teilweiser Code-Anfang darf kein Event erzeugen');
}
$events = DahuaEventParser::feed("de=SmartMotionHuman;action=Pulse;index=0\r\n", $carry);
if (count($events) !== 1 || strtolower($events[0]['action']) !== 'pulse' || !$events[0]['human']) {
    fail('Über TCP-Chunks geteilter Code-Anfang wurde nicht rekonstruiert');
}

$carry = '';
$events = DahuaEventParser::feed("Heartbeat\r\n--myboundary\r\nContent-Length: 9\r\n\r\nHeartbeat\r\n", $carry);
if ($events !== []) {
    fail('Heartbeat darf kein Event erzeugen');
}

$challenge = DahuaDigest::parseChallenge('Digest realm="testrealm@host.com", qop="auth", nonce="dcd98b7102dd2f0e8b11d0f600bfb0c093", opaque="5ccc069c403ebaf9f0171e9517f40e41"');
$auth = DahuaDigest::buildAuthorization(
    'Mufasa',
    'Circle Of Life',
    'GET',
    '/dir/index.html',
    $challenge,
    1,
    '0a4f113b'
);
if (strpos($auth, 'response="6629fae49393a05397450978507c4ef1"') === false) {
    fail('MD5-Digest-Testvektor stimmt nicht');
}

$shaChallenge = DahuaDigest::parseChallenge('Digest realm="cam", qop="auth", nonce="abc", algorithm=SHA-256');
$shaAuth = DahuaDigest::buildAuthorization('admin', 'secret', 'GET', '/cgi-bin/eventManager.cgi', $shaChallenge, 1, '12345678');
if (!str_contains($shaAuth, 'algorithm=SHA-256') || !preg_match('/response="[0-9a-f]{64}"/', $shaAuth)) {
    fail('SHA-256-Digest wurde nicht korrekt erzeugt');
}

echo "OK: Multi-Line-Parser, Human-Klassifizierung und Digest-Tests bestanden\n";
