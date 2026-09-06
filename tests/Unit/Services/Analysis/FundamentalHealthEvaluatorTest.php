<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\FundamentalHealthEvaluator;

/*
|--------------------------------------------------------------------------
| FundamentalHealthEvaluator — Unit Test (UC-010)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-004/UC-008/UC-009/UC-010/UC-011 業務ルール
|     「ファンダメンタルズ健全性フィルタは、ROE・自己資本比率・成長率（売上高
|     成長率または営業利益成長率のいずれか）・営業利益率が一定水準を満たす
|     銘柄のみを対象とする」。2026-09-06、CHG-0012／ADR-0011 で営業利益率を
|     4条件目として追加)
|   - docs/architecture/data-model.md「保留・確定が必要な初期パラメータ値」
|     の「買い増し用ファンダメンタルズ健全性フィルタ」行: 自己資本比率40%以上・
|     ROE10%以上・成長率>0%・営業利益率10%以上、および
|     `fundamental_indicators` テーブルの `operating_margin` カラム
|     （decimal(10,4), nullable, `after('equity_ratio')`）
|   - docs/adr/ADR-0011-operating-margin-health-criterion.md
|     (D1: 4条件目として `evaluate()` に組み込む / D2: 閾値10%以上 /
|      D6: null は判定に使わず `unavailable` 扱い、判定順序は「基準割れは
|      データ欠損より優先」)
|   - docs/adr/ADR-0007-existing-holding-add-on-buy-recommendation.md D4
|
| -------------------------------------------------------------------------
| CR (2026-09-06, CHG-0012 / ADR-0011): 営業利益率条件の追加（4条件目）
| -------------------------------------------------------------------------
| ADR-0011 の決定に基づき、`FundamentalHealthEvaluator::evaluate()` のシグ
| ネチャに `?float $operatingMargin` を5引数目として追加する。旧シグネチャは
| `evaluate(?float $equityRatio, ?float $roe, ?float $revenueGrowth,
| ?float $operatingIncomeGrowth): string`（4引数）で Green 実装済みのため、
| 本ファイルは新シグネチャ
| `evaluate(?float $equityRatio, ?float $roe, ?float $revenueGrowth,
| ?float $operatingIncomeGrowth, ?float $operatingMargin): string`（5引数）
| を前提に全テストを実行する。
|
| 定数 `MIN_OPERATING_MARGIN = 10.0`（%）を新設する。ROE の 10% と数字を
| 揃え、8%案との実測比較の末に採用された値（ADR-0011 D2。実測影響: 財務
| `passed` が合計 27→22）。10.0 ちょうどは「以上」で passed 側、9.99 は
| failed 側。
|
| 判定順序（ADR-0011 D6。`/review` 修正1〔2026-08-27〕の「基準割れはデータ
| 欠損より優先して即 failed」という不変条件を営業利益率にも適用する）:
|   equityRatio === null || roe === null                              → 'unavailable'
|   equityRatio < MIN_EQUITY_RATIO || roe < MIN_ROE                   → 'failed'
|   operatingMargin !== null && operatingMargin < MIN_OPERATING_MARGIN → 'failed'   ← 追加
|   operatingMargin === null                                          → 'unavailable' ← 追加
|   revenueGrowth === null && operatingIncomeGrowth === null          → 'unavailable'
|   （成長率 OR 判定。既存どおり）
|
| Expected Red state (2026-09-06 — PHP は非可変長メソッドに宣言数より多い
| 位置引数を渡してもエラーにならず余剰引数を黙って捨てる。したがって現行
| 4引数実装に対して本ファイルの5引数呼び出しはすべて legal で、旧ロジックと
| 新ロジックの判定が一致するケース（既存の境界値ケース・営業利益率が健全な
| ケース・equity/roe 基準割れが優先されるケース等）は偶然 Green のまま通る。
| Red になる（アサーション不一致。fatal error ではない）のは、旧ロジックと
| 新ロジックが本当に食い違う以下のケースのみ:
|   - 「営業利益率が9.99%（閾値未満）の場合、他が健全でも failed」
|       — 現行実装は営業利益率引数を無視し 'passed' を返す
|   - 「営業利益率が10.01%（閾値超）の場合、passed」
|       — 現行実装も 'passed'。GREEN のまま（境界仕様の回帰確認用）
|   - 「営業利益率が null（他は健全）の場合、unavailable」
|       — 現行実装は 'passed' を返す
|   - 「営業利益率が10.0%ちょうどの場合、passed」
|       — 現行実装も 'passed'。GREEN のまま（境界仕様の回帰確認用）
| 上記のうち実際に Red になるのは 2 件（9.99→failed / null→unavailable）。
| これが本 CR の意図した Red 状態であり、営業利益率フィルタの穴を正確に
| 切り出す。既存の 4引数時代・2引数時代の Red 状態は下記コメントに保存。
|
| -------------------------------------------------------------------------
| CR (2026-08-25): 成長率条件の追加
| -------------------------------------------------------------------------
| `/review` 指摘（「ファンダメンタルズ健全性フィルタから成長率条件が抜けて
| いる」）を受け、use-cases.md UC-010業務ルールに明記されている成長率条件
| （売上高成長率または営業利益成長率のいずれかが基準を満たす）を評価ロジック
| に追加した。既存実装は `evaluate(?float $equityRatio, ?float $roe): string`
| （2引数、自己資本比率・ROEの2条件のみ）で Green 実装済みだったため、
| このCRで `$revenueGrowth`/`$operatingIncomeGrowth` の2引数を追加した。
|
| -------------------------------------------------------------------------
| Design decisions still in force (documented per the project's TDD rule):
| -------------------------------------------------------------------------
|   すべてのパラメータ（`$operatingMargin` を含む）は plain nullable float
|   （FundamentalIndicator Eloquent モデルではない）。本クラスの「純粋計算・
|   DB非依存」設計に合わせる。Action層の呼び出し元は
|   `$holding->fundamentalIndicator?->operating_margin` を（decimal キャスト
|   由来の文字列を float へ変換した上で）渡す想定。
|
|   'unavailable' は「フィルタ結果を確定できない」を表す（欠損した必須入力が
|   1つでもあるとき）。営業利益率は成長率と同じく、実測値があり基準割れの
|   ときのみ failed、null は unavailable（ADR-0011 D6）。
|
|   'failed' は残余ケース。equity/roe が基準を満たし、営業利益率が実測されて
|   おり（not null）10%以上、成長率データがあり（両方 null ではない）、いずれ
|   かの成長率が厳密に正 — でない場合。">=" が passed（equity/roe/margin）、
|   成長率のみ ">"（ちょうど0%は「横ばい」で「成長」ではない）。
|
| -------------------------------------------------------------------------
| Original rationale (pre-CR, retained for historical context):
| -------------------------------------------------------------------------
|   Why a 3-way string return instead of e.g. a bool + separate
|   "is available" check: UC-010's own output spec already models
|   `fundamental_status` as a 3-state enum-like string ('passed' /
|   'unavailable', with 'failed' implied-but-never-emitted since failed
|   holdings are excluded from the list entirely).
|
|   'unavailable' takes precedence whenever `$equityRatio` or `$roe` is null
|   (an AND-shaped pair — both are always-required inputs).
|
*/

