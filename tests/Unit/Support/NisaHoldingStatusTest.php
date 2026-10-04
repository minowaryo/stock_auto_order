<?php

namespace Tests\Unit\Support;

use App\Support\NisaHoldingStatus;

/*
|--------------------------------------------------------------------------
| NisaHoldingStatus — Unit Test (CHG-0047) — Red phase
|--------------------------------------------------------------------------
|
| Source of truth: docs/product/use-cases.md UC-004 業務ルール
| 「NISA保有区分のバッジ（CHG-0047）」.
|
| Contract this Red phase proposes:
|   - NisaHoldingStatus::fromAccountTypes(iterable<string> $accountTypes): ?string
|       all NISA (nisa_growth / nisa_tsumitate, not distinguished) → 'nisa_only'
|       NISA mixed with taxable (specific / general)               → 'nisa_partial'
|       taxable only, or no breakdown rows                         → null
|   - Pure function, no DB.
|
| The class does not exist yet → every test fails with "Class not found".
*/

describe('CHG-0047: NISA保有区分の判定', function () {
    test('口座区分の組み合わせから NISAのみ／NISA一部／なし を判定する', function (array $accountTypes, ?string $expected) {
        expect(NisaHoldingStatus::fromAccountTypes($accountTypes))->toBe($expected);
    })->with([
        'NISA成長投資枠のみ' => [['nisa_growth'], 'nisa_only'],
        'NISAつみたて投資枠のみ' => [['nisa_tsumitate'], 'nisa_only'],
        '成長枠とつみたて枠（区別しない）' => [['nisa_growth', 'nisa_tsumitate'], 'nisa_only'],
        '特定口座とNISA成長投資枠' => [['specific', 'nisa_growth'], 'nisa_partial'],
        '一般口座とNISAつみたて投資枠' => [['general', 'nisa_tsumitate'], 'nisa_partial'],
        '特定口座のみ' => [['specific'], null],
        '特定口座と一般口座' => [['specific', 'general'], null],
        '内訳なし（旧スナップショット）' => [[], null],
    ]);

    test('Collection など iterable も受け付ける', function () {
        expect(NisaHoldingStatus::fromAccountTypes(collect(['specific', 'nisa_growth'])))->toBe('nisa_partial');
    });
});
