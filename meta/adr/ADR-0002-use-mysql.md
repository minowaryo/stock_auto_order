# ADR-0002: MySQL をデータベースとして採用

## Status
Accepted

## Date
[YYYY-MM-DD]

## Context

バックエンドのデータベースを選定する必要がある。
アプリケーションはトランザクション整合性が求められる業務データを扱う。
チームのMySQL運用経験・ホスティングコスト・エコシステムの広さを考慮する。

## Decision

MySQL を採用する。

## Rationale

**採用理由:**
- Laravel公式ドキュメントでも最も多くの例がMySQLベース
- 共有ホスティング・クラウド（AWS RDS / PlanetScale等）での対応が最も広い
- チームのMySQL運用ノウハウが豊富
- Eloquentとの統合が優秀（LaravelはMySQL向けに多くの機能を最適化）
- InnoDBエンジンによるACID準拠トランザクション
- 豊富なドキュメント・コミュニティ（AIも参照しやすい）
- JSON型サポート（MySQL 5.7.8+）

### 採用しなかった代替案
- **PostgreSQL**: 機能は豊富（JSONB・pg_trgm・RLS等）だが、今回の要件には過剰。チームのPostgreSQL運用知識が限定的。
- **SQLite**: スケールアウトに不向き（本番環境）
- **MongoDB**: ACID保証が弱く、業務データには不向き

## Consequences

### メリット
- チームが既存の運用ノウハウを活用できる
- ホスティングコストが比較的低い
- Laravel Telescopeなどのデバッグツールとの相性が良い
- AWS RDS MySQL / Aurora MySQL のマネージドサービスが利用しやすい

### デメリット・リスク
- PostgreSQLと比べてJSON操作（JSONB相当）の機能がやや限定的
- カラム型変更時にテーブルロックが発生する場合がある（大量データ時は `pt-online-schema-change` 等の検討が必要）
- strict mode の設定を忘れるとデータ整合性の問題が起きやすい

### 運用上の注意
- `strict: true` を `config/database.php` に必ず設定する
- 文字コードは `utf8mb4` / `utf8mb4_unicode_ci` を使用する
- 大量データへのALTER TABLEはメンテナンスウィンドウを設けて実施する

## Related
- ADR-0001: Laravel採用
- `.claude/rules/20-mysql.md`
- `docs/architecture/data-model.md`
