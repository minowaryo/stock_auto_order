<?php

namespace App\Livewire\SignalOutcome;

use App\Actions\SignalOutcome\ShowSignalOutcomesAction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * UC-014 シグナル検証画面（ADR-0017 D8）。ShowSignalOutcomesAction は参照専用の
 * ため render() のたびに呼び直す。グローバルナビのタブは増やさず、売買シグナル
 * タブをアクティブ表示にする。想定外の絞り込み値はエラーにせず無視する。
 */
#[Layout('components.layouts.app', ['title' => 'シグナル検証', 'active' => 'signals'])]
class SignalOutcomes extends Component
{
    #[Url]
    public ?string $source = null;

    #[Url]
    public ?string $market = null;

    public function render()
    {
        $source = in_array($this->source, ShowSignalOutcomesAction::SOURCES, true) ? $this->source : null;
        $market = in_array($this->market, ShowSignalOutcomesAction::MARKETS, true) ? $this->market : null;

        return view('livewire.signal-outcome.signal-outcomes', [
            'outcomes' => app(ShowSignalOutcomesAction::class)->execute($source, $market),
            'activeSource' => $source,
            'activeMarket' => $market,
        ]);
    }
}
