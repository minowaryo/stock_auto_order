# PLAN.md

> 2026-08-27（フロントエンド実装Phase5完了時点。UC-010 Gate4完了・コミット`ba239fe`分も含む）以前（Gate0セットアップ〜Phase1 Gate4サイクル完了・ADR-0002 NISA区分CR・ADR-0004分析エンジン実装〔設計確定〜各TDDサイクル、UC-001配線・UC-004画面・UC-003/UC-009新指標反映を含む〕完了・関連review指摘修正2件・UC-009サンプルレポート生成、F-010（UC-010）Gate1〜3ドキュメント叩き台整備完了、NISA区分内訳の書き込み・UC-004消費完了、未知の口座区分ラベルの扱いに関する`/review`指摘修正、Phase2 UC-008（Cycle1・Cycle2）完了、Phase2「UC-008→UC-005→UC-006」全完了・UC-007市場全体指標表示実装完了・実装済み全エンドポイントのIntegrationテスト網羅性監査完了、フロントエンド実装Phase0（基盤整備）完了、フロントエンド実装Phase1+2（CSV取込画面・サマリーレポート画面）完了、利確・リバランス閾値の動的分岐ロジック検討〔検討事項の記録のみ、実装はCHG-0006として2026-08-28〜29に別途完了〕、フロントエンド実装Phase3（UC-002保有銘柄一覧画面＋UC-007ウィジェット、共通レイアウトのcsrf-tokenバグ修正含む）完了、Phase3の`/review`拡張レベル実施（コミット汚染・ビュー内クエリ修正）、フロントエンド実装Phase4（UC-003銘柄詳細画面）完了、UC-010 Gate2/Gate3正式承認（買いシグナル7種の前提条件追加）完了、UC-010 Gate4完了・コミット（`ba239fe`）、フロントエンド実装Phase5（UC-004売買シグナル一覧画面）完了、およびフロントエンド実装Phase6（UC-005セクター配分ダッシュボード画面）完了〔2026-09-05、CHG-0011作業時に退避〕等）の完了済みエントリは `docs/history/plan-archive.md` に退避済み。
> **運用ルール**: PLAN.mdは300行を超えないよう保つ。300行に近づいたら、Statusが「完了」相当（Green確認完了・マージ済み等）の最も古いエントリから`docs/history/plan-archive.md`へ退避し、本ファイル冒頭のこの注記を更新する（詳細は `.claude/rules/60-docs.md` 参照）。300行超過に伴い「数値表示フォーマット修正完了（2026-08-28）」「UC-010買い増し候補セクションのフロントエンド統合完了（2026-08-28）」の2エントリを退避済み（2026-09-06、CHG-0012 Phase 0作業時）。約298行に達したため「利確検討ラインの動的分岐 CHG-0006（2026-08-28〜29）」「売買シグナル画面の可読性改善（2026-08-28）」の2エントリを退避済み（2026-09-06、CHG-0013／ADR-0012作業時）。300行超過に伴い「取込後サマリーレポートのグローバルナビタブ化 CHG-0008（2026-09-05）」の1エントリを退避済み（2026-09-12、F-012・CHG-0012のステータス記述を実態〔mainマージ済み〕に修正した際に発生した増分に対応）。300行超過に伴い「売買シグナル画面 判定チェックリスト表示 CHG-0007（2026-08-29〜09-05）」の1エントリを退避済み（2026-09-12、CHG-0016〔新規投資候補テーブルの固定ヘッダー化〕作業時）。300行超過に伴い「整理検討（含み損）候補一覧の新設 F-011（2026-09-05〜06）」の1エントリを退避済み（2026-09-21、CHG-0017/CHG-0018マージ後の最終`/review`・コミット・push前整理時）。300行超過見込みに伴い「財務健全性フィルタに営業利益率を追加 CHG-0012（2026-09-06〜07）」の1エントリを退避済み（2026-09-27、エビデンス提言取込・CHG-0020 Phase 0作業時）。300行超過見込みに伴い「成長率算出バグの是正 CHG-0013（2026-09-06〜12）」の1エントリを退避済み（2026-09-30、集中度ダッシュボード・CHG-0026 Phase 0作業時）。300行到達に伴い「お気に入り未保有銘柄ウォッチリスト／新規投資候補画面の刷新 F-012・CHG-0014（2026-09-06〜12）」の1エントリを退避済み（2026-10-01、CHG-0026 Cycle3作業時）。300行超過に伴い「新規投資候補テーブルの固定ヘッダー化・重複列マージ CHG-0016（2026-09-12）」の1エントリを退避済み（2026-10-01、CHG-0026のmain取り込み時）。約295行に達したため「バリュー/景気敏感銘柄向け判定ロジック分岐 CHG-0017（2026-09-19）」「買い増しシグナル共通前提の緩和とPER単体シグナル CHG-0018（2026-09-19〜）」の2エントリを退避済み（2026-10-01、CHG-0030作業時）。


