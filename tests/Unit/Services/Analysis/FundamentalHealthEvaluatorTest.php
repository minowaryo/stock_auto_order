<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\FundamentalHealthEvaluator;

/*
|--------------------------------------------------------------------------
| FundamentalHealthEvaluator — Unit Test (UC-010)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-010 業務ルール「ファンダメンタルズ健全性
|     フィルタは、ROE・自己資本比率・成長率（売上高成長率または営業利益成長率
|     のいずれか）が一定水準を満たす銘柄のみを対象とする...UC-009の新規投資
|     候補フィルタと同一値を用いて一貫性を保つ」、出力仕様 `fundamental_status`:
|     `passed`(健全性フィルタを満たす) / `unavailable`(ファンダメンタルズ指標
|     が未取得のため判定不可)。フィルタを満たさない銘柄（`failed`相当）は
|     一覧から除外される。出力例
|     「ROE15.2%・自己資本比率58.0%・営業利益成長率+12.3%」)
|   - docs/architecture/data-model.md「保留・確定が必要な初期パラメータ値」
|     の「買い増し用ファンダメンタルズ健全性フィルタ」行: 自己資本比率40%以上・
|     ROE10%以上（UC-009/UC-008の`NewCandidateFinder`
|     ::MIN_EQUITY_RATIO/MIN_ROEと同一値を流用する叩き台）、および
|     `fundamental_indicators`テーブルの`revenue_growth`/
|     `operating_income_growth`カラム（decimal(10,4), nullable, ADR-0006に
|     より`decimal(7,4)`から拡張）
|   - docs/adr/ADR-0007-existing-holding-add-on-buy-recommendation.md
|     (Decision D4: 「ファンダメンタルズ健全性フィルタは表示時（Action層）に
|     適用する...評価ロジックはapp/Services/Analysis/FundamentalHealthEvaluator
|     として、将来的に他の呼び出し元からも使える汎用クラスとして設計する
|     （買い専用に限定した設計にしない）」)
|
| -------------------------------------------------------------------------
| CR (2026-08-25): 成長率条件の追加
| -------------------------------------------------------------------------
| `/review` 指摘（「ファンダメンタルズ健全性フィルタから成長率条件が抜けて
| いる」）を受け、use-cases.md UC-010業務ルールに明記されている成長率条件
| （売上高成長率または営業利益成長率のいずれかが基準を満たす）を評価ロジック
| に追加する。既存実装 app/Services/Analysis/FundamentalHealthEvaluator は
| `evaluate(?float $equityRatio, ?float $roe): string`（2引数、自己資本比率・
| ROEの2条件のみ）でGreen実装済みのため、本ファイルは新シグネチャ
| `evaluate(?float $equityRatio, ?float $roe, ?float $revenueGrowth,
| ?float $operatingIncomeGrowth): string`（4引数）を前提に全テストを実行する。
|
| Verified actual Red cause (2026-08-25 — confirmed by running
| `docker compose exec laravel.test php artisan test
| tests/Unit/Services/Analysis/FundamentalHealthEvaluatorTest.php`, NOT
| merely hypothesized): every call to `fheEvaluator()->evaluate(...)` below
| passes 4 arguments, but the current implementation's method signature only
| declares 2 (`equityRatio`, `roe`). PHP does NOT raise an ArgumentCountError
| for this — passing *more* positional arguments than a non-variadic method
| declares is legal in PHP (only *too few* required arguments errors); the
| 2 extra arguments are simply discarded, and `evaluate()` silently falls
| back to its old equityRatio/roe-only judgement. Consequently, most of the
| tests below (including the untouched-in-spirit boundary cases and even
| some of the newly added growth-specific cases, e.g. "growth both plus" or
| "one growth null one plus") happen to pass anyway, since old-logic and
| new-logic agree whenever the equityRatio/roe-only verdict already matches
| the growth-aware verdict. Only the 3 cases where old logic and new logic
| genuinely disagree turn Red with a plain assertion mismatch (not a fatal
| error):
|   - "自己資本比率・ROEは基準を満たすが、売上高成長率・営業利益成長率が
|     両方ともマイナスの場合、failedを返す" — actual: 'passed' (old logic
|     ignores growth and only sees equityRatio/roe passing)
|   - "売上高成長率がゼロちょうど...で営業利益成長率もマイナスの場合、
|     failedを返す" — actual: 'passed' (same reason)
|   - "自己資本比率・ROEは基準を満たすが、成長率が両方ともnullの場合、
|     unavailableを返す" — actual: 'passed' (old logic never inspects the
|     growth arguments at all, so it cannot detect they are both missing)
| This is the intentional, expected Red state for this CR — it precisely
| isolates the growth-condition gap the `/review` finding identified,
| distinct from the original Red state (class not found) recorded below for
| historical reference.
|
| -------------------------------------------------------------------------
| Design decision this CR bakes in (documented per the project's TDD rule
| that the rationale be recorded in the test file, since the exact method
| signature/threshold semantics are this Red phase's own proposal, not yet
| Gate-4-confirmed):
| -------------------------------------------------------------------------
|   New parameters `$revenueGrowth`/`$operatingIncomeGrowth` are plain
|   nullable floats (not a FundamentalIndicator Eloquent model), consistent
|   with the existing `$equityRatio`/`$roe` parameters and with this class's
|   "pure calculation, no DB dependency" design (see the original rationale
|   below). The eventual Action-layer caller is expected to pass
|   `$holding->fundamentalIndicator?->revenue_growth` /
|   `$holding->fundamentalIndicator?->operating_income_growth`.
|
|   'unavailable' now additionally covers the case where BOTH growth figures
|   are null (growth data entirely unavailable) — but NOT when only one of
|   the two is null. Rationale: use-cases.md phrases the growth condition as
|   an OR ("売上高成長率または営業利益成長率のいずれか"), meaning a single
|   available growth figure is sufficient to reach a judgement; only when
|   neither figure is available is the growth condition truly
|   indeterminate. This mirrors the class's existing precedent of treating
|   'unavailable' as "cannot determine the filter result" (see the original
|   equityRatio/roe rationale below), extended to the OR-shaped growth pair
|   rather than requiring both to be null before falling back to
|   equityRatio/roe null-checks alone.
|
|   'passed' requires the growth OR-condition to be satisfied using
|   whichever of the two growth figures is non-null (a null figure is simply
|   not counted toward the OR — it is never treated as satisfying ">0" on
|   its own). This lets a holding with only one of the two growth metrics
|   available (e.g. revenue_growth present, operating_income_growth not yet
|   fetched) still pass the filter, consistent with the "unavailable" design
|   decision above (a single available growth figure is sufficient to reach
|   a judgement, not just to avoid 'unavailable').
|
|   'failed' is the residual case: equityRatio/roe both meet their bars,
|   growth data exists (not both null), but neither available growth figure
|   is strictly positive (e.g. both zero/negative, or one zero and the other
|   negative) — mirrors the existing equityRatio/roe "meets bar" boundary
|   convention (">=" is passed, so plain ">" for growth means exactly 0% is
|   the failing boundary, not the passing one — a 0% growth rate is
|   "flat", not "growth").
|
| -------------------------------------------------------------------------
| Original rationale (pre-CR, retained for historical context — the 3-way
| string return / plain-scalar-parameter / "unavailable on any single null"
| design decisions below still apply to $equityRatio/$roe unchanged by this
| CR):
| -------------------------------------------------------------------------
|   Why a 3-way string return instead of e.g. a bool + separate
|   "is available" check: UC-010's own output spec already models
|   `fundamental_status` as a 3-state enum-like string ('passed' /
|   'unavailable', with 'failed' implied-but-never-emitted since failed
|   holdings are excluded from the list entirely) — mirroring that shape at
|   the evaluator boundary lets the Action-layer caller (ADR-0007 D4:
|   "表示時（Action層）に適用する") do a trivial switch/match on the return
|   value instead of re-deriving the 3-way distinction from separate
|   booleans, which would let them get out of sync (e.g. `$passed=true,
|   $hasData=false`, an impossible-but-representable state) — dovetails with
|   ADR-0007's requirement that this class be "汎用クラスとして設計する
|   （買い専用に限定した設計にしない）".
|
|   `$equityRatio`/`$roe` (and now `$revenueGrowth`/`$operatingIncomeGrowth`)
|   are passed as plain nullable floats so this class stays pure/
|   DB-independent, consistent with BuySignalDeterminationService/
|   SignalDeterminationService/TechnicalIndicatorCalculator all taking plain
|   scalars/arrays rather than Eloquent models.
|
|   'unavailable' takes precedence whenever `$equityRatio` or `$roe` is null
|   (an AND-shaped pair — both are always-required inputs per
|   data-model.md's "自己資本比率40%以上・ROE10%以上"): data-model.md's
|   `fundamental_indicators` table allows either column to independently be
|   null (e.g. a fetch that partially failed), and UC-010's
|   `fundamental_status=unavailable` case ("ファンダメンタルズ指標が未取得の
|   ため判定不可") reads as "cannot determine the filter result" for any
|   single missing required input, not only total absence.
|
*/

