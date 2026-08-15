# ADR-0003: 認証戦略（Sanctum + Policy/Gate）

## Status
Accepted

## Date
[YYYY-MM-DD]

## Context

APIと従来のWebセッションを併用するシステムの認証・認可戦略を定める必要がある。
セキュリティを確保しながら、AI駆動開発でも正しく実装しやすい仕組みが求められる。

## Decision

- **認証**: Laravel Sanctum（APIトークン + セッション認証の両対応）
- **認可**: Policy / Gate を必ず経由する（直接ロールチェック禁止）

## Rationale

**Sanctum採用理由:**
- SPAとAPIトークンの両方に対応
- Laravelに標準統合されており追加設定が少ない
- Passportより軽量でシンプル

**Policy/Gate方針の理由:**
- 認可ロジックを一か所に集約できる（散在を防ぐ）
- テストが書きやすい
- AIがコード生成する際に認可抜けを防ぎやすい
- `before()` で管理者全権限を一元管理できる

### 採用しなかった代替案
- **Laravel Passport**: OAuthが必要でない限りオーバーエンジニアリング
- **JWT（独自実装）**: Sanctumで十分、独自実装はリスクが高い
- **直接ロールチェック**: 認可ロジックが散在し、AIが生成したコードで抜けが発生しやすい

## Consequences

### メリット
- 認可ロジックの一元管理
- AIのコード生成で認可漏れが起きにくい
- テストが書きやすい

### デメリット・リスク
- ControllerでPolicyの`authorize()`呼び出しを必ず書く規律が必要
- 忘れた場合のルールを`.claude/rules/40-security.md`に明記する

## Related
- `docs/architecture/authz-authn.md`
- `.claude/rules/40-security.md`
- `.claude/rules/10-laravel.md`
