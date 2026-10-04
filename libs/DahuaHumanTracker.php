<?php

declare(strict_types=1);

final class DahuaHumanTracker
{
    /**
     * @param array<string,array<string,mixed>> $active
     * @param array<string,mixed> $event
     * @return array<string,array<string,mixed>>
     */
    public static function apply(array $active, array $event, int $now): array
    {
        $action = strtolower(trim((string) ($event['action'] ?? '')));
        if (in_array($action, ['start', 'on'], true)) {
            if (!(bool) ($event['human'] ?? false)) {
                return $active;
            }
            $key = self::makeKey($event);
            $active[$key] = self::entry($event, $now);
            return $active;
        }

        if (!in_array($action, ['stop', 'off'], true)) {
            return $active;
        }

        $matches = self::matchingKeys($active, $event);
        foreach ($matches as $key) {
            unset($active[$key]);
        }
        return $active;
    }

    /**
     * @param array<string,mixed> $event
     */
    public static function makeKey(array $event): string
    {
        $code = strtolower(trim((string) ($event['code'] ?? 'unknown')));
        $index = (int) ($event['index'] ?? 0);

        foreach (['eventId', 'ruleId', 'groupId', 'objectId'] as $field) {
            $value = $event[$field] ?? null;
            if ($value !== null && $value !== '') {
                return $code . ':' . $index . ':' . strtolower($field) . '=' . (string) $value;
            }
        }
        return $code . ':' . $index;
    }

    /**
     * @param array<string,array<string,mixed>> $active
     * @param array<string,mixed> $event
     * @return string[]
     */
    private static function matchingKeys(array $active, array $event): array
    {
        $code = strtolower(trim((string) ($event['code'] ?? '')));
        $index = (int) ($event['index'] ?? 0);
        $base = [];

        foreach ($active as $key => $entry) {
            if (strtolower((string) ($entry['code'] ?? '')) !== $code) {
                continue;
            }
            if ((int) ($entry['index'] ?? 0) !== $index) {
                continue;
            }
            $base[$key] = $entry;
        }

        if ($base === []) {
            return [];
        }

        $provided = [];
        foreach (['eventId', 'ruleId', 'groupId', 'objectId'] as $field) {
            $value = $event[$field] ?? null;
            if ($value !== null && $value !== '') {
                $provided[$field] = (string) $value;
            }
        }

        if ($provided === []) {
            // Manche Firmwares liefern beim STOP nur Code/Index. In diesem Fall ist
            // der STOP die einzige Information, die wir haben. Alle aktiven Human-
            // Ereignisse dieses Code/Index-Paares werden beendet, damit der Status
            // nicht dauerhaft hängen bleibt.
            return array_keys($base);
        }

        $exact = [];
        foreach ($base as $key => $entry) {
            $compatible = true;
            $matched = false;
            foreach ($provided as $field => $value) {
                $entryValue = $entry[$field] ?? null;
                if ($entryValue === null || $entryValue === '') {
                    continue;
                }
                if ((string) $entryValue !== $value) {
                    $compatible = false;
                    break;
                }
                $matched = true;
            }
            if ($compatible && $matched) {
                $exact[] = $key;
            }
        }

        if ($exact !== []) {
            return $exact;
        }

        // Falls der START weniger IDs als der STOP enthielt, aber nur genau ein
        // Human-Ereignis dieses Code/Index-Paares aktiv ist, ist die Zuordnung eindeutig.
        return count($base) === 1 ? array_keys($base) : [];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private static function entry(array $event, int $now): array
    {
        return [
            'code' => (string) ($event['code'] ?? ''),
            'index' => (int) ($event['index'] ?? 0),
            'eventId' => $event['eventId'] ?? null,
            'ruleId' => $event['ruleId'] ?? null,
            'groupId' => $event['groupId'] ?? null,
            'objectId' => $event['objectId'] ?? null,
            'startedAt' => $now
        ];
    }
}