function fheEvaluator(): FundamentalHealthEvaluator
{
    return new FundamentalHealthEvaluator;
}

describe('FundamentalHealthEvaluator: 財務健全性フィルタ判定', function () {
    describe('健全性を満たす場合（passed）', function () {
        test('自己資本比率40.0%・ROE10.0%ちょうど（境界値）、売上高成長率がプラス、営業利益率が健全の場合、passedを返す', function () {
            // data-model.md「買い増し用ファンダメンタルズ健全性フィルタ」:
            // 自己資本比率40%以上・ROE10%以上。ちょうど閾値は「以上」なので
            // passedになる想定。営業利益成長率は未取得だが売上高成長率が
            // プラスのためOR条件を満たす。営業利益率12.0は基準10%以上。
            $result = fheEvaluator()->evaluate(40.0, 10.0, 5.0, null, 12.0);

            expect($result)->toBe('passed');
        });

        test('自己資本比率・ROE・成長率・営業利益率ともに閾値を大きく上回る場合、passedを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, 8.0, 12.3, 18.3);

            expect($result)->toBe('passed');
        });

        test('売上高成長率がプラス・営業利益成長率がマイナスの場合（いずれかプラスの条件を満たす）、営業利益率が健全ならpassedを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, 5.0, -2.0, 15.0);

            expect($result)->toBe('passed');
        });

        test('売上高成長率がnull・営業利益成長率がプラスの場合（片方のみデータありでプラス）、営業利益率が健全ならpassedを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, null, 8.0, 12.0);

            expect($result)->toBe('passed');
        });

        // -------------------------------------------------------------
        // CR (2026-09-06, CHG-0012): 営業利益率の閾値境界（10%以上でpassed）
        // -------------------------------------------------------------
        test('営業利益率が10.0%ちょうど（境界値）の場合、他が健全ならpassedを返す（「以上」でpassed側の境界）', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, 8.0, 12.3, 10.0);

            expect($result)->toBe('passed');
        });

        test('営業利益率が10.01%（閾値をわずかに超過）の場合、他が健全ならpassedを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, 8.0, 12.3, 10.01);

            expect($result)->toBe('passed');
        });
    });

    describe('健全性を満たさない場合（failed）', function () {
        test('自己資本比率が39.99%（閾値未満、境界値）の場合、ROE・成長率・営業利益率が基準を満たしていてもfailedを返す', function () {
            $result = fheEvaluator()->evaluate(39.99, 15.0, 5.0, null, 15.0);

            expect($result)->toBe('failed');
        });

        test('ROEが9.99%（閾値未満、境界値）の場合、自己資本比率・成長率・営業利益率が基準を満たしていてもfailedを返す', function () {
            $result = fheEvaluator()->evaluate(50.0, 9.99, 5.0, null, 15.0);

            expect($result)->toBe('failed');
        });

        test('自己資本比率・ROEともに閾値を下回る場合、failedを返す', function () {
            $result = fheEvaluator()->evaluate(20.0, 3.0, -5.0, -2.0, 5.0);

            expect($result)->toBe('failed');
        });

        test('自己資本比率・ROEは基準を満たすが、売上高成長率・営業利益成長率が両方ともマイナスの場合、営業利益率が健全でもfailedを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, -3.0, -1.0, 15.0);

            expect($result)->toBe('failed');
        });

        test('売上高成長率がゼロちょうど（境界値、プラスではない）で営業利益成長率もマイナスの場合、failedを返す', function () {
            // 成長率条件は自己資本比率/ROE/営業利益率と異なり「>0」(以上では
            // なく超過)。0%は「横ばい」であり「成長」ではないため、ちょうど
            // 0%は failed 側の境界値となる。
            $result = fheEvaluator()->evaluate(58.0, 15.2, 0.0, -1.0, 15.0);

            expect($result)->toBe('failed');
        });

        // -------------------------------------------------------------
        // CR (2026-09-06, CHG-0012 / ADR-0011): 営業利益率の基準割れ・判定順序
        // -------------------------------------------------------------
        test('営業利益率が9.99%（閾値未満、境界値）の場合、他がすべて健全でもfailedを返す', function () {
            // 「10%以上でpassed」の failed 側の境界。ADR-0011 D2。
            $result = fheEvaluator()->evaluate(58.0, 15.2, 8.0, 12.3, 9.99);

            expect($result)->toBe('failed');
        });

        test('営業利益率が7.6%（マツキヨココカラの実測相当）の場合、他が健全でもfailedを返す', function () {
            // ADR-0011 の実測検証で failed に落ちる代表銘柄（3088 7.6%）。
            $result = fheEvaluator()->evaluate(45.0, 12.0, 3.0, 2.0, 7.6);

            expect($result)->toBe('failed');
        });

        test('営業利益率が5.0%（基準割れ）かつ成長率も両方マイナスの場合、failedを返す（営業利益率チェックが成長率チェックより先）', function () {
            // 判定順序の検証: どちらのチェックでも failed だが、営業利益率
            // チェックが成長率 OR 判定より先に評価される（ADR-0011 D6）。
            $result = fheEvaluator()->evaluate(58.0, 15.2, -3.0, -2.0, 5.0);

            expect($result)->toBe('failed');
        });

        test('ROEが9.99%（閾値未満）かつ営業利益率がnull（未取得）の場合、unavailableではなくfailedを返す（基準割れがデータ欠損より優先）', function () {
            // /review 修正1（2026-08-27）の不変条件を営業利益率にも適用:
            // equity/roe の基準割れは、営業利益率が欠損していても即 failed。
            $result = fheEvaluator()->evaluate(50.0, 9.99, 5.0, null, null);

            expect($result)->toBe('failed');
        });

        test('自己資本比率が39.99%（閾値未満）かつ営業利益率がnull（未取得）の場合、unavailableではなくfailedを返す', function () {
            $result = fheEvaluator()->evaluate(39.99, 15.0, 5.0, null, null);

            expect($result)->toBe('failed');
        });

        // -------------------------------------------------------------
        // CR (2026-08-27): /review 指摘・修正1 — チェック順序バグの再発防止
        // -------------------------------------------------------------
        test('自己資本比率が9.99%（閾値未満）で成長率データが両方ともnull（未取得）の場合、unavailableではなくfailedを返す', function () {
            $result = fheEvaluator()->evaluate(9.99, 50.0, null, null, 15.0);

            expect($result)->toBe('failed');
        });

        test('ROEが9.99%（閾値未満）で成長率データが両方ともnull（未取得）の場合、unavailableではなくfailedを返す', function () {
            $result = fheEvaluator()->evaluate(50.0, 9.99, null, null, 15.0);

            expect($result)->toBe('failed');
        });
    });

    describe('指標が未取得の場合（unavailable）', function () {
        test('自己資本比率・ROEともにnull（US株等、ファンダメンタルズ未取得）の場合、unavailableを返す', function () {
            $result = fheEvaluator()->evaluate(null, null, null, null, null);

            expect($result)->toBe('unavailable');
        });

        test('自己資本比率のみnullの場合、ROE・成長率・営業利益率が基準を満たしていてもunavailableを返す', function () {
            $result = fheEvaluator()->evaluate(null, 15.0, 5.0, null, 15.0);

            expect($result)->toBe('unavailable');
        });

        test('ROEのみnullの場合、自己資本比率・成長率・営業利益率が基準を満たしていてもunavailableを返す', function () {
            $result = fheEvaluator()->evaluate(50.0, null, 5.0, null, 15.0);

            expect($result)->toBe('unavailable');
        });

        test('自己資本比率・ROEは基準を満たすが、成長率（売上高・営業利益とも）が両方ともnull（未取得）の場合、営業利益率が健全でもunavailableを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, null, null, 15.0);

            expect($result)->toBe('unavailable');
        });

        // -------------------------------------------------------------
        // CR (2026-09-06, CHG-0012 / ADR-0011): 営業利益率 null → unavailable
        // -------------------------------------------------------------
        test('自己資本比率・ROE・成長率は基準を満たすが、営業利益率がnull（JP当期の営業利益開示なし・US異常値null化等）の場合、unavailableを返す', function () {
            // ADR-0011 D6: 実測値があり10%未満のときのみ failed。null は
            // 既存の成長率と同じく unavailable。
            $result = fheEvaluator()->evaluate(58.0, 15.2, 8.0, 12.3, null);

            expect($result)->toBe('unavailable');
        });

        test('ACHR相当（営業利益率がMapperで異常値null化された）の場合、他が健全でもunavailableを返す', function () {
            // ADR-0011 D5: |営業利益率| > 999% は両 Mapper で null 化される。
            // その結果チップは unavailable（—）表示になる。
            $result = fheEvaluator()->evaluate(55.0, 14.0, 20.0, 25.0, null);

            expect($result)->toBe('unavailable');
        });
    });
});
