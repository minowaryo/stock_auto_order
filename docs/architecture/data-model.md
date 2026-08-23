# data-model.md — データモデル設計

> DB変更前に必ず参照すること。
> マイグレーション方針は `.claude/rules/20-mysql.md` を参照。
> 本ドラフトは `docs/product/use-cases.md`（UC-001〜UC-009、2026-08-15 Gate 2承認済み）を根拠に作成した。**2026-08-15 Gate 3承認済み**（テーブル構成・カラム設計を承認。「保留・確定が必要な初期パラメータ値」表のうち財務健全性フィルタ・合成スコアの重み付けの2項目は、叩き台の値のまま承認しPhase 1実装〔`/tdd`サイクル〕時に確定する方針とした。詳細は本ファイル末尾「承認記録」参照）。

## 前提

- 利用者は本人1人のみ（`docs/ai-context/project-summary.md`）。`users`テーブルはLaravel標準のまま維持し、複数ユーザーを前提としたテナント分離カラムは設けない
- 週次スナップショット運用（CSV取込のたびに新しい世代を追記し、既存データは上書きしない）が全体の設計の基本軸
- 口座区分（特定口座/一般口座/NISA成長投資枠/NISAつみたて投資枠）の内訳は、`holding_snapshot_accounts`テーブルに追記保存する（ADR-0002）。銘柄コード単位で合算・加重平均した値は引き続き`holding_snapshots`に保存し続け、既存の画面・ロジックへの影響はない

## ER図（概要）

```
[import_batches] ──1:1── [snapshots] ──1:N── [holding_snapshots] ──N:1── [holdings] ──N:1── [sector_classifications]
                              |                                              |  |
                              |                                              |  └──1:1── [technical_indicators]（現在値キャッシュ）
                              |                                              └──1:1── [fundamental_indicators]（現在値キャッシュ）
                              |                                              └──1:N── [financial_statements]
                              |
                              └──1:N── [market_indicator_snapshots]
[holding_snapshots] ──1:N── [signals]
[holding_snapshots] ──1:N── [holding_snapshot_accounts]

[import_batches] ──1:1── [import_summary_reports] ──1:N── [import_summary_report_items]

[holdings] ──1:N── [holding_memos]
[watch_records]（symbol_code + market。holdingsへの正規FKは持たない。理由は後述）
[watched_themes]（独立マスタ）
```

> `holdings`は「現在保有中の銘柄」だけでなく「過去に取込または候補チェックで参照したことがある全銘柄」を表す銘柄マスタとして扱う（UC-001のCSV取込だけでなく、UC-006/UC-008/UC-009での候補銘柄チェック時にも`find-or-create`で行を作成する）。「現在保有しているか」は`holdings`のカラムでは持たず、直近`snapshots`に対応する`holding_snapshots`行の有無で判定する。

## テーブル定義

### users

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| name | varchar(255) | NO | - | 表示名 |
| email | varchar(255) | NO | - | メールアドレス（unique） |
| email_verified_at | timestamp | YES | null | メール確認日時 |
| password | varchar(255) | NO | - | ハッシュ化パスワード |
| created_at | timestamp | NO | now() | 作成日時 |
| updated_at | timestamp | NO | now() | 更新日時 |
| deleted_at | timestamp | YES | null | 論理削除日時 |

**Index**: `email` (unique)

> 本人1人のみの利用のため、認可はSanctum認証のみで足り、Policyでのロール分岐は設けない（`meta/adr/ADR-0003-auth-strategy.md`）。

---

### import_batches（UC-001）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| status | enum('pending','processing','completed','failed') | NO | 'pending' | 取込ステータス（`glossary.md`のステータス定義に準拠） |
| jp_stock_filename | varchar(255) | NO | - | アップロードされた国内株式CSVの元ファイル名 |
| us_stock_filename | varchar(255) | NO | - | アップロードされた米国株式CSVの元ファイル名 |
| mutual_fund_filename | varchar(255) | YES | null | アップロードされた投資信託CSVの元ファイル名（任意） |
| imported_count | int unsigned | NO | 0 | 正常に取り込んだ銘柄数 |
| error_count | int unsigned | NO | 0 | スキップした行数 |
| failure_reason | varchar(255) | YES | null | `status='failed'`時の失敗理由（ファイル全体パース不能等） |
| imported_at | timestamp | YES | null | 取込完了日時（`status='completed'`時に設定） |
| created_at | timestamp | NO | now() | 作成日時（アップロード受付日時） |
| updated_at | timestamp | NO | now() | 更新日時 |

**Index**: `status`, `imported_at`

---

### snapshots（週次スナップショット世代・UC-001）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| import_batch_id | bigint | NO | - | `import_batches.id` への参照（1取込＝1スナップショット世代） |
| snapshotted_at | timestamp | NO | - | スナップショット時点日時（`import_batches.imported_at`と同値） |
| created_at | timestamp | NO | now() | 作成日時 |

**Index**: `snapshotted_at`（「直近」判定に使用。`ORDER BY snapshotted_at DESC LIMIT 1`）
**FK**: `import_batch_id` → `import_batches(id)`（unique制約も兼ねる。1取込1スナップショット）

> UC-002/003/004は常に「直近のスナップショット」のみを参照する。「直近」は本テーブルの`snapshotted_at`最大値で判定する。

---

### holdings（銘柄マスタ・UC-001/002/003）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| symbol_code | varchar(255) | NO | - | 銘柄コード（JP証券コード／USティッカー／投信ファンド名）。投資信託はファンド名そのものを格納するため20桁では収まらず、UC-001実装（Gate4/Green）時にvarchar(20)からvarchar(255)へ拡張した |
| market | enum('jp','us','mutual_fund') | NO | - | 市場区分 |
| instrument_type | enum('stock','etf','mutual_fund') | NO | - | 銘柄種別。ETF/投資信託は指標・シグナル計算の対象外（UC-002業務ルール） |
| symbol_name | varchar(255) | NO | - | 銘柄名 |
| sector_classification_id | bigint | YES | null | `sector_classifications.id` への参照。未分類はnull |
| first_detected_at | timestamp | NO | now() | 初回検出日時（新規検出銘柄判定の補助情報。候補チェックのみで一度も保有していない銘柄も含む） |
| created_at | timestamp | NO | now() | 作成日時 |
| updated_at | timestamp | NO | now() | 更新日時 |

