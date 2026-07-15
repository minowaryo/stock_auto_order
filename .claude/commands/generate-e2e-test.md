# /generate-e2e-test — Playwright E2Eテスト生成コマンド

引数で指定したUC番号のクリティカルフローに対するPlaywright E2Eテストの叩き台を生成してください。

## 実行前に読み込むファイル

- `docs/product/use-cases.md`
- `.claude/rules/30-testing.md`（「E2E Test（Playwright）」セクション）
- `docs/product/mockups/`（存在する場合、画面構造の確認用）

## 手順

1. `use-cases.md` から指定UCの操作フロー（画面遷移・入力・期待結果）を把握する
2. Playwright MCP（`.mcp.json` の `playwright` サーバー、利用可能な場合）でローカル起動中のアプリ（`php artisan serve` 想定）に接続し、実際のDOM構造・アクセシビリティ属性を確認してからセレクタを決定する
   - Playwright MCPが利用できない場合は、`resources/js/Pages/` の該当コンポーネントを読み、`getByLabel` / `getByRole` に使えそうな属性を確認する
3. `tests/e2e/{UC番号}-{フロー概要}.spec.ts` として保存する（命名規則は `.claude/rules/30-testing.md` を参照）
4. 生成後、`npx playwright test tests/e2e/{ファイル名}` を実行し、対象画面が未実装の場合は失敗することを確認する

## 制約

- 対象は**クリティカルフローのみ**（use-cases.mdに記載のないフローを追加しない。網羅目的の乱用禁止）
- 実データ・実認証情報を使わない（テスト用Factoryやシードデータのみ）
- Playwright MCPで本番URL・実データ環境に接続しない（ローカル開発環境限定）

## 使用例

```
/generate-e2e-test UC-001
```

→ `tests/e2e/uc001-user-registration.spec.ts` を生成する
