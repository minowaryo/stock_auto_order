# PLAN.md

> 2026-08-27（フロントエンド実装Phase5完了時点。UC-010 Gate4完了・コミット`ba239fe`分も含む）以前（Gate0セットアップ〜Phase1 Gate4サイクル完了・ADR-0002 NISA区分CR・ADR-0004分析エンジン実装〔設計確定〜各TDDサイクル、UC-001配線・UC-004画面・UC-003/UC-009新指標反映を含む〕完了・関連review指摘修正2件・UC-009サンプルレポート生成、F-010（UC-010）Gate1〜3ドキュメント叩き台整備完了、NISA区分内訳の書き込み・UC-004消費完了、未知の口座区分ラベルの扱いに関する`/review`指摘修正、Phase2 UC-008（Cycle1・Cycle2）完了、Phase2「UC-008→UC-005→UC-006」全完了・UC-007市場全体指標表示実装完了・実装済み全エンドポイントのIntegrationテスト網羅性監査完了、フロントエンド実装Phase0（基盤整備）完了、フロントエンド実装Phase1+2（CSV取込画面・サマリーレポート画面）完了、利確・リバランス閾値の動的分岐ロジック検討〔検討事項の記録のみ、実装はCHG-0006として2026-08-28〜29に別途完了〕、フロントエンド実装Phase3（UC-002保有銘柄一覧画面＋UC-007ウィジェット、共通レイアウトのcsrf-tokenバグ修正含む）完了、Phase3の`/review`拡張レベル実施（コミット汚染・ビュー内クエリ修正）、フロントエンド実装Phase4（UC-003銘柄詳細画面）完了、UC-010 Gate2/Gate3正式承認（買いシグナル7種の前提条件追加）完了、UC-010 Gate4完了・コミット（`ba239fe`）、フロントエンド実装Phase5（UC-004売買シグナル一覧画面）完了、およびフロントエンド実装Phase6（UC-005セクター配分ダッシュボード画面）完了〔2026-09-05、CHG-0011作業時に退避〕等）の完了済みエントリは `docs/history/plan-archive.md` に退避済み。
> **運用ルール**: PLAN.mdは300行を超えないよう保つ。300行に近づいたら、Statusが「完了」相当（Green確認完了・マージ済み等）の最も古いエントリから`docs/history/plan-archive.md`へ退避し、本ファイル冒頭のこの注記を更新する（詳細は `.claude/rules/60-docs.md` 参照）。300行超過に伴い「数値表示フォーマット修正完了（2026-08-28）」「UC-010買い増し候補セクションのフロントエンド統合完了（2026-08-28）」の2エントリを退避済み（2026-09-06、CHG-0012 Phase 0作業時）。約298行に達したため「利確検討ラインの動的分岐 CHG-0006（2026-08-28〜29）」「売買シグナル画面の可読性改善（2026-08-28）」の2エントリを退避済み（2026-09-06、CHG-0013／ADR-0012作業時）。300行超過に伴い「取込後サマリーレポートのグローバルナビタブ化 CHG-0008（2026-09-05）」の1エントリを退避済み（2026-09-12、F-012・CHG-0012のステータス記述を実態〔mainマージ済み〕に修正した際に発生した増分に対応）。300行超過に伴い「売買シグナル画面 判定チェックリスト表示 CHG-0007（2026-08-29〜09-05）」の1エントリを退避済み（2026-09-12、CHG-0016〔新規投資候補テーブルの固定ヘッダー化〕作業時）。300行超過に伴い「整理検討（含み損）候補一覧の新設 F-011（2026-09-05〜06）」の1エントリを退避済み（2026-09-21、CHG-0017/CHG-0018マージ後の最終`/review`・コミット・push前整理時）。

## mainへのマージ・最終`/review`・push（CHG-0016・CHG-0017・CHG-0018・F-013 Cycle1-2、2026-09-21）

### Decision

- 複数セッションが並行して`feat/chg0016-candidate-table-sticky-header`／`feat/chg0017-value-cyclical-judgment`（D5/Cycle6/Cycle7を含む）／`feat/chg0017-d4-sector-relative-fallback`／`feat/chg0017-d7-valuation-badge`／`feat/chg0018-undervalued-quality-buy-signal`／`feat/f012-favorites-watchlist`／`feat/f013-portfolio-buckets`を作業しmainに未マージのまま蓄積していたため、本人指示で棚卸し・マージを実施
- ADR-0015（CHG-0017）とADR-0016（CHG-0018）が独立に番号0015を先取しており衝突（`.claude/rules/06-branch-coordination.md`を新規作成、CHG-0018側を0016へ採番し直し）
- マージは`/tmp/wt-main`の別worktreeで実施（本体の作業ディレクトリが他セッションの作業中ブランチをチェックアウトしていたため、それを乱さないための分離）。conflictは追記型ドキュメント（PLAN.md/use-cases.md/data-model.md/traceability-matrix.md）中心で、いずれもunion方針（両ブランチの追記を両方残す）で解決
- マージ中に手動検証で発見・修正した実害バグ: (1) 2ブランチの`BuySignalDeterminationService`統合漏れ、(2) CHG-0018のテストフィクスチャがCHG-0017のD1救済閾値と偶然一致してしまいテスト意図が壊れていた、(3) `peg_undervalued`（低成長代替）と独立シグナル`per_undervalued`が同一PER値で想定外に重複発火（テストのアサーションを実態に合わせて修正、プロダクトロジックは両方とも正当な仕様のため不変）
- 全マージ後、`/code-review high`（8観点・5エージェント並列）で最終レビューを実施。**確定バグ3件を追加修正**:
  1. `BuySignalDeterminationService::determine()`が`FundamentalHealthEvaluator::evaluate()`呼び出し時にADR-0015 D2の3期平均成長率救済引数（avgRevenueGrowth/avgOperatingIncomeGrowth）を渡しておらず、他の全呼び出し元（`healthEvaluatorArgs()`経由）と不整合だった（3エージェントが独立検出）。`determine()`に2引数追加、`FetchExternalMarketDataAction`/`RefreshWatchlistMarketDataAction`の両呼び出し元を配線
  2. `ShowWatchlistAction`が`SignalCriteriaEvaluator::evaluateBuy()`に渡すmetrics配列に`per`/`pbr`キーが欠けており、ウォッチリスト画面（UC-012）の判定チェックリストPER/PBRチップが常にunavailable表示になっていた（CHG-0016で生のPER/PBR列を削除した後、唯一の表示経路だったため実質的にPER/PBRが画面から消えていた）
  3. `summary-report-body.blade.php`のnew_entry（ウォッチリスト候補）セクションが`@if ($totalHeldCount === 0)`の`@else`内に誤ってネストされており、保有0件のときウォッチリスト候補があっても一切表示されなかった（`ClassifyHoldingsAction::emptyResult()`は保有0件でもnew_entryを詰めて返す設計だったため意図と不一致）
- 各修正に回帰テストを追加。加えて、8角度レビューで見つかった重複ロジック（D4対セクター相対力フォールバックの4重実装・D3低成長PER/配当代替条件の2重実装・D1/D2レスキュー理由表示の2重実装）は実害なし（全コピーの挙動一致を確認済み）のため今回は修正せず、`accuracy-improvement-backlog.md`候補Lとして記録するに留めた（CHG-0005で同種の乖離に一度懲りた経緯があるため、次にD1〜D4のいずれかを変更する際の注意点として残す）

### Files touched

**マージ**: 7ブランチをmainへマージ（`--no-ff`、5コミット: chg0016単体、chg0018単体、chg0017本体、chg0017 Cycle6-7、chg0017 d7）。conflict解決: `PLAN.md`／`docs/product/use-cases.md`／`docs/architecture/data-model.md`／`docs/rcid/traceability-matrix.md`／`docs/product/accuracy-improvement-backlog.md`／`docs/history/plan-archive.md`／`app/Services/Analysis/BuySignalDeterminationService.php`／テスト2ファイル

**最終`/review`での追加修正**: `app/Services/Analysis/BuySignalDeterminationService.php`（avg growth引数追加）、`app/Actions/Analysis/FetchExternalMarketDataAction.php`／`app/Actions/Watchlist/RefreshWatchlistMarketDataAction.php`（配線）、`app/Actions/Watchlist/ShowWatchlistAction.php`（per/pbr追加）、`resources/views/components/summary-report-body.blade.php`（new_entryのネスト修正）。テスト: `tests/Unit/Services/Analysis/BuySignalDeterminationServiceTest.php`／`tests/Feature/UC012WatchlistScreenTest.php`／`tests/Feature/ImportSummaryReportShowTest.php`に回帰テスト追加。`docs/product/accuracy-improvement-backlog.md`候補L追加。`docs/history/plan-archive.md`（F-011エントリ退避）

**新設ルール**: `.claude/rules/06-branch-coordination.md`（並行ブランチのADR/CR採番衝突・追記型ドキュメントのマージ方針）、`CLAUDE.md`に参照追加

### Status

**マージ完了、最終`/review`（high、5エージェント並列）で確定バグ3件を追加修正・回帰テスト追加、フルスイート739 passed（0 failed）・pintクリーン**。`migrate:fresh`での新規DBからの全マイグレーション成功も確認済み。7ブランチ全てmain祖先に取り込み済み（`git merge-base --is-ancestor`で検証）。コミット・push実施

## 買い増しシグナル共通前提の緩和とPER単体シグナルの追加（F-010改修・UC-010・ADR-0016・CHG-0018）Phase 0 ドキュメント先行（2026-09-19〜）

### Decision

