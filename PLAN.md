# PLAN.md

> 2026-08-27（フロントエンド実装Phase5完了時点。UC-010 Gate4完了・コミット`ba239fe`分も含む）以前（Gate0セットアップ〜Phase1 Gate4サイクル完了・ADR-0002 NISA区分CR・ADR-0004分析エンジン実装〔設計確定〜各TDDサイクル、UC-001配線・UC-004画面・UC-003/UC-009新指標反映を含む〕完了・関連review指摘修正2件・UC-009サンプルレポート生成、F-010（UC-010）Gate1〜3ドキュメント叩き台整備完了、NISA区分内訳の書き込み・UC-004消費完了、未知の口座区分ラベルの扱いに関する`/review`指摘修正、Phase2 UC-008（Cycle1・Cycle2）完了、Phase2「UC-008→UC-005→UC-006」全完了・UC-007市場全体指標表示実装完了・実装済み全エンドポイントのIntegrationテスト網羅性監査完了、フロントエンド実装Phase0（基盤整備）完了、フロントエンド実装Phase1+2（CSV取込画面・サマリーレポート画面）完了、利確・リバランス閾値の動的分岐ロジック検討〔検討事項の記録のみ、実装はCHG-0006として2026-08-28〜29に別途完了〕、フロントエンド実装Phase3（UC-002保有銘柄一覧画面＋UC-007ウィジェット、共通レイアウトのcsrf-tokenバグ修正含む）完了、Phase3の`/review`拡張レベル実施（コミット汚染・ビュー内クエリ修正）、フロントエンド実装Phase4（UC-003銘柄詳細画面）完了、UC-010 Gate2/Gate3正式承認（買いシグナル7種の前提条件追加）完了、UC-010 Gate4完了・コミット（`ba239fe`）、フロントエンド実装Phase5（UC-004売買シグナル一覧画面）完了、およびフロントエンド実装Phase6（UC-005セクター配分ダッシュボード画面）完了〔2026-09-05、CHG-0011作業時に退避〕等）の完了済みエントリは `docs/history/plan-archive.md` に退避済み。
> **運用ルール**: PLAN.mdは300行を超えないよう保つ。300行に近づいたら、Statusが「完了」相当（Green確認完了・マージ済み等）の最も古いエントリから`docs/history/plan-archive.md`へ退避し、本ファイル冒頭のこの注記を更新する（詳細は `.claude/rules/60-docs.md` 参照）。300行超過に伴い「数値表示フォーマット修正完了（2026-08-28）」「UC-010買い増し候補セクションのフロントエンド統合完了（2026-08-28）」の2エントリを退避済み（2026-09-06、CHG-0012 Phase 0作業時）。約298行に達したため「利確検討ラインの動的分岐 CHG-0006（2026-08-28〜29）」「売買シグナル画面の可読性改善（2026-08-28）」の2エントリを退避済み（2026-09-06、CHG-0013／ADR-0012作業時）。300行超過に伴い「取込後サマリーレポートのグローバルナビタブ化 CHG-0008（2026-09-05）」の1エントリを退避済み（2026-09-12、F-012・CHG-0012のステータス記述を実態〔mainマージ済み〕に修正した際に発生した増分に対応）。300行超過に伴い「売買シグナル画面 判定チェックリスト表示 CHG-0007（2026-08-29〜09-05）」の1エントリを退避済み（2026-09-12、CHG-0016〔新規投資候補テーブルの固定ヘッダー化〕作業時）。300行超過に伴い「整理検討（含み損）候補一覧の新設 F-011（2026-09-05〜06）」の1エントリを退避済み（2026-09-21、CHG-0017/CHG-0018マージ後の最終`/review`・コミット・push前整理時）。300行超過見込みに伴い「財務健全性フィルタに営業利益率を追加 CHG-0012（2026-09-06〜07）」の1エントリを退避済み（2026-09-27、エビデンス提言取込・CHG-0020 Phase 0作業時）。300行超過見込みに伴い「成長率算出バグの是正 CHG-0013（2026-09-06〜12）」の1エントリを退避済み（2026-09-30、集中度ダッシュボード・CHG-0026 Phase 0作業時）。300行到達に伴い「お気に入り未保有銘柄ウォッチリスト／新規投資候補画面の刷新 F-012・CHG-0014（2026-09-06〜12）」の1エントリを退避済み（2026-10-01、CHG-0026 Cycle3作業時）。300行超過に伴い「新規投資候補テーブルの固定ヘッダー化・重複列マージ CHG-0016（2026-09-12）」の1エントリを退避済み（2026-10-01、CHG-0026のmain取り込み時）。約295行に達したため「バリュー/景気敏感銘柄向け判定ロジック分岐 CHG-0017（2026-09-19）」「買い増しシグナル共通前提の緩和とPER単体シグナル CHG-0018（2026-09-19〜）」の2エントリを退避済み（2026-10-01、CHG-0030作業時）。250行超過に伴い「売買シグナル画面のPER/PBR表示をUC-004・UC-011に拡張 CHG-0019（2026-09-23）」「mainへのマージ・最終`/review`・push（2026-09-21）」「ポートフォリオ分類ダッシュボード F-013・CHG-0015（2026-09-08〜09-19）」の3エントリを退避済み（2026-10-03、CHG-0027〜0029のStatusをgit履歴で裏取りして更新した際に実施）。


## 業種別基準PER/PBRとの比較による割安・割高の色付け（CHG-0034・ADR-0023・UC-002／UC-003）Gate 2承認済み・実装着手待ち（2026-10-03）

### Decision

- 発端: 本人から「セクターから基準PER/PBRを出し、実際の値との割合で割安・割高を色付けする。突出して良いものは色を濃くし、色付けだけか判定にも生かすか検討してほしい」（2026-10-02）
- 本人承認済み: 基準は外部の固定表（日本株=JPX月次統計の東証17業種の直近値、米国株=DamodaranとSPDR ETFの平均）。JPX資料は出典明記で利用可。色付けのみで財務健全性・売買シグナルの判定には使わない。段階は「色付け→シグナル発生記録に段階を保存して実測→判定への組み込みを検討」の順
- 設計（ADR-0023）: 比率（実際÷基準）で5段階、境界は穏やかな側。基準値ごとに信頼度（high/medium/low/none）を持ち、濃い色はhighのみ、lowは「基準が不安定」と注記。PBRは日本株全業種・米国株はUtilitiesのみ。純ロジック`ValuationBenchmarkJudge`＋`config/valuation_benchmarks.php`、DBスキーマ変更なし
- 追加（2026-10-03、本人承認）: 健全性指標（ROE・自己資本比率・営業利益率・売上成長率・営業利益成長率）にも二段階の色付け（ADR-0023 D9）。緑の下限＝現行の合格ライン。強い良好のラインは本人の指示で外部資料（JPX・Damodaran・EDINET DB）と保有銘柄の実データから根拠を取り、日本株／米国株別に設定（ROE15%／25%、自己資本比率70%／なし、営業利益率20%／30%、売上成長率15%／25%、営業利益成長率30%／30%。根拠は`valuation-benchmarks.md`7章）。**ライン値は本人が確認・承認済み（2026-10-03、米国株の営業利益率30%・売上成長率25%に本人が修正）**。銀行・金融は自己資本比率・営業利益率、不動産は自己資本比率を色なし。純ロジック`FundamentalMetricToneEvaluator`（良好の下限は`FundamentalHealthEvaluator`の定数を参照）。判定は変更しない
- 範囲外（後続CR）: 売買シグナル画面・新規投資候補への展開、`signal_occurrences`への段階の保存

