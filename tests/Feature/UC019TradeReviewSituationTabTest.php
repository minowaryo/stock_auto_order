<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\IndicatorObservation;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Models\User;
use App\Services\MarketData\WeekDateNormalizer;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use App\Services\TradeReview\SwitchAllocator;
use App\Services\TradeReview\TradeComparisonCalculator;
use App\Services\TradeReview\TradeContextFinder;
use App\Services\TradeReview\WeeklyPortfolioBuilder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| UC-019 画面「売買の振り返り」の「状況別」タブ — Red phase (CHG-0033 Cycle B3)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-019（2026-10-19 Gate 2承認）基本フロー1〜4・
|     状態変更・権限: 出所（当時記録／事後再計算〔価格系〕）、対象件数（定型は
|     除外件数として別表示）、比較可能・結果待ち・算出不可の件数、状況・市場・
|     評価期間ごとの件数・中央値・分布と個別売買へのリンク、クラスタ単位、
|     「探索的結果」と明記し成功法則・売買ルールを表示しない、事後再計算は売買週の
|     前週末までの株価だけ・業績指標は再計算しない、本人のみ閲覧
|   - UC-018 業務ルール: 定型は状況別集計から外す、+4／+13／+26週を合計しない
|   - Cycle B3 本人承認済み設計（2026-10-19）: 「状況別」は3つ目の内タブ、表のみ・
|     グラフなし、入力欄は増やさない、データは保存しない
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - GET /trade-review?tab=situations opens the tab. The inner nav shows
|     links /trade-review?tab=performance | trades | situations in that
|     order (成績 / 売買一覧 / 状況別); the current one has aria-current="page".
|   - The numbers are part of ShowTradeReviewAction's one cache entry
|     ('trade-review'): report['situations'] holds plain arrays / scalars only
|     (the database cache store unserializes with allowed_classes = false,
|     config/cache.php serializable_classes). TradeContextFinder::find,
|     TradeComparisonCalculator::sells / switches / buys and
|     WeeklyPortfolioBuilder::build run once per rebuild, shared with UC-018.
|   - Notes: 「探索的結果」, 「成功法則・売買ルールを示すものではありません」,
|     「売買週の前週末までの株価から計算」, 「業績指標は使いません」.
|   - Summary (whitespace-insensitive): 「対象の売買 N件」 = classified
|     (non-routine buy / sell) trades, 「定型の除外 N件」 =
|     SituationAggregation::excludedRoutine, 「当時記録 N件」/「事後再計算 N件」
|     = the classified trades by TradeSituation::source.
|   - #[Url] filters: axis = high_ratio (default) | relative_strength |
|     holding; type = sell (default) | switch | buy; horizon = 26 (default) |
|     4 | 13. Any other value falls back to the default. Shown as <select>s
|     with aria-label 軸 / 比較の種類 / 評価期間 and options 52週高値比 /
|     相対強度 / 保有状況, 売却単独 / 推定乗換え（参考） / 買付, +4週 / +13週 /
|     +26週.
|   - Table rows: one <tr> per market × band that has at least one trade
|     (SituationCell), 国内株 before 米国株, bands in the order listed below.
|     Each row's cells, in order: 市場, 区分, クラスタ数, 結果あり, 結果待ち,
|     算出不可, 対ガチホ差の中央値, 25%〜75%点, 上回ったクラスタ数, 当時記録の件数,
|     and a 「売買を見る」 control. Counts are plain integers; rates are
|     "%+.1f%%" (as in 売買一覧); the range is "{p25}〜{p75}"; with no 結果あり
|     the median and the range are 「—」.
|   - Band labels: 高値圏（52週高値の85%以上） / 押し目圏（85%未満） / 不明;
|     強い（対市場−5pt以上） / 弱い（−5pt未満） / 不明; 未保有 / 保有1%未満 /
|     保有1〜5% / 保有5%超. The internal codes are never shown as text.
|   - 「売買を見る」 selects the row via #[Url] cell = "{market}-{band}"
|     (e.g. jp-near_high); the row's control carries that key. The selected
|     cell's trades are listed below the table, one <tr> each, in
|     SituationCell::tradeIds order: 約定日, 銘柄コード, 銘柄名, and the diff of
|     the selected type and horizon as "%+.1f%%" or 結果待ち / 算出不可.
|     Without a (valid) cell nothing is listed.
|   - No trades at all → the same empty message as the other tabs.
|
| Expected Red: the tab, the filters, the table and report['situations'] do
| not exist yet (today ?tab=situations renders the 売買一覧 content: the view
| sees the public $tab, not the 'tab' the render() passes).
|
*/

