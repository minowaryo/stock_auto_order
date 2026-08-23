<?php

namespace App\Actions\WatchedTheme;

use App\Models\WatchedTheme;

/**
 * UC-008 (注目テーマ・セクターの登録・更新): registers a new watched theme.
 * Duplicate-name rejection is handled at the validation layer
 * (StoreWatchedThemeRequest), so this Action only performs the create.
 */
class StoreWatchedThemeAction
{
    public function execute(string $name): WatchedTheme
    {
        return WatchedTheme::create(['name' => $name]);
    }
}