### Files touched

`docs/adr/ADR-0023-sector-valuation-benchmark-color.md`（新規）、`docs/product/valuation-benchmarks.md`（新規、基準値の正本）、`docs/product/valuation-benchmarks-evidence/`（新規、7つの調査の取得結果）、`docs/product/use-cases.md`（UC-002・UC-003・承認記録）、`docs/rcid/traceability-matrix.md`（CHG-0034）。コードは未着手

### Status

ドキュメントのみ作成（ブランチ`docs/chg0043-sector-valuation-color`）。use-cases.mdは**Gate 2承認済み（2026-10-03）**。次は`/tdd`で実装（`ValuationBenchmarkJudge`のUnit Red→Gate 4→Green、`ListHoldingsAction`・`ShowHoldingDetailAction`・`badge`コンポーネントの濃い色バリアント）。実装前に、基準値の主要な値（特にETF側）を公式ページで目視確認する。`docs/product/user-guide.md`は実装時に更新する。業種未分類の銘柄（日本株64・米国株43）は判定が付かない。`sectors:backfill`の実行状況は別途確認

## 売買シグナル画面の供給元Action二重実行の解消（CHG-0032）実装完了・mainマージ済み（2026-10-03）

### Decision

- CHG-0027/0028の既知の懸念（描画ごとに`ClassifyHoldingsAction`が利確・買い増し・整理検討の3 Actionを二重実行）を解消。画面表示は変えない（性能のみ・ユーザー向け挙動変更なし）
- 契約: `ClassifyHoldingsAction::execute(?array $lossReviewRows, ?array $takeProfitRows, ?array $addOnRows)`と`ShowHoldListAction::execute($sort, …同3引数)`に任意引数を追加。`SignalList`が取得済みの行を渡す。null引数は従来どおり自前実行（UC-009/UC-013の既存呼び出しは無変更）。空配列は「該当なし」

### Files touched

`app/Actions/Portfolio/ClassifyHoldingsAction.php`、`app/Actions/Portfolio/ShowHoldListAction.php`、`app/Livewire/Signal/SignalList.php`、`tests/Feature/CHG0032SignalListSingleExecutionTest.php`

### Status

Red 6件（失敗4・回帰ガード2）→Gate4承認（2026-10-03）→Green。フルスイート1062 passed・pint適用済み。SignalListの修正だけを戻すとRed2件が再発することを確認。`run`（`/signals`を実データでmainと比較し本文が完全一致）実施済み・`/review`はスキップ（スコア19・recommended、本人指示）。mainへマージ済み（`--no-ff`、`Tests: 1062 passed`）。同日、CHG-0029の`sectors:backfill`を開発DBで実行済み（最新スナップショットの未分類3件はいずれも米国ETF〔HDV/SPYD/VYM、`instrument_type`は`stock`〕でFinnhubが業種を返さず0件分類。想定どおり）。

## 集中度ダッシュボードの判定色とセクター配分との行き来（CHG-0030・ADR-0021・UC-015／UC-005）実装完了・mainマージ済み（2026-10-01〜10-03）

### Decision

- 本人要望（実画面のスクリーンショットを見て）: ①「分散が効いているかをひと目で分かるように、緑・赤などの色分けで見た目にしてほしい」、②「セクター配分との導線が分かりにくい。行ったり来たりできない」。ADR-0019 D6「判定・バッジ・色分けを付けない」を覆す変更
- 本人確認（AskUserQuestion）: 導線は**両画面に小さなタブ切替（セクター配分｜集中度）**、判定は**3段階（緑・黄・赤）で、チャットで示した叩き台のしきい値で進める**。計画の範囲・ブランチ`feat/chg0030-sector-concentration-tabs`にも「進めてよい」の承認
- 判定（ADR-0021 D1〜D2）: PC1寄与率（低いほど良い）25%未満／25〜45%未満／45%以上、実効ベット数（高いほど良い）8以上／3以上8未満／3未満、上位5銘柄ウェイト（低いほど良い）30%未満／30〜50%未満／50%以上、ポートフォリオの対SOXベータ（絶対値）0.5未満／0.5〜0.8未満／0.8以上。境界は悪い側に含める。現状（PC1 15.3%＝緑、ENB 5.08＝黄、上位5銘柄 40.6%＝黄）。相関行列はヒートマップ（0.7以上＝濃い赤〜−0.3以下＝緑）。**合成スコア・ランキングは作らない（ADR-0019 D7維持）**
- 判定ロジックは純ロジック`ConcentrationVerdictEvaluator`、Actionが`verdicts`と`correlation_bands`を返し、ビューは色の割り当てのみ（ADR-0021 D4）。色は既存の`x-badge`（success／warning／danger）と相関セルのインライン背景色で、Tailwindの再ビルド不要
- 番号: ADR-**0021**、CR=**CHG-0030**（main・全ブランチでADR最大0020・CHG最大0029を確認済み）。ブランチ`feat/chg0030-sector-concentration-tabs`（main `8285367`から分岐）、worktree`.claude/worktrees/chg0030`、専用テストDB`testing_chg0030`

### Files touched

**Phase 0（ドキュメントのみ）**: `docs/adr/ADR-0021-concentration-verdict-colors-and-tabs.md`（新規、Accepted）、`docs/adr/ADR-0019-concentration-dashboard.md`（D6の置き換え注記）、`docs/product/use-cases.md`（UC-015の出力に`correlation_bands`・`verdicts`、業務ルールの「判定を付けない」を改訂、画面配置を切替に改訂、UC-005に切替の行き来を追記、承認記録）、`docs/product/requirements.md`（F-015の記述）、`docs/product/ui-guidelines.md`（サブタブ・判定色）、`docs/product/user-guide.md`（色の見方）、`docs/ai-context/glossary.md`、`docs/rcid/traceability-matrix.md`（CHG-0030行）、`docs/history/plan-archive.md`（CHG-0017／CHG-0018エントリ退避）、`PLAN.md`（本エントリ）

### Status

