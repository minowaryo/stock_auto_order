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
- 対処（当初）: 現時点では実害小さく（`docs/product/requirements.md`の非機能要件「厳密なレスポンス要件は設けない」）緊急対応不要のため、**現状のWindows側配置のまま様子見を継続**。体感が悪化する・実害が出るタイミングが来たら、リポジトリをWSL2ネイティブ側（`\\wsl$\...`配下）に移設する対応を第一候補とする（コンテナ化撤廃は不要。原因はbind mountの配置場所であり、コンテナ化そのものではないため）
- **2026-08-29 解消**: 体感悪化のため WSL2 ネイティブ（`/root/workspace/stock_auto_order`、WSL Ubuntu / root）へ開発環境一式を複製移設（`scripts/migrate-to-wsl.sh` / `docs/ai-context/wsl-migration-handoff.md`）。`GET /holdings` が Windows側 3〜9秒 → WSL側 **0.015〜0.025秒**（実測、200/302とも）。`php artisan test` 389 passed。
- **移設後にハマった点（重要）**: Windows側チェックアウト（`c:\workspace\stock_auto_order`）と WSL側は compose プロジェクト名が同じ（どちらもディレクトリ名由来の `stock_auto_order`）だったため、**デスクトップの起動バッチ等で Windows パスから `docker compose up` が走るたびに、高速な WSL 版コンテナが遅い Windows bind mount 版へ静かに作り直されていた**（体感だけ遅く戻り、原因が分かりにくい）。
- **恒久対処（2026-08-29）**: WSL側の `.env` に `COMPOSE_PROJECT_NAME=stock_auto_order_wsl` を追加してプロジェクトを完全分離。MySQLボリュームは `stock_auto_order_sail-mysql` → `stock_auto_order_wsl_sail-mysql` に複製（`docker run --rm -v old:/from:ro -v new:/to alpine cp -a /from/. /to/`、旧ボリュームはバックアップ保持）。以後 Windows パスから compose が走っても別プロジェクト（`stock_auto_order`）になり WSL 版（`stock_auto_order_wsl`）は無傷。両方同時起動時はポート80衝突で**明示エラー**になる（静かな劣化ではなくなる）。`scripts/*.bat` は WSL 内で `docker compose` を実行する形に修正済み（`COMPOSE_PROJECT_NAME` は `.env` から自動適用）。Windows側の旧デスクトップショートカット・旧スタックは撤去推奨。

### Docker Desktop（Windows + WSL2） — `docker info`はOKなのにWSL内`docker compose up`が失敗する（内部WSL VMの起動失敗）

- 現象: `scripts/start-app.bat`でWindows側の`docker info`チェックが「Docker OK」を返した直後、`wsl -d Ubuntu -- docker compose up -d`が`Cannot connect to the Docker daemon at unix:///var/run/docker.sock`で失敗。同時にDocker Desktopが「Unexpected WSL error」ダイアログ（`running wsl-bootstrap: ... : exit status 1`）を表示することがある
- 原因: Docker DesktopはWSL2バックエンドの場合、内部専用の`docker-desktop`ディストロでデーモン本体を動かし、ユーザーの`Ubuntu`ディストロにはソケット経由で橋渡し（WSL統合）する。Windows側の`docker info`はこの内部VMの起動完了を待たずに成功することがあり、その状態でWSL側から`docker compose`を叩くと橋渡しがまだ済んでおらず失敗する。まれに内部VM自体の起動が（`getty`の`console=hvc0`関連の警告等で）failed扱いになることもある
- 対処: `scripts/start-app.bat`に(1) `wsl -d Ubuntu -- docker info`でWSL側からの疎通も確認してから`docker compose up`に進む待機ループ、(2) `docker compose up -d`自体の数回リトライ、を追加（2026-09-05）。それでも失敗する場合は自動復旧せず、画面に「`wsl --shutdown`→Docker Desktop再起動→再実行」の手順を表示して停止する（`wsl --shutdown`は全WSLディストロを巻き込むため自動実行はしない設計。実行前に他のWSL作業がないか必ず確認すること）

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

