<?php

namespace App\Livewire\Holding;

use App\Actions\Holding\ListHoldingsAction;
use App\Actions\Market\ShowMarketIndicatorAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * UC-002 (保有銘柄一覧表示) + UC-007 (市場全体指標表示): Livewireフルページ版の
 * 保有銘柄一覧画面。ListHoldingsAction/ShowMarketIndicatorActionはどちらも
 * 副作用のない参照専用Actionのため、render()のたびに毎回呼び直す。
 */
#[Layout('components.layouts.app', ['title' => '保有銘柄一覧'])]
class HoldingList extends Component
{
    public ?string $sector = null;

    public bool $signalOnly = false;

    public function render()
    {
        $holdings = app(ListHoldingsAction::class)->execute($this->sector, $this->signalOnly);
        $marketIndicators = app(ShowMarketIndicatorAction::class)->execute();

        return view('livewire.holding.holding-list', [
            'holdings' => $holdings,
            'marketIndicators' => $marketIndicators,
        ]);
    }
}