- 本人指摘: 「ROE・営業利益率・成長率・財務健全性が異常に高く、PEGレシオ・RSI・PER/PBRが低い銘柄（市場評価が収益力に追いついていない優良株）が利確検討・買い増し候補に正しく収集されていないように見える」。あわせて「PER・PBRが売買シグナル判定にどう使われているか」を確認したいとの依頼
- 調査結果: 利確検討（UC-004）側は`TakeProfitThresholdEvaluator`が財務健全性`passed`かつシグナル0件の銘柄の利確ラインを+150%へ引き上げる設計で、これらの優良株を意図的に保護しており**仕様通り**（変更不要）。買い増し候補（UC-010）側に実際のギャップがあり、`BuySignalDeterminationService::determine()`の共通前提「直近13週以内に52週高値-15%以内へ到達」が押し目買い専用の設計で、52週高値から長期間乖離した財務健全な銘柄を構造的に除外していた。PER・PBRは判定ロジックのどこにも使われておらず（算出のみ、表示は銘柄詳細・ウォッチリスト等に限定）、売買シグナル画面にも列が存在しなかった
- Planフェーズで方針提示・承認。プランファイル: `~/.claude/plans/bubbly-floating-cocoa.md`
- **実データ検証で当初案を破棄**: 当初「PER≤15 かつ PBR≤1.0」のAND条件を検討したが、Sailコンテナで保有219銘柄を実測した結果、会計恒等式`PBR≈PER×ROE`により財務健全性フィルタ（ROE≥10%要件）とほぼ両立せず、財務健全性passed24銘柄中0件になることが判明（全保有銘柄で条件を満たす7銘柄はいずれも営業利益率4〜9%で財務健全性フィルタに弾かれる伝統的薄利業種）。ユーザーからのフィードバック「PER/PBRの適正水準は業種で変わるため、まずは見える化を優先。セクター相対評価は将来課題でよい」を踏まえ設計変更
- 確定した設計（ADR-0016）:
  - **D1 共通前提の緩和**: 前提A「52週高値-15%以内到達」を、**または**「財務健全性`passed`」のOR条件に緩和。前提B（相対力≥-5pt）は維持
  - **D2 新シグナル`per_undervalued`**: `PER≤15.0`のみを条件とする単一指標シグナル（PBRはAND条件に含めない）。実データで財務健全性passed24銘柄中4銘柄（ZM/三谷セキサン/三井E&S/ACN）が該当することを確認
  - **D3 表示**: 判定チェックリストにPER（基準あり、met/near/unmet色分け）・PBR（基準なし、実測値のみの中立表示`info`）を追加。買い増し候補セクションのみ
- 番号: ADR-**0016**（0015は`feat/chg0017-value-cyclical-judgment`が独立に先取していたため、2026-09-21マージ時に本CRを0016へ採番し直し。Gate1〜4承認済みでブランチ本文に大量参照されている側〔CHG-0017〕を優先し、Proposed止まりだった本CR側を変更した。`.claude/rules/06-branch-coordination.md`参照）、CR=**CHG-0018**
- **CHG-0016との整合対応（2026-09-21マージ時に実施）**: mainマージ済みの`feat/chg0016-candidate-table-sticky-header`は、UC-012候補チェック画面のPER/PBRについて「チップに対応項目が無いため生の数値列として残す」前提で重複列を削除していた。本CRでチップを追加しこの前提が崩れるため、マージ時にUC-012の生PER/PBR列を除去する追随対応を実施済み

### Files touched

**ドキュメント**: `docs/adr/ADR-0016-undervalued-quality-buy-signal.md`（新規、マージ時に0015→0016へリネーム）、`docs/product/use-cases.md`（UC-010業務ルール・出力表・UC-012の`criteria`項目数参照箇所）、`docs/architecture/data-model.md`（`buy_signals`/`watchlist_buy_signals`のenum定義・共通前提・初期パラメータ値・near バッファ・承認記録・変更履歴）、`docs/product/accuracy-improvement-backlog.md`（セクター別相対評価を後続候補として追記）、`docs/rcid/traceability-matrix.md`（F-010行・CHG-0018変更追跡行）、`PLAN.md`（本エントリ）

**コード（Green、`feat/chg0018-undervalued-quality-buy-signal`ブランチ）**: `app/Services/Analysis/BuySignalDeterminationService.php`（前提Aの緩和、`per_undervalued`新設）、`app/Services/Analysis/SignalCriteriaEvaluator.php`（PER/PBRチップ追加）、`app/Actions/Analysis/FetchExternalMarketDataAction.php`／`app/Actions/Watchlist/RefreshWatchlistMarketDataAction.php`（配線）、`app/Actions/Signal/ShowBuySignalListAction.php`、migration2件（`buy_signals`/`watchlist_buy_signals`に`per_undervalued`追加）、`resources/views/components/criteria-chip.blade.php`／`resources/views/livewire/signal/signal-list.blade.php`。**`/review`修正**（HIGH、コミット`0c57ecd`）: `determinePerUndervalued()`に`per > 0.0`の下限ガード追加（赤字米国株の負PERを誤って割安判定するバグ、ADR-0012 D4のPEG下限バグと同一クラス）。テスト: `BuySignalDeterminationServiceTest`/`SignalCriteriaEvaluatorTest`/`UC010BuySignalListTest`/`FetchExternalMarketDataActionBuySignalTest`に追加

**マージ時追随対応（2026-09-21、mainマージ担当セッション）**: UC-012候補チェック画面（`feat/chg0016-candidate-table-sticky-header`で作った生PER/PBR列）が新設のPER/PBRチップと重複するため、`resources/views/livewire/candidate/candidate-check.blade.php`／`watchlist-table-head.blade.php`／`watchlist-table-colgroup.blade.php`から生PER/PBR列を削除（固定列11→9、テーブル幅1594px→1482px、詳細行colspan基数11→9）。ADR番号0015→0016リネームは`.claude/rules/06-branch-coordination.md`の採番衝突ルールに基づく対応

### Status

**Green実装・`/review`修正完了、2026-09-21にmainへマージ**。マージ時にCHG-0016との重複列（UC-012の生PER/PBR列）を追随除去し、影響を受ける`tests/Feature/UC012WatchlistScreenTest.php`の固定列数アサーションを更新済み（詳細は下記CHG-0016エントリのマージ後追記を参照）。

## 新規投資候補テーブルの固定ヘッダー化・重複列マージ・判定チェックリスト1項目=1列化（CHG-0016）実装完了（2026-09-12）

### Decision

- ユーザー要望: `/candidate-check`（新規投資候補、UC-012）のテーブルでヘッダーが縦・横スクロールで固定されない、判定チェックリストが1つの`<td>`内で`grid grid-cols-4`により複数列に折り返され読みづらい。「シグナルの画面（`/signals`、CHG-0007）で同様の変更を行っているはずなのでそのピットフォールも踏まえて」との指示
- 調査の結果、シグナル画面と同じ課題に加え、この画面固有の問題として**左側の素の数値列（RSI・ROE・自己資本比率・営業利益率）と判定チェックリストのチップが同じ指標を二重表示**していることが判明（チップは実測値・基準・達成色を持つ上位互換の表示）。レビューフィードバックで「重複する情報はチップ側にマージする」方針に確定
- 設計（プランファイル: `~/.claude/plans/stock_auto_order-implementation-phase.md`。実体は `/root/.claude/plans/hidden-splashing-scone.md`）:
  - CHG-0007と同じ「ヘッダー用/本文用`<table>`2分割＋ヘッダー側divに`overflow-x-auto sticky top-0`＋横スクロール同期」を適用（`x-watchlist-table-colgroup`/`x-watchlist-table-head`、新規）
  - 判定チェックリストは`x-signal-criteria-cells`をそのまま流用し、チップ1項目=テーブル1列に分解
  - 重複列（RSI・ROE・自己資本比率・営業利益率の単独列、財務健全性の内訳サマリ文）を削除。財務健全性の総合判定バッジ（健全／基準割れ／取得不可）は維持。PER・PBRはチップに対応項目が無いため残す
  - ★列を`w-8`→`w-10`に拡大（padding+border控除後に★グリフが収まらない不具合を事前修正）、★・銘柄の2列を横方向に固定（`sticky left-0`/`left-10`）
  - `resources/js/app.js`: `wire:poll`・フィルタの`wire:model.live`によるLivewire再描画で片方のスクロールコンテナだけ`scrollLeft`がずれる懸念に対処するため、`livewire:navigated`に加えLivewireのJSフック`morphed`（`livewire:updated`というDOMイベントはv3+に存在しないため不採用）でも再同期。リスナー重複登録を避けるため対象要素を`WeakSet`で管理する形に拡張
  - `$visibleRows`が0件になるケースの添字エラー（`<colgroup>`/`<thead>`が`$visibleRows[0]['criteria']`に依存）をガードし空状態「該当する銘柄はありません」を表示
- Gate 4（テストケース承認）を経てGreen実装（TDD Red→Green）

### Files touched

`resources/views/livewire/candidate/candidate-check.blade.php`、`resources/views/components/watchlist-table-colgroup.blade.php`（新規）、`resources/views/components/watchlist-table-head.blade.php`（新規）、`resources/js/app.js`、`tests/Feature/UC012WatchlistScreenTest.php`（Red 4件追加）、`docs/product/use-cases.md`（UC-012の表示項目・業務ルール改訂）、`docs/product/ui-guidelines.md`（テーブル節に本CRの統一・重複回避の原則を追記）、`docs/rcid/traceability-matrix.md`（F-012行にCHG-0016追記）、`docs/history/plan-archive.md`（CHG-0007退避）、`PLAN.md`（本エントリ）

### Status

**Green実装完了**。Redテスト4件（判定チェックリストの2段ヘッダー構造・重複列マージの実測値重複排除・0件ガード・展開行colspan整合）を追加しGate4承認を得た上でGreen実装、フルスイート610件Green（pint適用済み、1件のインポート整理を自動修正）。`npm run build`でTailwind CSS再ビルド後、Sailコンテナ内Playwright（実データ216件・90未保有銘柄）で実ブラウザ検証: 縦スクロールでヘッダーが`top:0`に固定、横スクロールで★・銘柄列が固定されヘッダーと本文の`scrollLeft`が一致（443px同期）、チップ・バッジのはみ出しなし、固定列の罫線消失なし、0件時の空状態表示も確認。検証用`storage/app/pw-scratch/`は削除済み