### Yahoo Finance chart API — 週足の最新1件が「未確定・進行中の週」のプレースホルダーになることがある

- 現象: `FetchExternalMarketDataAction`を実データで動作確認したところ、日経平均の週足データの最終要素が`volume=0`かつ`close`が前週と全く同じ値になっていた（`change_rate`が実際には変化がないはずなのに0%と一致してしまい、偶然発覚しづらい）。`YahooFinanceChartClient`に「末尾要素が`volume===0`かつ直前週と`close`が同一の場合は除外する」ガードを追加し解消した（2026-08-22）
- **未解決の関連ケース**: 個別銘柄（トヨタ7203.T）では、同様に最終週の`close`が前週と同一になるケースで、`volume`が0ではなく前週より少ないが非ゼロの値（部分的な週内出来高）になっていることを確認した。現在のガード（`volume===0`限定）はこのケースを検出できない。「`close`が前週と同一」だけを条件にすると、偶然一致した正当な週まで誤って除外するリスクがあるため、あえて`volume===0`の明確なケースのみに限定した保守的な実装としている
- 対処: 本システムの運用サイクルは週末（土日）のCSV取込を前提とするため（`requirements.md`）、取引週が完全に終了した状態で取得することが多く、この問題が実害になる可能性は低いと判断し現状は追加対応しない。**週中（平日）にCSV取込・分析を行う運用に変える場合は、この制約を再検討すること**

### `FetchExternalMarketDataAction` — 実データでのみ顕在化するeps_growth桁溢れ・per-holding例外分離の非対称性

- 現象: 実際のユーザーCSV（134銘柄）をUC-001経由でインポートすると、EPSがほぼゼロ近辺から回復した銘柄でEPS成長率が約1136%に達し、`fundamental_indicators.eps_growth`（`decimal(7,4)`、最大±999.9999%）でMySQL strict modeが`Out of range value`エラーを出しINSERTが失敗した。さらにこの1件のDB例外が`execute()`の2つ目のループ（テクニカル/ファンダメンタルズ指標計算・DB保存）全体を中断させ、それ以降に処理されるはずだった銘柄（正常な銘柄も含む）が一切指標計算・シグナル判定されないまま処理が止まっていた（`fetchSectorInfo()`を呼ぶ1つ目のループ側にも同様の非対称性があり、価格履歴取得のみtry-catchで保護され`fetchSectorInfo()`は無保護だった）。ImportCsvAction側の外側のcatchが無ログで例外を握りつぶすため、テスト実行以外では気づけない状態だった
- 原因: (1) `fundamental_indicators.eps_growth`/`revenue_growth`/`operating_income_growth`の列幅がユニットテストの仮フィクスチャ（小さい成長率）でしか検証されておらず、実データの極端な成長率（分母がゼロに近い回復銘柄）を想定していなかった。(2) `FetchExternalMarketDataAction::execute()`の1つ目のループは価格履歴取得のみをtry-catchで保護し`fetchSectorInfo()`を保護範囲外に置いていた。2つ目のループ（指標計算・DB保存・シグナル判定）にはper-holdingのtry-catchが一切なかった
- 対処: `database/migrations/2026_08_22_000004_widen_growth_columns_on_fundamental_indicators_table.php`で3カラムを`decimal(10,4)`に拡張（`docs/adr/ADR-0006-widen-fundamental-growth-columns.md`）。`FetchExternalMarketDataAction::execute()`の両ループとも、1銘柄分の処理全体を1つのtry-catchで囲み、例外発生時は`Log::warning()`で銘柄ID・シンボルコード・例外メッセージを記録した上でその銘柄をスキップして次に進むよう修正した（`app/Actions/Analysis/FetchExternalMarketDataAction.php`）
- **追加で発見・修正した関連の非アトミック性**（`/review`拡張レベルで指摘）: 2つ目のループのtry-catchはスキップ自体は正しく行うが、`TechnicalIndicator::updateOrCreate()`が成功した**後**にファンダメンタルズ指標保存やシグナル判定で例外が起きた場合、その銘柄は「テクニカル指標だけ最新化され、ファンダメンタルズ指標・シグナルは古いまま」という中途半端な状態でDBに残っていた。2つ目のループの銘柄ごとの処理本体を`DB::transaction()`で包み、例外時は該当銘柄の全ての書き込み（テクニカル指標を含む）がロールバックされ、更新前の状態を維持するよう修正した

