<?php

namespace App\Livewire\TradeReview;

use App\Actions\TradeReview\ShowTradeReviewAction;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * UC-018 / UC-019 (F-017 / CHG-0033): 売買の振り返り — 成績, 売買一覧 and
 * 状況別 tabs. Holds only the view state (tab, filters, selected row, page);
 * the numbers come from ShowTradeReviewAction.
 */
#[Layout('components.layouts.app', ['title' => '売買の振り返り', 'active' => 'signals'])]
class TradeReview extends Component
{
    use WithPagination;

    private const PER_PAGE = 50;

    private const TABS = ['performance', 'trades', 'situations'];

    private const AXES = ['high_ratio', 'relative_strength', 'holding'];

    private const TYPES = ['sell', 'switch', 'buy'];

    private const HORIZONS = ['4', '13', '26'];

    #[Url]
    public string $tab = 'performance';

    #[Url]
    public ?string $market = null;

    #[Url]
    public ?string $kind = null;

    #[Url]
    public ?int $year = null;

    #[Url]
    public string $axis = 'high_ratio';

    #[Url]
    public string $type = 'sell';

    #[Url]
    public string $horizon = '26';

    #[Url]
    public ?string $cell = null;

    public function render()
    {
        // Unknown values fall back to the defaults, and the view sees the corrected ones.
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'performance';
        $this->axis = in_array($this->axis, self::AXES, true) ? $this->axis : 'high_ratio';
        $this->type = in_array($this->type, self::TYPES, true) ? $this->type : 'sell';
        $this->horizon = in_array($this->horizon, self::HORIZONS, true) ? $this->horizon : '26';

        $report = app(ShowTradeReviewAction::class)->execute();
        $trades = array_values(array_filter($report['trades'], fn (array $t) => (! in_array($this->market, ['jp', 'us'], true) || $t['market'] === $this->market)
            && (! in_array($this->kind, ['sell', 'buy'], true) || $t['kind'] === $this->kind)
            && ($this->year === null || $t['year'] === $this->year)));
        $page = $this->getPage();
        $situationRows = $report['situations']['rows'][$this->axis][$this->type][(int) $this->horizon] ?? [];
        $selected = collect($situationRows)->firstWhere('key', $this->cell);

        return view('livewire.trade-review.trade-review', [
            'tab' => $this->tab,
            'latest' => $report['latest'],
            'years' => $report['years'],
            'tradeYears' => array_values(array_unique(array_column($report['trades'], 'year'))),
            'trades' => new LengthAwarePaginator(array_slice($trades, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($trades), self::PER_PAGE, $page),
            'situationSummary' => $report['situations']['summary'],
            'situationRows' => $situationRows,
            'situationTrades' => $selected === null ? [] : array_map(fn (int $id) => [
                ...$report['situations']['trades'][$id],
                'diff' => $report['situations']['trades'][$id]['diffs'][$this->type][(int) $this->horizon] ?? '算出不可',
            ], $selected['trade_ids']),
        ]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['market', 'kind', 'year'], true)) {
            $this->resetPage();
        }
    }
}
