<?php

namespace App\Http\Controllers;

use App\Actions\Market\ShowMarketIndicatorAction;
use Illuminate\Http\JsonResponse;

/**
 * UC-007 (市場全体指標表示). Kept thin: business logic lives in
 * ShowMarketIndicatorAction (.claude/rules/10-laravel.md).
 * No input is accepted, so no FormRequest is needed.
 */
class MarketIndicatorController extends Controller
{
    public function index(ShowMarketIndicatorAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute()]);
    }
}
