<?php

namespace App\Livewire\Concentration;

use App\Actions\Concentration\ShowConcentrationDashboardAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * UC-015 (集中度ダッシュボード): Livewireフルページ版。ShowConcentrationDashboardAction
 * は副作用のない参照専用Actionのため、render()のたびに毎回呼び直す
 * （SectorDashboardと同じ規約）。グローバルナビのタブは増やさず、
 * セクター配分タブをアクティブ表示にする（ADR-0019 D6）。
 */
#[Layout('components.layouts.app', ['title' => '集中度ダッシュボード', 'active' => 'sector-dashboard'])]
class ConcentrationDashboard extends Component
{
    public function render()
    {
        return view('livewire.concentration.concentration-dashboard', [
            'dashboard' => app(ShowConcentrationDashboardAction::class)->execute(),
        ]);
    }
}
