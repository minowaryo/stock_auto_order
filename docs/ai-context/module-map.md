# module-map.md — モジュール・ディレクトリ担当一覧

## Backend (Laravel)

| パス | 役割 | 注意 |
|---|---|---|
| `app/Http/Controllers/` | HTTPリクエスト受付・レスポンス返却 | Fat Controller禁止 |
| `app/Http/Requests/` | バリデーションルール | FormRequestを必ず使う |
| `app/Http/Middleware/` | 認証・認可・ロギング | グローバル適用は慎重に |
| `app/Services/` | ビジネスロジック | 1クラス1責務 |
| `app/Actions/` | 単一操作のアクション | `execute()` メソッドに集約 |
| `app/Models/` | Eloquentモデル・リレーション | ビジネスロジックを書かない |
| `app/Policies/` | 認可ルール | 必ずGate経由で呼ぶ |
| `app/Events/` | ドメインイベント | 過去形の名前 |
| `app/Listeners/` | イベントハンドラ | 重い処理はQueueに |
| `app/Jobs/` | 非同期ジョブ | Horizon経由で実行 |

### 本プロジェクト固有のドメイン層

| パス | 役割 | 注意 |
|---|---|---|
| `app/Services/Import/` | 楽天証券CSV（JP株/US株/投資信託）のパース、およびお気に入り銘柄CSV（`RakutenFavoriteCsvParser`、UC-012／ADR-0013）のパース | Shift-JISエンコード・カンマ区切りクォート付き数値に対応。お気に入りCSVはヘッダなし6列・CP932・CRLF |
| `app/Services/Watchlist/` ／ `app/Actions/Watchlist/` | お気に入り未保有銘柄ウォッチリスト（UC-012／F-012／ADR-0013）。お気に入りCSV取込（`ImportFavoriteCsvAction`）、未保有銘柄の指標一括更新（`RefreshWatchlistMarketDataAction`、既存の `MarketData` クライアント・`Analysis` の Mapper／`BuySignalDeterminationService` を流用）、候補一覧の組み立て（`ShowWatchlistAction`） | `signals`/`buy_signals` には書き込まない。買いシグナルは `watchlist_buy_signals`（`holding_id` キー）に保存 |
| `app/Jobs/RefreshWatchlistMarketDataJob` ／ `app/Console/Commands/`（`watchlist:refresh`） | ウォッチリスト一括更新の非同期実行と、その手動実行コマンド（`RefetchUsFundamentalsCommand` の先例に倣う） | 進捗は `watchlist_refresh_runs` に記録。`compose.yaml` の `queue:work` サービスで実行 |
| `app/Services/Analysis/` | テクニカル指標（RSI/MACD/BB/移動平均）・ファンダメンタルズ指標（PER/PBR/ROE等）の計算、利確シグナル判定（`SignalDeterminationService`）・買い増しシグナル判定（`BuySignalDeterminationService`）・ファンダメンタルズ健全性評価（`FundamentalHealthEvaluator`） | 閾値・パラメータの持たせ方は `docs/architecture/data-model.md`（Gate 3）で確定。売り側と買い側は別クラスに分離（ADR-0007） |
| `app/Actions/Portfolio/`（予定） | ポートフォリオ分類ダッシュボード（UC-013／F-013／ADR-0014）。`ShowHoldListAction`（CHG-0028）は `hold` バケツを売買シグナル画面のキープ表向けに並べ替えて返す参照専用Action。`ClassifyHoldingsAction` が既存の抽出Action（`ShowSignalListAction`／`ShowBuySignalListAction`／`ShowLossReviewListAction`／`SectorAllocationCalculator`）を束ね、直近スナップショットの各保有銘柄を優先順位で1つのバケツ（`core_accumulation`／`loss_review`／`take_profit`／`add_on`／`hold`）に割り当てる純ロジック。第1段階はDB書き込みなし（表示専用）。第2段階で分類結果を `portfolio_classifications` に週次永続化し「先週→今週の遷移」を出す | `signals`／`buy_signals`／`FetchExternalMarketDataAction` には書き込まない。新しい抽出条件・閾値は作らず既存Actionの出力を再投影する。画面はサマリーレポートタブ（`app/Livewire/ImportSummaryReport/`）に相乗り |
| `app/Actions/SignalOutcome/` ／ `app/Services/SignalOutcome/` ／ `app/Livewire/SignalOutcome/`（ADR-0017） | シグナル結果の前向き記録・集計（UC-014／F-014）。週次価格履歴（`weekly_prices`／`index_weekly_prices`）の保存、シグナル発生記録（`signal_occurrences`）の追記、発生後+4/+13/+26週の対市場超過リターン・統計・判定区分の算出（`ExcessReturnCalculator`／`OutcomeStatisticsCalculator`／`SignalOutcomeVerdictEvaluator`、DB非依存の純ロジック）、集計の組み立て（`ShowSignalOutcomesAction`、読み取り専用）、画面`/signal-outcomes`（`SignalOutcomes`）。売買シグナル画面との切替タブは`resources/views/components/signal-outcome-tabs.blade.php`、根拠値の日本語表示は`app/Support/SignalOccurrenceMetricLabels.php` | 判定ロジック（`SignalDeterminationService`／`BuySignalDeterminationService`）と既存の`signals`／`buy_signals`／`watchlist_buy_signals`の削除→再作成には触れない。書き込みは`FetchExternalMarketDataAction`／`RefreshWatchlistMarketDataAction`の既存書き込み直後への追記のみ。結果（超過リターン）は保存しない |
| `app/Services/Concentration/` ／ `app/Actions/Concentration/` ／ `app/Livewire/Concentration/`（ADR-0019） | 集中度ダッシュボード（UC-015／F-015）。`weekly_prices`／`index_weekly_prices`（sox）から直近52週の週次リターン行列を作り、相関・PC1寄与率・実効ベット数（Meucci、双対形の固有分解）・対SOXベータ・上位5銘柄ウェイトを算出する純ロジックと、画面用の組み立て（`ShowConcentrationDashboardAction`） | 値は保存せず読み取り時に算出。数学ライブラリは使わずJacobi法を自前実装。判定・合成スコアは作らない。DBへの書き込みなし |
| `app/Services/MarketData/` | J-Quants API・Yahoo Finance相当の外部データ取得クライアント（個別銘柄の株価・指標に加え、日経平均・S&P500・米国10年債利回り・VIX指数・USD/JPY為替レート等の市場全体指標も取得する） | APIキー等は `docs/ai-context/do-not-touch.md` の外部連携セクション参照 |

