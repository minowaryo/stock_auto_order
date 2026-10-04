<?php

use App\Services\Analysis\ValuationBenchmarkJudge;

/*
| ValuationBenchmarkJudge — Red phase Unit Test (UC-002 / UC-003 / UC-010, CHG-0034)
| Source: ADR-0023 D3-D6, ADR-0026 D3, docs/product/valuation-benchmarks.md
| Expected Red: Class "App\Services\Analysis\ValuationBenchmarkJudge" not found.
*/

function vbjTable(): array
{
    return [
        'jp' => [
            'as_of' => '2026-09',
            'source' => 'JPX test',
            'per' => [
                '基準20' => ['value' => 20.0, 'confidence' => 'high'],
                '中信頼' => ['value' => 16.0, 'confidence' => 'medium'],
                '低信頼' => ['value' => 20.0, 'confidence' => 'low'],
                '信頼なし' => ['value' => 20.0, 'confidence' => 'none'],
                '高25' => ['value' => 25.0, 'confidence' => 'high'],
            ],
            'pbr' => [
                '基準20' => ['value' => 2.0, 'confidence' => 'high'],
                '中信頼' => ['value' => 2.0, 'confidence' => 'medium'],
            ],
        ],
        'us' => [
            'as_of' => '2026-01/2026-10',
            'source' => 'US test',
            'per' => [
                'Semiconductors' => ['value' => 44.8, 'confidence' => 'high'],
            ],
            'pbr' => [
                'Utilities' => ['value' => 2.0, 'confidence' => 'high'],
            ],
        ],
    ];
}

function vbj(): ValuationBenchmarkJudge
{
    return new ValuationBenchmarkJudge(vbjTable());
}

dataset('vbjTiers', [
    'PER 9.8 -> 0.49 strong_cheap' => [9.8, 0.49, 'strong_cheap'],
    'PER 10.0 -> 0.50 cheap' => [10.0, 0.50, 'cheap'],
    'PER 15.9 -> 0.80未満 cheap' => [15.8, 0.79, 'cheap'],
    'PER 16.0 -> 0.80 fair' => [16.0, 0.80, 'fair'],
    'PER 20.0 -> 1.00 fair' => [20.0, 1.00, 'fair'],
    'PER 25.0 -> 1.25 fair' => [25.0, 1.25, 'fair'],
    'PER 25.2 -> 1.26 expensive' => [25.2, 1.26, 'expensive'],
    'PER 40.0 -> 2.00 expensive' => [40.0, 2.00, 'expensive'],
    'PER 40.2 -> 2.01 strong_expensive' => [40.2, 2.01, 'strong_expensive'],
]);

test('UC-002: 日本株PERは基準PER比の5段階で判定され、境界は穏やかな側になる', function (float $value, float $ratio, string $tier) {
    $result = vbj()->judge('per', $value, 'jp', '基準20');

    expect($result['tier'])->toBe($tier)
        ->and($result['ratio'])->toBe($ratio)
        ->and($result['benchmark'])->toBe(20.0)
        ->and($result['confidence'])->toBe('high')
        ->and($result['unstable'])->toBeFalse()
        ->and($result['as_of'])->toBe('2026-09')
        ->and($result['source'])->toBe('JPX test')
        ->and($result['reason'])->toBeNull();
})->with('vbjTiers');

test('UC-002: 値は小数1桁に丸めてから比率を算出する', function () {
    // 15.96 -> 16.0 -> 0.80 fair (not cheap)
    $result = vbj()->judge('per', 15.96, 'jp', '基準20');

    expect($result['tier'])->toBe('fair')->and($result['ratio'])->toBe(0.80);
});

test('UC-003: 日本株PBRも業種別基準PBR比で判定される', function () {
    $result = vbj()->judge('pbr', 1.0, 'jp', '基準20');

    expect($result['tier'])->toBe('cheap')
        ->and($result['ratio'])->toBe(0.50)
        ->and($result['benchmark'])->toBe(2.0);
});

test('UC-003: 米国株PBRは基準表に載っている業種のみ判定される', function () {
    $result = vbj()->judge('pbr', 2.0, 'us', 'Utilities');

    expect($result['tier'])->toBe('fair')->and($result['as_of'])->toBe('2026-01/2026-10');
});

