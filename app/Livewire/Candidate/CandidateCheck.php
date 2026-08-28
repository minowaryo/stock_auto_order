<?php

namespace App\Livewire\Candidate;

use App\Actions\Candidate\SaveWatchRecordAction;
use App\Actions\Candidate\ShowCandidateCheckAction;
use App\Actions\Candidate\ShowNewCandidateListAction;
use App\Models\Holding;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * UC-006 + UC-008 (新規投資候補、統合画面): 上部の「おすすめ候補」一覧
 * (ShowNewCandidateListAction、副作用なし・render()毎に呼び直す) と、
 * 下部の「個別銘柄をチェック」フォーム (ShowCandidateCheckAction /
 * SaveWatchRecordAction) を1画面にまとめる。
 */
#[Layout('components.layouts.app', ['title' => '新規投資候補'])]
class CandidateCheck extends Component
{
    #[Url(as: 'symbol_code')]
    public string $symbolCode = '';

    public ?string $checkError = null;

    public ?array $checkResult = null;

    public string $watchStatus = '';

    public string $watchMemo = '';

    public function mount(): void
    {
        if (filled($this->symbolCode)) {
            $this->checkCandidate();
        }
    }

    public function checkCandidate(): void
    {
        $holding = Holding::where('symbol_code', $this->symbolCode)->first();

        if (! $holding) {
            $this->checkError = '銘柄コードを確認してください';
            $this->checkResult = null;

            return;
        }

        $this->checkError = null;
        $this->checkResult = app(ShowCandidateCheckAction::class)->execute($holding);
    }

    public function saveWatchRecord(): void
    {
        if (blank($this->watchStatus) && blank($this->watchMemo)) {
            $this->addError('watchRecord', 'watch_statusまたはwatch_memoのいずれかを指定してください');

            return;
        }

        $holding = Holding::where('symbol_code', $this->symbolCode)->first();

        app(SaveWatchRecordAction::class)->execute($holding, $this->watchStatus ?: null, $this->watchMemo ?: null);

        $this->checkResult = app(ShowCandidateCheckAction::class)->execute($holding);

        $this->watchStatus = '';
        $this->watchMemo = '';
    }

    public function render()
    {
        $candidates = app(ShowNewCandidateListAction::class)->execute();

        // ShowNewCandidateListAction's fundamental_summary rounds equity_ratio/roe
        // to whole numbers; the mockup's おすすめ候補 table shows the raw
        // fundamental values, so fetch them separately for display only (no new
        // calculation rule — same raw FundamentalIndicator values used elsewhere).
        $rawFundamentals = Holding::query()
            ->whereIn('symbol_code', array_column($candidates, 'symbol_code'))
            ->with('fundamentalIndicator')
            ->get()
            ->keyBy('symbol_code');

        return view('livewire.candidate.candidate-check', [
            'candidates' => $candidates,
            'rawFundamentals' => $rawFundamentals,
        ]);
    }
}