**Index**: `(symbol_code, market)` unique（UC-001業務ルール「銘柄コード＋市場区分の組み合わせで一意」）、`sector_classification_id`
**FK**: `sector_classification_id` → `sector_classifications(id)`

> **作成経路は2つ**: ①CSV取込（UC-001）でのバッチ作成、②新規投資候補チェック（UC-006）・レコメンド（UC-008/UC-009）で未知の銘柄コードが指定された際の`find-or-create`。②の場合は保有していないため、`holding_snapshots`の行は作られない（＝一覧・チャートには出ない）が、`technical_indicators`/`fundamental_indicators`/`financial_statements`は参照できる。

---

### holding_snapshots（銘柄ごとの週次実績・UC-001/002/003/004）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| snapshot_id | bigint | NO | - | `snapshots.id` への参照 |
| holding_id | bigint | NO | - | `holdings.id` への参照 |
| quantity | decimal(15,2) | NO | - | 保有数量（複数口座区分は合算値） |
| average_cost | decimal(15,2) | NO | - | 取得単価（複数口座区分は加重平均、円建て） |
| current_price | decimal(15,2) | NO | - | 現在値（投資信託は基準価額、円建て） |
| fx_rate_used | decimal(10,4) | YES | null | US株の円換算に使用した参考為替レート（`market='us'`のみ設定） |
| unrealized_gain_amount | decimal(15,2) | NO | - | 含み益額（円） |
| unrealized_gain_rate | decimal(7,4) | NO | - | 含み益率（%） |
| ma20 | decimal(15,2) | YES | null | 当該週時点の20週移動平均線（チャート描画用の履歴値） |
| ma75 | decimal(15,2) | YES | null | 当該週時点の75週移動平均線（チャート描画用の履歴値） |
| is_newly_detected | boolean | NO | false | 直前スナップショットに存在しなかった銘柄か（初回取込時は常にfalse） |
| created_at | timestamp | NO | now() | 作成日時 |

**Index**: `(snapshot_id, holding_id)` unique、`holding_id`（銘柄単位の時系列取得＝株価推移チャート用に使用）
**FK**: `snapshot_id` → `snapshots(id)`、`holding_id` → `holdings(id)`

> 株価推移チャート（UC-003）は本テーブルの`current_price`/`ma20`/`ma75`を`holding_id`で時系列に並べたものをそのまま終値データとして再利用する（新規のリアルタイム取得は行わない、という業務ルールに合致）。ここでの`ma20`/`ma75`は「取込週ごとに確定した過去値」であり、週が進めば値そのものが変わるため保存しても重複にはならない（後述の`technical_indicators`＝直近値キャッシュとは役割が異なる）。

---

### holding_snapshot_accounts（口座区分別の保有内訳・UC-001/004/005/008、ADR-0002）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| holding_snapshot_id | bigint | NO | - | `holding_snapshots.id` への参照 |
| account_type | enum('specific','general','nisa_growth','nisa_tsumitate') | NO | - | 口座区分（特定口座/一般口座/NISA成長投資枠/NISAつみたて投資枠） |
| quantity | decimal(15,2) | NO | - | 当該口座区分での保有数量 |
| average_cost | decimal(15,2) | NO | - | 当該口座区分での取得単価（円建て） |
| created_at | timestamp | NO | now() | 作成日時 |

**Index**: `(holding_snapshot_id, account_type)` unique、`holding_snapshot_id`
**FK**: `holding_snapshot_id` → `holding_snapshots(id)`

> `holding_snapshots`の`quantity`/`average_cost`（合算・加重平均値）はそのまま維持し、本テーブルは口座区分別の内訳を追加で保存するもの。`account_type`が`nisa_growth`/`nisa_tsumitate`の行を「NISA区分」として扱い、UC-004（利確シグナル）・UC-005（リバランス提案）で除外判定に使う。履歴ログ系テーブル（追記のみ、UPDATE/DELETEしない）として`holding_snapshots`と同じ設計方針に従う。

---

### technical_indicators（UC-002/003/004/006/008/009、個別株のみ・現在値キャッシュ）

保有中・候補問わず、指標を計算したことがある銘柄について**「現在の値」を1銘柄1行で保持し、再計算のたびにUPDATEする**キャッシュテーブル。週次取込のたびに新しい行をINSERTする設計にすると、値が変わらない週でも行が増え続けるため、あえて履歴を持たずUPSERTのみにしている（保有銘柄のチャート用の週次履歴は`holding_snapshots.ma20`/`ma75`側で別に持つ）。

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| holding_id | bigint | NO | - | `holdings.id` への参照（1:1、保有中・候補いずれの銘柄も対象） |
| rsi | decimal(5,2) | YES | null | RSI（14期間、直近値） |
| macd | decimal(10,4) | YES | null | MACD値（12/26期間、直近値） |
| macd_signal | decimal(10,4) | YES | null | MACDシグナル線（9期間、直近値） |
| ma20 | decimal(15,2) | YES | null | 20週移動平均線（直近値） |
| ma75 | decimal(15,2) | YES | null | 75週移動平均線（直近値） |
| bb_upper | decimal(15,2) | YES | null | ボリンジャーバンド+2σ（直近値） |
| bb_lower | decimal(15,2) | YES | null | ボリンジャーバンド-2σ（直近値） |
| volume | bigint unsigned | YES | null | 直近出来高（株数、ADR-0004） |
| volume_ma20 | bigint unsigned | YES | null | 20週平均出来高（ADR-0004） |
| week52_high | decimal(15,2) | YES | null | 52週高値（ADR-0004） |
| week52_low | decimal(15,2) | YES | null | 52週安値（ADR-0004） |
| relative_strength_vs_market | decimal(7,4) | YES | null | 相対力（対市場、%）。直近13週の当該銘柄騰落率−市場指数〔日経平均/S&P500〕騰落率（ADR-0004） |
| relative_strength_vs_sector | decimal(7,4) | YES | null | 相対力（対セクター、%）。直近13週の当該銘柄騰落率−保有銘柄内の同一セクター平均騰落率（J-Quants無料プランに業種別指数がないための簡易算出、ADR-0004） |
| computed_at | timestamp | NO | now() | 最終計算日時（UPSERTのたびに更新） |

