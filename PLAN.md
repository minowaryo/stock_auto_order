# PLAN.md

> 2026-08-23以前（Gate0セットアップ〜Phase1 Gate4サイクル完了・ADR-0002 NISA区分CR・ADR-0004分析エンジン実装〔設計確定〜各TDDサイクル、UC-001配線・UC-004画面・UC-003/UC-009新指標反映を含む〕完了・関連review指摘修正2件・UC-009サンプルレポート生成、F-010（UC-010）Gate1〜3ドキュメント叩き台整備完了、NISA区分内訳の書き込み・UC-004消費完了、未知の口座区分ラベルの扱いに関する`/review`指摘修正、およびPhase2 UC-008（Cycle1・Cycle2）完了等）の完了済みエントリは `docs/history/plan-archive.md` に退避済み。
> **運用ルール**: PLAN.mdは300行を超えないよう保つ。300行に近づいたら、Statusが「完了」相当（Green確認完了・マージ済み等）の最も古いエントリから`docs/history/plan-archive.md`へ退避し、本ファイル冒頭のこの注記を更新する（詳細は `.claude/rules/60-docs.md` 参照）。

## UC-010（既存保有株の買い増しタイミングレコメンド）Gate4完了・コミット（2026-08-27）

### Decision

- `test-writer`がRedフェーズで4ファイル45件を作成（`BuySignalDeterminationServiceTest`18件・`FundamentalHealthEvaluatorTest`8件・`UC010BuySignalListTest`15件・`FetchExternalMarketDataActionBuySignalTest`4件）。全て意図通りクラス未検出／ルート未定義／テーブル未検出で失敗することを確認しGate4承認
- `tdd-implementer`がGreenフェーズを実装: `buy_signals`マイグレーション、`BuySignal`モデル、`BuySignalDeterminationService`（7シグナル＋前提条件A/B）、`FundamentalHealthEvaluator`、`ShowBuySignalListAction`＋`BuySignalListController`（`GET /api/buy-signals`）、`FetchExternalMarketDataAction`への買いシグナル永続化組み込み（含み益率ゲートの外側で全銘柄対象、売り側`signals`ロジックは無改修）。対象45件・フルスイート316件Green
- `/review`で指摘2件: (1) `FundamentalHealthEvaluator`が業務ルールの「成長率」条件を欠いている、(2) `NewCandidateFinder`と`portfolioEvaluationTotal()`が重複。(1)はユーザーと協議の結果、追加実装を選択（use-cases.md/ADR-0007 D4の「UC-008/009と同一値」を字面通り2条件と誤解していたことが判明）。小さなCRとしてRed→Gate4→Green（`evaluate()`を4引数化、成長率が両方null→unavailable、いずれかプラスでpassed）を実施、既存13+新規5件のUnitテスト・Feature側1件のアサーション追加、全件Green
- (2)はRefactorとして対応: `app/Services/Portfolio/PortfolioEvaluationCalculator`を新設し`NewCandidateFinder`・`ShowBuySignalListAction`から重複コードを除去（DI経由）。`SectorAllocationCalculator`にも同型の3つ目の重複があることを発見したが、今サイクルのスコープ外として現状維持（将来の統合候補として記録）
- **並行セッションとの衝突が3回発生**: 別セッション（フロントエンドPhase3/4担当）が`routes/web.php`を編集するたびに、私の未コミットの`/buy-signals`ルート追加が巻き込まれて消失した。原因はコミット`166adce`で判明: 相手セッションが`/review`前に`git add routes/web.php`した際、他セッションの未コミット差分がファイル全体越しに混入するのを検知し、意図的に2行だけ除去していた（悪意・事故ではなく正しいgit衛生上の判断）。根本原因は「複数セッションが同一の未コミット作業ツリーを共有している」構造にあるため、都度復元するのではなく、UC-010の全作業をこのタイミングでコミットして解消した
- 以前から未コミットのまま残っていたF-010 Gate1〜3ドキュメント叩き台（`docs/history/plan-archive.md`参照）も含め、Gate0〜4の全成果物を1コミット（`ba239fe`）にまとめてコミット済み（`git push`は未実施、ユーザーの明示的指示があるまで行わない）

### Files touched

`app/Models/BuySignal.php`（新規）、`app/Services/Analysis/BuySignalDeterminationService.php`（新規）、`app/Services/Analysis/FundamentalHealthEvaluator.php`（新規）、`app/Services/Portfolio/PortfolioEvaluationCalculator.php`（新規）、`app/Actions/Signal/ShowBuySignalListAction.php`（新規）、`app/Http/Controllers/BuySignalListController.php`（新規）、`database/migrations/2026_08_25_000000_create_buy_signals_table.php`（新規）、`app/Actions/Analysis/FetchExternalMarketDataAction.php`（買いシグナル永続化組み込み）、`app/Models/HoldingSnapshot.php`（`buySignals()`リレーション追加）、`app/Services/Candidate/NewCandidateFinder.php`（重複除去）、`routes/web.php`（`/api/buy-signals`追加）、`tests/Feature/FetchExternalMarketDataActionBuySignalTest.php`・`tests/Feature/UC010BuySignalListTest.php`・`tests/Unit/Services/Analysis/BuySignalDeterminationServiceTest.php`・`tests/Unit/Services/Analysis/FundamentalHealthEvaluatorTest.php`（新規）、`PLAN.md`（本エントリ追加）

### Status

Gate4完了（Red→Gate4承認→Green→成長率CR→Refactor）。フルスイートGreen（並行セッションの別画面の作業中ファイル・一時的なテストDB競合を除く）。コミット済み（`ba239fe`、未push）。UC-010のフロントエンド（Livewire画面統合）は別セッション・別スコープのため対象外

## UC-010（既存保有株の買い増しタイミングレコメンド）Gate2/Gate3正式承認（2026-08-23）

### Decision