## 集中度ダッシュボードの判定色とセクター配分との行き来（CHG-0030・ADR-0021・UC-015／UC-005）Cycle1・2 Green完了（2026-10-01〜）

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
- 次: コミット、マージ前チェック（`prepare-merge`）

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

## 米国株・投資信託のセクター分類と市場別・金額付き表示（CHG-0029・ADR-0020）Green完了（2026-10-01）

### Decision

- 発端: セクター配分の「未分類」が評価額の約73%（米国株約978万円・投信約157万円。日本株は最新スナップショットで全件分類済み）で常時「偏り警告」。米国株・投信にはセクター取得処理が存在しない
- 本人判断: 米国株=Finnhub業種／投信=専用カテゴリで進める。追加要望: 米国と日本株を区別／合計額も表示／構成比は小数1桁（最後の1つは実装済み・未コミット）
- 設計（ADR-0020）: `sector_classifications.market`追加・一意制約を`(market,name)`へ、`FinnhubClient::fetchIndustry()`、表示は市場別＋評価額＋市場小計＋全体合計、既存分は`sectors:backfill`

### Files touched

`docs/adr/ADR-0020-*.md`（新規）、`docs/product/use-cases.md`（UC-005・承認記録=承認待ち）、`docs/architecture/data-model.md`（`sector_classifications`）、`resources/views/livewire/sector/sector-dashboard.blade.php`（小数1桁のみ）

### Status

Red 19件（通過3件は回帰ガード）→Gate4承認→Green。フルスイート877 passed・pint適用済み。追加: マイグレーション`2026_10_01_000000`、`SectorClassificationResolver`、`sectors:backfill`、`FinnhubClient::fetchIndustry`。**未実施**: 実画面確認、`sectors:backfill`の本番DB実行（実行すると米国株分の保有ごとにFinnhubを呼ぶ）、`/review`、コミット。

## 売買シグナル画面へのキープ（hold）表の追加（CHG-0028）Green完了（2026-10-01）

### Decision

- 本人要望: 売買シグナルにホールドも出す／既存3テーブルと同じ表形式で。`hold`はUC-013（F-013）の既存バケツで、現状はサマリーレポートの簡易表にしか出ていない
- 本人判断（すべて推奨案）: `hold`のみ（`core_accumulation`除外）／列=銘柄・評価額・含み損益率・要観察・ヘルスライン・セクター／並びはCHG-0027の共通切替／`feat/chg0027-...`から`feat/chg0028-signal-hold-table`を分岐

### Files touched

`docs/product/use-cases.md`（UC-013業務ルール・承認記録）、`docs/rcid/traceability-matrix.md`、`docs/ai-context/module-map.md`、`app/Actions/Portfolio/ShowHoldListAction.php`（新規）、`ClassifyHoldingsAction`（`sector_name`追加）、`SignalList`、`signal-list.blade.php`、`tests/Feature/CHG0028SignalHoldTableTest.php`

### Status

Red 12件→Gate4承認→Green。フルスイート858 passed・pint適用済み・`/review`実施済み（HIGHなし）。既知の懸念（別CR候補）: 描画ごとに`ClassifyHoldingsAction`が3 Actionを二重実行する。実画面確認は未実施。

## 売買シグナル画面の評価額ソート・整理検討の評価額列/列順統一（CHG-0027）Green完了（2026-10-01）

### Decision

- 本人要望: 整理検討にも評価額を出す／全テーブルのソート順を確認し評価額で並べたい（何がインパクト大かを知りたい）。本人判断: (1) 整理検討の列順を利確・買い増しと統一（銘柄→評価額→含み損益率）、(2) 既定の並びを評価額順に変更、従来の透明マルチキー（財務健全性等）は「おすすめ順」として選択可、(3) それ以外は提案どおり
- Actionは`execute(string $sort = 'recommended')`（`App\Support\SignalListSort`）。既定を従来のまま残すのは、JSON APIとUC-013（`ClassifyHoldingsAction`のバケツ内並び）が依存するため。画面（`SignalList`）だけが`market_value`（既定）/`recommended`を切替（ボタン・URL `?sort=`）。同額は従来の並びで決着
- **スコープ外（本人の「提案どおり」に含まれるが今回は未着手）**: 保有銘柄一覧・サマリレポートのバケツ別表への評価額列追加は、別途判断待ち

