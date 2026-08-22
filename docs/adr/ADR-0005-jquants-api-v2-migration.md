# ADR-0005: J-Quants API V1からV2（APIキー方式）への移行

## Status
Accepted（2026-08-22、`config/services.php`/`.env.example`をV2方式に更新済み。V1前提で作成していた`app/Services/MarketData/JQuantsClient`のRedフェーズテストはV2仕様で書き直す）

## Date
2026-08-22

## Context

J-Quants APIとの疎通確認を行ったところ、当初想定していたV1認証方式（メールアドレス/パスワードで`POST /v1/token/auth_user`を呼び出し`refreshToken`を取得、続けて`POST /v1/token/auth_refresh`で`idToken`を取得するトークン方式）では、正しい資格情報を使っても常に`403 Forbidden`（`x-amzn-errortype: ForbiddenException`）が返る事象が発生した。

調査の結果、以下が判明した。

- `/v1/token/auth_user`だけでなく、存在しないパスやルートパス（`/`）に対しても同一の403が返ることから、資格情報や個別エンドポイントの問題ではなく、AWS API Gateway/WAF相当のエッジ層で一律ブロックされていると分かった
- 送信元ネットワークのIP判定（フィリピンのISP）から地理制限を一時疑ったが、公式ドキュメント（J-Quants API Help / API Reference）には地域制限の明記はなかった
- 公式ドキュメント調査（WebSearch/WebFetch）により、**J-Quants API V2が2025年12月にリリースされ、認証方式がトークン方式からAPIキー方式（`x-api-key`ヘッダー）に変更されている**ことが判明した。2025年12月22日以降の新規登録ユーザーはV2のみ利用可能
- V2エンドポイント（例: `GET /v2/equities/bars/daily`）に対し、ダッシュボードで発行したAPIキーを`x-api-key`ヘッダーに設定してリクエストしたところ、`200 OK`でデータ取得に成功した（契約プランのカバー期間外の日付を指定した場合は`400`で「Your subscription covers the following dates: ...」という業務エラーが返り、認証自体は成立していることを確認済み）

現行の`config/services.php`・`.env.example`はV1方式（`JQUANTS_MAILADDRESS`/`JQUANTS_PASSWORD`）を前提に用意されていたが、V1の該当エンドポイントは実質的に利用不可（廃止済み）と判断できる状態だった。

## Decision

J-Quants APIとの連携は**V2（APIキー方式）を正とする**。

- 認証情報として`JQUANTS_API_KEY`を採用し、リクエストヘッダー`x-api-key`に設定する方式に統一する
- `config/services.php`の`jquants`設定・`.env.example`をV2方式（`JQUANTS_API_KEY`のみ）に更新する
- V1方式の`JQUANTS_MAILADDRESS`/`JQUANTS_PASSWORD`関連の設定・ドキュメント記載は撤去する
- 今後実装するJ-Quantsクライアント（`app/Services/`配下想定）はV2のレスポンス形式（`data`キー配下の配列、短縮カラム名など）を前提に設計する

## Rationale

### 採用しなかった代替案
- **V1方式を維持し、403の原因を個別に調査し続ける**: 公式にV1エンドポイントの仕様変更（V2への移行）が確認された時点で、V1を追い続けるのは無駄なコストであり不採用。V1が将来的に完全廃止された場合、再度同じ調査が必要になるリスクもある
- **地理制限を疑いVPN等でネットワーク環境を変更して回避する**: 403の実際の原因が地理制限ではなくAPI仕様変更だったため、根本原因に対処しない対症療法であり不採用

## Consequences

### メリット
- 認証フローがトークン取得の2段階（`auth_user`→`auth_refresh`）からAPIキー1本の単純な方式になり、実装・運用がシンプルになる
- APIキー自体に有効期限がなく（再発行・削除は可能）、トークンリフレッシュの実装・失効ハンドリングが不要になる
- V1が将来廃止された場合でも影響を受けない

### デメリット・リスク
- 既存の`.env.example`/`config/services.php`（V1方式で先行作成済み・未コミット）を書き換える必要がある
- V2のレスポンス形式（`data`キー配下、カラム名短縮）はV1と非互換のため、今後実装するJ-Quantsクライアント・関連テストはV2形式を前提に設計し直す必要がある
- `docs/ai-context/known-pitfalls.md`に記載済みのV1前提の記述（無料プランでの業種別指数取得不可等）は、V2ドキュメントでの再確認が必要

## Related
- `docs/ai-context/do-not-touch.md`「外部連携」（J-Quants認証情報の`.env`管理方針）
- `docs/ai-context/known-pitfalls.md`（J-Quants API — 無料プランでは業種別指数が取得できない、V1前提の記述）
- J-Quants API Reference: https://jpx-jquants.com/ja/spec/migration-v1-v2