- 別セッションでフロントエンド実装（Phase0〜）が進行中の一方、UC-010はGate1〜2叩き台のみで正式承認が未取得だった（`docs/history/plan-archive.md`のADR-0007エントリ参照）ため、バックエンドAPI実装（Gate4 TDDサイクル）に着手する前提としてGate2（`use-cases.md`）・Gate3（`data-model.md`）の正式承認をこの場で実施
- 承認レビューでユーザーから本機能の意図が確認された: 「健全で好調だった銘柄が、市場全体・セクター全体の調整で一時的に下げた場面」を拾う設計であり、長期低迷銘柄や個別要因で下落している銘柄を拾う設計ではない。当初のD2で定めた7シグナル種別（UC-004と面対称の汎用的な「売られすぎ・反発」指標のみ）はこの意図を担保する要素（直前の好調さ・連れ安の確認）を欠いていたため、承認前に設計を修正した
- 修正内容: 7シグナル共通の前提条件として(1)直近13週以内に`week52_high`の-15%以内に到達していたこと、(2)`relative_strength_vs_market`が-5pt以上であること、の2点を追加（`use-cases.md` UC-010業務ルール、`data-model.md`の`buy_signals`節・初期パラメータ表、`ADR-0007`のAddendumに反映）。いずれも既存の算出済みデータで実現でき追加の外部データ取得は発生しない
- 7シグナル種別自体・`buy_signals`テーブル分離方針・ファンダメンタルズフィルタ・NISA方針は変更なし。数値パラメータは他UC同様、叩き台のままGate4実装時に`/tdd`サイクルで確定する方針
- 次はGate4（バックエンドAPI実装、`BuySignalDeterminationService`・`FundamentalHealthEvaluator`・`buy_signals`マイグレーション等）のRedフェーズに着手する

### Files touched

`docs/product/use-cases.md`（UC-010業務ルールに前提条件2点追加、承認記録追記）、`docs/architecture/data-model.md`（`buy_signals`節・初期パラメータ表に前提条件追加、承認記録追記）、`docs/adr/ADR-0007-existing-holding-add-on-buy-recommendation.md`（Addendum追加）、`PLAN.md`（本エントリ追加）

### Status

Gate2・Gate3正式承認完了。次はGate4（TDD Red→Green→Refactor）でバックエンドAPI実装に着手する。

## フロントエンド実装Phase4（UC-003銘柄詳細画面）完了（2026-08-26）

### Decision

- Phase3に続き、Phase4（UC-003銘柄詳細画面、`GET /holdings/{holding}`。Phase3の一覧行が既にこのパスへリンクしていた先）を実施
- `test-writer`が14件のLivewireコンポーネントテストを作成。Gate4で2点確認: (1) 手書きSVGチャートのテスト可能性確保のため`price_history`の各データ点に`data-testid="price-chart-point"`マーカーを付与する（視覚的なpolylineとは別のテスト用要素）、(2) `ShowHoldingDetailAction`は現在値を返さないため、`price_history`最新値のclose_priceを「現在値」として表示する — いずれも「推奨」で承認
- `tdd-implementer`がGreenフェーズを実装: `app/Livewire/Holding/HoldingDetail.php`（`ShowHoldingDetailAction`を`render()`で毎回呼び出す純粋読み取り設計、`SaveHoldingMemoAction`によるメモ追記保存）。ADR-0004分の指標（出来高・52週高値安値・相対力・EPS成長率・PEGレシオ）も含め全指標を表示（モックアップはこれらの項目追加前の古い版のため参照せず、Actionの実際のレスポンス形状を正とした）。対象14件・フルスイート336件全てGreen
- **並行セッション対応**: `routes/web.php`が別セッションの未コミット`/buy-signals`ルートと混在した状態だったため、PLAN.mdと同じ安全な退避・再適用手順（HEAD復元→自分の追加分のみ適用→差分確認→コミット→退避内容を復元→再適用）を今回から`routes/web.php`にも適用し、Phase3で発生したような汚染を防止した
- 実ブラウザ確認（Playwright MCP、実データ）: `/holdings/2`（トヨタ自動車）で実際のテクニカル/ファンダメンタルズ指標が正しく表示され、EPS成長率がマイナスのためPEGレシオが正しく「取得不可」になること、利確シグナル判定（「52週高値3,825から3,132まで下落しました」）が実データに基づき表示されること、メモ保存が実際に永続化され画面に反映されることを確認（検証用に作成したテストメモは確認後に削除済み）

### Files touched

`app/Livewire/Holding/HoldingDetail.php`（新規）、`resources/views/livewire/holding/holding-detail.blade.php`（新規）、`routes/web.php`（`/holdings/{holding}`ルート追加）、`tests/Feature/HoldingDetailTest.php`（新規、14件）、`PLAN.md`（本エントリ追加）

### Status

Green確認・実ブラウザ動作確認完了（実データ）。フルスイート336件Green。次はPhase5（UC-004売買シグナル一覧画面、利確検討セクションのみ。UC-010買い増し候補セクションは別セッションのマージ完了後に追加）に進む。

## Phase3の`/review`拡張レベルを実施、コミット汚染とビュー内クエリを修正（2026-08-25）

### Decision

- push前にユーザーから`/review`の依頼を受け、コミット`b493a18`（Phase3、origin/main比7ファイル・673行・review-score約41≧閾値30）に対し拡張レベルのレビューを実施
- **重大な指摘**: `routes/web.php`は`PLAN.md`/`data-model.md`と異なり安全な退避・再適用手順（他セッションの未コミット変更を巻き込まないための手順）を踏まずに`git add`していたため、別セッションが並行して作業中の未コミットF-010（UC-010買い増しレコメンド）由来の`BuySignalListController`インポート・`/buy-signals`ルート登録がコミット`b493a18`に紛れ込んでいたことが判明した。当該コントローラ実体ファイルは未コミットのままディスク上に存在するため、`b493a18`単体をpull/参照した場合に存在しないクラスを参照する不整合なコミットになっていた
- **軽微な指摘**: セクターフィルタのプルダウン用データ取得（`SectorClassification::query()`）がコンポーネントではなくBladeビュー内で直接実行されており、他の全画面（`render()`/`mount()`でデータ取得しビューへ渡す設計）と一貫していなかった
- 両指摘を修正: `routes/web.php`から該当2行（インポート・ルート登録）を削除し汚染を解消（コントローラファイル自体は他セッションの作業として触れず維持）。セクター取得ロジックを`HoldingList::render()`に移動しビューへ`sectorOptions`として渡すよう変更。フルスイート302件Green（他セッション進行中のUC-010関連15件失敗は本修正と無関係、リグレッションでないことを確認）

