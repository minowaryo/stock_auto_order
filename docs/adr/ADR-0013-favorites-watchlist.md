# ADR-0013: お気に入り未保有銘柄ウォッチリストと新規投資候補画面の刷新

## Status
Accepted（Gate 1: requirements.md / Gate 2: use-cases.md UC-012 / Gate 3: data-model.md を 2026-09-08 に minowaryo が一括承認）

## Date
2026-09-06（起票）／ 2026-09-08（Gate 1〜3 承認）

## Context

### 本人の要望

本人が楽天証券に登録している「お気に入り銘柄」を書き出した CSV
（`docs/original-docs/お気に入り銘柄CSV.csv`、216銘柄）が追加された。要望は:

1. お気に入り銘柄を、既にシステムが取得済みの指標データと突き合わせて **一括で一覧表示**したい
2. その中から **買い時・買い足し候補**を抽出して出力したい
3. 主目的は **新規投資候補の選定**。「まだ持っていない銘柄」の情報を強く欲している。
   既に保有している銘柄は一覧から一律で除外してよい

### なぜ現状のシステムでできないのか

本システムの分析パイプライン（`FetchExternalMarketDataAction`）は、CSV 取込のたびに
**保有銘柄（`holding_snapshots`）に載っている銘柄だけ**について外部API
（Yahoo Finance＝株価、J-Quants＝日本株決算、Finnhub＝米国株財務）を叩き、
`technical_indicators` / `fundamental_indicators` を更新する。

お気に入り216銘柄のうち **未保有の銘柄は一度も外部APIを叩いたことがない**ため、
`technical_indicators` / `fundamental_indicators` に行が存在しない。
「すでに取れている情報で一覧を出す」は、未保有分については元データが無い。

→ 本CRの工事の本体は画面ではなく、**未保有のお気に入り銘柄についても外部APIを叩いて
指標を DB に入れる仕組み**である。

### CSV の実態（調査済み）

| 項目 | 内容 |
|---|---|
| 文字コード / 改行 | CP932（Shift-JIS）／CRLF。`file(1)` は NEL 終端と誤検出するが実体は SJIS ダブルバイトの後続バイト(0x85)で、`mb_convert_encoding(SJIS-win→UTF-8)` 後は素直な CRLF のみ |
| 行数 | 221行（1行目 `"MS2","2"` はメタ行） |
| 列構成 | `市場区分, 銘柄コード, フォルダ名, 取引所コード, 取引所ラベル, 銘柄名` |
| 内訳 | `STK`（日本株）144件 / `USS`（米国株）72件 / `CFD` 4件 |
| フォルダ名 | 「通信/情報系　日本株」「訪日インバウンド旅行」「日本株 トレンド国策銘柄」「インド株」等10種（STK/USS 行のフォルダ名の distinct 数。実測）。本人のテーマ分類として機能している |
| 注意点 | `USS` のうち SPYD/HDV/VYM は実質 ETF だが CSV に種別を示す列がない。`CFD` 4件は本システムの `instrument_type`（stock/etf/mutual_fund）対象外 |

### 既存の「新規投資候補」画面（`/candidate-check`）が機能していない

本人からの指摘:「投資のページがうまく利用できない。重複チェックとか試してみたけど使い方も
よく分からないし利用価値が感じられない。刷新して今回の機能に寄せるのもあり」

`/candidate-check` は `BACKGROUND.md` の課題「保有銘柄が AI・フィジカルAI・半導体関連に
偏っており、他セクターへの分散も課題」から生まれ、2機能で構成される。

- **UC-006（新規投資候補の重複チェック）**: ①買う前に「また同じセクターでは？」を
  `overlap_rate`（対象銘柄と同一セクターの既存保有比率）で突きつける ②CSV取込後の
  事後チェック経路（保有一覧の NEW バッジ → 本画面） ③ウォッチステータス
  （様子見/買い時/次回購入候補/リバランス対象）・メモの蓄積。
  明示的な非目標:「どの銘柄が有望かという選定自体はシステムの対象外」
- **UC-008（新規投資候補レコメンド・軽量版）**: UC-006 の入口に候補を供給する

