<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\SignalOccurrence;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Models\User;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| UC-018 画面「売買の振り返り」 — Red phase (CHG-0033 Cycle 7e)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-018 基本フロー1〜7・異常時
|   - 2026-10-11 本人承認の画面構成: /trade-review、「売買シグナル｜シグナル検証｜
|     売買の振り返り」の3つ目のタブ、画面内は「成績」「売買一覧」の2タブ
|     （「状況別」はUC-019のGate 2承認まで出さない）。ロジックの版・事後再計算・
|     割り当ての内訳は載せない。計算結果はデータが変わるまでキャッシュする。
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - Livewire full page App\Livewire\TradeReview\TradeReview on GET
|     /trade-review (auth), title 「売買の振り返り」. #[Url] tab
|     ('performance' default | 'trades'), market ('jp' | 'us'), kind
|     ('sell' | 'buy'), year, page.
|   - 成績: the note 「国内株・米国株の株式部分（配当除く）。投信・現金は含みません」;
|     the latest 52 weeks for 合計 / 国内株 / 米国株 and each calendar year:
|     年率 / ずっと持ち続けた場合 / 差 as "%+.1f%%" / "%+.1fpt", 判定 as
|     20%未達 | 20%達成・25%未達 | 25%達成 | 判定保留 | 参考 | 算出不可. A
|     calendar year (or the latest window) that starts before the first
|     rebuilt week starts at the first week instead, so a partial year is
|     「参考」.
|   - 売買一覧: sells and buys (not tsumitate), newest first, 50 per page.
|     Per trade: 約定日, 銘柄 (code), 売却 / 買付, 金額; comparison rows
|     売却単独 / 乗換え（参考） / 比例買増し / 指数（参考） with +4週 / +13週 /
|     +26週 as "%+.1f%%", 結果待ち or 算出不可; 直前の状況: the linked
|     signal types as badges and 「保有NN%」 when held the week before, or
|     「状況不明」 when nothing was recorded.
|   - The results are cached and rebuilt when the trade history, prices,
|     splits or signal / indicator records change.
|
| Expected Red: the route, the component and the tab do not exist yet.
|
*/

function trHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

