<?php

namespace App\Livewire\ImportSummaryReport;

use App\Actions\ImportSummaryReport\ShowImportSummaryReportAction;
use App\Models\ImportBatch;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * UC-009 (取込後サマリーレポート): Livewireフルページ版の表示画面。
 *
 * ShowImportSummaryReportActionは呼び出しごとに再計算する読み取り専用の
 * Action（副作用なし）のため、mount()で1回だけ実行すれば十分。
 *
 * F-013（ADR-0014）第1段階は「直近スナップショットのみを対象とする表示
 * 専用機能」（requirements.md F-013）であり、分類俯瞰は常に最新スナップ
 * ショットを反映する（$importBatchで指定した過去バッチに限定した分類には
 * 対応しない）。取込直後のリダイレクト直後（$importBatchが最新のとき）は
 * 一致するが、その後さらに新しい取込が行われた後に本URLを再訪した場合
 * ずれるため、日時キャプションは$importBatch->imported_atではなく実際に
 * 表示しているスナップショットの日時（classification.classified_at）を
 * 使い、キャプションと中身を常に一致させる（/reviewでの指摘対応）。
 */
#[Layout('components.layouts.app', ['title' => '取込後サマリーレポート', 'active' => 'summary-report'])]
class Show extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $report = [];

    public ?string $importedAtLabel = null;

    public function mount(ImportBatch $importBatch, ShowImportSummaryReportAction $showImportSummaryReportAction): void
    {
        $this->report = $showImportSummaryReportAction->execute($importBatch);
        $classifiedAt = $this->report['classification']['classified_at'] ?? null;
        $this->importedAtLabel = $classifiedAt?->format('Y-m-d H:i');
    }

    public function render()
    {
        return view('livewire.import-summary-report.show');
    }
}
