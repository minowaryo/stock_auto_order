<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Formats timestamps for display in the user's display timezone
 * (config('app.display_timezone'), default Asia/Manila). Storage and the
 * app timezone stay UTC; the given instance is never mutated (ADR-0018).
 */
final class DisplayTime
{
    public static function timezone(): string
    {
        return (string) config('app.display_timezone');
    }

    public static function dateTime(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone(self::timezone())->format('Y-m-d H:i');
    }

    public static function date(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone(self::timezone())->format('Y-m-d');
    }
}