const SIT_JP_HIGH = '高値圏（52週高値の85%以上）';
const SIT_JP_PULLBACK = '押し目圏（85%未満）';

function sitHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

function sitTrade(Holding $holding, string $kind, string $date, float $quantity = 10, float $jpy = 1000): TradeExecution
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
        'content_hash' => str_pad((string) (9000 + $seq), 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

/**
 * @param  array<string, float>  $closes  week => close
 */
function sitPrices(Holding $holding, array $closes): void
{
    ksort($closes);
    app(WeeklyPriceRecorder::class)->recordHolding($holding, array_map(
        fn (string $week, float $close) => ['date' => $week, 'close' => $close, 'volume' => 1],
        array_keys($closes), array_values($closes),
    ));
}

/**
 * An indicator observation recorded at the time (UTC), so the trade's
 * situation comes from the record (UC-019 出所「当時記録」).
 */
function sitObserve(Holding $holding, string $observedAtUtc, float $close, float $high, float $strength): void
{
    IndicatorObservation::create([
        'holding_id' => $holding->id, 'source' => 'holding_import', 'observed_at' => $observedAtUtc,
        'metrics' => ['close' => $close, 'week52_high' => $high, 'relative_strength_vs_market' => $strength],
    ]);
}

function sitGet(string $query = '')
{
    return test()->actingAs(User::factory()->create())->get('/trade-review?tab=situations'.$query);
}

/**
 * Every <tr> with <td>s: its cell texts joined by one space, and its HTML.
 *
 * @return list<array{text: string, html: string}>
 */
function sitRows(string $html): array
{
    $doc = new DOMDocument;
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    $rows = [];

    foreach ((new DOMXPath($doc))->query('//tr[td]') as $tr) {
        /** @var DOMElement $tr */
        $cells = [];

        foreach ($tr->childNodes as $cell) {
            if ($cell instanceof DOMElement && in_array($cell->nodeName, ['td', 'th'], true)) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
            }
        }

        $rows[] = ['text' => trim(preg_replace('/\s+/u', ' ', implode(' ', $cells))), 'html' => $doc->saveHTML($tr)];
    }

    return $rows;
}

/**
 * The rows of the situation table (they start with the market label).
 *
 * @return list<array{text: string, html: string}>
 */
function sitTableRows(string $html): array
{
    return array_values(array_filter(sitRows($html), fn (array $r) => preg_match('/^(国内株|米国株) /u', $r['text']) === 1));
}

/**
 * The text of the situation table row of $market and $band (fails if absent).
 */
function sitRow(string $html, string $market, string $band): string
{
    foreach (sitTableRows($html) as $row) {
        if (str_starts_with($row['text'], $market.' '.$band.' ')) {
            return $row['text'];
        }
    }

    throw new \RuntimeException("No row for {$market} {$band}");
}

/**
 * The listed trades of the selected cell (rows that start with a date).
 *
 * @return list<string>
 */
function sitDrillRows(string $html): array
{
    return array_values(array_map(
        fn (array $r) => $r['text'],
        array_filter(sitRows($html), fn (array $r) => preg_match('/^\d{4}-\d{2}-\d{2} /', $r['text']) === 1),
    ));
}

/**
 * The page text with tags stripped and all whitespace removed.
 */
function sitCompactText(string $html): string
{
    return preg_replace('/\s+/u', '', strip_tags($html));
}

