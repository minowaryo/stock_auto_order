<?php

namespace App\Livewire\Sector;

use App\Actions\Sector\ShowSectorDashboardAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * UC-005 (セクター配分ダッシュボード): Livewireフルページ版のセクター配分・
 * リバランス提案画面。ShowSectorDashboardActionは副作用のない参照専用Action
 * のため、render()のたびに毎回呼び直す（HoldingList/SignalListと同じ規約）。
 */
#[Layout('components.layouts.app', ['title' => 'セクター配分', 'active' => 'sector-dashboard'])]
class SectorDashboard extends Component
{
    public function render()
    {
        $dashboard = app(ShowSectorDashboardAction::class)->execute();

        $marketLabels = ['jp' => '日本株', 'us' => '米国株', 'mutual_fund' => '投資信託'];
        $sectorsByMarket = collect($dashboard['sectors'])->groupBy('market');

        $marketGroups = collect($marketLabels)
            ->filter(fn (string $label, string $market) => $sectorsByMarket->has($market))
            ->map(fn (string $label, string $market) => [
                'label' => $label,
                'sectors' => $sectorsByMarket[$market]->all(),
                'subtotal' => $sectorsByMarket[$market]->sum('allocation_amount'),
            ])
            ->values()
            ->all();

        return view('livewire.sector.sector-dashboard', [
            'marketGroups' => $marketGroups,
            'grandTotal' => collect($dashboard['sectors'])->sum('allocation_amount'),
            'rebalanceCandidates' => $dashboard['rebalance_candidates'],
        ]);
    }
}
