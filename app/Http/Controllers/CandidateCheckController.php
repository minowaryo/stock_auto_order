<?php

namespace App\Http\Controllers;

use App\Actions\Candidate\SaveWatchRecordAction;
use App\Actions\Candidate\ShowCandidateCheckAction;
use App\Http\Requests\SaveWatchRecordRequest;
use App\Http\Requests\ShowCandidateCheckRequest;
use App\Models\Holding;
use Illuminate\Http\JsonResponse;

/**
 * UC-006 (新規投資候補の重複チェック). Kept thin: validation lives in the
 * FormRequests, business logic lives in the Actions (.claude/rules/10-laravel.md).
 */
class CandidateCheckController extends Controller
{
    public function show(ShowCandidateCheckRequest $request, ShowCandidateCheckAction $action): JsonResponse
    {
        $holding = Holding::where('symbol_code', $request->validated('symbol_code'))->first();

        return response()->json(['data' => $action->execute($holding)]);
    }

    public function storeWatchRecord(SaveWatchRecordRequest $request, SaveWatchRecordAction $action): JsonResponse
    {
        $holding = Holding::where('symbol_code', $request->validated('symbol_code'))->first();

        $record = $action->execute($holding, $request->validated('watch_status'), $request->validated('watch_memo'));

        return response()->json([
            'data' => [
                'watch_status' => $record->watch_status,
                'memo' => $record->memo,
                'recorded_at' => $record->recorded_at,
            ],
        ], 201);
    }
}