### Files touched

`routes/web.php`（汚染除去）、`app/Livewire/Holding/HoldingList.php`・`resources/views/livewire/holding/holding-list.blade.php`（セクター取得ロジックのコンポーネント移動）、`PLAN.md`（本エントリ追加）

### Status

Green確認完了。フルスイート302件Green。今後、コード（Blade/ルート等）を含む共有ファイルへのコミット前は`git diff origin/main..HEAD`等でPLAN.md/data-model.md以外の共有ファイルにも他セッションの混入が無いか確認する運用とする。次はPhase4（UC-003銘柄詳細画面）に進む。

## フロントエンド実装Phase3（UC-002保有銘柄一覧画面＋UC-007ウィジェット）完了、共通レイアウトの重大バグ修正（2026-08-25）

### Decision

- Phase1+2に続き、Phase3（UC-002保有銘柄一覧画面、UC-007市場全体指標ウィジェットを内包）を実施
- `test-writer`が12件のLivewireコンポーネントテストを作成。Gate4で2点確認: (1) セクターフィルタのプルダウンに「未分類」を選択肢として手動追加（`ListHoldingsAction`は文字列一致でフィルタするだけなので追加ロジック不要）、(2) NEWバッジ・一覧行のリンク先（`/candidate-check`・`/holdings/{id}`）はまだ実装されていないPhase4/7の画面を先行して参照する（その間は404、Phase1+2と同じ進め方）— いずれも「妥当」で承認
- `tdd-implementer`がGreenフェーズを実装: `app/Livewire/Holding/HoldingList.php`（`ListHoldingsAction`・`ShowMarketIndicatorAction`を`render()`で毎回呼び出す純粋読み取り設計）。対象12件・フルスイート271件Green（他セッション進行中のUC-010関連の失敗は無関係と確認済み）
- **実ブラウザ確認（Playwright MCP）で重大バグを発見・修正**: 共通レイアウト（`resources/views/components/layouts/app.blade.php`、Phase0で作成）に`<meta name="csrf-token">`が欠落しており、Livewireの`wire:submit`/`wire:model.live`等のAJAX通信が実ブラウザでは無反応になっていた。`Livewire::test()`はブラウザのJS/AJAX層を経由しないため、Phase0のログイン機能を含めこれまでの全Feature Testでは検出できていなかった不具合。CSRFメタタグを追加し修正、回帰防止テスト（`tests/Feature/LayoutTest.php`）を追加し、実ブラウザでログイン→ログアウトの往復が正常に機能することを確認した
- Phase3自体は実データ（134銘柄超）で市場全体指標ウィジェット（日経平均・S&P500は実値、残り3指標は「取得不可」表示）・セクターフィルタ（未分類含む）・一覧表示が正しく動作することをPlaywrightで確認

### Files touched

`app/Livewire/Holding/HoldingList.php`（新規）、`resources/views/livewire/holding/holding-list.blade.php`（新規）、`routes/web.php`（`/holdings`ルート追加）、`tests/Feature/HoldingListTest.php`（新規、12件）、`resources/views/components/layouts/app.blade.php`（csrf-tokenメタタグ追加、バグ修正）、`tests/Feature/LayoutTest.php`（新規、回帰防止テスト1件）、`PLAN.md`（本エントリ追加）

### Status

Green確認・実ブラウザ動作確認完了（実データ）。フルスイート284件（271+13、他セッションのUC-010関連を除く）Green。CSRFバグ修正によりログイン画面（Phase0）を含む全Livewire画面のAJAX通信が実ブラウザで正しく機能するようになった。次はPhase4（UC-003銘柄詳細画面）に進む。

## フロントエンド実装Phase1+2（CSV取込画面・サマリーレポート画面）完了（2026-08-23）

### Decision

- Phase0に続き、Phase1（UC-001 CSV取込画面）とPhase2（UC-009サマリーレポート画面）を1サイクルとして実施。理由: Phase1の取込成功時フローがPhase2の画面へ直接リダイレクトするため、別々に作ると存在しないルートへのリダイレクトが残ってしまう
- `test-writer`が2画面分のLivewireコンポーネントテスト20件を作成。Gate4で2点確認: (1) サマリーレポート画面の行リンクは暫定的に`/holdings?symbol_code=...`（利確検討・新規投資候補）・`/sector-dashboard`（リバランス）とし、Phase3/4実装後に正式な`/holdings/{id}`リンクへ置き換える、(2) `ShowImportSummaryReportAction`に`symbol_code`フィールドを追加（利確検討・新規投資候補のみ、リバランスは対象外）— いずれも「妥当」で承認
- `tdd-implementer`がGreenフェーズを実装: `app/Livewire/CsvImport/Upload.php`（`WithFileUploads`、`StoreCsvImportRequest`と同一のバリデーション、`ImportCsvAction`を直接呼び出し成功時はサマリーレポート画面へリダイレクト、取込履歴一覧表示）、`app/Livewire/ImportSummaryReport/Show.php`（`mount()`で`ShowImportSummaryReportAction`を1回だけ呼び出し、`render()`では再呼び出ししない副作用安全設計）。`symbol_code`フィールドはAPIレスポンス（`toResponseItem()`）のみに追加し、`import_summary_report_items`テーブルへの永続化は対象外（DBスキーマ変更なし）。対象20件・フルスイート259件全てGreen
- 実ブラウザ確認（Playwright MCP）: `/csv-import`で実際の取込履歴（134銘柄・実ファイル名）が正しく表示されること、`/import-batches/15/summary-report`で実データに基づく利確検討候補20件（マイクロン テクノロジー含み益+555%等、実際の保有銘柄）が正しくランキング表示され、`symbol_code`ベースの暫定リンク（`/holdings?symbol_code=MU`等）が正しく生成されることを確認。コンソールエラーなし