**Phase 0 完了・Gate2承認済み（2026-10-01、本人）。Cycle1（サブタブ）・Cycle2（判定）をRed→Gate4承認→Greenで実装、フルスイート1045 passed・pintクリーン**。
- **実装**: `ConcentrationVerdictEvaluator`（しきい値は1か所の定数、境界は悪い側、対SOXベータは絶対値）、`ShowConcentrationDashboardAction`が`verdicts`・`correlation_bands`を追加（15キー）、`x-sector-concentration-tabs`（`aria-current`付き、両画面のタイトル下）、集中度ビューに判定バッジ（`x-badge`）・凡例・ヒートマップ、セクター配分ビューは文字リンクを切替に置き換え。新規Tailwindクラスなし（再ビルド不要）
- **Red段階で置き換えた既存テスト**: ADR-0019 D6を前提にした「判定・バッジを一切出さない」→「総合スコア・ランキング・UC-005ラベルは出さない（判定バッジは可）」、Actionの「ちょうど13キー」→15キー
- **実画面確認（`run`、複製DB＋`^SOX`）**: 判定は PC1寄与率15.3%＝分散OK、ENB 5.08＝やや集中、上位5銘柄40.6%＝やや集中、対SOXベータ0.39＝分散OK。ヒートマップは 0.90（PANW-CRWD）・0.85（2644-8035）が濃い赤、INPEXの逆相関が緑。タブで両画面を往復でき、グローバルナビは6タブのまま（「セクター配分」が選択中）、コンソールエラー0。確認用サーバー・Playwright一時ファイル・複製DBは削除、開発DBへの書き込みなし
- **`/review`（強化レベル、スコア68、3観点並行＋私の再確認、2026-10-03）**: 重大な欠陥なし（ドメイン境界チェック違反0）。指摘対応: ①判定が生の値・表示が丸めた値のため24.96%が「25.0%」で「分散OK」になる→判定と相関区分を表示と同じ桁（%は小数1桁・他は小数2桁）に丸めてから行う（Actionの1か所）、②`NaN`・無限大で判定がばらつく→判定なし（null）、③`/sector-dashboard`でグローバルナビの「セクター配分」が選択中にならない（従来の欠落、文書が両画面で選択中と明記していたため矛盾）→`SectorDashboard`のLayoutに`active`を指定、④ヒートマップの色の凡例を追加、⑤判定期待値の直書きテスト・実Actionを通すHTTPテストを追加、⑥traceabilityの古い記述を修正。見送り: `verdicts`欠落時の防御（Actionは常に15キーを返しテストで固定）、タブの`focus-visible`。フルスイート1056 passed・pintクリーン。実画面（複製DB＋`^SOX`）で、`wire:navigate`で往復してもグローバルナビが「セクター配分」のまま・凡例表示・コンソールエラー0を確認（確認用サーバー・Playwright一時ファイル・複製DBは削除済み、開発DBへの書き込みなし）
- **mainへマージ・後片付け完了（2026-10-03）**: マージ前にmainを取り込み衝突なし（取り込んだのはメガトレンドのメモのみ）。メインディレクトリには別作業（メガトレンド情報源の文書）の未コミット変更があり、うち`traceability-matrix.md`が重なったため、**そのファイルだけを一意名のスタッシュで退避→マージ→復元**した（復元後の差分はバックアップと同一、衝突は「両方残す」で解決、スタッシュは削除済み）。`main`にマージ（`1ae03be`、`--no-ff`、`Merge-Check: required (score 78)`・`Review: enhanced`・`Tests: 1056 passed`）。push未実施。`chg0030`のworktree・ローカルブランチ・専用テストDB`testing_chg0030`を削除。DBスキーマ変更なし（マイグレーション不要）
- **今後の宿題**: ①しきい値は叩き台のため実データで見直す（ADR-0021 D2）、②しきい値境界の丸め（PC1・ENB・β・相関）を計算クラスが`final`のため単体で検証できていない（top5のみ両側検証、他はActionの1か所のヘルパーで同一適用）、③`/sector-dashboard`の上部リンクは切替タブに置換済み

## 集中度ダッシュボード（CHG-0026・ADR-0019・F-015・UC-015）実装完了・mainマージ済み（2026-09-30〜10-01）

### Decision

- 本人選択: `accuracy-improvement-backlog.md`の候補O（提言5）を、並行セッションとの重複がないことを確認したうえで着手（候補M＝セクター取得はCHG-0023として別セッションが完了済み）。Planフェーズ承認済み（再レビュー1回）。プランファイル: `~/.claude/plans/stock_auto_order-concentration-dashboard-phase.md`
- 本人確認（AskUserQuestion）: 計算窓は**52週**、SOXは**Yahoo `^SOX`**を`MarketIndexClient`に追加、画面は**新ルート`/concentration-dashboard`＋`/sector-dashboard`上部からリンク**（タブ数6維持）、相関行列は**評価額上位20銘柄のみ**表示
- 原資料に無く本ADRで提案した事項（Gate2で確認）: 実効ベット数はMeucciの分散化尺度を**共分散行列**で計算（相関行列の固有ベクトルとウェイトは組み合わせない）、PC1寄与率は相関行列、窓は全銘柄共通の53週（ペアごとの共通週は行列が壊れるため不可）、除外は「株式以外」「窓内の週足欠け」、ウェイトは上位5銘柄のみ全保有・他は計算対象内で正規化してカバー率を表示、リターンは現地通貨建て、判定・目安値は出さない
- 再レビューで判明した前提: 保有個別株は85〜219銘柄と52週を上回る→固有分解は52×52のグラム行列で行う（双対形）。既存の指数取得は例外を捕まえていない→SOXは失敗しても取込を止めない形で足す。ウォッチリスト一括更新へのSOX配線は不要
- 番号: ADR-**0019**、CR=**CHG-0026**、機能=**F-015**、UC=**UC-015**（main・全ローカルブランチでADR最大0018・CHG最大0025、並行セッション71/bb/60に確認済み）
- ブランチ: `feat/chg0026-concentration-dashboard`（main `e7753c5`から分岐）。worktreeは`.claude/worktrees/chg0026`（`70-git.md` §7）。このworktreeは`vendor`／`.env`が無く、稼働中のSailコンテナはメインworktreeにバインドされているため、Gate4でテストを流す前に専用の`.env`（別`COMPOSE_PROJECT_NAME`・別ポート）と`composer install`を用意する

### Files touched

**Gate1〜3叩き台（ドキュメントのみ）**: `docs/adr/ADR-0019-concentration-dashboard.md`（新規、Proposed。D1窓／D2 SOX／D3指標の定義・双対形／D4計算対象・カバー率／D5現地通貨／D6表示／D7合成スコアにしない）、`docs/product/requirements.md`（2章IN・4章F-015・7章）、`docs/product/use-cases.md`（UC一覧・UC-015新設・承認記録に提案行）、`docs/architecture/data-model.md`（`index_weekly_prices.index_name`に`sox`・承認記録・変更履歴）、`docs/rcid/traceability-matrix.md`（F-015行・CHG-0026行）、`docs/ai-context/module-map.md`・`glossary.md`（用語4件）、`docs/product/accuracy-improvement-backlog.md`（候補O・提言5の行）、`docs/history/plan-archive.md`（CHG-0013エントリ退避）、`PLAN.md`（本エントリ）

### Status

