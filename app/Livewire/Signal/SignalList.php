<?php

namespace App\Livewire\Signal;

use App\Actions\Portfolio\ShowHoldListAction;
use App\Actions\Signal\ShowBuySignalListAction;
use App\Actions\Signal\ShowLossReviewListAction;
use App\Actions\Signal\ShowSignalListAction;
use App\Support\SignalListSort;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * UC-004 (利確検討) + UC-010 (買い増し候補) + UC-011 (整理検討〔含み損〕) を
 * 1画面に統合した売買シグナル画面。ShowSignalListAction /
 * ShowBuySignalListAction / ShowLossReviewListAction はいずれも副作用の
 * ない参照専用Actionのため、render()のたびに毎回呼び直す（HoldingListと
 * 同じ規約）。
 */
#[Layout('components.layouts.app', ['title' => '売買シグナル'])]
class SignalList extends Component
{
    /** CHG-0027: 3テーブル共通の並び順。既定は評価額順、おすすめ順（従来の透明マルチキー）に切替可。 */
    #[Url(as: 'sort')]
    public string $sort = SignalListSort::MARKET_VALUE;

    public function setSort(string $sort): void
    {
        if (SignalListSort::isValid($sort)) {
            $this->sort = $sort;
        }
    }

    public function render()
    {
        $sort = SignalListSort::isValid($this->sort) ? $this->sort : SignalListSort::MARKET_VALUE;

        $signals = app(ShowSignalListAction::class)->execute($sort);
        $buySignals = app(ShowBuySignalListAction::class)->execute($sort);
        $lossReviews = app(ShowLossReviewListAction::class)->execute($sort);

        $holdings = app(ShowHoldListAction::class)->execute($sort, $lossReviews, $signals, $buySignals);

        return view('livewire.signal.signal-list', [
            'signals' => $signals,
            'buySignals' => $buySignals,
            'lossReviews' => $lossReviews,
            'holdings' => $holdings,
            'activeSort' => $sort,
        ]);
    }
}
