<?php

namespace Tests\Feature;

use App\Actions\Import\ImportTradeHistoryAction;
use App\Livewire\TradeHistory\Import;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\HoldingSnapshotAccount;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Models\TradeReconciliationItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UC-017 売買履歴の取込画面 — Red phase (CHG-0033 Cycle 5)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-017（入力・基本フロー1〜5・出力・異常時・
|     状態変更・権限）。UC-001の取込画面から履歴専用の取込画面へリンクする
|   - docs/adr/ADR-0027-trade-history-storage.md
|   - .claude/rules/15-frontend.md（ロジックはAction/Serviceに委譲）
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - Route GET /trade-history-import (auth middleware), Livewire full-page
|     component App\\Livewire\\TradeHistory\\Import. The top navigation is not
|     changed; the screen is reached from the UC-001 CSV取込 screen via a
|     link labelled 「売買履歴の取込へ」 (wire:navigate), and its page header
|     links back to /csv-import. The nav highlights 「CSV取込」 on both screens.
|   - Properties: jp_trade_file, us_trade_file (both required, extensions csv,
|     max 5120 KB, same wording as UC-001 for the required / extension
|     errors). Actions:
|       preview()  — validate, call ImportTradeHistoryAction::preview(),
|                    show the counts; writes nothing
|       confirm()  — call ImportTradeHistoryAction::execute() for the same
|                    files; allowed only after a successful preview; shows the
|                    result in place (no redirect)
|     Selecting a different file after a preview clears the preview, so the
|     confirmed files are always the previewed ones.
|   - The preview shows: 対象期間, 総行数, 新規／既存／確認待ち（消える）／
|     読み飛ばした行 の件数. A failed preview (CsvStructureException) shows
|     「読み取れませんでした: {reason}」 and offers no confirm button.
|   - The result shows the same counts plus the reconciliation: the date of
|     the holdings snapshot used (DisplayTime::date) and the four status
|     counts (照合済み／保有側のみ／履歴側のみ／確認待ち), or 「保有CSVが
|     まだ取り込まれていないため、照合は行っていません」 when there is none.
|     Below it, a table of the non-matched items of this import only
|     (銘柄コード・口座・履歴の株数・保有CSVの株数・状態・理由), at most 50 rows
|     with a 「ほか{n}件」 note beyond that.
|   - The page also lists the 10 most recent trade imports (取込日時, 国内/
|     米国ファイル名, 状態, 新規, 確認待ち, 照合の件数なし) and shows an
|     empty-state text when there are none.
|   - Single user app: any authenticated user may use it; guests are
|     redirected to /login. Policies are not introduced here (same as UC-001).
|
| Expected Red: route, component, view and the UC-001 link do not exist yet.
|
*/

const TIUI_JP_HEADER = '約定日,受渡日,銘柄コード,銘柄名,市場名称,口座区分,取引区分,売買区分,信用区分,弁済期限,数量［株］,単価［円］,手数料［円］,税金等［円］,諸費用［円］,税区分,受渡金額［円］,建約定日,建単価［円］,建手数料［円］,建手数料消費税［円］,金利（支払）〔円〕,金利（受取）〔円〕,逆日歩／特別空売り料（支払）〔円〕,逆日歩（受取）〔円〕,貸株料,事務管理費〔円〕（税抜）,名義書換料〔円〕（税抜）';

const TIUI_US_HEADER = '約定日,受渡日,ティッカー,銘柄名,口座,取引区分,売買区分,信用区分,弁済期限,決済通貨,数量［株］,単価［USドル］,約定代金［USドル］,為替レート,手数料［USドル］,税金［USドル］,受渡金額［USドル］,受渡金額［円］';

/**
 * @param  array<string, string>  $over
 */
