<?php

namespace Tests\Feature;

use App\Models\User;

/*
|--------------------------------------------------------------------------
| 共通レイアウト — 回帰防止テスト
|--------------------------------------------------------------------------
|
| Phase3（UC-002保有銘柄一覧画面）の実ブラウザ確認（Playwright MCP）で、
| resources/views/components/layouts/app.blade.php に
| <meta name="csrf-token"> が欠落しているためLivewireのwire:submit/
| wire:model.live等のAJAXリクエストが実ブラウザでは無反応になる不具合を
| 発見した。Livewire::test()はブラウザのJS/AJAX層を経由しないため、
| これまでのFeature Test（Login含む）では検出できなかった。
|
*/

describe('共通レイアウト', function () {
    test('csrf-tokenのmetaタグが出力される（Livewireのwire:submit等に必須）', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/csv-import');

        $response->assertSuccessful();
        $response->assertSee('<meta name="csrf-token" content="', false);
    });
});
