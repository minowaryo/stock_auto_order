# 30-testing.md — テスト方針

## テスト優先順位

1. **Feature Test（最優先）**: HTTPリクエスト〜レスポンスの統合テスト
2. **Unit Test**: 複雑なビジネスロジック・計算ロジック
3. **E2E Test（Playwright）**: クリティカルなユーザーフロー（詳細は本ファイル末尾を参照）

## テスト作成前に読むファイル

- `docs/product/use-cases.md` — テストケース名・網羅範囲の導出元（UCが正常系/異常系/権限の基準）
- `docs/architecture/data-model.md` — DBアサーション・Factoryの型・制約の確認
- `docs/product/mockups/` — E2Eテストの画面構造・操作フローの確認（存在する場合）

## 基本ルール

- 変更には必ずFeature Testを追加する
- バグ修正時は再発防止テストを先に書く（TDD）
- テストはDBをモックしない（実DBを使う）
- Factoryを活用してテストデータを生成する
- テストケース名は `use-cases.md` のUCタイトル・フローを基に命名する

---

## TDDワークフロー（Claude Code / Codex 共通）

> 関連ADR: `docs/adr/ADR-0007-tdd-enforcement-probity.md`

Claude Code / Codex は「実装を先に書いてからテストを後付けする」挙動を取りやすい。
バグ修正に限らず、**通常の機能開発でもRed → Green → Refactorのサイクルを明示的に指示する**こと。

### サイクル

1. **Red**: 失敗するテストのみを書かせる。同じターンで実装コードを書かせない
2. **Green**: そのテストを通す最小限の実装のみを書かせる（テスト要件を超える実装をさせない）
3. **Refactor**: テストがGreenのままリファクタさせる

### プロンプト例

```
# Red
「〇〇の失敗するFeature Testを書いてください。実装コードはまだ書かないでください。」

# Green
「このテストを通す最小限の実装をしてください。テストが要求する以上のことはしないでください。」

# Refactor
「テストをGreenに保ったまま、実装を整理してください。」
```

### Gate 4 — テストケース承認

- RedフェーズとGreenフェーズを1リクエストにまとめない（AIがテストを実装に迎合させやすくなるため）
- Redフェーズでテストを書かせた後、**Greenフェーズ（実装）に進む前に必ず人間がテスト内容をレビュー・承認する**（`.claude/rules/00-global.md` の Gate 4）
  - 確認観点: 意図した仕様どおりにテストが失敗しているか、テストケースが `use-cases.md` の正常系/異常系/権限を網羅しているか
  - `/tdd` コマンドはこの承認を得るまでGreenフェーズに自動で進まない
- 機械的に強制したい場合は `@nizos/probity`（`docs/adr/ADR-0007-tdd-enforcement-probity.md` 参照）の導入を検討する。ただし導入有無に関わらずこのガイドラインは適用する

### Greenフェーズ完了後のスキル実行

「テストが通った」＝「機能が動く」とは限らない（テストのモック漏れ・カバー不足の可能性があるため）。Greenフェーズ完了時は以下を実行してから次のフェーズに進む。

1. **`verify` スキル**を実行し、実際に機能を動かして期待通りに動作するか確認する
2. 対象がUCのクリティカルフロー（`docs/product/use-cases.md`）かつUI変更を含む場合、**`/generate-e2e-test`** でPlaywright E2Eテストを追加する
3. Refactor完了後、マージ前に **`/review`** を実行する（`.claude/rules/50-review.md` 参照）

## 命名規則

```php
// Feature Test: 何をテストするか明確に
test('管理者はユーザー一覧を取得できる', function () { ... });
test('一般ユーザーはユーザー一覧にアクセスできない', function () { ... });
test('未認証ユーザーはログインページにリダイレクトされる', function () { ... });
```

## テスト構造（AAA パターン）

```php
test('example', function () {
    // Arrange: テストデータ・前提条件を準備
    $user = User::factory()->create();

    // Act: テスト対象の処理を実行
    $response = $this->actingAs($user)->get('/dashboard');

    // Assert: 期待する結果を検証
    $response->assertOk();
});
```

## 必ずテストすること

- [ ] 正常系（ハッピーパス）
- [ ] 認証・認可（未認証/権限なし）
- [ ] バリデーションエラー
- [ ] 境界値・エッジケース
- [ ] 削除・更新の副作用

## コマンド

```bash
# 全テスト実行
php artisan test

# 特定ファイルのみ
php artisan test tests/Feature/UserTest.php

# カバレッジ確認
php artisan test --coverage
```

---

## E2E Test（Playwright）

> 関連ADR: `docs/adr/ADR-0006-e2e-testing-playwright.md`

### 対象範囲

- `docs/product/use-cases.md` に記載された**クリティカルフローのみ**を対象とする（網羅目的でPlaywrightを使わない）
- 例: 会員登録〜ログイン、決済フロー、権限別の画面遷移制御
- 画面単体の表示崩れ・個別バリデーションはFeature Test（サーバーサイド）で担保し、Playwrightでは重複させない

### 配置・命名規則

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

### Inertia.js 特有の注意点

- Inertiaはフルリロードなしで画面遷移するため、`page.click()` 直後に要素を検証すると描画前に評価してしまうことがある。**遷移後は必ず `page.waitForURL()` または遷移先の要素の可視化待ち（`toBeVisible()` の自動リトライ）を使う**
- `document.querySelector` 等のDOM直接操作はテストコードでも避け、Playwrightの `getByRole` / `getByLabel` 等のロールベースセレクタを使う（`.claude/rules/15-vue.md` のDOM直接操作禁止と方針を揃える）
- バリデーションエラー表示は `useForm()` の `errors` に依存するため、フォーム送信後は該当メッセージの表示を明示的に待つ

### 実行環境

- 対象アプリは `php artisan serve` で起動したLaravel（Inertia経由でVueを描画）
- `playwright.config.ts` の `webServer` オプションでLaravelサーバーの自動起動・待受けを設定する

### コマンド

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

### 実行結果の可視化（任意・デバッグ時のみ）

**通常の実行（CI・日常確認）は常に上記のheadless `npx playwright test` を使う**（最速・画面表示なし）。
失敗原因を人間が目視で調べたいときだけ、以下を個別に使う。

| 方法 | コマンド | 用途 |
|---|---|---|
| UI Mode | `npx playwright test --ui` | ステップ単位の実行・時間軸を巻き戻してのデバッグ（実行速度は遅い） |
| HTMLレポート | `npx playwright show-report` | headless実行後の結果一覧・スクリーンショット・動画確認（追加実行不要） |
| Trace Viewer | `npx playwright show-trace trace.zip` | 失敗トレースの詳細調査（ネットワーク・DOM・操作履歴） |
