<?php

use App\Services\Analysis\BuySignalDeterminationService;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| BuySignalDeterminationService — 買い増し判定のPER基準の業種相対化
| CHG-0048 サイクル4（ADR-0026 D3、2026-10-04改訂 / UC-010・UC-012）— Red phase
|--------------------------------------------------------------------------
|
| 固定する約束:
|   - determine() の末尾に任意引数 ?string $market = null, ?string $sectorName = null を追加。
|   - PER単体シグナル（per_undervalued）と、低成長銘柄の代替条件（PER条件＋配当利回り≧3%）の
|     PERの条件を ValuationBenchmarkJudge::buyPerVerdict() の met で判定する。
|       信頼度 high/medium: 比率 < 0.80 ／ low: 比率 < 0.70 ／ none・未分類・表にない業種: PER≦15。
|   - market・sectorName が null の既存の呼び出しは従来どおり PER≦15（回帰ガード）。
|   - reason_summary の文言・配当利回り・PEG・前提条件は変えない。
|
| 基準値は実設定（config/valuation_benchmarks.php）。サービスはコンテナから解決する
| （ValuationBenchmarkJudge は AppServiceProvider で config から生成される）ため、
| このファイルは TestCase を使う（DBは使わない）。
|
| 価格推移は BuySignalDeterminationServiceTest の per_undervalued 用フィクスチャと同じ
| range(100, 151)（52週連続 +1/週）＋ marketReturn13w 0.0。前提条件A・Bを満たし、
| 7種のテクニカル条件はどれも単独では成立しないことを同ファイルで検証済み。
|
| Expected Red: determine() に market / sectorName の名前付き引数が無いため
| "Unknown named parameter $market" の Error。回帰ガード（market 省略）のテストは現状でも通る。
|
| 関数名は他ファイルとの再宣言衝突を避けるため `chg48bsd` 接頭辞。
*/

uses(TestCase::class);

/**
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function chg48bsdCalmHistory(): array
{
    $history = [];
    $date = new DateTimeImmutable('2024-01-01');

    foreach (range(100, 151) as $i => $close) {
        $history[] = [
            'date' => $date->modify("+{$i} weeks")->format('Y-m-d'),
            'close' => (float) $close,
            'volume' => 1000,
        ];
    }

    return $history;
}

function chg48bsdService(): BuySignalDeterminationService
{
    return app(BuySignalDeterminationService::class);
}

/**
 * @param  array<int, array{signal_type: string, reason_summary: string}>  $signals
 * @return array<int, string>
 */
function chg48bsdTypes(array $signals): array
{
    return array_column($signals, 'signal_type');
}

/**
 * PER単体シグナルの判定（低成長でない＝成長率の引数は渡さない）。
 *
 * @return array<int, array{signal_type: string, reason_summary: string}>
 */
function chg48bsdPerOnly(float $per, ?string $market, ?string $sectorName): array
{
    return chg48bsdService()->determine(
        chg48bsdCalmHistory(),
        marketReturn13w: 0.0,
        per: $per,
        market: $market,
        sectorName: $sectorName,
    );
}

/**
 * 低成長（売上成長率3.0%≦5%）の代替条件の判定。PEGは渡さない。
 *
 * @return array<int, array{signal_type: string, reason_summary: string}>
 */
function chg48bsdLowGrowth(float $per, float $dividendYield, ?string $market, ?string $sectorName): array
{
    return chg48bsdService()->determine(
        chg48bsdCalmHistory(),
        marketReturn13w: 0.0,
        pegRatio: null,
        per: $per,
        revenueGrowth: 3.0,
        operatingIncomeGrowth: null,
        dividendYield: $dividendYield,
        market: $market,
        sectorName: $sectorName,
    );
}

// --- PER単体シグナル（per_undervalued） ---

test('UC-010: 日本・小売（基準22.5、high）でPER17.4は比率0.77のため per_undervalued が発生する（従来の15では発生しない）', function () {
    // Act
    $result = chg48bsdPerOnly(17.4, 'jp', '小売');

    // Assert
    expect(chg48bsdTypes($result))->toContain('per_undervalued');
});

test('UC-010: 日本・建設・資材（基準15.4、medium）でPER13.3は比率0.86のため per_undervalued は発生しない（従来の15では発生する）', function () {
    // Act
    $result = chg48bsdPerOnly(13.3, 'jp', '建設・資材');

    // Assert
    expect(chg48bsdTypes($result))->not->toContain('per_undervalued');
});

test('UC-010: 日本・電機・精密（基準28.5、low）でPER19.0は比率0.67（0.70未満）のため per_undervalued が発生する', function () {
    // Act
    $result = chg48bsdPerOnly(19.0, 'jp', '電機・精密');

    // Assert
    expect(chg48bsdTypes($result))->toContain('per_undervalued');
});

test('UC-010: 日本・電機・精密（基準28.5、low）でPER20.0は比率0.70のため per_undervalued は発生しない（境界）', function () {
    // Act
    $result = chg48bsdPerOnly(20.0, 'jp', '電機・精密');

    // Assert
    expect(chg48bsdTypes($result))->not->toContain('per_undervalued');
});

test('UC-010: 米国・Automobiles（信頼度none）は従来どおりPER≦15で判定し、PER14.0で per_undervalued が発生する', function () {
    // Act
    $result = chg48bsdPerOnly(14.0, 'us', 'Automobiles');

    // Assert
    expect(chg48bsdTypes($result))->toContain('per_undervalued');
});

