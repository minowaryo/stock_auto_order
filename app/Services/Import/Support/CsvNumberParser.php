<?php

namespace App\Services\Import\Support;

use InvalidArgumentException;

/**
 * Parses 楽天証券 CSV numeric cells, which are quoted, comma-separated
 * strings (e.g. "2,000.00") (UC-001業務ルール).
 */
final class CsvNumberParser
{
    public static function parse(string $value): float
    {
        $normalized = str_replace(',', '', trim($value));

        if ($normalized === '' || ! is_numeric($normalized)) {
            throw new InvalidArgumentException("Not a numeric CSV value: {$value}");
        }

        return (float) $normalized;
    }
}