### Files touched

`app/Livewire/CsvImport/Upload.php`（新規）、`resources/views/livewire/csv-import/upload.blade.php`（新規）、`app/Livewire/ImportSummaryReport/Show.php`（新規）、`resources/views/livewire/import-summary-report/show.blade.php`（新規）、`app/Actions/ImportSummaryReport/ShowImportSummaryReportAction.php`（`symbol_code`フィールド追加）、`routes/web.php`（`/csv-import`・`/import-batches/{importBatch}/summary-report`ルート追加）、`tests/Feature/CsvImportUploadTest.php`（新規、14件）、`tests/Feature/ImportSummaryReportShowTest.php`（新規、6件）、`PLAN.md`（本エントリ追加）

### Status

Green確認・実ブラウザ動作確認完了（実データ）。フルスイート259件Green。次はPhase3（UC-002保有銘柄一覧画面＋UC-007市場全体指標ウィジェット）に進む。

## フロントエンド実装Phase0（基盤整備）完了（2026-08-23）

### Decision

- Phase2完了後、ユーザーから「CSVを投入してレポートを見て、その裏付けを画面で取れる状態か」と問われ、UC-001〜009は全てAPIバックエンドのみでUI（Livewireコンポーネント・Bladeビュー）が0件であることを確認。Planモードでフロントエンド実装計画（`stock_auto_order-frontend-implementation-phase.md`）を作成しユーザー承認を得た
- Plan時に3点をAskUserQuestionで確認済み: (1) 既存JSON API（10ルート・233件のテストが依存）は`/api`配下に移動し、Livewireページが元のURLを使う（推奨採用）、(2) `composer.json`が既にインストール済みのLivewire 4.xを採用しドキュメント側〔ADR-0001・`.claude/rules/15-frontend.md`〕の3.x記述を修正（推奨採用）、(3) UC-004画面のUC-010（買い増し候補）セクションは別セッション進行中・未マージのF-010に依存するため今回のPhase0/Phase1〜7スコープからは除外し、利確検討セクションのみで進める
- Phase0（全画面の前提となる基盤整備）を実施:
  - `routes/web.php`の既存13ルートを`Route::prefix('api')->middleware('auth')->group(...)`に再編。対応する10本のFeature TestファイルのURL文字列を`/api/...`に一括置換（ロジック変更なし）
  - `.claude/rules/15-frontend.md`・`docs/adr/ADR-0001-frontend-stack-selection.md`のLivewireバージョン記述を3.x→4.xに修正（新規ライブラリ採用ではないため新規ADR無し）
  - `ListHoldingsAction`のレスポンスに`id`（一覧→詳細画面のリンク生成用）を追加。回帰テスト1件追加
  - `ImportCsvAction::execute()`のシグネチャを`StoreCsvImportRequest`直接受け取りから、プレーンな`UploadedFile`3引数（Livewireの`TemporaryUploadedFile`は`Illuminate\Http\UploadedFile`のサブクラスのため互換）に変更。`CsvImportController`は薄いアダプタ化。既存テストへの影響なし（HTTP経由のみで検証されているため）
  - 共通レイアウト`resources/views/components/layouts/app.blade.php`（ui-guidelines.md確定の5タブナビゲーション）と共通Bladeコンポーネント6種（card/badge/stat-box/btn/empty-state/page-header）を新規作成。カラーパレットはui-guidelines.mdの値をTailwind v4の`@theme`セマンティックトークンとして`resources/css/app.css`に追加
  - 最小限の自作Livewireログイン画面（`app/Livewire/Auth/Login.php`、Breeze/Fortify等は導入せず）+ ログアウトルートを新規追加。既存シード（`test@example.com`/`password`）を使用。Feature Test 6件（Livewire::test()ベース）
  - `npm install && npm run build`でVite/Tailwindアセットをビルド（既存の`@vite`参照に必要）
  - Playwright MCPで実ブラウザ確認: `/login`にログインフォームが正しく表示され、正しい認証情報でログイン→`/holdings`へのリダイレクトが発火し、認証セッションが実際に確立されていること（`/api/holdings`への直接アクセスで実データJSON応答を確認）を確認。`/holdings`自体はPhase3未着手のため404だが想定通り
  - `docs/architecture/overview.md`に初めて実質的な内容を記載（フロントエンド構成の方針、/api分離の理由等）
- 対象6件（ログイン関連）+ 既存233件の回帰確認、フルスイート239件全てGreen

### Files touched

`routes/web.php`、`tests/Feature/UC001CsvImportTest.php`・`UC002HoldingListTest.php`・`UC003HoldingDetailTest.php`・`UC004SignalListTest.php`・`UC005SectorDashboardTest.php`・`UC006CandidateCheckTest.php`・`UC007MarketIndicatorTest.php`・`UC008WatchedThemeTest.php`・`UC008NewCandidateListTest.php`・`UC009ImportSummaryReportTest.php`（URL文字列を`/api/...`に変更）、`.claude/rules/15-frontend.md`、`docs/adr/ADR-0001-frontend-stack-selection.md`（バージョン記述修正）、`app/Actions/Holding/ListHoldingsAction.php`（`id`追加）、`app/Actions/Import/ImportCsvAction.php`・`app/Http/Controllers/CsvImportController.php`（シグネチャ変更）、`resources/views/components/layouts/app.blade.php`（新規）、`resources/views/components/{card,badge,stat-box,btn,empty-state,page-header}.blade.php`（新規）、`resources/css/app.css`（テーマトークン追加）、`app/Livewire/Auth/Login.php`（新規）、`resources/views/livewire/auth/login.blade.php`（新規）、`tests/Feature/LoginTest.php`（新規、6件）、`docs/architecture/overview.md`（初の実質的記載）、`PLAN.md`（本エントリ追加）

