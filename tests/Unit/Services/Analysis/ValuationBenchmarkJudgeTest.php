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

dataset('vbjBuyNull', [
    'low' => ['jp', '低信頼'],
    'none' => ['jp', '信頼なし'],
    '未分類' => ['jp', null],
    '表にない業種' => ['jp', '存在しない業種'],
    'その他' => ['jp', 'その他'],
]);

test('UC-010: 買い増し基準PERは信頼度low・none・未分類・表にない業種ではnullを返す', function (string $market, ?string $sector) {
    expect(vbj()->buyPerThreshold($market, $sector))->toBeNull();
})->with('vbjBuyNull');