**`/code-review`（medium）で1件判明・修正**（2026-09-13）:
- **確定バグ**: `resources/js/app.js`に追加した`document.addEventListener('livewire:updated', bindScrollSync)`が、実際にはLivewire 4.x（v3以降）に存在しないDOM CustomEventを購読しており、フィルタ変更（`wire:model.live`）・`wire:poll`での再描画時に一切発火しない死んだコードだった。reviewが`vendor/livewire/livewire/dist/livewire.js`を実際にgrepして裏付け（実際にdispatchされる`livewire:*`イベントは`init`/`navigate`系のみ）。前回の実ブラウザ検証（上記）は「フィルタ変更後もスクロール位置ずれなし」を確認済みとしていたが、これはたまたま列幅が変わらない操作だったため実害が顕在化しなかっただけで、修正の有効性自体は検証できていなかった
- 対処: `Livewire.hook('morphed', callback)`（DOM差分適用完了後に発火するLivewire公式JSフック）に置き換え。修正直後、Tailwindと同様`resources/js/`の変更も`npm run build`しないと反映されないことを失念し一度誤った「動作せず」という診断をしかけたが、再ビルド後に解消（`docs/ai-context/known-pitfalls.md`に両方追記: Vite JSビルド漏れ／Livewire `livewire:updated`不存在）
- 再検証（Playwright、強制デシンク手法）: 本文側を横スクロール後、ヘッダー側の`scrollLeft`のみ手動で0に戻し、フォルダフィルタを変更→`morphed`フック発火→ヘッダーが本文と同じ`scrollLeft`（443px）に正しく再同期されることを実測確認（`header=443 body=443`）。フルスイート610件Green再確認・pint適用済み。検証用`storage/app/pw-scratch/`は削除済み
- 次: コミット（push禁止）
- **本CRのスコープ外（CHG-0017として後続）**: 「財務健全性が大半`passed`になり選びきれない」課題への対応として、対市場13週相対リターン（`relative_strength_vs_market`、既に算出・保存済みで未表示）・基準からの余裕度スコア・判定チェックリスト達成数・ユニバース内パーセンタイル順位の4軸を序列づけに追加し、列ヘッダークリックでのソート切替、フォルダ別グループ表示を行う方針で本人と合意済み（2026-09-12）。今回のレイアウトはこれらと前方互換（2段ヘッダー・列定義とも拡張余地を残す設計）

## バリュー/景気敏感銘柄向け判定ロジック分岐（CHG-0017・ADR-0015）Gate1〜4承認・Cycle1 Green完了（2026-09-19）

### Decision

- 「売買戦略の深化ロードマップ」（下記エントリ）で特定した「次の1手」の着手。整合性レビュー（コヒーレンスチェック）で、財務健全性の成長率救済とPEG除外が独立オプションではなく同一設計単位であること（成長率救済だけでは`peg_overvalued`シグナルが`signalCount`を非ゼロに保ち高水準モードに到達できない）を発見し、ADR設計に反映
- `/adr`スキルでADR-0015を起票。AskUserQuestionで資料2末尾の5つの確認事項のうち4点を本人確認（実測期数はDB直接確認）: 判定軸は含み益率ベース継続／UC-004側に補助バッジ追加（UC-010への同時掲載はしない）／PER≦15かつ配当利回り≧3%を暫定採用して実測／資料3⑤（PEG合否基準明文化）は本CRのスコープに含めない
- 中期トレンド確認の実装方式は週足MA75の傾き判定に決定（新規MA30より低コスト、`technical_indicators`への軽量migrationが必要）
- 本人から市場全体タイミングを買いトリガーに組み込みたい要望・利確をチャート形状（山→押し目）とセットで判定したい要望あり。前者は候補F（マクロ文脈層）を「次の1手の直後の第2弾」に格上げ、後者は候補B（出口戦略）に明記。ロードマップ文書に反映済み
- **Gate1（requirements.md）・Gate2（use-cases.md）・Gate3（data-model.md）を本人が承認（2026-09-19）**。`technical_indicators`に`ma75_trend_rising` boolean nullableを1列追加（CHG-0012以来のスキーマ変更、追加のみの低リスク変更として承認）
- **Gate4 Cycle1でD1・D3を2回改訂**: 当初のセクター分類ベース設計（`StockStyleClassifier`）は実データで`sector_classification_id`がJP保有146銘柄中7銘柄しか埋まっておらず実効性がないと判明し撤回、財務指標ベース（ROE≧15%・自己資本比率≧50%）に変更。続けて配当利回り条件も本人指摘（米国株の無配当高ROE企業を除外してしまう）で削除し最終確定（`RESCUE_MIN_EQUITY_RATIO`/`RESCUE_MIN_ROE`、新規パラメータ不要）。詳細経緯は本ファイルのgit履歴およびADR-0015本文参照
- **Cycle1〜3b Gate4承認・Green完了**: Cycle1（D1財務指標救済）／Cycle2（`financial_statements`に`period_type`/`fiscal_year_end`永続化の欠落を発見・追加、`FundamentalIndicatorMapper::averageAnnualGrowth()`新設）／Cycle2b（D2を`FundamentalHealthEvaluator`へ配線、4値OR）／Cycle3a（`SignalDeterminationService`の低成長PEG除外、`LOW_GROWTH_THRESHOLD=5.0`）／Cycle3b（`BuySignalDeterminationService`の低成長PEG除外＋PER≦15かつ配当利回り≧3.0%への置き換え）。各CycleともRed→Gate4承認→Green、フルスイート634→673 passed（0 failed）まで段階的に増加
- **`/review`実施**: ブランチが`feat/f013-portfolio-buckets`（他セッションが並行push中）の上に乗っていたため`feat/chg0017-value-cyclical-judgment`に分離。10件の指摘中2件（`ClassifyHoldingsAction`関連）はF-013側でスコープ外。**実害バグ1件を修正**（買い側PER代替判定に`per > 0.0`の下限ガードが無く赤字企業の負PERを誤って割安判定）、**リファクタ1件**（`isLowGrowth()`の`SignalDeterminationService`/`BuySignalDeterminationService`間の重複を`LowGrowthDeterminer`に集約、CHG-0005型ドリフト防止）。フルスイート673 passed、pintクリーン
- **配線Cycle 4a〜4c Gate4承認・Green完了**: 4a: `fundamental_indicators`に`avg_revenue_growth`/`avg_operating_income_growth`を追加するmigration＋`FetchExternalMarketDataAction`のJP分岐で`averageAnnualGrowth()`を呼び保存（US非対象）。4b: `FetchExternalMarketDataAction`の売り側/買い側`determine()`・`RefreshWatchlistMarketDataAction`の買い側`determine()`、計3箇所に`$fundamental`配列から`revenue_growth`/`operating_income_growth`/`per`/`dividend_yield`を配線（Green中、`UC012WatchlistRefreshTest`の新規テスト1件がフィクスチャ不備〔`preconditionsSatisfied()`未充足〕でRed化していたのをtdd-implementerが発見、株価系列を修正）。4c: `FundamentalIndicator::healthEvaluatorArgs()`を5→7要素に拡張し、`FundamentalHealthEvaluator::evaluate()`の全6呼び出し元（`TakeProfitThresholdEvaluator`〔シグネチャも8引数化〕・`ShowSignalListAction`・`ShowBuySignalListAction`・`ShowLossReviewListAction`・`NewCandidateFinder`・`ShowWatchlistAction`）をヘルパー経由に統一しD2の平均成長率を配線（F-013の`ClassifyHoldingsAction`〔他ブランチ未マージ〕はスコープ外のまま）。フルスイート678→686 passed（0 failed）、pintクリーン
- **`/review`2回目実施（7角度）・Cycle4d修正完了**: 収束した実害バグ1件を修正——`RefreshWatchlistMarketDataAction::refreshHolding()`（未保有ウォッチリスト銘柄パイプライン）が`avg_revenue_growth`/`avg_operating_income_growth`を一切計算・保存しておらず、watchlist専用銘柄（一度も保有したことがない銘柄）ではD2の平均成長率レスキューが発火しない不整合だった（cross-file tracer／altitude／line-by-lineの3角度が独立発見）。Red（`UC012WatchlistRefreshTest`に新規テスト追加）→Gate4→Green（`FetchExternalMarketDataAction`のCycle4aと同じ`averageAnnualGrowth()`呼び出しを追加）で修正。あわせて`FundamentalIndicatorMapper::annualGrowth()`/`averageAnnualGrowth()`間で重複していたFY絞り込み・重複排除・ソートの18行を`annualStatementsDescending()`に共通化（reuse／simplification角度、挙動は不変）。`docs/architecture/data-model.md`に`avg_revenue_growth`/`avg_operating_income_growth`カラム説明・承認記録・変更履歴を追記（conventions角度が「PLAN.md側は追記済みと主張しているが実際は未追記」という整合性ギャップを発見、今回で解消）。フルスイート686→687 passed（0 failed）。対象外・記録のみ: `ClassifyHoldingsAction`5→7引数ギャップ（F-013由来・他ブランチ継承ファイルのためスコープ外、既知事項のまま）、`SignalCriteriaEvaluator`の判定チェックリストがD1/D2レスキュー閾値を認識せずバッジと矛盾しうるUX不整合（新規発見、次項ロードマップの候補Kまわりに記録要）、Show*ListAction間の7引数展開の共通化・`determinePegUndervalued()`の分割（simplification角度、不具合ではなく任意整理のため見送り）
- **D4（対セクター相対力フォールバック）は別セッションで実装・マージ済み**（`feat/chg0017-d4-sector-relative-fallback`ブランチ、コミット`be0f00c`→`418172b`マージでこのブランチに統合、本人が実施・プッシュ済み）。`BuySignalDeterminationService::preconditionsSatisfied()`の事前条件Bを`relative_strength_vs_sector ?? relative_strength_vs_market`に変更、テスト5件追加。本人の依頼でレビューを実施し、実装自体はADR-0015 D4設計と一致・テストカバレッジ十分・フルスイート692 passed（0 failed）・pintクリーンを確認
- **Cycle4e（D4レビューで発見した表示層ギャップの修正）**: `SignalCriteriaEvaluator::evaluateLossReview()`（UC-011整理検討チェックリストの「相対力」行）がD4改訂後も`relative_strength_vs_market`のみを見ており、対セクター相対力を考慮していなかった（対セクターでは基準以上でも対市場のみ見ると誤って「基準割れ」表示になりうる不整合）。Red（`UC011LossReviewListTest`に新規テスト追加）→Green（`ShowLossReviewListAction`が`relative_strength_vs_sector`を`$metrics`に追加、`SignalCriteriaEvaluator`に`preferredRelativeStrength()`/`relativeStrengthLabel()`ヘルパーを追加し対セクター優先判定＋ラベル動的切替`相対力(対セクター)`/`相対力(対市場)`に対応）で修正、Unit Test 2件を追加補強。`docs/architecture/data-model.md`「全シグナル共通の前提条件」注記と平仄が取れた
- **Cycle5（D5: 週足MA75中期トレンド確認）Gate4承認・Green完了**。migration（`technical_indicators.ma75_trend_rising` boolean nullable、Gate3で計画済みのカラム定義どおり）、`TechnicalIndicatorCalculator::calculate()`に算出ロジック追加（直近MA75と13週前MA75の比較、既存`simpleMovingAverage()`を再利用、新規外部データ取得なし）、`BuySignalDeterminationService::preconditionsSatisfied()`に事前条件C配線。**null（データ不足）ではブロックしない設計を採用**（既存の事前条件A/Bはnullで即ブロックする設計だが、Cは88週分という高いデータ要件があり同方針だと直近上場銘柄等で押し目買いシグナルが一律に出なくなる回帰リスクがあったため、AskUserQuestionで本人確認のうえ「`false`〔明確な下向き〕の場合のみブロック」に決定）。Red（`TechnicalIndicatorCalculatorTest`5件＋`BuySignalDeterminationServiceTest`3件）→Green、フィクスチャはtinkerで実測値を検証してから使用。既存52週フィクスチャは全てnull扱いで後方互換を保つことを確認。フルスイート702 passed（0 failed）、pintクリーン
- **`/review`3回目実施（code-reviewスキル、enhanced level、review-score=483）・Cycle6修正完了**: Step 0のreview-score.shがブランチ全体差分（72ファイル・7317行、migration3件がsensitive path該当）に対しenhanced判定。8角度・4検証パスで9件検出、全件CONFIRMED。**実害バグ3件を修正**: ①`ClassifyHoldingsAction::fundamentalStatus()`が`healthEvaluatorArgs()`の7要素中5要素しか使わずD2救済が無効化（3回目の独立検出。F-013由来だが既にこのブランチの履歴に含まれているため今回はスコープ外扱いを撤回し修正）、②同ファイルの`isHoldWatch()`/`healthLine()`がD4対セクターフォールバック未適用、③`ShowBuySignalListAction`/`ShowWatchlistAction`の`fundamentalSummary()`がD1/D2レスキュー時にマイナスの単年度成長率をそのまま合格根拠として表示（誤解を招く表示バグ）。**リファクタ1件**: `LowGrowthDeterminer::isLowGrowth()`が`SignalCriteriaEvaluator::higherGrowthRate()`と同じロジックを再実装していたため後者に委譲（CHG-0005型ドリフト防止という自身のdocblockの目的に反していた）。Red→Green、フルスイート702→708 passed（0 failed）、pintクリーン。**対象外・バックログ行き**: `SignalCriteriaEvaluator`の判定チェックリスト（成長率行・PEG行）がD1/D2/D3の分岐を一切反映していない件（表示のみの実害・設計判断が要る規模のため`accuracy-improvement-backlog.md`候補Lとして記録）、`ClassifyHoldingsAction::marketValue()`の3重実装・5倍のDB往復（F-013側で既知・ADR-0014で許容済みのトレードオフ、CHG-0017スコープ外）
- **Cycle7（Feature Test拡充）完了**: D1（財務指標救済）は`FundamentalHealthEvaluatorTest`でUnit Testとして厚くカバーされているが、UC-004/UC-008/UC-011/UC-013の各画面でD1単独（単年度・3期平均とも成長率がプラスでない、ROE/自己資本比率のみで救済）を確認するFeature Testが存在しないことを`/review`後に監査で確認（D2救済のテストはCycle4c/4dで各画面に追加済みだったが、D1単独ケースが漏れていた）。4画面に1件ずつ追加（UC-004: 高水準モード到達、UC-008: 候補一覧への含有、UC-011: `fundamental_status=passed`、UC-013: `hold_watch=false`）。**プロダクションコードの変更なし**（全画面とも既存配線が正しく機能していることを確認するのみ、隠れたバグは見つからず）。フルスイート708→712 passed（0 failed）、pintクリーン
- **Cycle D7（UC-004 `valuation_zone_badge`）Gate4承認・Green完了（別ブランチ`feat/chg0017-d7-valuation-badge`、2026-09-21マージ）**: `ShowSignalListAction`へ既存`LowGrowthDeterminer`をDIし、低成長かつ`PER > 0 && PER <= BuySignalDeterminationService::PER_UNDERVALUED_THRESHOLD`かつ`dividend_yield >= BuySignalDeterminationService::DIVIDEND_YIELD_UNDERVALUED_THRESHOLD`の場合だけ「絶対バリュエーション上は割安ゾーン」を返す表示専用項目を追加。UC-004 Bladeの銘柄セルにSuccess配色の補助バッジを表示。負PER・PER 0・各閾値外・成長率5%超の回帰テストを追加。UC-010は既存の`signals->isNotEmpty()`除外により同時掲載されないため無変更。D7はCycle5〜7と並行して別ブランチで進んでいたため、本マージで合流させた（コンフリクトはPLAN.md本エントリのみ、コードは無衝突）
- 次: pintクリーン確認・フルスイート再確認（マージ後730 passed）→コミット（push要再確認）