**機能しなかった構造的原因**:

- UC-008（`NewCandidateFinder`）は `holdings` テーブルからしか候補を出せない
  （`whereNotIn('id', $heldHoldingIds)`）。`holdings` に入るのは「過去に保有した銘柄」か
  「UC-006 で手入力された銘柄」のみ。**新規投資候補なのに、過去に持ったことのある銘柄しか出ない**
- セクター取得（`sector_classification_id` の解決）は日本株限定（`FetchExternalMarketDataAction`
  の J-Quants 分岐）のため、**米国株の候補は永久にゼロ件**
- 財務健全性フィルタ `passed` は実測で保有128銘柄中22件（17%）と厳しい
- 結果、おすすめ候補はほぼ空になり、UC-006 の入口が「銘柄コード手入力」だけになった。
  **候補リストを持っていない人間に候補コードを手入力させる**設計で、使われなくなった

お気に入り CSV は、この欠けていた「候補リストの供給源」そのものである。
フォルダ名10種は UC-008 が手動登録させようとしていた `watched_themes`（注目テーマ）の上位互換。

### 本人と合意したスコープ（2026-09-06、AskUserQuestion）

| 論点 | 決定 |
|---|---|
| 提供形態 | アプリの**常設画面**（一回きりのレポートではない） |
| 表示対象 | **未保有銘柄のみ**（保有済みは一律除外）。出す情報は売買シグナル画面と同等 |
| 置き場所 | `/candidate-check`（新規投資候補タブ）を**全面刷新**して主役にする |
| 買い候補ロジック | **既存の押し目買いシグナル（`BuySignalDeterminationService`）＋財務健全性フィルタ（`FundamentalHealthEvaluator`）を流用**。新ロジックは作らない |
| 候補の出し方 | シグナルで**絞り込まず、全件をソートで並べる**（F-011 整理検討テーブルと同じ思想） |
| データ取得タイミング | お気に入り CSV アップロード時 ＋ アップロードボタンの近くに置く「一括更新」ボタン（保有＋お気に入りを一律更新） |
| 一括更新の実行方式 | **キュー（非同期）実行 ＋ 画面に進捗表示** |
| CSV 再アップロード時 | **追加のみ**（前回あって今回無い銘柄もウォッチリストに残す） |
| 手動お気に入り | 画面上で銘柄を **★ で手動お気に入り登録・解除**できる機能を別途用意する |

## Decision

### D1. `requirements.md` 2章 IN に「楽天証券お気に入り銘柄 CSV の取込」を追加し、F-012 を新設する

- 2章 IN に「楽天証券のお気に入り銘柄リスト CSV を取り込み、未保有銘柄を
  ウォッチリストとして管理する」を追加
- 4章に **F-012「お気に入り未保有銘柄ウォッチリスト」**（Phase 2、優先度 中）を追加
- **F-006 / F-008 は F-012 に統合・刷新**する。要件そのもの（重複度の可視化・
  ウォッチステータス／メモ・小口購入額・NISA 推奨）は F-012 に引き継ぐが、
  「銘柄コード手入力の入口」「`watched_themes` の手動登録」は廃止する
- 7章のフェーズ計画に F-012 の位置づけ（F-006/F-008 の置き換え）を追記

### D2. 未保有のお気に入り銘柄も `holdings` に登録し、既存の指標テーブルを再利用する

`holdings` は実質「銘柄マスタ」（`unique(symbol_code, market)`、保有数量は
`holding_snapshots` 側）。`data-model.md` は既に
「`holdings` の作成経路は2つ。②新規投資候補チェック（UC-006）で未知の銘柄コードが
指定された際の find-or-create。②の場合は `holding_snapshots` の行は作られないが
`technical_indicators` / `fundamental_indicators` / `financial_statements` は参照できる」
と定めている（`holdings` セクションの注記）。**本CRはこの既定の経路②を実際に使う。**

- お気に入り CSV パース結果の各銘柄を `Holding::firstOrCreate(['symbol_code','market'], [...])`
  で登録する（`ImportCsvAction` の保有銘柄 upsert と同じ形）
