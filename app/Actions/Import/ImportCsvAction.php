<?php

namespace App\Actions\Import;

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Actions\Import\Support\AggregatedHoldingRow;
use App\Actions\Import\Support\ImportResult;
use App\Exceptions\Import\CsvStructureException;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\HoldingSnapshotAccount;
use App\Models\ImportBatch;
use App\Models\ImportSummaryReport;
use App\Models\Snapshot;
use App\Services\Import\JpStockCsvParser;
use App\Services\Import\MutualFundCsvParser;
use App\Services\Import\Support\ParsedCsvRow;
use App\Services\Import\UsStockCsvParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates UC-001 (CSV取込): decode the uploaded 楽天証券 CSVs, parse
 * them, aggregate holdings across account sections, persist a new weekly
 * snapshot, and trigger the UC-009 summary report.
 */
class ImportCsvAction
{
    public function __construct(
        private readonly JpStockCsvParser $jpStockCsvParser,
        private readonly UsStockCsvParser $usStockCsvParser,
        private readonly MutualFundCsvParser $mutualFundCsvParser,
        private readonly FetchExternalMarketDataAction $fetchExternalMarketDataAction,
    ) {}

    /**
     * @param  UploadedFile  $jpFile  JP株CSV（必須）
     * @param  UploadedFile  $usFile  US株CSV（必須）
     * @param  UploadedFile|null  $mutualFundFile  投資信託CSV（任意）
     *
     * プレーンなUploadedFileを受け取る（FormRequestに依存しない）ことで、
     * HTTP経由のCsvImportControllerだけでなく、Livewireコンポーネントが
     * 保持するTemporaryUploadedFile（Illuminate\Http\UploadedFileのサブ
     * クラス）からも直接呼び出せる（stock_auto_order-frontend-
     * implementation-phase.md Phase0）。
     */
    public function execute(UploadedFile $jpFile, UploadedFile $usFile, ?UploadedFile $mutualFundFile = null): ImportResult
    {
        $batch = ImportBatch::create([
            'status' => 'processing',
            'jp_stock_filename' => $jpFile->getClientOriginalName(),
            'us_stock_filename' => $usFile->getClientOriginalName(),
            'mutual_fund_filename' => $mutualFundFile?->getClientOriginalName(),
            'imported_count' => 0,
            'error_count' => 0,
        ]);

        try {
            $jpParsed = $this->jpStockCsvParser->parse($this->decode($jpFile));
            $usParsed = $this->usStockCsvParser->parse($this->decode($usFile));
            $mutualFundParsed = $mutualFundFile
                ? $this->mutualFundCsvParser->parse($this->decode($mutualFundFile))
                : null;
        } catch (CsvStructureException $e) {
            $batch->forceFill([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ])->save();

            return ImportResult::failure($batch->id, $e->getMessage());
        }

        /** @var array<int, ParsedCsvRow> $rows */
        $rows = [...$jpParsed->rows, ...$usParsed->rows, ...($mutualFundParsed->rows ?? [])];
        $errorCount = $jpParsed->errorCount + $usParsed->errorCount + ($mutualFundParsed->errorCount ?? 0);

        $aggregatedHoldings = $this->aggregate($rows);

        $result = DB::transaction(function () use ($batch, $aggregatedHoldings, $errorCount) {
            // docs/architecture/data-model.md#snapshots: "直近" is determined by
            // snapshotted_at (the column the table's index is built for), with id
            // as a tiebreaker for snapshots created within the same second.
            $previousSnapshot = Snapshot::query()
                ->orderByDesc('snapshotted_at')
                ->orderByDesc('id')
                ->first();

            $snapshot = Snapshot::create([
                'import_batch_id' => $batch->id,
                'snapshotted_at' => now(),
            ]);

            $newlyDetectedSymbols = [];

            foreach ($aggregatedHoldings as $row) {
                $holding = Holding::firstOrCreate(
                    ['symbol_code' => $row->symbolCode, 'market' => $row->market],
                    [
                        'instrument_type' => $row->instrumentType,
                        'symbol_name' => $row->symbolName,
                        'first_detected_at' => now(),
                    ],
                );

                $isNewlyDetected = false;

                if ($previousSnapshot) {
                    $existedInPreviousSnapshot = HoldingSnapshot::query()
                        ->where('snapshot_id', $previousSnapshot->id)
                        ->where('holding_id', $holding->id)
                        ->exists();

                    $isNewlyDetected = ! $existedInPreviousSnapshot;
                }

                if ($isNewlyDetected) {
                    $newlyDetectedSymbols[] = "{$row->symbolCode}:{$row->market}";
                }

                $gainAmount = ($row->currentPrice - $row->averageCost) * $row->quantity;
                $gainRate = $row->averageCost !== 0.0
                    ? ($row->currentPrice - $row->averageCost) / $row->averageCost * 100
                    : 0.0;

                $holdingSnapshot = HoldingSnapshot::create([
                    'snapshot_id' => $snapshot->id,
                    'holding_id' => $holding->id,
                    'quantity' => $row->quantity,
                    'average_cost' => $row->averageCost,
                    'current_price' => $row->currentPrice,
                    'fx_rate_used' => $row->fxRateUsed,
                    'unrealized_gain_amount' => $gainAmount,
                    'unrealized_gain_rate' => $gainRate,
                    'is_newly_detected' => $isNewlyDetected,
                ]);

                // docs/adr/ADR-0002-nisa-account-type-tracking.md: persist the
                // per-account-type breakdown alongside the combined snapshot.
                foreach ($row->accountBreakdown as $accountBreakdown) {
                    HoldingSnapshotAccount::create([
                        'holding_snapshot_id' => $holdingSnapshot->id,
                        'account_type' => $accountBreakdown['accountType'],
                        'quantity' => $accountBreakdown['quantity'],
                        'average_cost' => $accountBreakdown['averageCost'],
                    ]);
                }
            }

            $importedAt = now();

            $batch->forceFill([
                'status' => 'completed',
                'imported_count' => count($aggregatedHoldings),
                'error_count' => $errorCount,
                'imported_at' => $importedAt,
            ])->save();

            // UC-009: a summary report is auto-generated the moment an import
            // completes. The full composite-score/priority ranking logic is
            // implemented in UC-009's own /tdd cycle; here we only guarantee a
            // report row with a non-empty headline exists (per Gate 4 scope).
            ImportSummaryReport::create([
                'import_batch_id' => $batch->id,
                'portfolio_headline' => sprintf('%d件の保有銘柄を取り込みました。', count($aggregatedHoldings)),
                'generated_at' => now(),
            ]);

            return ImportResult::success(
                importBatchId: $batch->id,
                importedCount: count($aggregatedHoldings),
                errorCount: $errorCount,
                importedAt: $importedAt,
                newlyDetectedSymbols: $newlyDetectedSymbols,
            );
        });

        if ($result->success) {
            try {
                $this->fetchExternalMarketDataAction->execute($batch);
            } catch (\Throwable) {
                // UC-001業務ルール: 外部データ取得の失敗は取込全体を失敗させない
            }
        }

        return $result;
    }

