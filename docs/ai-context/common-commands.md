# common-commands.md — よく使うコマンド集

## テスト

```bash
# 全テスト実行
php artisan test

# 特定ファイル
php artisan test tests/Feature/UserTest.php

# カバレッジ付き
php artisan test --coverage

# 並列実行（高速）
php artisan test --parallel
```

## E2Eテスト（Playwright）

> 詳細は `.claude/rules/30-testing.md` の「E2E Test（Playwright）」を参照。

```bash
# 初回セットアップ
npm install -D @playwright/test
npx playwright install

# 全E2Eテスト実行
npx playwright test

# 特定ファイルのみ
npx playwright test tests/e2e/uc01-user-registration.spec.ts

# UIモード（デバッグ用）
npx playwright test --ui

# 直近の失敗レポート表示
npx playwright show-report
```

## Playwright MCP（ブラウザ操作ツール・任意導入）

> `.mcp.json` に定義済み。詳細は `docs/adr/ADR-0008-tdd-e2e-harness-tooling.md` を参照。
> ローカル開発環境限定で使用し、本番URL・実データ環境には接続しない。

```bash
# 初回利用時、Claude Codeが .mcp.json の承認プロンプトを表示するので許可する
# （手動で個別に追加する場合）
claude mcp add playwright npx @playwright/mcp@latest
```

## TDD強制ツール（Probity・任意導入）

> 詳細は `docs/adr/ADR-0007-tdd-enforcement-probity.md` を参照。

```bash
# 初回セットアップ
npm install -D @nizos/probity

# ルール違反チェック（probity.config.ts に基づく）
npx probity check
```

## コードスタイル

```bash
# フォーマット（自動修正）
./vendor/bin/pint

# チェックのみ（修正なし）
./vendor/bin/pint --test

# 静的解析
./vendor/bin/phpstan analyse
```

## データベース

```bash
# マイグレーション実行
php artisan migrate

# ロールバック
php artisan migrate:rollback

# DB リセット（開発環境のみ）
php artisan migrate:fresh --seed

# マイグレーション状態確認
php artisan migrate:status
```

## アプリケーション

```bash
# 開発サーバー起動
php artisan serve

# キャッシュクリア
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# キュー起動（開発）
php artisan queue:work

# スケジューラ起動（開発）
php artisan schedule:work
```

## コード生成（Artisan）

```bash
# コントローラ
php artisan make:controller UserController --resource

# モデル + マイグレーション + Factory + Seeder
php artisan make:model User -mfs

# FormRequest
php artisan make:request StoreUserRequest

# Policy
php artisan make:policy UserPolicy --model=User

# Action / Service（カスタム）
php artisan make:class Actions/RegisterUserAction
```

## Composer

```bash
# 依存関係インストール
composer install

# パッケージ追加
composer require [package]

# 脆弱性チェック
composer audit

# オートロード再生成
composer dump-autoload
```
