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

- 現象: `curl`で`http://localhost`上のLaravelアプリを叩くと、1リクエストあたり4〜13秒程度かかる（`docker compose exec`経由のPHPUnit/Pestテスト実行は数百ms〜1秒程度と高速なままで、実HTTPリクエストのみ遅い）
- 原因: 未特定。`php artisan serve`（PHP内蔵サーバー、`PHP_CLI_SERVER_WORKERS=4`）をDocker Desktop for Windows経由で公開した際のネットワークオーバーヘッドと推測される（Nginx/PHP-FPM構成への切り替えで改善するかは未検証）
- 対処: 現時点では許容し様子見（`docs/product/requirements.md`の非機能要件「厳密なレスポンス要件は設けない」に該当する用途のため実害は小さい）。UI実装（Livewire）着手時に体感が悪ければ、Nginx+PHP-FPM構成への切り替えを検討する

### Laravel `auth`ミドルウェア（ログイン画面未実装） — ブラウザ的な未認証アクセスが500になる

- 現象: `run`スキルでUC-003（`GET /holdings/{holding}`）を`Accept: application/json`ヘッダーなしでcurlすると500。ログを見ると`RouteNotFoundException: Route [login] not defined.`。同条件で`GET /holdings`（UC-002、既存）を叩いても再現するため、UC-003固有ではなく既存の構造的ギャップと判明。`Accept: application/json`を付けたリクエストでは正しく401が返る（Pest Feature Test群は`getJson()`/`postJson()`で常にこのヘッダーが付くため、テストでは検出できない）
- 原因: `docs/architecture/authz-authn.md`はWebセッション認証を前提としているが、ログイン画面・`POST /login`ルート（認証UC）自体が未実装。`auth`ミドルウェアは非JSON期待のリクエストを`route('login')`にリダイレクトしようとし、ルートが存在しないため例外→500になる
- 対処: 未対応。Livewire UI着手前にログイン画面（認証UC）を実装するまでの既知の暫定ギャップとして許容し、`PLAN.md`に記録済み。JSON API的な検証（curlに`Accept: application/json`を付ける、またはPestの`actingAs()`）では問題なく動作するため、UC-001〜003のAPI実装フェーズでは実害なし