### Tailwind CSS v4（`vite build`、Sailコンテナ） — Bladeに新しいユーティリティクラスを追加してもレイアウトが崩れたまま反映されない

- 現象: `resources/views/livewire/signal/signal-list.blade.php`に`overflow-x-auto`・`whitespace-nowrap`を新規追加し、`php artisan test`はGreen（Livewireテストヘルパーは実DOM描画を伴わない）だったにもかかわらず、実際に`/signals`をHTTP経由（ログイン後の実HTML）で取得して確認したところ、配信中のCSS（`public/build/assets/app-*.css`）に該当クラスの定義が存在しなかった。ユーザーからは「デザイン崩れが激しい」と報告があった
- 原因: 本プロジェクトの`compose.yaml`にはVite dev serverのサービスが定義されておらず、`npm run build`で一度ビルドした静的CSSを配信する運用。Tailwind v4はビルド時点でBladeファイルをスキャンして使用中のユーティリティクラスのみを生成する（JIT）ため、CSSビルド**後**に追加した新しいクラス名はコンテナ再起動やBlade編集だけでは反映されず、`npm run build`を再実行するまで欠落したまま
- 対処: Blade側でTailwindユーティリティクラス（特にこれまでこのビューで未使用だったクラス名）を新規に使い始めた場合は、`docker compose exec laravel.test bash -c "cd /var/www/html && npm run build"`を実行してCSSを再ビルドすること。Feature Test（Livewireの`->html()`）はコンパイル済みCSSを検証しないため、この種の欠落を検出できない — レイアウト変更時は実HTTP経由での目視確認（Playwright、またはログイン込みの実HTTP取得）が必須

### CSS `overflow-x: auto` + `position: sticky`（Tailwind、ブラウザ全般） — 横スクロール用ラッパー内で`sticky`が全く効かない。**`overflow-y`側の値をどういじっても直らない、構造自体を分ける必要がある不具合**

- 現象: `signal-list.blade.php`のテーブルヘッダー（`<thead>`）・先頭列に`sticky top-0`/`sticky left-0`を付与したが、実ブラウザで縦スクロールしてもヘッダーが固定されずページと一緒に流れていった。curlで取得した実HTMLにはクラス自体は正しく出力されており（Tailwindビルド漏れとは別原因）、`php artisan test`はもちろんクラスの存在確認だけの静的HTML検証でも検出できなかった
- 原因の本質: `<div class="overflow-x-auto">`で横スクロールを提供する限り、この**div自身が必ず「スクロールコンテナ」になり**、`position: sticky`な子孫要素の基準がページ本体ではなくこのdivになってしまう。`overflow-y`をどんな値にしてもこの結論は変わらない：
  - `overflow-y`未指定（既定`visible`）→ 「`visible`と非`visible`が混在すると両方`auto`に補正される」というCSS仕様の挙動で、暗黙に`overflow-y: auto`（スクロールコンテナ）になる
  - `overflow-y-hidden`に変更（1回目の誤った対処）→ `hidden`は`auto`と同様それ自体がスクロールコンテナを成立させる値であり、何も変わらない
  - `overflow-y-clip`に変更（2回目の誤った対処）→ 一見`clip`はスクロールコンテナを作らない値のはずだが、実際に`getComputedStyle()`で確認すると`overflow-y`は`"hidden"`として計算されていた。CSS仕様には「`overflow-x`/`overflow-y`の片方が`clip`で、もう片方が`visible`でも`clip`でもない場合、`clip`側は`hidden`に計算される」という追加の補正ルールがあり、`overflow-x: auto`（`visible`でも`clip`でもない）と組み合わせた時点でこの補正が発動し、結局`hidden`と同じ状態に戻ってしまう。**「横スクロールを本物のスクロールコンテナとして機能させる」ことと「縦方向はスクロールコンテナにしない」ことは、CSSの`overflow`プロパティだけでは同一要素上で両立できない**
