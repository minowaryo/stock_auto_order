<?php

namespace App\Livewire\Signal;

use App\Actions\Signal\ShowBuySignalListAction;
use App\Actions\Signal\ShowSignalListAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * UC-004 (利確検討) + UC-010 (買い増し候補) を1画面に統合した売買シグナル
 * 画面。ShowSignalListAction / ShowBuySignalListAction はいずれも副作用の
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

        return view('livewire.signal.signal-list', [
            'signals' => $signals,
            'buySignals' => $buySignals,
        ]);
    }
}