function trTrade(Holding $holding, string $kind, string $date, float $quantity, float $jpy): TradeExecution
{
    static $seq = 0;
    $batch = TradeImportBatch::query()->first()
        ?? TradeImportBatch::create(['status' => 'completed', 'jp_filename' => 'jp.csv', 'us_filename' => 'us.csv']);
    $seq++;

    return TradeExecution::create([
        'holding_id' => $holding->id, 'market' => $holding->market, 'trade_date' => $date,
        'account_type' => 'specific', 'kind' => $kind, 'is_routine' => $kind === 'tsumitate',
        'quantity' => $quantity, 'unit_price' => $jpy / $quantity,
        'price_currency' => $holding->market === 'us' ? 'usd' : 'jpy', 'settlement_currency' => 'jpy',
        'settlement_amount_jpy' => $jpy, 'fx_rate' => $holding->market === 'us' ? 100.0 : null,
        'content_hash' => str_pad((string) (100 + $seq), 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

/**
 * @param  array<string, float>  $closes
 */
function trPrices(Holding $holding, array $closes): void
{
    app(WeeklyPriceRecorder::class)->recordHolding($holding, array_map(
        fn (string $week, float $close) => ['date' => $week, 'close' => $close, 'volume' => 1],
        array_keys($closes), $closes,
    ));
}

/**
 * 53 weekly closes from 2025-09-29 to 2026-09-28: 100 every week, 121 at the end.
 */
function trYearOfCloses(): array
{
    $closes = [];
    for ($i = 0; $i <= 52; $i++) {
        $closes[Carbon::parse('2025-09-29')->addWeeks($i)->toDateString()] = $i === 52 ? 121.0 : 100.0;
    }

    return $closes;
}

function trGet(string $query = '')
{
    return test()->actingAs(User::factory()->create())->get('/trade-review'.$query);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00'); // last confirmed week 2026-09-28
});

describe('UC-018 売買の振り返り: 入口', function () {
    test('未認証ではログイン画面へ移り、認証済みなら「売買の振り返り」の画面が開く', function () {
        $this->get('/trade-review')->assertRedirect('/login');

        trGet()->assertOk()
            ->assertSee('<title>売買の振り返り | ポートフォリオ管理</title>', false)
            ->assertSeeText('成績')
            ->assertSeeText('売買一覧')
            ->assertDontSeeText('状況別');
    });

    test('売買シグナル・シグナル検証・売買の振り返りの切替タブに、3つ目として「売買の振り返り」が出る', function () {
        $html = test()->actingAs(User::factory()->create())->get('/signal-outcomes')->assertOk()->getContent();

        expect($html)->toContain('href="/trade-review"');
        expect(strpos($html, 'シグナル検証'))->toBeLessThan(strpos($html, '売買の振り返り'));
    });
});

describe('UC-018 売買の振り返り: 成績', function () {
    test('直近52週の年率・持ち続けた場合・差・判定を、合計・国内株・米国株で出し、株式部分・配当除きと注記する', function () {
        // Arrange: 10 shares bought in the base week (2025-09-29); 100 → 121 over the 52 weeks
        $a = trHolding('1111');
        trPrices($a, trYearOfCloses());
        trTrade($a, 'buy', '2025-09-30', 10, 1000);

        // Act
        $response = trGet();

        // Assert
        $response->assertSeeText('国内株・米国株の株式部分（配当除く）。投信・現金は含みません');
        $response->assertSeeText('直近52週');
        $response->assertSeeTextInOrder(['合計', '+21.0%', '+21.0%', '+0.0pt', '20%達成・25%未達']);
        $response->assertSeeTextInOrder(['国内株', '+21.0%', '+21.0%', '+0.0pt', '20%達成・25%未達']);
        $response->assertSeeTextInOrder(['米国株', '算出不可']);
    });

    test('暦年ごとにも出し、1年に満たない年（売買履歴の始まった年・今年）は判定せず「参考」にする', function () {
        // Arrange
        $a = trHolding('1111');
        trPrices($a, trYearOfCloses());
        trTrade($a, 'buy', '2025-09-30', 10, 1000);

        // Act / Assert
        trGet()->assertSeeTextInOrder(['暦年', '2025年', '参考', '2026年', '参考']);
    });
});

describe('UC-018 売買の振り返り: 売買一覧', function () {
    test('売却は+4・+13・+26週の売却単独の差を出し、まだ来ていない評価週は「結果待ち」、価格がなければ「算出不可」', function () {
        // Arrange: sold in the week of 2026-07-27: +4 = 2026-08-24 (115 → −15.0%), +13 = 2026-10-26 (to come)
        $a = trHolding('1111');
        trPrices($a, ['2026-07-27' => 100, '2026-08-24' => 115]);
        trTrade($a, 'buy', '2026-07-20', 10, 1000);
        trTrade($a, 'sell', '2026-07-28', 10, 1000);
        $b = trHolding('2222');
        trPrices($b, ['2026-07-27' => 100]); // no close at +4
        trTrade($b, 'buy', '2026-07-20', 10, 1000);
        trTrade($b, 'sell', '2026-07-28', 10, 1000);

        // Act
        $response = trGet('?tab=trades');

        // Assert
        $response->assertSeeTextInOrder(['2026-07-28', '1111', '売却', '1,000', '売却単独', '-15.0%', '結果待ち', '結果待ち']);
        $response->assertSeeTextInOrder(['2026-07-28', '2222', '売却', '売却単独', '算出不可']);
    });

    test('買付は比例買増しとの差と、指数（参考）との差を出す', function () {
        // Arrange: A held the week before; C bought; Nikkei 30,000 → 33,000
        $a = trHolding('1111');
        $c = trHolding('3333');
        trPrices($a, ['2026-07-20' => 100, '2026-07-27' => 100, '2026-08-24' => 100]);
        trPrices($c, ['2026-07-27' => 100, '2026-08-24' => 120]);
        app(WeeklyPriceRecorder::class)->recordIndex('nikkei225', [['date' => '2026-07-27', 'close' => 30000.0, 'volume' => 0], ['date' => '2026-08-24', 'close' => 33000.0, 'volume' => 0]]);
        trTrade($a, 'buy', '2026-07-14', 10, 1000);
        trTrade($c, 'buy', '2026-07-28', 10, 1000);

        // Act / Assert: +4: actual 1,200 vs proportional 1,000 (+20.0%) and vs index 1,100 (+10.0%)
        trGet('?tab=trades')->assertSeeTextInOrder(['2026-07-28', '3333', '買付', '比例買増し', '+20.0%', '指数（参考）', '+10.0%']);
    });

    test('直前の状況として、紐付いたシグナルと前週末の保有割合を出し、記録がなければ「状況不明」と出す', function () {
        // Arrange
        $a = trHolding('1111');
        $b = trHolding('2222');
        trPrices($a, ['2026-07-20' => 60, '2026-07-27' => 60]);
        trPrices($b, ['2026-07-20' => 40, '2026-07-27' => 40]);
        trTrade($a, 'buy', '2026-07-14', 10, 600);
        trTrade($b, 'buy', '2026-07-14', 10, 400);
        trTrade($a, 'sell', '2026-07-29', 1, 60);
        $signal = SignalOccurrence::create(['holding_id' => $a->id, 'source' => 'take_profit', 'signal_type' => 'week52_high_pullback', 'observed_week' => '2026-07-27', 'metrics' => []]);
        $signal->forceFill(['created_at' => '2026-07-27 01:00:00'])->save();
        trTrade($b, 'sell', '2026-07-29', 1, 40);

        // Act
        $response = trGet('?tab=trades');

        // Assert
        $response->assertSeeTextInOrder(['1111', '売却', 'week52_high_pullback', '保有60%']);
        $response->assertSeeTextInOrder(['2222', '売却', '状況不明']);
    });

    test('売却代金ですべて賄われた買付は、比較の欄に「売却代金を充当（乗換え側で評価）」と出す（2026-10-11 本人承認）', function () {
        // Arrange: A sold for 500 on Monday; C bought for 300 the same week → fully covered by the sale
        $a = trHolding('1111');
        $c = trHolding('3333');
        trTrade($a, 'buy', '2026-07-14', 10, 1000);
        trTrade($a, 'sell', '2026-07-27', 5, 500);
        trTrade($c, 'buy', '2026-07-28', 3, 300);

        // Act / Assert
        trGet('?tab=trades')->assertSeeTextInOrder(['2026-07-28', '3333', '買付', '売却代金を充当（乗換え側で評価）']);
    });

    test('前週末の保有割合が1%未満なら「保有1%未満」と出し、「保有0%」と出さない（2026-10-11 本人承認）', function () {
        // Arrange: A is 0.4% of the JP value the week before
        $a = trHolding('1111');
        $b = trHolding('2222');
        trPrices($a, ['2026-07-20' => 4, '2026-07-27' => 4]);
        trPrices($b, ['2026-07-20' => 996, '2026-07-27' => 996]);
        trTrade($a, 'buy', '2026-07-14', 1, 4);
        trTrade($b, 'buy', '2026-07-14', 1, 996);
        trTrade($a, 'sell', '2026-07-29', 1, 4);

        // Act / Assert
        trGet('?tab=trades')->assertSeeTextInOrder(['1111', '売却', '保有1%未満'])->assertDontSeeText('保有0%');
    });

    test('市場・種類・年で絞り込め、積立は一覧に出さない', function () {
        // Arrange
        $jp = trHolding('1111');
        $us = trHolding('NVDA', 'us');
        trTrade($jp, 'buy', '2025-07-01', 1, 100);
        trTrade($jp, 'sell', '2026-07-01', 1, 100);
        trTrade($us, 'buy', '2026-07-02', 1, 100);
        trTrade($us, 'tsumitate', '2026-07-03', 1, 100);

        // Act / Assert
        trGet('?tab=trades&market=us')->assertSeeText('2026-07-02')->assertDontSeeText('2026-07-01')->assertDontSeeText('2026-07-03');
        trGet('?tab=trades&kind=sell')->assertSeeText('2026-07-01')->assertDontSeeText('2026-07-02');
        trGet('?tab=trades&year=2025')->assertSeeText('2025-07-01')->assertDontSeeText('2026-07-01');
    });

    test('新しい売買から順に50件ずつ表示し、次のページに残りを出す', function () {
        // Arrange: 55 buys on consecutive days from 2026-05-01
        $a = trHolding('1111');
        for ($i = 0; $i < 55; $i++) {
            trTrade($a, 'buy', Carbon::parse('2026-05-01')->addDays($i)->toDateString(), 1, 100);
        }

        // Act / Assert: newest 2026-06-24 first; the 5 oldest (05-01..05-05) on page 2
        trGet('?tab=trades')->assertSeeTextInOrder(['2026-06-24', '2026-06-23'])->assertSeeText('2026-05-06')->assertDontSeeText('2026-05-05');
        trGet('?tab=trades&page=2')->assertSeeText('2026-05-05')->assertSeeText('2026-05-01')->assertDontSeeText('2026-05-06');
    });

    test('計算結果はキャッシュするが、売買履歴が増えれば作り直して新しい売買が出る', function () {
        // Arrange
        $a = trHolding('1111');
        trTrade($a, 'buy', '2026-07-01', 1, 100);
        trGet('?tab=trades')->assertSeeText('2026-07-01');

        // Act
        trTrade($a, 'buy', '2026-07-15', 1, 100);

        // Assert
        trGet('?tab=trades')->assertSeeText('2026-07-15');
    });
});