**Index**: `holding_id` unique
**FK**: `holding_id` → `holdings(id)`

> `holdings.instrument_type`がETF・投資信託の場合は行を作成しない（UC-002業務ルール「指標欄は対象外として扱う」）。外部データ取得に失敗した指標項目はnullのまま保存し、画面側で「取得不可」と表示する（UC-003エラーケース）。CSV取込時（UC-001）は、計算結果をこのテーブルにUPSERTすると同時に、その週の`holding_snapshots.ma20`/`ma75`にも複製して履歴として残す。

---

### fundamental_indicators（UC-002/003/006/008/009、個別株のみ・現在値キャッシュ）

`technical_indicators`と同じ理由・同じUPSERT方式の現在値キャッシュ。J-Quantsは最大12週間更新されないため、取込のたびに新規INSERTする設計だと同一内容の行が最大12週分近く積み上がってしまう。UPSERTにすることで、実際にJ-Quants側の値が更新された時だけ`fetched_at`が進む（値が変わらなければ行数は増えない）。

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| holding_id | bigint | NO | - | `holdings.id` への参照（1:1、保有中・候補いずれの銘柄も対象） |
| per | decimal(10,2) | YES | null | PER |
| pbr | decimal(10,2) | YES | null | PBR |
| roe | decimal(7,4) | YES | null | ROE（%） |
| revenue_growth | decimal(10,4) | YES | null | 売上高成長率（%、前年同期比）。ADR-0006により`decimal(7,4)`から拡張 |
| operating_income_growth | decimal(10,4) | YES | null | 営業利益成長率（%、前年同期比）。ADR-0006により`decimal(7,4)`から拡張 |
| equity_ratio | decimal(7,4) | YES | null | 自己資本比率（%） |
| dividend_yield | decimal(7,4) | YES | null | 配当利回り（%） |
| dividend_payout_ratio | decimal(7,4) | YES | null | 配当性向（%） |
| eps_growth | decimal(10,4) | YES | null | EPS成長率（%、前年同期比。`financial_statements.eps`から算出、ADR-0004）。実データでほぼゼロ近辺からの回復銘柄が999.9999%を超えINSERTエラーになったため、ADR-0006により`decimal(7,4)`から拡張 |
| peg_ratio | decimal(10,4) | YES | null | PEGレシオ（PER÷EPS成長率）。`eps_growth`が0以下の場合は算出せずnull（ADR-0004） |
| fetched_at | timestamp | NO | now() | J-Quantsからの取得日時（値が変化した時のみ更新。最大12週間遅延の可能性あり） |

**Index**: `holding_id` unique
**FK**: `holding_id` → `holdings(id)`

---

### financial_statements（UC-006の過去業績推移、保有中・候補いずれの銘柄も対象）

決算期ごとの実績は期をまたいで値そのものが変わる真の履歴データなので、`technical_indicators`/`fundamental_indicators`とは異なりUPSERTではなく期ごとにレコードを持つ（`(holding_id, fiscal_period)`が既存なら更新、なければ追加＝`fiscal_period`単位でのUPSERT。同一期を重複INSERTしない）。

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| holding_id | bigint | NO | - | `holdings.id` への参照 |
| fiscal_period | varchar(20) | NO | - | 決算期（例: `2025Q4`） |
| revenue | decimal(18,2) | YES | null | 売上高。J-Quantsが当該期のSalesを欠損で返す場合がありnull許容（ADR-0008） |
| operating_income | decimal(18,2) | YES | null | 営業利益。J-Quantsが当該期のOPを欠損で返す場合がありnull許容（ADR-0008） |
| eps | decimal(10,2) | YES | null | 1株当たり利益（EPS）。`fundamental_indicators.eps_growth`/`peg_ratio`の算出元（ADR-0004） |
| revenue_yoy_change | decimal(7,4) | YES | null | 売上高前年比増減（%） |
| operating_income_yoy_change | decimal(7,4) | YES | null | 営業利益前年比増減（%） |
| fetched_at | timestamp | NO | now() | J-Quantsからの取得日時 |
| created_at | timestamp | NO | now() | 作成日時 |

**Index**: `(holding_id, fiscal_period)` unique
**FK**: `holding_id` → `holdings(id)`

> UC-006業務ルール「直近3〜5期分」の件数は**初期値5期**とし、取得できる期数がそれ未満の場合は取得可能な範囲のみ表示する。

> **実装完了**（2026-08-23、UC-006 Cycle A Gate4）: `FetchExternalMarketDataAction`がJP株について既に取得している`jQuantsClient->fetchStatements()`の5期分をそのまま保存する（新規の外部API呼び出しは追加しない）。`revenue_yoy_change`/`operating_income_yoy_change`は**最新期（index 0）のみ**`FundamentalIndicatorMapper::calculateGrowth()`と同一ロジック（4期前との比較）で算出し、過去の期（index 1〜4）は比較対象期がフェッチ範囲外のためnullのままとする。US株・投信は対象外（fundamentals自体がJP限定のため）。