function tiuiJp(array $over = []): string
{
    $row = array_merge([
        '約定日' => '2026/9/1', '受渡日' => '2026/9/3', '銘柄コード' => '1234', '銘柄名' => 'テスト工業',
        '市場名称' => '東証', '口座区分' => '特定', '取引区分' => '現物', '売買区分' => '買付',
        '信用区分' => '-', '弁済期限' => '-', '数量［株］' => '100', '単価［円］' => '1,000.0',
        '手数料［円］' => '0', '税金等［円］' => '0', '諸費用［円］' => '0', '税区分' => '-',
        '受渡金額［円］' => '100,000',
        '建約定日' => '-', '建単価［円］' => '0.0', '建手数料［円］' => '0', '建手数料消費税［円］' => '0',
        '金利（支払）〔円〕' => '0', '金利（受取）〔円〕' => '0', '逆日歩／特別空売り料（支払）〔円〕' => '0',
        '逆日歩（受取）〔円〕' => '0', '貸株料' => '0', '事務管理費〔円〕（税抜）' => '0', '名義書換料〔円〕（税抜）' => '0',
    ], $over);

    return implode(',', array_map(fn (string $c) => str_contains($c, ',') ? '"'.$c.'"' : $c, array_values($row)));
}

/**
 * @param  array<int, string>  $lines
 */
function tiuiFile(string $name, string $header, array $lines): UploadedFile
{
    $utf8 = implode("\n", [$header, ...$lines])."\n";

    return UploadedFile::fake()->createWithContent($name, mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8'));
}

/**
 * @param  array<int, string>  $jpLines
 * @return array{0: UploadedFile, 1: UploadedFile}
 */
function tiuiFiles(array $jpLines = [], ?string $usHeader = null): array
{
    return [
        tiuiFile('tradehistory(JP).csv', TIUI_JP_HEADER, $jpLines),
        tiuiFile('tradehistory(US).csv', $usHeader ?? TIUI_US_HEADER, []),
    ];
}

/**
 * One holdings snapshot (UC-001 output) with the given specific-account positions.
 *
 * @param  array<string, float|int>  $positions  symbol code => quantity
 */
function tiuiSnapshot(string $at, array $positions): Snapshot
{
    $batch = ImportBatch::create([
        'status' => 'completed', 'jp_stock_filename' => 'jp.csv', 'us_stock_filename' => 'us.csv',
        'mutual_fund_filename' => null, 'imported_count' => count($positions), 'error_count' => 0, 'imported_at' => $at,
    ]);
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => $at]);

    foreach ($positions as $code => $quantity) {
        $holding = Holding::firstOrCreate(
            ['symbol_code' => (string) $code, 'market' => 'jp'],
            ['instrument_type' => 'stock', 'symbol_name' => '銘柄'.$code, 'first_detected_at' => $at],
        );
        $hs = HoldingSnapshot::create([
            'snapshot_id' => $snapshot->id, 'holding_id' => $holding->id, 'quantity' => $quantity,
            'average_cost' => 1000, 'current_price' => 1000, 'fx_rate_used' => null,
            'unrealized_gain_amount' => 0, 'unrealized_gain_rate' => 0, 'is_newly_detected' => false,
        ]);
        HoldingSnapshotAccount::create(['holding_snapshot_id' => $hs->id, 'account_type' => 'specific', 'quantity' => $quantity, 'average_cost' => 1000]);
    }

    return $snapshot;
}

function tiuiUser(): User
{
    return User::factory()->create();
}

/**
 * Visible text of the rendered component (tags stripped, whitespace folded).
 * Livewire's assertSeeInOrder() inspects the JSON-escaped payload, which
 * cannot match Japanese text, so label-then-value checks use this instead.
 */
function tiuiText(Testable $component): string
{
    return preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags($component->html())))) ?? '';
}