### Status

Green確認・実ブラウザ動作確認完了。フルスイート239件Green。次はPhase1（UC-001 CSV取込画面）に進む。

## 今後の対応（未着手・スコープ確認済み）（2026-08-23追記、UC-007完了時点で更新）

- **フロントエンドUI（Livewire画面化）**: UC-001〜UC-009はこれまで全てAPIのみで実装してきた（`app/Livewire/`・`resources/views/`配下のBladeビューは0件、`docs/product/mockups/`は静的HTMLモックのみで実際に動く画面ではない）。Phase2（F-005/F-006/F-007/F-008）がAPIレベルで全完了したため、**次はLivewireコンポーネント・Bladeビューの実装（実際にブラウザでCSV取込〜各画面確認ができる状態にする）に着手する**方針をユーザーと確認済み
- **F-007（UC-007 市場全体指標表示）の3指標が未実装**: `GET /market-indicators`エンドポイント自体は実装完了したが、**米国10年債利回り・VIX指数・USD/JPY為替レートの3指標は取得ロジック自体が無く**（J-Quantsの範囲外のデータで、別途新規の外部APIクライアント選定〔ADR要〕が必要）、常に`null`のプレースホルダを返す。3指標の外部データ取得自体は別タスクとして先送り

## 実装済み全エンドポイントのリクエスト/レスポンスIntegrationテスト網羅性監査・不足分追加（2026-08-23）

### Decision

- ユーザーから「実装済みの範囲で、データリクエスト、レスポンスにおいてIntegrationテストで漏れがないか確認して」との依頼を受け、実装済み全11ルート（`routes/web.php`）のController・FormRequest・対応するFeature Testを1件ずつ突き合わせ、リクエストのバリデーション分岐とレスポンスJSON契約の両面でテスト漏れを監査した
- 最大の漏れとして、UC-001（`POST /csv-import`）の成功時レスポンス本文（`CsvImportController::store`が返す`import_batch_id`/`status`/`imported_count`/`error_count`/`imported_at`/`newly_detected_symbols`）が、Red phase時点の意図的な設計判断（「DB側の副作用のみ検証し、レスポンス形状はGate4未確定のため見送る」）のままGreen実装完了後も検証されていなかったことを特定した。同様に、パース不能CSV・未知の口座区分見出しの422（`ImportResult::failure()`由来のカスタム`{"message": ...}`ボディ）もDBの`failure_reason`のみ検証されメッセージ自体は未検証だった
- 加えて、FormRequestにバリデーションルールが存在するのに対応する異常系分岐が一度もテストされていない箇所を5件特定: UC-002 `signal_only`（真偽値以外）、UC-003 `chart_period`（enum範囲外）・`memo`必須違反、UC-006 `watch_status`（enum範囲外）・`symbol_code`必須省略（GET/POST両方）
- ユーザー承認のもと、上記の欠落を埋める8件のテストケースを既存Feature Testファイルへ追加した（新規エンドポイント実装を伴わない、既存の完成済み実装に対する回帰防止テストの追加であるため、通常の`/tdd` Red→Gate4→Green分離は行わず直接追加）。追加はいずれも既存実装が返す値をそのまま検証するものであり、実装コードの変更は一切発生していない
- 対象8件・フルスイート233件全てGreenを確認（追加前225件 + 8件）

### Files touched

`tests/Feature/UC001CsvImportTest.php`（レスポンス本文アサーション追加×2箇所・422メッセージアサーション追加×2箇所）、`tests/Feature/UC002HoldingListTest.php`（`signal_only`異常系1件追加）、`tests/Feature/UC003HoldingDetailTest.php`（`chart_period`異常系・`memo`必須違反の2件追加）、`tests/Feature/UC006CandidateCheckTest.php`（`symbol_code`必須違反2件・`watch_status`異常系1件の3件追加）、`PLAN.md`（本エントリ追加、300行超過に伴い旧エントリ7件を`docs/history/plan-archive.md`へ退避）

### Status

Green確認完了。フルスイート233件Green。監査で識別した軽微な残課題（POST /holdings/{holding}/memosの存在しないholding ID時404、POST /watched-themes・POST /holdings/{holding}/memosの成功レスポンス本文の直接検証、UC-001の`us_stock_file`/`mutual_fund_file`個別の拡張子・サイズ境界値）は影響が小さいため今回は対応を見送り、必要になった時点で別途対応する。

## Phase2: UC-007（市場全体指標表示）実装完了、Phase2（F-005/F-006/F-007/F-008）全完了（2026-08-23）

### Decision

- UC-006（Cycle A/B）に続きPhase2最後の項目、UC-007（市場全体指標表示）を実装。`market_indicator_snapshots`テーブル・日経平均/S&P500の取得ロジック自体はPhase1（ADR-0004）で先行実装済みだったため、今回は表示エンドポイントのみが対象
- 調査の結果、米国10年債利回り・VIX指数・USD/JPY為替レートの3指標は取得ロジック自体がコードベースのどこにも存在しないことが判明（J-Quantsの範囲外データで新規の外部APIクライアント選定が必要）。ユーザーに確認し、**日経平均・S&P500の2指標のみ先に実装し、残り3指標は常にnullのプレースホルダとして返す**方針で合意（use-cases.mdエラーケース「該当指標のみ『取得不可』と表示」のAPI表現。3指標の外部データ取得は別タスクとして先送り、上記「今後の対応」に記録）
- `test-writer`が6件のFeature Testを作成。設計はAskUserQuestionで事前確定済みのためGate4での追加確認は無く、そのままGreenへ
- `tdd-implementer`がGreenフェーズを実装: `ShowMarketIndicatorAction`（直近スナップショットから5指標を固定順`nikkei225/sp500/us10y/vix/usdjpy`で返す。存在しない指標・スナップショット自体が無い場合もnullで安全に返す）、`MarketIndicatorController`（`GET /market-indicators`）、ルート追加。対象6件・フルスイート227件全てGreen
- 実データで実挙動確認（トランザクションロールバック）: nikkei225/sp500は実際のvalue/change_rate/ma_deviationが正しく返り、us10y/vix/usdjpyは想定通りnullで返ることを確認

