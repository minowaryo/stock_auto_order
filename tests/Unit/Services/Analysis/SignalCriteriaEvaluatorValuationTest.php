<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\SignalCriteriaEvaluator;

/*
|--------------------------------------------------------------------------
| SignalCriteriaEvaluator — 業種比較の判定・健全性の強い良好をチップに持たせる
| CHG-0034 サイクル3a（データ層のみ）— Red phase
|--------------------------------------------------------------------------
|
| Source of truth: docs/adr/ADR-0023 D3-D9 / ADR-0026 D1-D4,
| docs/product/use-cases.md UC-004・UC-010・UC-011・UC-013（CHG-0048）。
|
| 固定する約束:
|   - $metrics の任意キー per_verdict / pbr_verdict（ValuationBenchmarkJudge::judge()
|     の結果配列）と metric_tones（roe / equity_ratio / operating_margin /
|     revenue_growth / operating_income_growth）。
|   - PER・PBRチップ: 'valuation' => ['tier' => string, 'unstable' => bool] | null
|       利確検討・キープ: 両方 / 整理検討: 両方 + 'label_only' => true /
|       買い増し候補: PBRのみ（PERは従来どおり 'valuation' キー無し＝サイクル4で変更）。
|   - 財務チップ（ROE・自己資本比率・営業利益率・成長率）: 'strength' =>
|     'strong_good' | null。status が met かつ対応する tone が strong_good のときだけ。
|     成長率チップは higherGrowthRate で選ばれた側の指標のtoneを見る。
|     整理検討の財務チップには strength を付けない（赤の単一極性、ADR-0010 D6）。
*/

/** @return array<string, mixed> */
function chg34scVerdict(?string $tier, bool $unstable = false): array
{
    return [
        'tier' => $tier,
        'ratio' => $tier === null ? null : 0.4,
        'benchmark' => $tier === null ? null : 23.5,
        'confidence' => $tier === null ? null : ($unstable ? 'low' : 'high'),
        'unstable' => $unstable,
        'as_of' => '2026-09',
        'source' => 'test',
        'reason' => $tier === null ? 'sector_unclassified' : null,
    ];
}

/** @return array<string, float|null> 財務4項目がすべて健全（met）になる基準値 */
function chg34scHealthyMetrics(array $overrides = []): array
{
    return array_merge([
        'per' => 10.0,
        'pbr' => 0.9,
        'roe' => 16.0,
        'equity_ratio' => 75.0,
        'operating_margin' => 25.0,
        'revenue_growth' => 20.0,
        'operating_income_growth' => 5.0,
    ], $overrides);
}

function chg34scAllStrongTones(): array
{
    return [
        'roe' => 'strong_good',
        'equity_ratio' => 'strong_good',
        'operating_margin' => 'strong_good',
        'revenue_growth' => 'strong_good',
        'operating_income_growth' => 'strong_good',
    ];
}

function chg34scChip(array $criteria, string $group, string $label): array
{
    foreach ($criteria[$group] as $chip) {
        if ($chip['label'] === $label) {
            return $chip;
        }
    }

    throw new \RuntimeException("chip not found: {$label}");
}

/** 評価メソッド名 => 呼び出し */
dataset('chg34scTakeProfitAndHold', [
    '利確検討' => ['evaluateTakeProfit'],
    'キープ' => ['evaluateHold'],
]);

dataset('chg34scEvaluatorsWithFundamentalStrength', [
    '利確検討' => ['evaluateTakeProfit'],
    '買い増し候補' => ['evaluateBuy'],
    'キープ' => ['evaluateHold'],
]);

// --- PER/PBR の valuation ---

test('利確検討・キープ: PER・PBRチップは業種比較の段階と基準の不安定さを valuation に持つ', function (string $method) {
    // Arrange
    $metrics = chg34scHealthyMetrics([
        'per_verdict' => chg34scVerdict('strong_cheap'),
        'pbr_verdict' => chg34scVerdict('cheap', true),
    ]);

    // Act
    $criteria = (new SignalCriteriaEvaluator)->{$method}($metrics);

    // Assert
    expect(chg34scChip($criteria, 'technical', 'PER')['valuation'])->toBe(['tier' => 'strong_cheap', 'unstable' => false]);
    expect(chg34scChip($criteria, 'technical', 'PBR')['valuation'])->toBe(['tier' => 'cheap', 'unstable' => true]);
})->with('chg34scTakeProfitAndHold');