describe('UC-017 売買履歴の取込画面: アクセスと導線', function () {
    test('未認証ユーザーはログイン画面へリダイレクトされる', function () {
        // Act & Assert
        $this->get('/trade-history-import')->assertRedirect('/login');
    });

    test('認証済みユーザーは画面を開け、2つのファイル欄と、CSV取込画面へ戻るリンクが表示される', function () {
        // Act & Assert
        $this->actingAs(tiuiUser())->get('/trade-history-import')
            ->assertOk()
            ->assertSee('売買履歴の取込')
            ->assertSee('国内株式の売買履歴CSV')
            ->assertSee('米国株式の売買履歴CSV')
            ->assertSee('href="/csv-import"', false);
    });

    test('UC-001のCSV取込画面に、売買履歴の取込画面へのリンクがある', function () {
        // Act & Assert
        $this->actingAs(tiuiUser())->get('/csv-import')
            ->assertOk()
            ->assertSee('売買履歴の取込へ')
            ->assertSee('href="/trade-history-import"', false);
    });
});

describe('UC-017 売買履歴の取込画面: 入力の検証', function () {
    test('ファイルが未選択のときは、必須のエラーを表示し、プレビューも取込もしない', function () {
        // Act & Assert
        Livewire::actingAs(tiuiUser())->test(Import::class)
            ->call('runPreview')
            ->assertHasErrors(['jp_trade_file' => 'required', 'us_trade_file' => 'required'])
            ->assertSee('国内株式・米国株式の売買履歴CSVは両方アップロードしてください');

        expect(TradeImportBatch::count())->toBe(0);
    });

    test('CSV以外の拡張子のファイルは受け付けない', function () {
        // Arrange
        [, $us] = tiuiFiles();
        $notCsv = UploadedFile::fake()->create('history.pdf', 10, 'application/pdf');

        // Act & Assert
        Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $notCsv)
            ->set('us_trade_file', $us)
            ->call('runPreview')
            ->assertHasErrors(['jp_trade_file'])
            ->assertSee('CSVファイルのみアップロードできます');
    });

    test('5MBを超えるファイルは受け付けない', function () {
        // Arrange
        [, $us] = tiuiFiles();
        $oversized = UploadedFile::fake()->create('jp.csv', 5121, 'text/csv');

        // Act & Assert
        Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $oversized)
            ->set('us_trade_file', $us)
            ->call('runPreview')
            ->assertHasErrors(['jp_trade_file' => 'max']);
    });
});