- 保有済み銘柄は既存の `holdings` 行を再利用する（重複を作らない）
- `technical_indicators` / `fundamental_indicators` / `financial_statements` は
  すべて `holding_id` を主キーとする 1:1 キャッシュのため、未保有銘柄でも
  そのまま `updateOrCreate` できる

**副作用の確認（調査済み・いずれも問題なし）**:

| 懸念 | 結論 |
|---|---|
| 保有一覧（UC-002）に未保有銘柄が混ざる | 混ざらない。`ListHoldingsAction` は `holding_snapshots` 起点 |
| NEW バッジ（`is_newly_detected`）が誤作動する | しない。`ImportCsvAction` は「前回スナップショットに存在したか」で判定し `first_detected_at` は使わない |
| セクター配分（UC-005）が狂う | 狂わない。`SectorAllocationCalculator` も最新スナップショット起点 |
| `NewCandidateFinder`（UC-008）に新規行が現れる | 現れるが、UC-008 の画面導線を D6 で廃止するため影響なし |

### D3. 買いシグナルの保存先だけは新テーブル `watchlist_buy_signals` を作る

`signals` / `buy_signals` は `holding_snapshot_id` 外部キーを持つ。未保有銘柄には
スナップショット行が存在しないため流用できない。また `BuySignalDeterminationService::determine()`
は**週足の価格履歴そのもの**を必要とし、保存済み指標から再計算できないため、
判定結果を永続化する必要がある。

→ `watchlist_buy_signals`（`holding_id` キー、値域は `buy_signals` と同一）を新設する。
ADR-0007 D2 が「買いシグナルは `signals` を触らず独立テーブルに置く」とした判断と同形。
`buy_signals.holding_snapshot_id` を nullable 化する案は UC-010 の既存クエリ全てに影響するため却下（Rationale 参照）。

### D4. 押し目買いシグナルは「絞り込み条件」ではなく「1つの列＋ソートキー」として使う

`BuySignalDeterminationService` は ADR-0007 で**保有銘柄への買い増し**用に設計された。
新規エントリーへの転用には次の限界がある:

- 7シグナル全てに「直近13週で1度は 52週高値の 85%以上」という共通前提がある
  （ADR-0007 Addendum）。**高値更新中の優良銘柄は1件もシグナルが出ない**
- 「52週安値近辺」シグナルは、保有銘柄なら拾い場だが、未保有の新規銘柄では
  下降トレンド継続の疑いが濃い。共通前提と合わせると「高値から急落した銘柄」を推す形になる
- 押し目シグナルは「いつ買うか」しか答えず、「どれを買うか」の軸（バリュエーション水準・
  既存保有との重複）を持たない

したがって本CRでは **シグナルで対象を絞り込まない**。未保有の全銘柄を表示し、
押し目シグナルは表示の1列と並び順への寄与に留める。`BuySignalDeterminationService`
自体は**無改修**（共通前提を新規候補に限り緩める案は、実データで発生件数を見てから
別CRとして判断する。Consequences 参照）。

ソートキー（上位ほど検討価値が高い、F-011 D8 と同じ透明マルチキーソート・
F-009 の非開示合成スコアは使わない）:

1. 押し目買いシグナル件数（降順）
2. 財務健全性 `passed` → `unavailable` → `failed`
3. 同セクター保有比率（`overlap_rate`、昇順＝分散に効く銘柄が上）
4. 52週レンジ内の位置 `(現在値 − week52_low) ÷ (week52_high − week52_low)`（昇順＝高値から離れている順）

### D5. 一括更新はキュー実行とし、対象は「未保有のウォッチリスト銘柄」に限定する

- `RefreshWatchlistMarketDataJob`（Job）＋ `RefreshWatchlistMarketDataAction`（純ロジック）を新設
- **対象は未保有のウォッチリスト銘柄のみ**。保有銘柄は週次 CSV 取込
  （`FetchExternalMarketDataAction`）で既に更新されるため二重に叩かない。
  これにより対象が約350→100〜150銘柄に減り、実行時間も短縮する
  （本人の当初要望「保有＋お気に入りを一律更新」に対する調整。保有分は
  取込のたびに最新化されるので実質同じ）
