<?php

namespace App\Livewire\CsvImport;

use App\Actions\Import\ImportCsvAction;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * UC-001 (CSV取込): Livewireフルページ版のアップロード画面。
 *
 * ロジックはApp\Actions\Import\ImportCsvActionにそのまま委譲する
 * （.claude/rules/15-frontend.md「ロジックはapp/Services/やapp/Actions/に
 * 委譲する」）。バリデーションルールはStoreCsvImportRequestと同じ内容を
 * このコンポーネント自身のrules()に持つ（HTTP用FormRequestとLivewire用
 * コンポーネントでフィールド名を揃える設計）。
 */
#[Layout('components.layouts.app', ['title' => 'CSV取込', 'active' => 'csv-import'])]
class Upload extends Component
{
    use WithFileUploads;

    private const MAX_FILE_SIZE_KB = 5120; // 5MB (UC-001業務ルール)

    public $jp_stock_file = null;

    public $us_stock_file = null;

    public $mutual_fund_file = null;

    public ?string $importError = null;

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'jp_stock_file' => ['required', 'file', 'extensions:csv', 'max:'.self::MAX_FILE_SIZE_KB],
            'us_stock_file' => ['required', 'file', 'extensions:csv', 'max:'.self::MAX_FILE_SIZE_KB],
            'mutual_fund_file' => ['nullable', 'file', 'extensions:csv', 'max:'.self::MAX_FILE_SIZE_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        $neitherFileSelected = $this->jp_stock_file === null && $this->us_stock_file === null;

        $requiredMessage = $neitherFileSelected
            ? 'CSVファイルを選択してください'
            : '国内株式・米国株式のCSVは両方アップロードしてください';

        return [
            'jp_stock_file.required' => $requiredMessage,
            'us_stock_file.required' => $requiredMessage,
            'jp_stock_file.extensions' => 'CSVファイルのみアップロードできます',
            'us_stock_file.extensions' => 'CSVファイルのみアップロードできます',
            'mutual_fund_file.extensions' => 'CSVファイルのみアップロードできます',
        ];
    }

    public function import(): void
    {
        $this->importError = null;

        $this->validate();

        $result = app(ImportCsvAction::class)->execute(
            $this->jp_stock_file,
            $this->us_stock_file,
            $this->mutual_fund_file,
        );

        if (! $result->success) {
            $this->importError = $result->failureReason;

            return;
        }

        $this->redirect("/import-batches/{$result->importBatchId}/summary-report", navigate: true);
    }

    public function render()
    {
        $recentBatches = ImportBatch::query()
            ->orderByDesc('imported_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $newlyDetectedCounts = $recentBatches->mapWithKeys(function (ImportBatch $batch) {
            $count = HoldingSnapshot::query()
                ->whereHas('snapshot', fn ($query) => $query->where('import_batch_id', $batch->id))
                ->where('is_newly_detected', true)
                ->count();

            return [$batch->id => $count];
        });

        return view('livewire.csv-import.upload', [
            'recentBatches' => $recentBatches,
            'newlyDetectedCounts' => $newlyDetectedCounts,
        ]);
    }
}