### Files touched

`docs/product/use-cases.md`（UC-004/010/011・承認記録）、`docs/rcid/traceability-matrix.md`、`app/Support/SignalListSort.php`（新規）、`app/Actions/Signal/Show{SignalList,BuySignalList,LossReviewList}Action.php`、`app/Livewire/Signal/SignalList.php`、`resources/views/livewire/signal/signal-list.blade.php`、`resources/views/components/loss-review-table-colgroup.blade.php`、`tests/Feature/CHG0027SignalSortTest.php`（新規13件）

### Status

Red 9件→Gate4承認→Green。フルスイート845 passed・pint適用済み。実画面確認・`/review`・コミットは未実施。

## エビデンス提言の取込とシグナル検証基盤（CHG-0020・ADR-0017・F-014・UC-014）Phase 0 完了・Gate1〜3叩き台作成中（2026-09-27〜）

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
- 次: CHG-0020 Cycle3（超過リターン算出の純ロジック）コードはGate2・3承認後に`/tdd`で着手（想定Cycle: ①`weekly_prices`/`index_weekly_prices`/`signal_occurrences`のmigrationと価格UPSERT、②シグナル発生記録と既存6スナップショットの移送、③超過リターン算出の純ロジック＋分割前提の回帰テスト、④集計表示）

## mainへのマージ・最終`/review`・push（CHG-0016・CHG-0017・CHG-0018・F-013第1段階、2026-09-21）

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

## 売買シグナル画面のPER/PBR表示をUC-004・UC-011に拡張（CHG-0019、ADR-0016 D4）Green完了（2026-09-23）

### Decision

- 本人指摘: 「売買シグナル画面と投資候補一覧のデザインが違いすぎる」「PER/PBRが画面に出ていない」。調査の結果、真の項目差分はPER/PBRのみと判明（RSI・ROE・自己資本比率・営業利益率は既に判定チェックリストのチップとして両画面に存在）。フォーマット差分は当時未マージだったCHG-0016で既に解消済みと判明
- 続けて調査したところ、CHG-0018（ADR-0016 D3）が既に買い増し候補（UC-010、および`criteria`を共有する新規投資候補UC-012）にPER/PBRチップを追加済み・mainマージ済みであることが判明（本人・別セッションによる並行作業）。ADR-0016 D3は「対象はUC-010のみ、利確検討（UC-004）・整理検討（UC-011）は対象外」と明記されていたため、当初計画していた3テーブル一律追加は不要と判断し、UC-004/UC-011のみへスコープを絞り直した
- 本人確認（AskUserQuestion）: 表示方法は売買シグナル画面のチップ形式を踏襲しつつ、表示項目は両画面の和集合とする方針で合意。「PER・PBR以外に差分は無いか」を確認した上でGate4承認を得た
- 設計判断: UC-010のPERはmet/near/unmet閾値（≤15）を持つが、これは`per_undervalued`シグナルという実際の判定ロジックと対応しているため。UC-004/UC-011にはPERを使う判定ロジックが存在せず、割安であることは利確・整理を後押しする理由にならない（むしろ逆）ため、機械的に同じ閾値を持ち込むと意味が反転して誤解を招く。よってUC-004/UC-011のPER・PBRは両方ともPBRと同じ基準なしの参考表示（`status`＝`info`）とした（ADR-0016 D4として追記）
- Red→Gate4承認→Green、フルスイート745 passed（0 failed）・pintクリーン。実機確認（Sailコンテナ内にPlaywright+Chromiumをセットアップしスクリーンショット取得、Playwright MCPは接続タイムアウトのためフォールバック使用）で3テーブルとも判定チェックリストの想定位置にPER/PBRが表示され、買い増し候補のみ閾値で色分け・利確検討/整理検討は中立表示であることを確認済み

### Files touched

**コード（Green）**: `app/Services/Analysis/SignalCriteriaEvaluator.php`（`evaluateTakeProfit()`/`evaluateLossReview()`にPER・PBR行を追加）、`app/Actions/Signal/ShowSignalListAction.php`／`ShowLossReviewListAction.php`（metricsへper/pbr配線）、`resources/views/livewire/signal/signal-list.blade.php`（テーブル固定幅1478→1622px・1512→1656px）。テスト: `tests/Unit/Services/Analysis/SignalCriteriaEvaluatorTest.php`、`tests/Feature/SignalListTest.php`、`tests/Feature/UC004SignalListTest.php`、`tests/Feature/UC011LossReviewListTest.php`

