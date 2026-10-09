<?php

namespace App\Livewire\TradeHistory;

use App\Actions\Import\ImportTradeHistoryAction;
use App\Actions\Import\Support\TradeHistoryImportSummary;
use App\Models\Snapshot;
use App\Models\TradeImportBatch;
use App\Models\TradeReconciliationItem;
use App\Support\DisplayTime;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * UC-017 (売買履歴の取込): プレビュー → 確定の取込画面。
 *
 * ロジックはApp\Actions\Import\ImportTradeHistoryActionに委譲する
 * （.claude/rules/15-frontend.md）。この画面は、選んだファイルと、プレビュー・
 * 結果の表示状態を保持するだけ。確定できるのはプレビューが成功した直後だけで、
 * ファイルを選び直すとプレビューは消える（確定するのは必ずプレビューした
 * ファイル）。
 */
#[Layout('components.layouts.app', ['title' => '売買履歴の取込', 'active' => 'csv-import'])]
class Import extends Component
{
    use WithFileUploads;

    private const MAX_FILE_SIZE_KB = 5120; // 5MB (UC-001と同じ)

    private const DETAIL_LIMIT = 50;

    private const HISTORY_LIMIT = 10;

    public $jp_trade_file = null;

    public $us_trade_file = null;

    // Server-held state: locked so a tampered client cannot fake a preview
    // (which would skip the preview-before-confirm step) or a result.
    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public ?string $previewError = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $result = null;

    #[Locked]
    public ?string $importError = null;

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'jp_trade_file' => ['required', 'file', 'extensions:csv', 'max:'.self::MAX_FILE_SIZE_KB],
            'us_trade_file' => ['required', 'file', 'extensions:csv', 'max:'.self::MAX_FILE_SIZE_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'jp_trade_file.required' => '国内株式・米国株式の売買履歴CSVは両方アップロードしてください',
            'us_trade_file.required' => '国内株式・米国株式の売買履歴CSVは両方アップロードしてください',
            'jp_trade_file.extensions' => 'CSVファイルのみアップロードできます',
            'us_trade_file.extensions' => 'CSVファイルのみアップロードできます',
        ];
    }

    public function updatedJpTradeFile(): void
    {
        $this->clearPreview();
    }

    public function updatedUsTradeFile(): void
    {
        $this->clearPreview();
    }

    // Not named preview(): $wire.preview would read the $preview property
    // instead of calling this method (tests/Unit/Livewire/ComponentMemberNamesTest.php).
    public function runPreview(): void
    {
        $this->clearPreview();
        $this->result = null;
        $this->importError = null;

        $this->validate();

        $summary = app(ImportTradeHistoryAction::class)->preview($this->jp_trade_file, $this->us_trade_file);

        if (! $summary->success) {
            $this->previewError = $summary->failureReason;

            return;
        }

        $this->preview = $this->summaryToArray($summary);
    }

    public function confirm(): void
    {
        if ($this->preview === null) {
            return;
        }

        $this->importError = null;

        $summary = app(ImportTradeHistoryAction::class)->execute($this->jp_trade_file, $this->us_trade_file);

        $this->preview = null;

        if (! $summary->success) {
            $this->importError = $summary->failureReason;

            return;
        }

        $this->result = $this->summaryToArray($summary) + ['batchId' => $summary->batchId];
    }

    public function render()
    {
        $details = collect();
        $detailTotal = 0;
        $snapshotDate = null;

        if ($this->result !== null) {
            $query = TradeReconciliationItem::query()
                ->where('trade_import_batch_id', $this->result['batchId'])
                ->where('status', '!=', 'matched');
            $detailTotal = (clone $query)->count();
            $details = $query->with('holding:id,symbol_code,symbol_name')
                ->orderBy('status')
                ->orderBy('holding_id')
                ->limit(self::DETAIL_LIMIT)
                ->get();

            if ($this->result['reconciliation'] !== null) {
                $snapshotDate = DisplayTime::date(Snapshot::find($this->result['reconciliation']['snapshotId'])?->snapshotted_at);
            }
        }

        return view('livewire.trade-history.import', [
            'details' => $details,
            'moreDetails' => max(0, $detailTotal - self::DETAIL_LIMIT),
            'snapshotDate' => $snapshotDate,
            'recentImports' => TradeImportBatch::query()->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get(),
        ]);
    }

    private function clearPreview(): void
    {
        $this->preview = null;
        $this->previewError = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryToArray(TradeHistoryImportSummary $summary): array
    {
        return [
            'totalRows' => $summary->totalRows,
            'newRows' => $summary->newRows,
            'existingRows' => $summary->existingRows,
            'missingRows' => $summary->missingRows,
            'errorCount' => $summary->errorCount,
            'periodFrom' => $summary->periodFrom,
            'periodTo' => $summary->periodTo,
            'reconciliation' => $summary->reconciliation === null ? null : [
                'snapshotId' => $summary->reconciliation->snapshotId,
                'counts' => $summary->reconciliation->counts,
            ],
        ];
    }
}
