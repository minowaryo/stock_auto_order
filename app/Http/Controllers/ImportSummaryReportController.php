<?php

namespace App\Http\Controllers;

use App\Actions\ImportSummaryReport\ShowImportSummaryReportAction;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;

/**
 * UC-009 (取込後サマリーレポート). Kept thin: business logic lives in
 * ShowImportSummaryReportAction (.claude/rules/10-laravel.md). UC-009 has no
 * request input (docs/product/use-cases.md 入力: なし), so no FormRequest is
 * needed here.
 */
class ImportSummaryReportController extends Controller
{
    public function show(ImportBatch $importBatch, ShowImportSummaryReportAction $action): JsonResponse
    {
        $data = $action->execute($importBatch);

        return response()->json(['data' => $data]);
    }
}
