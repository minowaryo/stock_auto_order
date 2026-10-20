# e2e-testing.md — E2E Test（Playwright）

> 関連ADR: `meta/adr/ADR-0006-e2e-testing-playwright.md`
> Feature Test / Unit Test / TDDワークフローの方針は `.claude/rules/30-testing.md` を参照。
> このファイルは `/generate-e2e-test` 実行時など、E2Eテストを実装する時にのみ読む（毎セッション読み込まれるファイルではなく、通常のTDDサイクルでも読まない）。

## 対象範囲

- `docs/product/use-cases.md` に記載された**クリティカルフローのみ**を対象とする（網羅目的でPlaywrightを使わない）
- 例: 会員登録〜ログイン、決済フロー、権限別の画面遷移制御
- 画面単体の表示崩れ・個別バリデーションはFeature Test（サーバーサイド）で担保し、Playwrightでは重複させない

## 配置・命名規則

| 項目 | 規則 |
|---|---|
| 配置場所 | `tests/e2e/` |
| ファイル名 | `uc{UC番号}-{フロー概要}.spec.ts`（例: `uc001-user-registration.spec.ts`） |
| テスト名 | `use-cases.md` のUCタイトルを基に日本語で記述 |

```ts
// tests/e2e/uc001-user-registration.spec.ts
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
npx playwright test tests/e2e/uc001-user-registration.spec.ts

# 直近の実行結果レポート表示（成功/失敗一覧・スクリーンショット・動画）
npx playwright show-report
```

## 変わった画面とスクリーンショット（マージ計画）

`prepare-merge` は、ブランチで追加・変更された画面を挙げ、新しい画面を撮影する。人がアプリを起動せずに、
役割ごとの見た目を確認できるようにするためである。この節を読むのは、撮影を始めるときだけにする。
画像は自分では開かない——保存してパスを示し、見るのは人である。

**どの画面か** — git だけで判定し、書かれたとおりに実行する（`prepare-merge` ステップ2）:

```bash
base=$(git merge-base HEAD "${REVIEW_SCORE_BASE_BRANCH:-main}")
git diff --name-status "$base" -- resources/js/Pages resources/js/pages resources/js/Components resources/js/components resources/js/Layouts resources/js/layouts resources/views
git ls-files --others --exclude-standard -- resources/js/Pages resources/js/pages resources/views
```

ページが `A` または未追跡なら**新規**、`D` 以外のそれ以外は**変更**とし、ファイル名そのままで挙げる
（コンポーネントはコンポーネント名で挙げる）。存在しないパスは何にも一致しないので、スタックごとに
書き換える必要はない。

**撮影** — `playwright.config.ts` と `tests/e2e/` があるときだけ行う。新規画面は自動で撮り、変更された
画面はユーザーが求めたとき（「撮って」/ "take screenshots"）に撮る。変更されたコンポーネントについては、
それを使っていると分かっているページを選ぶ。

1. 撮る画面には、それぞれ `tests/e2e/screens.ts`（コミットする）に1行が必要である。足りない行を足す——
   URL と役割だけで、画面を開く手順のコードは書かない。`roles` には見え方が違う役割だけを挙げる
   （例: ある役割にはボタンがあり、別の役割にはない）。最低1つ。操作が必要な状態（開いたモーダルなど）は、
   最初に表示された状態で撮る。

   ```ts
   // tests/e2e/screens.ts — one line per screen, read by screen-capture.spec.ts
   export type Screen = { page: string; uc: string; name: string; url: string; roles: string[] };

   export const screens: Screen[] = [
     { page: 'Schedules/Edit', uc: 'UC-042', name: 'schedule-edit', url: '/schedules/1/edit', roles: ['section-head', 'staff'] },
   ];
   ```

2. 初回だけ、撮影用スペックとログイン用ヘルパーを作る（ヘルパーは、E2E のシードデータにあるその役割の
   ユーザーでログインする）:

   ```ts
   // tests/e2e/screen-capture.spec.ts — runs only when CAPTURE_SCREENS is set
   import { test } from '@playwright/test';
   import { screens } from './screens';
   import { loginAs } from './support/login';

   const wanted = (process.env.CAPTURE_SCREENS ?? '').split(',').map((s) => s.trim()).filter(Boolean);

   for (const screen of screens.filter((s) => wanted.includes(s.page))) {
     for (const role of screen.roles) {
       const file = `${screen.uc.replace('-', '').toLowerCase()}_${screen.name}_${role}.png`;
       test(`capture ${file}`, async ({ page }) => {
         await loginAs(page, role);
         await page.goto(screen.url);
         await page.waitForLoadState('networkidle');
         await page.screenshot({ path: `tests/e2e/.screenshots/${file}`, fullPage: true });
       });
     }
   }
   ```

   ```ts
   // tests/e2e/support/login.ts — map each role to a user the E2E seed creates
   import type { Page } from '@playwright/test';

   const users: Record<string, { login: string; password: string }> = {
     // 'section-head': { login: '...', password: '...' },  (seed data only — never real accounts)
   };

   export async function loginAs(page: Page, role: string) {
     const user = users[role];
     if (!user) throw new Error(`no E2E user for role "${role}"`);
     await page.goto('/login');
     // fill the project's login form with user.login / user.password, submit, wait for the next page
   }
   ```

3. 実行する（先にフォルダを空にする。`--reporter=line` は出力を短くし、HTML レポートが開いてコマンドが
   止まるのを防ぐ）:

   ```bash
   rm -rf tests/e2e/.screenshots && CAPTURE_SCREENS="Schedules/Index,Schedules/Edit" npx playwright test tests/e2e/screen-capture.spec.ts --reporter=line
   ```

画像は `tests/e2e/.screenshots/`（git の管理外）に `{uc}_{screen}_{role}.png` の名前で保存される
（例: `uc042_schedule-edit_section-head.png`）。撮れない画面（シードのレコードや役割のユーザーがない、
ページのエラー、サーバーが起動しない）があっても何も止めない。失敗の出力から最初の理由を拾い、通すために
シーダーを変えることはしない。

**マージ計画には1行** — 応答の言語で、名前はフォルダより下のパスから拡張子を除いたもの、最大5件で
残りは「ほか N 件」:

```
画面: 新規 Schedules/Index（スクショ2枚 → tests/e2e/.screenshots/）、変更 Schedules/Edit・DatePicker ほか3件 — 変更分は「撮って」と言えば撮ります
```

画面の変更がなければ行を出さない。Playwright がなければ名前だけにする。ユーザーがスクリーンショットを
求めたときは、その応答で画像ごとに1行（画面 × 役割 → パス）を並べる。

## 実行結果の可視化（任意・デバッグ時のみ）

**通常の実行（CI・日常確認）は常に上記のheadless `npx playwright test` を使う**（最速・画面表示なし）。
失敗原因を人間が目視で調べたいときだけ、以下を個別に使う。

| 方法 | コマンド | 用途 |
|---|---|---|
| UI Mode | `npx playwright test --ui` | ステップ単位の実行・時間軸を巻き戻してのデバッグ（実行速度は遅い） |
| HTMLレポート | `npx playwright show-report` | headless実行後の結果一覧・スクリーンショット・動画確認（追加実行不要） |
| Trace Viewer | `npx playwright show-trace trace.zip` | 失敗トレースの詳細調査（ネットワーク・DOM・操作履歴） |