- 既存サービスを**そのまま利用**する（新規クライアントは作らない）:
  `JpStockPriceClientInterface` / `UsStockPriceClientInterface` /
  `MarketIndexClientInterface` / `JQuantsClientInterface` / `FinnhubClientInterface` /
  `TechnicalIndicatorCalculator` / `FundamentalIndicatorMapper` /
  `UsFundamentalIndicatorMapper` / `BuySignalDeterminationService`
- 書き込み先: `technical_indicators` / `fundamental_indicators` / `financial_statements`
  （`holding_id` で `updateOrCreate`）＋ `watchlist_buy_signals`（銘柄単位で delete → 再作成）
- **`signals` / `buy_signals` には一切書き込まない**（週次取込＝UC-004/UC-010 の領分）
- 銘柄単位の try/catch ＋ `Log::warning`（`FetchExternalMarketDataAction` と同じ。
  例外メッセージに API キーが含まれない前提も同じ）。失敗銘柄は既存の
  「取得不可（—）」表示にフォールバックする（ADR-0009 D5 と同方針）
- Finnhub 無料枠 60req/min に合わせた呼び出し間ウェイトを入れる
- 進捗は `watchlist_refresh_runs` テーブル（`processed_count` / `total_count` など）に記録し、
  画面が `wire:poll` で読む
- キューワーカー未整備のため `compose.yaml` に `queue` サービス
  （`php artisan queue:work --tries=1 --timeout=3600`）を追加する
- 同等の `watchlist:refresh` artisan コマンドも用意する（`RefetchUsFundamentalsCommand`
  の先例。キューワーカー不調時の逃げ道・CI／手動運用用）

### D6. `/candidate-check` を「お気に入り未保有銘柄一覧」中心に刷新する（UC-012）

ルート `/candidate-check`・ナビ「新規投資候補」タブは維持（`ui-guidelines.md` の
6タブ上限方針を守るため新タブは作らない）。画面構成:

1. **取込・更新バー**: お気に入り CSV アップロード、隣に「一括更新」ボタン、
   最終更新日時、実行中は「更新中 42/150」の進捗
2. **未保有銘柄一覧（主役）**: 保有済みを除外した全件を D4 の4キーでソート表示。
   既存の `x-signal-table-*` 部品と `SignalCriteriaEvaluator::evaluateBuy()` の
   判定チェックリストを流用（＝売買シグナル画面と同じ見た目・同じ判断材料）。
   列: ★ / 銘柄 / 市場 / フォルダ / 現在値 / 52週レンジ内位置 / **同セクター保有比率** /
   押し目シグナル数 / 財務健全性判定 / RSI / PER / PBR / ROE / 自己資本比率 / 営業利益率 /
   判定チェックリスト（テクニカル・財務）。フォルダ絞り込み・★のみ表示フィルタ付き。
   行展開で `ShowCandidateCheckAction` 相当の内容（過去業績推移・分散コメント・
   ウォッチステータス／メモの記録と履歴）＝ **UC-006 の中身をここに収容**
3. **★トグル**: `watchlist_items.is_starred` を反転する Livewire アクション

**削除するもの**: 「おすすめ候補」セクション（UC-008）、「銘柄コード手入力」フォーム。
`NewCandidateFinder` / `WatchedTheme` 関連クラスとテーブルは残置し（`/api/new-candidates`
エンドポイントと既存テストを壊さない）、画面からの導線のみ削除する。

**引き継ぐ UC-006 の意図**:

| 元の意図 | 刷新後 |
|---|---|
| 重複度・分散影響の可視化（`overlap_rate`） | 一覧の1列「同セクター保有比率」に昇格。手入力不要で全銘柄に常時表示 |
| ウォッチステータス・メモ（`watch_records`） | 一覧の行展開から記録・閲覧 |
| NEW バッジからの事後チェック導線 | 遷移先を新一覧の該当行に付け替え |
| 銘柄コード手入力の入口 | 廃止（お気に入り一覧が代替） |
| UC-008 注目テーマの手動登録（`watched_themes`） | 廃止（CSV フォルダ名が上位互換） |

