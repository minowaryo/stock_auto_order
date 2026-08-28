# PLAN.md

> 2026-08-23（実装済み全エンドポイントのIntegrationテスト網羅性監査完了時点）以前（Gate0セットアップ〜Phase1 Gate4サイクル完了・ADR-0002 NISA区分CR・ADR-0004分析エンジン実装〔設計確定〜各TDDサイクル、UC-001配線・UC-004画面・UC-003/UC-009新指標反映を含む〕完了・関連review指摘修正2件・UC-009サンプルレポート生成、F-010（UC-010）Gate1〜3ドキュメント叩き台整備完了、NISA区分内訳の書き込み・UC-004消費完了、未知の口座区分ラベルの扱いに関する`/review`指摘修正、Phase2 UC-008（Cycle1・Cycle2）完了、Phase2「UC-008→UC-005→UC-006」全完了・UC-007市場全体指標表示実装完了・実装済み全エンドポイントのIntegrationテスト網羅性監査完了等）の完了済みエントリは `docs/history/plan-archive.md` に退避済み。
> **運用ルール**: PLAN.mdは300行を超えないよう保つ。300行に近づいたら、Statusが「完了」相当（Green確認完了・マージ済み等）の最も古いエントリから`docs/history/plan-archive.md`へ退避し、本ファイル冒頭のこの注記を更新する（詳細は `.claude/rules/60-docs.md` 参照）。

## フロントエンド実装Phase7（UC-006/UC-008統合「新規投資候補」画面）完了、全7Phase完了（2026-08-28）

### Decision

- Phase6に続き、フロントエンド実装計画の最終Phase7（UC-006「新規投資候補の重複チェック」+ UC-008「おすすめ候補」の統合画面、`GET /candidate-check`）を実施。use-cases.mdの業務ルール（UC-006「画面はUC-008と統合し単一メニュー項目の下部セクションとして提供」/ UC-008「UC-006と同一画面の上部セクション、専用メニュー項目は設けない」）通り1画面に統合。既存の`ShowNewCandidateListAction`・`ShowCandidateCheckAction`・`SaveWatchRecordAction`（いずれもPhase2で実装済み・無改修で再利用）を配線するのみ
- `test-writer`が8件のLivewireコンポーネントテストを作成。Gate4で2点確認: (1) 他画面（SignalList/SectorDashboard）からの`/candidate-check?symbol_code=XXXX`リンク遷移時、`#[Url(as:'symbol_code')]`でクエリパラメータをsymbolCodeプロパティに束縛し、`mount()`時点で自動的に個別チェックを実行する設計、(2) 存在しないsymbol_codeでのチェック時は「銘柄コードを確認してください」をインライン表示し指標は一切表示しない（クラッシュ・リダイレクトなし）— いずれも「推奨」で承認
- `tdd-implementer`がGreenフェーズを実装: `app/Livewire/Candidate/CandidateCheck.php`（おすすめ候補は`render()`で毎回呼び直す純粋読み取り、個別チェック・ウォッチ記録保存は`checkCandidate()`/`saveWatchRecord()`メソッド）。対象8件・フルスイート365件全てGreen（回帰なし）
- 実装上の注意点（軽微、次点の課題として記録）: (a) おすすめ候補テーブルの自己資本比率・ROE生値表示のため`render()`内で`Holding`を追加クエリしており、`ShowNewCandidateListAction`の`fundamental_summary`（四捨五入済み文字列）とは別に生データを取得している。新規計算式ではなく既存カラムの表示専用の再取得のため許容、(b) 判定結果カードの重複度ラベル（「やや偏り」等）は、`ShowCandidateCheckAction`/`CandidateOverlapCalculator`がラベルを返さないため、Blade側で`SectorAllocationCalculator`（UC-005）と同一の40%/70%閾値をコメント付きで再定義して導出している。**この閾値がBlade側とService側の2箇所に分散する形になっており、将来どちらかだけ変更されると表示が乖離するリスクがある**。是正するなら`CandidateOverlapCalculator`にラベル算出を寄せる小さなリファクタが必要（Action改修を伴うため別途Red→Gate4→Greenサイクル）。実害は表示ラベルのみ（`overlap_rate`自体の数値は実データのまま）のため今回は許容し先送りとした
- 実ブラウザ確認（Playwright MCP）: ログイン→`/candidate-check`へ正常遷移、コンソールエラーなし。開発DBの保有データ・ウォッチテーマが空のため（Phase6から継続、下記「今後の対応」参照）、おすすめ候補は空状態表示を確認。個別チェックは実データが無いため「有効なsymbol_code」のケースは未確認だが、「存在しないsymbol_code」のエラーパス（「銘柄コードを確認してください」のインライン表示）は実際に画面上で発火・表示されることを確認した（`.click()`がセッション経過による既知のPlaywright側の反応不良を示したため、`window.Livewire.find(wireId).call('checkCandidate')`で直接呼び出して確認 — アプリ側の不具合ではないことは`/api/holdings`等の既知の切り分け手順と同様に確認済み）
- これで計画（`stock_auto_order-frontend-implementation-phase.md`）のPhase0〜7が全て完了。UC-001〜UC-009（UC-007はUC-002内ウィジェット、UC-008はUC-006と統合画面）を一通りブラウザで操作・確認できる状態になった（保有データが復元され次第、実データでのEnd-to-End最終確認を行う）

