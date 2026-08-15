# 31-e2e-testing.md — E2E Test（Playwright）

> 関連ADR: `meta/adr/ADR-0006-e2e-testing-playwright.md`
> Feature Test / Unit Test / TDDワークフローの方針は `.claude/rules/30-testing.md` を参照。
> このファイルは `/generate-e2e-test` 実行時など、E2Eテストを実装する時にのみ読む（通常のTDDサイクルでは読まない）。

## 対象範囲

- `docs/product/use-cases.md` に記載された**クリティカルフローのみ**を対象とする（網羅目的でPlaywrightを使わない）
- 例: 会員登録〜ログイン、決済フロー、権限別の画面遷移制御
- 画面単体の表示崩れ・個別バリデーションはFeature Test（サーバーサイド）で担保し、Playwrightでは重複させない

## 配置・命名規則

| 項目 | 規則 |
|---|---|
| 配置場所 | `tests/e2e/` |
| ファイル名 | `{UC番号}-{フロー概要}.spec.ts`（例: `uc01-user-registration.spec.ts`） |
| テスト名 | `use-cases.md` のUCタイトルを基に日本語で記述 |

```ts
// tests/e2e/uc01-user-registration.spec.ts
import { test, expect } from '@playwright/test';

test('ユーザーは会員登録フォームからアカウントを作成できる', async ({ page }) => {
  await page.goto('/register');
  await page.getByLabel('メールアドレス').fill('test@example.com');
  await page.getByLabel('パスワード').fill('password');
  await page.getByRole('button', { name: '登録する' }).click();

  await page.waitForURL('/dashboard');
  await expect(page.getByText('ようこそ')).toBeVisible();
});
```

## Inertia.js 特有の注意点（`meta/adr/ADR-0005-frontend-stack.md` で Vue/React + Inertia.js を選定した場合）

- Inertiaはフルリロードなしで画面遷移するため、`page.click()` 直後に要素を検証すると描画前に評価してしまうことがある。**遷移後は必ず `page.waitForURL()` または遷移先の要素の可視化待ち（`toBeVisible()` の自動リトライ）を使う**
- `document.querySelector` 等のDOM直接操作はテストコードでも避け、Playwrightの `getByRole` / `getByLabel` 等のロールベースセレクタを使う（`.claude/rules/15-frontend.md` のDOM直接操作禁止と方針を揃える）
- バリデーションエラー表示は `useForm()` の `errors` に依存するため、フォーム送信後は該当メッセージの表示を明示的に待つ
- Blade / Livewire 等、Inertia を使わないスタックを選定した場合はこの節は対象外（通常のフルリロード遷移として `page.waitForURL()` / 要素の可視化待ちを使う）

## 実行環境

- 対象アプリは `php artisan serve` で起動したLaravelアプリ（画面描画は選定したフロントエンドスタックによる。Vue+Inertia選定時は Inertia 経由で Vue を描画）
- `playwright.config.ts` の `webServer` オプションでLaravelサーバーの自動起動・待受けを設定する

## コマンド

```bash
# 初回セットアップ
npm install -D @playwright/test
npx playwright install

# 全E2Eテスト実行
npx playwright test

# 特定ファイルのみ
npx playwright test tests/e2e/uc01-user-registration.spec.ts

# 直近の実行結果レポート表示（成功/失敗一覧・スクリーンショット・動画）
npx playwright show-report
```

## 実行結果の可視化（任意・デバッグ時のみ）

**通常の実行（CI・日常確認）は常に上記のheadless `npx playwright test` を使う**（最速・画面表示なし）。
失敗原因を人間が目視で調べたいときだけ、以下を個別に使う。

| 方法 | コマンド | 用途 |
|---|---|---|
| UI Mode | `npx playwright test --ui` | ステップ単位の実行・時間軸を巻き戻してのデバッグ（実行速度は遅い） |
| HTMLレポート | `npx playwright show-report` | headless実行後の結果一覧・スクリーンショット・動画確認（追加実行不要） |
| Trace Viewer | `npx playwright show-trace trace.zip` | 失敗トレースの詳細調査（ネットワーク・DOM・操作履歴） |