    private function decode(UploadedFile $file): string
    {
        return mb_convert_encoding($file->get(), 'UTF-8', 'SJIS-win');
    }

    /**
     * Merge rows across every parsed file by (market, symbol_code): sum
     * quantity, and weight-average the acquisition cost (UC-001業務ルール
     * 「銘柄コード単位で保有数量を合算し、取得単価は加重平均で算出」).
     *
     * @param  array<int, ParsedCsvRow>  $rows
     * @return array<int, AggregatedHoldingRow>
     */
    private function aggregate(array $rows): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $key = "{$row->market}|{$row->code}";

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'symbol_code' => $row->code,
                    'symbol_name' => $row->name,
                    'market' => $row->market,
                    'instrument_type' => $row->instrumentType,
                    'quantity' => 0.0,
                    'cost_sum' => 0.0,
                    'current_price' => $row->currentPrice,
                    'fx_rate_used' => $row->fxRateUsed,
                    'account_buckets' => [],
                ];
            }

            $buckets[$key]['quantity'] += $row->quantity;
            $buckets[$key]['cost_sum'] += $row->quantity * $row->averageCost;
            $buckets[$key]['current_price'] = $row->currentPrice;

            if ($row->fxRateUsed !== null) {
                $buckets[$key]['fx_rate_used'] = $row->fxRateUsed;
            }

            // docs/adr/ADR-0002-nisa-account-type-tracking.md: also aggregate
            // within (market, code) by account_type so the write-path can
            // populate holding_snapshot_accounts alongside the combined total.
            if (! isset($buckets[$key]['account_buckets'][$row->accountType])) {
                $buckets[$key]['account_buckets'][$row->accountType] = [
                    'quantity' => 0.0,
                    'cost_sum' => 0.0,
                ];
            }

            $buckets[$key]['account_buckets'][$row->accountType]['quantity'] += $row->quantity;
            $buckets[$key]['account_buckets'][$row->accountType]['cost_sum'] += $row->quantity * $row->averageCost;
        }

        return array_values(array_map(
            static function (array $bucket) {
                $accountBreakdown = [];

                foreach ($bucket['account_buckets'] as $accountType => $accountBucket) {
                    $accountBreakdown[] = [
                        'accountType' => $accountType,
                        'quantity' => $accountBucket['quantity'],
                        'averageCost' => $accountBucket['quantity'] > 0.0
                            ? $accountBucket['cost_sum'] / $accountBucket['quantity']
                            : 0.0,
                    ];
                }

                return new AggregatedHoldingRow(
                    symbolCode: $bucket['symbol_code'],
                    symbolName: $bucket['symbol_name'],
                    market: $bucket['market'],
                    instrumentType: $bucket['instrument_type'],
                    quantity: $bucket['quantity'],
                    averageCost: $bucket['quantity'] > 0.0 ? $bucket['cost_sum'] / $bucket['quantity'] : 0.0,
                    currentPrice: $bucket['current_price'],
                    fxRateUsed: $bucket['fx_rate_used'],
                    accountBreakdown: $accountBreakdown,
                );
            },
            $buckets,
        ));
    }
}