/**
 * Eight recorded jp / us sells (week 2026-03-02 .. 2026-07-27) — every
 * comparison is a sell (no buys, so no switch and no buy comparison):
 *
 *   trade (created in this order)  band (high / strength)  +4週    +26週
 *   A1 1111 2026-03-05             near_high / strong      −8.0%   −10.0%
 *   A2 1111 2026-03-06             near_high / strong      −8.0%   −10.0%  (same cluster as A1)
 *   C  3333 2026-03-12             near_high / strong      +12.0%  +20.0%
 *   G  7777 2026-03-19             near_high / weak        +0.0%   +40.0%
 *   D  4444 2026-07-28             near_high / strong      +4.0%   結果待ち
 *   E  5555 2026-03-05             near_high / strong      算出不可 算出不可 (no closes)
 *   P  6666 2026-03-05             pullback / weak         +0.0%   −20.0%
 *   U  NVDA (us) 2026-03-05        near_high / strong      算出不可 −10.0%
 *
 * A sell's diff = (proceeds 1,000 − 10 shares × the close then) ÷ 1,000.
 * None was held before, so all are 未保有.
 */
function sitMainScenario(): void
{
    $a = sitHolding('1111');
    $c = sitHolding('3333');
    $g = sitHolding('7777');
    $d = sitHolding('4444');
    $e = sitHolding('5555');
    $p = sitHolding('6666');
    $u = sitHolding('NVDA', 'us');

    sitObserve($a, '2026-03-03 01:00:00', 90, 100, 0.0);
    sitObserve($c, '2026-03-10 01:00:00', 95, 100, 0.0);
    sitObserve($g, '2026-03-17 01:00:00', 100, 100, -8.0);
    sitObserve($d, '2026-07-26 01:00:00', 90, 100, 0.0);
    sitObserve($e, '2026-03-03 01:00:00', 90, 100, 0.0);
    sitObserve($p, '2026-03-03 01:00:00', 50, 100, -10.0);
    sitObserve($u, '2026-03-03 01:00:00', 90, 100, 0.0);

    sitPrices($a, ['2026-03-30' => 108.0, '2026-08-31' => 110.0]);
    sitPrices($c, ['2026-04-06' => 88.0, '2026-09-07' => 80.0]);
    sitPrices($g, ['2026-04-13' => 100.0, '2026-09-14' => 60.0]);
    sitPrices($d, ['2026-08-24' => 96.0]);
    sitPrices($p, ['2026-03-30' => 100.0, '2026-08-31' => 120.0]);
    sitPrices($u, ['2026-08-31' => 1.1]);
    app(WeeklyPriceRecorder::class)->recordIndex('usdjpy', [['date' => '2026-08-31', 'close' => 100.0, 'volume' => 0]]);

    sitTrade($a, 'sell', '2026-03-05');
    sitTrade($a, 'sell', '2026-03-06');
    sitTrade($c, 'sell', '2026-03-12');
    sitTrade($g, 'sell', '2026-03-19');
    sitTrade($d, 'sell', '2026-07-28');
    sitTrade($e, 'sell', '2026-03-05');
    sitTrade($p, 'sell', '2026-03-05');
    sitTrade($u, 'sell', '2026-03-05');
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00'); // last confirmed week 2026-09-28
});

