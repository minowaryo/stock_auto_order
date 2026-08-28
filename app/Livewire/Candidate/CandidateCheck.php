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
    /**
     * SaveWatchRecordRequest と同じ許可値・上限（Gate 4 確定契約、
     * docs/product/use-cases.md UC-006）。
     *
     * @var list<string>
     */
    private const WATCH_STATUS_OPTIONS = ['様子見', '買い時', '次回購入候補', 'リバランス対象'];

    private const WATCH_MEMO_MAX = 2000;

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

        if (filled($this->watchStatus) && ! in_array($this->watchStatus, self::WATCH_STATUS_OPTIONS, true)) {
            $this->addError('watchRecord', 'ウォッチステータスの値が不正です');

            return;
        }

        if (mb_strlen($this->watchMemo) > self::WATCH_MEMO_MAX) {
            $this->addError('watchRecord', 'メモは2000文字以内で入力してください');

            return;
        }

        $holding = Holding::where('symbol_code', $this->symbolCode)->first();

        if (! $holding) {
            $this->addError('watchRecord', '銘柄コードを確認してください');

            return;
        }

        app(SaveWatchRecordAction::class)->execute($holding, $this->watchStatus ?: null, $this->watchMemo ?: null);

        $this->checkResult = app(ShowCandidateCheckAction::class)->execute($holding);

        $this->watchStatus = '';
        $this->watchMemo = '';
    }

    public function render()
    {
        $candidates = app(ShowNewCandidateListAction::class)->execute();

        // ShowNewCandidateListAction の fundamental_summary は equity_ratio/ROE を
        // 整数に丸める。おすすめ候補テーブルのモック
        // (screen-UC006-candidate-check.html) は小数第1位まで表示するため、生の
        // FundamentalIndicator 値から fundamental_summary を表示専用に組み直す
        // （新しい計算ルールは作らない・他画面と同じ値）。
        $rawFundamentals = Holding::query()
            ->whereIn('symbol_code', array_column($candidates, 'symbol_code'))
            ->with('fundamentalIndicator')
            ->get()
            ->keyBy('symbol_code');

        foreach ($candidates as $index => $candidate) {
            $indicator = $rawFundamentals->get($candidate['symbol_code'])?->fundamentalIndicator;

            if ($indicator !== null) {
                $candidates[$index]['fundamental_summary'] = sprintf(
                    '自己資本比率%s%%・ROE%s%%',
                    number_format((float) $indicator->equity_ratio, 1),
                    number_format((float) $indicator->roe, 1),
                );
            }
        }

        return view('livewire.candidate.candidate-check', [
            'candidates' => $candidates,
        ]);
    }
}