### Files touched

`app/Livewire/Candidate/CandidateCheck.php`（新規）、`resources/views/livewire/candidate/candidate-check.blade.php`（新規）、`routes/web.php`（`/candidate-check`ルート追加）、`tests/Feature/CandidateCheckTest.php`（新規、8件）、`PLAN.md`（本エントリ追加）

### Status

Green確認完了。フルスイート365件Green。実ブラウザ確認はエラーパスのみ実施（開発DBの保有データ欠落のため、「今後の対応」参照）。フロントエンド実装計画の全7Phase完了。次は開発DBへの実データ復元（CSV再取込）とEnd-to-End最終確認、または別タスク（数値未整形表示の是正・重複度ラベルの閾値統合リファクタ等）に進む。

## UC-010 `/review`指摘3件の修正完了（CHG-0005含む）（2026-08-28）

### Decision

- UC-010 Green実装完了後にユーザーの依頼で`/review`を実施（8観点の並列エージェント）。確定バグ2件・consistency指摘1件をユーザーに詳細説明し、全て修正する方針で承認を得た
- **バグ1**: `FundamentalHealthEvaluator::evaluate()`が成長率データ両方null判定をequity_ratio/roeの閾値判定より先に行っていたため、equity_ratio/roeが明らかに基準未満（本来`failed`）の銘柄でも成長率未取得なだけで`unavailable`が返り、一覧に表示されてしまうバグを修正。equity_ratio/roeいずれかが基準未満なら即座に`failed`を返す順序に変更
- **バグ2**: `ShowBuySignalListAction::fundamentalSummary()`が符号を見ずに営業利益成長率を無条件優先表示していたため、売上高成長率のプラスで合格したのにマイナスの営業利益成長率が表示される矛盾を修正。実際にプラスだった方を優先表示するよう変更
- **CHG-0005（consistency指摘）**: ADR-0007 D4は「UC-008/UC-009と同一値」と謳っていたが、成長率条件を追加したのはUC-010のみで、UC-008/UC-009（`NewCandidateFinder`・`ShowImportSummaryReportAction`）は自己資本比率・ROEの2条件のみだったため、同一銘柄がUC-008では候補に出るがUC-010では出ない（またはその逆）という乖離が起こり得た。ユーザーと相談し、UC-008/UC-009にも成長率条件を追加して統一する方針で合意。`use-cases.md`（UC-008業務ルール改訂・承認記録）、`data-model.md`（財務健全性フィルタ行・承認記録）、`traceability-matrix.md`（CHG-0005、F-010ステータス修正、CHG-0004承認者の記載漏れ修正）を先に整備
- `test-writer`がRedフェーズで5ファイルを改訂・作成（`FundamentalHealthEvaluatorTest`2件・`UC010BuySignalListTest`1件・`UC008NewCandidateListTest`2件・`UC005SectorDashboardTest`0件〔フィクスチャ調整のみ〕・`UC009ImportSummaryReportTest`1件、計6件Red）。並行セッションによるテストDB競合（migrate中のdeadlock等）でフルスイート実行が不安定だったため、ファイル単位で個別実行して意図通りのRed原因であることを確認しGate4承認
- `tdd-implementer`がGreenフェーズを実装: `FundamentalHealthEvaluator`（判定順序修正）、`ShowBuySignalListAction::fundamentalSummary()`（表示優先順位修正）、`NewCandidateFinder`・`ShowImportSummaryReportAction`（`FundamentalHealthEvaluator`をDI注入し`evaluate()==='passed'`のみ候補として残す。`ShowSectorDashboardAction`〔UC-005〕は`NewCandidateFinder`を直接利用しているため無改修で自動的に反映）
- Green実装により、Gate4対象外だった`tests/Feature/SectorDashboardTest.php`（UC-005のLivewire画面テスト、別セッション所有・Phase6で新規作成されたばかり）で2件の回帰を検出（フィクスチャが成長率データを設定しておらず、CHG-0005の仕様通りの正しい副作用として除外されてしまっていた）。Gate4承認済みの`UC005SectorDashboardTest.php`（API版）に適用したのと全く同じ最小フィクスチャ修正（`revenue_growth: 8.0`追加）を適用し解消。新しい業務ルールのテストではなく既存フィクスチャの整合性維持のみのため、新規Gate4サイクルは経由せず対応
- フルスイート357件Green確認後、コミット（`bfe29da`、未push）

