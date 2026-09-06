# PLAN.md

> 2026-08-27（フロントエンド実装Phase5完了時点。UC-010 Gate4完了・コミット`ba239fe`分も含む）以前（Gate0セットアップ〜Phase1 Gate4サイクル完了・ADR-0002 NISA区分CR・ADR-0004分析エンジン実装〔設計確定〜各TDDサイクル、UC-001配線・UC-004画面・UC-003/UC-009新指標反映を含む〕完了・関連review指摘修正2件・UC-009サンプルレポート生成、F-010（UC-010）Gate1〜3ドキュメント叩き台整備完了、NISA区分内訳の書き込み・UC-004消費完了、未知の口座区分ラベルの扱いに関する`/review`指摘修正、Phase2 UC-008（Cycle1・Cycle2）完了、Phase2「UC-008→UC-005→UC-006」全完了・UC-007市場全体指標表示実装完了・実装済み全エンドポイントのIntegrationテスト網羅性監査完了、フロントエンド実装Phase0（基盤整備）完了、フロントエンド実装Phase1+2（CSV取込画面・サマリーレポート画面）完了、利確・リバランス閾値の動的分岐ロジック検討〔検討事項の記録のみ、実装はCHG-0006として2026-08-28〜29に別途完了〕、フロントエンド実装Phase3（UC-002保有銘柄一覧画面＋UC-007ウィジェット、共通レイアウトのcsrf-tokenバグ修正含む）完了、Phase3の`/review`拡張レベル実施（コミット汚染・ビュー内クエリ修正）、フロントエンド実装Phase4（UC-003銘柄詳細画面）完了、UC-010 Gate2/Gate3正式承認（買いシグナル7種の前提条件追加）完了、UC-010 Gate4完了・コミット（`ba239fe`）、フロントエンド実装Phase5（UC-004売買シグナル一覧画面）完了、およびフロントエンド実装Phase6（UC-005セクター配分ダッシュボード画面）完了〔2026-09-05、CHG-0011作業時に退避〕等）の完了済みエントリは `docs/history/plan-archive.md` に退避済み。
> **運用ルール**: PLAN.mdは300行を超えないよう保つ。300行に近づいたら、Statusが「完了」相当（Green確認完了・マージ済み等）の最も古いエントリから`docs/history/plan-archive.md`へ退避し、本ファイル冒頭のこの注記を更新する（詳細は `.claude/rules/60-docs.md` 参照）。300行超過に伴い「数値表示フォーマット修正完了（2026-08-28）」「UC-010買い増し候補セクションのフロントエンド統合完了（2026-08-28）」の2エントリを退避済み（2026-09-06、CHG-0012 Phase 0作業時）。

## 財務健全性フィルタに営業利益率を追加（CHG-0012・ADR-0011）Phase 0 ドキュメント先行（2026-09-06〜）

### Decision

- 本人要望: 「財務健全性を確認できるわかりやすい項目を、中長期目線での評価がしやすくなるよう3項目からもう1項目足したい。おすすめは？」→ 営業利益率（営業利益÷売上高）を推奨・採用。3項目（ROE・自己資本比率・成長率）は資本効率／BSの頑丈さ／成長を見るが「事業そのものの稼ぐ力（利益率）」が欠けていた
- Planフェーズ承認済み。プランファイル: `~/.claude/plans/stock_auto_order-operating-margin-phase.md`
- 本人と確定（AskUserQuestion）: (1) **表示のみでなく判定に組み込む**（`FundamentalHealthEvaluator` の4条件目）、(2) 閾値 **10%以上**（8%案と実測比較。8%＝追加で2銘柄 failed／10%＝5銘柄。境界9〜10%の3銘柄を切ることを許容）、(3) `|営業利益率|>999%` は「—」（算出不可）扱いで Mapper が null 化
- 実測検証済み（保有128銘柄）: US=Finnhub `stock/metric` の `operatingMarginTTM`（無ければ `operatingMarginAnnual`）に存在・パーセントスケール・実態一致。JP=J-Quants で既取得の `net_sales`/`operating_profit` から実測算出（新規APIコールなし）。判定組み込みの実影響は 財務 `passed` が JP 15→11・US 12→11（合計 27→22）。落ちる銘柄: 3088マツキヨココカラ7.6% / 7867タカラトミー9.0% / 5288アジアパイルHD9.4% / 5805SWCC9.8% / IONQ-408%（いずれも妥当な検出）。ACHR は `operatingMarginAnnual=-243100` を返すため `decimal(7,4)` だと ADR-0006 と同じ INSERT エラー → `decimal(10,4)` ＋ null化で予防
- 影響範囲: `FundamentalHealthEvaluator::evaluate()` が5引数化 → 呼び出し元6機能（`TakeProfitThresholdEvaluator`／`NewCandidateFinder`／`ShowImportSummaryReportAction`／`ShowBuySignalListAction`／`ShowLossReviewListAction`）改修必須。`FundamentalIndicator::healthEvaluatorArgs()` を4→5要素化。判定チェックリスト（`SignalCriteriaEvaluator::fundamentalRows()`）が財務3→4項目、Bladeのテーブル固定幅 `w-[1440px]`→`w-[1512px]`。UC-011 は ADR-0010 D6 の反転フラグに営業利益率も乗せる。NISA推奨の追加基準は変更しない。**`NewCandidateFinder` の SQL事前絞り込みに `operating_margin>=10` を足さない**（NULL行がSQLで落ち evaluator の unavailable 判定に到達しなくなる）
- **実施タイミング**: F-011（`feat/f011-loss-review-list`）が `fundamentalRows()` と `SignalCriteriaEvaluatorTest` の `'total' => 3` アサート群を触っている最中のため、**F-011 マージ後に独立CRとして実装着手**。本セッションは Phase 0（ドキュメント＋ADR-0011）のみ、別ブランチ `feat/chg0012-operating-margin-criterion` で先行

### Files touched

**ドキュメント（Phase 0、本セッション）**: `docs/adr/ADR-0011-operating-margin-health-criterion.md`（新規、Status: Proposed）、`docs/architecture/data-model.md`（`fundamental_indicators` に `operating_margin` 行・US Mapper注記・「保留・確定が必要な初期パラメータ値」表4行〔財務健全性フィルタ／買い増し用／整理検討3→4項目／near バッファ〕・承認記録・変更履歴）、`docs/product/use-cases.md`（UC-001フロー7・UC-003フロー4・UC-004/010/011 判定チェックリスト財務3→4項目・UC-005/008/009 健全性フィルタ・`criteria`データ辞書・`fundamental_summary`例・承認記録）、`docs/product/requirements.md`（3章ファンダ指標一覧・F-010説明）、`docs/product/ui-guidelines.md`（チップ配色規約・サマリバッジ文言・固定件数記述・テーブル幅注記）、`docs/product/accuracy-improvement-backlog.md`（営業利益率の行を追加）、`docs/rcid/traceability-matrix.md`（CHG-0012行・F-004/009/010/011行に注記）、`PLAN.md`（本エントリ＋2エントリ退避）

**コード**: 未着手（F-011 マージ後）。実装ステップはプランファイル参照（migration → 両Mapper → `FundamentalHealthEvaluator` → 呼び出し元6機能 → `SignalCriteriaEvaluator` → Blade、Red→Gate4→Green→Refactor→`/review`）

### Status

Phase 0（ドキュメント先行）完了。Gate 2（use-cases.md）／Gate 3（data-model.md、`operating_margin` 列追加のマイグレーションを伴うため実質的な承認が必要）は**本人レビュー待ち**。実装は F-011 マージ後。

## 整理検討（含み損）候補一覧の新設（F-011・UC-011・ADR-0010・CHG-0010）（2026-09-05〜）

### Decision

