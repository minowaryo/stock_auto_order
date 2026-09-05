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

    /*
    |----------------------------------------------------------------------
    | CHG-0008: 最新サマリーレポートのタブ化 — ナビゲーション回帰テスト
    |----------------------------------------------------------------------
    |
    | resources/views/components/layouts/app.blade.php の $navItems に
    | 'summary-report' エントリ（href="/summary-report" ・ ラベル
    | 「サマリーレポート」）がまだ追加されていないため、Red state では
    | assertSee('href="/summary-report"', false) が「そのような文字列は
    | レスポンスに含まれない」という理由で失敗する想定（既存の5タブの
    | href/ラベルはこのテスト追加時点でも変更していないため、他の既存
    | LayoutTestのテストケースには影響しない）。
    |
    */
    test('ナビゲーションに「サマリーレポート」タブ（/summary-report）が表示される', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/holdings');

        $response->assertSuccessful();
        $response->assertSee('href="/summary-report"', false);
        $response->assertSee('サマリーレポート');
    });
});
