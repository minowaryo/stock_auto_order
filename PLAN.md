# PLAN.md

> 2026-08-27（フロントエンド実装Phase5完了時点。UC-010 Gate4完了・コミット`ba239fe`分も含む）以前（Gate0セットアップ〜Phase1 Gate4サイクル完了・ADR-0002 NISA区分CR・ADR-0004分析エンジン実装〔設計確定〜各TDDサイクル、UC-001配線・UC-004画面・UC-003/UC-009新指標反映を含む〕完了・関連review指摘修正2件・UC-009サンプルレポート生成、F-010（UC-010）Gate1〜3ドキュメント叩き台整備完了、NISA区分内訳の書き込み・UC-004消費完了、未知の口座区分ラベルの扱いに関する`/review`指摘修正、Phase2 UC-008（Cycle1・Cycle2）完了、Phase2「UC-008→UC-005→UC-006」全完了・UC-007市場全体指標表示実装完了・実装済み全エンドポイントのIntegrationテスト網羅性監査完了、フロントエンド実装Phase0（基盤整備）完了、フロントエンド実装Phase1+2（CSV取込画面・サマリーレポート画面）完了、利確・リバランス閾値の動的分岐ロジック検討〔検討事項の記録のみ、実装はCHG-0006として2026-08-28〜29に別途完了〕、フロントエンド実装Phase3（UC-002保有銘柄一覧画面＋UC-007ウィジェット、共通レイアウトのcsrf-tokenバグ修正含む）完了、Phase3の`/review`拡張レベル実施（コミット汚染・ビュー内クエリ修正）、フロントエンド実装Phase4（UC-003銘柄詳細画面）完了、UC-010 Gate2/Gate3正式承認（買いシグナル7種の前提条件追加）完了、UC-010 Gate4完了・コミット（`ba239fe`）、フロントエンド実装Phase5（UC-004売買シグナル一覧画面）完了、およびフロントエンド実装Phase6（UC-005セクター配分ダッシュボード画面）完了〔2026-09-05、CHG-0011作業時に退避〕等）の完了済みエントリは `docs/history/plan-archive.md` に退避済み。

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

## ポートフォリオ分類ダッシュボード（F-013・UC-013・ADR-0014・CHG-0015）Phase 0 ドキュメント先行（2026-09-08〜）

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
  - **D9 画面**: サマリーレポート（UC-009）タブ最上部に「分類俯瞰」セクションを追加。新タブなし（`ui-guidelines.md` タブ数6個維持）。UC-009 の非開示 top-10/20 は当面併存
- **★本人レビューで確定が必要な判断（Gate2）**: (1) 排他の優先順位、(2) `loss_review`×`add_on` 競合をどちらに置くか、(3) リバランスをバッジに留めるか `rebalance` サブバケツを立てるか、(4) キープの「要観察（`hold_watch`）」を第1段階から出すか、(5) UC-009 top-10/20 を分類俯瞰に置き換えるか併存か
- 追加観点として本人に提示済み（別途 backlog 化候補）: インカム貢献度／単一銘柄集中度／買付余力・現金比率（20%枠トラッキングの分母、要別入力）／為替エクスポージャー／口座配置最適化／投資テーゼの陳腐化検知

### Files touched

**ドキュメント（Phase 0、本セッション、`feat/f012-favorites-watchlist` ブランチ上で作業）**: `docs/adr/ADR-0014-portfolio-bucket-classification.md`（新規、Status: Proposed）、`docs/product/use-cases.md`（UC一覧に UC-013・UC-013 節新設・承認記録行）、`docs/product/requirements.md`（2章 IN・4章 F-013 行・7章フェーズ表＋段落）、`docs/rcid/traceability-matrix.md`（F-013 マトリクス行・CHG-0015 変更追跡行）、`docs/ai-context/glossary.md`（バケツ分類／分類俯瞰セクション／積立・インデックスコア／分類遷移）、`docs/ai-context/module-map.md`（`app/Actions/Portfolio/`）、`docs/product/ui-guidelines.md`（サマリーレポートタブの分類俯瞰セクション）、`PLAN.md`（本エントリ）