### D7. 新テーブルは3つ。既存テーブルの変更はしない

- `watchlist_items`（`holding_id` FK・unique、`folder_name`、`exchange_label`、
  `source`〔`rakuten_favorites_csv`/`manual`〕、`is_starred` bool、`last_seen_in_csv_at`、
  `registered_at`）
- `watchlist_buy_signals`（D3）
- `watchlist_refresh_runs`（`status`、`total_count`、`processed_count`、`failed_count`、
  `started_at`、`finished_at`）

いずれも新規テーブル追加のみで、`.claude/rules/20-mysql.md` の危険操作に該当しない。
`is_starred` を `watch_records` に寄せない理由: `watch_records` は append-only の履歴で
「★を外す」操作が表現できない（外す＝『様子見』を追記では意味が変わる）。★は独立 boolean とする。

## Rationale

- **`holdings` を再利用する（D2）**: `data-model.md` が既に「作成経路②」として想定・許容している。
  新たに `watchlist_symbols` のような銘柄マスタを並立させると、`technical_indicators` 等の
  `holding_id` 外部キーを二重管理することになり、UC-003/UC-006 の表示ロジックも分岐が必要になる
- **買いシグナルだけ別テーブル（D3）**: ADR-0007 D2 と同じ。`buy_signals.holding_snapshot_id`
  を nullable 化すると、`ShowBuySignalListAction` の抽出クエリ・`FetchExternalMarketDataAction`
  の再判定削除・UC-011 の `evaluateLossReview()` の押し目件数カウントの3系統に
  「スナップショット無しの行」が漏れ込むリスクがある。新テーブルなら既存ゼロ改修
- **シグナルで絞り込まない（D4）**: F-011 D8 と同じ。押し目シグナルの共通前提は
  「保有していて含み益が出ている（もしくは薄い）優良銘柄が一時的に下げた」場面に
  最適化されており、新規候補の母集団（高値更新中の銘柄を含む216銘柄）に対しては
  取りこぼしが大きすぎる。絞り込みに使うと「候補ゼロ件」が現実的に起こる
  （財務 passed 17% × 押し目シグナル発生率）。全件ソートなら常に「今週はこの辺が上位」が見える
- **対象を未保有のみに絞る（D5）**: 本人の当初要望は「保有＋お気に入りを一律更新」だったが、
  保有分は週次 CSV 取込で毎回最新化されるので、一括更新ボタンで重ねて叩く実益がない。
  Finnhub 律速の実行時間を約半分にできる
- **`/candidate-check` の刷新（D6）**: 既存の UC-006/UC-008 は「候補供給」が構造的に
  破綻しており、新タブを足すより既存の空き家を建て替えるほうが `ui-guidelines.md` の
  タブ数方針とも整合する。UC-006 の価値ある部分（重複度・ウォッチメモ）は一覧に畳み込む

### 採用しなかった代替案

- **一回きりの分析レポートを出力する**: 本人が「常設画面」を明示的に選択。また未保有分の
  API 取得という工事は一回きりでも必要で、レポートだけ作っても再利用できない
- **`watchlist_symbols` 等の独立した銘柄マスタを新設し、指標テーブルも別立てにする**:
  `technical_indicators` / `fundamental_indicators` / `financial_statements` /
  `watch_records` を「保有用」「ウォッチ用」で二重に持つことになる。D2 の Rationale 参照
- **`buy_signals.holding_snapshot_id` を nullable 化して1テーブルに統合する**: D3 の Rationale 参照
- **押し目シグナル1件以上 かつ 財務 passed で候補を絞り込む**: D4 の Rationale 参照。
  候補ゼロ件のリスク
- **新規投資候補用に別タブを作る**: `ui-guidelines.md` のタブ数上限（6個、現在6個）に抵触
- **`BuySignalDeterminationService` の共通前提（52週高値85%以内）を本CRで緩める**:
  影響が UC-010（保有銘柄の買い増し）にも及ぶ横断変更になる。実データで新規候補の
  シグナル発生件数を見てから、別CRで「新規候補に限り前提を外す」判断をする