- 本人要望: 「めちゃくちゃ損益が出ている銘柄を早く足切りしたい。足切りの踏ん切りをつけたい。そのための情報が分かる状況を作りたい。売買シグナル画面に第3テーブルを追加して」。`accuracy-improvement-backlog.md` の持ち越し項目「整理対象（損切り）候補一覧」（F-010完成後に着手判断、2026-09-05本人指示）そのもの
- Planフェーズ承認済み。プランファイル: `~/.claude/plans/stock_auto_order-loss-review-implementation-phase.md`
- 本人合意のスコープ（AskUserQuestionで確認）: (1) 一覧＋判断材料の提示のみ（分割売却の指値提案なし）、(2) UC-009サマリーレポート統合なし・DB変更なし（表示レイヤー完結、CHG-0006方式）、(3) 推定保有期間は観測以来の連続保有週数のみ（`holdings.acquired_on` 追加はしない）
- `requirements.md` 2章OUT「含み損銘柄の売却判断支援」を部分改訂（F-010がADR-0007で押し目買いに限りOUTを覆したのと同じ構図）。ADR-0010新規作成（Status: Accepted）
- 設計要点: `/signals` 最下部に第3セクション「整理検討（含み損）」。対象＝直近スナップショットの個別株で含み益率 ≤ -20%（叩き台）。ファンダ `failed` も押し目シグナル発生銘柄も**除外せず**透明マルチキーソートで順序制御（①押し目なし→あり ②`failed`→`unavailable`→`passed` ③整理該当数 ④含み損の深い順）。新規閾値3件のみ（-20% / 52週高値-30% / 押し目0件）、残りは既存閾値の可視化。新設 `ShowLossReviewListAction` / `LossReviewThresholds` / `ContinuousHoldingWeeksCalculator`、`SignalCriteriaEvaluator::evaluateLossReview()` 追加。既存 `ShowSignalListAction`/`ShowBuySignalListAction`/`signals`/`buy_signals`/`FetchExternalMarketDataAction` は不変
- Gate1（requirements.md 2章OUT改訂・F-011追加・7章）・Gate2（use-cases.md UC-011＋承認記録）・Gate3（data-model.md 初期パラメータ6行＋算出式4件＋承認記録、DBスキーマ変更なしのため影響範囲確認のみ）を本人が順に承認（2026-09-05）
- **並行作業との衝突（要調整）**: 別セッションが CHG-0011（`/signals` に評価額列を銘柄の右隣＝2列目に追加）を進行中。CHG-0011のドキュメント変更が同一作業ツリー・同一ブランチ（`feat/f011-loss-review-list`）に未コミットで混在している。CHG-0011は `signal-table-colgroup.blade.php`（ラベル列5→6）・両Action・共通テーブル部品を変更するため、F-011のBlade/Action実装はCHG-0011の確定後にその上に乗せる必要がある。両者とも「ドキュメント完了・コード未着手」段階

### Files touched

**ドキュメント**: `docs/adr/ADR-0010-loss-review-candidate-list.md`（新規）、`docs/product/requirements.md`（2章IN/OUT・4章F-011・7章）、`docs/product/use-cases.md`（UC一覧・UC-011節・承認記録）、`docs/architecture/data-model.md`（初期パラメータ表6行・分析ロジック計算仕様4件＋通貨単位注記・承認記録・変更履歴）、`docs/rcid/traceability-matrix.md`（F-011行・CHG-0010・F-004/F-010行に横断修正注記）、`docs/product/ui-guidelines.md`（3セクション構成・dangerバッジ配色）、`docs/product/accuracy-improvement-backlog.md`（持ち越し項目→着手）、`PLAN.md`（300行超過に伴い旧2エントリを `docs/history/plan-archive.md` へ退避）

**コード（新規）**: `app/Actions/Signal/ShowLossReviewListAction.php`、`app/Services/Analysis/LossReviewThresholds.php`、`app/Services/Portfolio/ContinuousHoldingWeeksCalculator.php`、`resources/views/components/loss-review-table-colgroup.blade.php`、`tests/Feature/UC011LossReviewListTest.php`（19件）、`tests/Feature/CriteriaChecklistUnitConsistencyTest.php`（9件・横断バグ再発防止）、`tests/Unit/Services/Portfolio/ContinuousHoldingWeeksCalculatorTest.php`（6件）

**コード（変更）**: `app/Services/Analysis/SignalCriteriaEvaluator.php`（`evaluateLossReview()`＋`indicatorComparablePrice()`追加、`fundamentalRows()` に反転フラグ追加〔2026-09-06 D6改訂〕、`evaluateTakeProfit()`/`evaluateBuy()` は無改修）、`resources/views/components/criteria-chip.blade.php`（`tone` prop 追加、整理テーブルは danger パレット）、`app/Services/Analysis/BuySignalDeterminationService.php`（`MIN_RELATIVE_STRENGTH` を public const 化）、`app/Actions/Signal/ShowSignalListAction.php`＋`app/Actions/Signal/ShowBuySignalListAction.php`（判定チェックリスト用 `current_price` をUSD割り戻し。**CHG-0011 と同ファイルだが別メソッド**）、`app/Livewire/Signal/SignalList.php`（`lossReviews` 1行追加）、`resources/views/livewire/signal/signal-list.blade.php`（末尾に第3セクション追記）、`tests/Unit/Services/Analysis/SignalCriteriaEvaluatorTest.php`＋`tests/Feature/SignalListTest.php`（追記）

### Status

Gate1/2/3/4 承認完了（2026-09-05）。**Green 完了**。
- F-011 本体: 新規 `ShowLossReviewListAction` ＋ 3 サービスクラス、`/signals` 最下部に第3セクション「整理検討（含み損）」。専用 colgroup で CHG-0011（評価額列）と切り分け。共有は `signal-list.blade.php`（末尾追記のみ）＋`SignalList.php`（1行）
- 横断バグ修正（本人承認済み案「A」）: F-011 実データ確認で、判定チェックリストの価格乖離チップが米国株で桁違い（円建て `current_price` vs USD建て `technical_indicators`）になる既存バグ（CHG-0007由来・CHG-0009で顕在化）を発見。`SignalCriteriaEvaluator::indicatorComparablePrice()` を新設し UC-004/UC-010/UC-011 横断で修正（判定チェックリスト用 `current_price` のみ `÷ fx_rate_used`）。`market_value`・分割指値・`recovery_required_rate` 等の円建て値は不変
- フルスイート **517 passed / 13 deprecated（既存・無関係）/ 0 failed**。pint クリーン
- `npm run build` 済み。実データ（保有134銘柄・含み損-20%超19件）で実ブラウザ確認: 第3セクション描画・sticky追従・met/near/unmet/unavailable の4状態・並び順（含み損の深い順）・注記表示・**米国株の乖離チップが修正後 -63.1% 等の正常値**を確認
- 既知の残課題（本タスク非スコープ）: `fundamental_summary` の成長率が near-zero base で `+69860.4%` 等の極端値になる（UC-004/UC-010/UC-008 共通のデータ品質問題、`accuracy-improvement-backlog.md` の EPS成長率と同種）

**設計リワーク（2026-09-06、ADR-0010 D6 改訂）**: 上記 Green 完了分は**旧契約**（判定チェックリストの財務3項目＝健全→`met`＝緑チップ、テクニカルと同じ緑）で実装されている。設計レビューで「1テーブル内にテクニカルの緑〔＝売り後押し〕と財務の緑〔＝保留材料〕が同居し、同じ色が正反対を意味する。サマリバッジが『合計◯/10＝売り確定』と誤読される」懸念が判明。本人合意のうえ **D6 を「赤の単一極性」に改訂**:
- 整理検討テーブルの criteria チップは達成＝赤系（`bg-red-100`）／あと一歩＝淡い赤／未達・データなし＝グレー。**緑は出さない**
- 財務健全性3項目は判定の向きを反転し「基準割れ（＝投資根拠の毀損）」を `met`（赤）とする。閾値の値（ROE 10%／自己資本比率 40%／成長率 0%）は既存流用のまま
- サマリ文言は「整理シグナル ◯/7」「投資根拠の毀損 ◯/3」（分子を合算しない）
- UC-004（利確検討）・UC-010（買い増し候補）テーブルの配色・`fundamentalRows()` 既定挙動は不変（反転はフラグ指定時のみ）

**ドキュメント CR 完了（2026-09-06）**: `docs/adr/ADR-0010-loss-review-candidate-list.md`（D5/D6/Rationale/採用しなかった代替案/Consequences）、`docs/architecture/data-model.md`（「整理検討の財務健全性3項目」行・承認記録・変更履歴）、`docs/product/use-cases.md`（UC-011 業務ルール「判定チェックリスト」節・承認記録）、`docs/product/ui-guidelines.md`（チップ配色規約・サマリバッジ）、`docs/rcid/traceability-matrix.md`（F-011行・CHG-0010行）を改訂。

