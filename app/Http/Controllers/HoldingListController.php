<?php

namespace App\Http\Controllers;

use App\Actions\Holding\ListHoldingsAction;
use App\Http\Requests\ListHoldingsRequest;
use Illuminate\Http\JsonResponse;

/**
 * UC-002 (保有銘柄一覧表示). Kept thin: validation lives in
 * ListHoldingsRequest, business logic lives in ListHoldingsAction
 * (.claude/rules/10-laravel.md).
 */
class HoldingListController extends Controller
{
    public function index(ListHoldingsRequest $request, ListHoldingsAction $action): JsonResponse
    {
        $rows = $action->execute(
            sector: $request->validated('sector'),
            signalOnly: $request->boolean('signal_only'),
        );

        return response()->json(['data' => $rows]);
    }
}
