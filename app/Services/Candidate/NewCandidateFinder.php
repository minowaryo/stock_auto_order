<?php

namespace App\Services\Candidate;

use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Models\WatchedTheme;
use Illuminate\Support\Collection;

/**
 * UC-008 (新規投資候補レコメンド・軽量版): finds unheld stock holdings whose
 * sector matches a registered watched theme and satisfies the base financial
 * health filter, enriched with UC-008-specific fields (matched_theme /
 * fundamental_summary / suggested_amount / nisa_recommended /
 * nisa_recommended_reason). Extraction condition mirrors
 * ShowImportSummaryReportAction::buildNewCandidateItems() (UC-009's lighter
 * precedent), which this class does not modify.
 */
class NewCandidateFinder
{
    /**
     * 財務健全性フィルタ (data-model.md draft: 自己資本比率40%以上・ROE10%以上).
     */
    private const MIN_EQUITY_RATIO = 40.0;

    private const MIN_ROE = 10.0;

    /**
     * NISA推奨追加基準 (Gate4確定: 自己資本比率50%以上・ROE15%以上).
     */
    private const NISA_MIN_EQUITY_RATIO = 50.0;

    private const NISA_MIN_ROE = 15.0;

    /**
     * suggested_amount = 保有評価額合計の1% (Gate4確定).
     */
    private const SUGGESTED_AMOUNT_RATE = 0.01;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function find(): array
    {
        $themeNames = WatchedTheme::query()->pluck('name');

        if ($themeNames->isEmpty()) {
            return [];
        }

        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        $allHoldingSnapshots = $latestSnapshot
            ? HoldingSnapshot::query()->where('snapshot_id', $latestSnapshot->id)->get()
            : collect();

        $heldHoldingIds = $allHoldingSnapshots->pluck('holding_id')->all();

        $portfolioTotal = $this->portfolioEvaluationTotal($allHoldingSnapshots);
        $suggestedAmount = $portfolioTotal * self::SUGGESTED_AMOUNT_RATE;

        $candidateHoldings = Holding::query()
            ->whereNotIn('id', $heldHoldingIds)
            ->where('instrument_type', 'stock')
            ->whereNotNull('sector_classification_id')
            ->whereHas('sectorClassification', fn ($query) => $query->whereIn('name', $themeNames))
            ->whereHas('fundamentalIndicator', function ($query) {
                $query->where('equity_ratio', '>=', self::MIN_EQUITY_RATIO)
                    ->where('roe', '>=', self::MIN_ROE);
            })
            ->with(['sectorClassification', 'fundamentalIndicator'])
            ->get();

        return $candidateHoldings
            ->map(fn (Holding $holding) => $this->toRow($holding, $suggestedAmount))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, HoldingSnapshot>  $holdingSnapshots
     */
    private function portfolioEvaluationTotal(Collection $holdingSnapshots): float
    {
        return (float) $holdingSnapshots->sum(function (HoldingSnapshot $holdingSnapshot) {
            $value = (float) $holdingSnapshot->quantity * (float) $holdingSnapshot->current_price;

            if ($holdingSnapshot->holding->instrument_type === 'mutual_fund') {
                $value /= 10000;
            }

            return $value;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Holding $holding, float $suggestedAmount): array
    {
        $equityRatio = (float) $holding->fundamentalIndicator->equity_ratio;
        $roe = (float) $holding->fundamentalIndicator->roe;

        $nisaRecommended = $equityRatio >= self::NISA_MIN_EQUITY_RATIO && $roe >= self::NISA_MIN_ROE;

        return [
            'symbol_code' => $holding->symbol_code,
            'symbol_name' => $holding->symbol_name,
            'matched_theme' => $holding->sectorClassification->name,
            'fundamental_summary' => sprintf('自己資本比率%s%%・ROE%s%%', $this->fmt($equityRatio), $this->fmt($roe)),
            'suggested_amount' => $suggestedAmount,
            'nisa_recommended' => $nisaRecommended,
            'nisa_recommended_reason' => $nisaRecommended
                ? sprintf('自己資本比率%s%%・ROE%s%%と財務健全性が高くNISA口座での長期保有に適しています', $this->fmt($equityRatio), $this->fmt($roe))
                : '',
        ];
    }

    private function fmt(float $value): string
    {
        return (string) (int) round($value);
    }
}
