<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\FundamentalHealthEvaluator;
use App\Services\Analysis\TakeProfitThresholdEvaluator;

/*
|--------------------------------------------------------------------------
| TakeProfitThresholdEvaluator — Unit Test (UC-004/UC-009, CHG-0006)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-004業務ルール「利確検討ラインの動的分岐
|     （2026-08-28確定、CHG-0006）」: 銘柄ごとに「通常モード」/「高水準モード」
|     のいずれかを適用する。
|       通常モード（高水準モードの条件を満たさない銘柄）: 含み益+20%超で対象、
|         分割指値は+20%地点で1/3・+35%地点で1/3・残りトレンド追従
|       高水準モード（「現在シグナルが0件」かつ「財務健全性フィルタを満たす」
|         銘柄のみ）: 含み益+150%超で対象、分割指値は+100%地点で1/3・
|         +150%地点で1/3・残りトレンド追従
|   - docs/architecture/data-model.md「保留・確定が必要な初期パラメータ値」
|     「利確検討ラインの動的分岐（高水準モード）」行（叩き台のまま承認、
|     TakeProfitThresholdEvaluatorのGate4実装時に確定する前提）
|   - docs/rcid/traceability-matrix.md CHG-0006
|
| This class does not exist yet under app/Services/Analysis/ (no migration,
| no route involved — it is a pure calculation service like
| FundamentalHealthEvaluator/SignalDeterminationService). Every test below is
| therefore expected to fail with a "Class ... not found" fatal error, not an
| assertion mismatch. This is the intended Red state for this file.
|
| -------------------------------------------------------------------------
| Design decisions this Red phase bakes in (method name / parameter shape /
| return shape are this phase's own proposal, not yet Gate-4-confirmed —
| flag at Gate 4 if a different contract is preferred):
| -------------------------------------------------------------------------
|
|   Class shape: constructor-injects FundamentalHealthEvaluator (same
|   dependency-injection pattern ShowImportSummaryReportAction already uses
|   for FundamentalHealthEvaluator), rather than re-implementing the
|   equity_ratio/roe/growth judgement inline. This keeps a single source of
|   truth for "what counts as financially healthy" shared with UC-005/008/
|   009/010, per data-model.md's explicit cross-reference ("UC-005\008\009\
|   010と共通の基準").
|
|   `evaluate(int $signalCount, ?float $equityRatio, ?float $roe,
|   ?float $revenueGrowth, ?float $operatingIncomeGrowth): array` — the
|   signal condition is passed as a plain `int` count (not the raw
|   `signals`/`SignalDeterminationService::determine()` array shape) because
|   the only fact this class needs is "is the count zero or not" (use-cases.
|   md: "現在シグナルが0件"), and a plain int keeps this class fully
|   DB/collection-independent (matching FundamentalHealthEvaluator's own
|   "plain nullable floats, not an Eloquent model" precedent). Both call
|   sites (ShowSignalListAction's `$holdingSnapshot->signals`, an Eloquent
|   collection, and ShowImportSummaryReportAction's already-queried `$signals`
|   collection) can trivially pass `->count()`.
|
|   Return shape is an associative array (not a small value object/DTO)
|   because every other pure-calculation service in this codebase
|   (SignalDeterminationService::determine(), TechnicalIndicatorCalculator
|   ::calculate()) already returns plain arrays, and the Action-layer callers
|   only need to read a handful of scalar keys once per holding — introducing
|   a dedicated DTO class for this single call site would add ceremony
|   without a corresponding benefit.
|
|   Return keys:
|     - `mode`: 'normal' | 'high_water_mark' — lets a caller build the
|       CHG-0006-mandated `signal_reason_summary` wording ("利確ラインを
|       +150%まで引き上げています") without re-deriving the mode itself from
|       the threshold values.
|     - `target_gain_rate_threshold`: float (20.0 / 150.0) — compared against
|       `unrealized_gain_rate` with a strict `>` (both modes use "超" per
|       use-cases.md), same boundary convention as the existing
|       `ShowSignalListAction`/`ShowImportSummaryReportAction` "+20%超"
|       thresholds (i.e. exactly-equal is NOT included, unchanged by this
|       CR).
|     - `first_tier_price_multiplier` / `second_tier_price_multiplier`:
|       float multipliers meant to be applied directly to `average_cost`
|       (e.g. `average_cost * 1.20` for the normal mode's existing +20%
|       tier), mirroring `ShowSignalListAction::splitLimitSuggestion()`'s
|       existing `$averageCost * 1.20` / `$averageCost * 1.35` literals
|       one-for-one, so the Green-phase change to that method is a minimal
|       "replace the literal with the evaluator's returned multiplier"
|       edit rather than a re-derivation of the price-band math. Normal:
|       1.20/1.35 (unchanged). High water mark: 2.00/2.50 (average_cost×2.00
|       = +100%, average_cost×2.50 = +150%).
|
|   High-water-mode determination precedence: signal count is checked first
|   (short-circuit before ever calling FundamentalHealthEvaluator) because
|   use-cases.md phrases the two conditions as an AND ("シグナルが0件」かつ
|   「財務健全性フィルタを満たす」), and the task instructions explicitly call
|   out "シグナルがある場合は財務健全性に関わらず通常モード" as a case to cover
|   — i.e. a holding with signals present must be `normal` regardless of how
|   healthy its fundamentals are, so the fundamental check must never be
|   allowed to override a non-zero signal count.
|
*/