**Gate1〜3承認済み（2026-09-30、本人。Gate2の確認3点も提案どおり確定、ADR-0019をAccepted）**。叩き台コミット`a394204`。コード・マイグレーションは未着手で、次は`/tdd`でGate4 Cycle1（着手前にこのworktree専用の`.env`・`composer install`を用意）。
- **テスト環境（2026-09-30）**: worktreeはメインディレクトリ配下（Sailコンテナのバインド範囲内）なので、共有コンテナから`docker exec -w /var/www/html/.claude/worktrees/chg0026 -e DB_DATABASE=testing_chg0026 stock_auto_order_wsl-laravel.test-1 php artisan test`で実行する。共有の`testing` DBは使わず専用DB`testing_chg0026`（`sail`ユーザーは`testing%`に全権限あり）を使用。worktreeには`.env`（メインのコピー）・`vendor`（`composer install`）・`public/build`（メインからコピー、無いとViteManifestNotFoundExceptionで5件落ちる）を用意。開発DBにはマイグレーションを流していない
- **後片付け（mainマージ後に実施）**: `testing_chg0026` DBの削除、worktreeの`.env`・`vendor`・`public/build`の削除、`git worktree remove`とローカルブランチ削除（`merge-base --is-ancestor`で確認後）
- **Cycle1 Green完了（2026-09-30）**: `index_weekly_prices.index_name`に`sox`を追加するマイグレーション、`MarketIndexClient`に`^SOX`、`FetchExternalMarketDataAction`でSOXを取得・保存（例外は警告ログのみで分析継続、nikkei225/sp500の取得方法・`market_indicator_snapshots`・ウォッチリスト一括更新は不変）。Red 7件（うち失敗4件）→Gate4承認→Green、フルスイート839 passed。`^SOX`の実レスポンスは出来高が全行0（nullではない）で、行は消えず最新週の誤除去もないことを確認（ADR-0019 D2追記）。**実挙動確認**: 開発DBの複製（`testing_chg0026_run`、確認後に削除。開発DB本体にはマイグレーション未適用）に実CSV3本で`ImportCsvAction`を実行し、取込`completed`（252秒）・`sox` 104行・`market_indicator_snapshots`は2指数のみ・SOX警告ログ0件。
- **Cycle2 Green完了（2026-10-01）**: `app/Services/Concentration/`に`HoldingWeightCalculator`（評価額・計算対象内ウェイト）、`WeeklyReturnMatrixBuilder`（窓＝株式の最新週から53個の月曜、全週そろった株式のみ、除外理由`not_stock`／`insufficient_history`、SOXは欠けたらnull）、`EigenSolver`（巡回Jacobi法）を新設。いずれもDB非依存の純ロジック。Red 35件→Gate4承認→Green、フルスイート874 passed・pintクリーン。Jacobi法の実測: 52×52=0.16秒、200×200=10.4秒（ADR-0019 D3の双対形＝52×52で解く設計の裏付け）。SOXの終値0以下はnull扱い（テスト未固定の解釈）。
- **Cycle3 Green完了（2026-10-01）**: `CorrelationMatrixCalculator`／`PrincipalComponentAnalyzer`（双対形）／`EffectiveBetCalculator`（Meucci、双対形）／`SoxBetaCalculator`／`TopHoldingsConcentrationCalculator`を新設（純ロジック）。Red 40件→Gate4承認→Green、フルスイート914 passed・pintクリーン。Red段階で判明: ①等分散・無相関の等ウェイトのように固有値が重なるとENBは固有ベクトルの取り方で変わる（Meucciの式に元からある性質。テストは固有値が異なる「リスク等価」の組に置換）、②指示側の算数ミス（上位5は270/280でなく250/280）をサブエージェントが是正
- **実データ確認（2026-10-01、開発DBの複製＋実CSV再取込、複製は削除済み）**: 保有127銘柄（JP85・US42）すべて計算対象、除外は投資信託6本のみ、カバー率90.0%、窓2025-09-29〜2026-09-28。上位5銘柄41.3%、PC1寄与率14.7%、ENB 5.33、ポートフォリオの対SOXベータ0.38、上位20銘柄の平均相関0.13。**双対形とN×N直接計算が一致**（PC1・ENBとも）、日米の週の位置合わせも正しい（JP/US平均リターン相関がlag0で0.33、±1週で約0）。銘柄間の平均相関はJP-JP 0.13・US-US 0.17・JP-US 0.04で、提言書§2.3の目安（平均相関0.6〜0.7・PC1 60〜75%・ENB 2〜5）より分散している。なお固有値上位は14.7%・12.2%と近く、ENBは固有ベクトルの取り方に敏感な領域（ADR-0019 Consequencesに注記）
- **未反映の論点（Cycle4のRedで本人確認）**: 開発DBの保有株の週足は次回CSV取込まで0件（最後の取込が週足保存機能の前だったため）。取込前に画面を開くと全銘柄が「週足不足」で算出不可になるため、空状態の文言（「次回のCSV取込後に算出できます」等）をUC-015のエラーケースに足すか決める。- **SOXの開発DBへの投入方針（2026-10-01、本人「おすすめで」）**: 開発DBにはまだ`sox`のマイグレーションもSOX行も無く（取込は別ブランチのコードで動いているため）、いまは何もしない。mainマージ時に`php artisan migrate`を流し、次回のCSV取込から自動で入る。Cycle4の`run`での実画面確認の直前だけ、先に投入が必要になる（worktreeからマイグレーション適用＋`^SOX`1回取得）ので、その時に改めて本人に確認する

- **Cycle4 Green完了（2026-10-01）**: `ShowConcentrationDashboardAction`（読み取り専用、13キー）、`ConcentrationDashboard`（Livewire、`/concentration-dashboard`、ナビのアクティブはセクター配分）、ビュー、`/sector-dashboard`上部のリンクを追加。Red 23件→Gate4承認→Green、フルスイート937 passed・pintクリーン。新しいTailwindクラスなし。空状態の文言「週足がそろった銘柄がありません。次回のCSV取込後に算出できます」はGate4で本人承認。ユーザーガイドにUC-015の見方を追加。- **実画面確認（`run`、2026-10-01）**: worktreeのコードをコンテナ内の`artisan serve`（8001番）で起動し、Playwright（コンテナ内、使い捨て）でログイン→`/sector-dashboard`のリンク→`/concentration-dashboard`を実データ（開発DB／複製DB）で確認。コンソールエラー0、ナビは6タブのまま。**テストで検出できない表示不具合を2点発見して修正**: ①相関行列の左端列が狭く銘柄名が1文字ずつ折り返され表が約1,800pxに伸びた→`whitespace-nowrap`＋左端固定（`sticky left-0`）で746pxに、②投資信託は銘柄コードと銘柄名が同一文字列で除外一覧に二重表示→同一なら1回だけ表示（新規Tailwindクラスなし、テスト23件は変更なしで通過）。SOXあり（複製DBに`^SOX`を投入）の状態も確認: ポートフォリオの対SOXベータ0.39、銘柄別MU 1.45・INPEX -0.33・AAPL 0.10。SOXなし（開発DB）は「取得不可（—）」表示。**注意**: `artisan serve`は環境変数`DB_DATABASE`ではなく`.env`を読むため、複製DBに向けるにはworktreeの`.env`を一時的に書き換える必要があった（確認後に復元）。開発DBの`sessions`に私のログインで5行入ったが削除済み。後片付け済み（8001のサーバー停止・Playwright一時ファイル・複製DB削除）。他セッション（CHG-0029）が`sector-dashboard.blade.php`の書式を変更中だが別の行で、マージは自動統合の見込み（マージ時に目視確認）。- **`/review`（強化レベル、スコア242、3観点並行＋私の再確認）対応（2026-10-01）**: 重大な欠陥なし（数式は直接計算と1e-13以内で一致）。指摘対応: ①Yahooクライアントは失敗時に例外でなく空配列を返すためSOX取得失敗が警告ログに出なかった→空の結果も警告ログ化（UC-015エラーケース・ADR-0019 D2に追記）、②計算対象2銘柄未満で`hidden_count`が1になり「算出不可」と矛盾→0に、③traceabilityの状態・クラス数の食い違い、ユーザーガイドの注記条件、列見出しの`scope="col"`、回帰ガード3件（定数系列・N=40>T=12の直接計算一致）を追加。見送り: 週足の全件モデル化（実測0.71秒・5クエリ・75MB）、行見出しのscope（ビルド済みCSSに無いクラスが必要）、絶対閾値1e-18（実データでは到達しない）。フルスイート968 passed・pintクリーン。- **mainへマージ・後片付け完了（2026-10-01）**: マージ前にmain（CHG-0029）を取り込み衝突なし（SOX取得とセクター分類が`FetchExternalMarketDataAction`内で両立）。メインディレクトリがCHG-0029のマージ作業中（`MERGE_HEAD`あり）だったため、コミットを待ってから実施。`main`にマージ（`c522d60`、`--no-ff`、`Merge-Check: required (score 247)`・`Review: enhanced`・`Tests: 989 passed`）。push未実施。`chg0026`のworktree・ローカルブランチ・専用テストDB`testing_chg0026`を削除。**開発DBに`sox`のマイグレーションを適用済み**（`index_name`は`enum('nikkei225','sp500','sox')`・NOT NULL、既存行は不変）。次回のCSV取込から`^SOX`の週足が保存され、集中度ダッシュボードの対SOXベータが数値になる（それまでは「取得不可（—）」）。
- **今後の宿題**: ①候補N（エクスポージャー管理）は本機能で保存が始まるSOX週次系列を利用できる、②ENBの固有値上位が近い領域での不安定性（ADR-0019 Consequences）が気になる場合は最小ねじりベットを別ADRで検討、③`YahooFinanceChartClient`が失敗時に空配列を返す仕様はnikkei225/sp500でも無警告のまま（今回はSOXのみ空の結果をログ化）想定Cycle: ①enum拡張マイグレーション（単独コミット）＋SOXの取得・保存（失敗時の継続・`^SOX`の出来高を実データで確認）、②ウェイト・リターン行列（窓・除外、除外件数を実データで確認）・Jacobi法、③相関・PC1・ENB・ベータ・上位5銘柄（手計算できる小行列で固定、双対形とN×N直接計算の一致を回帰テスト）、④Action・Livewire・ビュー・ルート・リンク（Feature Test、`run`スキルで実画面確認）