test('UC-003: 米国株PBRで基準表にない業種はno_benchmarkになる', function () {
    $result = vbj()->judge('pbr', 5.0, 'us', 'Semiconductors');

    expect($result['tier'])->toBeNull()->and($result['reason'])->toBe('no_benchmark');
});

test('UC-002: 米国株PERは米国の基準表で判定される', function () {
    $result = vbj()->judge('per', 44.8, 'us', 'Semiconductors');

    expect($result['tier'])->toBe('fair')->and($result['ratio'])->toBe(1.00);
});

test('UC-002: 市場を取り違えるとno_benchmarkになる', function () {
    expect(vbj()->judge('per', 20.0, 'us', '基準20')['reason'])->toBe('no_benchmark')
        ->and(vbj()->judge('per', 20.0, 'jp', 'Semiconductors')['reason'])->toBe('no_benchmark');
});

dataset('vbjMediumLowTiers', [
    'medium strong_cheap -> cheap' => ['中信頼', 4.0, 'cheap', false],
    'medium strong_expensive -> expensive' => ['中信頼', 40.0, 'expensive', false],
    'medium fair はそのまま' => ['中信頼', 16.0, 'fair', false],
    'low strong_cheap -> cheap' => ['低信頼', 4.0, 'cheap', true],
    'low strong_expensive -> expensive' => ['低信頼', 50.0, 'expensive', true],
    'low fair はそのまま' => ['低信頼', 20.0, 'fair', true],
]);

test('UC-002: 信頼度がmedium/lowのとき濃色は使わず、lowはunstableになる', function (string $sector, float $value, string $tier, bool $unstable) {
    $result = vbj()->judge('per', $value, 'jp', $sector);

    expect($result['tier'])->toBe($tier)->and($result['unstable'])->toBe($unstable);
})->with('vbjMediumLowTiers');

test('UC-002: 信頼度の値がそのまま返る', function () {
    expect(vbj()->judge('per', 16.0, 'jp', '中信頼')['confidence'])->toBe('medium')
        ->and(vbj()->judge('per', 20.0, 'jp', '低信頼')['confidence'])->toBe('low');
});

dataset('vbjNoJudgement', [
    'null値' => ['per', null, 'jp', '基準20', 'invalid_value'],
    '値0' => ['per', 0.0, 'jp', '基準20', 'invalid_value'],
    '負値' => ['per', -5.0, 'jp', '基準20', 'invalid_value'],
    '業種null' => ['per', 20.0, 'jp', null, 'sector_unclassified'],
    'その他' => ['per', 20.0, 'jp', 'その他', 'no_benchmark'],
    '表にない業種' => ['per', 20.0, 'jp', '存在しない業種', 'no_benchmark'],
    '信頼度none' => ['per', 20.0, 'jp', '信頼なし', 'confidence_none'],
]);

test('UC-002: 判定できない場合はtierがnullで理由が返る', function (string $metric, ?float $value, string $market, ?string $sector, string $reason) {
    $result = vbj()->judge($metric, $value, $market, $sector);

    expect($result['tier'])->toBeNull()->and($result['reason'])->toBe($reason);
})->with('vbjNoJudgement');

test('UC-010: 買い増し基準PERは信頼度highなら基準PERの0.8倍(小数1桁)になる', function () {
    expect(vbj()->buyPerThreshold('jp', '高25'))->toBe(20.0);
});

test('UC-010: 買い増し基準PERは信頼度mediumでも0.8倍になる', function () {
    expect(vbj()->buyPerThreshold('jp', '中信頼'))->toBe(12.8);
});

test('UC-010: 買い増し基準PERは米国株highでも0.8倍(小数1桁)になる', function () {
    expect(vbj()->buyPerThreshold('us', 'Semiconductors'))->toBe(35.8);
});

// CHG-0048（ADR-0026 D3、2026-10-04改訂）: 信頼度lowの業種は null ではなく基準PERの0.7倍を返す。
// 旧: low → null ／ 新: low → 0.7×基準（下の「信頼度lowは0.7倍」のテスト）。
test('UC-010: 買い増し基準PERは信頼度lowなら基準PERの0.7倍(小数1桁)になる', function () {
    expect(vbj()->buyPerThreshold('jp', '低信頼'))->toBe(14.0);
});

