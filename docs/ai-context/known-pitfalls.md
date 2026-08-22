# known-pitfalls.md — 既知のハマりどころ

> このファイルは常時読込されない。エラー調査時・同種のライブラリ/機能を実装する時にAIが参照する。
> 新しいハマりどころを解決したら、都度この下に追記する（このファイルはリファクタしない・記録を消さない）。

## 記載ルール

- 1件 = 見出しレベル3（`###`）+ 現象・原因・対処の3行のみ（長文の経緯は書かない）
- ライブラリ名・バージョンが分かる場合は明記する（バージョン依存の不具合が多いため）
- 汎用的なコーディング規約はここに書かない（`.claude/rules/` 側に反映する）

## テンプレート

```markdown
### [ライブラリ名/機能] — [症状を一言で]

- 現象: [何が起きたか]
- 原因: [なぜ起きたか]
- 対処: [何をしたら解決したか。該当ファイルがあれば `path:line` で記載]
```

---

## 記録

### Laravel Sail（Windows + Docker Desktop） — storage/配下に書き込めず実HTTPリクエストが500になる

- 現象: `docker compose exec laravel.test php artisan test` は全件Green。しかし`run`スキルで実際に`curl`から`POST /csv-import`を叩くと500（`tempnam(): file created in the system's temporary directory`）。テストのみでは検出できなかった
- 原因: `docker compose exec`はデフォルトrootユーザーで実行されるため、`composer create-project`/`artisan`系コマンドで作られる`storage/`・`bootstrap/cache`配下のファイルがroot所有（mode 755）になる。一方、実際のWebサーバープロセス（`php artisan serve`、supervisord経由）は`sail`ユーザー（uid 1337）で動くため、ビューキャッシュ等への書き込みで権限エラーになる
- 対処: `docker compose exec laravel.test chown -R sail:sail storage bootstrap/cache` を実行（Laravel雛形作成直後・初回`docker compose up`後に一度実行すればよい。以後`docker compose exec`でrootのままファイルを作った場合は同様の症状が出るので都度実行する）

### Laravel `php artisan serve`（Windows + Docker Desktop） — 実HTTPリクエストが数秒〜十数秒かかる

- 現象: `curl`で`http://localhost`上のLaravelアプリを叩くと、1リクエストあたり2〜14秒程度かかる（`docker compose exec`経由のPHPUnit/Pestテスト実行は数百ms〜1秒程度と高速なままで、実HTTPリクエストのみ遅い）
- 原因【2026-08-21 検証確定】: WSL2バックエンドのDocker Desktopで、Windows側パス（`c:\workspace\stock_auto_order`）を`.:/var/www/html`としてbind mountしていることによるWindows⇄WSL2間のファイルI/O変換オーバーヘッド。1リクエストで数百〜数千のPHPファイルに`stat`/`open`が走るLaravelの特性上、このオーバーヘッドが顕著に乗る。検証: リポジトリをWSL2ネイティブ側（`~/`配下）にコピーし、同じdocker composeスタック・同じエンドポイント（`GET /holdings`）で計測したところ、Windows側 2.3〜14.0秒 → WSL2ネイティブ側 0.015〜0.4秒（100倍以上高速化）。コンテナ化自体・`php artisan serve`自体が原因ではないことを確認済み（コンテナ実行速度＝テスト速度は元々高速だった）
- 対処: 現時点では実害小さく（`docs/product/requirements.md`の非機能要件「厳密なレスポンス要件は設けない」）緊急対応不要のため、**現状のWindows側配置のまま様子見を継続**。体感が悪化する・実害が出るタイミングが来たら、リポジトリをWSL2ネイティブ側（`\\wsl$\...`配下）に移設する対応を第一候補とする（コンテナ化撤廃は不要。原因はbind mountの配置場所であり、コンテナ化そのものではないため）

### Laravel `auth`ミドルウェア（ログイン画面未実装） — ブラウザ的な未認証アクセスが500になる

- 現象: `run`スキルでUC-003（`GET /holdings/{holding}`）を`Accept: application/json`ヘッダーなしでcurlすると500。ログを見ると`RouteNotFoundException: Route [login] not defined.`。同条件で`GET /holdings`（UC-002、既存）を叩いても再現するため、UC-003固有ではなく既存の構造的ギャップと判明。`Accept: application/json`を付けたリクエストでは正しく401が返る（Pest Feature Test群は`getJson()`/`postJson()`で常にこのヘッダーが付くため、テストでは検出できない）
- 原因: `docs/architecture/authz-authn.md`はWebセッション認証を前提としているが、ログイン画面・`POST /login`ルート（認証UC）自体が未実装。`auth`ミドルウェアは非JSON期待のリクエストを`route('login')`にリダイレクトしようとし、ルートが存在しないため例外→500になる
- 対処: 未対応。Livewire UI着手前にログイン画面（認証UC）を実装するまでの既知の暫定ギャップとして許容し、`PLAN.md`に記録済み。JSON API的な検証（curlに`Accept: application/json`を付ける、またはPestの`actingAs()`）では問題なく動作するため、UC-001〜003のAPI実装フェーズでは実害なし

