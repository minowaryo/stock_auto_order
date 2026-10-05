<?php

namespace App\Actions\Import;

use App\Actions\Import\Support\TradeHistoryImportSummary;
use App\Actions\Import\Support\TradeReconciliationSummary;
use App\Exceptions\Import\CsvStructureException;
use App\Models\Holding;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Services\Import\Support\ParsedTradeRow;
use App\Services\Import\TradeHistoryCsvParser;
use App\Services\Import\TradeHistoryReconciler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Previews or imports the 楽天証券 full-history trade CSVs (UC-017,
 * ADR-0027 D1).
 *
 * Rows are matched on (market, content_hash, occurrence_index), so
 * re-submitting the full history adds only new trades. A previously stored
 * row that is absent from the latest history is flagged
 * missing_in_latest, never deleted. Reconciliation with the holdings CSV is
 * a separate step.
 */
class ImportTradeHistoryAction
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly TradeHistoryCsvParser $parser,
        private readonly TradeHistoryReconciler $reconciler,
    ) {}

    /**
     * Counts what an import would do, without writing anything.
     */
    public function preview(UploadedFile $jpFile, UploadedFile $usFile): TradeHistoryImportSummary
    {
        try {
            [$rows, $errorCount] = $this->parse($jpFile, $usFile);
        } catch (CsvStructureException $e) {
            return TradeHistoryImportSummary::failure(null, $e->getMessage());
        }

        $diff = $this->diff($rows);

        return $this->summary(null, $rows, $errorCount, $diff);
    }

    public function execute(UploadedFile $jpFile, UploadedFile $usFile): TradeHistoryImportSummary
    {
        $batch = TradeImportBatch::create([
            'status' => 'pending',
            'jp_filename' => $jpFile->getClientOriginalName(),
            'us_filename' => $usFile->getClientOriginalName(),
        ]);

        try {
            [$rows, $errorCount] = $this->parse($jpFile, $usFile);
        } catch (CsvStructureException $e) {
            $batch->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 255)])->save();

            return TradeHistoryImportSummary::failure($batch->id, $e->getMessage());
        }

        try {
            return $this->save($batch, $rows, $errorCount);
        } catch (Throwable $e) {
            // The transaction rolled back; record the failure so the batch is
            // never left looking 'pending', then let the caller see the error.
            $batch->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw $e;
        }
    }

    /**
     * @param  array<int, ParsedTradeRow>  $rows
     */
    private function save(TradeImportBatch $batch, array $rows, int $errorCount): TradeHistoryImportSummary
    {
        return DB::transaction(function () use ($batch, $rows, $errorCount) {
            $diff = $this->diff($rows);
            $now = now();

            $this->insertNew($diff['new'], $batch->id, $now);

            foreach (array_chunk($diff['existingIds'], self::INSERT_CHUNK) as $ids) {
                TradeExecution::whereIn('id', $ids)->update([
                    'last_seen_import_batch_id' => $batch->id,
                    'review_status' => 'ok',
                    'updated_at' => $now,
                ]);
            }

            foreach (array_chunk($diff['missingIds'], self::INSERT_CHUNK) as $ids) {
                TradeExecution::whereIn('id', $ids)->update([
                    'review_status' => 'missing_in_latest',
                    'updated_at' => $now,
                ]);
            }

            $reconciliation = $this->reconciler->reconcile($batch);
            $summary = $this->summary($batch->id, $rows, $errorCount, $diff, $reconciliation);

            $batch->forceFill([
                'status' => 'completed',
                'period_from' => $summary->periodFrom,
                'period_to' => $summary->periodTo,
                'total_rows' => $summary->totalRows,
                'new_rows' => $summary->newRows,
                'existing_rows' => $summary->existingRows,
                'missing_rows' => $summary->missingRows,
                'imported_at' => $now,
            ])->save();

            return $summary;
        });
    }

    /**
     * @return array{0: array<int, ParsedTradeRow>, 1: int}
     */
    private function parse(UploadedFile $jpFile, UploadedFile $usFile): array
    {
        $jp = $this->parser->parseJp($this->decode($jpFile));
        $us = $this->parser->parseUs($this->decode($usFile));

        return [[...$jp->rows, ...$us->rows], $jp->errorCount + $us->errorCount];
    }

    private function decode(UploadedFile $file): string
    {
        return mb_convert_encoding($file->get(), 'UTF-8', 'SJIS-win');
    }

    /**
     * Splits the file rows against the stored rows by key.
     *
     * @param  array<int, ParsedTradeRow>  $rows
     * @return array{new: array<int, ParsedTradeRow>, existingIds: array<int, int>, missingIds: array<int, int>}
     */
    private function diff(array $rows): array
    {
        $stored = TradeExecution::query()
            ->select(['id', 'market', 'content_hash', 'occurrence_index'])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $r) => ["{$r->market}|{$r->content_hash}|{$r->occurrence_index}" => (int) $r->id])
            ->all();

        $new = [];
        $existingIds = [];

        foreach ($rows as $row) {
            $key = "{$row->market}|{$row->contentHash}|{$row->occurrenceIndex}";

            if (isset($stored[$key])) {
                $existingIds[] = $stored[$key];
                unset($stored[$key]);
            } else {
                $new[] = $row;
            }
        }

        return ['new' => $new, 'existingIds' => $existingIds, 'missingIds' => array_values($stored)];
    }

    /**
     * @param  array<int, ParsedTradeRow>  $rows
     */
    private function insertNew(array $rows, int $batchId, \DateTimeInterface $now): void
    {
        $holdingIds = [];
        $records = [];

        foreach ($rows as $row) {
            $holdingKey = "{$row->market}|{$row->symbolCode}";
            $holdingIds[$holdingKey] ??= Holding::firstOrCreate(
                ['symbol_code' => $row->symbolCode, 'market' => $row->market],
                ['instrument_type' => 'stock', 'symbol_name' => $row->symbolName, 'first_detected_at' => $now],
            )->id;

            $records[] = [
                'holding_id' => $holdingIds[$holdingKey],
                'market' => $row->market,
                'trade_date' => $row->tradeDate,
                'settlement_date' => $row->settlementDate,
                'account_type' => $row->accountType,
                'kind' => $row->kind,
                'is_routine' => $row->isRoutine,
                'quantity' => $row->quantity,
                'unit_price' => $row->unitPrice,
                'price_currency' => $row->priceCurrency,
                'settlement_currency' => $row->settlementCurrency,
                'settlement_amount_jpy' => $row->settlementAmountJpy,
                'settlement_amount_usd' => $row->settlementAmountUsd,
                'fx_rate' => $row->fxRate,
                'fee_amount' => $row->feeAmount,
                'content_hash' => $row->contentHash,
                'occurrence_index' => $row->occurrenceIndex,
                'source_row' => json_encode($row->sourceRow, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'first_import_batch_id' => $batchId,
                'last_seen_import_batch_id' => $batchId,
                'review_status' => 'ok',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
            TradeExecution::insert($chunk);
        }
    }

    /**
     * @param  array<int, ParsedTradeRow>  $rows
     * @param  array{new: array<int, ParsedTradeRow>, existingIds: array<int, int>, missingIds: array<int, int>}  $diff
     */
    private function summary(?int $batchId, array $rows, int $errorCount, array $diff, ?TradeReconciliationSummary $reconciliation = null): TradeHistoryImportSummary
    {
        $dates = array_map(fn (ParsedTradeRow $r) => $r->tradeDate, $rows);

        return new TradeHistoryImportSummary(
            success: true,
            batchId: $batchId,
            totalRows: count($rows) + $errorCount,
            newRows: count($diff['new']),
            existingRows: count($diff['existingIds']),
            missingRows: count($diff['missingIds']),
            errorCount: $errorCount,
            periodFrom: $dates === [] ? null : min($dates),
            periodTo: $dates === [] ? null : max($dates),
            reconciliation: $reconciliation,
        );
    }
}