**リワーク実装完了（2026-09-06、Gate4承認済み）**:
- Red: `test-writer` が `SignalCriteriaEvaluatorTest`（evaluateLossReview 節・`lossMetricsAllMet()`・コメント）と `SignalListTest`（UC-011 Livewire 節）を新契約に書き換え、13件 Red（アサーション不一致）。既存テストは無改変で Green 維持。Gate4 承認
- Green: `tdd-implementer` が実装。`SignalCriteriaEvaluator::fundamentalRows()` に `bool $forLossReview = false` 追加（true で ROE/自己資本比率 `lt`・成長率 `lte`・threshold_label 反転、閾値の値は `FundamentalHealthEvaluator` 流用）。`evaluateLossReview()` は `fundamentalRows($metrics, true)` 呼び出しに変更。`criteria-chip.blade.php` に `tone`（success 既定／danger）、`signal-criteria-cells.blade.php` に `tone` 転送、`signal-criteria-summary-badges.blade.php` に `variant="lossReview"`（文言「整理シグナル ◯/7」「投資根拠の毀損 ◯/3」・全該当色 `text-red-700`）。`signal-list.blade.php` 第3セクションのみ `tone="danger"` / `variant="lossReview"` 付与。`evaluateTakeProfit()`/`evaluateBuy()`・買い増し/利確セクションは不変
- フルスイート **531 passed / 13 deprecated（既存・無関係）**。pint クリーン。`npm run build` 済み（`app-Ck2oRFta.css`、赤系クラス生成確認）
- 実データ実ブラウザ確認（コンテナ内 Playwright、保有135・整理検討19件）: 第3セクションのチェックリストチップは **met＝赤98個・緑0個**、unmet＝グレー。財務3項目の反転を確認（JOBY: ROE -58.3%→赤 met／自己資本比率 78.5%→グレー unmet／成長率→グレー、サマリ「投資根拠の毀損 1/3」。SOFI 2/3、WIT 0/3）。サマリバッジ「整理シグナル N/7」「投資根拠の毀損 N/3」表示。3セクション共存で買い増し=緑・整理=赤が区別可能

**`/review` 実施（2026-09-06、normal レベル）**: MEDIUM 1件・LOW 3件・NIT 1件。
- **MEDIUM 修正済み**: 整理検討テーブルの `x-signal-table-head` 列グループ見出しが「判定チェックリスト（テクニカル）／（財務）」のままでサマリバッジ「整理シグナル／投資根拠の毀損」と語彙がずれていた。`signal-table-head.blade.php` に `variant` prop（既定 `signal`／`lossReview`）を追加し、整理検討テーブルは「判定チェックリスト（整理シグナル）／（投資根拠の毀損）」に。`SignalListTest` に見出しアサーション1件追加（Red→Green）。`ui-guidelines.md` 追記。フルスイート **532 passed**、pint・build クリーン、実データ確認済み
- **LOW 対応済み**: (2) `fundamentalSummary()` の `growthNote` を成長率ちょうど0%でも正しい「（成長率0%以下）」表記に修正（`UC011LossReviewListTest` に回帰テスト1件追加）。フルスイート **533 passed**
- **LOW 見送り**: (1) eager load 最適化はパフォーマンス影響小のため任意（別タスク候補）。(3) `docs/product/user-guide.md` はプロジェクト全体で未記入のテンプレート（UC-001〜UC-010 も全て未記載）のため、F-011 単独で節を足すと不整合。プロジェクト横断の別対応とする
- NIT: `evaluateLossReview()` docブロックの旧記述 → 修正済み

**コミット完了（2026-09-06、`b3d1793`、未push）**: ①**CHG-0010（F-011本体＋赤単一極性リワーク＋通貨単位横断バグ修正）**分のみを `git add -p` で選択ステージしてコミット（27ファイル）。作業ツリーに残る②**CHG-0011（評価額列 market_value）**・③**CHG-0009運用化（`market-data:refetch-us-fundamentals` コマンド）**は別セッションの未完了作業（②は `/review` 前）のため本セッションではコミットしない。混在ファイル（`ShowSignalListAction`/`ShowBuySignalListAction`/`signal-list.blade.php`/`SignalListTest.php`/`use-cases.md`/`traceability-matrix.md`/`PLAN.md`）は①分ハンクのみをコミット済み、②分ハンクは未ステージのまま残置。

UC-011 は閲覧系フローで `.claude/rules/31-e2e-testing.md` のクリティカルフロー対象外のため Playwright E2E は追加しない（UC-004/UC-009/CHG-0007 と同じ判断）。

**b3d1793 後の追加確認（2026-09-06、CHG-0011 引き取りセッション）**:
- `b3d1793` は既に `origin/feat/f011-loss-review-list` に push 済み（`c98165b` まで）。`traceability-matrix.md` の F-011 行を「完了」に更新（コミット済みだが `b3d1793` 時点では2行しか反映されず「リワーク中」表記が残っていた）→ CHG-0011 コミットに同梱。
- **今後の対応（低優先・先送り）**: `ShowLossReviewListAction::also_on_buy_list` は `$reboundPresent && fundamental_status !== 'failed'` で判定しており、`ShowBuySignalListAction::isEligible()` の「利確シグナル（`signals`）同時成立銘柄を除外」条件（`signals->isNotEmpty()`）を再現していない。含み損-20%超の銘柄が利確シグナル（通常 +20%超が条件）を持つのは異常/古いデータのケースのみで到達性は低い。修正するには `ShowLossReviewListAction` の eager load に `signals` 追加（N+1回避）が必要。次に F-011 に手を入れる際に解消する。
- **今後の対応（軽微・cosmetic）**: 整理検討テーブルのヘッダー用/本文用 `<table>` の `scrollWidth` が 1441 vs 1452（12px差）。`getBoundingClientRect().width` は両方 1441 で実描画・列整列は一致しており視認上の崩れはない（`known-pitfalls.md`「table-fixed + 折返し不可ラベル」と同種の軽微な内容オーバーフロー）。実害が出たら本文側の長いラベル/バッジの `break-words` を見直す。

## 売買シグナル画面「評価額」列追加（CHG-0011）＋米国株ファンダのDB補完（2026-09-05〜）

### Decision

- ユーザー要望2点:
  1. 売買シグナル画面（利確検討 UC-004 / 買い増し候補 UC-010）の左の方に、各行の「実際の評価額」（保有数量 × 現在値、CSV由来の円換算値）を表示したい
  2. ADR-0009（CHG-0009、Finnhub）で米国株ファンダ取得コードは実装済みだが、既存の最新スナップショット（batch 135）の米国株44銘柄は `fundamental_indicators` が0件で画面上「取得不可」のまま。Finnhubから実取得してDB補完し画面に実数を出したい
- ユーザー確認済みの決定:
  - 評価額列の位置 = 銘柄の右隣（2列目）。銘柄セルの `sticky left-0` は据え置き、評価額は通常列
  - 米国株補完 = `FetchExternalMarketDataAction` 本体は変更せず、米国株ファンダのみ再取得する軽量 artisan コマンドを新設（US分岐ロジックの一部重複は許容）
- CR番号: CHG-0011（評価額列。CHG-0010 は ADR-0010 で使用済み）。US補完コマンドは ADR-0009 の運用化のため新規CR/ADR不要
- 評価額 = `quantity * current_price`（シグナル画面は stock のみ対象なので投信の ÷10000 補正は不要）。US株は取込時に円換算済みのため円建て

### Files touched（予定）

- ドキュメント（先行）: `docs/product/use-cases.md`（UC-004/UC-010 出力表 + 変更履歴 CHG-0011）, `docs/rcid/traceability-matrix.md`, `docs/ai-context/common-commands.md`
- Part A（CHG-0011・表示のみ）: `app/Actions/Signal/ShowSignalListAction.php`, `app/Actions/Signal/ShowBuySignalListAction.php`, `resources/views/components/signal-table-colgroup.blade.php`, `resources/views/livewire/signal/signal-list.blade.php`, テスト（`tests/Feature/UC004SignalListTest.php` / `tests/Feature/UC010BuySignalListTest.php` / `tests/Feature/SignalListTest.php`）
- Part B: `app/Console/Commands/RefetchUsFundamentalsCommand.php`（新規）, `tests/Feature/RefetchUsFundamentalsCommandTest.php`（新規）
- スコープ外: `FetchExternalMarketDataAction` 本体, UC-003 銘柄詳細, `financial_statements` への米国株保存, JP株の再取得, DBマイグレーション

### Status