- **お気に入り CSV を保有 CSV 取込（UC-001）と同じ画面・同じフローに載せる**:
  CSV の形式（6列・Shift-JIS・数量なし）も目的（未保有の候補管理）も異なる。
  UC-001 のパーサ・スナップショット生成に無関係な分岐が増える

## Consequences

### メリット

- 未保有のお気に入り銘柄について、売買シグナル画面と同等の判断材料
  （テクニカル・ファンダ・判定チェックリスト）が一覧で見えるようになる
- 重複度（`overlap_rate`）が手入力なしで全候補に常時表示され、`BACKGROUND.md` の
  「セクター偏り」課題に初めて実用的に効く
- 既存の分析サービス（クライアント・Mapper・Calculator・判定サービス）を新規実装ゼロで流用
- 既に Green・マージ済みの UC-001〜UC-011 のロジックへの改修が発生しない
  （`/candidate-check` の Livewire コンポーネントと Blade のみ差し替え）

### デメリット・リスク

- Gate 1（requirements.md）・Gate 2（use-cases.md）・Gate 3（data-model.md）で
  一度承認された F-006/F-008 の設計を刷新する CR のため、3ドキュメントで再レビューが必要
- 一括更新の実行時間: 未保有100〜150銘柄 × 平均2〜3コール ≒ 300〜450リクエスト。
  Finnhub 60req/min 律速で実測5分前後を見込む。Cycle 3 で実測し、長すぎる場合は
  「お気に入りのみ更新」などの絞り込みオプションを足す
- 押し目シグナルの発生件数が新規候補の母集団で極端に少ない可能性がある。
  D4 の通りソートキーには使えるが、実測して `BuySignalDeterminationService` の
  前提緩和を別CRで検討する余地を残す
- US ETF 3件（SPYD/HDV/VYM）が `stock` として登録される。Finnhub のファンダが疎になり
  `unavailable` 表示に落ちるだけで害はないが、実測後に除外判断
- キューワーカーを `compose.yaml` に追加するインフラ変更が入る（個人利用規模のため
  supervisor 等の本格構成は不要、単一 `queue:work` プロセスで十分）
- `watchlist_items` / `watchlist_buy_signals` / `watchlist_refresh_runs` の3テーブル追加。
  ただし `signals`/`buy_signals` に続く同型テーブルの追加であり、パターンは既存踏襲

## Related

- `docs/product/requirements.md`（2章 IN 追加・4章 F-012 追加・F-006/F-008 改訂・7章）
- `docs/product/use-cases.md`（UC-012 新設・UC-006 改訂・UC-008 Superseded）
- `docs/architecture/data-model.md`（`watchlist_items` / `watchlist_buy_signals` /
  `watchlist_refresh_runs` 新設・ソートキー仕様・「保留・確定が必要な初期パラメータ値」表）
- `docs/product/ui-guidelines.md`（新規投資候補画面の刷新後構成・タブ数方針の維持）
- `docs/ai-context/module-map.md` / `docs/ai-context/glossary.md`（`app/Actions/Watchlist/`・用語追加）
- `docs/rcid/traceability-matrix.md`（F-012 行・CHG-0014・F-006/F-008 行の刷新注記）
- `docs/adr/ADR-0007-existing-holding-add-on-buy-recommendation.md`（`buy_signals` を独立
  テーブルにした先例、透明マルチキーソートの先例、`BuySignalDeterminationService` の共通前提）
- `docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md`（銘柄単位の取得失敗を `unavailable`
  にフォールバックする方針、`RefetchUsFundamentalsCommand` の単独コマンド先例）
- `docs/adr/ADR-0010-loss-review-candidate-list.md`（シグナルで絞り込まず全件を透明ソートで
  並べる先例、UC-004 の鏡像機能を同一画面に足す先例）
- `docs/adr/ADR-0011-operating-margin-health-criterion.md`（財務健全性フィルタ4条件目）