describe('UC-017 売買履歴の取込画面: プレビュー', function () {
    test('プレビューは対象期間と、新規・既存・確認待ち・読み飛ばしの件数を表示し、データベースを変えない', function () {
        // Arrange: one trade already stored, then a file with it + a new one + a broken row
        [$jp, $us] = tiuiFiles([tiuiJp()]);
        Livewire::actingAs(tiuiUser())->test(Import::class)->set('jp_trade_file', $jp)->set('us_trade_file', $us)->call('runPreview')->call('confirm');
        $batchesBefore = TradeImportBatch::count();
        $executionsBefore = TradeExecution::orderBy('id')->get()->toArray();
        [$jp2, $us2] = tiuiFiles([tiuiJp(['銘柄コード' => '5678', '約定日' => '2026/9/10', '受渡日' => '2026/9/12']), tiuiJp(['約定日' => '2026/13/40'])]);

        // Act
        $component = Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $jp2)->set('us_trade_file', $us2)
            ->call('runPreview');

        // Assert: counts (new 1, existing 0, missing 1 = the stored 1234 row, skipped 1)
        $component->assertSee('2026-09-10')->assertSee('総行数')->assertSee('取込を確定');
        expect(tiuiText($component))->toMatch('/新規 ?1(?!\d)/u')
            ->toMatch('/確認待ち（[^）]*） ?1(?!\d)/u')
            ->toMatch('/読み飛ばし ?1(?!\d)/u');

        // Assert: nothing written by the preview
        expect(TradeImportBatch::count())->toBe($batchesBefore);
        expect(TradeExecution::orderBy('id')->get()->toArray())->toBe($executionsBefore);
    });

    test('形式の違うファイルはプレビューで読み取れなかった理由を表示し、取込を確定するボタンを出さず、何も保存しない', function () {
        // Arrange
        [$jp] = tiuiFiles([tiuiJp()]);
        $brokenUs = tiuiFile('tradehistory(US).csv', '列A,列B', ['1,2']);

        // Act
        $component = Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $jp)->set('us_trade_file', $brokenUs)
            ->call('runPreview');

        // Assert
        $component->assertSee('読み取れませんでした')->assertDontSee('取込を確定');
        expect(TradeImportBatch::count())->toBe(0);
    });

    test('プレビューのあとにファイルを選び直すと、プレビューは消え、確定ボタンも消える', function () {
        // Arrange
        [$jp, $us] = tiuiFiles([tiuiJp()]);
        $component = Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $jp)->set('us_trade_file', $us)->call('runPreview')
            ->assertSee('取込を確定');

        // Act
        [$other] = tiuiFiles([tiuiJp(['銘柄コード' => '9999'])]);
        $component->set('jp_trade_file', $other);

        // Assert
        $component->assertDontSee('取込を確定');
    });

    test('ブラウザ側からプレビューの状態を書き換えても、プレビューなしで確定することはできない', function () {
        // Arrange
        [$jp, $us] = tiuiFiles([tiuiJp()]);
        $component = Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $jp)->set('us_trade_file', $us);

        // Act: a tampered client sets the server-held preview state directly
        $tamper = fn () => $component->set('preview', ['totalRows' => 1, 'newRows' => 1, 'existingRows' => 0, 'missingRows' => 0, 'errorCount' => 0, 'periodFrom' => null, 'periodTo' => null, 'reconciliation' => null]);

        // Assert: the property is locked, and nothing was imported
        expect($tamper)->toThrow(CannotUpdateLockedPropertyException::class);
        $component->call('confirm');
        expect(TradeImportBatch::count())->toBe(0);
    });

    test('プレビューをしていない状態で確定を呼んでも、取込は行われない', function () {
        // Arrange
        [$jp, $us] = tiuiFiles([tiuiJp()]);

        // Act
        Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $jp)->set('us_trade_file', $us)
            ->call('confirm');

        // Assert
        expect(TradeImportBatch::count())->toBe(0);
        expect(TradeExecution::count())->toBe(0);
    });
});