### Files touched

`app/Actions/Market/ShowMarketIndicatorAction.php`（新規）、`app/Http/Controllers/MarketIndicatorController.php`（新規）、`routes/web.php`（ルート追加）、`tests/Feature/UC007MarketIndicatorTest.php`（新規、6件）、`docs/architecture/data-model.md`（実装完了注記・変更履歴）、`PLAN.md`（本エントリ・今後の対応の更新）

### Status

Green確認・実データ動作確認完了。フルスイート227件Green。これでPhase2（F-005/F-006/F-007/F-008）が全て完了。次はフロントエンドUI（Livewire画面化）に着手する（上記「今後の対応」参照）。

## Phase2: UC-006 Cycle B（本体）完了、Phase2「UC-008→UC-005→UC-006」全完了（2026-08-23）

### Decision

- Cycle A（`financial_statements`書き込み経路）に続き、UC-006本体（`GET /candidate-check`・`POST /candidate-check/watch-records`）を実装。計画は`C:\Users\minow\.claude\plans\stock_auto_order-uc006-implementation-phase.md`
- `test-writer`が13件のFeature Testを作成。Gate4で3点確認: (1) `overlap_rate`/`diversification_comment`はUC-005の`SectorAllocationCalculator`を流用し、対象銘柄のセクターに一致する行の`allocation_rate`/`allocation_status`から決定（一致行が無い＝現在保有が無いセクターの場合は`overlap_rate=0`）— 「妥当」で承認、(2) `watch_status`・`watch_memo`が両方省略されたPOSTは422で拒否 — 承認、(3) `GET /candidate-check`の未認証時は302リダイレクト（既存UCと統一）— 承認。いずれもテストの仮定通りで確定したためテスト修正なしでGreenへ進んだ
- `tdd-implementer`がGreenフェーズを実装: `WatchRecord`モデル・マイグレーション（`holding_memos`と同じ追記のみパターン）、`CandidateOverlapCalculator`（`SectorAllocationCalculator`を呼び出しセクター名一致行から算出。新規計算式は作らない）、`ShowCandidateCheckAction`（UC-003`ShowHoldingDetailAction`と同一の指標フィールド・null安全パターンを踏襲）、`SaveWatchRecordAction`、`ShowCandidateCheckRequest`/`SaveWatchRecordRequest`（`symbol_code`未存在を`Rule::exists`で422化）、`CandidateCheckController`、ルート追加。対象13件・フルスイート221件全てGreen
- 実データで実挙動確認（トランザクションロールバックでDBは汚さず）: (a) 直近スナップショットに存在しない保有銘柄（トヨタ自動車）で`overlap_rate=0`・「現在このセクターの保有はありません」コメントになることを確認（該当セクターの現在保有が無いケースの実例）、(b) 直近スナップショットに存在する銘柄（ソフトバンクグループ、情報通信・サービスその他セクター）で`overlap_rate`が`SectorAllocationCalculator::calculate()`の該当行の`allocation_rate`と完全一致することを確認、(c) `SaveWatchRecordAction`での保存→`ShowCandidateCheckAction`での再取得が正しく連動することを確認

### Files touched

`database/migrations/2026_08_23_000002_create_watch_records_table.php`（新規）、`app/Models/WatchRecord.php`（新規）、`app/Services/Candidate/CandidateOverlapCalculator.php`（新規）、`app/Actions/Candidate/ShowCandidateCheckAction.php`（新規）、`app/Actions/Candidate/SaveWatchRecordAction.php`（新規）、`app/Http/Requests/ShowCandidateCheckRequest.php`（新規）、`app/Http/Requests/SaveWatchRecordRequest.php`（新規）、`app/Http/Controllers/CandidateCheckController.php`（新規）、`app/Models/Holding.php`（`watchRecords()`リレーション追加）、`routes/web.php`（ルート追加）、`tests/Feature/UC006CandidateCheckTest.php`（新規、13件）、`docs/architecture/data-model.md`（実装完了注記・変更履歴）、`PLAN.md`（本エントリ追加）

### Status

Green確認・実データ動作確認完了。フルスイート221件Green。これでPhase2（F-005/F-008/F-006、「UC-008→UC-005→UC-006」の順）が全て完了。次のスコープはユーザーと相談の上で決定する（F-007市場全体指標ダッシュボードUI、他のPhase2/3項目、または別セッションで進行中のF-010〔ADR-0007、押し目買いシグナル〕への合流等、未確定）。

## UC-006 Cycle Aの`/review`拡張レベル指摘（MEDIUM）を修正（2026-08-23）

### Decision

- コミット`2712167`（UC-006 Cycle A）に対し`/review`拡張レベル（review-score 43 ≧ 閾値30、`database/migrations/`が該当したため）を実施
- 指摘（MEDIUM）: `financial_statements.revenue`/`operating_income`をNOT NULLで定義していたが、データソースである`JQuantsClient::fetchStatements()`の`net_sales`/`operating_profit`は`float|null`として型付けされており、`FundamentalIndicatorMapper`側は既にこのnullを一貫して考慮済みだった。この非対称性により、J-Quantsが該当期のSales/OPを欠損で返す銘柄で`financial_statements`のINSERTが`QueryException`となり、`DB::transaction()`配下の同一銘柄の`technical_indicators`/`fundamental_indicators`/`signals`更新まで巻き添えでロールバックしてしまう不具合があった
- 修正方針は「修正してから、Cycle Bへ」の指示に従い即座に対応。再発防止として先に回帰テスト（Red）を自分で書き、現状のNOT NULL制約で実際にロールバックが発生する（`FinancialStatement::count()`が0になる）ことを確認してから、新規マイグレーション`2026_08_23_000001_nullable_revenue_operating_income_on_financial_statements_table.php`で`revenue`/`operating_income`をnullable化（Green）。既存の`2026_08_23_000000_create_financial_statements_table`は編集せず`change()`で列制約のみ変更（`.claude/rules/20-mysql.md`）
- 列制約変更は`.claude/rules/60-docs.md`の「危険な操作（ADR必須）」に該当するため、先行するeps_growth拡張（ADR-0006）と同じ形式でADR-0008を新規作成
- フルスイート208件Green（207→208、回帰テスト1件追加）を確認

