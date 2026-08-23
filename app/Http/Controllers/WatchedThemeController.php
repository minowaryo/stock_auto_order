<?php

namespace App\Http\Controllers;

use App\Actions\WatchedTheme\ShowWatchedThemeListAction;
use App\Actions\WatchedTheme\StoreWatchedThemeAction;
use App\Http\Requests\StoreWatchedThemeRequest;
use Illuminate\Http\JsonResponse;

/**
 * UC-008 (注目テーマ・セクターの登録・更新). Kept thin: validation lives in
 * the FormRequest, business logic lives in the Actions
 * (.claude/rules/10-laravel.md).
 */
class WatchedThemeController extends Controller
{
    public function store(StoreWatchedThemeRequest $request, StoreWatchedThemeAction $action): JsonResponse
    {
        $watchedTheme = $action->execute($request->validated('name'));

        return response()->json([
            'data' => [
                'id' => $watchedTheme->id,
                'name' => $watchedTheme->name,
            ],
        ], 201);
    }

    public function index(ShowWatchedThemeListAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute()]);
    }
}
