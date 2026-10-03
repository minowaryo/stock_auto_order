<?php

namespace Tests\Feature;

use App\Actions\Concentration\ShowConcentrationDashboardAction;
use App\Livewire\Concentration\ConcentrationDashboard;
use App\Livewire\Sector\SectorDashboard;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\WeeklyPrice;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UC-015: 集中度ダッシュボード（Livewireフルページ） — Red phase, CHG-0026 Cycle4
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0019-concentration-dashboard.md D5/D6/D7
|   - docs/product/use-cases.md UC-015 業務ルール・エラーケース
|   - docs/product/ui-guidelines.md（判定・バッジを付けない / タブ数6を維持）
|
| Screen tests MOCK ShowConcentrationDashboardAction so the formatting
| assertions are exact; the calculation itself is covered by
| tests/Feature/UC015ConcentrationDashboardActionTest.php.
|
| Contract assumed (flag at Gate 4):
|   - Route GET /concentration-dashboard inside the `auth` group;
|     App\Livewire\Concentration\ConcentrationDashboard (full-page, title
|     contains 集中度), render() calls the Action on every render.
|   - The correlation matrix table carries data-testid="correlation-matrix"
|     (used to assert the matrix section is / is not rendered).
|   - Formats: PC1 / coverage / top5 = 1 decimal + '%'; ENB / betas /
|     correlations = 2 decimals; beta-list weight = 1 decimal + '%';
|     window '2025-09-29 〜 2026-09-28'; count '121銘柄'.
|   - Wordings: null beta '取得不可（—）', null metric '算出不可',
|     null correlation cell '—', hidden '表示外 N銘柄', reasons
|     '株式以外' / '週足不足', notes '現地通貨建て' + '推定誤差'
|     (the latter only when included_count > 52).
|
| Expected Red: "Class App\Livewire\Concentration\ConcentrationDashboard not found"
| / "Class App\Actions\Concentration\ShowConcentrationDashboardAction not found"
| / GET /concentration-dashboard 404.
|
| CHG-0030 (ADR-0021 D1〜D5) additions: the payload now also carries
| `correlation_bands` and `verdicts`; the screen shows per-metric verdict badges
| (data-testid="verdict-{key}", data-verdict="ok|caution|concentrated"), a
| legend, heatmap cells (data-band), and the セクター配分 | 集中度 sub-tabs
| (<nav aria-label="セクターと集中度の切替"> via x-sector-concentration-tabs).
| The old "no judgement UI" rule (ADR-0019 D6) is replaced: badges/colours are
| allowed, composite scores and the UC-005 labels are still forbidden (D7).
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function concDashScreenTestPayload(array $overrides = []): array
{
    return array_merge([
        'has_snapshot' => true,
        'window_start_week' => '2025-09-29',
        'window_end_week' => '2026-09-28',
        'included_count' => 121,
        'coverage_rate' => 92.34,
        'pc1_share' => 14.7,
        'effective_number_of_bets' => 5.0812,
        'portfolio_sox_beta' => 1.2345,
        'top5_weight' => 38.26,
        'correlation_matrix' => [
            ['holding_id' => 1, 'symbol_code' => '8035', 'symbol_name' => '東京エレクトロン', 'correlations' => [1.0, 0.5678, -0.1234]],
            ['holding_id' => 2, 'symbol_code' => 'NVDA', 'symbol_name' => 'エヌビディア', 'correlations' => [0.5678, 1.0, 0.3333]],
            ['holding_id' => 3, 'symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車', 'correlations' => [-0.1234, 0.3333, 1.0]],
        ],
        'hidden_count' => 0,
        'sox_betas' => [
            ['symbol_code' => '8035', 'symbol_name' => '東京エレクトロン', 'weight' => 42.54, 'sox_beta' => 1.4567],
            ['symbol_code' => 'NVDA', 'symbol_name' => 'エヌビディア', 'weight' => 31.06, 'sox_beta' => 1.1],
            ['symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車', 'weight' => 26.4, 'sox_beta' => 0.2345],
        ],
        'excluded' => [],
        'correlation_bands' => [
            [null, 'high', 'none'],
            ['high', null, 'mild'],
            ['none', 'mild', null],
        ],
        'verdicts' => [
            'pc1_share' => 'ok',
            'effective_number_of_bets' => 'caution',
            'top5_weight' => 'caution',
            'portfolio_sox_beta' => 'concentrated',
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 */
function concDashScreenTestMock(array $payload): void
{
    $mock = \Mockery::mock(ShowConcentrationDashboardAction::class);
    $mock->shouldReceive('execute')->andReturn($payload);
    app()->instance(ShowConcentrationDashboardAction::class, $mock);
}

/**
 * @param  array<string, string|null>  $verdicts
 * @return array<string, mixed>
 */
function concDashScreenTestVerdicts(?string $pc1, ?string $enb, ?string $top5, ?string $beta): array
{
    return [
        'pc1_share' => $pc1,
        'effective_number_of_bets' => $enb,
        'top5_weight' => $top5,
        'portfolio_sox_beta' => $beta,
    ];
}

/**
 * Finds the verdict badge element: returns [opening-tag attributes, inner text] or null.
 *
 * @return array{0: string, 1: string}|null
 */
function concDashScreenTestBadge(string $html, string $key): ?array
{
    if (preg_match('#<span([^>]*data-testid="verdict-'.preg_quote($key, '#').'"[^>]*)>\s*([^<]*?)\s*</span>#u', $html, $m) !== 1) {
        return null;
    }

    return [$m[1], $m[2]];
}

/**
 * Returns the correlation matrix body as rows of cells (row-label cell dropped);
 * each cell is [td attributes, inner text].
 *
 * @return list<list<array{0: string, 1: string}>>
 */
function concDashScreenTestHeatmapCells(string $html): array
{
    preg_match('#<table[^>]*data-testid="correlation-matrix".*?</table>#su', $html, $table);
    preg_match_all('#<tr[^>]*>(.*?)</tr>#su', $table[0] ?? '', $rows);
    $result = [];
    foreach ($rows[1] as $rowHtml) {
        if (preg_match_all('#<td([^>]*)>(.*?)</td>#su', $rowHtml, $cells, PREG_SET_ORDER) === 0) {
            continue;
        }
        array_shift($cells);
        $result[] = array_map(fn ($c) => [$c[1], trim(strip_tags($c[2]))], $cells);
    }

    return $result;
}

/**
 * Parses the sub-tab nav: label => anchor attributes. Null when the nav is absent.
 *
 * @return array<string, string>|null
 */
function concDashScreenTestSubTabs(string $html): ?array
{
    if (preg_match('#<nav[^>]*aria-label="セクターと集中度の切替"[^>]*>(.*?)</nav>#su', $html, $nav) !== 1) {
        return null;
    }
    preg_match_all('#<a([^>]*)>\s*([^<]*?)\s*</a>#u', $nav[1], $links, PREG_SET_ORDER);

    return array_column(array_map(fn ($l) => [$l[2], $l[1]], $links), 1, 0);
}

/**
 * Parses the global header nav (the <nav> that is not the sub-tab nav): label => anchor attributes.
 *
 * @return array<string, string>
 */
function concDashScreenTestGlobalTabs(string $html): array
{
    preg_match_all('#<nav([^>]*)>(.*?)</nav>#su', $html, $navs, PREG_SET_ORDER);
    foreach ($navs as $nav) {
        if (str_contains($nav[1], 'セクターと集中度の切替')) {
            continue;
        }
        preg_match_all('#<a([^>]*)>\s*([^<]*?)\s*</a>#u', $nav[2], $links, PREG_SET_ORDER);

        return array_column(array_map(fn ($l) => [$l[2], $l[1]], $links), 1, 0);
    }

    return [];
}

/**
 * Real-DB fixture: 3 stocks with 53 weeks of deterministic closes, no SOX rows.
 */
function concDashScreenTestSeedRealData(): void
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => now(),
    ]);
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
    $end = new \DateTimeImmutable('2026-09-28', new \DateTimeZone('UTC'));

    foreach ([1, 2, 3] as $seed) {
        $holding = Holding::create([
            'symbol_code' => 'R00'.$seed,
            'market' => 'jp',
            'instrument_type' => 'stock',
            'symbol_name' => '実データ'.$seed,
            'sector_classification_id' => null,
            'first_detected_at' => now(),
        ]);
        HoldingSnapshot::create([
            'snapshot_id' => $snapshot->id,
            'holding_id' => $holding->id,
            'quantity' => 100,
            'average_cost' => 1,
            'current_price' => 10 * $seed,
            'fx_rate_used' => null,
            'unrealized_gain_amount' => 0,
            'unrealized_gain_rate' => 0,
            'ma20' => null,
            'ma75' => null,
            'is_newly_detected' => false,
        ]);
        $close = 100.0 + $seed * 10;
        $rows = [];
        for ($k = 0; $k < 53; $k++) {
            if ($k > 0) {
                $close = round($close * (1 + 0.01 * sin($k * (0.7 + 0.13 * $seed) + $seed)), 4);
            }
            $rows[] = [
                'holding_id' => $holding->id,
                'week_date' => $end->modify('-'.((52 - $k) * 7).' days')->format('Y-m-d'),
                'close' => $close,
                'volume' => 1000,
            ];
        }
        WeeklyPrice::insert($rows);
    }
}

describe('UC-015: 集中度ダッシュボード（Livewire）', function () {
    describe('正常系: 5指標・窓・対象銘柄数・カバー率', function () {
        test('UC-015: 5指標のラベルと整形済みの値、計算窓、計算対象の銘柄数、カバー率が表示される', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload());

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('PC1寄与率');
            $component->assertSee('実効ベット数');
            $component->assertSee('対SOXベータ');
            $component->assertSee('上位5銘柄ウェイト');
            $component->assertSee('相関行列');
            $component->assertSee('14.7%');
            $component->assertSee('5.08');
            $component->assertSee('1.23');
            $component->assertSee('38.3%');
            $component->assertSee('2025-09-29 〜 2026-09-28');
            $component->assertSee('121銘柄');
            $component->assertSee('カバー率');
            $component->assertSee('92.3%');
        });
    });

    describe('相関行列', function () {
        test('UC-015: 相関行列に銘柄コード・銘柄名と小数2桁の相関係数が表示され、表示外が無ければ「表示外」は出ない', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload(['hidden_count' => 0]));

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSeeHtml('data-testid="correlation-matrix"');
            $component->assertSee('8035');
            $component->assertSee('東京エレクトロン');
            $component->assertSee('NVDA');
            $component->assertSee('トヨタ自動車');
            $component->assertSee('1.00');
            $component->assertSee('0.57');
            $component->assertSee('-0.12');
            $component->assertSee('0.33');
            $component->assertDontSee('表示外');
            $component->assertDontSee('—');
        });

        test('UC-015: 表示しなかった銘柄があるときは「表示外 N銘柄」が表示される', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload(['hidden_count' => 2, 'included_count' => 5]));

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('表示外 2銘柄');
        });

        test('UC-015: 定数系列で相関が null のセルは「—」で表示される', function () {
            // Arrange
            $user = User::factory()->create();
            $matrix = [
                ['holding_id' => 1, 'symbol_code' => '8035', 'symbol_name' => '東京エレクトロン', 'correlations' => [1.0, null]],
                ['holding_id' => 2, 'symbol_code' => 'NVDA', 'symbol_name' => 'エヌビディア', 'correlations' => [null, null]],
            ];
            concDashScreenTestMock(concDashScreenTestPayload(['correlation_matrix' => $matrix, 'included_count' => 2, 'correlation_bands' => [[null, null], [null, null]]]));

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('—');
            $component->assertSee('1.00');
        });
    });

    describe('銘柄別の対SOXベータ', function () {
        test('UC-015: 銘柄別の対SOXベータ一覧にウェイト(小数1桁%)とベータ(小数2桁)が表示され、null は「取得不可（—）」になる', function () {
            // Arrange
            $user = User::factory()->create();
            $betas = [
                ['symbol_code' => '8035', 'symbol_name' => '東京エレクトロン', 'weight' => 42.54, 'sox_beta' => 1.4567],
                ['symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車', 'weight' => 26.4, 'sox_beta' => null],
            ];
            concDashScreenTestMock(concDashScreenTestPayload(['sox_betas' => $betas]));

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('42.5%');
            $component->assertSee('1.46');
            $component->assertSee('26.4%');
            $component->assertSee('取得不可（—）');
        });
    });

    describe('除外銘柄', function () {
        test('UC-015: 計算対象から外した銘柄が理由ラベル（株式以外／週足不足）付きで表示される', function () {
            // Arrange
            $user = User::factory()->create();
            $excluded = [
                ['symbol_code' => '1306', 'symbol_name' => 'TOPIX連動ETF', 'reason' => 'not_stock'],
                ['symbol_code' => '9999', 'symbol_name' => '新規上場株', 'reason' => 'insufficient_history'],
            ];
            concDashScreenTestMock(concDashScreenTestPayload(['excluded' => $excluded]));

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('TOPIX連動ETF');
            $component->assertSee('株式以外');
            $component->assertSee('新規上場株');
            $component->assertSee('週足不足');
        });
    });

    describe('注記', function () {
        test('UC-015: 現地通貨建ての注記は常に表示され、推定誤差の注記は計算対象が52銘柄を超えるときだけ表示される', function () {
            // Arrange
            $user = User::factory()->create();

            // Act + Assert: 121 > 52 -> shown
            concDashScreenTestMock(concDashScreenTestPayload(['included_count' => 121]));
            $many = Livewire::actingAs($user)->test(ConcentrationDashboard::class);
            $many->assertSee('現地通貨建て');
            $many->assertSee('推定誤差');

            // Act + Assert: 10 <= 52 -> not shown
            concDashScreenTestMock(concDashScreenTestPayload(['included_count' => 10]));
            $few = Livewire::actingAs($user)->test(ConcentrationDashboard::class);
            $few->assertSee('現地通貨建て');
            $few->assertDontSee('推定誤差');
        });
    });

    describe('合成スコアを作らない（ADR-0019 D7 / ADR-0021）', function () {
        test('UC-015: 総合スコア・ランキング・UC-005の判定ラベル・ENBの目安は、どの値でも画面に出ない（判定バッジは出てよい）', function () {
            // Arrange: extreme values
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload([
                'pc1_share' => 95.0,
                'effective_number_of_bets' => 1.02,
                'top5_weight' => 90.0,
                'portfolio_sox_beta' => 2.5,
                'verdicts' => concDashScreenTestVerdicts('concentrated', 'concentrated', 'concentrated', 'concentrated'),
            ]));

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            foreach (['総合スコア', '総合点', 'ランキング', 'スコア', '偏り警告', 'やや偏り', '健全', 'ENB 2〜5'] as $forbidden) {
                $component->assertDontSee($forbidden);
            }
            $component->assertSeeHtml('data-testid="verdict-pc1_share"');
        });
    });

    describe('指標ごとの判定バッジ（ADR-0021 D1/D4）', function () {
        test('UC-015: 4指標それぞれに、判定段階に応じたラベル（分散OK／やや集中／集中）と色（緑／黄／赤）のバッジが表示される', function () {
            // Arrange
            $user = User::factory()->create();
            $levels = [
                'ok' => ['分散OK', 'bg-green-100'],
                'caution' => ['やや集中', 'bg-amber-100'],
                'concentrated' => ['集中', 'bg-red-100'],
            ];

            foreach ($levels as $level => [$label, $colorClass]) {
                concDashScreenTestMock(concDashScreenTestPayload([
                    'verdicts' => concDashScreenTestVerdicts($level, $level, $level, $level),
                ]));

                // Act
                $html = Livewire::actingAs($user)->test(ConcentrationDashboard::class)->html();

                // Assert
                foreach (['pc1_share', 'effective_number_of_bets', 'top5_weight', 'portfolio_sox_beta'] as $key) {
                    $badge = concDashScreenTestBadge($html, $key);
                    expect($badge)->not->toBeNull("badge for {$key} at {$level}");
                    expect($badge[0])->toContain('data-verdict="'.$level.'"');
                    expect($badge[0])->toContain($colorClass);
                    expect($badge[1])->toBe($label);
                }
            }
        });

        test('UC-015: 指標ごとに独立して判定され、指標ごとに異なる段階のバッジが並ぶ', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload([
                'verdicts' => concDashScreenTestVerdicts('ok', 'caution', 'concentrated', 'caution'),
            ]));

            // Act
            $html = Livewire::actingAs($user)->test(ConcentrationDashboard::class)->html();

            // Assert
            expect(concDashScreenTestBadge($html, 'pc1_share')[0])->toContain('data-verdict="ok"');
            expect(concDashScreenTestBadge($html, 'effective_number_of_bets')[0])->toContain('data-verdict="caution"');
            expect(concDashScreenTestBadge($html, 'top5_weight')[0])->toContain('data-verdict="concentrated"');
            expect(concDashScreenTestBadge($html, 'portfolio_sox_beta')[0])->toContain('data-verdict="caution"');
        });

        test('UC-015: 判定が null の指標にはバッジが出ず、他の指標のバッジは出る', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload([
                'portfolio_sox_beta' => null,
                'verdicts' => concDashScreenTestVerdicts('ok', null, 'caution', null),
            ]));

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSeeHtml('data-testid="verdict-pc1_share"');
            $component->assertSeeHtml('data-testid="verdict-top5_weight"');
            $component->assertDontSeeHtml('data-testid="verdict-effective_number_of_bets"');
            $component->assertDontSeeHtml('data-testid="verdict-portfolio_sox_beta"');
        });

        test('UC-015: 凡例に「指標ごとの良い向き（低いほど／高いほど）」と「しきい値は叩き台」の説明が表示される', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload());

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('低いほど');
            $component->assertSee('高いほど');
            $component->assertSee('叩き台');
        });
    });

    describe('相関行列のヒートマップ（ADR-0021 D3）', function () {
        test('UC-015: 相関セルに区分（high/mild/none）が data-band で付き、対角セルには付かない', function () {
            // Arrange: default payload (3x3)
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload());

            // Act
            $cells = concDashScreenTestHeatmapCells(Livewire::actingAs($user)->test(ConcentrationDashboard::class)->html());

            // Assert
            expect($cells)->toHaveCount(3);
            $expectedBands = [
                [null, 'high', 'none'],
                ['high', null, 'mild'],
                ['none', 'mild', null],
            ];
            foreach ($expectedBands as $i => $row) {
                expect($cells[$i])->toHaveCount(3);
                foreach ($row as $j => $band) {
                    if ($band === null) {
                        expect($cells[$i][$j][0])->not->toContain('data-band');
                        expect($cells[$i][$j][0])->not->toContain('background-color');
                    } else {
                        expect($cells[$i][$j][0])->toContain('data-band="'.$band.'"');
                    }
                }
            }
            // none has no colour; high / mild do
            expect($cells[0][1][0])->toContain('background-color');
            expect($cells[1][2][0])->toContain('background-color');
            expect($cells[0][2][0])->not->toContain('background-color');
            expect($cells[2][0][0])->not->toContain('background-color');
            // values stay as before
            expect($cells[0][1][1])->toBe('0.57');
            expect($cells[0][2][1])->toBe('-0.12');
        });

        test('UC-015: 強い連動（strong）と逆相関（negative）のセルにも data-band と背景色が付く', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload([
                'correlation_matrix' => [
                    ['holding_id' => 1, 'symbol_code' => '8035', 'symbol_name' => '東京エレクトロン', 'correlations' => [1.0, 0.8, -0.4]],
                    ['holding_id' => 2, 'symbol_code' => 'NVDA', 'symbol_name' => 'エヌビディア', 'correlations' => [0.8, 1.0, 0.1]],
                    ['holding_id' => 3, 'symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車', 'correlations' => [-0.4, 0.1, 1.0]],
                ],
                'correlation_bands' => [
                    [null, 'strong', 'negative'],
                    ['strong', null, 'none'],
                    ['negative', 'none', null],
                ],
            ]));

            // Act
            $cells = concDashScreenTestHeatmapCells(Livewire::actingAs($user)->test(ConcentrationDashboard::class)->html());

            // Assert
            expect($cells[0][1][0])->toContain('data-band="strong"')->toContain('background-color');
            expect($cells[0][2][0])->toContain('data-band="negative"')->toContain('background-color');
            expect($cells[2][0][0])->toContain('data-band="negative"')->toContain('background-color');
            expect($cells[1][2][0])->toContain('data-band="none"')->not->toContain('background-color');
            expect($cells[1][1][0])->not->toContain('data-band');
        });

        test('UC-015: 相関が null のセル（band も null）には data-band も背景色も付かず「—」が表示される', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload([
                'included_count' => 2,
                'correlation_matrix' => [
                    ['holding_id' => 1, 'symbol_code' => '8035', 'symbol_name' => '東京エレクトロン', 'correlations' => [1.0, null]],
                    ['holding_id' => 2, 'symbol_code' => 'NVDA', 'symbol_name' => 'エヌビディア', 'correlations' => [null, null]],
                ],
                'correlation_bands' => [[null, null], [null, null]],
            ]));

            // Act
            $cells = concDashScreenTestHeatmapCells(Livewire::actingAs($user)->test(ConcentrationDashboard::class)->html());

            // Assert
            foreach ($cells as $row) {
                foreach ($row as $cell) {
                    expect($cell[0])->not->toContain('data-band');
                    expect($cell[0])->not->toContain('background-color');
                }
            }
            expect($cells[0][1][1])->toBe('—');
        });
    });

    describe('ヒートマップの凡例（UC-015 業務ルール / ADR-0021 D2）', function () {
        test('UC-015: 相関行列があるときは「赤＝同じ方向に動く／緑＝逆方向に動く／色なし＝ほぼ無関係」の凡例が表示される', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload());

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('赤＝同じ方向に動く');
            $component->assertSee('緑＝逆方向に動く');
            $component->assertSee('色なし＝ほぼ無関係');
        });

        test('UC-015: 相関行列が無い（算出不可・スナップショット無し）ときは凡例を表示しない', function () {
            // Arrange
            $user = User::factory()->create();
            $noMatrix = ['correlation_matrix' => [], 'correlation_bands' => [], 'hidden_count' => 0];

            // Act / Assert: 算出不可（行列なし）
            concDashScreenTestMock(concDashScreenTestPayload($noMatrix));
            Livewire::actingAs($user)->test(ConcentrationDashboard::class)
                ->assertDontSee('赤＝同じ方向に動く')
                ->assertDontSee('緑＝逆方向に動く')
                ->assertDontSee('色なし＝ほぼ無関係');

            // Act / Assert: スナップショット無し
            concDashScreenTestMock(concDashScreenTestPayload($noMatrix + ['has_snapshot' => false]));
            Livewire::actingAs($user)->test(ConcentrationDashboard::class)
                ->assertDontSee('赤＝同じ方向に動く')
                ->assertDontSee('色なし＝ほぼ無関係');
        });
    });

    describe('空状態・算出不可', function () {
        test('UC-015: スナップショットが無いときは「まだ保有データがありません。CSVを取り込むと表示されます」が表示される', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock([
                'has_snapshot' => false,
                'window_start_week' => null,
                'window_end_week' => null,
                'included_count' => 0,
                'coverage_rate' => null,
                'pc1_share' => null,
                'effective_number_of_bets' => null,
                'portfolio_sox_beta' => null,
                'top5_weight' => null,
                'correlation_matrix' => [],
                'hidden_count' => 0,
                'sox_betas' => [],
                'excluded' => [],
                'correlation_bands' => [],
                'verdicts' => concDashScreenTestVerdicts(null, null, null, null),
            ]);

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('まだ保有データがありません。CSVを取り込むと表示されます');
            $component->assertDontSeeHtml('data-testid="correlation-matrix"');
        });

        test('UC-015: 計算対象が2銘柄未満のときPC1・実効ベット数・ポートフォリオβは「算出不可」、相関行列は出ず、上位5銘柄ウェイトと除外一覧は表示される', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload([
                'included_count' => 1,
                'coverage_rate' => 40.0,
                'pc1_share' => null,
                'effective_number_of_bets' => null,
                'portfolio_sox_beta' => null,
                'top5_weight' => 55.5,
                'correlation_matrix' => [],
                'hidden_count' => 1,
                'sox_betas' => [
                    ['symbol_code' => '8035', 'symbol_name' => '東京エレクトロン', 'weight' => 100.0, 'sox_beta' => 1.4567],
                ],
                'excluded' => [
                    ['symbol_code' => '1306', 'symbol_name' => 'TOPIX連動ETF', 'reason' => 'not_stock'],
                ],
                'correlation_bands' => [],
                'verdicts' => concDashScreenTestVerdicts(null, null, 'concentrated', null),
            ]));

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('算出不可');
            $component->assertDontSeeHtml('data-testid="correlation-matrix"');
            $component->assertSee('55.5%');
            $component->assertSee('TOPIX連動ETF');
            $component->assertSee('株式以外');
            $component->assertDontSee('週足がそろった銘柄がありません');
        });

        test('UC-015: 計算対象が0銘柄で週足不足の銘柄があるときは「週足がそろった銘柄がありません。次回のCSV取込後に算出できます」も表示される', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload([
                'included_count' => 0,
                'coverage_rate' => 0.0,
                'pc1_share' => null,
                'effective_number_of_bets' => null,
                'portfolio_sox_beta' => null,
                'top5_weight' => 100.0,
                'correlation_matrix' => [],
                'hidden_count' => 0,
                'sox_betas' => [],
                'excluded' => [
                    ['symbol_code' => '9999', 'symbol_name' => '新規上場株', 'reason' => 'insufficient_history'],
                ],
                'correlation_bands' => [],
                'verdicts' => concDashScreenTestVerdicts(null, null, 'concentrated', null),
            ]));

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('週足がそろった銘柄がありません。次回のCSV取込後に算出できます');
            $component->assertSee('算出不可');
            $component->assertSee('週足不足');
            $component->assertSee('100.0%');
        });

        test('UC-015: SOXのβだけが null のときは「取得不可（—）」が出て、他の指標は通常どおり表示され「算出不可」は出ない', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload([
                'portfolio_sox_beta' => null,
                'verdicts' => concDashScreenTestVerdicts('ok', 'caution', 'caution', null),
                'sox_betas' => [
                    ['symbol_code' => '8035', 'symbol_name' => '東京エレクトロン', 'weight' => 60.0, 'sox_beta' => null],
                    ['symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車', 'weight' => 40.0, 'sox_beta' => null],
                ],
            ]));

            // Act
            $component = Livewire::actingAs($user)->test(ConcentrationDashboard::class);

            // Assert
            $component->assertSee('取得不可（—）');
            $component->assertSee('14.7%');
            $component->assertSee('5.08');
            $component->assertSee('38.3%');
            $component->assertDontSee('算出不可');
        });
    });

    describe('HTTP（実DB・モックなし）', function () {
        test('UC-015: 未認証ユーザーは /concentration-dashboard にアクセスするとログインページへリダイレクトされる', function () {
            // Arrange: guest

            // Act
            $response = $this->get('/concentration-dashboard');

            // Assert
            $response->assertRedirect('/login');
        });

        test('UC-015: 認証済みユーザーはスナップショットが無くても200で空状態メッセージと「集中度」の見出しを見られる', function () {
            // Arrange
            $user = User::factory()->create();

            // Act
            $response = $this->actingAs($user)->get('/concentration-dashboard');

            // Assert
            $response->assertOk();
            $response->assertSee('まだ保有データがありません。CSVを取り込むと表示されます');
            expect($response->getContent())->toMatch('#<title>[^<]*集中度[^<]*</title>#u');
            expect($response->getContent())->toMatch('#<h1[^>]*>[^<]*集中度[^<]*</h1>#u');
        });

        test('UC-015: 実データ（3銘柄・SOX無し）でも200で表示され、PC1寄与率・カバー率100.0%・「取得不可（—）」が出る', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestSeedRealData();

            // Act
            $response = $this->actingAs($user)->get('/concentration-dashboard');

            // Assert
            $response->assertOk();
            $response->assertSee('PC1寄与率');
            $response->assertSee('3銘柄');
            $response->assertSee('100.0%');
            $response->assertSee('取得不可（—）');
            $response->assertSee('実データ1');
            $response->assertDontSee('算出不可');
        });

        test('UC-015: 実データ（3銘柄・SOX無し）のHTMLに、上位5ウェイト・PC1の判定バッジとヒートマップのdata-bandが出て、SOXベータの判定バッジは出ない', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestSeedRealData();

            // Act
            $response = $this->actingAs($user)->get('/concentration-dashboard');

            // Assert: 3 holdings -> top5 = 100% -> concentrated; no SOX rows -> no beta verdict
            $response->assertOk();
            $html = $response->getContent();
            $top5 = concDashScreenTestBadge($html, 'top5_weight');
            expect($top5)->not->toBeNull();
            expect($top5[0])->toContain('data-verdict="concentrated"');
            $pc1 = concDashScreenTestBadge($html, 'pc1_share');
            expect($pc1)->not->toBeNull();
            expect($pc1[0])->toMatch('/data-verdict="(ok|caution|concentrated)"/');
            expect($html)->toMatch('/data-band="(strong|high|mild|none|negative)"/');
            $response->assertDontSeeHtml('data-testid="verdict-portfolio_sox_beta"');
        });
    });

    describe('セクター配分との切替タブ（ADR-0021 D5 / UC-005 行き来）', function () {
        test('UC-005: セクター配分画面から集中度ダッシュボードへのリンクがあり、グローバルナビは変わらない', function () {
            // Arrange
            $user = User::factory()->create();

            // Act
            $sector = Livewire::actingAs($user)->test(SectorDashboard::class);
            $holdings = $this->actingAs($user)->get('/holdings');

            // Assert
            $sector->assertSeeHtml('href="/concentration-dashboard"');
            $sector->assertSee('集中度');
            $holdings->assertOk();
            expect($holdings->getContent())->not->toContain('concentration-dashboard');
        });

        test('UC-015: 集中度画面（空状態・HTTP）に切替タブがあり、「集中度」が現在位置、「セクター配分」は /sector-dashboard へのリンクになる', function () {
            // Arrange
            $user = User::factory()->create();

            // Act
            $response = $this->actingAs($user)->get('/concentration-dashboard');

            // Assert
            $response->assertOk();
            $tabs = concDashScreenTestSubTabs($response->getContent());
            expect($tabs)->not->toBeNull();
            expect(array_keys($tabs))->toBe(['セクター配分', '集中度']);
            expect($tabs['セクター配分'])->toContain('href="/sector-dashboard"')->toContain('wire:navigate')->not->toContain('aria-current');
            expect($tabs['集中度'])->toContain('href="/concentration-dashboard"')->toContain('wire:navigate')->toContain('aria-current="page"');
        });

        test('UC-015: 集中度画面（Livewire・データあり）にも同じ切替タブが表示される', function () {
            // Arrange
            $user = User::factory()->create();
            concDashScreenTestMock(concDashScreenTestPayload());

            // Act
            $html = Livewire::actingAs($user)->test(ConcentrationDashboard::class)->html();

            // Assert
            $tabs = concDashScreenTestSubTabs($html);
            expect($tabs)->not->toBeNull();
            expect($tabs['集中度'])->toContain('aria-current="page"');
            expect($tabs['セクター配分'])->toContain('href="/sector-dashboard"')->not->toContain('aria-current');
        });

        test('UC-005: セクター配分画面に切替タブがあり、「セクター配分」が現在位置・「集中度」はリンクで、旧テキストリンクは無い', function () {
            // Arrange
            $user = User::factory()->create();

            // Act
            $response = $this->actingAs($user)->get('/sector-dashboard');
            $component = Livewire::actingAs($user)->test(SectorDashboard::class);

            // Assert
            $response->assertOk();
            foreach ([$response->getContent(), $component->html()] as $html) {
                $tabs = concDashScreenTestSubTabs($html);
                expect($tabs)->not->toBeNull();
                expect(array_keys($tabs))->toBe(['セクター配分', '集中度']);
                expect($tabs['セクター配分'])->toContain('href="/sector-dashboard"')->toContain('wire:navigate')->toContain('aria-current="page"');
                expect($tabs['集中度'])->toContain('href="/concentration-dashboard"')->toContain('wire:navigate')->not->toContain('aria-current');
            }
            $response->assertDontSee('集中度ダッシュボード（相関・実効ベット数・対SOXベータ）→');
            $component->assertDontSee('集中度ダッシュボード（相関・実効ベット数・対SOXベータ）→');
        });

        test('UC-015: グローバルナビは6タブのままで、集中度画面でも「セクター配分」が選択中になる', function () {
            // Arrange
            $user = User::factory()->create();

            // Act
            $response = $this->actingAs($user)->get('/concentration-dashboard');

            // Assert
            $tabs = concDashScreenTestGlobalTabs($response->getContent());
            expect(array_keys($tabs))->toBe(['保有一覧', '売買シグナル', 'セクター配分', '新規投資候補', 'CSV取込', 'サマリーレポート']);
            foreach ($tabs as $label => $attributes) {
                if ($label === 'セクター配分') {
                    expect($attributes)->toContain('text-primary')->toContain('bg-blue-50');
                } else {
                    expect($attributes)->not->toContain('text-primary')->not->toContain('bg-blue-50');
                }
            }
        });

        test('UC-005: グローバルナビは6タブのままで、セクター配分画面でも「セクター配分」だけが選択中になる', function () {
            // Arrange
            $user = User::factory()->create();

            // Act
            $response = $this->actingAs($user)->get('/sector-dashboard');

            // Assert
            $response->assertOk();
            $tabs = concDashScreenTestGlobalTabs($response->getContent());
            expect(array_keys($tabs))->toBe(['保有一覧', '売買シグナル', 'セクター配分', '新規投資候補', 'CSV取込', 'サマリーレポート']);
            foreach ($tabs as $label => $attributes) {
                if ($label === 'セクター配分') {
                    expect($attributes)->toContain('text-primary')->toContain('bg-blue-50');
                } else {
                    expect($attributes)->not->toContain('text-primary')->not->toContain('bg-blue-50');
                }
            }
        });
    });
});