> 2026-10-01: CHG-0028作業時に「お気に入り未保有銘柄ウォッチリスト／新規投資候補画面の刷新（F-012・UC-012・ADR-0013・CHG-0014）」エントリを`docs/history/plan-archive.md`へ退避（実装完了・mainマージ済みと記載済み）。
> 2026-10-01: CHG-0027作業時に「成長率算出バグの是正（CHG-0013・ADR-0012）＋押し目買いPEG下限バグ」エントリを`docs/history/plan-archive.md`へ退避（mainマージ済み確認）。

## 米国株・投資信託のセクター分類と市場別・金額付き表示（CHG-0029・ADR-0020）実装完了・mainマージ済み（2026-10-01）

### Decision

- 発端: セクター配分の「未分類」が評価額の約73%（米国株約978万円・投信約157万円。日本株は最新スナップショットで全件分類済み）で常時「偏り警告」。米国株・投信にはセクター取得処理が存在しない
- 本人判断: 米国株=Finnhub業種／投信=専用カテゴリで進める。追加要望: 米国と日本株を区別／合計額も表示／構成比は小数1桁（最後の1つは実装済み・未コミット）
- 設計（ADR-0020）: `sector_classifications.market`追加・一意制約を`(market,name)`へ、`FinnhubClient::fetchIndustry()`、表示は市場別＋評価額＋市場小計＋全体合計、既存分は`sectors:backfill`

### Files touched

`docs/adr/ADR-0020-*.md`（新規）、`docs/product/use-cases.md`（UC-005・承認記録=承認待ち）、`docs/architecture/data-model.md`（`sector_classifications`）、`resources/views/livewire/sector/sector-dashboard.blade.php`（小数1桁のみ）

### Status

Red 19件（通過3件は回帰ガード）→Gate4承認→Green。フルスイート877 passed・pint適用済み。追加: マイグレーション`2026_10_01_000000`、`SectorClassificationResolver`、`sectors:backfill`、`FinnhubClient::fetchIndustry`。その後`/review`（強化レベル）で市場別の重複照合を修正（`2598490`）し、mainへマージ（`3c6e886`、`Merge-Check: required (score 72)`・`Review: enhanced`・`Tests: 879 passed`）。**未確認**: `sectors:backfill`を開発DBで実行済みか（実行すると米国株分の保有ごとにFinnhubを呼ぶ。記録なし）。（2026-10-03、Status記述をgit履歴で裏取りして更新）

## 売買シグナル画面へのキープ（hold）表の追加（CHG-0028）実装完了・mainマージ済み（2026-10-01）

### Decision

- 本人要望: 売買シグナルにホールドも出す／既存3テーブルと同じ表形式で。`hold`はUC-013（F-013）の既存バケツで、現状はサマリーレポートの簡易表にしか出ていない
- 本人判断（すべて推奨案）: `hold`のみ（`core_accumulation`除外）／列=銘柄・評価額・含み損益率・要観察・ヘルスライン・セクター／並びはCHG-0027の共通切替／`feat/chg0027-...`から`feat/chg0028-signal-hold-table`を分岐

### Files touched

`docs/product/use-cases.md`（UC-013業務ルール・承認記録）、`docs/rcid/traceability-matrix.md`、`docs/ai-context/module-map.md`、`app/Actions/Portfolio/ShowHoldListAction.php`（新規）、`ClassifyHoldingsAction`（`sector_name`追加）、`SignalList`、`signal-list.blade.php`、`tests/Feature/CHG0028SignalHoldTableTest.php`

### Status

Red 12件→Gate4承認→Green。フルスイート858 passed・pint適用済み・`/review`実施済み（HIGHなし）。既知の懸念（別CR候補）: 描画ごとに`ClassifyHoldingsAction`が3 Actionを二重実行する。CHG-0027と合わせてmainへマージ（`d41adf8`、`Merge-Check: required (score 67)`・`Review: enhanced`・`Tests: 858 passed`）。（2026-10-03、Status記述をgit履歴で裏取りして更新）

## 売買シグナル画面の評価額ソート・整理検討の評価額列/列順統一（CHG-0027）実装完了・mainマージ済み（2026-10-01）

### Decision

- 本人要望: 整理検討にも評価額を出す／全テーブルのソート順を確認し評価額で並べたい（何がインパクト大かを知りたい）。本人判断: (1) 整理検討の列順を利確・買い増しと統一（銘柄→評価額→含み損益率）、(2) 既定の並びを評価額順に変更、従来の透明マルチキー（財務健全性等）は「おすすめ順」として選択可、(3) それ以外は提案どおり
- Actionは`execute(string $sort = 'recommended')`（`App\Support\SignalListSort`）。既定を従来のまま残すのは、JSON APIとUC-013（`ClassifyHoldingsAction`のバケツ内並び）が依存するため。画面（`SignalList`）だけが`market_value`（既定）/`recommended`を切替（ボタン・URL `?sort=`）。同額は従来の並びで決着
- **スコープ外（本人の「提案どおり」に含まれるが今回は未着手）**: 保有銘柄一覧・サマリレポートのバケツ別表への評価額列追加は、別途判断待ち

### Files touched