describe('UC-019 状況別: 入口', function () {
    test('未認証で状況別タブを開くとログイン画面へ移る', function () {
        $this->get('/trade-review?tab=situations')->assertRedirect('/login');
    });

    test('「成績」「売買一覧」の横に「状況別」タブのリンクが出て、?tab=situations で状況別タブが開く', function () {
        // Arrange
        sitMainScenario();

        // Act
        $html = sitGet()->assertOk()->getContent();
        $default = test()->actingAs(User::factory()->create())->get('/trade-review')->assertOk()->getContent();

        // Assert: the three inner tabs in order, 状況別 current
        $performance = strpos($html, 'href="/trade-review?tab=performance"');
        $trades = strpos($html, 'href="/trade-review?tab=trades"');
        $situations = strpos($html, 'href="/trade-review?tab=situations"');
        expect([$performance !== false, $trades !== false, $situations !== false])->toBe([true, true, true]);
        expect($performance)->toBeLessThan($trades);
        expect($trades)->toBeLessThan($situations);
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $current = (new DOMXPath($doc))->query('//a[@aria-current="page" and contains(@href, "tab=")]');
        expect($current->length)->toBe(1);
        expect(trim($current->item(0)->textContent))->toBe('状況別');

        // Assert: the 状況別 content replaces the 成績 content, and the default tab stays 成績 (control)
        expect(sitCompactText($html))->toContain('探索的結果')->not->toContain('投信・現金は含みません');
        expect(sitCompactText($default))->toContain('投信・現金は含みません')->not->toContain('探索的結果');
        expect(sitCompactText($default))->toContain('状況別');
    });

    test('不明なタブ（?tab=foo）を開くと「成績」タブの内容を出し、「売買一覧」の内容は出さない', function () {
        // Regression: the view used to read the raw public $tab instead of the normalized one,
        // so an unknown tab fell through to the 売買一覧 branch.

        // Arrange
        sitMainScenario();
        $user = User::factory()->create();

        // Act
        $unknown = test()->actingAs($user)->get('/trade-review?tab=foo')->assertOk()->getContent();
        $trades = test()->actingAs($user)->get('/trade-review?tab=trades')->assertOk()->getContent();

        // Assert: the 売買一覧 markers do show on ?tab=trades (control)
        expect($trades)->toContain('aria-label="市場"');
        expect(sitCompactText($trades))->toContain('直前の状況')->not->toContain('投信・現金は含みません');

        // Assert: an unknown tab shows the 成績 content and none of the 売買一覧 content
        expect(sitCompactText($unknown))->toContain('投信・現金は含みません')->not->toContain('直前の状況');
        expect($unknown)->not->toContain('aria-label="市場"');
    });

    test('売買が1件もなければ、状況別タブでも他のタブと同じ空表示を出して落ちない', function () {
        sitGet()->assertOk()
            ->assertSeeText('売買履歴がまだありません。売買履歴CSVを取り込むと表示されます。')
            ->assertDontSeeText('探索的結果');
    });
});

describe('UC-019 状況別: 注記と対象件数', function () {
    test('「探索的結果」であり成功法則・売買ルールを示すものではない旨と、事後再計算の範囲を注記する', function () {
        // Arrange
        sitMainScenario();

        // Act
        $text = sitCompactText(sitGet()->assertOk()->getContent());

        // Assert
        expect($text)
            ->toContain('探索的結果')
            ->toContain('成功法則・売買ルールを示すものではありません')
            ->toContain('売買週の前週末までの株価から計算')
            ->toContain('業績指標は使いません');
    });

    test('対象の売買件数・定型の除外件数・出所の内訳（当時記録／事後再計算）を出す', function () {
        // Arrange: A's sell recorded at the time; B and C sells and D's buy without a record
        // (recomputed); two 積立 on A (routine, excluded)
        $a = sitHolding('1111');
        $b = sitHolding('2222');
        $c = sitHolding('3333');
        $d = sitHolding('4444');
        sitObserve($a, '2026-03-03 01:00:00', 90, 100, 0.0);
        sitTrade($a, 'tsumitate', '2026-01-06', 1, 100);
        sitTrade($a, 'tsumitate', '2026-02-03', 1, 100);
        sitTrade($a, 'sell', '2026-03-05');
        sitTrade($b, 'sell', '2026-03-05');
        sitTrade($c, 'sell', '2026-03-12');
        sitTrade($d, 'buy', '2026-03-19');

        // Act
        $text = sitCompactText(sitGet()->assertOk()->getContent());

        // Assert
        expect($text)
            ->toContain('対象の売買4件')
            ->toContain('定型の除外2件')
            ->toContain('当時記録1件')
            ->toContain('事後再計算3件');
    });
});

