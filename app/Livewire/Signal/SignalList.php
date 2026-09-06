<?php

namespace App\Livewire\Signal;

use App\Actions\Signal\ShowBuySignalListAction;
use App\Actions\Signal\ShowLossReviewListAction;
use App\Actions\Signal\ShowSignalListAction;
use Livewire\Attributes\Layout;
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
    public function render()
    {
        $signals = app(ShowSignalListAction::class)->execute();
        $buySignals = app(ShowBuySignalListAction::class)->execute();
        $lossReviews = app(ShowLossReviewListAction::class)->execute();

        return view('livewire.signal.signal-list', [
            'signals' => $signals,
            'buySignals' => $buySignals,
            'lossReviews' => $lossReviews,
        ]);
    }
}
