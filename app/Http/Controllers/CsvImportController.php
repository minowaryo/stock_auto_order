<?php

namespace App\Http\Controllers;

use App\Actions\Import\ImportCsvAction;
use App\Http\Requests\StoreCsvImportRequest;
use Illuminate\Http\JsonResponse;

/**
 * UC-001 (CSV取込). Kept thin: validation lives in StoreCsvImportRequest,
 * all business logic lives in ImportCsvAction (.claude/rules/10-laravel.md).
 */
class CsvImportController extends Controller
{
    public function store(StoreCsvImportRequest $request, ImportCsvAction $action): JsonResponse
    {
        $result = $action->execute(
            $request->file('jp_stock_file'),
            $request->file('us_stock_file'),
            $request->file('mutual_fund_file'),
        );

        if (! $result->success) {
            return response()->json([
                'message' => $result->failureReason,
            ], 422);
        }

        return response()->json([
            'import_batch_id' => $result->importBatchId,
            'status' => 'completed',
            'imported_count' => $result->importedCount,
            'error_count' => $result->errorCount,
            'imported_at' => $result->importedAt,
            'newly_detected_symbols' => $result->newlyDetectedSymbols,
        ], 201);
    }
}
