<?php

namespace App\Livewire\TradeReview;

use App\Actions\TradeReview\ShowTradeReviewAction;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * UC-018 (F-017 / CHG-0033): 売買の振り返り — 成績 and 売買一覧 tabs. Holds
 * only the view state (tab, filters, page); the numbers come from
 * ShowTradeReviewAction. The 状況別 tab (UC-019) is not shown until its
 * Gate 2 approval.
 */
#[Layout('components.layouts.app', ['title' => '売買の振り返り', 'active' => 'signals'])]
class TradeReview extends Component
{
    use WithPagination;

    private const PER_PAGE = 50;

    #[Url]
    public string $tab = 'performance';

    #[Url]
    public ?string $market = null;

    #[Url]
    public ?string $kind = null;

    #[Url]
    public ?int $year = null;

    public function render()
    {
        $report = app(ShowTradeReviewAction::class)->execute();
        $trades = array_values(array_filter($report['trades'], fn (array $t) => (! in_array($this->market, ['jp', 'us'], true) || $t['market'] === $this->market)
            && (! in_array($this->kind, ['sell', 'buy'], true) || $t['kind'] === $this->kind)
            && ($this->year === null || $t['year'] === $this->year)));
        $page = $this->getPage();

        return view('livewire.trade-review.trade-review', [
            'tab' => $this->tab === 'trades' ? 'trades' : 'performance',
            'latest' => $report['latest'],
            'years' => $report['years'],
            'tradeYears' => array_values(array_unique(array_column($report['trades'], 'year'))),
            'trades' => new LengthAwarePaginator(array_slice($trades, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($trades), self::PER_PAGE, $page),
        ]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['market', 'kind', 'year'], true)) {
            $this->resetPage();
        }
    }
}