function takeProfitThresholdEvaluator(): TakeProfitThresholdEvaluator
{
    return new TakeProfitThresholdEvaluator(new FundamentalHealthEvaluator);
}

/**
 * Fundamentals that FundamentalHealthEvaluator judges as 'passed' (same
 * values as FundamentalHealthEvaluatorTest's own "大きく上回る" case:
 * equity_ratio=58.0, roe=15.2, both growth figures comfortably positive).
 *
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function tpteHealthyFundamentals(): array
{
    return [58.0, 15.2, 8.0, 12.3];
}

describe('TakeProfitThresholdEvaluator: 利確検討ラインの動的分岐判定（CHG-0006）', function () {
    describe('高水準モードが適用される場合', function () {
        test('シグナル0件・財務健全性passedの場合、高水準モード（対象抽出+150%超、分割指値+100%/+150%）を返す', function () {
            [$equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth] = tpteHealthyFundamentals();

            $result = takeProfitThresholdEvaluator()->evaluate(0, $equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth);

            expect($result['mode'])->toBe('high_water_mark');
            expect($result['target_gain_rate_threshold'])->toEqualWithDelta(150.0, 0.001);
            expect($result['first_tier_price_multiplier'])->toEqualWithDelta(2.00, 0.001);
            expect($result['second_tier_price_multiplier'])->toEqualWithDelta(2.50, 0.001);
        });
    });

    describe('通常モードが適用される場合（高水準モードの条件を満たさない）', function () {
        test('シグナルが1件以上ある場合、財務健全性がpassedであっても通常モード（+20%超、+20%/+35%）を返す', function () {
            [$equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth] = tpteHealthyFundamentals();

            $result = takeProfitThresholdEvaluator()->evaluate(1, $equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth);

            expect($result['mode'])->toBe('normal');
            expect($result['target_gain_rate_threshold'])->toEqualWithDelta(20.0, 0.001);
            expect($result['first_tier_price_multiplier'])->toEqualWithDelta(1.20, 0.001);
            expect($result['second_tier_price_multiplier'])->toEqualWithDelta(1.35, 0.001);
        });

        test('シグナルが複数件ある場合も、財務健全性がpassedであれば通常モードを返す', function () {
            [$equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth] = tpteHealthyFundamentals();

            $result = takeProfitThresholdEvaluator()->evaluate(3, $equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth);

            expect($result['mode'])->toBe('normal');
        });

        test('シグナル0件だが財務健全性がfailed（自己資本比率・ROEとも基準未満）の場合、通常モードを返す', function () {
            // FundamentalHealthEvaluatorTest「自己資本比率・ROEともに閾値を
            // 下回る場合、failedを返す」と同一値（20.0, 3.0, -5.0, -2.0）。
            $result = takeProfitThresholdEvaluator()->evaluate(0, 20.0, 3.0, -5.0, -2.0);

            expect($result['mode'])->toBe('normal');
            expect($result['target_gain_rate_threshold'])->toEqualWithDelta(20.0, 0.001);
            expect($result['first_tier_price_multiplier'])->toEqualWithDelta(1.20, 0.001);
            expect($result['second_tier_price_multiplier'])->toEqualWithDelta(1.35, 0.001);
        });

        test('シグナル0件だが自己資本比率・ROEが基準を満たし成長率のみfailed（両方マイナス）の場合、通常モードを返す', function () {
            // FundamentalHealthEvaluatorが'failed'を返す境界（自己資本比率・
            // ROEは基準を満たすが成長率が両方マイナス）でも高水準モードには
            // ならないことを確認する。
            $result = takeProfitThresholdEvaluator()->evaluate(0, 58.0, 15.2, -3.0, -1.0);

            expect($result['mode'])->toBe('normal');
        });

        test('シグナル0件だが財務健全性がunavailable（自己資本比率・ROEとも未取得、米国株等）の場合、通常モードを返す', function () {
            $result = takeProfitThresholdEvaluator()->evaluate(0, null, null, null, null);

            expect($result['mode'])->toBe('normal');
            expect($result['target_gain_rate_threshold'])->toEqualWithDelta(20.0, 0.001);
            expect($result['first_tier_price_multiplier'])->toEqualWithDelta(1.20, 0.001);
            expect($result['second_tier_price_multiplier'])->toEqualWithDelta(1.35, 0.001);
        });

        test('シグナル0件だが財務健全性がunavailable（自己資本比率・ROEは基準を満たすが成長率データが両方null）の場合、通常モードを返す', function () {
            $result = takeProfitThresholdEvaluator()->evaluate(0, 58.0, 15.2, null, null);

            expect($result['mode'])->toBe('normal');
        });
    });
});