- 正しい対処: **1つの要素に両方の性質を持たせようとするのをやめ、ヘッダー用と本文用で`<table>`ごと2つに分割する。** ヘッダー側の`<table>`（`<colgroup>`+`<thead>`のみ、`<tbody>`は無し）を`overflow-x-auto`かつ`position: sticky; top: 0`を**同じdiv自身に**付与してラップする。この場合、`sticky`は「このdivの祖先」を基準に解決されるため（このdiv自身がoverflow-x-autoでスクロールコンテナになっていることは無関係）、祖先に`overflow`を持つ要素が無ければページ本体に対して正しく固定される。本文側の`<table>`（`<tbody>`のみ）は別の`overflow-x-auto`のdivに入れ、`sticky`は付けない。2つのdivは独立した横スクロール位置を持つため、本文側の`scroll`イベントでヘッダー側の`scrollLeft`を同期するJS（`resources/js/app.js`、`data-scroll-sync-with="<ヘッダーdivのid>"`属性を目印にする）が必要。ヘッダー用の横スクロールバーは本文側と二重に見えるため`[scrollbar-width:none] [&::-webkit-scrollbar]:hidden`で視覚的に隠す（`scrollLeft`によるプログラム操作は可能なまま）
- 検証方法: この種の不具合はクラスの存在確認（curlで取得した静的HTML・コンパイル済みCSSのgrep）は元より、`getComputedStyle()`で「クラスが正しく適用されているか」を見るだけでも「本当にスクロールする/固定されるか」は確認できない（`overflow-y: clip`は正しく適用されていたが、それでも`hidden`相当の挙動になっていた）。**実際にブラウザ上でスクロールさせて`getBoundingClientRect()`の座標が動かないことを目で確認するまで気づけない**。本プロジェクトのSailコンテナには`npx playwright install chromium`でChromiumを追加インストールでき（`docker compose exec laravel.test npx playwright install chromium`、約300MBダウンロード）、Node.jsスクリプトから`import { chromium } from 'playwright'`する形で実際にスクロール・スクリーンショットを取得して検証できる（詳細は`.claude/skills/verify/SKILL.md`参照）。理論上正しく見えるCSSでも、複雑な`overflow`/`position: sticky`の組み合わせは必ず実ブラウザで検証すること
- 教訓: `overflow-x: auto`の入れ物に`position: sticky`な子孫を入れる設計は、`overflow-y`の値を工夫する対症療法では直らないケースがある（今回のように`hidden`↔`auto`↔`clip`のいずれも同じスクロールコンテナ扱いになる組み合わせでは特に）。構造そのもの（スクロール軸ごとに要素を分離する）を見直す必要がある

### Tailwind `table-fixed` + `w-max` — `<colgroup>`で列幅を固定したつもりが、内容の長い列だけ幅が膨らむ