- ドキュメントCR反映（Gate2）: 完了（2026-09-05）
- Part A（評価額列）: Red → Gate4承認 → Green 完了。`ShowSignalListAction`/`ShowBuySignalListAction` に `market_value` 追加、`signal-table-colgroup` に列追加、`signal-list.blade.php` に「評価額」列（銘柄の右隣・`number_format(...)円`・幅 w-[1406px]）追加。テスト5件追加、フルスイート465件 Green
- Part B（`market-data:refetch-us-fundamentals`）: Red → Gate4承認 → Green 完了。`app/Console/Commands/RefetchUsFundamentalsCommand.php` 新規。テスト5件追加
- 実データ実行: `docker compose exec -T laravel.test php artisan market-data:refetch-us-fundamentals` → 44件更新 / 0件失敗。売買シグナル画面の米国株が実数のファンダ表示に切り替わったことを `ShowSignalListAction` 実データ出力で確認（利確検討US13件が「—」から ROE%/自己資本比率%/成長% 表示へ。ARM/ASML/GOOG/TSM 等は `financials-reported` に us-gaap Assets/Equity が無く自己資本比率のみ「—」＝ADR-0009 のフォールバック通り）
- `/review`（2026-09-06、別セッションが引き取り、medium レベル）: **CHG-0011 自体は指摘なし**（`market_value` の投信 ÷10000 補正欠落＝両リスト stock 絞り込み済みで誤検知、colgroup/ラベル列数一致、等いずれも verify で refute）。PLAUSIBLE 1件は CHG-0011 ではなくコミット済み F-011 の `ShowLossReviewListAction::also_on_buy_list` が「利確シグナル同時成立銘柄」の除外を再現しない点（到達性低・下記「今後の対応」に記録し先送り）
- `npm run build` 済み（`app-Ck2oRFta.css`）。実データ実ブラウザ再確認（保有135）: 買い増し候補・利確検討テーブルの2列目に評価額（`13,740円` 等・右寄せ）が正しく表示、レイアウト崩れなし
- コミット（2026-09-06、本セッション）: Part A + Part B + 付随ドキュメント（`use-cases.md` の `market_value` 行・承認記録、`traceability-matrix.md` の CHG-0011 追跡行＋F-004/F-010/F-011 行更新、`common-commands.md`）＋ PLAN.md 300行超過対応（Phase7 の2エントリを `plan-archive.md` へ退避）をまとめてコミット
- プランファイル: `~/.claude/plans/stock_auto_order-signal-market-value-and-us-fundamentals-implementation-phase.md`

## 米国株ファンダメンタルズ指標データソースとしてFinnhub採用（CHG-0009）Gate2/3承認完了（2026-09-05〜）

### Decision

- ユーザー要望: 米国株のファンダメンタルズ指標が取得困難（J-Quantsは日本株専用のため、ADR-0004/ADR-0007以来ずっと`unavailable`固定）な状態を解消し、日本株と対等に比較できるようにしたい
- 候補データソース（Alpha Vantage/Financial Modeling Prep/Finnhub/Twelve Data）を調査し、Finnhubを最有力候補として提案。実際にAPIキーを発行してもらい（`.env`の`FINNHUB_API_KEY`、`.gitignore`対象・確認済み）、`docker compose exec laravel.test php artisan tinker`から実HTTPで疎通確認した:
  - `stock/metric`（`metric=all`）でAAPLを実際に取得し、PER(`peTTM`)・PBR(`pbAnnual`)・ROE(`roeTTM`)・売上高成長率(`revenueGrowthTTMYoy`)・EPS成長率(`epsGrowthTTMYoy`)・配当利回り(`dividendYieldIndicatedAnnual`)・配当性向(`payoutRatioTTM`)・PEGレシオ(`pegTTM`)が無料枠（60リクエスト/分）で取得できることを確認
  - 自己資本比率は当初`totalDebt/totalEquity`からの近似（`1/(1+D/E)`）を検討したが、AAPL/ACN/AMD/AMZN/ACHRの5銘柄で実測値と比較したところ最大約2倍の過大評価（AAPL: 実測20.52% vs 近似42.47%）が判明し不採用。代わりに`stock/financials-reported`（10-KのXBRL実データ、無料枠でアクセス可）から総資産・自己資本の実額を取得し実測計算する方式に変更。AAPLの実測値はSEC提出10-Kの公表値（Web検索で確認）と完全一致し、貸借対照表の内部整合性（資産＝負債＋資本）も一致することを確認済み
  - 営業利益成長率もFinnhubの`metric`に直接のYoYフィールドが無いため、同じく`financials-reported`の`ic`セクション（`us-gaap_OperatingIncomeLoss`）を直近期・前期で比較して算出する方式とした（16期分のデータが取得できることを確認済み）
- ユーザー承認: 対象8指標（PER・PBR・ROE・売上高/営業利益成長率・自己資本比率・配当利回り/性向・EPS成長率・PEGレシオ）を段階導入ではなく一括で実数値化する
- `docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md`を新規作成（`/adr`スキル使用、Status: Accepted）。JP用`FundamentalIndicatorMapper`とは入力形状が根本的に異なるため、新規`UsFundamentalIndicatorMapper`（仮称）を別クラスとして並立させる設計方針とし、既存のJP側実装・テストは無改修とする方針を明記
- Gate2（`use-cases.md` UC-001フロー7・UC-003/UC-004/UC-010のファンダメンタルズ記述、CHG-0009承認記録）・Gate3（`data-model.md` `fundamental_indicators`節、自己資本比率/営業利益成長率の算出方法を「実装完了」注記として追記）・`traceability-matrix.md`（CHG-0009）・`docs/ai-context/do-not-touch.md`「外部連携」節（Finnhub APIキーの扱い）を更新・承認済み。`fundamental_indicators`テーブルは市場非依存の既存スキーマのままでDBマイグレーション不要（Gate3は影響範囲確認のみ）

### Files touched

`.env.example`（`FINNHUB_API_KEY`プレースホルダ追加）、`docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md`（新規）、`docs/product/use-cases.md`（UC-001/UC-003/UC-004/UC-010の記述改訂・承認記録追加）、`docs/architecture/data-model.md`（`fundamental_indicators`節・承認記録追加）、`docs/rcid/traceability-matrix.md`（CHG-0009）、`docs/ai-context/do-not-touch.md`（Finnhub APIキー追記）、`PLAN.md`（本エントリ追加、300行超過に伴い「UC-010 Gate4完了・コミット」エントリを`docs/history/plan-archive.md`へ退避）

### Status

Gate2/Gate3承認完了。DBスキーマ変更なし。次はGate4（`/tdd`のRed→Green→Refactorサイクル）で`UsFundamentalIndicatorMapper`（仮称）・Finnhub用HTTPクライアント（レート制限の自己スロットリング・429リトライ含む）・`FetchExternalMarketDataAction`への組み込みを実装する。未着手・未コミット。

## 取込後サマリーレポートのグローバルナビタブ化（CHG-0008）完了（2026-09-05）

### Decision

- ユーザー要望: 取込後サマリーレポート（UC-009）は取込直後のリダイレクトでしか見られず、他画面へ移動すると取込バッチIDを知らない限り戻れない。「最新レポートだけでいいので、いつでも見られるようタブを作ってほしい」との依頼
- Planフェーズで承認。設計（プランファイル: `~/.claude/plans/stock_auto_order-latest-summary-report-tab-implementation-phase.md`）:
  - 新規ルート`GET /summary-report`（`app/Livewire/ImportSummaryReport/Latest.php`）を追加し、グローバルナビの右端（CSV取込の後、6タブ目）に「サマリーレポート」タブを新設
  - 中身はスナップショットを持つ**最新の取込バッチ**を`ImportBatch::query()->whereHas('snapshot')->orderByDesc('imported_at')->orderByDesc('id')->first()`で特定し、既存`ShowImportSummaryReportAction`をそのまま呼んで毎回再計算（過去バッチの履歴閲覧は対象外）。DBスキーマ変更なし
  - 既存`show.blade.php`のヘッドライン・上位10件・補足レコメンドの描画部を`resources/views/components/summary-report-body.blade.php`に切り出し、新旧2画面（取込直後リダイレクト先／恒常タブ）で共有。既存の`ImportSummaryReportShowTest`・`UC009ImportSummaryReportTest`・`CsvImportUploadTest`は無改変のままGreenを維持し、出力が変わっていないことを担保
  - 取込バッチが1件も無い場合は「まだCSVの取込がありません」＋CSV取込導線を表示
  - 取込直後リダイレクト先（`/import-batches/{id}/summary-report`）でも「サマリーレポート」タブがハイライトされるよう`Show.php`にも`active`指定を追加。あわせて両画面に取込日時のキャプション（`$importedAtLabel`）を追加