test('利確検討・キープ: 判定なし（tier null）・verdictキー無しのPER・PBRチップは valuation が null', function (string $method) {
    // Arrange
    $withNullTier = chg34scHealthyMetrics(['per_verdict' => chg34scVerdict(null), 'pbr_verdict' => chg34scVerdict(null)]);
    $withoutKey = chg34scHealthyMetrics();

    // Act
    $a = (new SignalCriteriaEvaluator)->{$method}($withNullTier);
    $b = (new SignalCriteriaEvaluator)->{$method}($withoutKey);

    // Assert
    foreach ([$a, $b] as $criteria) {
        foreach (['PER', 'PBR'] as $label) {
            $chip = chg34scChip($criteria, 'technical', $label);
            expect(array_key_exists('valuation', $chip))->toBeTrue();
            expect($chip['valuation'])->toBeNull();
        }
    }
})->with('chg34scTakeProfitAndHold');

test('整理検討: PER・PBRチップは valuation に加えて色を付けない目印 label_only を持つ', function () {
    // Arrange
    $metrics = chg34scHealthyMetrics([
        'per_verdict' => chg34scVerdict('strong_expensive'),
        'pbr_verdict' => chg34scVerdict('fair'),
    ]);

    // Act
    $criteria = (new SignalCriteriaEvaluator)->evaluateLossReview($metrics);

    // Assert
    $per = chg34scChip($criteria, 'technical', 'PER');
    $pbr = chg34scChip($criteria, 'technical', 'PBR');
    expect($per['valuation'])->toBe(['tier' => 'strong_expensive', 'unstable' => false]);
    expect($per['label_only'])->toBeTrue();
    expect($pbr['valuation'])->toBe(['tier' => 'fair', 'unstable' => false]);
    expect($pbr['label_only'])->toBeTrue();
});

test('整理検討: 判定なしのPER・PBRチップは valuation が null', function () {
    // Arrange
    $metrics = chg34scHealthyMetrics(['per_verdict' => chg34scVerdict(null)]);

    // Act
    $criteria = (new SignalCriteriaEvaluator)->evaluateLossReview($metrics);

    // Assert
    foreach (['PER', 'PBR'] as $label) {
        $chip = chg34scChip($criteria, 'technical', $label);
        expect(array_key_exists('valuation', $chip))->toBeTrue();
        expect($chip['valuation'])->toBeNull();
    }
});

test('買い増し候補: PBRチップだけが valuation を持ち、PERチップは従来どおり valuation キーを持たない', function () {
    // Arrange
    $metrics = chg34scHealthyMetrics([
        'per_verdict' => chg34scVerdict('strong_cheap'),
        'pbr_verdict' => chg34scVerdict('cheap'),
    ]);

    // Act
    $criteria = (new SignalCriteriaEvaluator)->evaluateBuy($metrics);

    // Assert
    expect(chg34scChip($criteria, 'technical', 'PBR')['valuation'])->toBe(['tier' => 'cheap', 'unstable' => false]);
    expect(array_key_exists('valuation', chg34scChip($criteria, 'technical', 'PER')))->toBeFalse();
});

test('買い増し候補: 判定なしのPBRチップは valuation が null', function () {
    // Arrange / Act
    $criteria = (new SignalCriteriaEvaluator)->evaluateBuy(chg34scHealthyMetrics(['pbr_verdict' => chg34scVerdict(null)]));

    // Assert
    $chip = chg34scChip($criteria, 'technical', 'PBR');
    expect(array_key_exists('valuation', $chip))->toBeTrue();
    expect($chip['valuation'])->toBeNull();
});

// --- 財務チップの strength ---

test('財務4チップは達成（met）かつ強い良好のとき strength が strong_good になる', function (string $method) {
    // Arrange
    $metrics = chg34scHealthyMetrics(['metric_tones' => chg34scAllStrongTones()]);

    // Act
    $criteria = (new SignalCriteriaEvaluator)->{$method}($metrics);

    // Assert
    foreach (['ROE', '自己資本比率', '営業利益率', '成長率'] as $label) {
        $chip = chg34scChip($criteria, 'fundamental', $label);
        expect($chip['status'])->toBe('met');
        expect($chip['strength'])->toBe('strong_good');
    }
})->with('chg34scEvaluatorsWithFundamentalStrength');

test('財務チップの strength は tone が良好・基準未満・metric_tones無しなら null（キーは存在する）', function (string $method, ?array $tones) {
    // Arrange
    $metrics = chg34scHealthyMetrics($tones === null ? [] : ['metric_tones' => $tones]);

    // Act
    $criteria = (new SignalCriteriaEvaluator)->{$method}($metrics);

    // Assert
    foreach (['ROE', '自己資本比率', '営業利益率', '成長率'] as $label) {
        $chip = chg34scChip($criteria, 'fundamental', $label);
        expect(array_key_exists('strength', $chip))->toBeTrue();
        expect($chip['strength'])->toBeNull();
    }
})->with('chg34scEvaluatorsWithFundamentalStrength')->with([
    'toneが良好' => [['roe' => 'good', 'equity_ratio' => 'good', 'operating_margin' => 'good', 'revenue_growth' => 'good', 'operating_income_growth' => 'good']],
    'toneが基準未満' => [['roe' => 'below', 'equity_ratio' => 'below', 'operating_margin' => 'below', 'revenue_growth' => 'below', 'operating_income_growth' => 'below']],
    'toneがnull' => [['roe' => null, 'equity_ratio' => null, 'operating_margin' => null, 'revenue_growth' => null, 'operating_income_growth' => null]],
    'metric_tones無し' => [null],
]);