> **`/review`拡張レベル指摘の修正**（2026-08-23、MEDIUM、ADR-0008）: `revenue`/`operating_income`は当初NOT NULLで定義していたが、データソースである`net_sales`/`operating_profit`（J-Quants）自体がnullを返しうるため、`financial_statements`のINSERTが同一銘柄の`technical_indicators`/`fundamental_indicators`/`signals`更新まで巻き添えでロールバックさせる不具合があった。両カラムをnullableに変更（`2026_08_23_000001_nullable_revenue_operating_income_on_financial_statements_table.php`）。

---

### signals（利確シグナル・UC-004）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| holding_snapshot_id | bigint | NO | - | `holding_snapshots.id` への参照 |
| signal_type | enum('rsi_reversal','macd_dead_cross','bollinger_overheat','week52_high_pullback','peg_overvalued','relative_strength_weakening','volume_spike_decline') | NO | - | シグナル種別。後方4種はADR-0004で追加 |
| reason_summary | varchar(255) | NO | - | 判定根拠の一言サマリ（例: "RSIが72から65に反落"） |
| created_at | timestamp | NO | now() | 検出日時 |

**Index**: `(holding_snapshot_id, signal_type)` unique（再計算・再取込時の重複行作成を防ぐ）
**FK**: `holding_snapshot_id` → `holding_snapshots(id)`

> `split_limit_suggestion`（分割指値提案）はシグナルではなく含み益率のみから機械的に算出できるため、テーブルには持たずアプリケーション層で都度計算する（UC-004業務ルール「固定ルールではなく例示」）。**初期パラメータ値**: 含み益+20%到達で1/3・+35%到達で1/3・残りはトレンド追従。含み益+20%未満の銘柄は本テーブルの対象外（`signals`行を作らない）。
> **`signal_type`のENUM拡張（ADR-0004）は`.claude/rules/20-mysql.md`が定める「危険な操作（カラム型変更）」に該当する**（MySQLの`ENUM`拡張は`ALTER TABLE ... MODIFY COLUMN`を要し、原理上テーブルロックを伴いうる）。個人利用規模（`signals`は数十〜数百行程度）のため実害は軽微だが、本ADRをもって正式な変更理由の記録とする。

---

### sector_classifications（UC-002/005）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| code | varchar(10) | YES | null | J-Quants 17業種コード（`Sector17Code`）。US株等コードが存在しない場合はnull |
| name | varchar(100) | NO | - | セクター名 |
| created_at | timestamp | NO | now() | 作成日時 |
| updated_at | timestamp | NO | now() | 更新日時 |

**Index**: `code`、`name` unique（`watched_themes`等の他マスタ系テーブルと制約の厳密さを揃えるため）

> 「未分類」は`holdings.sector_classification_id = null`で表現し、本テーブルに「未分類」レコードは作らない。
> **粒度は17業種で確定**（33業種は粒度が細かすぎ、UC-005の偏り検出用途では不利と判断。2026-08-15ユーザー確認）。J-Quants APIからは`Sector17Code`/`Sector17CodeName`を取得して`code`/`name`に格納する。

---

### holding_memos（UC-003）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| holding_id | bigint | NO | - | `holdings.id` への参照 |
| body | text | NO | - | メモ本文（最大2000文字はアプリ層バリデーション） |
| recorded_at | timestamp | NO | now() | 記録日時（自動付与） |

**Index**: `holding_id`
**FK**: `holding_id` → `holdings(id)`

> 追記のみ・編集不可（UC-003業務ルール）のため`updated_at`は持たない。削除機能もuse-cases.mdで定義されていないため実装しない（`.claude/rules/30-testing.md`のCRUD網羅ルール = 定義されている操作のみ対象）。

---

### watch_records（ウォッチステータス・UC-006）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| holding_id | bigint | NO | - | `holdings.id` への参照（保有中・候補いずれも対象。UC-006フロー2「セクター分類を取得」の時点で`holdings`にfind-or-createされている前提） |
| watch_status | enum('様子見','買い時','次回購入候補','リバランス対象') | YES | null | ウォッチステータス（ステータスのみ更新の場合あり） |
| memo | text | YES | null | メモ本文（任意、最大2000文字はアプリ層バリデーション） |
| recorded_at | timestamp | NO | now() | 記録日時（自動付与） |

**Index**: `(holding_id, recorded_at)`（最新レコード取得・履歴表示に使用）
**FK**: `holding_id` → `holdings(id)`

> 追記のみで、最新1件を「現在のステータス」として画面表示する。`holdings`が保有・候補を問わない銘柄マスタになったこと（前述）により、`symbol_code`/`market`の直接保持ではなく`holding_id`経由に統一した。

---

### watched_themes（注目テーマ・UC-008）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| name | varchar(100) | NO | - | テーマ・セクター名（本人が自由入力） |
| created_at | timestamp | NO | now() | 登録日時 |
| updated_at | timestamp | NO | now() | 更新日時 |

**Index**: `name` unique

> `deleted_at`（テーマ削除）は持たない。UC-008に登録解除操作が定義されていないため（`.claude/rules/30-testing.md`のCRUD網羅ルールに従い、use-cases.mdで定義されていない削除機能を先回りして実装しない）。将来UC-008に登録解除フローを追加する場合はuse-cases.md更新とあわせて`deleted_at`を追加する。なお`deleted_at`を後付けする場合、MySQLのUNIQUE制約はNULL同士を区別せず`unique(name, deleted_at)`単体では有効レコード内の重複を防げない点に注意（アプリ層バリデーションが別途必要）。

---

