<?php

namespace App\Http\Controllers;

use App\Actions\Holding\SaveHoldingMemoAction;
use App\Actions\Holding\ShowHoldingDetailAction;
use App\Http\Requests\SaveHoldingMemoRequest;
use App\Http\Requests\ShowHoldingDetailRequest;
use App\Models\Holding;
use Illuminate\Http\JsonResponse;

/**
 * UC-003 (銘柄詳細表示). Kept thin: validation lives in the FormRequests,
 * business logic lives in the Actions (.claude/rules/10-laravel.md).
 */
class HoldingDetailController extends Controller
{
    public function show(ShowHoldingDetailRequest $request, Holding $holding, ShowHoldingDetailAction $action): JsonResponse
    {
        $data = $action->execute($holding, $request->validated('chart_period'));

        return response()->json(['data' => $data]);
    }

    public function storeMemo(SaveHoldingMemoRequest $request, Holding $holding, SaveHoldingMemoAction $action): JsonResponse
    {
        $memo = $action->execute($holding, $request->validated('memo'));

        return response()->json([
            'data' => [
                'body' => $memo->body,
                'recorded_at' => $memo->recorded_at,
            ],
        ], 201);
    }
}