### Files touched

**ドキュメント（本セッション）**: `docs/adr/ADR-0015-value-cyclical-stock-judgment-branching.md`（新規→D1/D3改訂→D1配当条件再改訂→D2データ基盤の追記、Status: Accepted〔Gate1/2/3〕）、`docs/product/requirements.md`、`docs/product/use-cases.md`（承認記録3行。D5の業務ルール文言〔全シグナル共通の前提条件③〕はGate2時点で既に記載済みだったため今回変更なし。Cycle6で`fundamental_summary`のD1/D2レスキュー時の表示優先順位を追記）、`docs/architecture/data-model.md`（`technical_indicators.ma75_trend_rising`・`financial_statements.period_type`/`fiscal_year_end`の2migrationはCycle2〜3b時点で追記済み。`fundamental_indicators.avg_*`のカラム説明・承認記録・変更履歴は配線Cycle4a時点では追記漏れだったものを2回目の`/review`〔conventions角度〕指摘を受けCycle4dで追記。D5実装に伴い「全シグナル共通の前提条件」注記を3条件に更新・変更履歴追記）、`docs/rcid/traceability-matrix.md`、`docs/product/accuracy-improvement-backlog.md`（候補K追加、Cycle6で候補L追加）、`PLAN.md`（本エントリ）。**削除**: `tests/Unit/Services/Analysis/StockStyleClassifierTest.php`

**コード（Green）**: Cycle1〜3b: `FundamentalHealthEvaluator.php`（D1救済）、`database/migrations/2026_09_19_000000_...php`（新規）、`FinancialStatement.php`、`FundamentalIndicatorMapper.php`（`averageAnnualGrowth()`）、`SignalDeterminationService.php`／`BuySignalDeterminationService.php`（PEG除外・PER/配当代替）、`LowGrowthDeterminer.php`（新規、`/review`対応）。配線Cycle4a〜4c: `database/migrations/2026_09_19_000001_...php`（新規）、`FundamentalIndicator.php`（`$fillable`/`casts`+2・`healthEvaluatorArgs()`7要素化）、`FetchExternalMarketDataAction.php`（avg_*保存＋determine()×2配線）、`RefreshWatchlistMarketDataAction.php`（determine()配線）、`TakeProfitThresholdEvaluator.php`（8引数化）、`ShowSignalListAction.php`／`ShowBuySignalListAction.php`／`ShowLossReviewListAction.php`／`NewCandidateFinder.php`／`ShowWatchlistAction.php`（ヘルパー経由に統一）。Cycle4d（2回目`/review`対応）: `RefreshWatchlistMarketDataAction.php`（`averageAnnualGrowth()`呼び出し追加）、`FundamentalIndicatorMapper.php`（`annualStatementsDescending()`共通化）。D4（別セッション、`be0f00c`）: `BuySignalDeterminationService.php`（対セクター優先フォールバック）。Cycle4e: `ShowLossReviewListAction.php`（`relative_strength_vs_sector`追加）、`SignalCriteriaEvaluator.php`（`preferredRelativeStrength()`/`relativeStrengthLabel()`新設）。Cycle5: `database/migrations/2026_09_21_000000_...php`（新規）、`TechnicalIndicatorCalculator.php`（`calculateMa75TrendRising()`新設）、`TechnicalIndicator.php`（`$fillable`/`casts`+1）、`BuySignalDeterminationService.php`（事前条件C）。Cycle6（3回目`/review`対応）: `ClassifyHoldingsAction.php`（`fundamentalStatus()`7要素化・`isHoldWatch()`/`healthLine()`のD4フォールバック）、`ShowBuySignalListAction.php`／`ShowWatchlistAction.php`（`fundamentalSummary()`のD1/D2レスキュー表示対応）、`LowGrowthDeterminer.php`（`higherGrowthRate()`委譲）。Cycle7: コードなし（テストのみ、`UC004SignalListTest.php`／`UC008NewCandidateListTest.php`／`UC011LossReviewListTest.php`／`ClassifyHoldingsActionTest.php`にD1単独ケース各1件追加）

