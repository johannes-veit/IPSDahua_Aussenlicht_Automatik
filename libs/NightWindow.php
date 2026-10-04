<?php

declare(strict_types=1);

final class NightWindow
{
    public static function isNight(int $now, int $sunrise, int $sunset): bool
    {
        if ($now <= 0 || $sunrise <= 0 || $sunset <= 0) {
            return false;
        }

        // Location Control setzt einen erreichten Sonnenzeitpunkt auf den nächsten
        // entsprechenden Zeitpunkt weiter. Deshalb wird nicht nur die Uhrzeit verglichen:
        // Das nächste noch bevorstehende Sonnenereignis bestimmt die aktuelle Phase.
        //   nächstes Ereignis Sonnenaufgang  -> wir befinden uns in der Nacht
        //   nächstes Ereignis Sonnenuntergang -> wir befinden uns am Tag
        $futureSunrise = $sunrise > $now ? $sunrise : null;
        $futureSunset = $sunset > $now ? $sunset : null;

        if ($futureSunrise !== null || $futureSunset !== null) {
            if ($futureSunrise === null) {
                return false; // nur Sonnenuntergang liegt noch vor uns -> Tag
            }
            if ($futureSunset === null) {
                return true; // nur Sonnenaufgang liegt noch vor uns -> Nacht
            }
            return $futureSunrise < $futureSunset;
        }

        // Defensive Fallback-Logik für veraltete Werte: Das zuletzt eingetretene
        // Sonnenereignis bestimmt die Phase.
        return $sunset > $sunrise;
    }

    public static function nextBoundary(int $now, int $sunrise, int $sunset): ?int
    {
        if ($now <= 0 || $sunrise <= 0 || $sunset <= 0) {
            return null;
        }

        $future = [];
        if ($sunrise > $now) {
            $future[] = $sunrise;
        }
        if ($sunset > $now) {
            $future[] = $sunset;
        }
        if ($future !== []) {
            return min($future);
        }

        // Nur als Fallback bei veralteten Location-Control-Werten: die gespeicherten
        // Uhrzeiten auf den nächsten Kalendertag projizieren.
        $today = date('Y-m-d', $now);
        $candidates = [];
        foreach ([$sunrise, $sunset] as $source) {
            $clock = date('H:i:s', $source);
            $candidate = strtotime($today . ' ' . $clock);
            if ($candidate === false) {
                continue;
            }
            if ($candidate <= $now) {
                $candidate = strtotime('+1 day', $candidate);
                if ($candidate === false) {
                    continue;
                }
            }
            $candidates[] = $candidate;
        }

        return $candidates === [] ? null : min($candidates);
    }
}
