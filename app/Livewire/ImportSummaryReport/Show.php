<?php

namespace App\Livewire\ImportSummaryReport;

use App\Actions\ImportSummaryReport\ShowImportSummaryReportAction;
use App\Models\ImportBatch;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * UC-009 (取込後サマリーレポート): Livewireフルページ版の表示画面。
 *
 * ShowImportSummaryReportActionは呼び出しごとにimport_summary_report_items
 * を削除・再挿入する副作用を持つ（同Actionのdocblock参照）ため、
 * mount()で1回だけ実行し、render()では保存済みの$reportを再利用する。
 */
#[Layout('components.layouts.app', ['title' => '取込後サマリーレポート'])]
class Show extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $report = [];

    public function mount(ImportBatch $importBatch, ShowImportSummaryReportAction $showImportSummaryReportAction): void
    {
        $this->report = $showImportSummaryReportAction->execute($importBatch);
    }

    public function render()
    {
        return view('livewire.import-summary-report.show');
    }
}