### Status

**Gate1〜4承認済み、Cycle1〜3b（D1/D2/D3判定ロジック本体）・配線Cycle4a〜4e・D4（別セッション実装）・Cycle5（D5）・Cycle6（3回目`/review`対応）・Cycle7（Feature Test拡充）・D7（UC-004補助バッジ、別ブランチ）すべてGreen完了、2026-09-21にmainへマージ**（マージ後フルスイート730 passed / 0 failed、pintクリーン）。D1・D2・D3・D4・D5すべてが`FetchExternalMarketDataAction`/`RefreshWatchlistMarketDataAction`（シグナル永続化）・`FundamentalHealthEvaluator::evaluate()`の全7呼び出し元（財務健全性判定、`ClassifyHoldingsAction`分も含め全て統一）・`SignalCriteriaEvaluator`（判定チェックリスト表示）・`BuySignalDeterminationService`（押し目買い事前条件A/B/C）に配線され、watchlist専用銘柄・ポートフォリオ分類ダッシュボードも含めD2平均成長率レスキュー・D4対セクターフォールバック・D5中期トレンド確認が表示・判定の両面で一貫して機能する状態になった。D1単独レスキューの陽性ケースもUC-004/008/011/013の4画面全てでFeature Testレベルで確認済み。D7では同じ低成長判定・絶対閾値をUC-004の表示へ再利用し、UC-010への同時掲載は行わない。**本CRの主目的（伊藤忠等の低成長健全銘柄の救済）が実際に機能する状態**。ADR-0015のD1〜D7全項目が実装完了

## 売買戦略の深化ロードマップ策定（2026-09-19）

### Decision

- 本人要望: `docs/original-docs/`に一次資料2件（`stock_auto_order_strategy_notes.md`／`売買戦略2.txt`）を追加したので参照し、売買戦略をさらに進化させる方向性をプランニングしてほしい。加えて、会話で共有された別セッション「戦略の調査とレコメンド」（著名手法とのベンチマーク）の内容も含めて検討してほしい
- Planフェーズで承認。プランファイル: `~/.claude/plans/stock_auto_order-strategy-roadmap-phase.md`
- 3資料（資料1=スコアリング層拡張案、資料2=現行コードの実装レビュー、資料3=O'Neil/Minervini SEPA/Peter Lynch GARP/Weinstein/Bogleheads/Piotroski等とのベンチマーク）を現行実装と突き合わせ。**資料2と資料3の一部項目（②押し目買いの中期トレンド確認・③相対力RSの活用・⑤PEG基準の明文化）が独立した切り口から同一のコード箇所（`FundamentalHealthEvaluator`の成長率救済・PEG基準、`BuySignalDeterminationService`の相対力フォールバック）を指摘**しており、これを「次の1手」として抽出
- 本人フィードバック（重要）: 3資料を単純に足し合わせると15項目超のロードマップになり「機能追加が複雑になる」懸念が示された。**本プロジェクト既存の「実測検証→効果を数値確認→次に進む」パターン（CHG-0012の閾値比較等）に倣い、「次の1手のみ詳細設計、残り9項目（候補A〜J）は前提コスト・効果見込みのみの軸情報」という記録粒度で合意**（AskUserQuestion）
- 実装は行わない（ドキュメント記録のみ）。F-013（Gate1/2承認済み・実装未着手）と作業ツリーが衝突しないよう配慮

### Files touched

**ドキュメント（本セッション）**: `docs/product/accuracy-improvement-backlog.md`（新節「売買戦略の深化ロードマップ（2026-09-19、一次資料2件＋別セッションのベンチマークより）」追加。3資料の突き合わせ表・次の1手の詳細設計・候補リストA〜J・既存backlog行との相互参照・関連ドキュメント節への出典追加）、`docs/history/plan-archive.md`（CHG-0007エントリを退避・冒頭注記更新）、`PLAN.md`（本エントリ、300行超過に伴いCHG-0007エントリを退避）

**触っていないファイル**（意図的）: `docs/original-docs/`（参照のみ・編集禁止）、`requirements.md`／`use-cases.md`／`data-model.md`（Gate1/2/3は動かしていない）、`docs/rcid/traceability-matrix.md`（CR番号未発行）、アプリケーションコード一式

### Status

**完了**。`accuracy-improvement-backlog.md`への記録完了。次のアクション: 「次の1手」（`FundamentalHealthEvaluator`の成長率救済・PEG基準是正、`BuySignalDeterminationService`の相対力フォールバック、押し目買いの中期トレンド条件、スタイルタグ導入）を独立CRとして起票する場合は、ドキュメント先行・別ブランチで進める（本プロジェクトの標準方式）。候補A〜J（ファンダ履歴蓄積／ATR出口戦略／信用需給／ウォッチリスト棚卸し／検証基盤等）は次の1手の実測結果が出るまで着手判断を保留。

## ポートフォリオ分類ダッシュボード（F-013・UC-013・ADR-0014・CHG-0015）Gate1/2最終確定・実装着手待ち（2026-09-08〜09-17）

### Decision

- 本人要望: 保有銘柄を「利確・リバランス検討／整理対象／買い増し候補／新規購入候補／キープ」の5（実質は「減らす／保つ／増やす」の3）分類に束ね直し、①各銘柄がどこに当てはまるか、②評価額の何%がどの分類に入っているか、を人の目で分かる形で1画面俯瞰したい。将来的には「先週→今週である銘柄がどの分類へ動いたか」の遷移シグナルも見たい。運用像は「積立・長期保有（ガチホ）を核70〜80%、残り20%でアクティブに新規売買」
- 数回の対話で方向性の妥当性を確認 → 合意。「まず紙で分類定義と優先順位を固める」＋「現在スナップショットだけの読み取り専用ダッシュボード」を第1段階とし、永続化・遷移は第2段階に切り分ける方針で合意
- **本セッションは Phase 0（ドキュメント＋ADR-0014）のみ**。実装は F-012 マージ後
- 番号: ADR-**0014**（0012=成長率修正、0013=お気に入りウォッチリストで使用済み）、CR=**CHG-0015**（0013=成長率、0014=お気に入り）、機能=**F-013**、UC=**UC-013**
- 中核設計（ADR-0014）:
  - **D2 再投影に徹する**: バケツ6種（`core_accumulation`／`loss_review`／`take_profit`／`add_on`／`hold`／`new_entry`）の所属条件は UC-004（`ShowSignalListAction`＋`TakeProfitThresholdEvaluator`）／UC-011（`ShowLossReviewListAction`）／UC-010（`ShowBuySignalListAction`）／UC-005（`SectorAllocationCalculator`）／UC-012（`ShowWatchlistAction`）の既存抽出条件をそのまま使う。新しい閾値・判定ロジックを一切作らない
  - **D3 排他解決（新規部分）**: 1銘柄1バケツ。優先順位 `core_accumulation`（instrument_type/口座区分で先に確定）→ `loss_review` > `take_profit` > `add_on` > `hold`。`take_profit`×`add_on` は ADR-0007 で既に排他、`take_profit`×`loss_review` は含み益/損が同時成立しないため競合しない。`loss_review`×`add_on`（急落した健全銘柄）は `loss_review` を採り「買い増し候補にも掲載」注記（UC-011 の既存挙動）
  - **D4 リバランスは個別銘柄のバケツを動かさない**: セクター偏りは per-stock ではなくセクター単位の警告バッジ＋サマリで表示（UC-005 の設計に合わせる）。偏り警告セクター全銘柄を「減らす」に落とすと直感と齟齬
  - **D6 構成比は `market_value` ベース**（CHG-0011 の算出流用）。「保つ」は積立コア/キープの内訳を明示（ガチホ核比率の可視化）。`new_entry` は分母・分子に含めない
  - **D8 段階リリース**: 第1段階＝表示レイヤー完結（`ClassifyHoldingsAction` 相当の純ロジック＋Blade、DBスキーマ変更なし・永続化なし、CHG-0006/CHG-0010 と同方式）。第2段階＝`portfolio_classifications`（`holding_snapshot_id` FK・`bucket`・`reason`）を取込時に書き遷移表示＋トレードジャーナル（別CR、Gate3実質承認要）
  - **D9 画面**: サマリーレポート（UC-009）タブ最上部に「分類俯瞰」セクションを追加。新タブなし（`ui-guidelines.md` タブ数6個維持）