### Files touched

`app/Services/Analysis/FundamentalHealthEvaluator.php`、`app/Actions/Signal/ShowBuySignalListAction.php`、`app/Services/Candidate/NewCandidateFinder.php`、`app/Actions/ImportSummaryReport/ShowImportSummaryReportAction.php`、`docs/product/use-cases.md`（UC-008業務ルール改訂・承認記録）、`docs/architecture/data-model.md`（財務健全性フィルタ行・承認記録）、`docs/rcid/traceability-matrix.md`（CHG-0005、F-010/CHG-0004のステータス修正）、`tests/Unit/Services/Analysis/FundamentalHealthEvaluatorTest.php`、`tests/Feature/UC010BuySignalListTest.php`、`tests/Feature/UC008NewCandidateListTest.php`、`tests/Feature/UC005SectorDashboardTest.php`、`tests/Feature/UC009ImportSummaryReportTest.php`、`tests/Feature/SectorDashboardTest.php`（フィクスチャ修正のみ）、`PLAN.md`（本エントリ追加）

### Status

Gate4完了（Red→Gate4承認→Green）。フルスイート357件Green。コミット済み（`bfe29da`、未push）。`/review`指摘のうち残る低優先度項目（効率性の重複計算、NISA推奨ロジック・`Signal`/`BuySignal`永続化の重複等）は対応保留、必要になった時点で再検討

## 今後の対応（未着手）（2026-08-27追記、Phase5の実ブラウザ確認時に発見）