describe('UC-019 状況別: 表', function () {
    test('既定は52週高値比・売却単独・+26週で、市場×区分ごとにクラスタ数・結果の内訳・中央値・25%〜75%点・上回ったクラスタ数・当時記録の件数を出す', function () {
        // Arrange
        sitMainScenario();

        // Act
        $html = sitGet()->assertOk()->getContent();

        // Assert: jp near_high = clusters A(−10), C(+20), G(+40), D(待ち), E(不可);
        // median of −10 / +20 / +40 = +20, p25 = +5, p75 = +30; 2 of them > 0; 6 recorded trades
        expect(array_map(fn (array $r) => $r['text'], sitTableRows($html)))->toHaveCount(3);
        expect(sitTableRows($html)[0]['text'])->toStartWith('国内株 '.SIT_JP_HIGH.' 5 3 1 1 +20.0% +5.0%〜+30.0% 2 6');
        expect(sitTableRows($html)[1]['text'])->toStartWith('国内株 '.SIT_JP_PULLBACK.' 1 1 0 0 -20.0% -20.0%〜-20.0% 0 1');
        expect(sitTableRows($html)[2]['text'])->toStartWith('米国株 '.SIT_JP_HIGH.' 1 1 0 0 -10.0% -10.0%〜-10.0% 0 1');
    });

    test('評価期間を+4週に切り替えると+4週の結果で集計し直し、+26週の結果と合算しない', function () {
        // Arrange
        sitMainScenario();

        // Act
        $html = sitGet('&horizon=4')->assertOk()->getContent();

        // Assert: A(−8), C(+12), G(+0), D(+4), E(不可) → median of −8 / 0 / 4 / 12 = +2, p25 −2, p75 +6;
        // 0.0 is not "better" → 2
        expect(sitRow($html, '国内株', SIT_JP_HIGH))->toStartWith('国内株 '.SIT_JP_HIGH.' 5 4 0 1 +2.0% -2.0%〜+6.0% 2 6');
    });

    test('軸を相対強度に切り替えると、強い（対市場−5pt以上）／弱い（−5pt未満）で集計する', function () {
        // Arrange
        sitMainScenario();

        // Act
        $html = sitGet('&axis=relative_strength')->assertOk()->getContent();

        // Assert: strong jp = A(−10), C(+20), D(待ち), E(不可); weak jp = G(+40), P(−20)
        expect(sitRow($html, '国内株', '強い（対市場−5pt以上）'))->toStartWith('国内株 強い（対市場−5pt以上） 4 2 1 1 +5.0% -2.5%〜+12.5% 1 5');
        expect(sitRow($html, '国内株', '弱い（−5pt未満）'))->toStartWith('国内株 弱い（−5pt未満） 2 2 0 0 +10.0% -5.0%〜+25.0% 1 2');
        expect(sitRow($html, '米国株', '強い（対市場−5pt以上）'))->toStartWith('米国株 強い（対市場−5pt以上） 1 1 0 0 -10.0% -10.0%〜-10.0% 0 1');
    });

    test('軸を保有状況に切り替えると、売買前週末の保有状況で集計する（前週末に持っていなければ未保有）', function () {
        // Arrange
        sitMainScenario();

        // Act
        $html = sitGet('&axis=holding')->assertOk()->getContent();

        // Assert: every sell is 未保有; jp = A(−10), C(+20), G(+40), P(−20), D(待ち), E(不可)
        expect(array_map(fn (array $r) => $r['text'], sitTableRows($html)))->toHaveCount(2);
        expect(sitRow($html, '国内株', '未保有'))->toStartWith('国内株 未保有 6 4 1 1 +5.0% -12.5%〜+25.0% 2 7');
        expect(sitRow($html, '米国株', '未保有'))->toStartWith('米国株 未保有 1 1 0 0 -10.0% -10.0%〜-10.0% 0 1');
    });

    test('保有状況は保有1%未満・保有1〜5%・保有5%超・未保有に分け、結果ありがなければ中央値と分布は「—」にする', function () {
        // Arrange: the week before the sells (2026-07-20) X = 4 (0.4%), Y = 30 (3%), Z = 966 (96.6%);
        // W was not held. All +26週 evaluations are still to come.
        $x = sitHolding('1111');
        $y = sitHolding('2222');
        $z = sitHolding('3333');
        $w = sitHolding('4444');
        sitPrices($x, ['2026-07-20' => 4.0, '2026-07-27' => 4.0]);
        sitPrices($y, ['2026-07-20' => 30.0, '2026-07-27' => 30.0]);
        sitPrices($z, ['2026-07-20' => 966.0, '2026-07-27' => 966.0]);
        sitTrade($x, 'buy', '2026-07-14', 1, 4);
        sitTrade($y, 'buy', '2026-07-14', 1, 30);
        sitTrade($z, 'buy', '2026-07-14', 1, 966);
        sitTrade($x, 'sell', '2026-07-29', 1, 4);
        sitTrade($y, 'sell', '2026-07-29', 1, 30);
        sitTrade($z, 'sell', '2026-07-29', 1, 966);
        sitTrade($w, 'sell', '2026-07-29', 1, 50);

        // Act
        $html = sitGet('&axis=holding')->assertOk()->getContent();

        // Assert
        expect(array_map(fn (array $r) => $r['text'], sitTableRows($html)))->toHaveCount(4);
        expect(sitTableRows($html)[0]['text'])->toStartWith('国内株 未保有 1 0 1 0 — — 0 0');
        expect(sitTableRows($html)[1]['text'])->toStartWith('国内株 保有1%未満 1 0 1 0 — — 0 0');
        expect(sitTableRows($html)[2]['text'])->toStartWith('国内株 保有1〜5% 1 0 1 0 — — 0 0');
        expect(sitTableRows($html)[3]['text'])->toStartWith('国内株 保有5%超 1 0 1 0 — — 0 0');
    });

    test('比較の種類を推定乗換え（参考）にすると乗換えの対ガチホ差で集計し、売却単独の結果と混ぜない', function () {
        // Arrange: A sold (recorded near_high) and its proceeds went into B 2 business days later.
        // +26週 (2026-08-31): A 110, B 130 → 売却単独 (1,000 − 1,100) / 1,000 = −10%,
        // 乗換え (1,300 − 1,100) / 1,000 = +20%
        $a = sitHolding('1111');
        $b = sitHolding('2222');
        sitObserve($a, '2026-03-03 01:00:00', 90, 100, 0.0);
        sitPrices($a, ['2026-08-31' => 110.0]);
        sitPrices($b, ['2026-08-31' => 130.0]);
        sitTrade($a, 'sell', '2026-03-05');
        sitTrade($b, 'buy', '2026-03-09');

        // Act
        $switch = sitGet('&type=switch')->assertOk()->getContent();
        $sell = sitGet()->assertOk()->getContent();

        // Assert
        expect(sitRow($switch, '国内株', SIT_JP_HIGH))->toStartWith('国内株 '.SIT_JP_HIGH.' 1 1 0 0 +20.0% +20.0%〜+20.0% 1 1');
        expect(sitRow($sell, '国内株', SIT_JP_HIGH))->toStartWith('国内株 '.SIT_JP_HIGH.' 1 1 0 0 -10.0% -10.0%〜-10.0% 0 1');
    });

    test('比較の種類を買付にすると比例買増しとの差で集計し、状況が求まらなければ「不明」の行に入れる', function () {
        // Arrange (as in UC-018): A held the week before; C bought 2026-07-28; at +4週 C 120 vs
        // proportional 1,000 → +20%. A's own buy (2026-07-14) has no close at +4 → 算出不可.
        // Neither has 52 weeks of closes or a record → 52週高値比 不明.
        $a = sitHolding('1111');
        $c = sitHolding('3333');
        sitPrices($a, ['2026-07-20' => 100.0, '2026-07-27' => 100.0, '2026-08-24' => 100.0]);
        sitPrices($c, ['2026-07-27' => 100.0, '2026-08-24' => 120.0]);
        sitTrade($a, 'buy', '2026-07-14');
        sitTrade($c, 'buy', '2026-07-28');

        // Act
        $html = sitGet('&type=buy&horizon=4')->assertOk()->getContent();

        // Assert
        expect(array_map(fn (array $r) => $r['text'], sitTableRows($html)))->toHaveCount(1);
        expect(sitRow($html, '国内株', '不明'))->toStartWith('国内株 不明 2 1 0 1 +20.0% +20.0%〜+20.0% 1 0');
    });

    test('軸・比較の種類・評価期間の切替を出し、不正な値は既定（52週高値比・売却単独・+26週）に戻す', function () {
        // Arrange
        sitMainScenario();

        // Act
        $html = sitGet('&axis=foo&type=bar&horizon=5')->assertOk()->getContent();

        // Assert: the filters
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);
        $options = fn (string $label) => array_map(fn ($o) => trim($o->textContent), iterator_to_array($xpath->query('//select[@aria-label="'.$label.'"]/option')));
        expect($options('軸'))->toBe(['52週高値比', '相対強度', '保有状況']);
        expect($options('比較の種類'))->toBe(['売却単独', '推定乗換え（参考）', '買付']);
        expect($options('評価期間'))->toBe(['+4週', '+13週', '+26週']);

        // Assert: the defaults' numbers (same as the default view)
        expect(sitRow($html, '国内株', SIT_JP_HIGH))->toStartWith('国内株 '.SIT_JP_HIGH.' 5 3 1 1 +20.0% +5.0%〜+30.0% 2 6');
    });

    test('区分は日本語で表示し、内部の区分コードを画面に出さない', function () {
        // Arrange
        sitMainScenario();

        // Act / Assert: the Japanese labels are shown (control) and the codes are not, on every axis
        foreach (['' => SIT_JP_HIGH, '&axis=relative_strength' => '強い（対市場−5pt以上）', '&axis=holding' => '未保有'] as $query => $label) {
            $response = sitGet($query)->assertOk();
            expect(sitCompactText($response->getContent()))->toContain(preg_replace('/\s+/u', '', $label));

            foreach (['near_high', 'pullback', 'unknown', 'strong', 'weak', 'lt1', '1to5', 'gt5', 'high_ratio', 'relative_strength', 'recorded', 'recomputed'] as $code) {
                $response->assertDontSeeText($code);
            }
        }
    });
});

