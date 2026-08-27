<?php

namespace App\Http\Controllers;

use App\Actions\Signal\ShowBuySignalListAction;
use Illuminate\Http\JsonResponse;

/**
 * UC-010 (既存保有株の買い増しタイミングレコメンド一覧). Kept thin: business
 * logic lives in ShowBuySignalListAction (.claude/rules/10-laravel.md). No
 * input is accepted, so no FormRequest is needed.
 */
class BuySignalListController extends Controller
{
    public function index(ShowBuySignalListAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute()]);
    }
}
