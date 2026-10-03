<?php

namespace Tests\Feature\SignalOutcome;

use App\Actions\SignalOutcome\ShowSignalOutcomesAction;
use App\Livewire\SignalOutcome\SignalOutcomes;
use App\Models\Holding;
use App\Models\IndexWeeklyPrice;
use App\Models\SignalOccurrence;
use App\Models\User;
use App\Models\WeeklyPrice;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UC-014: シグナル検証画面（/signal-outcomes, Livewire） — Red phase, CHG-0020 Cycle4
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-014 基本フロー（集計の閲覧）・出力・業務ルール・エラーケース
|   - docs/adr/ADR-0017-signal-outcome-tracking.md D7（判定区分）/ D8（新ルート＋/signalsからのリンク、タブ数6維持）
|
| Formatting tests MOCK ShowSignalOutcomesAction (payload shape = the Action
| contract in ShowSignalOutcomesActionTest.php) so the numbers are exact; the
| filter tests use real DB rows end-to-end.
|
| Contract assumed (flag at Gate 4):
|   - Route GET /signal-outcomes inside the `auth` group →
|     App\Livewire\SignalOutcome\SignalOutcomes (full page,
|     view livewire.signal-outcome.signal-outcomes),
|     #[Layout('components.layouts.app', ['title' => 'シグナル検証', 'active' => 'signals'])].
|   - Query string ?source= / ?market= ; invalid values are ignored, i.e. the
|     Action is called with null for that filter (no error, 200).
|   - render() calls app(ShowSignalOutcomesAction::class)->execute($source, $market).
|   - Labels: take_profit=利確検討 / buy=買い増し / watchlist_buy=ウォッチリスト押し目買い,
|     the raw signal_type code is shown too; horizons 「+4週」「+13週」「+26週」.
|   - Formats: mean / median and per-occurrence excess = sign + 2 decimals + '%'
|     (5.0 → '+5.00%', -3.25 → '-3.25%'); t値 = 2 decimals (signed only when
|     negative) or '—' when null; 的中率 = 1 decimal + '%';
|     counts 「結果待ち N件」「算出不可 N件」.
|   - Verdicts: working=機能している (provisional → 機能している（暫定）),
|     pending=判断保留, not_working=機能していない, suspicious=異常を疑う.
|   - Each group has a <details> element listing its occurrences (symbol code,
|     observed week Y-m-d, per-horizon excess or 結果待ち/算出不可, metrics null →
|     記録なし) in the payload order (newest first).
|   - /signals has a link whose href ends with /signal-outcomes.
|   - Global nav stays 6 tabs; the 売買シグナル tab gets the active classes
|     (text-primary bg-blue-50) — app.blade.php marks the active tab by class,
|     not by aria-current.
|   - Verdict labels are rendered only for actual verdicts (no static legend
|     that lists every label), otherwise the 「（暫定）」 absence check is moot.
|   - How non-null metrics are rendered in the detail is NOT asserted (left to
|     the implementer).
|   No data-testid is required.
|
| Expected Red: "Class App\Livewire\SignalOutcome\SignalOutcomes not found" /
| GET /signal-outcomes 404 / /signals has no link to /signal-outcomes.
|
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ssopHorizon(array $overrides = []): array
{
    return array_merge([
        'matured_count' => 0,
        'pending_count' => 0,
        'unavailable_count' => 0,
        'mean' => null,
        'median' => null,
        't_value' => null,
        'hit_rate' => null,
        'verdict' => 'pending',
        'provisional' => false,
    ], $overrides);
}

/**
 * @param  array<int, float|null>  $excess
 * @param  array<int, string>  $statuses
 * @param  array<string, mixed>|null  $metrics
 * @return array<string, mixed>
 */
function ssopOccurrence(string $code, string $market, string $week, array $excess, array $statuses, ?array $metrics): array
{
    return [
        'symbol_code' => $code,
        'symbol_name' => '銘柄'.$code,
        'market' => $market,
        'observed_week' => $week,
        'metrics' => $metrics,
        'excess_returns' => $excess,
        'statuses' => $statuses,
    ];
}