- **数値の未整形表示（Phase3〜5共通）**: `HoldingList`（保有一覧、Phase3）・`SignalList`（利確検討、Phase5）の含み益率・取得単価・現在値・分割指値の価格が、`{{ $value }}`で生の浮動小数点値をそのまま出力しており（例: 含み益率が`89.5793`と%記号なし表示、価格が`3632.676`のような小数点3桁表示）、実際にPlaywrightで画面を目視確認した際に発見した。レイアウト崩れではなく数値の可読性の問題。既存テストは生の数値部分文字列を検証する設計のため、これらのテストを含め画面3つ（Phase3/4/5）をまとめて後日別タスクで整形する（%サフィックス・価格の四捨五入・桁区切り等）方針とし、今回のPhase5サイクルでは対応を見送る
- **UC-004のE2Eテスト**: 一覧→詳細遷移のみの標準的な閲覧フローであり、`.claude/rules/31-e2e-testing.md`が対象とする「クリティカルフロー」に該当しないと判断し追加しない（Phase3/UC-002・Phase4/UC-003の同種の遷移もE2E化していないこととの一貫性を優先）
- **開発DBの保有データが空になっている**: Phase6の実ブラウザ確認時に発覚。`test@example.com`ユーザー自体も消えており(`db:seed`で復元済み)、CSV再取込等の保有データは未復元。並行セッションが`migrate:fresh`等を実行した際の巻き添えと推測されるが未確定。今回はセクター配分ダッシュボードの空状態表示（「リバランス候補はありません」）の確認に留め、実データでの再確認は保有データが復元された時点で改めて行う。Phase7でも同様の制約により、`/candidate-check`のエラーパス（存在しないsymbol_code）のみ実ブラウザ確認済みで、有効データでの判定結果表示・ウォッチ記録保存は未確認
- **重複度ラベルの閾値がBlade側とService側に分散（Phase7で発生）**: `resources/views/livewire/candidate/candidate-check.blade.php`が判定結果カードの「健全」/「やや偏り」/「偏り警告」ラベルを、`SectorAllocationCalculator`（UC-005）と同一の40%/70%閾値をBladeの`@php`ブロック内に再定義して導出している（`ShowCandidateCheckAction`/`CandidateOverlapCalculator`はラベルを返さず`overlap_rate`の数値のみ返すため）。閾値が2箇所に分散しており、将来どちらか一方だけ変更されるとラベル表示が実際の判定基準と乖離するリスクがある。是正するには`CandidateOverlapCalculator`にラベル算出を寄せるリファクタが必要（`ShowCandidateCheckAction`の出力契約変更を伴うため別途Red→Gate4→Greenサイクルが必要）。実害は表示ラベルのみ（`overlap_rate`の数値自体は正しい）のため優先度は低いが、次にこの画面に手を入れる際に解消する

## フロントエンド実装Phase6（UC-005セクター配分ダッシュボード画面）完了（2026-08-28）

### Decision

- Phase5に続き、Phase6（UC-005セクター配分ダッシュボード画面、`GET /sector-dashboard`）を実施。`ShowSectorDashboardAction`は既存（Phase2で実装済み）のため、Livewireコンポーネント・ビューの新規作成のみが対象
- `test-writer`が7件のLivewireコンポーネントテストを作成。Gate4で2点確認: (1) 「健全」セクターは業務ルール（情報過多の回避）に基づきバッジ・文言を一切表示しない完全抑制とする、(2) NISA推奨候補の表示文言は「NISA」という部分文字列を含めば良い叩き台とする — いずれも「推奨」で承認
- `tdd-implementer`がGreenフェーズを実装: `app/Livewire/Sector/SectorDashboard.php`（`ShowSectorDashboardAction`を`render()`で毎回呼び出す純粋読み取り設計、HoldingList/SignalListと同一規約）。セクター配分バーはCSSのみ（`width: X%`インラインスタイル）、偏り警告→dangerバッジ／やや偏り→warningバッジ／健全→非表示、`is_overweight`時のみ売却提案（金額・株数）表示、リバランス候補は`/candidate-check?symbol_code=...`へのリンク・NISA推奨バッジ・空状態時「リバランス候補はありません」。対象7件・フルスイート335件Green（22件失敗は全て他UC・並行セッション作業由来の既存分、本変更による回帰なし）
- 実ブラウザ確認（Playwright MCP）: ログイン→`/sector-dashboard`へ正常遷移、コンソールエラーなし。開発DBの保有データが空の状態だったため（下記「今後の対応」参照）、セクター配分バー・バッジ・売却提案・NISA推奨バッジ付きの表示は目視確認できず、リバランス候補の空状態表示（「リバランス候補はありません」）のみ実ブラウザで確認した。データが入っている場合の各表示パターンは7件のFeature Testで網羅済み

