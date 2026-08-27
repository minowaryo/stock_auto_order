<?php

namespace App\Livewire\Signal;

use App\Actions\Signal\ShowSignalListAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * UC-004 (利確検討画面): Livewireフルページ版の利確検討一覧画面。
 * ShowSignalListActionは副作用のない参照専用Actionのため、render()の
 * たびに毎回呼び直す（HoldingListと同じ規約）。
 */
#[Layout('components.layouts.app', ['title' => '利確検討'])]
class SignalList extends Component
{
    public function render()
    {
        $signals = app(ShowSignalListAction::class)->execute();

        return view('livewire.signal.signal-list', [
            'signals' => $signals,
        ]);
    }
}