- Gate2（`docs/product/use-cases.md` UC-009フロー7・業務ルール「タブからの再表示」・エラーケース）・`ui-guidelines.md`（5タブ→6タブ）・`traceability-matrix.md`（CHG-0008）を先に更新・承認。Gate3はDBスキーマ変更が無いため対象外
- `test-writer`がRedフェーズで新規`ImportSummaryReportLatestTest.php`6件（最新バッチ選択・古いバッチ非表示・スナップショット無し失敗バッチのスキップ・空状態＋Action不実行・Action1回のみ呼び出し・未認証リダイレクト）＋`LayoutTest.php`にナビ回帰1件を作成。7件Red確認しGate4承認
- `tdd-implementer`がGreenフェーズを実装。対象7件・関連する既存3ファイル（`ImportSummaryReportShowTest`/`UC009ImportSummaryReportTest`/`CsvImportUploadTest`、計37件）無改変Green・フルスイート422件Green
- 実データ（保有134銘柄・取込バッチ3件）で実HTTP確認: Playwright MCPが接続不能だったため、`artisan tinker`から実際のセッションCookie（`CookieValuePrefix`＋`Crypt`でLaravelの暗号化Cookieを再現）を発行し、実行中のDockerコンテナへ本物のHTTP経由でアクセスして検証。(1) 全画面のナビ右端に「サマリーレポート」タブが表示される、(2) `/summary-report`で最新バッチ（id=133）のレポート（おすすめ上位10件含む）が表示されタブがハイライトされる、(3) `/import-batches/133/summary-report`（取込直後リダイレクト先）でも同タブがハイライトされる、(4) 応答にエラーマーカーなし、を確認。検証用に作成した一時セッション行は削除済み
- UC-009タブ化は一覧→詳細遷移と同種の標準的な閲覧フローであり、`.claude/rules/31-e2e-testing.md`が対象とする「クリティカルフロー」に該当しないと判断しPlaywright E2Eテストは追加しない（UC-004のE2E見送り判断と同一の考え方）

### Files touched

`app/Livewire/ImportSummaryReport/Latest.php`（新規）、`app/Livewire/ImportSummaryReport/Show.php`（`active`指定・`importedAtLabel`追加）、`resources/views/components/summary-report-body.blade.php`（新規、描画部の移設）、`resources/views/livewire/import-summary-report/latest.blade.php`（新規）、`resources/views/livewire/import-summary-report/show.blade.php`（共通部品呼び出しへ置換・キャプション追加）、`resources/views/components/layouts/app.blade.php`（ナビタブ追加）、`routes/web.php`（`/summary-report`ルート追加）、`docs/product/use-cases.md`（UC-009フロー7・業務ルール・エラーケース・承認記録）、`docs/product/ui-guidelines.md`（6タブ化）、`docs/rcid/traceability-matrix.md`（CHG-0008）、`tests/Feature/ImportSummaryReportLatestTest.php`（新規、6件）、`tests/Feature/LayoutTest.php`（回帰テスト1件追加）、`PLAN.md`（本エントリ追加、300行超過に伴い「フロントエンド実装Phase3」エントリを`docs/history/plan-archive.md`へ退避）

### Status

Gate4完了（Red→Gate4承認→Green→`/review`→修正）。フルスイート422件Green（13 deprecatedは既存・無関係）。pint適用済み・実HTTP確認済み。`/review`でFat Livewireコンポーネント指摘（`Latest::mount()`への「最新バッチ」クエリ直書き）を修正（`ImportBatch::scopeLatestWithSnapshot()`に切り出し）。コミット済み（`bf3c0da`、`c43e1a2`、いずれも未push）。

## 売買シグナル画面 判定チェックリスト表示（CHG-0007）（2026-08-29〜）

### Decision

- ユーザー要望: `/signals`（利確検討・買い増し候補の両セクション）で、良し悪しの判断根拠を「基準点 × その銘柄の実測値 × 達成状態」の形で一覧に出したい。達成は緑、基準の8割まで来ていれば達成より淡い緑、で見づらくならない程度に。ファンダメンタルズ・財務健全性・シグナル基準から大事な項目を過不足なくピックアップ。横に長くなるのは許容
- Planフェーズで承認。設計（プランファイル: `~/.claude/plans/stock_auto_order-signal-criteria-panel-implementation-phase.md`）:
  - 各銘柄行の直下にフル幅サブ行（`<tr><td colspan>`）で判定チェックリストを敷く（列は増やさない）
  - **テクニカル7項目**（利確: 含み益率>ライン/RSI≧70/52週高値下落率≦-10%/BB上限乖離≧0%/MACD−シグナル線<0/PEG≧2.0/相対力<0。買い増し: RSI≦30/52週安値距離≦+10%/BB下限乖離≦0%/MACD−シグナル線>0/MA20乖離≦-10%/PEG≦1.0/出来高倍率≧1.5）＋ **財務健全性3項目**（ROE≧10%/自己資本比率≧40%/成長率>0%）を別グループで表示・別集計
  - 基準値は既存の確定済みシグナル判定閾値・`FundamentalHealthEvaluator`の閾値をそのまま可視化（新設なし）。`near`（あと一歩）= 基準値の±20%手前、基準値0の項目は達成/未達の2値（`data-model.md`で新規確定）
  - 新設 `SignalCriteriaEvaluator`（表示専用の純粋計算クラス）＋ Bladeコンポーネント2つ（`criteria-chip`/`criteria-panel`）。`SignalDeterminationService`/`BuySignalDeterminationService`/`FundamentalHealthEvaluator`のハードコード閾値を`public const`に抽出して共有（CHG-0005型の二重管理を防ぐ。判定ロジックは不変）
  - 両Actionに`holding.technicalIndicator`のEager Load追加（CHG-0006のN+1回帰と同じ轍を踏まない）
  - DBスキーマ変更なし
- Gate2（use-cases.md UC-004/UC-010）・Gate3（data-model.md `near`バッファ）・`ui-guidelines.md`（「一覧に全指標の内訳を出さない」方針の例外化＋チップ配色規約）・traceability-matrix.md（CHG-0007）を先に更新済み

### Files touched

`app/Services/Analysis/SignalCriteriaEvaluator.php`（新規）、`app/Services/Analysis/SignalDeterminationService.php`（閾値をpublic const化）、`app/Services/Analysis/BuySignalDeterminationService.php`（同）、`app/Services/Analysis/FundamentalHealthEvaluator.php`（同）、`app/Actions/Signal/ShowSignalListAction.php`、`app/Actions/Signal/ShowBuySignalListAction.php`、`resources/views/components/criteria-chip.blade.php`（新規、サイズ縮小のため後日修正）、`resources/views/components/signal-table-colgroup.blade.php`（新規）、`resources/views/components/signal-table-head.blade.php`（新規）、`resources/views/components/signal-criteria-cells.blade.php`（新規）、`resources/views/components/signal-criteria-summary-badges.blade.php`（新規）、`resources/views/livewire/signal/signal-list.blade.php`、`docs/product/use-cases.md`、`docs/architecture/data-model.md`、`docs/product/ui-guidelines.md`、`docs/rcid/traceability-matrix.md`、`tests/Unit/Services/Analysis/SignalCriteriaEvaluatorTest.php`（新規）、`tests/Feature/UC004SignalListTest.php`、`tests/Feature/UC010BuySignalListTest.php`、`tests/Feature/SignalListTest.php`、`.claude/skills/verify/SKILL.md`（Tailwindリビルド必須の注記・Playwright MCP不通時のcurlログインfallback手順を追記）、`docs/ai-context/known-pitfalls.md`（Tailwind CSS v4の同様の記録を追記）、`PLAN.md`（本エントリ）

### Status

