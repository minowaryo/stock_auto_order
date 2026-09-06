<?php

namespace App\Services\Analysis;

/**
 * Single source of truth for the three NEW thresholds introduced by F-011 /
 * UC-011 (整理検討候補一覧, CHG-0010 / ADR-0010). The other loss-review
 * criteria reuse existing constants on BuySignalDeterminationService, so only
 * these three live here (docs/architecture/data-model.md
 * "保留・確定が必要な初期パラメータ値" — 叩き台).
 *
 * Pure constants — no DB/HTTP dependency.
 */
final class LossReviewThresholds
{
    /**
     * 整理検討ライン: 含み益率がこの値を下回る個別株を対象とする
     * （対象抽出は strict less than、チェックリスト①含み損率は lte 判定）.
     */
    public const LOSS_REVIEW_LINE = -20.0;

    /**
     * 整理チェックリスト②「52週高値からの下落率」: この値以下で達成（met）.
     */
    public const WEEK52_HIGH_DECLINE_PCT = -30.0;

    /**
     * 整理チェックリスト⑦「押し目買いシグナル件数」: 0件で達成（met）.
     */
    public const NO_REBOUND_SIGNAL_COUNT = 0;
}