`docs/product/use-cases.md`（UC-004/010/011・承認記録）、`docs/rcid/traceability-matrix.md`、`app/Support/SignalListSort.php`（新規）、`app/Actions/Signal/Show{SignalList,BuySignalList,LossReviewList}Action.php`、`app/Livewire/Signal/SignalList.php`、`resources/views/livewire/signal/signal-list.blade.php`、`resources/views/components/loss-review-table-colgroup.blade.php`、`tests/Feature/CHG0027SignalSortTest.php`（新規13件）

### Status

Red 9件→Gate4承認→Green。フルスイート845 passed・pint適用済み。`feat/chg0028-signal-hold-table`の祖先としてmainへマージ（`d41adf8`、`Review: enhanced`）。（2026-10-03、Status記述をgit履歴で裏取りして更新）

## エビデンス提言の取込とシグナル検証基盤（CHG-0020・ADR-0017・F-014・UC-014）Cycle1〜2完了・mainマージ済み、Cycle3〜4未着手（2026-09-27〜）

### Decision

- 本人要望: `docs/original-docs/`に追加した外部調査ベースの改善提言2件（`stock-auto-order-recommendations-summary.md`／`-detail.md`、2026-09-23）を参考に後続計画を進めたい
- Planフェーズで承認。プランファイル: `~/.claude/plans/stock_auto_order-evidence-roadmap-phase.md`
- 提言の要旨: 半導体集中で実効ベット数2〜5のため、シグナル精緻化から取れるアルファはほぼない。価値があるのは①シグナルの検証機構（現在ゼロ）、②エクスポージャー管理。PEGの高成長側だけは天井で買いシグナルを出す方向に系統的に逆向き
- **現物確認で提言書の記述を補正**（詳細は`accuracy-improvement-backlog.md`「エビデンス提言の取込」節）: シグナルは`holding_snapshot_id`単位の削除のため過去6スナップショット分は残っている（根拠値・株価は無し、`watchlist_buy_signals`は履歴なし）／PEG≤1.0の53銘柄中32銘柄が成長率30%超で、山側ガードの単純適用は買いPEGシグナルの大半を消す／`WatchedTheme`によるUC-009の絞り込みはF-013で退役済み／`fetchSectorInfo()`はHTTP失敗を黙ってnull扱い（セクター7/146の原因候補）
- 本人判断（AskUserQuestion）: (1) 着手順は**検証基盤→PEG山側ガード**、(2) CHG-0016エントリで合意していた「新規投資候補の序列4軸追加」は**検証基盤で実測するまで4軸とも保留**（対市場相対リターンは日本で横断面モメンタムが機能しないとの実証あり）、(3) 検証基盤は**前向き記録のみ**（バックテストは`requirements.md`2章OUTのまま）、(4) `BACKGROUND.md`の数値目標を**今回見直す**（下限ライン→目標、現金ベース→対ガチホ差分）
- 番号: ADR-**0017**、CR=**CHG-0020**、機能=**F-014**、UC=**UC-014**（全ローカルブランチでADR最大0016・CHG最大0019を確認済み）。PEG山側ガードは後続のCHG-0021想定
- ブランチ: `feat/chg0020-signal-outcome-tracking`を`feat/chg0019-signals-per-pbr-parity`（main未マージ）のHEADから分岐。本ブランチをmainへマージすればCHG-0019も含まれる（`.claude/rules/06-branch-coordination.md`）

### Files touched

**Phase 0（ドキュメントのみ、コード変更なし）**: `docs/product/accuracy-improvement-backlog.md`（新節「エビデンス提言の取込」：本人判断・補正事項・提言と既存候補の対応・採用する「やらないこと」・候補M〜P、優先順位1位への追記）、`BACKGROUND.md`（数値目標を対ガチホ差分の目標へ改訂）、`docs/ai-context/project-summary.md`（目的欄1文）、`docs/history/plan-archive.md`（CHG-0012エントリ退避）、`PLAN.md`（本エントリ）。一次資料2件（`docs/original-docs/stock-auto-order-recommendations-*.md`）は本人追加分をそのままコミット（内容の編集なし）

**Phase 1 Gate1〜3叩き台**: `docs/adr/ADR-0017-signal-outcome-tracking.md`（新規、Proposed。D1前向き記録のみ／D2 `weekly_prices`・`index_weekly_prices`／D3 `signal_occurrences`／D4 結果は読み取り時算出／D5 分割調整前提の固定／D6 既存6スナップショット移送／D7 評価指標・判定基準の事前固定／D8 表示）、`docs/product/requirements.md`（2章IN追加・OUTのバックテスト文言整理、4章F-014、5章データ保持、7章着手順）、`docs/product/use-cases.md`（UC一覧・UC-014新設・承認記録に提案行）、`docs/architecture/data-model.md`（ER図・3テーブル定義・設計方針「上書き型の時系列」・承認記録・変更履歴）、`docs/rcid/traceability-matrix.md`（F-014行・CHG-0020行）、`docs/ai-context/module-map.md`・`glossary.md`（用語3件）
- 叩き台作成時の実測: Yahoo `quote.close`はNTT 9432（2023-06-25 25:1分割）で分割前も約161円＝**分割遡及調整済み**、`adjclose`は配当込みで別物（約9%差）。ファーストリテイリング9983は提言書が例示した2024-03の期間にYahooが分割イベントを返さず未確認。個別株の`holdings`は219件

### Status

