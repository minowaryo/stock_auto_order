<?php

namespace App\Livewire\ImportSummaryReport;

use App\Actions\ImportSummaryReport\ShowImportSummaryReportAction;
use App\Models\ImportBatch;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * UC-009 (取込後サマリーレポート): グローバルナビ「サマリーレポート」
 * タブの表示画面（CHG-0008）。スナップショットを持つ最新の取込バッチ1件
 * のみを対象とし、過去バッチの一覧・履歴閲覧は提供しない。
 *
 * ShowImportSummaryReportActionは呼び出しごとにimport_summary_report_items
 * を削除・再挿入する副作用を持つため、mount()で1回だけ実行し、render()
 * では保存済みの$reportを再利用する（Show.phpと同様）。
 */
#[Layout('components.layouts.app', ['title' => '取込後サマリーレポート', 'active' => 'summary-report'])]
class Latest extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $report = [];

    public ?string $importedAtLabel = null;

    public function mount(ShowImportSummaryReportAction $showImportSummaryReportAction): void
    {
        $batch = ImportBatch::query()
            ->whereHas('snapshot')
            ->orderByDesc('imported_at')
            ->orderByDesc('id')
            ->first();

        if ($batch === null) {
            return;
        }

        $this->report = $showImportSummaryReportAction->execute($batch);
        $this->importedAtLabel = $batch->imported_at?->format('Y-m-d H:i');
    }

    public function render()
    {
        return view('livewire.import-summary-report.latest');
    }
}