**コード**: 未着手（F-012 マージ後）。実装ステップ（第1段階: Cycle 1 `ClassifyHoldingsAction` の純ロジック → Cycle 2 サマリーレポートタブへのセクション追加、各 Red→Gate4→Green→Refactor）はプラン確定時に詳細化

### Status

Phase 0（ドキュメント先行）完了。**Gate 1（requirements.md）／Gate 2（use-cases.md UC-013）は本人レビュー待ち**。第1段階は DB スキーマ変更を伴わないため Gate 3 は影響範囲確認のみ（第2段階の `portfolio_classifications` は別CRで Gate 3 実質承認）。実装は F-012 マージ後、`feat/f013-portfolio-buckets` 等の新ブランチで。

> **ブランチ状況の注意（2026-09-08）**: 本 Phase 0 作業は `feat/f012-favorites-watchlist` ブランチ上で行ったが、同ブランチでは並行して別セッションが F-012 実装（Cycle 2 まで）と CHG-0013 成長率修正の未コミット作業を進めており、作業中に `git reset`／ブランチ往復が複数回発生した（glossary.md への追記が一度巻き戻され再適用）。本エントリのドキュメント変更はコミット前で、別セッションのコミット・リセットで再度巻き戻るリスクがある。ADR-0014（未追跡ファイル）は影響を受けない。コミット単位の切り分けは本人判断待ち。

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

## 米国株ファンダメンタルズ指標データソースとしてFinnhub採用（CHG-0009）実装完了・mainマージ済み（2026-09-05〜）

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

**ドキュメント（Gate2/3承認）**: `.env.example`（`FINNHUB_API_KEY`プレースホルダ追加）、`docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md`（新規）、`docs/product/use-cases.md`（UC-001/UC-003/UC-004/UC-010の記述改訂・承認記録追加）、`docs/architecture/data-model.md`（`fundamental_indicators`節・承認記録追加）、`docs/rcid/traceability-matrix.md`（CHG-0009）、`docs/ai-context/do-not-touch.md`（Finnhub APIキー追記）

**コード（Green、`fb894b1`）**: `app/Services/MarketData/FinnhubClient.php`＋`FinnhubClientInterface.php`（新規）、`app/Services/Analysis/UsFundamentalIndicatorMapper.php`（新規）、`app/Actions/Analysis/FetchExternalMarketDataAction.php`（US分岐の組み込み）、`app/Providers/AppServiceProvider.php`／`config/services.php`（バインディング・設定追加）。テスト: `tests/Feature/FetchExternalMarketDataActionTest.php`（拡張）、`tests/Unit/Services/MarketData/FinnhubClientTest.php`（新規）、`tests/Unit/Services/Analysis/UsFundamentalIndicatorMapperTest.php`（新規）、`tests/Support/Fakes/FakeFinnhubClient.php`（新規）

### Status

**Green実装・`/review`修正完了、mainマージ済み**（コミット`fb894b1`）。`/review`で2件修正: (1) `FinnhubClient`のリトライ例外メッセージにAPIキー（クエリパラメータ渡しのためJ-Quantsのヘッダー方式と異なり露出しやすい）が漏れないようサニタイズ、(2) XBRL概念値が`present-but-null`の場合に無言で`0.0`扱いされ自己資本比率が「0%」と誤判定される不具合を修正（unavailable扱いに）。両修正とも回帰テスト追加。実DB確認: `app/Services/Analysis/UsFundamentalIndicatorMapper.php`が存在し稼働中（CHG-0011の米国株ファンダ補完・F-012のウォッチリスト画面が実際にこの経路で米国株の指標を取得していることを確認済み）。

## 今後の対応（未着手）（2026-08-27追記、Phase5の実ブラウザ確認時に発見）