dataset('vbjBuyNull', [
    'none' => ['jp', '信頼なし'],
    '未分類' => ['jp', null],
    '表にない業種' => ['jp', '存在しない業種'],
    'その他' => ['jp', 'その他'],
]);

test('UC-010: 買い増し基準PERは信頼度none・未分類・表にない業種ではnullを返す', function (string $market, ?string $sector) {
    expect(vbj()->buyPerThreshold($market, $sector))->toBeNull();
})->with('vbjBuyNull');

/*
|--------------------------------------------------------------------------
| CHG-0048 サイクル4: buyPerVerdict（買い増し判定のPER条件、ADR-0026 D3 2026-10-04改訂）— Red phase
|--------------------------------------------------------------------------
| 戻り値: ['met' => bool, 'basis' => 'sector'|'fixed', 'threshold' => float, 'benchmark' => ?float, 'factor' => ?float]
|   high/medium: 比率（round(PER,1)÷基準、小数2桁）< 0.80、threshold=round(0.80×基準,1)
|   low:         比率 < 0.70、threshold=round(0.70×基準,1)
|   none・未分類・表にない業種: 従来どおり PER≦15.0（basis 'fixed'）
|   PER null・0以下: met false
| Expected Red: Call to undefined method ValuationBenchmarkJudge::buyPerVerdict().
*/

dataset('vbjBuyVerdictMet', [
    'high 基準20 PER15.8 -> 0.79 成立' => ['基準20', 15.8, true],
    'high 基準20 PER16.0 -> 0.80 不成立' => ['基準20', 16.0, false],
    'high 基準20 PER10.0 -> 0.50 成立' => ['基準20', 10.0, true],
    'medium 基準16 PER12.5 -> 0.78 成立' => ['中信頼', 12.5, true],
    'medium 基準16 PER12.8 -> 0.80 不成立' => ['中信頼', 12.8, false],
    'low 基準20 PER13.8 -> 0.69 成立' => ['低信頼', 13.8, true],
    'low 基準20 PER14.0 -> 0.70 不成立' => ['低信頼', 14.0, false],
    'low 基準20 PER15.8 -> 0.79 (0.80未満でも0.70以上は)不成立' => ['低信頼', 15.8, false],
]);

test('UC-010: 業種の基準PERがある業種では、比率が信頼度に応じた倍率未満のとき買い増しのPER条件を満たす', function (string $sector, float $per, bool $met) {
    expect(vbj()->buyPerVerdict($per, 'jp', $sector)['met'])->toBe($met);
})->with('vbjBuyVerdictMet');

test('UC-010: 信頼度highの業種は basis sector・倍率0.8・基準PER・0.8倍の閾値を返す', function () {
    $verdict = vbj()->buyPerVerdict(15.8, 'jp', '基準20');

    expect($verdict['basis'])->toBe('sector')
        ->and($verdict['factor'])->toBe(0.8)
        ->and($verdict['benchmark'])->toBe(20.0)
        ->and($verdict['threshold'])->toBe(16.0)
        ->and($verdict['met'])->toBeTrue();
});

test('UC-010: 信頼度mediumの業種も倍率0.8で判定する', function () {
    $verdict = vbj()->buyPerVerdict(12.5, 'jp', '中信頼');

    expect($verdict['basis'])->toBe('sector')
        ->and($verdict['factor'])->toBe(0.8)
        ->and($verdict['benchmark'])->toBe(16.0)
        ->and($verdict['threshold'])->toBe(12.8);
});

test('UC-010: 信頼度lowの業種は固定の15ではなく、より厳しい倍率0.7で判定する', function () {
    $verdict = vbj()->buyPerVerdict(13.8, 'jp', '低信頼');

    expect($verdict['basis'])->toBe('sector')
        ->and($verdict['factor'])->toBe(0.7)
        ->and($verdict['benchmark'])->toBe(20.0)
        ->and($verdict['threshold'])->toBe(14.0)
        ->and($verdict['met'])->toBeTrue();
});

