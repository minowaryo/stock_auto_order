<?php

namespace App\Actions\Portfolio;

use App\Support\SignalListSort;

/**
 * CHG-0028: 売買シグナル画面のキープ表。ClassifyHoldingsAction の hold バケツを
 * SignalListSort の並び順で返す参照専用Action。
 */
class ShowHoldListAction
{
    public function __construct(private readonly ClassifyHoldingsAction $classifyHoldingsAction) {}

    /**
     * CHG-0032: 供給元Actionの出力を取得済みなら渡して二重実行を避ける。
     *
     * @param  array<int, array<string, mixed>>|null  $lossReviewRows
     * @param  array<int, array<string, mixed>>|null  $takeProfitRows
     * @param  array<int, array<string, mixed>>|null  $addOnRows
     * @return array<int, array<string, mixed>>
     */
    public function execute(string $sort = SignalListSort::MARKET_VALUE, ?array $lossReviewRows = null, ?array $takeProfitRows = null, ?array $addOnRows = null): array
    {
        $holdings = collect($this->classifyHoldingsAction->execute($lossReviewRows, $takeProfitRows, $addOnRows)['buckets'])
            ->firstWhere('bucket', 'hold')['holdings'] ?? [];

        // おすすめ順: hold_watch 先頭 → 含み損益率の低い順（ClassifyHoldingsAction と同じ）。
        $recommended = fn (array $a, array $b) => (($b['hold_watch'] ? 1 : 0) <=> ($a['hold_watch'] ? 1 : 0))
            ?: ((float) $a['unrealized_gain_rate'] <=> (float) $b['unrealized_gain_rate']);

        usort($holdings, SignalListSort::comparator($sort, $recommended));

        return $holdings;
    }
}
