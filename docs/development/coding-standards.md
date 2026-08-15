# coding-standards.md — コーディング規約

> 詳細なLaravelルールは `.claude/rules/10-laravel.md` を参照。

## 基本方針

- PSR-12 準拠
- Laravel Pint でフォーマット（CI必須）
- PHPStan Level 6 以上をクリア

## PHP

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

final class RegisterUserService
{
    public function execute(array $data): User
    {
        // 実装
    }
}
```

### 命名規則

| 対象 | 規則 | 例 |
|---|---|---|
| クラス名 | PascalCase | `UserService` |
| メソッド名 | camelCase | `findById()` |
| 変数名 | camelCase | `$userId` |
| 定数 | UPPER_SNAKE_CASE | `MAX_RETRY_COUNT` |
| DBカラム | snake_case | `created_at` |

### 型宣言

- `declare(strict_types=1)` を全ファイルに付ける
- 引数・戻り値の型宣言を必ず書く
- nullable は `?Type` で表現（`mixed` 乱用禁止）

## コメント

- 自明なコードにコメントを書かない
- **なぜ** そう書いたかをコメントする（**何を** ではなく）
- PHPDoc は public API にのみ書く

```php
// OK: なぜを説明している
// MySQLのROW LOCK を使うのは、同一ユーザーの並行予約を防ぐため
$booking->lockForUpdate()->find($id);

// NG: 何をするか（コードを読めばわかる）
// ユーザーを取得する
$user = User::find($id);
```

## API規約

- レスポンス形式は成功時・エラー時で統一フォーマットを使う（エンドポイントごとにバラバラにしない）

```json
// 成功時
{
  "data": { "id": 1, "name": "example" }
}

// エラー時（バリデーションエラー等、複数フィールドがありうる場合）
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

- 一覧系APIはページネーション情報を `meta` に含める（`data` と同階層）
- HTTPステータスコードは意味に沿って使い分ける（200/201/204/401/403/404/422/500 等。全て200で返さない）
- 破壊的なレスポンス形式変更はADRを書く（`docs/adr/`）

## エラーハンドリング方針

エラーは種別ごとに扱いを分ける。全て同じログレベル・同じレスポンスで握りつぶさない。

| 種別 | 例 | HTTPステータス | ログレベル |
|---|---|---|---|
| バリデーションエラー | FormRequestの検証失敗 | 422 | ログ出力しない（想定内の入力ミス） |
| 認証・認可エラー | 未ログイン・権限不足 | 401 / 403 | `info`（試行は記録するが異常ではない） |
| 業務エラー | 在庫不足・状態不整合等 | 400 / 409 | `warning` |
| システムエラー | DB接続断・外部API障害等 | 500 | `error`（スタックトレース含む。ただし本番ではPIIをログに出さない。`.claude/rules/40-security.md` 参照） |

- 例外は`app/Exceptions/`配下に業務エラー用の専用クラスを作り、`Handler`で種別ごとにレスポンス変換する（Controllerでtry-catchを乱立させない）
- ユーザーに見せるメッセージと、ログに残す詳細情報は分離する（ユーザー向けメッセージに内部実装の詳細を含めない）

## Git

- コミットメッセージは日本語または英語で意図を書く
- 1コミット1変更（混在させない）
- PRは小さく保つ（レビューしやすいサイズ）

### コミットメッセージ形式

```
[type]: [変更の概要]

[必要なら詳細説明]
```

type: `feat` / `fix` / `refactor` / `test` / `docs` / `chore`

例:
```
feat: ユーザー一覧APIにページネーションを追加

無限スクロール対応のため cursor-based pagination を実装。
offset-based から変更した理由は ADR-0005 を参照。
```
