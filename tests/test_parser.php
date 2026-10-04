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
$events = DahuaEventParser::feed("Code=CrossLineDetection;action=Start;index=0;data={\"Object\":{\"ObjectType\":\"Human\"}}\r\n", $carry);
if (count($events) !== 1 || !$events[0]['human']) {
    fail('Human-IVS-Ereignis wurde nicht erkannt');
}

$carry = '';
$events = DahuaEventParser::feed("Code=VideoMotion;action=Start;index=0\r\n", $carry);
if (count($events) !== 1 || $events[0]['human']) {
    fail('VideoMotion darf nicht als Person gelten');
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
    fail('Digest-Testvektor stimmt nicht');
}

echo "OK: Parser- und Digest-Tests bestanden\n";