**ドキュメント**: `docs/adr/ADR-0016-undervalued-quality-buy-signal.md`（D4追記）、`docs/product/use-cases.md`（UC-004/UC-011の`criteria`項目数・業務ルール改訂、承認記録1行）、`docs/rcid/traceability-matrix.md`（CHG-0019行・F-004/F-011行に注記）、`PLAN.md`（本エントリ）

### Status

**Gate4承認済み・Green実装完了、実機確認済み**。コミット・push未実施（本人の明示的指示待ち）。

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

## ポートフォリオ分類ダッシュボード（F-013・UC-013・ADR-0014・CHG-0015）第1段階Green完了・mainマージ済み（2026-09-08〜09-19）

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

**コード（Green、`feat/f013-portfolio-buckets`ブランチ、2026-09-19）**: Cycle 1（`b7c90fc`）: `app/Actions/Portfolio/ClassifyHoldingsAction.php`新規（純ロジック、6バケツへの再投影・排他解決、DBスキーマ変更なし）＋`ClassifyHoldingsActionTest.php`。`/review`3件即修正（symbol_code単独キーの衝突対策・`orderedBucketHoldings()`のnullガード・`FundamentalHealthEvaluator::evaluate()`の重複呼び出し解消）。実データ135銘柄で件数整合を確認（reduce64+hold61+increase10=135）。Cycle 2（`728b66b`）: `ShowImportSummaryReportAction.php`から独自候補選定ロジック（`buildTakeProfitCandidates`/`buildRebalanceCandidates`/`buildNewCandidateItems`/`composite_score`等）を削除しCycle 1の`ClassifyHoldingsAction`呼び出しに置換（D9-1）、`ImportCsvAction.php`のプレースホルダー行作成を削除（D9-2）、`summary-report-body.blade.php`を分類俯瞰UIに置換。**このコミットに元の計画の「Cycle 3」（`ShowSignalListAction`への`take_profit`並び順追加、D10-1）も一緒に含めて実装済み**（`ShowBuySignalListAction`/`ShowLossReviewListAction`と同じcompareRows方式）。実データでの認証済みcurl確認済み（旧フィールド0件・分類集計が一致・ソート順も設計通り）。E2Eテストなし（`.claude/rules/31-e2e-testing.md`の既存判断＝非クリティカルフローの集計画面は対象外、と整合）。フルスイート623 passed。追加`/review`修正（`7f70ec3`）: HIGH（`ShowImportSummaryReportAction`が引数の`ImportBatch`を無視し常に最新スナップショットを見る実装だったため、Show画面のキャプションが古いバッチURLでも「今日」の日付を表示する不整合を修正）、MEDIUM（`bucket_reason`をD9-4で規定した「`SignalCriteriaEvaluator`の達成度データからの機械生成」に変更、当初はバケツ種別ごとの固定文言だった）。フルスイート625 passed、pintクリーン

### Status

**Gate1（requirements.md）／Gate2（use-cases.md UC-013）を2026-09-17に本人が最終承認**。第1段階は DB スキーマ変更を伴わないため Gate 3 は影響範囲確認のみ（2026-09-17確認済み。第2段階の `portfolio_classifications` は別CRで Gate 3 実質承認）。**Cycle 1〜2（当初計画のCycle 3の`take_profit`並び順分もCycle 2に含めて実装）がGreen完了・`/review`2回対応済み、2026-09-21にmainへマージ**（コミット`3ac3f9c`。CHG-0017 value/cyclical judgment branchingとの合流マージで、両ブランチのロジックが同時に走って初めて判明した競合3件を解消——`BuySignalDeterminationService`のコンストラクタ/引数統合、テストフィクスチャの偶発的なD1救済該当の修正、`peg_undervalued`と`per_undervalued`が同一PER値に反応することによるアサーション更新。マージ後フルスイート720 passed）。第1段階の実装は完了。残るのは第2段階（`portfolio_classifications`永続化・週次遷移表示・トレードジャーナル、別CRでGate3実質承認から）のみ

