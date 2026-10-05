<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DahuaEventParser.php';

function failChunk(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$stream = "--b\r\nContent-Type: text/plain\r\n\r\n"
    . "Code=CrossRegionDetection;action=Start;index=2;data={\r\n"
    . '"EventID":4711,"RuleID":8,"Object":{"ObjectID":99,"ObjectType":"Human","Name":"A } in string"}}'
    . "\r\n--b\r\nContent-Type: text/plain\r\n\r\n"
    . "Code=CrossRegionDetection;action=Stop;index=2;data={\"EventID\":4711,\"RuleID\":8}\r\n"
    . "--b\r\nHeartbeat\r\n";

$runChunks = static function (array $chunks): array {
    $carry = '';
    $events = [];
    foreach ($chunks as $chunk) {
        foreach (DahuaEventParser::feed($chunk, $carry) as $event) {
            $events[] = $event;
        }
    }
    foreach (DahuaEventParser::feed('', $carry) as $event) {
        $events[] = $event;
    }
    return $events;
};

$validate = static function (array $events, string $label): void {
    if (count($events) !== 2) {
        failChunk("{$label}: erwartet 2 Events, erhalten " . count($events));
    }
    if (!$events[0]['human'] || strtolower($events[0]['action']) !== 'start' || $events[0]['eventId'] !== 4711 || $events[0]['objectId'] !== 99) {
        failChunk("{$label}: START falsch rekonstruiert");
    }
    if (strtolower($events[1]['action']) !== 'stop' || $events[1]['eventId'] !== 4711) {
        failChunk("{$label}: STOP falsch rekonstruiert");
    }
};

// Jedes einzelne Byte als eigener TCP-Chunk.
$validate($runChunks(str_split($stream, 1)), 'byteweise');

// Jede mögliche einzelne Trennposition.
$len = strlen($stream);
for ($i = 1; $i < $len; $i++) {
    $validate($runChunks([substr($stream, 0, $i), substr($stream, $i)]), 'split@' . $i);
}

// Deterministische Mischgrößen.
for ($seed = 1; $seed <= 50; $seed++) {
    mt_srand($seed);
    $chunks = [];
    $offset = 0;
    while ($offset < $len) {
        $size = mt_rand(1, 37);
        $chunks[] = substr($stream, $offset, $size);
        $offset += $size;
    }
    $validate($runChunks($chunks), 'random#' . $seed);
}

echo "OK: Parser über Byte-, Split- und Zufalls-TCP-Chunkgrenzen robust\n";