**Phase 0完了・Gate1〜3承認済み（2026-09-27、本人「すすめてよい」）**。Gate2の★画面配置は新ルート`/signal-outcomes`＋`/signals`上部リンクで確定（AskUserQuestion）。Gate4（`/tdd`）Cycle1着手。
- **Cycle1 Green完了（2026-09-27）**: migration3件（`weekly_prices`／`index_weekly_prices`／`signal_occurrences`）＋Model3件、`WeekDateNormalizer`（週の月曜に正規化）・`WeeklyPriceRecorder`（先勝ちの週重複排除＋UPSERT、例外は握りつぶして警告ログ）を新設し、`FetchExternalMarketDataAction`／`RefreshWatchlistMarketDataAction`の取得直後（トランザクション外）に配線。Red 23件→Gate4承認→Green。Green中にMySQL JSON型がキー順を正規化するため`SignalOccurrenceTest`の1件を`toBe`→`toEqual`へ修正（本人がGate4再承認）。フルスイート768 passed・pintクリーン。開発DBへmigrate適用（未適用だった2026-09-19の`per_undervalued`追加も同時適用）、`watchlist:refresh`実行で`weekly_prices` 9,218行（90銘柄、大半103週）・`index_weekly_prices` 207行、`week_date`全行月曜・重複なしを確認
- **Cycle1で発見した既存バグ（本人指示で後続CR、CHG-0022）**: `YahooFinanceChartClient`が末尾の直近取引日足（出来高あり・前週足と同終値）を除去せず、RSI・13週リターン等の指標計算で最終週が二重計上されている（7203・AAPLで実測）
- **CHG-0022 修正完了（2026-09-27）**: `YahooFinanceChartClient`に「末尾行が直前行と同じ週なら捨てる」規則を追加（`WeekDateNormalizer`を再利用、コンストラクタにデフォルト値付きで注入）。回帰テスト6件（うち4件Red）→Gate4承認→Green、フルスイート774 passed・pintクリーン。実データで7203.T/AAPL/^N225の末尾が週足で終わることを確認（7203のRSI 65.92→65.13）。`docs/ai-context/known-pitfalls.md`に追記
- **`/review`（強化レベル、review-score 198、`/code-review high`）対応完了（2026-09-27）**: MEDIUM 3件を修正——①週の重複除去がクライアント（末尾2行のみ）と保存側（全行）で食い違っていたため`WeekDateNormalizer::foldByWeek()`に一本化し、`WeekDateNormalizer`を`App\Services\MarketData`へ移設（汎用層が機能モジュールに依存していたLOW指摘も同時解消）、②data-model.md・traceability F-014行の更新漏れ、③UC-014エラーケース「保存失敗でもシグナル判定は継続」のAction単位テスト追加（出来高に負値を入れMySQL strictで書き込みを拒否させ、実物の例外処理を通す）。Red→Gate4承認→Green、フルスイート780 passed。**見送り（LOW）**: 週途中実行時に同一週の新しい終値より週足の終値を優先する件（週末取込の運用では確定後に両者一致を実測済み）、`recordIndex`/`recordHolding`の重複、`index_weekly_prices.index_name`のenum拡張漏れリスク（data-model.mdに注意書き追加のみ）。backlogへの転記は別セッションの未コミット変更と重なるため後日
- **Cycle2 Green完了（2026-09-27）**: `SignalOccurrenceRecorder`（INSERT IGNORE・例外は警告ログ）・`SignalOccurrenceMetricsBuilder`（根拠値17キーを1か所で組み立て）を新設し、両Actionのトランザクションのコミット後に`take_profit`／`buy`／`watchlist_buy`を記録。移送コマンド`signal-outcomes:backfill`を追加。Red 23件→Gate4承認→Green、フルスイート803 passed・pintクリーン。開発DBで移送425件（5週分）、再実行0件を確認
- **日付の基準をマニラ時間に統一（2026-09-27 本人指示）**: 調査の結果、アプリ・PHP・MySQLともUTCで、画面の日時もUTCのまま無表記で表示されていた。本人選択（AskUserQuestion）で「DB保存はUTCのまま、表示と日付判定をAsia/Manilaに」と確定。移送コマンドの週判定はCycle2でマニラ時間に対応済み（ADR-0017 D6追記）。画面表示の変換は別CRで対応予定
- **Cycle2で発見した既存バグ（別CRで対応予定）**: `FetchExternalMarketDataAction`の日本株処理で、`BuySignalDeterminationService::determine()`へ渡す`$fundamental`に`avg_revenue_growth`／`avg_operating_income_growth`が含まれず常にnull（DBには保存されている）。保有中の日本株の買い増し判定でADR-0015 D2（3期平均成長率救済）が効いていない。2026-09-21の最終`/review`修正①の配線漏れ。ウォッチリスト側（`RefreshWatchlistMarketDataAction`）は正しく渡している
- **CHG-0024（ADR-0018）画面表示のマニラ時間化 完了（2026-09-27）**: 設定値`app.display_timezone`（既定Asia/Manila）と`App\Support\DisplayTime`を新設し、取込履歴・新規投資候補の最終更新/ウォッチ記録・銘柄メモ・サマリーレポートのキャプション・株価チャートの日付・移送コマンドの週判定を変換。DB保存・JSON APIはUTCのまま。Red 16件→Gate4承認→Green、フルスイート818 passed、実画面で確認済み
- **並行作業の調整（2026-09-27）**: 別セッション`stock-auto-order-17`がCHG-0023（`JQuantsClient::fetchSectorInfo()`のHTTPエラー処理、候補M）を担当・`FetchExternalMarketDataAction`のセクター部分は基本触らない。番号はCHG-0023=別セッション、CHG-0024/0025・ADR-0018=本セッション。作業ツリーの未コミットdocs3ファイル（候補D）はどちらのセッションの作業でもなく、本人確認待ち（両セッションとも触らない）
- **CHG-0025 完了（2026-09-27）**: `FetchExternalMarketDataAction`の買い増し判定呼び出しで3期平均成長率を`$avgGrowth`から渡すよう修正（2行）。同じテストファイルでADR-0015 D3の回帰テストが`test()`内に入れ子になり実行されていなかった構造バグも修正（実行して成功）。実データ影響0件（保有JP株85銘柄）。フルスイート821 passed
- **候補D（ウォッチリスト棚卸しルール）・候補P（英字コード実数確認）を本人が直接コミット（`cf3d104`、2026-09-27）**: UC-012・user-guide.mdに月次/四半期/イベント時の棚卸しルールを明文化（自動除外・物理削除は追加しない）。実DB確認でJP銘柄マスタ147件中1件（`285A`キオクシアホールディングス）が英字入りコードと判明、指標登録は成功しセクター分類のみ未取得。候補Mの取得失敗調査とは別扱いで継続
- **CHG-0023（`JQuantsClient::fetchSectorInfo()`のHTTPエラー処理、候補M）を`feat/chg0020-signal-outcome-tracking`へマージ（`cfa94ba`、2026-09-30）**: 別セッションが`fix/chg0023-jquants-http-errors`ブランチで実装（全銘柄マスターを1リクエストでキャッシュ・429/5xx等のHTTP失敗を例外で表面化・4桁+英字コードのJ-Quantsコード変換`0`付与に対応）。コンフリクトは`FetchExternalMarketDataAction.php`1ファイルのみで、事前にワーキングツリー上で正しく解消済み（sectorフェッチのtry/catchとCHG-0020/0025の変更が両立）だったため検証のみで`git add`・コミット。**マージ前のテストで130件失敗**したが、原因はマージ内容ではなく、複数セッションが同一ディレクトリ・同一Sailコンテナ（`stock_auto_order_wsl-laravel.test-1`、`/root/workspace/stock_auto_order`に固定バインド）を共有しており、並行して走っていた別セッションのテスト実行がtesting DBのテーブルを一時的に欠落させていたため（当該プロセス終了後に再実行し829 passed・pintクリーンで確認）。マージ後、`fix/chg0023-jquants-http-errors`は`git merge-base --is-ancestor`で完全に取り込み済みと確認。ローカルブランチ削除は本人確認後に実施予定
- **複数セッション（stock-auto-order-15/60/bb等、命名はセッションごとに変動）が同一ディレクトリ`/root/workspace/stock_auto_order`・同一チェックアウトを共有していることを2026-09-30に確認**。worktree（`/tmp/stock_auto_order-chg0023`等）は存在するが、Sailの`COMPOSE_PROJECT_NAME`（`.env`はgitignore対象）が同一だと同じコンテナ＝メインworktreeのコードに繋がるため、別worktreeでの作業実態は要注意。DB操作（migrate系）前に他セッションへの声掛けを徹底することで合意
- **本セッション（2026-09-30、当時の名称`stock-auto-order-17`→再接続後`stock-auto-order-15`）の状況**: コード実装は行わず、並行セッション（71/60/bb、`stock-auto-order-ea`は途中で消滅）との役割調整のみ実施。候補M（CHG-0023）は71が既に実装・マージ済みと確認し、本セッションでの再実装は見送り。本人選択で次は**候補O（集中度ダッシュボード）**に新規worktreeで着手する方針。ただしbbが`.claude/`・`meta/adr/`配下のハーネス設定（Domain Boundary・Gitワークフロー・`/commit`・`prepare-merge`・Trialスキル3種等、CHG-0020ブランチ上に7コミット・push済み）をmainへマージ準備中のため、**bbのmainマージ完了後にそのmainから新規worktreeを分岐する**方針でbbと合意し、着手を保留（→ 2026-09-30、CHG-0020の71による退行バグ修正〔`5be5092`〕とmainマージ〔`e7753c5`〕の完了後、`feat/chg0026-concentration-dashboard`で着手。上記CHG-0026エントリ参照）。60から「候補OはCHG-0020のweekly_pricesが前提でCycle3完了までブロックされ得る」との指摘を受けたが、実際にはCycle1（`weekly_prices`/`index_weekly_prices`のmigration・UPSERT）は本ブランチに既にマージ済みのため、候補Oのデータ取得自体は着手可能と判断（60の状況報告はセッション間で古くなっていた可能性がある。**ピア自己申告より`git log`/`git status`の実測を優先する**教訓）
- **mainマージ前`/code-review enhanced`（review-score 348、8観点並列）実施・確定バグ1件を修正（2026-09-30）**: CHG-0023自身が持ち込んだ退行——`JQuantsClient::fetchStatements()`に`.throw()`を追加したのに、呼び出し元2箇所（`FetchExternalMarketDataAction`／`RefreshWatchlistMarketDataAction`）にcatchが付いておらず、J-Quantsのレート制限(429)等で例外が起きると保存済みのテクニカル指標までロールバックされ、シグナル判定・シグナル発生記録が丸ごとスキップされる（3つの独立した観点が収束、最高確度）。あわせて`fetchSectorInfo()`側の既存catchが`RequestException`のみで接続断（`ConnectionException`、共通の親`HttpClientException`）を捕捉していない件も発見・修正。対処は`fetchSectorInfo()`と同型（catch→警告ログ→空データで処理続行）。Red 3件→Gate4承認→Green、フルスイート832 passed・pintクリーン。CHG-0023固有の番号は追加発行せず本マージ準備の`/review`修正として記録（過去のマージ`/review`修正と同方式）
- **見送り（LOW、backlog行き）**: `WeeklyPriceRecorder`のrecordHolding/recordIndex重複、逐次DB書き込みの一括化余地（Action・Command計4箇所）、`WeeklyPrice`/`IndexWeeklyPrice`モデルの重複、`observedWeek()`実装が3箇所に分散、backfillの週判定とライブ経路の週判定が異なる（ADR-0017 D6で許容範囲と明記済み）、コードコメント言語規約の軽微な違反数件、`285A`型銘柄コードのJ-Quantsコード変換が未検証の前提を持つ
- **mainへマージ済み**（`e7753c5`、2026-09-30。2026-10-03にgit履歴で確認）
- 次: CHG-0020 Cycle3（超過リターン算出の純ロジック）コードはGate2・3承認後に`/tdd`で着手（想定Cycle: ①`weekly_prices`/`index_weekly_prices`/`signal_occurrences`のmigrationと価格UPSERT、②シグナル発生記録と既存6スナップショットの移送、③超過リターン算出の純ロジック＋分割前提の回帰テスト、④集計表示）

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