### market_indicator_snapshots（市場全体指標・UC-007）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| snapshot_id | bigint | NO | - | `snapshots.id` への参照 |
| index_name | enum('nikkei225','sp500','us10y','vix','usdjpy') | NO | - | 指標名 |
| value | decimal(15,4) | NO | - | 直近値 |
| change_rate | decimal(7,4) | YES | null | 前日比（%） |
| ma_deviation | decimal(7,4) | YES | null | 移動平均乖離率（%） |
| created_at | timestamp | NO | now() | 作成日時 |

**Index**: `(snapshot_id, index_name)` unique
**FK**: `snapshot_id` → `snapshots(id)`

> **Phase1先行実装（ADR-0004）**: 本来UC-007（Phase2）専用のテーブルだが、`technical_indicators.relative_strength_vs_market`（UC-003）の算出に必要なため、`index_name`が`nikkei225`/`sp500`の2件分のみ「取得・保存」ロジックをPhase1で先行実装する（`us10y`/`vix`/`usdjpy`はUC-007画面本体〔Phase2〕着手時にあわせて実装）。F-009がF-005/F-008の軽量ロジックを先行実装したのと同じパターン（`requirements.md` 7章参照）。

---

### import_summary_reports（取込後サマリーレポート・UC-009）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| import_batch_id | bigint | NO | - | `import_batches.id` への参照（1取込＝1レポート） |
| portfolio_headline | varchar(500) | NO | - | 全体感サマリー（1〜数文）。判定の主要因となった代表指標を含める（ADR-0003） |
| generated_at | timestamp | NO | now() | レポート生成日時 |

**Index**: `import_batch_id` unique
**FK**: `import_batch_id` → `import_batches(id)`

---

### import_summary_report_items（UC-009）

| カラム | 型 | Nullable | デフォルト | 説明 |
|---|---|---|---|---|
| id | bigint | NO | auto | 主キー |
| import_summary_report_id | bigint | NO | - | `import_summary_reports.id` への参照 |
| rank | tinyint unsigned | NO | - | 優先度順位（1〜20） |
| is_supplementary | boolean | NO | false | 11〜20位（補足レコメンド）はtrue |
| recommendation_type | enum('利確検討','リバランス','新規投資候補') | NO | - | レコメンド種別 |
| target_label | varchar(255) | NO | - | 対象銘柄コード・銘柄名またはセクター名 |
| action_suggestion | varchar(255) | NO | - | 提案内容の一言 |
| reason_summary | varchar(255) | NO | - | 背景理由の一言サマリ。判定の主要因となった代表指標1〜2件を具体的な値とともに含める（例:「含み益+38%・RSI71（過熱）が中心的根拠」） |
| link_to | varchar(50) | NO | - | 裏付け確認先の画面識別子（例: `UC-003`, `UC-005`, `UC-006`） |
| composite_score | decimal(10,4) | NO | - | 合成スコア（詳細な計算式・重み付けは非開示だが、順位再現性のためDBには保存する） |

**Index**: `(import_summary_report_id, rank)` unique
**FK**: `import_summary_report_id` → `import_summary_reports(id)`

> 合成スコアリングの詳細な計算式・重み付けは`requirements.md` 6章の設計方針（ADR-0003）に基づきユーザーに開示しない。ただし判定結果そのものは非開示にせず、`reason_summary`（および`import_summary_reports.portfolio_headline`）には主要因となった代表指標を含める。内部実装（`app/Services/Analysis/`）は`composite_score`の算出に用いた各指標の寄与度を保持し、`reason_summary`生成時に寄与度上位1〜2件を代表指標として抽出する（抽出ロジックの詳細はPhase 1実装時の`/tdd`サイクルで確定）。件数（10件/20件）・スコア計算の重み付けは**初期パラメータ値（叩き台）**であり、Phase 1実装時の`/tdd`サイクルで確定させる。

---

## 設計方針

- 主キーは `bigint` (auto increment)
- タイムスタンプは `timestamp`（文字コードは utf8mb4 を使用）
- 論理削除（`deleted_at`）は「ユーザーが能動的に無効化する」マスタ系テーブル（`watched_themes`）にのみ付与する。`holdings`/`snapshots`/`holding_snapshots`等の実績・履歴系テーブルは追記のみで論理削除を持たない
- 金額は `decimal(15, 2)`（float禁止）。比率・指標値は用途に応じて`decimal(5,2)`〜`decimal(10,4)`を使い分ける
- 外部キーには必ずインデックスを作成
- テーブルは「現在値キャッシュ（UPSERT、履歴を持たない）」と「履歴ログ（追記のみ、UPDATE/DELETEしない）」の2種類に分けて設計する
  - 現在値キャッシュ: `technical_indicators`/`fundamental_indicators`（`holding_id`単位で1行、値が変わってもINSERTせずUPDATEする。J-Quantsの更新頻度〔最大12週遅延〕に対して毎週INSERTすると同一内容の行が積み上がるため）
  - 履歴ログ: `holding_snapshots`（`ma20`/`ma75`含む）/`financial_statements`（決算期単位）/`signals`/`market_indicator_snapshots`は、時点ごとに値そのものが変わりうる真の時系列データのため追記のみとする（UC-001業務ルール「既存スナップショットは上書きせず、履歴として蓄積する」）

## 保留・確定が必要な初期パラメータ値（Gate 3承認時点の状態）

