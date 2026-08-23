<?php

namespace App\Http\Controllers;

use App\Actions\Sector\ShowSectorDashboardAction;
use Illuminate\Http\JsonResponse;

/**
 * UC-005 (セクター配分ダッシュボード). Kept thin: business logic lives in
 * ShowSectorDashboardAction / SectorAllocationCalculator (.claude/rules/10-laravel.md).
 * No input is accepted, so no FormRequest is needed.
 */
class SectorDashboardController extends Controller
{
    public function index(ShowSectorDashboardAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute()]);
    }
}
