<?php

namespace Tests\Unit\Services\Portfolio;

use App\Services\Portfolio\ContinuousHoldingWeeksCalculator;

/*
|--------------------------------------------------------------------------
| ContinuousHoldingWeeksCalculator — Unit Test (UC-011 / F-011, CHG-0010)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-011 出力
|       `continuous_holding_weeks` / `continuous_holding_weeks_is_truncated`
|   - docs/architecture/data-model.md「分析ロジックの計算仕様」節
|       「整理検討候補一覧の算出項目」表の `continuous_holding_weeks` 行:
|       「`snapshots` を `snapshotted_at` 降順で並べ、対象 `holding_id` の
|        `holding_snapshots` が直近から連続して存在する数を数える。最古まで
|        連続なら `continuous_holding_weeks_is_truncated=true`。純粋計算は
|        `ContinuousHoldingWeeksCalculator` に分離」
|   - docs/adr/ADR-0010-loss-review-candidate-list.md D9
|
| App\Services\Portfolio\ContinuousHoldingWeeksCalculator does not exist yet
| (pure calculation service, no DB/HTTP dependency — same shape as
| PortfolioEvaluationCalculator). Every test below is therefore expected to
| fail with a "Class ... not found" fatal error, not an assertion mismatch.
| That is the intended Red state.
|
| -------------------------------------------------------------------------
| Contract this Red phase proposes (flag at Gate 4 if a different shape is
| preferred):
| -------------------------------------------------------------------------
|   - Constructor takes no arguments (`new ContinuousHoldingWeeksCalculator`).
|   - Single public method:
|       calculate(
|         array $allSnapshotIdsNewestFirst,   // every snapshot id, ordered
|                                             // snapshotted_at desc, id desc
|         array $holdingPresentSnapshotIds,   // snapshot ids where THIS
|                                             // holding has a holding_snapshots
|                                             // row (order irrelevant)
|       ): array{weeks: int, is_truncated: bool}
|   - `weeks`: number of snapshots, counting from the newest, in which the
|     holding appears *consecutively* (stops at the first gap).
|   - `is_truncated`: true when that consecutive run reaches the oldest
|     snapshot in $allSnapshotIdsNewestFirst (i.e. the real holding period is
|     at least this long, probably longer — observation started mid-hold).
|   - Defensive: empty $allSnapshotIdsNewestFirst → weeks 0, is_truncated
|     false.
*/

function continuousHoldingWeeksCalculator(): ContinuousHoldingWeeksCalculator
{
    return new ContinuousHoldingWeeksCalculator;
}

describe('ContinuousHoldingWeeksCalculator（UC-011 推定連続保有週数）', function () {
    test('全スナップショットに出現している場合、weeks は総数・is_truncated は true', function () {
        $result = continuousHoldingWeeksCalculator()->calculate([30, 20, 10], [10, 20, 30]);

        expect($result['weeks'])->toBe(3);
        expect($result['is_truncated'])->toBeTrue();
    });

    test('直近2スナップショットに出現・最古には無い場合、weeks は 2・is_truncated は false', function () {
        $result = continuousHoldingWeeksCalculator()->calculate([30, 20, 10], [30, 20]);

        expect($result['weeks'])->toBe(2);
        expect($result['is_truncated'])->toBeFalse();
    });

    test('直近1スナップショットのみ出現の場合、weeks は 1・is_truncated は false', function () {
        $result = continuousHoldingWeeksCalculator()->calculate([30, 20, 10], [30]);

        expect($result['weeks'])->toBe(1);
        expect($result['is_truncated'])->toBeFalse();
    });

    test('スナップショットが1つだけ・そこに出現している場合、weeks は 1・is_truncated は true', function () {
        $result = continuousHoldingWeeksCalculator()->calculate([10], [10]);

        expect($result['weeks'])->toBe(1);
        expect($result['is_truncated'])->toBeTrue();
    });

    test('連続が途切れている場合（最新に出現・2つ目に無し・3つ目に出現）、最新から連続する数のみ数える', function () {
        $result = continuousHoldingWeeksCalculator()->calculate([30, 20, 10], [30, 10]);

        expect($result['weeks'])->toBe(1);
        expect($result['is_truncated'])->toBeFalse();
    });

    test('スナップショットが1件も無い場合、防御的に weeks 0・is_truncated false を返す', function () {
        $result = continuousHoldingWeeksCalculator()->calculate([], []);

        expect($result['weeks'])->toBe(0);
        expect($result['is_truncated'])->toBeFalse();
    });
});