- 現象: 上記のヘッダー/本文分割後、`table-fixed`かつ`<colgroup>`で全列の幅を指定したテーブルに`w-max`（`width: max-content`）を付けたところ、`scrollWidth`を実測すると同じ`<colgroup>`を共有しているはずのヘッダー用`<table>`と本文用`<table>`で幅が異なっていた（例: 1446pxと1350px）。原因を追うと、特定の列（バッジの文言が長い「発生シグナル」列等）だけ`<colgroup>`で指定した幅（130px）を大きく超えて実際にレンダリングされていた（164px等）
- 原因: `table-layout: fixed`は本来「列幅はcolgroup/最初の行の指定値のみで決まり、内容は無視する」アルゴリズムだが、テーブル自身の`width`が`auto`または`max-content`（`w-max`）の場合、Chromiumは指定した列幅を無視して内容の最小幅（min-content、特に折り返せない文字列があるとその文字列の全幅）を考慮した上でテーブル全体の幅を決めてしまう。ヘッダー側は`whitespace-nowrap`を付けた項目名（例: 「52週安値からの距離」）があり、本文側にはそのような幅を強制する要素が無かったため、ヘッダーと本文で実際の列幅が食い違っていた
- 対処: テーブルの`width`を`w-max`ではなく**`<colgroup>`の合計値と一致する具体的なpx値**（例: `w-[1296px]`、5固定列90+56+130+150+150 + 判定チェックリスト10列×72の合計）で明示的に指定する。こうすると`table-layout: fixed`が指定通りの列幅を厳密に守り、内容がそれより長い場合は列幅を広げず、セル内で折り返す（またはセル外へ視覚的にはみ出す）挙動になる。加えて、ヘッダー側の項目名から`whitespace-nowrap`を外し`break-words`に統一することで、ヘッダー・本文どちらも同じ折り返しルールになり幅の食い違いが起きなくなる
- 教訓: `table-fixed`は「列幅を内容から独立させる」ためのものだが、**テーブル自身の`width`が`auto`/`max-content`のままだと内容依存の挙動が残る**。`<colgroup>`で列幅を固定するときは、テーブルの`width`も列幅の合計と一致する具体的な値で固定しないと、`table-fixed`が期待通りに機能しない場合がある。またヘッダー用・本文用でテーブルを分けた構成では、両者の折り返しルール（`whitespace-nowrap`の有無等）を完全に揃えないと、同じ`<colgroup>`を共有していても実際の描画幅がずれる

### Tailwind `inline-block`な要素（例: バッジコンポーネント） — 親セルに`break-words`を付けても長い1単語のテキストが折り返されず、セルからはみ出して隣接要素と重なる

- 現象: `signal-list.blade.php`の「発生シグナル」列で、シグナル種別を表示する`<x-badge>`（`display: inline-block`）の中身が`week52_high_pullback`のような区切り文字（スペース・ハイフン）を含まない長い1単語だった場合、親の`<td>`に`[&_td]:break-words`（`overflow-wrap: break-word`）を付けていたにもかかわらずバッジ自体は折り返されず、セル幅（130px）を大きく超えて（151px）隣の要素・下の行に視覚的に重なって表示された。実ブラウザで下にスクロールして初めて発見した（`table-fixed`の列幅自体は正しく130pxのまま変わっていなかったため、上記の「列が広がる」不具合とは別の症状）
- 原因: `overflow-wrap: break-word`はテキストの折り返しルールとして子孫に継承されるが、`display: inline-block`な要素自身の「置き換えられない限り自分の内容に基づいて幅を決める」というサイズ決定の性質までは変えない。`<td>`側で折り返しを許可していても、`inline-block`の中身が折り返せない1単語の場合、その`inline-block`要素自体が中身の全幅を必要とする箱として振る舞い、結果的に親セルの幅を無視してはみ出す
- 対処: `inline-block`要素自身（`resources/views/components/badge.blade.php`）に直接`max-w-full break-words`を追加する。`max-w-full`で自身の幅を親の利用可能幅までに制限し、`break-words`（`overflow-wrap: break-word`）と組み合わせることで、はみ出す代わりにバッジ自身の中で改行されるようになる。バッジは他画面でも使う共有コンポーネントだが、通常の短いテキストでは`max-w-full`は何の影響も与えないため後方互換
- 教訓: 折り返し系のCSSプロパティ（`overflow-wrap`/`word-break`）は継承されるが、`inline-block`のような「自身の内容で幅が決まる」表示タイプの要素には、**その要素自身にも**明示的に指定しないと効かないことがある。親要素にだけ付けて安心せず、実際にはみ出していないか（`getBoundingClientRect().width`が親セル幅を超えていないか）を実ブラウザで確認すること
