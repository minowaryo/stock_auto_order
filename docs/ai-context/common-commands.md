# common-commands.md — よく使うコマンド集

## 実行環境について（重要）

本マシンにはローカルPHP/Composer/MySQLがインストールされていない。Docker Desktop上のLaravel Sail（`compose.yaml`、PHP 8.5 + MySQL 8.4コンテナ）で開発する。

**`./vendor/bin/sail`ラッパースクリプトはWSL2/macOS/Linux専用で、Windows+Git Bash単体では動作しない**（`Unsupported operating system [MINGW64_NT-...]`エラーになる）。そのため本プロジェクトでは`sail`コマンドの代わりに`docker compose exec`を直接使う。

```bash
# コンテナ起動（初回・再起動時）
docker compose up -d

# コンテナ停止
docker compose down

# 起動状態確認
docker compose ps

# 以降の全コマンドは "docker compose exec laravel.test <コマンド>" で実行する
# 例: php artisan test → docker compose exec laravel.test php artisan test
```

以下の各コマンド例は、コンテナが起動済み（`docker compose up -d`実行済み）の状態を前提に、実際に動作確認済みの形式で記載する。

## テスト

```bash
# 全テスト実行
docker compose exec laravel.test php artisan test

# 特定ファイル
docker compose exec laravel.test php artisan test tests/Feature/UserTest.php

# カバレッジ付き
docker compose exec laravel.test php artisan test --coverage

# 並列実行（高速）
docker compose exec laravel.test php artisan test --parallel
```

## E2Eテスト（Playwright）

> 詳細は `.claude/rules/31-e2e-testing.md` を参照。

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

> `.mcp.json` に定義済み。詳細は `meta/adr/ADR-0008-tdd-e2e-harness-tooling.md` を参照。
> ローカル開発環境限定で使用し、本番URL・実データ環境には接続しない。

```bash
# 初回利用時、Claude Codeが .mcp.json の承認プロンプトを表示するので許可する
# （手動で個別に追加する場合）
claude mcp add playwright npx @playwright/mcp@latest
```

## TDD強制ツール（Probity・任意導入）

> 詳細は `meta/adr/ADR-0007-tdd-enforcement-probity.md` を参照。

```bash
# 初回セットアップ
npm install -D @nizos/probity

# ルール違反チェック（probity.config.ts に基づく）
npx probity check
```

## コードスタイル

```bash
# フォーマット（自動修正）
docker compose exec laravel.test ./vendor/bin/pint

# チェックのみ（修正なし）
docker compose exec laravel.test ./vendor/bin/pint --test

# 静的解析（導入時）
docker compose exec laravel.test ./vendor/bin/phpstan analyse
```

## データベース

```bash
# マイグレーション実行
docker compose exec laravel.test php artisan migrate

# ロールバック
docker compose exec laravel.test php artisan migrate:rollback

# DB リセット（開発環境のみ）
docker compose exec laravel.test php artisan migrate:fresh --seed

# マイグレーション状態確認
docker compose exec laravel.test php artisan migrate:status
```

## アプリケーション

```bash
# コンテナ起動（laravel.test は80番ポートでhttp://localhost に公開済み。serveコマンドは不要）
docker compose up -d

# キャッシュクリア
docker compose exec laravel.test php artisan cache:clear
docker compose exec laravel.test php artisan config:clear
docker compose exec laravel.test php artisan route:clear
docker compose exec laravel.test php artisan view:clear

# キュー起動（開発）
docker compose exec laravel.test php artisan queue:work

# スケジューラ起動（開発）
docker compose exec laravel.test php artisan schedule:work
```

## コード生成（Artisan）

```bash
# コントローラ
docker compose exec laravel.test php artisan make:controller UserController --resource

# モデル + マイグレーション + Factory + Seeder
docker compose exec laravel.test php artisan make:model User -mfs

# FormRequest
docker compose exec laravel.test php artisan make:request StoreUserRequest

# Policy
docker compose exec laravel.test php artisan make:policy UserPolicy --model=User

# Action / Service（カスタム）
docker compose exec laravel.test php artisan make:class Actions/RegisterUserAction
```

## Composer

```bash
# 依存関係インストール
docker compose exec laravel.test composer install

# パッケージ追加
docker compose exec laravel.test composer require [package]

# 脆弱性チェック
docker compose exec laravel.test composer audit

# オートロード再生成
docker compose exec laravel.test composer dump-autoload
```
