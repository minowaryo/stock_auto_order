<?php

namespace App\Actions\WatchedTheme;

use App\Models\WatchedTheme;

/**
 * UC-008 (注目テーマ・セクターの登録・更新): lists all registered watched
 * themes as {id, name} rows.
 */
class ShowWatchedThemeListAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(): array
    {
        return WatchedTheme::query()
            ->get(['id', 'name'])
            ->map(fn (WatchedTheme $watchedTheme) => [
                'id' => $watchedTheme->id,
                'name' => $watchedTheme->name,
            ])
            ->values()
            ->all();
    }
}
