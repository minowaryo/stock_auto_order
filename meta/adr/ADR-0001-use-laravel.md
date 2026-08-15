# ADR-0001: Laravel をバックエンドフレームワークとして採用

## Status
Accepted

## Date
[YYYY-MM-DD]

## Context

Webアプリケーションのバックエンドフレームワークを選定する必要がある。
開発チームはPHP経験が豊富であり、日本市場向けのWebアプリを構築する。
AI駆動開発を前提とするため、AIが理解しやすいコード規約が整ったフレームワークが望ましい。

## Decision

Laravel を採用する。

## Rationale

**採用理由:**
- PHPエコシステムで最も活発なコミュニティ
- Eloquent ORM、Migration、Seeder、Factory など、AI駆動開発に必要な機能が標準装備
- Policy / Gate による認可機能が標準搭載
- FormRequest、Resource、Job、Event など、責務分離が明確なアーキテクチャ
- テスト（PestPHP / PHPUnit）との統合が優れている
- 豊富な公式ドキュメント（AIが参照しやすい）

### 採用しなかった代替案
- **Symfony**: 強力だが設定が複雑で開発速度が遅くなる可能性
- **Slim / Lumen**: シンプルすぎてAI駆動開発で必要な標準機能が少ない
- **Node.js (Express/Nest)**: チームの主要スキルがPHP

## Consequences

### メリット
- チーム全員が既存知識を活用できる
- Laravelのコード規約をAIが学習済みのため、コード生成品質が高い
- 豊富なパッケージエコシステム（Cashier、Telescope、Horizon）

### デメリット・リスク
- PHPバージョンアップへの追随が必要
- フレームワークの慣習に縛られる部分がある

## Related
- ADR-0002: MySQL採用
- `docs/architecture/overview.md`