- **数値の未整形表示（Phase3〜5共通）**: `HoldingList`（保有一覧、Phase3）・`SignalList`（利確検討、Phase5）の含み益率・取得単価・現在値・分割指値の価格が、`{{ $value }}`で生の浮動小数点値をそのまま出力しており（例: 含み益率が`89.5793`と%記号なし表示、価格が`3632.676`のような小数点3桁表示）、実際にPlaywrightで画面を目視確認した際に発見した。レイアウト崩れではなく数値の可読性の問題。既存テストは生の数値部分文字列を検証する設計のため、これらのテストを含め画面3つ（Phase3/4/5）をまとめて後日別タスクで整形する（%サフィックス・価格の四捨五入・桁区切り等）方針とし、今回のPhase5サイクルでは対応を見送る
- **UC-004のE2Eテスト**: 一覧→詳細遷移のみの標準的な閲覧フローであり、`.claude/rules/31-e2e-testing.md`が対象とする「クリティカルフロー」に該当しないと判断し追加しない（Phase3/UC-002・Phase4/UC-003の同種の遷移もE2E化していないこととの一貫性を優先）
- **開発DBの保有データが空になっている**: Phase6の実ブラウザ確認時に発覚。`test@example.com`ユーザー自体も消えており(`db:seed`で復元済み)、CSV再取込等の保有データは未復元。並行セッションが`migrate:fresh`等を実行した際の巻き添えと推測されるが未確定。セクター配分ダッシュボードは空状態表示（「リバランス候補はありません」）のみ実ブラウザ確認済み。Phase7（`/candidate-check`）は`/verify`スキルで一時的にtinker投入した実データによりhappy path含め確認済み（検証後は削除しDBは空のまま）だが、いずれの画面も**本番相当のCSV再取込データでの確認はまだ行っていない**。実データでの最終End-to-End確認は保有データが復元された時点で改めて行う
- **重複度ラベルの閾値がBlade側とService側に分散（Phase7で発生）**: `resources/views/livewire/candidate/candidate-check.blade.php`が判定結果カードの「健全」/「やや偏り」/「偏り警告」ラベルを、`SectorAllocationCalculator`（UC-005）と同一の40%/70%閾値をBladeの`@php`ブロック内に再定義して導出している（`ShowCandidateCheckAction`/`CandidateOverlapCalculator`はラベルを返さず`overlap_rate`の数値のみ返すため）。閾値が2箇所に分散しており、将来どちらか一方だけ変更されるとラベル表示が実際の判定基準と乖離するリスクがある。是正するには`CandidateOverlapCalculator`にラベル算出を寄せるリファクタが必要（`ShowCandidateCheckAction`の出力契約変更を伴うため別途Red→Gate4→Greenサイクルが必要）。実害は表示ラベルのみ（`overlap_rate`の数値自体は正しい）のため優先度は低いが、次にこの画面に手を入れる際に解消する

## 今後の対応（未着手・スコープ確認済み）（2026-08-23追記、UC-007完了時点で更新）

- **フロントエンドUI（Livewire画面化）**: UC-001〜UC-009はこれまで全てAPIのみで実装してきた（`app/Livewire/`・`resources/views/`配下のBladeビューは0件、`docs/product/mockups/`は静的HTMLモックのみで実際に動く画面ではない）。Phase2（F-005/F-006/F-007/F-008）がAPIレベルで全完了したため、**次はLivewireコンポーネント・Bladeビューの実装（実際にブラウザでCSV取込〜各画面確認ができる状態にする）に着手する**方針をユーザーと確認済み
- **F-007（UC-007 市場全体指標表示）の3指標が未実装**: `GET /market-indicators`エンドポイント自体は実装完了したが、**米国10年債利回り・VIX指数・USD/JPY為替レートの3指標は取得ロジック自体が無く**（J-Quantsの範囲外のデータで、別途新規の外部APIクライアント選定〔ADR要〕が必要）、常に`null`のプレースホルダを返す。3指標の外部データ取得自体は別タスクとして先送り