Gate4承認・Green実装完了（フルスイート73件Green、うちSignalCriteriaEvaluatorTest 16件・UC004/UC010/SignalList各Feature Test追加分含む）。Green完了後、実画面レビューで判定チェックリストのレイアウトをユーザーフィードバックに基づき2段階で改訂:
1. 当初実装（`resources/views/components/criteria-panel.blade.php`を新設し、銘柄行直下のフル幅サブ行に1個のパネルとして配置）は1銘柄=2行になり視認しづらいとの指摘で、パネルを銘柄行の末尾に1列で集約する1銘柄=1行構成に変更
2. その1列集約案も、列内でチップが折り返され銘柄ごとに折返し位置がずれて見づらいとの追加指摘で、**チップ1項目=テーブル1列**に分解する最終形に変更。`criteria-panel.blade.php`は不要になったため削除し、`criteria-chip.blade.php`を`signal-list.blade.php`から直接、2段ヘッダー（グループ`colspan`＋項目ラベル）付きで列ごとに呼び出す構成に変更
`ui-guidelines.md`のCHG-0007該当箇所を最終形に合わせて更新済み。フルスイート422件Green再確認。
- 実データ（保有134銘柄・シグナル187件）で実HTTP確認: Playwright MCPが接続不能だったため、CHG-0008と同じ手法（`artisan tinker`で実セッションCookieを発行し実行中コンテナへ本物のHTTP経由でアクセス）で検証。最終形（チップ1項目=1列、2段ヘッダー）が意図通り描画され、met（緑濃）/near（緑薄）/unmet（グレー）/unavailable（薄グレー、値`—`）の4状態が実データで正しく出現することを確認。検証用の一時セッション行は削除済み
- `/review`（medium）で2件判明、両方修正: (1) **確定バグ**: 「相対力(対市場)」チップが基準0に対し`lte`（≤0）で判定しており、`SignalDeterminationService::determineRelativeStrengthWeakening()`の厳密な`<0`判定と境界値0.0で食い違っていた（Red時点のテスト仕様コメントは`<0`と明記済みだったが、Green実装が`lte`を誤って流用）。`SignalCriteriaEvaluator::classify()`に`lt`（厳密未満）方向を追加し、当該項目のみ`lt`＋ラベル`<0`に修正。(2) **効率**: `evaluateTakeProfit()`/`evaluateBuy()`がそれぞれ`fundamentalRows()`を2回呼んでいたのをローカル変数に一度だけ格納する形に修正。修正後フルスイート422件Green再確認・実HTTP確認で反映を再確認（相対力チップの基準ラベルが`<0`表示に変わり、境界値-6.8等が正しくmet判定）・pint適用済み
- UC-004/UC-010の一覧→チェックリスト表示は`.claude/rules/31-e2e-testing.md`が対象とする「クリティカルフロー」に該当しないと判断しPlaywright E2Eテストは追加しない（UC-004本編・UC-009タブ化と同一の考え方）

**実画面確認（2026-09-05）**: 上記コミット準備が整った後、ユーザーから「デザイン崩れが激しい」と報告あり、Playwright MCPで確認しようとしたが本セッションでは接続不通（`CONNECT_TIMEOUT`）、コンテナ内に`chromium-cli`・ブラウザ・表示系フォールバックも無し。代わりに`verify`スキルへ追記した手順で、Livewireのログイン画面（`wire:submit`コンポーネント）に対して実際のLivewire update AJAXプロトコルをcurl+pythonで再現してログインし、本物のセッションCookieで`/signals`の実HTMLを取得して確認した。
- 判明した実際の原因: `compose.yaml`にVite dev serverが無く、CSSは`npm run build`による静的ビルド。Tailwind v4はビルド時点でBladeをスキャンするJIT方式のため、今回のレイアウト変更で新規に使い始めた`overflow-x-auto`・`whitespace-nowrap`クラスが、直近のビルド済みCSS（ビルド日時がこの変更より前）に含まれておらず、テーブルの横スクロール・ヘッダーの折返し防止が効かないまま配信されていた（`php artisan test`はコンパイル済みCSSを見ないため検出不可）
- 対処: `docker compose exec laravel.test bash -c "cd /var/www/html && npm run build"`でCSSを再ビルド（`app-C9OyCJfb.css`43KB→`app-Czb6vJjq.css`62KB、両クラスの定義を確認）。取得した実HTMLをパースし、両テーブルとも2段ヘッダーの実効列数（5+7+3=15）とtbody各行（買い増し9行・利確48行）のcolspan合計が全て一致することを確認済み（構造上の崩れは無し）。再ビルド後にフルスイート422件Green再確認
- 再発防止として`docs/ai-context/known-pitfalls.md`に本件を記録し、`verify`スキルに「Blade変更時は`npm run build`必須」「Playwright不通時のcurlログインfallback手順」を追記

**表フォーマットの統一・固定表示・縮小（2026-09-05）**: 続けてユーザーから「利確検討と買い増し候補のフォーマットが異なる」「ヘッダー・銘柄名を固定表示にしてほしい」「セル全体を10〜20%程度縮小して画面に収まりやすくしてほしい」と依頼あり
- 原因分析: 2表は列数・列順は同じだが列ごとの内容（財務健全性／理由サマリ等）が異なり、個別にBladeを手書きしていたため対応列の実測幅（ブラウザの自動レイアウト）が表ごとにずれていた
- 対処: `<colgroup>`（`resources/views/components/signal-table-colgroup.blade.php`、新規）・2段ヘッダー（`signal-table-head.blade.php`、新規）・判定チェックリストの`<td>`群（`signal-criteria-cells.blade.php`、新規）を共通コンポーネント化し、両テーブルが完全に同一の列幅定義を共有する構成に変更（`table-fixed`＋`colgroup`で列幅を内容量に依存させず固定）。取得した実HTMLで両テーブルの`colgroup`が完全一致することを確認済み
- 固定表示: `<thead>`に`sticky top-0 z-20 bg-surface`、銘柄列（ヘッダー・本文とも）に`sticky left-0`を付与し、縦スクロールでヘッダーが、横スクロールで銘柄名列が常に見える状態にした
- 縮小: 表全体`text-[13px]`→`text-[11px]`、セルpadding`py-2 px-2`→`py-1.5 px-1.5`、`criteria-chip`の固定`min-w-[88px]`を廃止しセル幅（`w-[72px]`）に追従させ内部フォントも1段階縮小。列幅固定に伴い長いシグナル種別名がはみ出さないよう`[&_td]:break-words`を追加
- `docs/product/ui-guidelines.md`のCHG-0007該当箇所に本改訂を追記。再ビルド後、実データで両テーブルの`colgroup`一致・列数一致（構造崩れ無し）を確認、フルスイート422件Green再確認

