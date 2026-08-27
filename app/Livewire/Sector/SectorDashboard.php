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
#[Layout('components.layouts.app', ['title' => 'セクター配分'])]
class SectorDashboard extends Component
{
    public function render()
    {
        $dashboard = app(ShowSectorDashboardAction::class)->execute();

        return view('livewire.sector.sector-dashboard', [
            'sectors' => $dashboard['sectors'],
            'rebalanceCandidates' => $dashboard['rebalance_candidates'],
        ]);
    }
}
