<?php

use App\Services\Analysis\FundamentalHealthEvaluator;
use App\Services\Analysis\FundamentalMetricToneEvaluator;

/*
| FundamentalMetricToneEvaluator — Red phase Unit Test (UC-002 / UC-003, CHG-0034, ADR-0023 D9)
| Expected Red: Class "App\Services\Analysis\FundamentalMetricToneEvaluator" not found.
*/

function tone(string $metric, ?float $value, string $market = 'jp', ?string $sector = null): ?string
{
    return (new FundamentalMetricToneEvaluator)->tone($metric, $value, $market, $sector);
}

dataset('toneBoundaries', [
    // ROE (JP strong 15 / US strong 25)
    'roe jp 14.9' => ['roe', 'jp', 14.9, 'good'],
    'roe jp 15.0' => ['roe', 'jp', 15.0, 'strong_good'],
    'roe jp 14.96 丸め' => ['roe', 'jp', 14.96, 'strong_good'],
    'roe us 24.9' => ['roe', 'us', 24.9, 'good'],
    'roe us 25.0' => ['roe', 'us', 25.0, 'strong_good'],
    'roe jp 10.0' => ['roe', 'jp', 10.0, 'good'],
    'roe jp 9.9' => ['roe', 'jp', 9.9, 'below'],
    'roe jp 9.96 丸め' => ['roe', 'jp', 9.96, 'good'],
    'roe jp 0.0' => ['roe', 'jp', 0.0, 'below'],
    'roe jp -0.1' => ['roe', 'jp', -0.1, 'strong_below'],
    'roe jp -0.04 丸め' => ['roe', 'jp', -0.04, 'below'],
    // equity_ratio (JP strong 70 / US none)
    'eq jp 70.0' => ['equity_ratio', 'jp', 70.0, 'strong_good'],
    'eq jp 69.9' => ['equity_ratio', 'jp', 69.9, 'good'],
    'eq us 70.0' => ['equity_ratio', 'us', 70.0, 'good'],
    'eq us 95.0' => ['equity_ratio', 'us', 95.0, 'good'],
    'eq jp 40.0' => ['equity_ratio', 'jp', 40.0, 'good'],
    'eq jp 39.9' => ['equity_ratio', 'jp', 39.9, 'below'],
    'eq jp 20.0' => ['equity_ratio', 'jp', 20.0, 'below'],
    'eq jp 19.9' => ['equity_ratio', 'jp', 19.9, 'strong_below'],
    // operating_margin (JP 20 / US 30)
    'om jp 20.0' => ['operating_margin', 'jp', 20.0, 'strong_good'],
    'om jp 19.9' => ['operating_margin', 'jp', 19.9, 'good'],
    'om us 30.0' => ['operating_margin', 'us', 30.0, 'strong_good'],
    'om us 29.9' => ['operating_margin', 'us', 29.9, 'good'],
    'om jp 10.0' => ['operating_margin', 'jp', 10.0, 'good'],
    'om jp 9.9' => ['operating_margin', 'jp', 9.9, 'below'],
    'om jp 0.0' => ['operating_margin', 'jp', 0.0, 'below'],
    'om jp -0.1' => ['operating_margin', 'jp', -0.1, 'strong_below'],
    // revenue_growth (JP 15 / US 25, good >0)
    'rg jp 15.0' => ['revenue_growth', 'jp', 15.0, 'strong_good'],
    'rg jp 14.9' => ['revenue_growth', 'jp', 14.9, 'good'],
    'rg us 25.0' => ['revenue_growth', 'us', 25.0, 'strong_good'],
    'rg us 24.9' => ['revenue_growth', 'us', 24.9, 'good'],
    'rg jp 0.1' => ['revenue_growth', 'jp', 0.1, 'good'],
    'rg jp 0.0' => ['revenue_growth', 'jp', 0.0, 'below'],
    'rg jp 0.04 丸め' => ['revenue_growth', 'jp', 0.04, 'below'],
    'rg jp -10.0' => ['revenue_growth', 'jp', -10.0, 'below'],
    'rg jp -10.1' => ['revenue_growth', 'jp', -10.1, 'strong_below'],
    // operating_income_growth (30/30, good >0, below >= -20)
    'oig jp 30.0' => ['operating_income_growth', 'jp', 30.0, 'strong_good'],
    'oig jp 29.9' => ['operating_income_growth', 'jp', 29.9, 'good'],
    'oig us 30.0' => ['operating_income_growth', 'us', 30.0, 'strong_good'],
    'oig us 29.9' => ['operating_income_growth', 'us', 29.9, 'good'],
    'oig jp 0.1' => ['operating_income_growth', 'jp', 0.1, 'good'],
    'oig jp 0.0' => ['operating_income_growth', 'jp', 0.0, 'below'],
    'oig jp -20.0' => ['operating_income_growth', 'jp', -20.0, 'below'],
    'oig jp -20.1' => ['operating_income_growth', 'jp', -20.1, 'strong_below'],
]);