test('財務チップの strength は未達（met以外）なら tone が strong_good でも null', function (string $method) {
    // Arrange（ROE 5.0・自己資本比率 30.0 は基準未満＝unmet/near。toneだけ strong_good を渡す）
    $metrics = chg34scHealthyMetrics([
        'roe' => 5.0,
        'equity_ratio' => 30.0,
        'metric_tones' => chg34scAllStrongTones(),
    ]);

    // Act
    $criteria = (new SignalCriteriaEvaluator)->{$method}($metrics);

    // Assert
    foreach (['ROE', '自己資本比率'] as $label) {
        $chip = chg34scChip($criteria, 'fundamental', $label);
        expect($chip['status'])->not->toBe('met');
        expect($chip['strength'])->toBeNull();
    }
    expect(chg34scChip($criteria, 'fundamental', '営業利益率')['strength'])->toBe('strong_good');
})->with('chg34scEvaluatorsWithFundamentalStrength');

test('成長率チップの strength は higherGrowthRate で選ばれた側の指標のtoneで決まる', function (?float $revenue, ?float $operatingIncome, array $tones, ?string $expected) {
    // Arrange
    $metrics = chg34scHealthyMetrics([
        'revenue_growth' => $revenue,
        'operating_income_growth' => $operatingIncome,
        'metric_tones' => $tones,
    ]);

    // Act
    $criteria = (new SignalCriteriaEvaluator)->evaluateTakeProfit($metrics);

    // Assert
    expect(chg34scChip($criteria, 'fundamental', '成長率')['strength'])->toBe($expected);
})->with([
    '売上成長率が高い側で売上のtoneがstrong_good' => [40.0, 10.0, ['revenue_growth' => 'strong_good', 'operating_income_growth' => 'good'], 'strong_good'],
    '売上成長率が高い側で営業利益のtoneだけstrong_goodなら null' => [40.0, 10.0, ['revenue_growth' => 'good', 'operating_income_growth' => 'strong_good'], null],
    '営業利益成長率が高い側で営業利益のtoneがstrong_good' => [10.0, 40.0, ['revenue_growth' => 'good', 'operating_income_growth' => 'strong_good'], 'strong_good'],
    '営業利益成長率が高い側で売上のtoneだけstrong_goodなら null' => [10.0, 40.0, ['revenue_growth' => 'strong_good', 'operating_income_growth' => 'good'], null],
    '売上成長率が取得不可なら営業利益側を使う' => [null, 40.0, ['revenue_growth' => null, 'operating_income_growth' => 'strong_good'], 'strong_good'],
    '営業利益成長率が取得不可なら売上側を使う' => [40.0, null, ['revenue_growth' => 'strong_good', 'operating_income_growth' => null], 'strong_good'],
]);

test('整理検討: 財務チップには strength を付けない（tone が strong_good でも赤の単一極性）', function () {
    // Arrange
    $metrics = chg34scHealthyMetrics(['metric_tones' => chg34scAllStrongTones()]);

    // Act
    $criteria = (new SignalCriteriaEvaluator)->evaluateLossReview($metrics);

    // Assert
    foreach (['ROE', '自己資本比率', '営業利益率', '成長率'] as $label) {
        expect(chg34scChip($criteria, 'fundamental', $label)['strength'] ?? null)->toBeNull();
    }
});

// --- 回帰ガード ---

test('verdict・toneを渡しても status・ラベル・値・集計は変わらない（全評価メソッド）', function (string $method) {
    // Arrange
    $base = chg34scHealthyMetrics(['unrealized_gain_rate' => 25.0, 'current_price' => 1000.0, 'rsi' => 50.0]);
    $extended = array_merge($base, [
        'per_verdict' => chg34scVerdict('strong_cheap'),
        'pbr_verdict' => chg34scVerdict('cheap', true),
        'metric_tones' => chg34scAllStrongTones(),
    ]);
    $strip = function (array $criteria): array {
        foreach (['technical', 'fundamental'] as $group) {
            $criteria[$group] = array_map(
                fn (array $chip) => array_diff_key($chip, array_flip(['valuation', 'label_only', 'strength'])),
                $criteria[$group],
            );
        }

        return $criteria;
    };

    // Act
    $without = (new SignalCriteriaEvaluator)->{$method}($base);
    $with = (new SignalCriteriaEvaluator)->{$method}($extended);

    // Assert
    expect($strip($with))->toBe($strip($without));
})->with([
    ['evaluateTakeProfit'],
    ['evaluateBuy'],
    ['evaluateLossReview'],
    ['evaluateHold'],
]);