- **Gate2最終確定（2026-09-17）**: 5つの判断ポイントをすべて確定。(1) 排他優先順位は叩き台通り採用、(2) `loss_review`×`add_on`競合は`loss_review`採用＋「買い増し候補にも掲載」注記、(3) リバランスはバッジ＋セクターサマリ止まり（`rebalance`サブバケツは作らない）、(4) `hold_watch`（要観察）は第1段階から表示、(5) **UC-009のtop-10/20は分類俯瞰に完全に置き換え**（併存しない）
- **(5)の実装確認で判明した追加論点（すべてGate2で確定）**: UC-009（`ShowImportSummaryReportAction`）が既存UCを再利用せず独自に3種の候補選定ロジック（`buildTakeProfitCandidates`/`buildRebalanceCandidates`/`buildNewCandidateItems`）・非開示合成スコア（`composite_score`、ADR-0003）を重複実装していたことが判明。置き換えにあわせて退役させる（ADR-0014 D9-1）。永続化（`import_summary_reports`/`import_summary_report_items`）は書き込み専用・読み返し機能なしと判明したため廃止（`ImportCsvAction`のプレースホルダー行作成も削除、D9-2）。`portfolio_headline`はバケツ件数の集計文に変更（D9-3）。各行の一言評価（`bucket_reason`）は独自文章生成をやめ`SignalCriteriaEvaluator`の達成度データから機械生成（シンプル・理由明快限定、D9-4）。バケツ内ソート順を新規に全確定（D10）: `add_on`/`loss_review`/`new_entry`は供給元Actionの既存流用、`take_profit`は新規設計しUC-004本体`ShowSignalListAction`に実装（D10-1、本CR唯一の既存UC改修）、`hold`は`hold_watch`優先→含み損益率順、`core_accumulation`は評価額順、セクター偏りサマリは超過幅順。ADR-0003はSuperseded（D11）。`WatchedTheme`ベースの新規候補ロジックは退役（実データ確認済み・登録0件のため実質影響なし、モデル自体はF-005用に残置）
- **本CRのスコープ外として`accuracy-improvement-backlog.md`へ記録**: `hold`内の「好調キープ強調」（判定基準が既存UCに無く新規閾値の発明になるため見送り）、20%アクティブ枠の予算トラッキング（D7、余力の別入力手段が必要）
- 追加観点として本人に提示済み（別途 backlog 化候補）: インカム貢献度／単一銘柄集中度／買付余力・現金比率（20%枠トラッキングの分母、要別入力）／為替エクスポージャー／口座配置最適化／投資テーゼの陳腐化検知

### Files touched

**ドキュメント（Phase 0、`feat/f012-favorites-watchlist` ブランチ上で作業、2026-09-08）**: `docs/adr/ADR-0014-portfolio-bucket-classification.md`（新規、Status: Proposed）、`docs/product/use-cases.md`（UC一覧に UC-013・UC-013 節新設・承認記録行）、`docs/product/requirements.md`（2章 IN・4章 F-013 行・7章フェーズ表＋段落）、`docs/rcid/traceability-matrix.md`（F-013 マトリクス行・CHG-0015 変更追跡行）

**ドキュメント（Gate2最終確定、`feat/f013-portfolio-buckets` ブランチ、2026-09-17）**: `docs/adr/ADR-0014-portfolio-bucket-classification.md`（Status: Accepted、D3〜D5・D9の★判断ポイントを確定内容で置換、D9-1〜D9-4・D10・D10-1・D11を新設）、`docs/adr/ADR-0003-f009-scoring-transparency-relaxation.md`（Status: Superseded by ADR-0014）、`docs/product/use-cases.md`（UC-013業務ルール全面改訂・承認記録2行追加、UC-009業務ルール全面改訂〔top-10/20廃止・分類俯瞰への委譲〕、UC-004に並び順ルール追加）、`docs/product/requirements.md`（F-013説明改訂、UC-004改修・UC-009ロジック退役を明記）、`docs/architecture/data-model.md`（`import_summary_reports`/`import_summary_report_items`に書き込み廃止の注記、初期パラメータ値表にバケツ内ソート順5行追加・UC-009の件数区分/合成スコア重み付け2行を廃止、承認記録・変更履歴各1行）、`docs/rcid/traceability-matrix.md`（F-013行・CHG-0015行を確定内容に更新）、`docs/product/accuracy-improvement-backlog.md`（`hold`好調キープ強調・20%アクティブ枠トラッキングの2行追加）、`PLAN.md`（本エントリ）

**コード**: 未着手（F-012 マージ後、マージ済み）。実装ステップ（第1段階: Cycle 1 `ClassifyHoldingsAction` の純ロジック → Cycle 2 サマリーレポートタブへのセクション追加・UC-009旧ロジック削除 → Cycle 3 `ShowSignalListAction`への`take_profit`並び順追加、各 Red→Gate4→Green→Refactor）はプラン確定時に詳細化

### Status

**Gate1（requirements.md）／Gate2（use-cases.md UC-013）を2026-09-17に本人が最終承認**。第1段階は DB スキーマ変更を伴わないため Gate 3 は影響範囲確認のみ（2026-09-17確認済み。第2段階の `portfolio_classifications` は別CRで Gate 3 実質承認）。実装は F-012 マージ後（マージ済み）、ブランチ `feat/f013-portfolio-buckets` で。次はTDDサイクル（Cycle 1: `ClassifyHoldingsAction`のRed）から。

> **ブランチ状況の補足**: Phase 0（2026-09-08）は当時の`feat/f012-favorites-watchlist`ブランチ上で行われ、F-012マージ（`444ee65`）でmainに統合済み。Gate2最終確定分（2026-09-17）はmain（`4d6071e`）から新規に切った`feat/f013-portfolio-buckets`ブランチ上で作業（他の未マージ機能ブランチ〔`feat/chg0016-candidate-table-sticky-header`〕とは独立）。

## お気に入り未保有銘柄ウォッチリスト／新規投資候補画面の刷新（F-012・UC-012・ADR-0013・CHG-0014）実装完了・mainマージ済み（2026-09-06〜09-12）

### Decision

- 本人要望: 楽天証券のお気に入り銘柄CSV（`docs/original-docs/お気に入り銘柄CSV.csv`、216銘柄＝日本株144・米国株72・CFD 4）を取り込み、①既取得の指標と突き合わせた一覧、②そこからの買い時・買い足し候補、を出したい。主目的は**新規投資候補の選定**で「まだ持っていない銘柄」の情報が欲しい。保有済みは一律除外でよい
- なぜ今できないか: 分析パイプライン（`FetchExternalMarketDataAction`）が保有銘柄（`holding_snapshots`）限定で、未保有のお気に入り銘柄は `technical_indicators`／`fundamental_indicators` に行が無い。工事の本体は画面ではなく「未保有銘柄も外部APIを叩いてDBに入れる仕組み」
- Planフェーズ承認済み。プランファイル: `~/.claude/plans/stock_auto_order-favorites-watchlist-implementation-phase.md`
- 本人と確定（AskUserQuestion 2回）: (1) アプリの常設画面、(2) 表示は未保有のみ・出す情報は売買シグナル画面と同等、(3) `/candidate-check`（新規投資候補タブ）を全面刷新して主役に、(4) 押し目買いシグナル＋財務健全性フィルタは**流用**するが絞り込みには使わず全件を透明マルチキーソート（F-011方式）、(5) データ取得はCSVアップロード時＋「一括更新」ボタン（キュー非同期＋進捗表示）、(6) CSV再取込は追加のみ＋画面上で★手動お気に入り
- 既存画面の意図の棚卸し: UC-006（重複チェック）の価値ある部分（`overlap_rate` の可視化・ウォッチステータス／メモ・過去業績推移）は一覧の行展開に引き継ぐ。UC-008（軽量レコメンド）は候補供給が構造的に破綻（`holdings` 由来＝過去保有銘柄のみ・米国株ゼロ件）していたため置き換え。`NewCandidateFinder`／`watched_themes` は F-005 が流用するためコード残置。捨てるのは「銘柄コード手入力の入口」「注目テーマの手動登録」のみ
- 中核設計: (D2) 未保有銘柄も `holdings` に `firstOrCreate`（data-model.md が既に想定する「作成経路②」）して既存の `technical_indicators`／`fundamental_indicators`／`financial_statements` を `holding_id` で共有。副作用（保有一覧混入・NEWバッジ誤作動・セクター配分）は調査済みでいずれも問題なし。(D3) 買いシグナルは未保有銘柄が `holding_snapshot_id` を持てないため専用テーブル `watchlist_buy_signals`（`holding_id` キー、`buy_signals` と値域同一）に分離（ADR-0007 D2 と同じ判断）。(D4) 押し目シグナルの共通前提〔52週高値85%以内〕は高値更新中の優良銘柄を取りこぼすため、絞り込みではなく表示＋ソートキー。`BuySignalDeterminationService` は無改修（前提緩和は実測後に別CR）
- 新テーブル3件（`watchlist_items`／`watchlist_buy_signals`／`watchlist_refresh_runs`）、既存テーブルの変更なし。`compose.yaml` に `queue:work` サービス追加
- ADR番号は **ADR-0013**（ADR-0012 は別セッションの成長率修正で使用済み）、CR番号は **CHG-0014**（CHG-0013 も同修正で使用済み）
- **並行作業**: main に別セッションの ADR-0012（成長率修正）の未コミット作業（docs 3ファイル＋ Red テスト）が存在。本人の指示で「何も触らずブランチだけ切る」。`feat/f012-favorites-watchlist` を main から分岐（未コミット変更が作業ツリーに乗るが本ブランチでは一切コミット・変更しない）。**2026-09-12 追記**: 各セッションが作業を終えた後、本人の指示で3セッション分（ADR-0012／F-012／F-013 Phase0）の混在ファイルを行単位で突き合わせて再構成し、それぞれ独立したコミットに分離した（`data-model.md`／`traceability-matrix.md`／`PLAN.md`）
- **実施タイミング**: 実装着手は F-011（`feat/f011-loss-review-list`）マージ後。本セッションは Phase 0（ドキュメント＋ADR-0013）のみ

### Files touched

**ドキュメント（Phase 0、本セッション、`feat/f012-favorites-watchlist` ブランチ）**: `docs/adr/ADR-0013-favorites-watchlist.md`（新規、Status: Accepted 予定＝Gate1/2/3承認をもって）、`docs/product/requirements.md`（2章 IN＋6章制約＋4章 F-012 追加・F-006/F-008 改訂＋7章）、`docs/product/use-cases.md`（UC一覧・UC-012 節新設・UC-006 統合注記・UC-008 Superseded 注記・承認記録）、`docs/architecture/data-model.md`（`watchlist_items`／`watchlist_buy_signals`／`watchlist_refresh_runs` 定義・`holdings` 作成経路②注記・`watched_themes` 注記・「保留・確定が必要な初期パラメータ値」表4行・承認記録・変更履歴）、`docs/product/ui-guidelines.md`（新規投資候補画面の刷新後構成・6タブ維持・配色は UC-010 と同一）、`docs/ai-context/module-map.md`（`app/Services/Watchlist/`・`app/Actions/Watchlist/`・`app/Jobs/`・`watchlist:refresh`）、`docs/ai-context/glossary.md`（お気に入り銘柄CSV／ウォッチリスト／一括更新／★お気に入り／注目テーマ・軽量レコメンドの改訂）、`docs/rcid/traceability-matrix.md`（F-012 行・CHG-0014 行）、`PLAN.md`（本エントリ）