### Files touched

`app/Livewire/Sector/SectorDashboard.php`（新規）、`resources/views/livewire/sector/sector-dashboard.blade.php`（新規）、`routes/web.php`（`/sector-dashboard`ルート追加）、`tests/Feature/SectorDashboardTest.php`（新規、7件）、`PLAN.md`（本エントリ追加、300行超過に伴い旧エントリ7件を`docs/history/plan-archive.md`へ退避）

### Status

Green確認完了。実ブラウザ動作確認は空状態のみ（開発DBの保有データ欠落のため、上記「今後の対応」参照）。フルスイート335件Green（他UC由来の既存失敗22件は無関係）。次はPhase7（UC-006/UC-008統合「新規投資候補」画面）に進む。

## フロントエンド実装Phase5（UC-004売買シグナル一覧画面）完了（2026-08-27）

### Decision

- Phase4に続き、Phase5（UC-004売買シグナル一覧画面、`GET /signals`）を実施。モックアップは買い増し候補（UC-010）セクションも含む2段構成だが、UC-010バックエンドは別セッション（`BuySignalDeterminationService`等）の担当スコープのため、今回は利確検討セクションのみを実装しUC-010セクションは対象外とした（別セッションのマージ完了後、別サイクルで追加する）
- `test-writer`が8件のLivewireコンポーネントテストを作成。Gate4で2点確認: (1) シグナルバッジは`signal_types`の生の文字列（`rsi_reversal`等）をそのまま表示（日本語ラベル変換は別タスク）、(2) 分割指値3段目（price=null、トレンド追従枠）の表示文言は「現在値以降」— いずれも「推奨」で承認
- `tdd-implementer`がGreenフェーズを実装: `ShowSignalListAction`に`id`（holdings.id、一覧→詳細画面のリンク生成用）を追加（Phase0の`ListHoldingsAction`と同じ先例）。`app/Livewire/Signal/SignalList.php`（`render()`で毎回呼び出す純粋読み取り設計）。対象8件・フルスイート344件全てGreen
- **並行セッション対応**: 別セッションが同日中にUC-010バックエンド一式をコミット（`ba239fe`）したことで、`routes/web.php`の同時編集競合が解消された（従来は`/buy-signals`が未コミットのまま`routes/web.php`に残り続けていたため、Phase3/4のたびに退避・再適用が必要だった）。今回は競合なくシンプルに完了
- 実ブラウザ確認（Playwright MCP、実データ）: `/signals`で実際の利確検討対象銘柄（マイクロン テクノロジー含み益+555%等、40件超）が正しく一覧表示され、各行が`/holdings/{id}`へのリンクを持つこと、シグナルなし銘柄・複数シグナル銘柄・分割指値3段（トレンド追従枠の「現在値以降」表示含む）が正しく表示されることを確認。コンソールエラーなし

### Files touched

`app/Actions/Signal/ShowSignalListAction.php`（`id`フィールド追加）、`app/Livewire/Signal/SignalList.php`（新規）、`resources/views/livewire/signal/signal-list.blade.php`（新規）、`routes/web.php`（`/signals`ルート追加）、`tests/Feature/SignalListTest.php`（新規、8件）、`PLAN.md`（本エントリ追加）

### Status

Green確認・実ブラウザ動作確認完了（実データ）。フルスイート344件Green。次はPhase6（UC-005セクター配分ダッシュボード画面）に進む。

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