### Files touched

`database/migrations/2026_08_23_000001_nullable_revenue_operating_income_on_financial_statements_table.php`（新規）、`docs/adr/ADR-0008-nullable-financial-statement-columns.md`（新規）、`tests/Feature/FetchExternalMarketDataActionTest.php`（回帰テスト1件追加）、`docs/architecture/data-model.md`（`financial_statements`のnullable反映・変更履歴）、`PLAN.md`（本エントリ追加）

### Status

Green確認完了。フルスイート208件Green。次はUC-006 Cycle B（`watch_records`テーブル＋`GET /candidate-check`・`POST /candidate-check/watch-records`）に進む。

## Phase2: UC-006 Cycle A（financial_statements書き込み経路）完了（2026-08-23）

### Decision

- Cycle4（UC-006）に着手。他3サイクルと異なり`financial_statements`/`watch_records`という未実装の2テーブルに依存するため、計画（`C:\Users\minow\.claude\plans\stock_auto_order-uc006-implementation-phase.md`）で2サイクルに分割し、まずCycle A（`financial_statements`）を実施した
- Planフェーズでユーザーに確認: (1) `holdings`に一度も存在しない銘柄コードは422エラーで拒否（外部APIでの新規find-or-createは実装しない）、(2) 指標データはキャッシュ済みのみ参照（ライブ外部APIコールはしない）、(3) `financial_statements`は`FetchExternalMarketDataAction`が既に取得済みの`jQuantsClient->fetchStatements()`結果を保存先追加するだけ（新規API呼び出しなし）
- `test-writer`が5件のFeature Testを`FetchExternalMarketDataActionTest.php`に追加しGate4承認。過去期（index1〜4）のYoY成長率は5期分の取得データだけでは4期前を遡れないためnullにする設計を確認
- `tdd-implementer`がGreenフェーズを実装: 新規マイグレーション・モデル`FinancialStatement`、`FetchExternalMarketDataAction`のJP株処理ブロック内に5期分の`updateOrCreate()`を追加。`revenue_yoy_change`/`operating_income_yoy_change`は最新期（index0）のみ`FundamentalIndicatorMapper::calculateGrowth()`と同一ロジックで算出。対象5件・フルスイート207件全てGreen（実装完了後、セッションのAPI制限で報告前に中断したが、成果物を直接確認し完了を確認した）
- 実データで実挙動確認: 既存の再取込み済みバッチに対し`FetchExternalMarketDataAction`を再実行し、225件の`financial_statements`が実際のJ-Quants財務データ（売上高・営業利益・YoY成長率）で正しく保存されることを確認

### Files touched

`database/migrations/2026_08_23_000000_create_financial_statements_table.php`（新規）、`app/Models/FinancialStatement.php`（新規）、`app/Actions/Analysis/FetchExternalMarketDataAction.php`、`tests/Feature/FetchExternalMarketDataActionTest.php`（5件追加）、`docs/architecture/data-model.md`（実装完了注記・変更履歴）、`PLAN.md`（本エントリ追加）

### Status

Green確認・実データ動作確認完了。フルスイート207件Green。次はCycle B（`watch_records`テーブル＋UC-006本体`GET /candidate-check`・`POST /candidate-check/watch-records`）に進む。

## `/review`拡張レベルの指摘（NewCandidateFinderのN+1）を修正（2026-08-23）

### Decision

- Cycle3完了後、Cycle4着手前に`/review`を実施（Cycle1〜3累積差分16ファイル・+1767/-4行に対しスコア105〔閾値30超過〕→拡張レベル）
- MEDIUM指摘: `NewCandidateFinder::find()`が`portfolioEvaluationTotal()`算出用の`$allHoldingSnapshots`を`holding`リレーションをeager loadせずに取得しており、`instrument_type`参照のたびに遅延ロードクエリが発生するN+1だった。UC-005（`ShowSectorDashboardAction`）も内部で`NewCandidateFinder::find()`を呼ぶため影響が波及していた
- `HoldingSnapshot::query()->where(...)->with('holding')->get()`に1行修正。既存のテスト値・挙動は変わらないため新規テストは追加せず、フルスイート202件Greenで回帰なしを確認
- LOW指摘（`NewCandidateFinder`と`SectorAllocationCalculator`の評価額計算ロジック重複）はユーザー判断で今回見送り

### Files touched

`app/Services/Candidate/NewCandidateFinder.php`、`PLAN.md`（本エントリ追加）

### Status

Green確認完了。フルスイート202件Green。次はCycle4のUC-006（新規投資候補の重複チェック）に進む。

## Phase2: UC-005 Cycle3（セクター配分ダッシュボード）完了（2026-08-23）

### Decision

