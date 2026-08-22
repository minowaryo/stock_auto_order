<?php

namespace Tests\Unit\Services\Import\Support;

use App\Services\Import\Support\AccountTypeMapper;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| AccountTypeMapper — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0002-nisa-account-type-tracking.md
|   - docs/architecture/data-model.md (`holding_snapshot_accounts.account_type` enum)
|   - C:\Users\minow\.claude\plans\stock_auto_order-nisa-account-implementation-phase.md
|     (実装方針1: "口座区分ラベル→enumのマッピングを共通化")
|
| App\Services\Import\Support\AccountTypeMapper does not exist yet (no file
| at app/Services/Import/Support/AccountTypeMapper.php). Every test below is
| expected to fail with a fatal "Class \"App\Services\Import\Support\
| AccountTypeMapper\" not found" error — this is the intentional, expected
| Red state (same convention as
| tests/Unit/Services/Analysis/FundamentalIndicatorMapperTest.php).
|
| This is a pure mapping/calculation Unit Test (no DB/HTTP dependency), so
| — matching the other files under tests/Unit/Services/ — it is not bound
| to Tests\TestCase (Unit/ tests are plain Pest per tests/Pest.php).
|
| Assumption flagged for Gate 4 review: the implementation plan only
| specifies "a static method", not an exact method name/signature. This
| test assumes a public static `AccountTypeMapper::toEnum(string $label):
| string` API. If the Green-phase implementation prefers a different method
| name, this test (and the parser tests that reference it conceptually)
| should be revisited together with that choice.
*/

test('特定口座ラベルはspecificにマッピングされる', function () {
    expect(AccountTypeMapper::toEnum('特定口座'))->toBe('specific');
});

test('投資信託CSVの短縮ラベル「特定」もspecificにマッピングされる', function () {
    expect(AccountTypeMapper::toEnum('特定'))->toBe('specific');
});

test('一般口座ラベルはgeneralにマッピングされる', function () {
    expect(AccountTypeMapper::toEnum('一般口座'))->toBe('general');
});

test('NISA成長投資枠ラベルはnisa_growthにマッピングされる', function () {
    expect(AccountTypeMapper::toEnum('NISA成長投資枠'))->toBe('nisa_growth');
});

test('NISAつみたて投資枠ラベルはnisa_tsumitateにマッピングされる', function () {
    expect(AccountTypeMapper::toEnum('NISAつみたて投資枠'))->toBe('nisa_tsumitate');
});

test('未知の口座区分ラベルを渡すと例外が投げられる', function () {
    expect(fn () => AccountTypeMapper::toEnum('謎の口座区分'))
        ->toThrow(InvalidArgumentException::class);
});