**コード（新規、Cycle 1〜4）**: `app/Services/Import/RakutenFavoriteCsvParser.php`＋`Support/ParsedFavoriteFile.php`／`ParsedFavoriteRow.php`（Cycle 1、`ee48a26`）、`app/Actions/Watchlist/ImportFavoriteCsvAction.php`＋`Support/FavoriteImportResult.php`（Cycle 2、`0d285fa`）、`app/Actions/Watchlist/RefreshWatchlistMarketDataAction.php`／`app/Jobs/RefreshWatchlistMarketDataJob.php`／`app/Console/Commands/RefreshWatchlistCommand.php`（Cycle 3・キュー、`a2d4373`）、`app/Actions/Watchlist/ShowWatchlistAction.php`＋`/candidate-check`刷新（Cycle 4、`03f4776`）、`app/Models/WatchlistItem.php`／`WatchlistBuySignal.php`／`WatchlistRefreshRun.php`、migration4件（`watchlist_items`／`watchlist_buy_signals`／`watchlist_refresh_runs`／`last_close`列追加）、`compose.yaml`（`queue:work`サービス）。テスト: `tests/Feature/UC012WatchlistImportTest.php`／`UC012WatchlistRefreshTest.php`／`UC012WatchlistScreenTest.php`（新規、旧`CandidateCheckTest.php`608行は削除）

**コード（`/review`修正、`085ac50`）**: watchlist refresh run のライフサイクル統一＋銘柄単位のアトミックな書き込みに修正

### Status

**Green実装・Refactor・`/review`完了、mainマージ済み**（コミット `444ee65`、2026-09-12）。Cycle 1〜4すべて Red→Gate4承認→Green で完了、ブランチ`feat/f012-favorites-watchlist`はmainにマージ済み。UC012関連テスト36件Green（フルスイート606 passed / 24 deprecated・既存無関係 / 0 failed）。
実データ確認（2026-09-07、本番相当）: 楽天のお気に入り銘柄CSV（216銘柄）を実際にアップロードし`watchlist_items`へ取込済み（`source=rakuten_favorites_csv`）。一括更新も複数回実行（`watchlist_refresh_runs` id 1〜7、いずれも`completed`・未保有対象90銘柄を処理・failed 0）、`watchlist_buy_signals`16件検出、対象216銘柄すべてに`technical_indicators`が格納済み。**候補CSVの取込・一括更新は既に実施済みであり、改めて試す必要はない状態**。

> 注: 本ブランチの作業ツリーには別セッションの ADR-0012（成長率修正）作業が混在していたため、F-012 分の `data-model.md`／`traceability-matrix.md`／`PLAN.md` は当初コミットを保留していた。2026-09-12、他セッションが手を止めているタイミングで内容を行単位に再構成し分離コミット（詳細は上記「並行作業」参照）。

## 成長率算出バグの是正（CHG-0013・ADR-0012）＋押し目買いPEG下限バグ（2026-09-06〜）

### Decision

- 発端: `/signals` 画面で商社（8001等）の財務指標・PEGレシオが「—」表示になる理由の調査。DB内訳を実測（`fundamental_indicators` 128件）した結果、"—" の約半分は減益（設計どおりのN/A）だが、もう半分は**取得が更新されていない古いデータ**（次回CSV取込で `FetchExternalMarketDataAction` が再フェッチされ復旧する見込み。要対応なし）と判明。加えて調査中に2件の内部ロジックバグを発見
- **バグA（ADR-0012）**: `FundamentalIndicatorMapper::calculateGrowth()`（＋`FetchExternalMarketDataAction::calculateStatementGrowth()` の複製）が成長率を「`fetchStatements()` の配列 index 0 と index 4 の比較」で算出。J-Quants `/v2/fins/summary` が (a) 同一決算を重複開示（8001の3Q決算が `DiscDate` 違いで2行）、(b) 1Q/2Q/3Q/FY を時系列混在で返すため、「通期売上 ÷ 1Q売上 → +316%」のような無意味な値が `financial_statements.revenue_yoy_change` に永続化されていた。data-model.md の定義は「前年同期比」。下流の `FundamentalHealthEvaluator`（passed/failed）→`TakeProfitThresholdEvaluator`（+150%ライン）→UC-004/005/008/009/010/011 に波及
- **修正方針（本人がAskUserQuestionで選択）**: 「最新の本決算（FY）とその前期の本決算の比較（前期通期比）」に変更。`CurPerType==='FY'` で絞り `CurFYEn`（会計年度末）で重複排除して2期比較。四半期ベースの鮮度は捨てる（本人の目的は中長期評価）。代替案（`CurPerType` を年跨ぎで突合／重複排除だけ／FY限定fetch）は却下（ADR-0012 Rationale）
- **バグB**: `BuySignalDeterminationService::determinePegUndervalued()` が `if ($pegRatio <= 1.0)` で下限なし。**US株の Finnhub `pegTTM` は負値をそのまま返す**（DB実データに WIT -34.7 / RGTI -0.98 等）ため、減益・赤字成長株を「割安」と誤判定して `peg_undervalued` シグナルを出していた。JP株は Mapper が `eps_growth<=0` で null 化するため無傷。`SignalCriteriaEvaluator` の買いチェックリスト PEG 行も同様。→ `0 < peg <= 1.0` に修正（`classify()` に `lte_positive` 方向を追加）
- **低優先（今回スコープ外・`accuracy-improvement-backlog.md` へ記録予定）**: (C) `calculateGrowth`/`calculateOperatingIncomeGrowth` は前期がマイナスだと成長率の符号が反転（黒字転換が「減益」に見える）、(D) `FinnhubClient::fetchReportedFinancials` が並べ替えなしで `[0]`=当期前提、(E) `findConceptValue` が XBRL の先頭一致で前期比較値を拾うリスク、(F) セクター平均相対力に自銘柄を含む、(G) week52 高安が週足終値ベース
- 問題なしを確認: RSI/MACD/ボリンジャー/13週リターン窓/通貨整合（`SignalDeterminationService` ネイティブ vs `SignalCriteriaEvaluator` fx換算）/`FundamentalHealthEvaluator` の判定順序/`higherGrowthRate` の `max()`

### Files touched

**ドキュメント（先行）**: `docs/adr/ADR-0012-growth-rate-fy-comparison.md`（新規、Accepted）、`docs/architecture/data-model.md`（成長率3列＋`peg_ratio`＋`financial_statements.*_yoy_change` の説明・UC-006注記・変更ログ）、`docs/ai-context/known-pitfalls.md`（`/fins/summary` の重複開示・累計期混在）、`docs/rcid/traceability-matrix.md`（CHG-0013 行）、`PLAN.md`（本エントリ）

**コード（Red→Gate4承認→Green→Refactor 完了）**: `app/Services/MarketData/JQuantsClient.php`（`period_type`/`fiscal_year_end` 追加・既定16期）、`app/Services/MarketData/JQuantsClientInterface.php`、`app/Services/Analysis/FundamentalIndicatorMapper.php`（`annualGrowth()` public 新設、`calculateGrowth()` 廃止）、`app/Actions/Analysis/FetchExternalMarketDataAction.php`（`calculateStatementGrowth()` 廃止し `annualGrowth()` 共有）、`app/Services/Analysis/BuySignalDeterminationService.php`（PEG下限）、`app/Services/Analysis/SignalCriteriaEvaluator.php`（`lte_positive` 方向）、`tests/Support/Fakes/FakeJQuantsClient.php`、テスト5ファイル（`FundamentalIndicatorMapperTest`/`JQuantsClientTest`/`FetchExternalMarketDataActionTest`/`BuySignalDeterminationServiceTest`/`SignalCriteriaEvaluatorTest`、新規10件）

### Status

Green実装・Refactor完了。フルスイート582件Green（13 deprecated は既存・無関係）、pint適用済み。実データ（live J-Quants）で 8001/8058/1605/7203 の成長率が妥当な通期比になることを確認（8001 revenue_growth: +316% → +0.67%）。C〜G の低優先課題は `docs/product/accuracy-improvement-backlog.md`「内部計算ロジックの精度課題」節に記録済み。当初 `feat/chg0012-operating-margin-criterion` 上で未コミットのまま作業していたが、CHG-0012（営業利益率、無関係）とは別件のため独立ブランチ `feat/chg0013-growth-rate-fy-comparison` に分離してコミット、`/review`（コメント英訳・data-model.mdのindex 0記述誤りの訂正・backlog H〜K追加）を経て**mainマージ済み**（`f2a73a7`／`2a7bceb`。2026-09-12、`feat/f012-favorites-watchlist` 経由でmainへ統合後、この独立ブランチとの内容差分を突き合わせ`/review`分の差分を追加反映）。`feat/chg0013-growth-rate-fy-comparison` ブランチは以後削除可

## 財務健全性フィルタに営業利益率を追加（CHG-0012・ADR-0011）実装完了・mainマージ済み（2026-09-06〜09-07）

### Decision