## Frontend（選定結果: **Livewire**。`docs/adr/ADR-0001-frontend-stack-selection.md` 参照）

| パス | 役割 | 注意 |
|---|---|---|
| `app/Livewire/` | Livewireコンポーネントクラス（PHP） | ビジネスロジックを書かず `app/Services/` に委譲する |
| `resources/views/livewire/` | Livewireコンポーネントに対応するBladeビュー | kebab-case命名 |
| `resources/views/components/` | 汎用・共通のBladeコンポーネント | Livewireに依存しない表示部品 |

詳細な実装ルールは `.claude/rules/15-frontend.md` を参照。

## Database

| パス | 役割 |
|---|---|
| `database/migrations/` | スキーマ変更履歴（変更禁止） |
| `database/factories/` | テスト用データ生成 |
| `database/seeders/` | 初期データ投入 |

## Tests

| パス | 役割 |
|---|---|
| `tests/Feature/` | 統合テスト（最優先） |
| `tests/Unit/` | 単体テスト（ビジネスロジック） |

## Docs

| パス | 役割 | 更新者 |
|---|---|---|
| `docs/ai-context/` | AI向け要約（短く正確に） | 開発者 |
| `docs/product/` | 要件・ユースケース | ビジネス側 |
| `docs/architecture/` | システム設計 | 開発者 |
| `docs/adr/` | 意思決定記録 | 開発者 |
| `docs/development/` | 開発プロセス | 開発者 |
| `docs/security/` | セキュリティポリシー | セキュリティ担当 |

## 触ってはいけない領域

`docs/ai-context/do-not-touch.md` を参照。