> **ブランチ状況の補足**: Phase 0（2026-09-08）は当時の`feat/f012-favorites-watchlist`ブランチ上で行われ、F-012マージ（`444ee65`）でmainに統合済み。Gate2最終確定分（2026-09-17）・Cycle1〜2実装（2026-09-19）はmain（`4d6071e`）から新規に切った`feat/f013-portfolio-buckets`ブランチ上で作業。mainへのマージは`feat/chg0017-value-cyclical-judgment`との合流マージ（`3ac3f9c`）として実施（詳細は上記CHG-0017エントリ参照）。

## 今後の対応（未着手）（2026-08-27追記、Phase5の実ブラウザ確認時に発見）

- **数値の未整形表示（Phase3〜5共通）**: `HoldingList`（保有一覧、Phase3）・`SignalList`（利確検討、Phase5）の含み益率・取得単価・現在値・分割指値の価格が、`{{ $value }}`で生の浮動小数点値をそのまま出力しており（例: 含み益率が`89.5793`と%記号なし表示、価格が`3632.676`のような小数点3桁表示）、実際にPlaywrightで画面を目視確認した際に発見した。レイアウト崩れではなく数値の可読性の問題。既存テストは生の数値部分文字列を検証する設計のため、これらのテストを含め画面3つ（Phase3/4/5）をまとめて後日別タスクで整形する（%サフィックス・価格の四捨五入・桁区切り等）方針とし、今回のPhase5サイクルでは対応を見送る
- **UC-004のE2Eテスト**: 一覧→詳細遷移のみの標準的な閲覧フローであり、`.claude/rules/31-e2e-testing.md`が対象とする「クリティカルフロー」に該当しないと判断し追加しない（Phase3/UC-002・Phase4/UC-003の同種の遷移もE2E化していないこととの一貫性を優先）
- **開発DBの保有データが空になっている**: Phase6の実ブラウザ確認時に発覚。`test@example.com`ユーザー自体も消えており(`db:seed`で復元済み)、CSV再取込等の保有データは未復元。並行セッションが`migrate:fresh`等を実行した際の巻き添えと推測されるが未確定。セクター配分ダッシュボードは空状態表示（「リバランス候補はありません」）のみ実ブラウザ確認済み。Phase7（`/candidate-check`）は`/verify`スキルで一時的にtinker投入した実データによりhappy path含め確認済み（検証後は削除しDBは空のまま）だが、いずれの画面も**本番相当のCSV再取込データでの確認はまだ行っていない**。実データでの最終End-to-End確認は保有データが復元された時点で改めて行う
- **重複度ラベルの閾値がBlade側とService側に分散（Phase7で発生）**: `resources/views/livewire/candidate/candidate-check.blade.php`が判定結果カードの「健全」/「やや偏り」/「偏り警告」ラベルを、`SectorAllocationCalculator`（UC-005）と同一の40%/70%閾値をBladeの`@php`ブロック内に再定義して導出している（`ShowCandidateCheckAction`/`CandidateOverlapCalculator`はラベルを返さず`overlap_rate`の数値のみ返すため）。閾値が2箇所に分散しており、将来どちらか一方だけ変更されるとラベル表示が実際の判定基準と乖離するリスクがある。是正するには`CandidateOverlapCalculator`にラベル算出を寄せるリファクタが必要（`ShowCandidateCheckAction`の出力契約変更を伴うため別途Red→Gate4→Greenサイクルが必要）。実害は表示ラベルのみ（`overlap_rate`の数値自体は正しい）のため優先度は低いが、次にこの画面に手を入れる際に解消する

## 今後の対応（未着手・スコープ確認済み）（2026-08-23追記、UC-007完了時点で更新）

- **フロントエンドUI（Livewire画面化）**: UC-001〜UC-009はこれまで全てAPIのみで実装してきた（`app/Livewire/`・`resources/views/`配下のBladeビューは0件、`docs/product/mockups/`は静的HTMLモックのみで実際に動く画面ではない）。Phase2（F-005/F-006/F-007/F-008）がAPIレベルで全完了したため、**次はLivewireコンポーネント・Bladeビューの実装（実際にブラウザでCSV取込〜各画面確認ができる状態にする）に着手する**方針をユーザーと確認済み
- **F-007（UC-007 市場全体指標表示）の3指標が未実装**: `GET /market-indicators`エンドポイント自体は実装完了したが、**米国10年債利回り・VIX指数・USD/JPY為替レートの3指標は取得ロジック自体が無く**（J-Quantsの範囲外のデータで、別途新規の外部APIクライアント選定〔ADR要〕が必要）、常に`null`のプレースホルダを返す。3指標の外部データ取得自体は別タスクとして先送り