describe('UC-017 売買履歴の取込画面: 確定と結果', function () {
    test('プレビューのあとに確定すると、取込が保存され、結果に件数が表示される', function () {
        // Arrange
        [$jp, $us] = tiuiFiles([tiuiJp(), tiuiJp(['銘柄コード' => '5678'])]);

        // Act
        $component = Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $jp)->set('us_trade_file', $us)
            ->call('runPreview')->call('confirm');

        // Assert
        expect(TradeImportBatch::sole()->status)->toBe('completed');
        expect(TradeExecution::count())->toBe(2);
        $component->assertSee('取込が完了しました');
        expect(tiuiText($component))->toMatch('/新規 ?2(?!\d)/u');
    });

    test('保有CSVがまだない場合は、照合を行っていないことを表示する', function () {
        // Arrange
        [$jp, $us] = tiuiFiles([tiuiJp()]);

        // Act & Assert
        Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $jp)->set('us_trade_file', $us)
            ->call('runPreview')->call('confirm')
            ->assertSee('保有CSVがまだ取り込まれていないため、照合は行っていません');
    });

    test('照合結果として、使った保有CSVの日付と4つの区分の件数、一致しなかった銘柄の明細を表示する', function () {
        // Arrange: snapshot 2026-10-03 holds 200 of 7777 (not in history) and 100 of 1234 (matches); history also has 5555 (history only)
        tiuiSnapshot('2026-10-03 12:00:00', ['1234' => 100, '7777' => 200]);
        [$jp, $us] = tiuiFiles([tiuiJp(), tiuiJp(['銘柄コード' => '5555'])]);

        // Act
        $component = Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $jp)->set('us_trade_file', $us)
            ->call('runPreview')->call('confirm');

        // Assert: which snapshot, the four counts
        $component->assertSee('2026-10-03');
        expect(tiuiText($component))->toMatch('/照合済み ?1(?!\d)/u')
            ->toMatch('/保有側のみ ?1(?!\d)/u')
            ->toMatch('/履歴側のみ ?1(?!\d)/u')
            ->toMatch('/確認待ち ?0(?!\d)/u');

        // Assert: details of the non-matched items only
        $component->assertSee('7777')->assertSee('5555')->assertDontSee('1234');
        expect(TradeReconciliationItem::count())->toBe(3);
    });

    test('一致しなかった明細が50件を超える場合は、50件までを表示し、残りの件数を「ほか」で示す', function () {
        // Arrange: 52 history-only holdings (no snapshot holdings for them) + a snapshot so reconciliation runs
        tiuiSnapshot('2026-10-03 12:00:00', ['0001' => 1]);
        $lines = [];
        for ($i = 1; $i <= 52; $i++) {
            $lines[] = tiuiJp(['銘柄コード' => sprintf('9%03d', $i)]);
        }
        [$jp, $us] = tiuiFiles($lines);

        // Act
        $component = Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $jp)->set('us_trade_file', $us)
            ->call('runPreview')->call('confirm');

        // Assert: 52 history_only + 1 snapshot_only = 53 → 50 shown, ほか3件
        $component->assertSee('ほか3件');
    });

    test('結果の明細は今回の取込のものだけで、前回の取込の照合結果は混ざらない', function () {
        // Arrange: first import leaves a snapshot_only item for 7777; second import has it matched
        tiuiSnapshot('2026-10-03 12:00:00', ['7777' => 100]);
        [$jp1, $us1] = tiuiFiles([tiuiJp(['銘柄コード' => '1111'])]);
        Livewire::actingAs(tiuiUser())->test(Import::class)->set('jp_trade_file', $jp1)->set('us_trade_file', $us1)->call('runPreview')->call('confirm');
        [$jp2, $us2] = tiuiFiles([tiuiJp(['銘柄コード' => '7777'])]);

        // Act
        $component = Livewire::actingAs(tiuiUser())->test(Import::class)
            ->set('jp_trade_file', $jp2)->set('us_trade_file', $us2)
            ->call('runPreview')->call('confirm');

        // Assert: the second result shows no non-matched detail rows
        $component->assertSee('一致しなかった銘柄はありません')->assertDontSee('1111');
    });
});

describe('UC-017 売買履歴の取込画面: 取込履歴', function () {
    test('取込がまだない場合は、空の状態を表示する', function () {
        // Act & Assert
        $this->actingAs(tiuiUser())->get('/trade-history-import')
            ->assertOk()
            ->assertSee('まだ売買履歴の取込はありません');
    });

    test('取込履歴に、直近10件の取込日時・ファイル名・状態・件数が新しい順に表示される', function () {
        // Arrange: 11 imports, the first with a distinctive file name
        for ($i = 1; $i <= 11; $i++) {
            $jp = tiuiFile($i === 1 ? 'oldest-JP.csv' : "history-{$i}-JP.csv", TIUI_JP_HEADER, [tiuiJp(['銘柄コード' => sprintf('8%03d', $i)])]);
            $us = tiuiFile("history-{$i}-US.csv", TIUI_US_HEADER, []);
            app(ImportTradeHistoryAction::class)->execute($jp, $us);
        }

        // Act
        $response = $this->actingAs(tiuiUser())->get('/trade-history-import');

        // Assert
        $response->assertOk()->assertSee('history-11-JP.csv')->assertSee('history-2-JP.csv')->assertDontSee('oldest-JP.csv');
        $response->assertSeeInOrder(['history-11-JP.csv', 'history-10-JP.csv']);
    });
});