- Cycle2（UC-008）に続き、UC-005（セクター配分ダッシュボード）を実装した。計画は`C:\Users\minow\.claude\plans\stock_auto_order-uc005-implementation-phase.md`（Planモードで作成、ユーザー承認済み）
- use-cases.mdの出力表は`sector_name`/`allocation_rate`等をフラットに列挙しているが、モックアップ（`screen-UC005-sector-dashboard.html`）は「セクター配分バー一覧」と「リバランス提案」の2セクション構成だったため、レスポンス形状を`{"data": {"sectors": [...], "rebalance_candidates": [...]}}`の入れ子構造として設計し、Gate4でユーザー承認を得た
- セクター集計はUC-008/UC-009と異なり**全instrument_type（stock/etf/mutual_fund）を対象**とする設計とした（use-cases.md「セクター分類が取得できていない銘柄は『未分類』として集計に含める」という文言が保有全体を前提にしているため）
- `test-writer`が7件のFeature Testを作成しGate4承認。`suggested_sell_quantity`の按分方法（セクター内課税口座保有銘柄の加重平均現在値で除算）は叩き台として承認
- `tdd-implementer`がGreenフェーズを実装: `SectorAllocationCalculator`（新規、投資信託の単位補正込み評価額集計・40%/70%閾値判定・NISA区分除外〔`holding_snapshot_accounts`経由、UC-004と同じフォールバックパターン〕）・`ShowSectorDashboardAction`（`NewCandidateFinder`をそのまま呼び出しフィールドをリマップ、偏り警告セクター所属候補を除外）・`SectorDashboardController`（`GET /sector-dashboard`）を新規作成。対象7件・フルスイート202件全てGreen
- 実データで実挙動確認: `allocation_rate`合計が100%になることを確認。既知の制約（J-Quantsレート制限によるセクター分類カバレッジ不足）により保有の96.7%が「未分類」に集約され偏り警告（売却提案額¥4,266,160）になることを確認。ロジック自体は正常
- `data-model.md`の「保留・確定が必要な初期パラメータ値」表を更新: セクター配分閾値・目標配分率・財務健全性フィルタ・NISA推奨基準のUC-005分を確定、売却株数按分方法を新規追記

### Files touched

`app/Services/Sector/SectorAllocationCalculator.php`（新規）、`app/Actions/Sector/ShowSectorDashboardAction.php`（新規）、`app/Http/Controllers/SectorDashboardController.php`（新規）、`routes/web.php`（ルート追加）、`tests/Feature/UC005SectorDashboardTest.php`（新規）、`docs/architecture/data-model.md`（初期パラメータ確定・変更履歴）、`PLAN.md`（本エントリ追加）

### Status

Green確認・実データ動作確認完了。フルスイート202件Green。これでPhase2「UC-008→UC-005」が完了。次はCycle4のUC-006（新規投資候補の重複チェック、UC-008と同一画面の下部セクション）に進む。

## 【検討事項・未着手】利確・リバランス閾値の動的分岐ロジック検討（2026-08-22）

### Decision

まだ設計未着手の検討事項として記録のみ行う（実装・use-cases.md本文の確定変更はしない）。

- **課題認識（ユーザー提起）**: 現状、利確検討の閾値は一律の固定値になっている（UC-004の対象抽出・分割指値提案は含み益+20%/+35%固定、UC-009の`buildTakeProfitCandidates()`も`TAKE_PROFIT_GAIN_RATE_THRESHOLD = 20.0`の固定値）。本システムは中長期目線での運用を想定しているため、この一律20%〜35%という水準は緩すぎる可能性がある。下落トレンド中の銘柄に対する「20%戻ったら利確」という基準と、上昇が継続しそうな銘柄に対する基準を同じにするのはナンセンスで、後者はもっと高い水準（例: +100%〜+150%程度）まで引き上げるべきではないか、という指摘
- **検討の方向性（未確定）**: 一律閾値ではなく、銘柄の状態に応じて利確検討ラインを動的に分岐させる。分岐軸の候補としてユーザーと合意した出発点は以下2軸の組み合わせ:
  1. **シグナル軸**: ADR-0004で実装済みの7種シグナル（`SignalDeterminationService`: RSI高値反落・MACDデッドクロス・BB過熱・52週高値反落・PEG割高・相対力弱含み・出来高急増下落）の発生有無・件数。下落兆候シグナルが出ていない銘柄は「まだ上昇モメンタムが続いている」とみなし利確ラインを引き上げ、シグナルが1件以上出ている銘柄は従来通り早めの水準で利確検討を促す
  2. **ファンダメンタルズ軸**: UC-003で既に算出・表示しているROE・利益成長率等の成長性指標。ファンダメンタルズが良好な銘柄は中長期の「地力」があるとみなし閾値を引き上げる
  - イメージ: シグナルなし＋ファンダメンタルズ良好 → 利確検討ラインを+100%〜+150%程度まで引き上げ／シグナルあり、またはファンダメンタルズ悪化 → 従来通り+20%〜+35%程度で早期に利確検討
- **未確定事項（次にこのテーマに着手する際、Planフェーズで具体化する）**:
  - 分岐の具体的な閾値レンジ・段階数（2段階か3段階以上か）
  - シグナル軸とファンダメンタルズ軸の組み合わせ方（AND/ORか、スコア加算方式か）
  - UC-004（分割指値提案・対象抽出）とUC-009（`buildTakeProfitCandidates()`の`composite_score`・対象抽出閾値）両方への反映要否・反映方法の異同
  - `docs/product/use-cases.md`・`docs/architecture/data-model.md`（「保留・確定が必要な初期パラメータ値」表）への正式な反映はGate 2/3を経てから行う
- 現時点では`docs/product/use-cases.md`のUC-004/UC-009に「検討中」の注記のみ追加した（本文の閾値記述自体は未変更）。着手判断はユーザーの指示待ち
- **【申し送り、2026-08-23追記】** F-010（既存保有株の買い増しタイミングレコメンド、ADR-0007）の設計時に本検討事項との整合性を確認した。動的分岐の「売りシグナル0件なら利確ラインを引き上げる」判定は`signals`（利確シグナル）の件数を数える設計になる想定だが、F-010の買いシグナルは意図的に別テーブル（`buy_signals`）に分離してあるため混入しない。一方で、動的分岐により`FetchExternalMarketDataAction`の利確シグナル生成ゲート（含み益+20%超）の閾値が銘柄ごとに20〜150%へ変動するようになると、**UC-010（買い増し候補）の対象範囲が連動して広がる**（`signals`行が作られない銘柄が増える→`whereDoesntHave('signals')`の対象が増える）。動的分岐の実装時にはこの副作用を踏まえ、UC-010側の対象件数の変化を実データで確認すること

### Files touched

`PLAN.md`（本エントリ追加）、`docs/product/use-cases.md`（UC-004・UC-009に検討中の注記追加）

### Status

検討事項として記録のみ。設計・実装は未着手。次にこのテーマに着手する際はPlanフェーズから開始する。