**達成数サマリの復活・固定表示の実効化（2026-09-05）**: ユーザーが実ブラウザのスクリーンショットで確認したところ2点の追加指摘: (1) 判定チェックリストを横スクロールすると達成数（テクニカル/財務それぞれ何項目達成か）が分からなくなる、(2) ヘッダー行（銘柄・含み益率等のラベル行）が縦スクロールで固定されていない
- (1)への対処: 一度「列見出しと冗長」として省略した「◯/N 達成」テキストサマリを`x-signal-criteria-summary-badges`（新規）として復活。判定チェックリスト列側ではなく、横スクロールしても常に見える**銘柄セル（sticky left-0）内・銘柄名の直下**に「技術 3/7」「財務 2/3」の2行で表示し、達成率に応じて簡易配色（全達成=緑／0件=グレー／それ以外=amber）。値は既に`criteria.summary`としてAction側で計算済みだったため追加計算は不要
- (2)への原因調査: 実HTMLには`sticky top-0`のクラス自体は正しく出力されており、静的HTML確認だけでは検出できない**実ブラウザのレンダリング挙動の不具合**だった。原因はテーブルの横スクロール用ラッパー`<div class="overflow-x-auto">`が`overflow-y`を指定していなかったこと。CSS仕様上、`overflow-x`と`overflow-y`の片方が`visible`でもう片方がそうでない場合は両方`auto`に補正されるルールがあり、このdivが（実際は縦オーバーフローしないにも関わらず）縦スクロールの祖先要素とみなされてしまい、`position: sticky`の基準がページではなくこのdivになって効かなくなっていた
- (2)への対処（1回目、誤り）: ラッパーに`overflow-y-hidden`を追加（`overflow-x-auto overflow-y-hidden`）し補正ルールの発動条件を外せば解消すると考えたが、ユーザーが実ブラウザで再確認したところ「まだヘッダーが固定されない」と再度指摘があり誤りと判明
- **原因の再調査と正しい対処**: `overflow: hidden`は`auto`と同様それ自体がスクロールコンテナを成立させる値であり、`visible`から`hidden`に変えても「このdivが`sticky`の基準になってしまう」問題自体は解消していなかった（`visible`⇄`auto`の補正ルールは事実だが、「`visible`以外なら何でも良い」という結論部分が誤りだった）。正しくは`overflow-y-clip`（`overflow-y: clip`）を使う必要がある。`clip`は`hidden`と異なりスクロールコンテナを一切成立させない仕様上明確に区別された値で、`overflow-x-auto overflow-y-clip`とすることで水平方向は実際にスクロールコンテナとして機能しつつ、垂直方向は`sticky`の基準として無視されページ本体まで正しく伝播する。修正後、再ビルドし実データ取得したHTMLで両ラッパーのクラスが`overflow-x-auto overflow-y-clip`になっていること・コンパイル済みCSSに`.overflow-y-clip{overflow-y:clip}`が生成されていることを確認
- `docs/product/ui-guidelines.md`・`docs/ai-context/known-pitfalls.md`を`hidden`ではなく`clip`が正しい理由込みで訂正・追記。フルスイート422件Green再確認
- **さらにユーザーから「まだ直っていなそう」と3度目の指摘**。`overflow-y-clip`も実ブラウザでは効果がなかった（後述の通り根本原因の理解自体が誤りだった）。この時点で理論だけで直すのをやめ、実ブラウザでの検証手段を確保する方針に切替: Sailコンテナ内で`npx playwright install chromium`を実行したところ実際にChromiumをダウンロード・インストールでき（`storage/app/pw-scratch/`に`npm install playwright`し、Node.jsスクリプトから実際にログイン・スクロール・スクリーンショット取得が可能になった。Playwright MCP接続不可の際の恒久的な代替手段として`.claude/skills/verify/SKILL.md`に手順を追記
- **実ブラウザでの計測により判明した真因**: `getComputedStyle()`で確認すると、`overflow-y-clip`を指定していたにも関わらず実際の計算値は`"hidden"`だった。CSS仕様上「`overflow-x`/`overflow-y`の片方が`clip`でもう片方が`visible`でも`clip`でもない場合、`clip`側は`hidden`に補正される」という追加ルールがあり、`overflow-x: auto`と組み合わせた時点でこの補正が発動していた。**`overflow-x: auto`な要素は、`overflow-y`の値を`hidden`/`auto`/`clip`のどれにしても必ずそれ自体がスクロールコンテナになり、子孫の`sticky`の基準がページ本体ではなくこの要素になってしまう**——CSSの`overflow`プロパティだけでは「横スクロールは本物のスクロールコンテナ」かつ「縦方向はスクロールコンテナにしない」を同一要素上で両立できないという構造的な限界だった
- **最終対処（構造変更）**: 1個の`<table>`に固執するのをやめ、**ヘッダー用（`<colgroup>`+`<thead>`のみ）と本文用（`<colgroup>`+`<tbody>`のみ）で`<table>`を2つに分割**。ヘッダー用`<table>`を包むdiv自身に`overflow-x-auto`と`sticky top-0`を両方付与（`sticky`は「このdivの祖先」を基準に解決されるため、div自身がスクロールコンテナであることとは無関係にページ本体への固定が効く）。本文用`<table>`は別の`overflow-x-auto`なdivに入れ`sticky`は付けない。2つのdivの横スクロール位置を同期する数行のJS（`resources/js/app.js`新規、`data-scroll-sync-with`属性で対象指定、`livewire:navigated`で初期化）を追加。ヘッダー側の重複する横スクロールバーは`[scrollbar-width:none]`等で視覚的に隠した
- **この構造変更に伴い連鎖的に発覚・修正した2つの不具合**（実ブラウザでの計測で発見。静的HTML確認では検出不可能だった）:
  1. `table-fixed`に付けていた`w-max`（`width: max-content`）が、折り返せない長いヘッダーラベル（`52週安値からの距離`等、`whitespace-nowrap`付き）を持つ列だけ`<colgroup>`指定幅を無視して広げてしまい、ヘッダー用・本文用の実際の描画幅が食い違っていた（例: 1446px vs 1350px）。テーブルの`width`を`<colgroup>`合計値と一致する具体的なpx値（`w-[1296px]`）に変更し、ヘッダー側の`whitespace-nowrap`も本文側と同じ`break-words`に統一して解消。修正後、両`<table>`の`scrollWidth`が1297pxで完全一致することを実測確認
  2. `<x-badge>`（`inline-block`、共有コンポーネント）内の折り返せない1単語のシグナル種別名（`week52_high_pullback`等）が、親`<td>`の`break-words`だけでは折り返されずセル幅（130px）を超えて（151px）隣接要素と視覚的に重なっていた。`overflow-wrap`は継承されるが`inline-block`自身の「内容で幅が決まる」性質までは変えないため。`badge.blade.php`自体に`max-w-full break-words`を追加（他画面で使う短いテキストには無害）し解消。修正後117px（130px以内）に収まることを実測確認
- 上記全てを実際にPlaywrightで`/signals`にログイン・スクロールしてスクリーンショットで最終確認（ページ最上部・買い増し候補ヘッダー固定中・利確検討ヘッダーへの引き継ぎ後の3枚、ユーザーにも送付）。`docs/ai-context/known-pitfalls.md`に3件の不具合（sticky構造上の限界／table-fixed+w-maxの幅食い違い／inline-blockの折り返し）、`docs/product/ui-guidelines.md`のCHG-0007該当箇所を最終構造に合わせて全面的に訂正、`verify`スキルにコンテナ内Playwrightのセットアップ手順を追記。検証用の`storage/app/pw-scratch/`（Chromiumバイナリ・node_modules）は削除済み。フルスイート422件Green再確認
- 次: `/review` → コミット（push禁止）

## 利確検討ラインの動的分岐（CHG-0006）実装完了（2026-08-28〜29）

### Decision

- 「【検討事項・未着手】利確・リバランス閾値の動的分岐ロジック検討」（2026-08-22記録）をPlanモードで具体化し、ユーザー承認を得た: 「現在シグナル0件」かつ「`FundamentalHealthEvaluator`が`passed`」の銘柄のみ「高水準モード」（対象抽出+150%超、分割指値+100%/+150%）を適用し、それ以外は「通常モード」（従来の+20%/+35%）のまま。閾値の具体値（+100%/+150%）は検討メモの例をそのまま採用
- 判定は表示・集計レイヤー（`ShowSignalListAction`・`ShowImportSummaryReportAction`）のみで完結させ、`FetchExternalMarketDataAction`のシグナル判定・永続化条件（含み益+20%超）は変更しない設計とし、UC-010（買い増しレコメンド）への影響を設計時点で排除した
- use-cases.md（UC-004/UC-009業務ルール改訂）・data-model.md（初期パラメータ表）・traceability-matrix.md（CHG-0006）を先に整備しGate2/3承認
- `test-writer`がRedフェーズで新規`TakeProfitThresholdEvaluatorTest`（7件）＋`UC004SignalListTest`/`UC009ImportSummaryReportTest`への追加テストを作成。10件Red・44件Green確認しGate4承認
- `tdd-implementer`がGreenフェーズを実装: 新規`TakeProfitThresholdEvaluator`（シグナル数0件を先にショートサーキットし、0件のときのみ財務健全性を評価）、両Actionへの組み込み。対象54件・フルスイート388件Green
- 実データ（134銘柄）で実ブラウザ確認: 含み益94%・シグナル0件・財務健全な銘柄（6098等）が高水準モード適用により`/signals`・サマリーレポート双方から正しく除外されることを確認（サマリーレポートの候補数が54→52件に減少）
- `/review`（5観点の並列エージェント）で1件の確定バグ・3件の品質指摘が判明。全て修正:
  - **確定バグ**: `signal-list.blade.php`が「+20%地点」「+35%地点」ラベルをハードコードしており、高水準モード適用銘柄でも古いラベルのまま実際の価格（+100%/+150%地点）を表示してしまう内部矛盾があった。Livewire画面側のテストに高水準モードのケースが無かったためGreen時点ですり抜けていた。`ShowSignalListAction`に`is_high_water_mark`フィールドを追加しBlade側でラベルを動的に切り替えるよう修正。再発防止テストを`SignalListTest.php`に追加
  - **N+1回帰**: `buildTakeProfitCandidates()`でシグナル数が判定に必要になった結果、`Signal::query()`が全保有銘柄に対して実行されるようになっていた。`signals`リレーションのEager Loadに変更し解消
  - **重複コード**: `FundamentalIndicator`からのequity_ratio/roe/成長率抽出処理が今回の変更で2箇所増えていた。`FundamentalIndicator::healthEvaluatorArgs()`を新設し集約（既存の2箇所〔`NewCandidateFinder`・`ShowBuySignalListAction`〕は今回のスコープ外として維持）
  - **マジックナンバーの結合リスク**: `ShowSignalListAction`のSQL事前絞り込み`> 20`を`TakeProfitThresholdEvaluator::MIN_POSSIBLE_GAIN_RATE_THRESHOLD`定数参照に変更
  - （プロセス違反という指摘が1件あったが、実際にはGate4承認を別ターンで得ておりコミット粒度の見た目だけの誤検知のため対応不要と判断）