### J-Quants API — 無料プランでは業種別指数（TOPIX-17指数等）が取得できない

- 現象: 相対力（対セクター）指標の設計時、J-Quantsの`/indices`（指数四本値）で業種別指数を取得しセクターベンチマークとして使う案を検討したが、無料プランでは利用できない可能性が高いと判明
- 原因: J-Quants公式記事（[指数四本値を取得できる新規のAPIについて](https://qiita.com/j_quants/items/68ffe2383cd6c3b8f6e1)）によると、`/indices`エンドポイントの利用にはスタンダード/プレミアムプランの契約が必要と明記されており、サンプルコードにも業種別指数（TOPIX-17等）は含まれていない（TOPIX Core30/Large70/Mid400等の規模別指数のみ）
- 対処: `requirements.md`の前提（J-Quantsは無料プラン使用）を維持したまま、相対力（対セクター）は「保有銘柄内の同一セクター平均騰落率」で簡易代用する設計にした（ADR-0004）。将来J-Quantsを有償プランに切り替える場合は、業種別指数ベースの算出に置き換える余地がある
- **2026-08-22追記（ADR-0005、V2移行時に再確認）**: J-Quants API V2移行後にも同条件（指数四本値は無料プランで「-」＝利用不可、有償プランのみ過去10年分等が開放）が維持されていることをWebSearchで再確認した。上記の対処方針（対セクター相対力は保有銘柄内平均で簡易代用）は変更不要

### J-Quants API — V1認証（メールアドレス/パスワード・トークン方式）が403 Forbiddenで機能しない（V2への移行）

- 現象: `POST /v1/token/auth_user`に正しい資格情報を送っても常に`403 Forbidden`（`x-amzn-errortype: ForbiddenException`）が返る。存在しないパス・ルートパスでも同一の403が返り、個別の資格情報・エンドポイントの問題ではないことが分かった
- 原因: J-Quants APIは2025年12月にV2がリリースされ、認証方式がトークン方式（`refreshToken`→`idToken`）からAPIキー方式（`x-api-key`ヘッダー）に変更された。2025年12月22日以降の新規登録ユーザーはV2のみ利用可能で、V1の該当エンドポイント（`/v1/token/auth_user`等）は実質的に利用不可
- 対処: `docs/adr/ADR-0005-jquants-api-v2-migration.md`の通りV2方式に全面移行。認証情報は`JQUANTS_API_KEY`（`.env`）のみ、リクエストヘッダー`x-api-key`で送る。エンドポイントも`/v1/listed/info`→`/v2/equities/master`、`/v1/fins/statements`→`/v2/fins/summary`に変更（レスポンスは`{"data": [...]}`形式、カラム名も短縮される。例: `Sector17Code`→`S17`、`EarningsPerShare`→`EPS`、`EquityToAssetRatio`→`EqAR`）。V1向けに書いていたテスト・実装は破棄してV2仕様で書き直す

### J-Quants API V2 `/fins/summary` — `EqAR`/`ROE`/`PayoutRatioAnn`は0〜1の比率で返る（%表記ではない）

- 現象: `JQuantsClient::fetchStatements()`をGate4承認後に実際のAPIキーで疎通確認したところ（トヨタ`72030`）、`EqAR`（自己資本比率）が`0.378`、`ROE`が`0.101`、`PayoutRatioAnn`（配当性向）が`0.321`という値で返ってきた。実際の値（自己資本比率37.8%・ROE10.1%・配当性向32.1%）と整合するため、APIの異常値ではなく仕様。Gate4テストのモックでは`"38.7"`のような%表記の数値を仮に使っていたため、この仕様差はUnit Testでは検出できなかった
- 原因: J-Quants API V2の`/fins/summary`は`EqAR`/`ROE`/`PayoutRatioAnn`を比率（0〜1）で返す。`EPS`/`BPS`/`Sales`等の金額・株数系フィールドはそのままの単位（円・株）で返る
- 対処: `JQuantsClient`自体は生の値をそのまま返す設計（変換責務を持たない）ため実装変更は不要。ただし、今後実装する「J-Quants生データ→`fundamental_indicators`」変換層（`FundamentalIndicatorMapper`等）では、`equity_ratio`/`roe`/`dividend_payout_ratio`（`data-model.md`でパーセント値として定義済み）にマッピングする際、`EqAR`/`ROE`/`PayoutRatioAnn`の値を**×100**すること。実装時にこの記録を必ず参照する
- 補足: 四半期決算（`disclosed_date`が直近でも本決算でない回）では`BPS`/`ROE`/`DivAnn`/`PayoutRatioAnn`が空文字列で返り`null`になるケースを確認（本決算のみ開示される項目のため、想定通りの挙動）
