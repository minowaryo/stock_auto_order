{{--
    docs/product/ui-guidelines.md確定のナビゲーション方針（5タブ上限）に基づく
    共通レイアウト。UC-003（銘柄詳細）・UC-009（サマリーレポート）は
    ナビゲーションタブを持たないため $active を渡さない。
--}}
@props(['title' => null, 'active' => null])
<!DOCTYPE html>
<html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ? $title.' | ポートフォリオ管理' : 'ポートフォリオ管理' }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="bg-app-bg text-text text-sm antialiased" style="font-variant-numeric: tabular-nums;">
        <header class="bg-surface border-b border-app-border h-14 flex items-center px-6 gap-8">
            <div class="text-base font-bold text-primary">📊 ポートフォリオ管理</div>
            @auth
                <nav class="flex gap-1">
                    {{--
                        フェーズ単位で画面を追加していくため、未実装フェーズの
                        画面へのリンクも含め、名前付きルートではなくリテラルな
                        URL文字列で統一する（stock_auto_order-frontend-
                        implementation-phase.md確定のパス）。
                    --}}
                    @php
                        $navItems = [
                            'holdings' => ['label' => '保有一覧', 'url' => '/holdings'],
                            'signals' => ['label' => '売買シグナル', 'url' => '/signals'],
                            'sector-dashboard' => ['label' => 'セクター配分', 'url' => '/sector-dashboard'],
                            'candidate-check' => ['label' => '新規投資候補', 'url' => '/candidate-check'],
                            'csv-import' => ['label' => 'CSV取込', 'url' => '/csv-import'],
                        ];
                    @endphp
                    @foreach ($navItems as $key => $item)
                        <a
                            href="{{ $item['url'] }}"
                            wire:navigate
                            class="px-3 py-2 rounded-md text-[13px] font-medium {{ $active === $key ? 'text-primary bg-blue-50' : 'text-text-secondary hover:bg-app-bg' }}"
                        >{{ $item['label'] }}</a>
                    @endforeach
                </nav>
                <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                    @csrf
                    <button type="submit" class="text-[13px] text-text-secondary hover:text-text">ログアウト</button>
                </form>
            @endauth
        </header>
        <main class="max-w-[1200px] mx-auto p-6">
            {{ $slot }}
        </main>
        @livewireScripts
    </body>
</html>