- フルスイート389件Green確認後、コミット（`c3a3752`、`8f7ac51`、いずれも未push）

### Files touched

`app/Services/Analysis/TakeProfitThresholdEvaluator.php`（新規）、`app/Actions/Signal/ShowSignalListAction.php`、`app/Actions/ImportSummaryReport/ShowImportSummaryReportAction.php`、`app/Models/FundamentalIndicator.php`（`healthEvaluatorArgs()`追加）、`resources/views/livewire/signal/signal-list.blade.php`、`docs/product/use-cases.md`（UC-004/UC-009業務ルール改訂・承認記録）、`docs/architecture/data-model.md`（初期パラメータ表・承認記録）、`docs/rcid/traceability-matrix.md`（CHG-0006）、`tests/Unit/Services/Analysis/TakeProfitThresholdEvaluatorTest.php`（新規）、`tests/Feature/UC004SignalListTest.php`、`tests/Feature/UC009ImportSummaryReportTest.php`、`tests/Feature/SignalListTest.php`、`PLAN.md`（本エントリ追加）

### Status

Gate4完了（Red→Gate4承認→Green→`/review`→修正）。フルスイート389件Green、実データ実ブラウザ確認済み。コミット済み（未push）。

## 売買シグナル画面の可読性改善（表の縦罫線＋シグナルの色分け）（2026-08-28）

### Decision

- ユーザー要望3件のうち2件に対応。(1)「表全体が見やすくなるよう縦線を入れて」→ `signal-list.blade.php` の2テーブル（買い増し候補・利確検討）に、既存の行下線に加えてセルの縦罫線（グリッド線、`border-app-border`）を追加し、セルを `align-top` に。(2)「よいシグナルがわかるように」→ 買い増し候補セクションのシグナルバッジを `variant="success"`（緑）、利確検討セクションを `variant="warning"`（琥珀）に色分け（ユーザーは当初「良い方だけ」と言ったが確認の結果「緑＋琥珀」を選択）
- Blade/ドキュメントのみの変更。Livewireコンポーネント・Actionは無変更。バッジのスロット文字列（生の signal_type）は不変のため `SignalListTest` の既存アサーションに影響なし（25件 Green 確認）
- `docs/product/ui-guidelines.md` テーブル節に「1行に複数要素を詰め込む一覧の縦罫線＋align-top」「シグナルバッジの色分け（買い=Success緑／利確・警戒=Warning琥珀）」を追記
- PEGレシオ／RSIの指標解説はチャットで回答（コード変更なし）

### 未対応（別タスク化を提案済み）

- **銘柄詳細の株価推移チャートが出ない件**: 原因はデータ取得漏れではなく「過去株価の時系列をDBに保存していない設計」。チャートは `holding_snapshots.current_price`（CSV取込1回=1点）の蓄積を描画しており、取込回数が少ないと点が1〜数個で線にならない。`FetchExternalMarketDataAction` がYahoo/J-Quantsから約2年分の週次履歴を取得しているが指標計算に使うのみで永続化していない。本物の折れ線には週次価格履歴の保存テーブル追加（新規migration、Gate3対象）＋チャート側の参照先変更が必要 → 別 /tdd サイクルで対応
- **signal_type の日本語ラベル化**（`week52_high_pullback` → 「52週高値から押し目」等）: `x-signal-badge` コンポーネント新設＋ `SignalListTest` 数件の修正が必要。効果が大きいので独立ステップ推奨

### Files touched

`resources/views/livewire/signal/signal-list.blade.php`、`docs/product/ui-guidelines.md`、`PLAN.md`（本エントリ追加）

### Status

`SignalListTest`/`HoldingListTest` 25件 Green。`npm run build` でTailwindの追加クラス（`[&_td]:border` 等）がビルド済みCSSに反映されていることを確認。実ブラウザでの目視確認は別セッションのPlaywrightがブラウザプロファイルをロックしていて未実施（次回セッションで確認）。未コミット

## 今後の対応（未着手）（2026-08-27追記、Phase5の実ブラウザ確認時に発見）

- **数値の未整形表示（Phase3〜5共通）**: `HoldingList`（保有一覧、Phase3）・`SignalList`（利確検討、Phase5）の含み益率・取得単価・現在値・分割指値の価格が、`{{ $value }}`で生の浮動小数点値をそのまま出力しており（例: 含み益率が`89.5793`と%記号なし表示、価格が`3632.676`のような小数点3桁表示）、実際にPlaywrightで画面を目視確認した際に発見した。レイアウト崩れではなく数値の可読性の問題。既存テストは生の数値部分文字列を検証する設計のため、これらのテストを含め画面3つ（Phase3/4/5）をまとめて後日別タスクで整形する（%サフィックス・価格の四捨五入・桁区切り等）方針とし、今回のPhase5サイクルでは対応を見送る
- **UC-004のE2Eテスト**: 一覧→詳細遷移のみの標準的な閲覧フローであり、`.claude/rules/31-e2e-testing.md`が対象とする「クリティカルフロー」に該当しないと判断し追加しない（Phase3/UC-002・Phase4/UC-003の同種の遷移もE2E化していないこととの一貫性を優先）
- **開発DBの保有データが空になっている**: Phase6の実ブラウザ確認時に発覚。`test@example.com`ユーザー自体も消えており(`db:seed`で復元済み)、CSV再取込等の保有データは未復元。並行セッションが`migrate:fresh`等を実行した際の巻き添えと推測されるが未確定。セクター配分ダッシュボードは空状態表示（「リバランス候補はありません」）のみ実ブラウザ確認済み。Phase7（`/candidate-check`）は`/verify`スキルで一時的にtinker投入した実データによりhappy path含め確認済み（検証後は削除しDBは空のまま）だが、いずれの画面も**本番相当のCSV再取込データでの確認はまだ行っていない**。実データでの最終End-to-End確認は保有データが復元された時点で改めて行う
- **重複度ラベルの閾値がBlade側とService側に分散（Phase7で発生）**: `resources/views/livewire/candidate/candidate-check.blade.php`が判定結果カードの「健全」/「やや偏り」/「偏り警告」ラベルを、`SectorAllocationCalculator`（UC-005）と同一の40%/70%閾値をBladeの`@php`ブロック内に再定義して導出している（`ShowCandidateCheckAction`/`CandidateOverlapCalculator`はラベルを返さず`overlap_rate`の数値のみ返すため）。閾値が2箇所に分散しており、将来どちらか一方だけ変更されるとラベル表示が実際の判定基準と乖離するリスクがある。是正するには`CandidateOverlapCalculator`にラベル算出を寄せるリファクタが必要（`ShowCandidateCheckAction`の出力契約変更を伴うため別途Red→Gate4→Greenサイクルが必要）。実害は表示ラベルのみ（`overlap_rate`の数値自体は正しい）のため優先度は低いが、次にこの画面に手を入れる際に解消する

## 今後の対応（未着手・スコープ確認済み）（2026-08-23追記、UC-007完了時点で更新）

- **フロントエンドUI（Livewire画面化）**: UC-001〜UC-009はこれまで全てAPIのみで実装してきた（`app/Livewire/`・`resources/views/`配下のBladeビューは0件、`docs/product/mockups/`は静的HTMLモックのみで実際に動く画面ではない）。Phase2（F-005/F-006/F-007/F-008）がAPIレベルで全完了したため、**次はLivewireコンポーネント・Bladeビューの実装（実際にブラウザでCSV取込〜各画面確認ができる状態にする）に着手する**方針をユーザーと確認済み
- **F-007（UC-007 市場全体指標表示）の3指標が未実装**: `GET /market-indicators`エンドポイント自体は実装完了したが、**米国10年債利回り・VIX指数・USD/JPY為替レートの3指標は取得ロジック自体が無く**（J-Quantsの範囲外のデータで、別途新規の外部APIクライアント選定〔ADR要〕が必要）、常に`null`のプレースホルダを返す。3指標の外部データ取得自体は別タスクとして先送り


