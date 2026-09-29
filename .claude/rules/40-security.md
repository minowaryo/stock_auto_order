# 40-security.md — セキュリティルール

## Secrets管理（最重要）

### 絶対禁止
- APIキー・パスワードをコードにハードコード
- `.env` 実値をコミット・チャット・AIに貼り付け
- 本番資格情報をAIプロンプトに含める
- `secrets.*` 系ファイルをGitにコミット

### 正しい管理方法
- シークレットは `.env` のみ（`.env.example` にキー名だけ記載）
- 本番シークレットはSecret Manager（AWS Secrets Manager等）を使う
- AIに渡すコンテキストにはダミー値を使う

### `docs/credentials/` の扱い

`docs/original-docs/`（参照のみ・編集禁止）とは異なり、開発中に必要な認証情報を記録する運用を想定し、**AIによる新規作成・編集・参照を許可する**。ただし以下は厳守する。

- 実際の値（本番APIキー・パスワード・トークン等）を**チャットの応答やコミットメッセージ・PR説明にそのまま出力・引用しない**
- `docs/credentials/` は `.gitignore` に登録し、リポジトリにはコミットしない（ローカル・開発環境限定の置き場所とする）
- 本番シークレットの実体は既存ルール通りSecret Managerで管理し、`docs/credentials/` には「どこに何が保管されているか（参照先）」の記録や開発用ダミー値に留める
- 実際の本番相当の値を発見した場合は、そのまま扱わずユーザーに確認する（コミット・出力の前に必ず止める）

## 認証・認可

- 認証: Laravel Sanctum / Passport を使う
- 認可: **Policy / Gate を必ず使う**（直接ロールチェック禁止）
- セッション: HttpOnly + Secure cookie を設定
- CSRF: `VerifyCsrfToken` ミドルウェアを必ず有効にする

## 入力バリデーション

- ユーザー入力は必ずFormRequestでバリデーション
- SQLインジェクション対策: Eloquentを使う（生SQL禁止）
- XSS対策: Bladeの `{{ }}` を使う（`{!! !!}` は最小限）
- ファイルアップロード: MIMEタイプ・サイズ・拡張子を検証

## ログ・監査

### ログに出してはいけないもの
- パスワード
- APIキー・トークン
- クレジットカード番号
- 個人識別情報（PII）

### ログに出すべきもの
- ユーザーID（個人を特定できない形で）
- 操作の種類・タイムスタンプ
- エラーの種類・スタックトレース（本番では最小限）

### 監査ログチャンネル

特権操作・破壊的操作の監査エントリは、通常のアプリケーションログではなく**専用の `audit` チャンネル**に記録する。グローバル設定の「監査ログは JSONL 形式で `logs/audit.jsonl` に記録」（`~/.claude/CLAUDE.md`）に従い、本プロジェクトではこのチャンネルの出力形式をJSONLとする。

- `config/logging.php` に `audit` チャンネルを定義する（driver `single` または `daily`、`path` は `storage/logs/audit.jsonl`、daily rotationの場合は保持期間を `LOG_AUDIT_DAYS` 環境変数で制御。`.env.example` にキーを追加する）。1行1JSONオブジェクトになるよう `formatter` に `Monolog\Formatter\JsonFormatter`（`includeStacktraces: false`）を指定する
- チャンネルの `level` は `LOG_LEVEL` から**独立して** `info` に固定する。本番でアプリケーションログレベルを引き上げても監査証跡が消えないようにするため
- エントリは常に以下の固定最小スキーマで書き込む。自由形式のメッセージにせず、機械可読性を保つ:

| フィールド | 内容 |
|---|---|
| `action` | 操作名（例: `holding.deleted`） |
| `actor_id` | 実行ユーザーのID（IDのみ。氏名・メールは含めない） |
| `subject_type` | 対象レコードのクラス名/テーブル名 |
| `subject_id` | 対象レコードのID |

- PII（氏名・メール・住所等）や変更後の値そのものを監査エントリに含めない。`subject_type` + `subject_id` があればレコードを特定できる
- レコード上のActor Stampカラム（`.claude/rules/10-laravel.md` 参照）と本チャンネルは相補的な関係にある: カラムは最新状態、チャンネルは履歴を保持する

## 依存パッケージ

- `composer audit` を定期実行して脆弱性チェック
- メジャーアップデートはテスト環境で検証してからマージ
- 使っていないパッケージは削除する

## OWASP Top 10 対応チェック

- [ ] Injection（SQLi/XSS）
- [ ] Broken Authentication
- [ ] Sensitive Data Exposure
- [ ] XXE
- [ ] Broken Access Control
- [ ] Security Misconfiguration
- [ ] Insecure Deserialization
- [ ] Using Components with Known Vulnerabilities
- [ ] Insufficient Logging