## 今後の対応（未着手）（2026-08-27追記、Phase5の実ブラウザ確認時に発見）

- **数値の未整形表示（Phase3〜5共通）**: `HoldingList`（保有一覧、Phase3）・`SignalList`（利確検討、Phase5）の含み益率・取得単価・現在値・分割指値の価格が、`{{ $value }}`で生の浮動小数点値をそのまま出力しており（例: 含み益率が`89.5793`と%記号なし表示、価格が`3632.676`のような小数点3桁表示）、実際にPlaywrightで画面を目視確認した際に発見した。レイアウト崩れではなく数値の可読性の問題。既存テストは生の数値部分文字列を検証する設計のため、これらのテストを含め画面3つ（Phase3/4/5）をまとめて後日別タスクで整形する（%サフィックス・価格の四捨五入・桁区切り等）方針とし、今回のPhase5サイクルでは対応を見送る
- **UC-004のE2Eテスト**: 一覧→詳細遷移のみの標準的な閲覧フローであり、`.claude/rules/31-e2e-testing.md`が対象とする「クリティカルフロー」に該当しないと判断し追加しない（Phase3/UC-002・Phase4/UC-003の同種の遷移もE2E化していないこととの一貫性を優先）
- **開発DBの保有データが空になっている**: Phase6の実ブラウザ確認時に発覚。`test@example.com`ユーザー自体も消えており(`db:seed`で復元済み)、CSV再取込等の保有データは未復元。並行セッションが`migrate:fresh`等を実行した際の巻き添えと推測されるが未確定。セクター配分ダッシュボードは空状態表示（「リバランス候補はありません」）のみ実ブラウザ確認済み。Phase7（`/candidate-check`）は`/verify`スキルで一時的にtinker投入した実データによりhappy path含め確認済み（検証後は削除しDBは空のまま）だが、いずれの画面も**本番相当のCSV再取込データでの確認はまだ行っていない**。実データでの最終End-to-End確認は保有データが復元された時点で改めて行う
- **重複度ラベルの閾値がBlade側とService側に分散（Phase7で発生）**: `resources/views/livewire/candidate/candidate-check.blade.php`が判定結果カードの「健全」/「やや偏り」/「偏り警告」ラベルを、`SectorAllocationCalculator`（UC-005）と同一の40%/70%閾値をBladeの`@php`ブロック内に再定義して導出している（`ShowCandidateCheckAction`/`CandidateOverlapCalculator`はラベルを返さず`overlap_rate`の数値のみ返すため）。閾値が2箇所に分散しており、将来どちらか一方だけ変更されるとラベル表示が実際の判定基準と乖離するリスクがある。是正するには`CandidateOverlapCalculator`にラベル算出を寄せるリファクタが必要（`ShowCandidateCheckAction`の出力契約変更を伴うため別途Red→Gate4→Greenサイクルが必要）。実害は表示ラベルのみ（`overlap_rate`の数値自体は正しい）のため優先度は低いが、次にこの画面に手を入れる際に解消する

## 今後の対応（未着手・スコープ確認済み）（2026-08-23追記、UC-007完了時点で更新）

- **フロントエンドUI（Livewire画面化）**: UC-001〜UC-009はこれまで全てAPIのみで実装してきた（`app/Livewire/`・`resources/views/`配下のBladeビューは0件、`docs/product/mockups/`は静的HTMLモックのみで実際に動く画面ではない）。Phase2（F-005/F-006/F-007/F-008）がAPIレベルで全完了したため、**次はLivewireコンポーネント・Bladeビューの実装（実際にブラウザでCSV取込〜各画面確認ができる状態にする）に着手する**方針をユーザーと確認済み
- **F-007（UC-007 市場全体指標表示）の3指標が未実装**: `GET /market-indicators`エンドポイント自体は実装完了したが、**米国10年債利回り・VIX指数・USD/JPY為替レートの3指標は取得ロジック自体が無く**（J-Quantsの範囲外のデータで、別途新規の外部APIクライアント選定〔ADR要〕が必要）、常に`null`のプレースホルダを返す。3指標の外部データ取得自体は別タスクとして先送り