/**
 * @return array<string, mixed>
 */
function ssopPayload(): array
{
    return [
        'has_occurrences' => true,
        'as_of_week' => '2026-10-05',
        'groups' => [
            [
                'source' => 'take_profit',
                'signal_type' => 'rsi_overbought',
                'occurrence_count' => 3,
                'horizons' => [
                    4 => ssopHorizon(['matured_count' => 1, 'pending_count' => 1, 'unavailable_count' => 1, 'mean' => -3.25, 'median' => -3.25, 't_value' => null, 'hit_rate' => 100.0]),
                    13 => ssopHorizon(['pending_count' => 3]),
                    26 => ssopHorizon(['pending_count' => 3]),
                ],
                'occurrences' => [
                    ssopOccurrence('MSFT', 'us', '2026-09-14', [4 => null, 13 => null, 26 => null], [4 => 'pending', 13 => 'pending', 26 => 'pending'], ['close' => 400.0]),
                    ssopOccurrence('6758', 'jp', '2026-09-07', [4 => -3.25, 13 => null, 26 => null], [4 => 'matured', 13 => 'pending', 26 => 'pending'], null),
                    ssopOccurrence('4755', 'jp', '2026-08-31', [4 => null, 13 => null, 26 => null], [4 => 'unavailable', 13 => 'pending', 26 => 'pending'], ['close' => 900.0]),
                ],
            ],
            [
                'source' => 'buy',
                'signal_type' => 'pullback',
                'occurrence_count' => 2,
                'horizons' => [
                    4 => ssopHorizon(['matured_count' => 2, 'mean' => 5.0, 'median' => 4.876, 't_value' => 3.4567, 'hit_rate' => 66.6666, 'verdict' => 'working', 'provisional' => true]),
                    13 => ssopHorizon(['matured_count' => 2, 'mean' => -1.234, 'median' => -1.5, 't_value' => -2.5, 'hit_rate' => 0.0, 'verdict' => 'not_working']),
                    26 => ssopHorizon(['matured_count' => 2, 'mean' => 35.0, 'median' => 35.0, 't_value' => 9.0, 'hit_rate' => 100.0, 'verdict' => 'suspicious']),
                ],
                'occurrences' => [
                    ssopOccurrence('7203', 'jp', '2026-03-02', [4 => 5.0, 13 => -1.234, 26 => 35.0], [4 => 'matured', 13 => 'matured', 26 => 'matured'], ['close' => 2500.0]),
                ],
            ],
            [
                'source' => 'watchlist_buy',
                'signal_type' => 'pullback',
                'occurrence_count' => 1,
                'horizons' => [
                    4 => ssopHorizon(['pending_count' => 1]),
                    13 => ssopHorizon(['pending_count' => 1]),
                    26 => ssopHorizon(['pending_count' => 1]),
                ],
                'occurrences' => [
                    ssopOccurrence('NVDA', 'us', '2026-09-28', [4 => null, 13 => null, 26 => null], [4 => 'pending', 13 => 'pending', 26 => 'pending'], ['close' => 180.0]),
                ],
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 * @param  list<string|null>|null  $expectedArgs  null = any args
 */
function ssopMock(array $payload, ?array $expectedArgs = null): void
{
    $mock = \Mockery::mock(ShowSignalOutcomesAction::class);
    $expectation = $mock->shouldReceive('execute');
    $expectedArgs === null ? $expectation->withAnyArgs() : $expectation->with(...$expectedArgs);
    $expectation->andReturn($payload);
    app()->instance(ShowSignalOutcomesAction::class, $mock);
}

/**
 * The <details> block containing the given symbol code, or null.
 */
function ssopDetails(string $html, string $symbolCode): ?string
{
    preg_match_all('#<details\b.*?</details>#su', $html, $blocks);
    foreach ($blocks[0] as $block) {
        if (str_contains($block, $symbolCode)) {
            return $block;
        }
    }

    return null;
}

/**
 * Global nav tabs: label => <a> attributes (same parsing as ConcentrationDashboardTest).
 *
 * @return array<string, string>
 */
function ssopGlobalTabs(string $html): array
{
    preg_match_all('#<header\b.*?<nav([^>]*)>(.*?)</nav>#su', $html, $navs, PREG_SET_ORDER);
    foreach ($navs as $nav) {
        preg_match_all('#<a([^>]*)>\s*([^<]*?)\s*</a>#u', $nav[2], $links, PREG_SET_ORDER);

        return array_column(array_map(fn ($l) => [$l[2], $l[1]], $links), 1, 0);
    }

    return [];
}

/**
 * Real DB rows: JP 9984 buy/pullback and US MSFT take_profit/rsi_overbought.
 */
function ssopSeedRealData(): void
{
    $jp = Holding::create(['symbol_code' => '9984', 'market' => 'jp', 'instrument_type' => 'stock', 'symbol_name' => 'テストJP', 'first_detected_at' => now()]);
    $us = Holding::create(['symbol_code' => 'MSFT', 'market' => 'us', 'instrument_type' => 'stock', 'symbol_name' => 'テストUS', 'first_detected_at' => now()]);
    WeeklyPrice::create(['holding_id' => $jp->id, 'week_date' => '2026-08-31', 'close' => 1000.0, 'volume' => 1]);
    WeeklyPrice::create(['holding_id' => $jp->id, 'week_date' => '2026-09-28', 'close' => 1100.0, 'volume' => 1]);
    IndexWeeklyPrice::create(['index_name' => 'nikkei225', 'week_date' => '2026-08-31', 'close' => 30000.0]);
    IndexWeeklyPrice::create(['index_name' => 'nikkei225', 'week_date' => '2026-09-28', 'close' => 31500.0]);
    SignalOccurrence::create(['holding_id' => $jp->id, 'source' => 'buy', 'signal_type' => 'pullback', 'observed_week' => '2026-08-31', 'snapshot_id' => null, 'metrics' => null]);
    SignalOccurrence::create(['holding_id' => $us->id, 'source' => 'take_profit', 'signal_type' => 'rsi_overbought', 'observed_week' => '2026-08-31', 'snapshot_id' => null, 'metrics' => null]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 03:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('UC-014: シグナル検証画面（Livewire）', function () {
    describe('権限・空状態', function () {
        test('UC-014: 未認証ユーザーはログインページにリダイレクトされる', function () {
            // Act
            $response = $this->get('/signal-outcomes');

            // Assert
            $response->assertRedirect('/login');
        });

        test('UC-014: 発生記録が0件のときは「まだシグナルの記録がありません。次回のCSV取込から記録が始まります」と表示される', function () {
            // Arrange
            $user = User::factory()->create();

            // Act
            $response = $this->actingAs($user)->get('/signal-outcomes');

            // Assert
            $response->assertOk();
            $response->assertSee('まだシグナルの記録がありません。次回のCSV取込から記録が始まります');
            $response->assertSee('<title>シグナル検証 | ポートフォリオ管理</title>', false);
        });
    });

    describe('集計表', function () {
        test('UC-014: 発生元ラベルとシグナル種別、評価期間（+4週／+13週／+26週）が表示される', function () {
            // Arrange
            $user = User::factory()->create();
            ssopMock(ssopPayload());

            // Act
            $component = Livewire::actingAs($user)->test(SignalOutcomes::class);

            // Assert
            $component->assertSee('利確検討');
            $component->assertSee('買い増し');
            $component->assertSee('ウォッチリスト押し目買い');
            $component->assertSee('rsi_overbought');
            $component->assertSee('pullback');
            $component->assertSee('+4週');
            $component->assertSee('+13週');
            $component->assertSee('+26週');
            $component->assertDontSee('まだシグナルの記録がありません');
        });

        test('UC-014: 平均・中央値は符号付き小数2桁の%、t値は小数2桁（未算出は「—」）、的中率は小数1桁の%で表示される', function () {
            // Arrange
            $user = User::factory()->create();
            ssopMock(ssopPayload());

            // Act
            $component = Livewire::actingAs($user)->test(SignalOutcomes::class);

            // Assert
            $component->assertSee('+5.00%');   // mean 5.0
            $component->assertSee('+4.88%');   // median 4.876
            $component->assertSee('-3.25%');   // take_profit mean / median
            $component->assertSee('-1.23%');   // mean -1.234
            $component->assertSee('-1.50%');   // median -1.5
            $component->assertSee('+35.00%');
            $component->assertSee('3.46');     // t 3.4567
            $component->assertSee('-2.50');    // t -2.5
            $component->assertSee('—');        // t null
            $component->assertSee('66.7%');    // hit 66.6666
            $component->assertSee('100.0%');
        });

        test('UC-014: 結果待ち・算出不可の発生は件数だけ「結果待ち n件」「算出不可 n件」で表示される', function () {
            // Arrange
            $user = User::factory()->create();
            ssopMock(ssopPayload());

            // Act
            $component = Livewire::actingAs($user)->test(SignalOutcomes::class);

            // Assert
            $component->assertSee('結果待ち 1件');
            $component->assertSee('算出不可 1件');
            $component->assertSee('結果待ち 3件');
        });

        test('UC-014: 判定区分は 機能している（暫定）／判断保留／機能していない／異常を疑う のラベルで表示される', function () {
            // Arrange
            $user = User::factory()->create();
            ssopMock(ssopPayload());

            // Act
            $component = Livewire::actingAs($user)->test(SignalOutcomes::class);

            // Assert
            $component->assertSee('機能している（暫定）');
            $component->assertSee('判断保留');
            $component->assertSee('機能していない');
            $component->assertSee('異常を疑う');
        });

        test('UC-014: 蓄積3年以上で確定した working は「（暫定）」なしの「機能している」で表示される', function () {
            // Arrange
            $user = User::factory()->create();
            $payload = ssopPayload();
            $payload['groups'] = [$payload['groups'][1]];
            $payload['groups'][0]['horizons'][4]['provisional'] = false;
            $payload['groups'][0]['horizons'][13] = ssopHorizon(['matured_count' => 2]);
            $payload['groups'][0]['horizons'][26] = ssopHorizon(['matured_count' => 2]);
            ssopMock($payload);

            // Act
            $component = Livewire::actingAs($user)->test(SignalOutcomes::class);

            // Assert
            $component->assertSee('機能している');
            $component->assertDontSee('機能している（暫定）');
        });
    });

    describe('個別発生の展開表示', function () {
        test('UC-014: 行を展開すると個別発生が銘柄コード・発生週・各期間の超過リターン（結果待ち／算出不可）とともに新しい順に表示され、根拠値なしは「記録なし」', function () {
            // Arrange
            $user = User::factory()->create();
            ssopMock(ssopPayload());

            // Act
            $html = Livewire::actingAs($user)->test(SignalOutcomes::class)->html();

            // Assert
            $takeProfit = ssopDetails($html, '6758');
            expect($takeProfit)->not->toBeNull();
            expect($takeProfit)
                ->toContain('MSFT')->toContain('2026-09-14')
                ->toContain('6758')->toContain('2026-09-07')->toContain('-3.25%')
                ->toContain('4755')->toContain('2026-08-31')
                ->toContain('結果待ち')
                ->toContain('算出不可')
                ->toContain('記録なし');
            expect(strpos($takeProfit, 'MSFT'))->toBeLessThan(strpos($takeProfit, '6758'));
            expect(strpos($takeProfit, '6758'))->toBeLessThan(strpos($takeProfit, '4755'));
            expect($takeProfit)->not->toContain('7203');

            $buy = ssopDetails($html, '7203');
            expect($buy)->not->toBeNull();
            expect($buy)
                ->toContain('2026-03-02')
                ->toContain('+5.00%')
                ->toContain('-1.23%')
                ->toContain('+35.00%')
                ->not->toContain('記録なし');
        });
    });

    describe('絞り込み（クエリ文字列）', function () {
        test('UC-014: ?source=buy では利確検討の発生が表示されない', function () {
            // Arrange
            $user = User::factory()->create();
            ssopSeedRealData();

            // Act
            $response = $this->actingAs($user)->get('/signal-outcomes?source=buy');

            // Assert
            $response->assertOk();
            $response->assertSee('9984');
            $response->assertSee('pullback');
            $response->assertDontSee('MSFT');
            $response->assertDontSee('rsi_overbought');
        });

        test('UC-014: ?market=us ではJP株だけの集計行が表示されない', function () {
            // Arrange
            $user = User::factory()->create();
            ssopSeedRealData();

            // Act
            $response = $this->actingAs($user)->get('/signal-outcomes?market=us');

            // Assert
            $response->assertOk();
            $response->assertSee('MSFT');
            $response->assertSee('rsi_overbought');
            $response->assertDontSee('9984');
            $response->assertDontSee('pullback');
        });

        test('UC-014: 想定外の ?source=foo はエラーにせず無視して全件を表示する', function () {
            // Arrange
            $user = User::factory()->create();
            ssopSeedRealData();

            // Act
            $response = $this->actingAs($user)->get('/signal-outcomes?source=foo&market=eu');

            // Assert
            $response->assertOk();
            $response->assertSee('9984');
            $response->assertSee('MSFT');
        });

        test('UC-014: 正しい絞り込み値はそのまま、想定外の値は null として Action に渡される', function () {
            // Arrange
            $user = User::factory()->create();

            // Act & Assert: valid values are forwarded
            ssopMock(ssopPayload(), ['buy', 'us']);
            Livewire::withQueryParams(['source' => 'buy', 'market' => 'us'])
                ->actingAs($user)
                ->test(SignalOutcomes::class)
                ->assertOk();

            // Act & Assert: invalid values are dropped to null
            ssopMock(ssopPayload(), [null, null]);
            Livewire::withQueryParams(['source' => 'foo', 'market' => 'eu'])
                ->actingAs($user)
                ->test(SignalOutcomes::class)
                ->assertOk();
        });
    });

    describe('画面配置（ADR-0017 D8）', function () {
        test('UC-014: 売買シグナル画面（/signals）にシグナル検証画面へのリンクがある', function () {
            // Arrange
            $user = User::factory()->create();

            // Act
            $response = $this->actingAs($user)->get('/signals');

            // Assert
            $response->assertOk();
            expect($response->getContent())->toMatch('#href="[^"]*/signal-outcomes"#');
        });

        test('UC-014: グローバルナビは6タブのままで、シグナル検証画面では「売買シグナル」タブだけが選択中になる', function () {
            // Arrange
            $user = User::factory()->create();

            // Act
            $response = $this->actingAs($user)->get('/signal-outcomes');

            // Assert
            $response->assertOk();
            $tabs = ssopGlobalTabs($response->getContent());
            expect(array_keys($tabs))->toBe(['保有一覧', '売買シグナル', 'セクター配分', '新規投資候補', 'CSV取込', 'サマリーレポート']);
            foreach ($tabs as $label => $attributes) {
                if ($label === '売買シグナル') {
                    expect($attributes)->toContain('text-primary')->toContain('bg-blue-50');
                } else {
                    expect($attributes)->not->toContain('text-primary')->not->toContain('bg-blue-50');
                }
            }
        });

        // Regression: SignalList's Layout had no 'active', so /signals itself never highlighted its own tab
        // (same class of bug as /sector-dashboard fixed in CHG-0030). Fix approved by the user on 2026-10-03.
        test('UC-004: 売買シグナル画面でもグローバルナビの「売買シグナル」タブだけが選択中になる', function () {
            // Arrange
            $user = User::factory()->create();

            // Act
            $response = $this->actingAs($user)->get('/signals');

            // Assert
            $response->assertOk();
            $tabs = ssopGlobalTabs($response->getContent());
            foreach ($tabs as $label => $attributes) {
                if ($label === '売買シグナル') {
                    expect($attributes)->toContain('text-primary')->toContain('bg-blue-50');
                } else {
                    expect($attributes)->not->toContain('text-primary')->not->toContain('bg-blue-50');
                }
            }
        });
    });
});
