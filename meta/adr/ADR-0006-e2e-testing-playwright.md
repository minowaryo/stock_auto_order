# ADR-0006: E2Eテストフレームワークの選定（Playwright）

## Status
Accepted

## Date
2026-07-15

## Context

`.claude/rules/30-testing.md` ではテストピラミッドの最上段として「E2E Test（クリティカルなユーザーフロー）」を定義していたが、具体的な実行ツール・配置場所・実行コマンドが未定義だった。
`meta/adr/ADR-0005-frontend-stack.md` によりプロジェクトごとにフロントエンドスタックを選定する運用になったが、
デフォルト推奨である Vue 3 + Inertia.js を含め、いずれのスタックでもブラウザ上でのユーザー操作（画面遷移・フォーム入力・バリデーション表示）を実際に検証するUI統合テストの手段が必要になる。

検討した選択肢:

1. **Playwright**
2. **Cypress**
3. **Laravel Dusk**

## Decision

**Playwright**（`@playwright/test`）を採用する。

- 配置場所: `tests/e2e/`
- 対象: `docs/product/use-cases.md` に記載されたクリティカルフローのみ（網羅目的では使わない）
- 実行対象アプリ: `php artisan serve` で起動したLaravelアプリ（画面描画は `meta/adr/ADR-0005-frontend-stack.md` で選定したフロントエンドスタックによる。Blade / Livewire / Inertia いずれの場合も Playwright は適用可能）

## Rationale

### Playwright を選んだ理由
- 複数ブラウザエンジン（Chromium / Firefox / WebKit）に単一APIで対応でき、CI環境でも安定動作する
- 自動待機（auto-waiting）機構により、フルリロードなしのSPA的遷移（Vue/React + Inertia.js選定時）で発生しやすい要素未描画によるflakyテストを抑制できる。Blade / Livewire 等のフルリロード遷移でも同様に安定動作する
- トレース・スクリーンショット・動画記録が標準機能で、失敗時の原因調査コストが低い
- TypeScriptとの親和性が高く、フロントエンドが Vue 3 / React + TypeScript（任意）の場合に型を共有しやすい
- フロントエンドスタックの選定（`meta/adr/ADR-0005-frontend-stack.md`）によらず同一のE2Eツールを使い回せるため、プロジェクト間でテスト運用ノウハウを共有しやすい

### 採用しなかった代替案
- **Cypress**: マルチタブ・マルチオリジンの制約があり、Inertia経由の外部リダイレクト（決済等）を伴うフローで不利
- **Laravel Dusk**: PHP側にテストコードが混在し、フロントエンドの変更をフロントエンド側の知識だけでテストできない。ChromeDriverの管理コストも発生する

## Consequences

### メリット
- クリティカルフロー（会員登録・決済・認可エラー画面遷移等）を実ブラウザで検証でき、Feature Testでは検出できないUI崩れ・JS例外を捕捉できる
- CIでのマルチブラウザ実行が容易

### デメリット・リスク
- Node.jsのビルド・実行環境がテストのためだけに追加で必要になる
- E2Eテストは実行時間が長いため、対象を「クリティカルフローのみ」に絞る運用規律が必要（範囲が広がるとCI時間が肥大化する）
- Laravelアプリ起動（`php artisan serve` or `php artisan test` 用サーバー）とPlaywrightの起動タイミングを同期する設定が必要

### 実装ルール
詳細な配置規約・命名規則・実行コマンドは `.claude/rules/30-testing.md` を参照。

## Related
- `.claude/rules/30-testing.md`
- `docs/development/testing-strategy.md`
- `docs/ai-context/common-commands.md`
- ADR-0005-frontend-stack