function fheEvaluator(): FundamentalHealthEvaluator
{
    return new FundamentalHealthEvaluator;
}

describe('FundamentalHealthEvaluator: 財務健全性フィルタ判定', function () {
    describe('健全性を満たす場合（passed）', function () {
        test('自己資本比率40.0%・ROE10.0%ちょうど（境界値）、売上高成長率がプラスの場合、passedを返す', function () {
            // data-model.md「買い増し用ファンダメンタルズ健全性フィルタ」:
            // 自己資本比率40%以上・ROE10%以上（NewCandidateFinderの
            // MIN_EQUITY_RATIO/MIN_ROEと同一値の叩き台）。ちょうど閾値は
            // 「以上」なのでpassedになる想定。営業利益成長率は未取得だが
            // 売上高成長率がプラスのためOR条件を満たす。
            $result = fheEvaluator()->evaluate(40.0, 10.0, 5.0, null);

            expect($result)->toBe('passed');
        });

        test('自己資本比率・ROE・成長率（売上高/営業利益とも）ともに閾値を大きく上回る場合、passedを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, 8.0, 12.3);

            expect($result)->toBe('passed');
        });

        test('売上高成長率がプラス・営業利益成長率がマイナスの場合（いずれかプラスの条件を満たす）、passedを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, 5.0, -2.0);

            expect($result)->toBe('passed');
        });

        test('売上高成長率がnull・営業利益成長率がプラスの場合（片方のみデータありでプラス）、passedを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, null, 8.0);

            expect($result)->toBe('passed');
        });
    });

    describe('健全性を満たさない場合（failed）', function () {
        test('自己資本比率が39.99%（閾値未満、境界値）の場合、ROE・成長率が基準を満たしていてもfailedを返す', function () {
            $result = fheEvaluator()->evaluate(39.99, 15.0, 5.0, null);

            expect($result)->toBe('failed');
        });

        test('ROEが9.99%（閾値未満、境界値）の場合、自己資本比率・成長率が基準を満たしていてもfailedを返す', function () {
            $result = fheEvaluator()->evaluate(50.0, 9.99, 5.0, null);

            expect($result)->toBe('failed');
        });

        test('自己資本比率・ROEともに閾値を下回る場合、failedを返す', function () {
            $result = fheEvaluator()->evaluate(20.0, 3.0, -5.0, -2.0);

            expect($result)->toBe('failed');
        });

        test('自己資本比率・ROEは基準を満たすが、売上高成長率・営業利益成長率が両方ともマイナスの場合、failedを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, -3.0, -1.0);

            expect($result)->toBe('failed');
        });

        test('売上高成長率がゼロちょうど（境界値、プラスではない）で営業利益成長率もマイナスの場合、failedを返す', function () {
            // 成長率条件は自己資本比率/ROEと異なり「>0」(以上ではなく超過)。
            // 0%は「横ばい」であり「成長」ではないため、ちょうど0%は
            // failed側の境界値となる。
            $result = fheEvaluator()->evaluate(58.0, 15.2, 0.0, -1.0);

            expect($result)->toBe('failed');
        });

        // -------------------------------------------------------------
        // CR (2026-08-27): /review 指摘・修正1 — チェック順序バグの再発防止
        // -------------------------------------------------------------
        // 現在の実装は、成長率が両方nullかどうかのチェック（'unavailable'を
        // 返す）を、equityRatio/roeの閾値判定より先に行っている。
        // equityRatio/roeが明らかに閾値未満（failedになるべき）であっても、
        // 成長率データが両方未取得なだけで'unavailable'が返ってしまい、
        // UC-010の一覧表示では'unavailable'が表示対象・'failed'が除外対象
        // であるため、財務的に不健全な銘柄が候補として表示されてしまう。
        // 以下2件は、equityRatio/roeの閾値判定が成長率のnullチェックより
        // 優先されるべきことを検証する（現状は誤って'unavailable'が返る）。
        test('自己資本比率が9.99%（閾値未満）で成長率データが両方ともnull（未取得）の場合、unavailableではなくfailedを返す', function () {
            $result = fheEvaluator()->evaluate(9.99, 50.0, null, null);

            expect($result)->toBe('failed');
        });

        test('ROEが9.99%（閾値未満）で成長率データが両方ともnull（未取得）の場合、unavailableではなくfailedを返す', function () {
            $result = fheEvaluator()->evaluate(50.0, 9.99, null, null);

            expect($result)->toBe('failed');
        });
    });

    describe('指標が未取得の場合（unavailable）', function () {
        test('自己資本比率・ROEともにnull（US株等、ファンダメンタルズ未取得）の場合、unavailableを返す', function () {
            $result = fheEvaluator()->evaluate(null, null, null, null);

            expect($result)->toBe('unavailable');
        });

        test('自己資本比率のみnullの場合、ROE・成長率が基準を満たしていてもunavailableを返す', function () {
            $result = fheEvaluator()->evaluate(null, 15.0, 5.0, null);

            expect($result)->toBe('unavailable');
        });

        test('ROEのみnullの場合、自己資本比率・成長率が基準を満たしていてもunavailableを返す', function () {
            $result = fheEvaluator()->evaluate(50.0, null, 5.0, null);

            expect($result)->toBe('unavailable');
        });

        test('自己資本比率・ROEは基準を満たすが、成長率（売上高・営業利益とも）が両方ともnull（未取得）の場合、unavailableを返す', function () {
            $result = fheEvaluator()->evaluate(58.0, 15.2, null, null);

            expect($result)->toBe('unavailable');
        });
    });
});