test('UC-002/UC-003: 財務指標は各ラインの境界で良好・基準未満などに判定される', function (string $metric, string $market, float $value, string $expected) {
    expect(tone($metric, $value, $market))->toBe($expected);
})->with('toneBoundaries');

test('UC-002: 良好の下限はFundamentalHealthEvaluatorの定数と一致する', function () {
    expect(tone('roe', FundamentalHealthEvaluator::MIN_ROE))->toBe('good')
        ->and(tone('roe', FundamentalHealthEvaluator::MIN_ROE - 0.1))->toBe('below')
        ->and(tone('equity_ratio', FundamentalHealthEvaluator::MIN_EQUITY_RATIO))->toBe('good')
        ->and(tone('equity_ratio', FundamentalHealthEvaluator::MIN_EQUITY_RATIO - 0.1))->toBe('below')
        ->and(tone('operating_margin', FundamentalHealthEvaluator::MIN_OPERATING_MARGIN))->toBe('good')
        ->and(tone('operating_margin', FundamentalHealthEvaluator::MIN_OPERATING_MARGIN - 0.1))->toBe('below')
        ->and(tone('revenue_growth', FundamentalHealthEvaluator::MIN_GROWTH_RATE))->toBe('below')
        ->and(tone('revenue_growth', FundamentalHealthEvaluator::MIN_GROWTH_RATE + 0.1))->toBe('good');
});

test('UC-002: 値がnullなら全指標でnullを返す', function (string $metric) {
    expect(tone($metric, null))->toBeNull()
        ->and(tone($metric, null, 'us'))->toBeNull();
})->with(['roe', 'equity_ratio', 'operating_margin', 'revenue_growth', 'operating_income_growth']);

dataset('toneExclusions', [
    '銀行 equity_ratio' => ['銀行', 'equity_ratio', 50.0, null],
    '銀行 operating_margin' => ['銀行', 'operating_margin', 25.0, null],
    '銀行 roe は判定' => ['銀行', 'roe', 12.0, 'good'],
    '銀行 revenue_growth は判定' => ['銀行', 'revenue_growth', 5.0, 'good'],
    '銀行 operating_income_growth は判定' => ['銀行', 'operating_income_growth', -5.0, 'below'],
    '金融（除く銀行） equity_ratio' => ['金融（除く銀行）', 'equity_ratio', 50.0, null],
    '金融（除く銀行） operating_margin' => ['金融（除く銀行）', 'operating_margin', 25.0, null],
    '金融（除く銀行） roe は判定' => ['金融（除く銀行）', 'roe', 16.0, 'strong_good'],
    '不動産 equity_ratio' => ['不動産', 'equity_ratio', 50.0, null],
    '不動産 operating_margin は判定' => ['不動産', 'operating_margin', 12.0, 'good'],
    '不動産 roe は判定' => ['不動産', 'roe', 5.0, 'below'],
    '業種null は除外しない equity_ratio' => [null, 'equity_ratio', 50.0, 'good'],
    '業種null は除外しない operating_margin' => [null, 'operating_margin', 25.0, 'strong_good'],
    'その他 は除外しない equity_ratio' => ['その他', 'equity_ratio', 50.0, 'good'],
]);

test('UC-002: 銀行・金融は自己資本比率と営業利益率、不動産は自己資本比率を判定しない', function (?string $sector, string $metric, float $value, ?string $expected) {
    expect(tone($metric, $value, 'jp', $sector))->toBe($expected);
})->with('toneExclusions');
