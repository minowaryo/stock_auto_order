<?php

namespace App\Http\Controllers;

use App\Actions\Signal\ShowSignalListAction;
use Illuminate\Http\JsonResponse;

/**
 * UC-004 (利確シグナル一覧). Kept thin: business logic lives in
 * ShowSignalListAction (.claude/rules/10-laravel.md). No input is accepted,
 * so no FormRequest is needed.
 */
class SignalListController extends Controller
{
    public function index(ShowSignalListAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute()]);
    }
}
