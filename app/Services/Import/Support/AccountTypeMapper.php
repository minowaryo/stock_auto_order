<?php

namespace App\Services\Import\Support;

use InvalidArgumentException;

/**
 * Maps a 楽天証券 CSV's raw 口座区分 (account type) label text to the
 * `holding_snapshot_accounts.account_type` enum value used across the app
 * (docs/architecture/data-model.md, docs/adr/ADR-0002-nisa-account-type-tracking.md).
 */
final class AccountTypeMapper
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        '特定口座' => 'specific',
        '特定' => 'specific',
        '一般口座' => 'general',
        'NISA成長投資枠' => 'nisa_growth',
        'NISAつみたて投資枠' => 'nisa_tsumitate',
    ];

    public static function toEnum(string $label): string
    {
        if (! isset(self::MAP[$label])) {
            throw new InvalidArgumentException("Unknown account type label: {$label}");
        }

        return self::MAP[$label];
    }
}