test('UC-010: 業種未分類はPER≦15で判定し、PER14.0は発生・PER16.0は発生しない', function () {
    // Act
    $met = chg48bsdPerOnly(14.0, 'jp', null);
    $unmet = chg48bsdPerOnly(16.0, 'jp', null);

    // Assert
    expect(chg48bsdTypes($met))->toContain('per_undervalued');
    expect(chg48bsdTypes($unmet))->not->toContain('per_undervalued');
});

test('UC-010: 基準表にない業種はPER≦15で判定する（PER14.0は発生・PER16.0は発生しない）', function () {
    // Act
    $met = chg48bsdPerOnly(14.0, 'jp', '存在しない業種');
    $unmet = chg48bsdPerOnly(16.0, 'jp', '存在しない業種');

    // Assert
    expect(chg48bsdTypes($met))->toContain('per_undervalued');
    expect(chg48bsdTypes($unmet))->not->toContain('per_undervalued');
});

test('UC-010: 業種相対でもPERが負値（赤字）なら per_undervalued は発生しない', function () {
    // Act
    $result = chg48bsdPerOnly(-8.0, 'jp', '小売');

    // Assert
    expect(chg48bsdTypes($result))->not->toContain('per_undervalued');
});

test('UC-010: 業種相対で発生した per_undervalued の reason_summary の文言は従来どおり', function () {
    // Act
    $result = chg48bsdPerOnly(17.4, 'jp', '小売');

    // Assert
    $signal = collect($result)->firstWhere('signal_type', 'per_undervalued');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toBe('PERが17.4と割安水準です');
});

// --- 回帰ガード（市場・業種を渡さない既存の呼び出し） ---

test('回帰: 市場・業種を渡さない既存の呼び出しは従来どおりPER≦15で判定する（15.0は発生・15.01は発生しない）', function () {
    // Act
    $met = chg48bsdService()->determine(chg48bsdCalmHistory(), marketReturn13w: 0.0, per: 15.0);
    $unmet = chg48bsdService()->determine(chg48bsdCalmHistory(), marketReturn13w: 0.0, per: 15.01);

    // Assert
    expect(chg48bsdTypes($met))->toBe(['per_undervalued']);
    expect(chg48bsdTypes($unmet))->not->toContain('per_undervalued');
});

test('回帰: 市場・業種を渡さない既存の呼び出しでは、小売相当のPER17.4でも per_undervalued は発生しない', function () {
    // Act
    $result = chg48bsdService()->determine(chg48bsdCalmHistory(), marketReturn13w: 0.0, per: 17.4);

    // Assert
    expect(chg48bsdTypes($result))->not->toContain('per_undervalued');
});

// --- 低成長銘柄の代替条件（PER条件＋配当利回り≧3%） ---

test('UC-010: 低成長の日本・食品（基準18.9、high）でPER15.0・配当利回り3.5%は比率0.79のため代替条件が成立し peg_undervalued が発生する', function () {
    // Act
    $result = chg48bsdLowGrowth(15.0, 3.5, 'jp', '食品');

    // Assert
    expect(chg48bsdTypes($result))->toContain('peg_undervalued');
});

test('UC-010: 低成長の日本・建設・資材（基準15.4、medium）でPER13.3・配当利回り3.5%は比率0.86のため代替条件が不成立（従来の15では成立）', function () {
    // Act
    $result = chg48bsdLowGrowth(13.3, 3.5, 'jp', '建設・資材');

    // Assert
    expect(chg48bsdTypes($result))->not->toContain('peg_undervalued');
});

test('UC-010: 低成長の日本・小売（基準22.5）でPER17.4・配当利回り3.5%は代替条件が成立し、reason_summary の文言は従来どおり', function () {
    // Act
    $result = chg48bsdLowGrowth(17.4, 3.5, 'jp', '小売');

    // Assert
    $signal = collect($result)->firstWhere('signal_type', 'peg_undervalued');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toBe('PERが17.4倍・配当利回りが3.5%と低成長銘柄の割安水準です');
});

test('UC-010: 低成長の代替条件は業種相対でPER条件を満たしても、配当利回りが3%未満なら不成立のまま', function () {
    // Act
    $result = chg48bsdLowGrowth(17.4, 2.9, 'jp', '小売');

    // Assert
    expect(chg48bsdTypes($result))->not->toContain('peg_undervalued');
});

test('回帰: 低成長の代替条件も市場・業種を渡さない呼び出しは従来どおりPER≦15（PER13.3・配当3.5%で成立）', function () {
    // Act
    $result = chg48bsdService()->determine(
        chg48bsdCalmHistory(),
        marketReturn13w: 0.0,
        pegRatio: null,
        per: 13.3,
        revenueGrowth: 3.0,
        operatingIncomeGrowth: null,
        dividendYield: 3.5,
    );

    // Assert
    expect(chg48bsdTypes($result))->toContain('peg_undervalued');
});

// --- 前提条件は変えない ---

test('UC-010: 業種相対でPER条件を満たしても、前提条件B（相対力）が不成立なら per_undervalued は発生しない', function () {
    // Arrange: marketReturn13w 未指定 → 相対力 null → 前提条件B不成立
    // Act
    $result = chg48bsdService()->determine(chg48bsdCalmHistory(), per: 17.4, market: 'jp', sectorName: '小売');

    // Assert
    expect($result)->toBe([]);
});
