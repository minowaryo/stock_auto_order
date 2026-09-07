<?php

namespace App\Livewire\Candidate;

use App\Actions\Candidate\SaveWatchRecordAction;
use App\Actions\Candidate\ShowCandidateCheckAction;
use App\Actions\Watchlist\ImportFavoriteCsvAction;
use App\Actions\Watchlist\ShowWatchlistAction;
use App\Jobs\RefreshWatchlistMarketDataJob;
use App\Models\Holding;
use App\Models\WatchlistItem;
use App\Models\WatchlistRefreshRun;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * UC-012 (F-012 / ADR-0013): お気に入り未保有銘柄ウォッチリスト画面。
 * 旧 UC-006（重複チェック）/ UC-008（おすすめ候補）を刷新して置き換える。
 *
 * ロジックは App\Actions\Watchlist\* / App\Actions\Candidate\* に委譲する
 * （.claude/rules/15-frontend.md）。render() のたびに ShowWatchlistAction を
 * 呼び直す参照専用パターン（SignalList と同じ）。
 */
#[Layout('components.layouts.app', ['title' => '新規投資候補', 'active' => 'candidate-check'])]
class CandidateCheck extends Component
{
    use WithFileUploads;

    private const WATCH_STATUS_OPTIONS = ['様子見', '買い時', '次回購入候補', 'リバランス対象'];

    private const WATCH_MEMO_MAX = 2000;

    private const MAX_FILE_SIZE_KB = 5120; // 5MB

    public $favorites_csv_file = null;

    public ?string $importError = null;

    public ?string $importMessage = null;

    public string $folderFilter = '';

    public bool $starredOnly = false;

    /** 行展開中の銘柄コード（UC-006 相当の詳細を表示する）。 */
    public ?string $expandedSymbol = null;

    public ?array $expandedDetail = null;

    public string $watchStatus = '';

    public string $watchMemo = '';

    public function importFavorites(): void
    {
        $this->importError = null;
        $this->importMessage = null;

        $this->validate([
            'favorites_csv_file' => ['required', 'file', 'extensions:csv', 'max:'.self::MAX_FILE_SIZE_KB],
        ], [], ['favorites_csv_file' => 'お気に入り銘柄CSV']);

        $result = app(ImportFavoriteCsvAction::class)->execute($this->favorites_csv_file);

        if (! $result->success) {
            $this->importError = $result->failureReason;

            return;
        }

        $this->favorites_csv_file = null;
        $this->importMessage = "{$result->registeredCount}件を登録しました（対象外 {$result->skippedCount}件）。指標の取得を開始しました。";
    }

    public function refreshAll(): void
    {
        if (WatchlistRefreshRun::active()->exists()) {
            $this->importMessage = '更新処理を実行中です。完了までお待ちください。';

            return;
        }

        $run = WatchlistRefreshRun::create(['status' => WatchlistRefreshRun::STATUS_QUEUED]);
        RefreshWatchlistMarketDataJob::dispatch($run->id);
        $this->importMessage = 'ウォッチリストの一括更新を開始しました。';
    }

    public function toggleStar(int $watchlistItemId): void
    {
        $item = WatchlistItem::find($watchlistItemId);

        if ($item !== null) {
            $item->update(['is_starred' => ! $item->is_starred]);
        }
    }

    public function toggleExpand(string $symbolCode): void
    {
        if ($this->expandedSymbol === $symbolCode) {
            $this->expandedSymbol = null;
            $this->expandedDetail = null;

            return;
        }

        $holding = Holding::where('symbol_code', $symbolCode)->first();

        if ($holding === null) {
            return;
        }

        $this->expandedSymbol = $symbolCode;
        $this->expandedDetail = app(ShowCandidateCheckAction::class)->execute($holding);
        $this->watchStatus = '';
        $this->watchMemo = '';
    }

    public function saveWatchRecord(): void
    {
        if ($this->expandedSymbol === null) {
            return;
        }

        if (blank($this->watchStatus) && blank($this->watchMemo)) {
            $this->addError('watchRecord', 'watch_statusまたはwatch_memoのいずれかを指定してください');

            return;
        }

        if (filled($this->watchStatus) && ! in_array($this->watchStatus, self::WATCH_STATUS_OPTIONS, true)) {
            $this->addError('watchRecord', 'ウォッチステータスの値が不正です');

            return;
        }

        if (mb_strlen($this->watchMemo) > self::WATCH_MEMO_MAX) {
            $this->addError('watchRecord', 'メモは2000文字以内で入力してください');

            return;
        }

        $holding = Holding::where('symbol_code', $this->expandedSymbol)->first();

        if ($holding === null) {
            return;
        }

        app(SaveWatchRecordAction::class)->execute($holding, $this->watchStatus ?: null, $this->watchMemo ?: null);

        $this->expandedDetail = app(ShowCandidateCheckAction::class)->execute($holding);
        $this->watchStatus = '';
        $this->watchMemo = '';
    }

    public function render()
    {
        $rows = app(ShowWatchlistAction::class)->execute();

        $folders = collect($rows)->pluck('folder_name')->filter()->unique()->sort()->values()->all();

        $visibleRows = collect($rows)
            ->when($this->starredOnly, fn ($c) => $c->where('is_starred', true))
            ->when($this->folderFilter !== '', fn ($c) => $c->where('folder_name', $this->folderFilter))
            ->values()
            ->all();

        $activeRun = WatchlistRefreshRun::query()->orderByDesc('id')->first();

        return view('livewire.candidate.candidate-check', [
            'rows' => $rows,
            'visibleRows' => $visibleRows,
            'folders' => $folders,
            'watchlistCount' => WatchlistItem::count(),
            'activeRun' => $activeRun,
            'watchStatusOptions' => self::WATCH_STATUS_OPTIONS,
        ]);
    }
}