| 項目 | 初期値（叩き台） | 該当UC | Gate3時点の状態 |
|---|---|---|---|
| 分割指値の閾値・比率 | +20%地点で1/3、+35%地点で1/3、残りはトレンド追従 | UC-004 | 叩き台のまま承認 |
| セクター配分の判定閾値 | 40%未満=健全、40〜70%=やや偏り、70%以上=偏り警告 | UC-005 | **確定済み**（2026-08-23、`SectorAllocationCalculator`Gate4でそのまま採用） |
| 目標配分率 | 70%（偏り警告閾値と同一） | UC-005 | **確定済み**（2026-08-23、`SectorAllocationCalculator`Gate4でそのまま採用。`suggested_sell_amount`＝`(現在配分率-70)/100×保有評価額合計〔全体〕`） |
| 売却株数の按分方法（`suggested_sell_quantity`） | セクター内の課税口座（specific/general）保有銘柄の加重平均`current_price`で`suggested_sell_amount`を除算 | UC-005 | **確定済み**（2026-08-23、`SectorAllocationCalculator`Gate4） |
| 財務健全性フィルタ | 自己資本比率40%以上・ROE10%以上 | UC-005/UC-008 | **確定済み**（UC-008分2026-08-23`NewCandidateFinder`Gate4、UC-005分2026-08-23`SectorAllocationCalculator`Gate4で同一値をそのまま流用） |
| 小口購入額の目安率（`suggested_amount`） | 保有評価額合計の1%（投資信託は`quantity×current_price÷10000`で単位補正して合算） | UC-008 | **確定済み**（2026-08-23、`NewCandidateFinder`Gate4） |
| NISA推奨（`nisa_recommended`）の追加基準 | 財務健全性フィルタの基準に加え、自己資本比率50%以上・ROE15%以上 | UC-005/UC-008 | **確定済み**（UC-008分2026-08-23`NewCandidateFinder`Gate4。UC-005分は`rebalance_candidates`が`NewCandidateFinder`をそのまま流用するため同一基準、2026-08-23`SectorAllocationCalculator`Gate4） |
| 過去業績推移の取得期数 | 5期 | UC-006 | 叩き台のまま承認 |
| サマリーレポートの件数区分 | 主要10件・補足10件（計20件） | UC-009 | 叩き台のまま承認 |
| 合成スコアの重み付け | 利確検討・リバランス・新規投資候補の3種を横断する優先順位ロジック（未確定） | UC-009 | 叩き台のまま承認。Phase 1実装時に`/tdd`サイクルで確定 |
| ~~セクター分類の粒度（17業種 or 33業種）~~ | **確定済み: 17業種**（2026-08-15、J-Quants `Sector17Code`を使用） | UC-002/005 | 確定済み |
| 52週高値からの反落閾値 | -10%以上下落で`week52_high_pullback`検出 | UC-004 | **確定済み**（2026-08-22、`SignalDeterminationService`Gate4でそのまま採用） |
| PEGレシオの割高判定閾値 | 2.0以上で`peg_overvalued`検出 | UC-004 | **確定済み**（2026-08-22、`SignalDeterminationService`Gate4でそのまま採用） |
| 相対力の算出期間・低下判定基準 | 直近13週の騰落率差。~~直近4週でプラス→マイナス転換時に検出~~→**確定: 現在の相対力（対市場）が0未満で`relative_strength_weakening`検出**（単時点の閾値判定に簡略化。トレンド判定には過去時点のベンチマーク騰落率が別途必要になり複雑化するため） | UC-003/004 | **確定済み**（2026-08-22、`SignalDeterminationService`Gate4） |
| 出来高急増の判定閾値 | 20週平均比1.5倍以上、かつ株価が前週比下落で`volume_spike_decline`検出 | UC-004 | **確定済み**（2026-08-22、`SignalDeterminationService`Gate4でそのまま採用） |

## 分析ロジックの計算仕様（`TechnicalIndicatorCalculator`、Gate4確定・2026-08-21）

`technical_indicators`の各カラムを算出する`app/Services/Analysis/TechnicalIndicatorCalculator::calculate()`の計算式・データ不足時の扱いを記録する（ADR-0004、`tests/Unit/Services/Analysis/TechnicalIndicatorCalculatorTest.php`のGate4承認内容と同期）。入力は週次の価格系列（`date`/`close`/`volume`、時系列昇順）と、相対力算出用のベンチマーク13週騰落率（呼び出し側が算出して渡す。このクラス自身はベンチマークの騰落率を計算しない）。

| 指標 | 計算式 | 必要データ件数 | 不足時 |
|---|---|---|---|
| `rsi` | RSI(14週)。直近15件（14回の変化）の値上がり幅合計÷14＝平均上げ幅、値下がり幅合計÷14＝平均下げ幅（Wilderのスムージングは使わない単純移動平均ベース）。RS＝平均上げ幅÷平均下げ幅、RSI＝100−100/(1+RS)。平均下げ幅が0の場合はRSI＝100（0除算回避、慣例通り） | 15件 | `null` |
| `macd` | 12週EMA − 26週EMA | 26件 | `null` |
| `macd_signal` | MACD値の時系列（26件目以降、各時点で再計算したMACD値）に対する9週EMA | 35件 | `null`（`macd`自体は26件で算出されるため、26〜34件では`macd`のみ値を持ち`macd_signal`は`null`になりうる） |
| `ma20` / `ma75` | 直近20週/75週の終値の単純移動平均 | 20件 / 75件 | `null` |
| `bb_upper` / `bb_lower` | 直近20週終値の単純移動平均 ±（2 × 標本標準偏差、分散はn-1で除算） | 20件 | `null` |
| `volume` | 直近週（配列の最終要素）の出来高そのまま | 1件 | `null`（0件時） |
| `volume_ma20` | 直近20週の出来高の単純移動平均 | 20件 | `null` |
| `week52_high` / `week52_low` | 直近52週の終値の最大値/最小値 | 52件 | `null`（部分レンジでは算出しない） |
| `relative_strength_vs_market` / `relative_strength_vs_sector` | 直近13週の当該銘柄騰落率(%) − 対応するベンチマーク13週騰落率(%、引数`marketReturn13w`/`sectorReturn13w`)。銘柄騰落率＝(直近close − 13週前close) ÷ 13週前close × 100 | 14件（かつ対応するベンチマーク引数が非null） | `null`（データ不足またはベンチマーク引数が`null`の場合。市場・セクターは独立に判定するため、片方だけ算出されるケースもありうる） |