- 本人要望: 「財務健全性を確認できるわかりやすい項目を、中長期目線での評価がしやすくなるよう3項目からもう1項目足したい。おすすめは？」→ 営業利益率（営業利益÷売上高）を推奨・採用。3項目（ROE・自己資本比率・成長率）は資本効率／BSの頑丈さ／成長を見るが「事業そのものの稼ぐ力（利益率）」が欠けていた
- Planフェーズ承認済み。プランファイル: `~/.claude/plans/stock_auto_order-operating-margin-phase.md`
- 本人と確定（AskUserQuestion）: (1) **表示のみでなく判定に組み込む**（`FundamentalHealthEvaluator` の4条件目）、(2) 閾値 **10%以上**（8%案と実測比較。8%＝追加で2銘柄 failed／10%＝5銘柄。境界9〜10%の3銘柄を切ることを許容）、(3) `|営業利益率|>999%` は「—」（算出不可）扱いで Mapper が null 化
- 実測検証済み（保有128銘柄）: US=Finnhub `stock/metric` の `operatingMarginTTM`（無ければ `operatingMarginAnnual`）に存在・パーセントスケール・実態一致。JP=J-Quants で既取得の `net_sales`/`operating_profit` から実測算出（新規APIコールなし）。判定組み込みの実影響は 財務 `passed` が JP 15→11・US 12→11（合計 27→22）。落ちる銘柄: 3088マツキヨココカラ7.6% / 7867タカラトミー9.0% / 5288アジアパイルHD9.4% / 5805SWCC9.8% / IONQ-408%（いずれも妥当な検出）。ACHR は `operatingMarginAnnual=-243100` を返すため `decimal(7,4)` だと ADR-0006 と同じ INSERT エラー → `decimal(10,4)` ＋ null化で予防
- 影響範囲: `FundamentalHealthEvaluator::evaluate()` が5引数化 → 呼び出し元6機能（`TakeProfitThresholdEvaluator`／`NewCandidateFinder`／`ShowImportSummaryReportAction`／`ShowBuySignalListAction`／`ShowLossReviewListAction`）改修必須。`FundamentalIndicator::healthEvaluatorArgs()` を4→5要素化。判定チェックリスト（`SignalCriteriaEvaluator::fundamentalRows()`）が財務3→4項目、Bladeのテーブル固定幅 `w-[1440px]`→`w-[1512px]`。UC-011 は ADR-0010 D6 の反転フラグに営業利益率も乗せる。NISA推奨の追加基準は変更しない。**`NewCandidateFinder` の SQL事前絞り込みに `operating_margin>=10` を足さない**（NULL行がSQLで落ち evaluator の unavailable 判定に到達しなくなる）
- **実施タイミング**: F-011（`feat/f011-loss-review-list`）が `fundamentalRows()` と `SignalCriteriaEvaluatorTest` の `'total' => 3` アサート群を触っている最中のため、**F-011 マージ後に独立CRとして実装着手**。本セッションは Phase 0（ドキュメント＋ADR-0011）のみ、別ブランチ `feat/chg0012-operating-margin-criterion` で先行

### Files touched

**ドキュメント（Phase 0、本セッション）**: `docs/adr/ADR-0011-operating-margin-health-criterion.md`（新規、Status: Proposed）、`docs/architecture/data-model.md`（`fundamental_indicators` に `operating_margin` 行・US Mapper注記・「保留・確定が必要な初期パラメータ値」表4行〔財務健全性フィルタ／買い増し用／整理検討3→4項目／near バッファ〕・承認記録・変更履歴）、`docs/product/use-cases.md`（UC-001フロー7・UC-003フロー4・UC-004/010/011 判定チェックリスト財務3→4項目・UC-005/008/009 健全性フィルタ・`criteria`データ辞書・`fundamental_summary`例・承認記録）、`docs/product/requirements.md`（3章ファンダ指標一覧・F-010説明）、`docs/product/ui-guidelines.md`（チップ配色規約・サマリバッジ文言・固定件数記述・テーブル幅注記）、`docs/product/accuracy-improvement-backlog.md`（営業利益率の行を追加）、`docs/rcid/traceability-matrix.md`（CHG-0012行・F-004/009/010/011行に注記）、`PLAN.md`（本エントリ＋2エントリ退避）

**コード（Green、`508a9fc`）**: migration `add_operating_margin_to_fundamental_indicators_table`（`decimal(10,4)` nullable）、`FundamentalIndicator`（fillable/cast/`healthEvaluatorArgs()`5要素化）、`FundamentalIndicatorMapper`（JP、`operating_profit/net_sales*100`）、`UsFundamentalIndicatorMapper`（US、`operatingMarginTTM`??`Annual`）、`FundamentalHealthEvaluator::evaluate()`（5引数化）、呼び出し元6機能（`ShowSignalListAction`／`ShowBuySignalListAction`／`ShowLossReviewListAction`／`NewCandidateFinder`／`ShowImportSummaryReportAction`／`TakeProfitThresholdEvaluator`）、`SignalCriteriaEvaluator::fundamentalRows()`（4項目目・整理検討テーブルは反転）、`holding-detail.blade.php`・`signal-list.blade.php`（テーブル幅+72px）。テスト9ファイル更新（`FundamentalHealthEvaluatorTest`/`FundamentalIndicatorMapperTest`/`UsFundamentalIndicatorMapperTest`/`SignalCriteriaEvaluatorTest`/`TakeProfitThresholdEvaluatorTest`ほかFeature Test6本）

### Status

**Green実装完了、mainマージ済み**（コミット`6d5a9cb`、F-011マージ後に`feat/chg0012-operating-margin-criterion`ブランチで実装）。実DB確認: `fundamental_indicators.operating_margin`列が実際に存在。当時のフルスイート**572 passed**（現在は後続作業を含め606 passed）。実データ確認（保有135銘柄）: 財務`passed`がJP 15→11・US 12→11（合計27→22）、想定銘柄（マツキヨ7.6%/タカラトミー9.0%/アジアパイルHD9.4%/SWCC9.8%/IONQ-408%）が想定通りfailedになることをADR-0011どおり確認済み。

## 今後の対応（未着手）（2026-08-27追記、Phase5の実ブラウザ確認時に発見）

- **数値の未整形表示（Phase3〜5共通）**: `HoldingList`（保有一覧、Phase3）・`SignalList`（利確検討、Phase5）の含み益率・取得単価・現在値・分割指値の価格が、`{{ $value }}`で生の浮動小数点値をそのまま出力しており（例: 含み益率が`89.5793`と%記号なし表示、価格が`3632.676`のような小数点3桁表示）、実際にPlaywrightで画面を目視確認した際に発見した。レイアウト崩れではなく数値の可読性の問題。既存テストは生の数値部分文字列を検証する設計のため、これらのテストを含め画面3つ（Phase3/4/5）をまとめて後日別タスクで整形する（%サフィックス・価格の四捨五入・桁区切り等）方針とし、今回のPhase5サイクルでは対応を見送る
- **UC-004のE2Eテスト**: 一覧→詳細遷移のみの標準的な閲覧フローであり、`.claude/rules/31-e2e-testing.md`が対象とする「クリティカルフロー」に該当しないと判断し追加しない（Phase3/UC-002・Phase4/UC-003の同種の遷移もE2E化していないこととの一貫性を優先）
- **開発DBの保有データが空になっている**: Phase6の実ブラウザ確認時に発覚。`test@example.com`ユーザー自体も消えており(`db:seed`で復元済み)、CSV再取込等の保有データは未復元。並行セッションが`migrate:fresh`等を実行した際の巻き添えと推測されるが未確定。セクター配分ダッシュボードは空状態表示（「リバランス候補はありません」）のみ実ブラウザ確認済み。Phase7（`/candidate-check`）は`/verify`スキルで一時的にtinker投入した実データによりhappy path含め確認済み（検証後は削除しDBは空のまま）だが、いずれの画面も**本番相当のCSV再取込データでの確認はまだ行っていない**。実データでの最終End-to-End確認は保有データが復元された時点で改めて行う
- **重複度ラベルの閾値がBlade側とService側に分散（Phase7で発生）**: `resources/views/livewire/candidate/candidate-check.blade.php`が判定結果カードの「健全」/「やや偏り」/「偏り警告」ラベルを、`SectorAllocationCalculator`（UC-005）と同一の40%/70%閾値をBladeの`@php`ブロック内に再定義して導出している（`ShowCandidateCheckAction`/`CandidateOverlapCalculator`はラベルを返さず`overlap_rate`の数値のみ返すため）。閾値が2箇所に分散しており、将来どちらか一方だけ変更されるとラベル表示が実際の判定基準と乖離するリスクがある。是正するには`CandidateOverlapCalculator`にラベル算出を寄せるリファクタが必要（`ShowCandidateCheckAction`の出力契約変更を伴うため別途Red→Gate4→Greenサイクルが必要）。実害は表示ラベルのみ（`overlap_rate`の数値自体は正しい）のため優先度は低いが、次にこの画面に手を入れる際に解消する

## 今後の対応（未着手・スコープ確認済み）（2026-08-23追記、UC-007完了時点で更新）

- **フロントエンドUI（Livewire画面化）**: UC-001〜UC-009はこれまで全てAPIのみで実装してきた（`app/Livewire/`・`resources/views/`配下のBladeビューは0件、`docs/product/mockups/`は静的HTMLモックのみで実際に動く画面ではない）。Phase2（F-005/F-006/F-007/F-008）がAPIレベルで全完了したため、**次はLivewireコンポーネント・Bladeビューの実装（実際にブラウザでCSV取込〜各画面確認ができる状態にする）に着手する**方針をユーザーと確認済み
- **F-007（UC-007 市場全体指標表示）の3指標が未実装**: `GET /market-indicators`エンドポイント自体は実装完了したが、**米国10年債利回り・VIX指数・USD/JPY為替レートの3指標は取得ロジック自体が無く**（J-Quantsの範囲外のデータで、別途新規の外部APIクライアント選定〔ADR要〕が必要）、常に`null`のプレースホルダを返す。3指標の外部データ取得自体は別タスクとして先送り

