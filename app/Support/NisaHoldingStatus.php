<?php

namespace App\Support;

/**
 * CHG-0047: 売買シグナル画面の銘柄セルに出すNISA保有区分。
 * holding_snapshot_accounts の口座区分から判定する（成長投資枠・つみたて投資枠は区別しない）。
 */
final class NisaHoldingStatus
{
    public const NISA_ONLY = 'nisa_only';

    public const NISA_PARTIAL = 'nisa_partial';

    private const NISA_ACCOUNT_TYPES = ['nisa_growth', 'nisa_tsumitate'];

    /**
     * @param  iterable<string>  $accountTypes
     */
    public static function fromAccountTypes(iterable $accountTypes): ?string
    {
        $hasNisa = false;
        $hasTaxable = false;

        foreach ($accountTypes as $accountType) {
            if (in_array($accountType, self::NISA_ACCOUNT_TYPES, true)) {
                $hasNisa = true;
            } else {
                $hasTaxable = true;
            }
        }

        if (! $hasNisa) {
            return null;
        }

        return $hasTaxable ? self::NISA_PARTIAL : self::NISA_ONLY;
    }
}