describe('UC-019 状況別: 個別売買へのリンク', function () {
    test('各行に「売買を見る」があり、押した行の売買を約定日・銘柄・対ガチホ差（結果待ち／算出不可）で新しい順に表の下に出す', function () {
        // Arrange
        sitMainScenario();

        // Act
        $before = sitGet()->assertOk()->getContent();
        $selected = sitGet('&cell=jp-near_high')->assertOk()->getContent();

        // Assert: every row has the control carrying its cell key; nothing is listed before pressing
        $rows = sitTableRows($before);
        expect($rows)->toHaveCount(3);
        foreach (['jp-near_high', 'jp-pullback', 'us-near_high'] as $i => $key) {
            expect($rows[$i]['text'])->toContain('売買を見る');
            expect($rows[$i]['html'])->toContain($key);
        }
        expect(sitDrillRows($before))->toBe([]);

        // Assert: the jp near_high trades, newest first (same date → later id first), +26週 売却単独
        expect(sitDrillRows($selected))->toBe([
            '2026-07-28 4444 銘柄4444 結果待ち',
            '2026-03-19 7777 銘柄7777 +40.0%',
            '2026-03-12 3333 銘柄3333 +20.0%',
            '2026-03-06 1111 銘柄1111 -10.0%',
            '2026-03-05 5555 銘柄5555 算出不可',
            '2026-03-05 1111 銘柄1111 -10.0%',
        ]);
        expect(sitRow($selected, '国内株', SIT_JP_HIGH))->toStartWith('国内株 '.SIT_JP_HIGH.' 5 3 1 1');
    });

    test('別の行を選ぶとその行の売買だけを出し、評価期間を変えるとその期間の差を出す', function () {
        // Arrange
        sitMainScenario();

        // Act / Assert
        expect(sitDrillRows(sitGet('&cell=jp-pullback')->assertOk()->getContent()))->toBe(['2026-03-05 6666 銘柄6666 -20.0%']);
        expect(sitDrillRows(sitGet('&cell=us-near_high')->assertOk()->getContent()))->toBe(['2026-03-05 NVDA 銘柄NVDA -10.0%']);
        expect(sitDrillRows(sitGet('&cell=jp-near_high&horizon=4')->assertOk()->getContent()))->toBe([
            '2026-07-28 4444 銘柄4444 +4.0%',
            '2026-03-19 7777 銘柄7777 +0.0%',
            '2026-03-12 3333 銘柄3333 +12.0%',
            '2026-03-06 1111 銘柄1111 -8.0%',
            '2026-03-05 5555 銘柄5555 算出不可',
            '2026-03-05 1111 銘柄1111 -8.0%',
        ]);
    });

    test('存在しない行を指定しても売買は出さず、画面は落ちない', function () {
        // Arrange
        sitMainScenario();

        // Act
        $html = sitGet('&cell=jp-foo')->assertOk()->getContent();

        // Assert: the table is still there (control), no trades listed
        expect(sitTableRows($html))->toHaveCount(3);
        expect(sitDrillRows($html))->toBe([]);
    });
});

