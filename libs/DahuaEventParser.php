<?php

declare(strict_types=1);

final class DahuaEventParser
{
    /**
     * Nimmt beliebige Stream-Chunks entgegen. Unvollständige letzte Zeilen bleiben in $carry.
     *
     * @return array<int,array{code:string,action:string,index:int,human:bool,raw:string}>
     */
    public static function feed(string $chunk, string &$carry): array
    {
        $carry .= $chunk;
        if (strlen($carry) > 131072) {
            $carry = substr($carry, -65536);
        }

        $parts = preg_split('/\r?\n/', $carry);
        if ($parts === false || count($parts) === 0) {
            return [];
        }

        $carry = (string) array_pop($parts);
        $events = [];

        foreach ($parts as $line) {
            $line = trim((string) $line);
            if ($line === '' || stripos($line, 'Code=') === false) {
                continue;
            }

            if (!preg_match('/Code\s*=\s*([^;]+)\s*;\s*action\s*=\s*([^;\s]+)\s*;\s*index\s*=\s*(\d+)/i', $line, $m)) {
                continue;
            }

            $code = trim((string) $m[1]);
            $action = trim((string) $m[2]);
            $index = (int) $m[3];

            $human = strcasecmp($code, 'SmartMotionHuman') === 0;
            if (!$human) {
                $human = (bool) preg_match('/(?:ObjectType|Object\.ObjectType|Type)["\']?\s*[:=]\s*["\']?Human\b/i', $line);
            }

            $events[] = [
                'code' => $code,
                'action' => $action,
                'index' => $index,
                'human' => $human,
                'raw' => $line
            ];
        }

        return $events;
    }
}
