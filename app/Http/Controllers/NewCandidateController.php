<?php

namespace App\Http\Controllers;

use App\Actions\Candidate\ShowNewCandidateListAction;
use Illuminate\Http\JsonResponse;

/**
 * UC-008 (新規投資候補レコメンド・軽量版). Kept thin: business logic lives in
 * ShowNewCandidateListAction / NewCandidateFinder (.claude/rules/10-laravel.md).
 * No input is accepted, so no FormRequest is needed.
 */
class NewCandidateController extends Controller
{
    public function index(ShowNewCandidateListAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute()]);
    }
}