describe('UC-019 状況別: 計算とキャッシュ', function () {
    test('状況別の集計は売買の振り返りの同じキャッシュ1行に入り、売買直前の状況と比較は既存の計算と共有して1回ずつしか計算しない', function () {
        // Arrange: pass-through counters around the shared calculations
        sitMainScenario();
        Cache::forget('trade-review');
        $normalizer = app(WeekDateNormalizer::class);
        $finder = new class($normalizer) extends TradeContextFinder
        {
            public int $calls = 0;

            public function find(array $weeks): array
            {
                $this->calls++;

                return parent::find($weeks);
            }
        };
        $calculator = new class(app(SwitchAllocator::class), $normalizer) extends TradeComparisonCalculator
        {
            /** @var array<string, int> */
            public array $calls = ['sells' => 0, 'switches' => 0, 'buys' => 0];

            public function sells(array $weeks): array
            {
                $this->calls['sells']++;

                return parent::sells($weeks);
            }

            public function switches(array $weeks): array
            {
                $this->calls['switches']++;

                return parent::switches($weeks);
            }

            public function buys(array $weeks): array
            {
                $this->calls['buys']++;

                return parent::buys($weeks);
            }
        };
        $builder = new class($normalizer) extends WeeklyPortfolioBuilder
        {
            public int $calls = 0;

            public function build(?string $market = null): array
            {
                $this->calls++;

                return parent::build($market);
            }
        };
        app()->instance(TradeContextFinder::class, $finder);
        app()->instance(TradeComparisonCalculator::class, $calculator);
        app()->instance(WeeklyPortfolioBuilder::class, $builder);

        // Act
        sitGet()->assertOk();

        // Assert
        $cached = Cache::get('trade-review');
        expect($cached)->toBeArray();
        expect($cached['report'])->toHaveKey('situations');
        expect([$finder->calls, $calculator->calls, $builder->calls])->toBe([1, ['sells' => 1, 'switches' => 1, 'buys' => 1], 1]);
    });

    test('キャッシュに入る状況別の集計は配列と値だけで、データベースのキャッシュから読み戻しても同じになる', function () {
        // Arrange: config/cache.php serializable_classes = false → objects come back as
        // __PHP_Incomplete_Class from the database store
        sitMainScenario();

        // Act
        sitGet()->assertOk();

        // Assert
        $report = Cache::get('trade-review')['report'];
        expect($report)->toHaveKey('situations');
        expect(unserialize(serialize($report), ['allowed_classes' => false]))->toEqual($report);
    });

    test('当時の指標の記録が増えれば計算を作り直し、出所の内訳が変わる', function () {
        // Arrange
        $a = sitHolding('1111');
        sitTrade($a, 'sell', '2026-03-05');
        expect(sitCompactText(sitGet()->assertOk()->getContent()))->toContain('当時記録0件')->toContain('事後再計算1件');

        // Act
        sitObserve($a, '2026-03-03 01:00:00', 90, 100, 0.0);

        // Assert
        expect(sitCompactText(sitGet()->assertOk()->getContent()))->toContain('当時記録1件')->toContain('事後再計算0件');
    });
});