test('UC-010: 米国株も基準表にある業種は同じ規則（Semiconductors high、基準44.8）で判定する', function () {
    // 35.0 / 44.8 = 0.78125 -> 0.78（成立）、threshold = round(35.84, 1) = 35.8
    $verdict = vbj()->buyPerVerdict(35.0, 'us', 'Semiconductors');

    expect($verdict['met'])->toBeTrue()
        ->and($verdict['basis'])->toBe('sector')
        ->and($verdict['threshold'])->toBe(35.8)
        ->and($verdict['benchmark'])->toBe(44.8);
});

test('UC-010: PERは小数1桁に丸めてから比率を算出する（15.84 -> 15.8 -> 0.79 成立、15.96 -> 16.0 -> 0.80 不成立）', function () {
    expect(vbj()->buyPerVerdict(15.84, 'jp', '基準20')['met'])->toBeTrue()
        ->and(vbj()->buyPerVerdict(15.96, 'jp', '基準20')['met'])->toBeFalse();
});

dataset('vbjBuyVerdictFixed', [
    '信頼度none' => ['jp', '信頼なし'],
    '業種未分類' => ['jp', null],
    '表にない業種' => ['jp', '存在しない業種'],
    '市場の取り違え（米国にない業種）' => ['us', '基準20'],
]);

test('UC-010: 信頼度none・業種未分類・基準表にない業種は従来どおりPER≦15.0で判定する（basis fixed）', function (string $market, ?string $sector) {
    $met = vbj()->buyPerVerdict(15.0, $market, $sector);
    $unmet = vbj()->buyPerVerdict(15.1, $market, $sector);

    expect($met['met'])->toBeTrue()
        ->and($met['basis'])->toBe('fixed')
        ->and($met['threshold'])->toBe(15.0)
        ->and($met['benchmark'])->toBeNull()
        ->and($met['factor'])->toBeNull()
        ->and($unmet['met'])->toBeFalse()
        ->and($unmet['basis'])->toBe('fixed');
})->with('vbjBuyVerdictFixed');

dataset('vbjBuyVerdictInvalidPer', [
    'high PER null' => [null, '基準20', 'sector'],
    'high PER 0' => [0.0, '基準20', 'sector'],
    'high PER 負' => [-8.0, '基準20', 'sector'],
    'low PER 負' => [-8.0, '低信頼', 'sector'],
    '未分類 PER null' => [null, null, 'fixed'],
    '未分類 PER 0' => [0.0, null, 'fixed'],
    '未分類 PER 負' => [-8.0, null, 'fixed'],
]);

test('UC-010: PERがnull・0以下（赤字）は買い増しのPER条件を満たさない', function (?float $per, ?string $sector, string $basis) {
    $verdict = vbj()->buyPerVerdict($per, 'jp', $sector);

    expect($verdict['met'])->toBeFalse()->and($verdict['basis'])->toBe($basis);
})->with('vbjBuyVerdictInvalidPer');

test('UC-003: 基準値が null の対象外業種（信頼度none）は、判定なしの benchmark を 0.0 にせず null で返す', function () {
    // Arrange: the real config lists US Automobiles etc. as value null / confidence none.
    $judge = new ValuationBenchmarkJudge([
        'us' => [
            'as_of' => '2026-01',
            'source' => 'test',
            'per' => ['Automobiles' => ['value' => null, 'confidence' => 'none']],
            'pbr' => [],
        ],
    ]);

    // Act
    $result = $judge->judge('per', 30.0, 'us', 'Automobiles');

    // Assert
    expect($result['tier'])->toBeNull()
        ->and($result['reason'])->toBe('confidence_none')
        ->and($result['benchmark'])->toBeNull();
});

test('UC-003: 判定なしでも基準値は表の桁のまま返す（PBR 1.86 を 1.9 に丸めない）', function () {
    // Arrange
    $judge = new ValuationBenchmarkJudge([
        'jp' => [
            'as_of' => '2026-09',
            'source' => 'test',
            'per' => [],
            'pbr' => ['対象外' => ['value' => 1.86, 'confidence' => 'none']],
        ],
    ]);

    // Act
    $result = $judge->judge('pbr', 1.0, 'jp', '対象外');

    // Assert
    expect($result['reason'])->toBe('confidence_none')
        ->and($result['benchmark'])->toBe(1.86);
});