**EMA（`macd`/`macd_signal`共通）**: 最初の`period`件の単純移動平均でシードし、以降を平滑化定数 α=2/(period+1) で `EMA_t = α×値_t + (1-α)×EMA_{t-1}` として再帰計算する。

> 上記の計算式・データ件数閾値はGate4（テストケース承認、2026-08-21）で確定済み。変更する場合は`tests/Unit/Services/Analysis/TechnicalIndicatorCalculatorTest.php`の該当テストとあわせて改訂すること。

## 承認記録

| 日付 | レビュアー | 結果 | コメント |
|---|---|---|---|
| 2026-08-15 | minowaryo | 承認 | テーブル構成・カラム設計を承認。「財務健全性フィルタ」「合成スコアの重み付け」の2項目は叩き台の値のまま承認し、Phase 1実装（`/tdd`サイクル）時に確定する方針とした。他の初期パラメータ値（分割指値閾値・セクター配分閾値・目標配分率・業績推移取得期数・レポート件数区分）も同様に叩き台のまま承認。セクター分類の粒度（17業種）は本承認に先立ち確定済み |

## 変更履歴

| 日付 | 変更内容 | ADR |
|---|---|---|
| 2026-08-15 | 初版ドラフト作成（UC-001〜UC-009、Gate 2承認済みuse-cases.mdに基づく）。Gate 3レビュー待ち | - |
| 2026-08-15 | レビュー指摘を反映し改訂: ①`technical_indicators`/`fundamental_indicators`を`holding_snapshot_id`単位から`holding_id`単位の現在値キャッシュ（UPSERT）に変更し、候補銘柄（UC-006/008/009、未保有）でも指標を持てるようにした（保有銘柄のチャート用週次履歴は`holding_snapshots.ma20`/`ma75`に分離）。②`holdings`をCSV取込専用から「保有・候補問わない銘柄マスタ」に位置づけ変更（find-or-create）。③`watch_records`を`symbol_code`/`market`直持ちから`holding_id`FK参照に統一。④`signals`に`(holding_snapshot_id, signal_type)`のunique制約を追加（重複行防止）。⑤`watched_themes`の`deleted_at`（未定義の削除機能）を削除し、MySQLのNULL非同一性によるunique制約の不備を解消 | - |
| 2026-08-15 | **Gate 3承認**。承認記録を追加。財務健全性フィルタ・合成スコアの重み付けの2項目は叩き台のままPhase 1実装時に確定する方針を明記 | - |
| 2026-08-16 | UC-001 Gate4 Greenフェーズ実装に伴い`holdings.symbol_code`を`varchar(20)`→`varchar(255)`に拡張。投資信託の`symbol_code`はファンド名そのものを格納する仕様（UC-001業務ルール）のため20桁では収まらないことが実装時に判明した | - |
| 2026-08-16 | UC-002 Gate4 Greenフェーズ実装に伴い`sector_classifications`/`technical_indicators`/`fundamental_indicators`/`signals`をドラフト通りのカラム構成で実装。`holdings.sector_classification_id`は`sector_classifications`不在のため見送っていたFK制約を後続マイグレーションで追加（既存の`holdings`マイグレーションは編集せず、`.claude/rules/20-mysql.md`の「実行済みマイグレーションは編集しない」に従い後方互換を維持） | - |
| 2026-08-16 | NISA区分を含む口座区分の内訳を`holding_snapshot_accounts`テーブルに追記保存する方針に変更（Gate 3承認済みだった「口座区分を保持しない」方針〔前提セクション旧記述〕を覆すCR）。既存の`holdings`/`holding_snapshots`のカラムは変更しない。UC-004/UC-005/UC-008でNISA区分の除外・推奨判定に使用する | ADR-0002 |
| 2026-08-21 | `import_summary_reports.portfolio_headline`・`import_summary_report_items.reason_summary`の説明を改訂（Gate 3承認済みだった「合成スコアの算出根拠は非開示」という方針を部分的に緩和するCR）。詳細な計算式・重み付けは引き続き非開示のままとしつつ、判定の主要因となった代表指標を出力に含める方針に変更。テーブル構造・カラム追加は不要（既存の`varchar`カラムの文字列内で表現） | ADR-0003（CHG-0002） |
| 2026-08-21 | `holding_snapshot_accounts`テーブルをドラフト通りのカラム構成で実装（`2026_08_21_000000_create_holding_snapshot_accounts_table.php`）。ADR-0002決定時点では未着手だったマイグレーション・`HoldingSnapshotAccount`モデル（`holdingSnapshot()`リレーション）・`HoldingSnapshot::accounts()`逆リレーションを追加。CSVパーサ（`JpStockCsvParser`/`UsStockCsvParser`/`MutualFundCsvParser`）・`ImportCsvAction`側での口座区分別内訳の書き込みロジックはまだ未実装（別途Gate4 TDDサイクルで対応） | ADR-0002 |
| 2026-08-21 | 分析エンジンの指標セットを拡張（CHG-0003）。`technical_indicators`に`volume`/`volume_ma20`/`week52_high`/`week52_low`/`relative_strength_vs_market`/`relative_strength_vs_sector`、`fundamental_indicators`に`eps_growth`/`peg_ratio`、`financial_statements`に`eps`を追加。`signals.signal_type`のENUMに4種追加（危険な操作に該当、上記注記参照）。`market_indicator_snapshots`（`nikkei225`/`sp500`分のみ）をUC-007に先行してPhase1で実装対象に追加。`technical_indicators`/`fundamental_indicators`/`signals`は既存マイグレーション実行済みのため、本変更は新規ALTER TABLEマイグレーションで対応する（既存マイグレーションファイルは編集しない） | ADR-0004 |
| 2026-08-21 | `app/Services/Analysis/TechnicalIndicatorCalculator`（TDD Red-Green完了）に伴い「分析ロジックの計算仕様」節を追加。`technical_indicators`の各カラムの算出式・必要データ件数・不足時のnull扱いをGate4確定内容として記録 | ADR-0004 |
| 2026-08-22 | `app/Actions/Analysis/FetchExternalMarketDataAction`実装に伴い、CHG-0003で設計した`technical_indicators`/`fundamental_indicators`の列追加・`signals.signal_type`のENUM拡張・`market_indicator_snapshots`テーブルを実際にマイグレーション化（`2026_08_22_000000`〜`000003`）。`market_indicator_snapshots.ma_deviation`の移動平均期間を26週に確定（MACD低速EMA期間と揃えた） | ADR-0004 |
| 2026-08-21 | UC-009 Gate4 Greenフェーズ実装に伴い`watched_themes`/`import_summary_report_items`をドラフト通りのカラム構成で実装。Gate4承認によりPhase1スコープではNISA区分除外（ADR-0002）を対象外とし、全保有数量ベースで優先順位を算出する方針とした（`holding_snapshot_accounts`との連携は別途UC-004/005/008実装時に対応）。新規投資候補の注目テーマ合致判定は`watched_themes.name`と`sector_classifications.name`の完全一致、財務健全性フィルタ・件数区分（上位10件/補足10件）は本ファイルの叩き台の値をそのまま採用 | - |
| 2026-08-22 | 実際のユーザーCSV（134銘柄）をUC-001経由でインポートした際、ほぼゼロ近辺からの回復銘柄でEPS成長率が1136%に達し`fundamental_indicators.eps_growth`（`decimal(7,4)`、最大±999.9999%）がMySQLの`Out of range`エラーになる実バグが判明。`eps_growth`/`revenue_growth`/`operating_income_growth`を`decimal(10,4)`に拡張するマイグレーションを追加（既存マイグレーションは編集せず、`change()`による新規ALTER TABLE）。あわせて`FetchExternalMarketDataAction`のper-holding例外分離が2つ目のループ・`fetchSectorInfo()`呼び出しに及んでいなかった非対称バグも修正（1銘柄の失敗が同一バッチ内の他銘柄の処理を巻き込んで中断させていた） | ADR-0006 |
| 2026-08-23 | `holding_snapshot_accounts`（口座区分別内訳、ADR-0002）の書き込み経路（CSVパーサー3本・`ImportCsvAction`）と消費側（UC-004 `ShowSignalListAction`）を実装完了。書き込み: `AccountTypeMapper`（新規、ラベル→enum変換）を追加し、JP/US株パーサーは`■`見出し行のラベルを、投資信託パーサーは`口座区分`列を読み取って`holding_snapshot_accounts`に口座区分ごとの内訳を保存する。消費: `ShowSignalListAction`の`split_limit_suggestion`は課税口座（specific/general）分の数量のみを基準に算出し、全額NISA（内訳が`nisa_growth`/`nisa_tsumitate`のみ）の銘柄は一覧から除外する。価格帯（+20%/+35%）は変更せず全体の`average_cost`基準のまま。内訳が1件も無い銘柄（後方互換）は保有数量全体を課税口座扱いとしてフォールバックする。テーブル・モデル・リレーションは2026-08-21時点で既存のため変更なし | ADR-0002 |
| 2026-08-23 | Phase2着手（UC-008 Cycle2）。`NewCandidateFinder`サービス実装に伴い、「保留・確定が必要な初期パラメータ値」表の財務健全性フィルタ・NISA推奨追加基準のUC-008分を確定（自己資本比率40%/ROE10%以上、NISA推奨は自己資本比率50%/ROE15%以上）。小口購入額の目安率（保有評価額合計の1%）を新規に確定・追記。保有評価額合計の算出には投資信託の基準価額単位補正（`quantity×current_price÷10000`）が必要であることを実データで確認し明記した | - |
| 2026-08-23 | Phase2 Cycle3（UC-005セクター配分ダッシュボード）実装完了。「保留・確定が必要な初期パラメータ値」表のセクター配分判定閾値（40%/70%）・目標配分率（70%）を確定し、`suggested_sell_amount`の算出式（(現在配分率-70)/100×保有評価額合計）を明記。売却株数の按分方法（セクター内課税口座保有銘柄の加重平均現在値で除算）を新規に確定・追記。財務健全性フィルタ・NISA推奨基準のUC-005分もUC-008と同一値で確定（`rebalance_candidates`が`NewCandidateFinder`をそのまま流用するため）。セクター集計はUC-008/UC-009と異なり全instrument_type（stock/etf/mutual_fund）を対象とする点を明記 | - |
| 2026-08-23 | Phase2 Cycle4（UC-006）のCycle A: `financial_statements`テーブルをドラフト通りのカラム構成で実装（`2026_08_23_000000_create_financial_statements_table.php`）。`FetchExternalMarketDataAction`を改修し、JP株について既に取得済みの`jQuantsClient->fetchStatements()`の5期分を新規の外部API呼び出しなしで`financial_statements`に保存するようにした。`revenue_yoy_change`/`operating_income_yoy_change`は最新期（index 0）のみ算出し、過去の期（index 1〜4）はフェッチ範囲外のためnullとする方針をGate4で確定 | - |
| 2026-08-23 | UC-006 Cycle Aの`/review`拡張レベル指摘（MEDIUM）を修正。`financial_statements.revenue`/`operating_income`をNOT NULLからnullableに変更（`2026_08_23_000001_nullable_revenue_operating_income_on_financial_statements_table.php`）。データソース（J-Quants `net_sales`/`operating_profit`）自体がnullを返しうるにもかかわらずNOT NULLだったため、該当銘柄で`financial_statements`のINSERT失敗が同一トランザクション内の`technical_indicators`/`fundamental_indicators`/`signals`更新まで巻き添えでロールバックさせていた | ADR-0008 |
