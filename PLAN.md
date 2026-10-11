# PLAN.md

## 日本株の財務指標の計算元の修正（CHG-0050・ADR-0030）実装完了・mainマージ済み（2026-10-09）

### Decision

- 発端: CHG-0049段階0のJ-Quants Lightの効果の見積もり（2026-10-09）で、`FundamentalIndicatorMapper::map()`が種類を見ずに最新の開示行から指標を計算していることがわかった。最新の行が四半期決算の銘柄（149銘柄中52）でROE・配当が空、PERが累計EPSで最大6.7倍に過大。業績予想の修正の行（3銘柄）では全指標が空。修正の行は`CurPerType='FY'`を持つため、成長率の「最新の通期決算」にも誤って選ばれうる
- 本人の判断（2026-10-09）: Lightの契約に関わらず直す。計算元は項目ごとに使い分ける案を選択（全項目を通期から・ROE等だけ直す、の2案と比較）。Lightは契約する方向（3か月程度のお試し）だが、契約作業はいまはしない
- 設計（ADR-0030）: D1 修正の行は`JQuantsClient::fetchStatements()`で除外。D2 PER・ROE・営業利益率・配当利回り・配当性向は最新の通期決算の行から（空なら空、古い年度に遡らない）。D3 自己資本比率・PBRは値がある最新の実績の行から。米国株は対象外
- ADR番号: ADR-0028は別セッション（`fix/chg0031-review-fixes`、未コミット）が使用中、ADR-0029はCHG-0049段階1の予定のため、0030を採番

### Files touched

- `docs/adr/ADR-0030-jp-fundamentals-source-row.md`（新規）、`docs/rcid/traceability-matrix.md`（CHG-0050行）、`docs/architecture/data-model.md`（`fundamental_indicators`の各カラム・`financial_statements`の注記）
- Red: `tests/Unit/Services/Analysis/FundamentalIndicatorMapperTest.php`、`tests/Unit/Services/MarketData/JQuantsClientTest.php`、`tests/Feature/FetchExternalMarketDataActionTest.php`
- Green: `app/Services/MarketData/JQuantsClient.php`、`app/Services/Analysis/FundamentalIndicatorMapper.php`

### Status

ブランチ`fix/chg0050-indicator-source`（ワークツリー`.claude/worktrees/chg0050-indicator-source`、テストDB`testing_chg0050`）。Gate 4承認（2026-10-09）、Red（新規7件）→Green→Refactor済み、フルスイート1736件Green。mainへマージ済み（2026-10-09、マージ結果で1781件Green）。ブランチ・ワークツリーは削除済み。次: 次回の外部データ取得で値が入れ替わることを実データで確認する（例: 2914のPERが65.1→約25、ROEが表示される）

## 定性情報の数値化（判定APIによる開示・ニュースの判定、CHG-0049）段階0 PoC進行中——このPCでの事前確認は完了、別PCでの作業待ち（2026-10-07〜08）

### Decision

- **発端と方針**:
  - 本人の「Jev活用 引き継ぎメモ」（2026-10-07）を参考に、アプリを改善できるか、実行可能性が高いかを検証する依頼。
  - 本人方針: 目的は「定性情報の数値化と利活用」。必要なら要件の変更も考えてよい。
  - 開発は別PCで行う。このPCでは、計画・ドキュメント・アプリ外の検証まで。
- **結論**（サブエージェント計8本: 調査4本、追加調査1本、批判的レビュー2本〔計画書・文書〕、組み込み設計の叩き台1本）:
  - **組み込み**: 容易。LaravelのHTTPクライアントで直接呼べる。
  - **API費用**: ほぼゼロ。Jevは月$0.01〜$0.7、Haiku 4.5は月$0.2〜$12。
  - **モデル**: 既定はHaiku 4.5。Jevは早期アクセスでSLAがなく、独立評価でもHaikuに劣ったため、本検証でHaikuより有意に良い場合だけ採用する。
  - **ボトルネックは定性情報の取得**:
    - 数値（業績予想・配当予想の修正）: J-Quants Light（月¥1,650）の`/v2/fins/summary`で、開示日時・修正後の値つきで取れる。無料版は12週遅延で、適時の検知には使えない。
    - 本文: 正規の自動取得はTDnetアドオン（月¥11,000）だけ。本文が必要な用途がGOになった場合だけ、決算期の後に1か月ずつ契約する（年約4.4万〜5.1万円）。
    - 無料の補助: EDINET（臨時報告書・大量保有報告書）、米国株はEDGAR・Finnhub。
    - 使わないもの: TDnet公開画面の自動収集、株探などのスクレイピング。
  - **用途**:
    - A: 投資仮説チェック。手入力の仮説登録は本人が却下（2026-10-07）し、既存データから仮説を自動で作る案に置き換えた。
    - A候補: 落ちるナイフ除外・一過性利益の検出。AIを使わない代替（タイトルの正規表現＋XBRLの数値）を上回った場合だけ採用する。
    - 対象外: セクター・テーマ分類（既存機能と重複）。
  - **要件変更の案**（未適用。Gateで承認）:
    - 内蔵LLMの対象外を「対話・自由記述の出力」に限る。
    - 主要因開示に、根拠箇所の抜粋を加える。
- **番号**: CHG-0049。ADR-0028/0029・F-018・UC-020は、段階1で採番する予定（未確保）。

### Files touched

- **新規の文書**: [変更要求案](docs/product/qualitative-signal-proposal.md)、[PoC検証計画](docs/product/qualitative-signal-poc-validation-plan.md)、[PoC結果記録](docs/product/qualitative-signal-poc-results.md)、[組み込み設計の叩き台](docs/product/qualitative-signal-integration-draft.md)
- **新規のスクリプト**: `scripts/poc/`（アプリ外の評価スクリプト6本とREADME。出力はgit管理外の`storage/app/qualitative-eval/`）
- **更新**: `scripts/README.md`、`docs/rcid/traceability-matrix.md`（CHG-0049行）、`PLAN.md`（本エントリ、CHG-0020・CHG-0030エントリの退避）、`docs/history/plan-archive.md`
- **未変更**: アプリコード・requirements.md・use-cases.md

### Status

**段階0（PoC）進行中。** 本人は段階0への着手を承認済み（2026-10-07）。要件・Gateの承認はまだない。

このPCで実施した事前確認（詳細は[PoC結果記録](docs/product/qualitative-signal-poc-results.md)）:

- [x] **事前確認1**（Haiku×公開データchABSA 120件、APIキーなしでClaude Codeのヘッドレス実行）:
  - 良い／悪いの2分類で93.3%（日本語の指示）。自己申告の確率が0.9以上の65.6%の項目は全件正解。
  - 確率は、回答の割合より自己申告の確率が良い → 検証計画 §4を修正した。
  - 正しさの確認: 正解は人手のラベル。回答の対応付けに不備なし。20件の抜き取りで、Haikuの実質の誤りは1件（対象の取り違え）。
- [x] **事前確認2**（Q1の形式、100組）:
  - AUC 0.946。見逃しは50件中1件、誤報は50件中14件。
  - 出現率3%では、注記のうち本当に仮説を損なうのは閾値0.8で約22%、0.9でも約36% → 関連度での絞り込みと、注記を確認待ちにする方針が必要。
- [x] **別PCで使うスクリプト**: Jevで同じ項目を比べる`jev_eval.py`、Lightの効果を無料版のデータで見積もる`jquants_light_effect.py`（どちらも偽のデータでのみ動作確認）。
- [x] **組み込み設計の叩き台**: 最初のスライスは、AIを使わない予想修正の数値による注記。

**別PCで次にやること（上から順に）**:

1. [x] `jquants_light_effect.py`を無料版のキーで実行した（2026-10-09、日本株149銘柄）。無料版の決算は平均82.5日古い。業績予想の修正は2年で35銘柄・59件。詳細は[PoC結果記録](docs/product/qualitative-signal-poc-results.md)。
   - [x] 本人の判断（2026-10-09）: Lightは契約する方向。まず3か月程度のお試し。**契約の前に、下の別件の修正を済ませる。**
2. [ ] （本人の判断で保留。2026-10-09、実行するかは後で検討）`jev_eval.py thesis`・`chabsa`を実行する（OpenRouterで、クレジット購入を含めて$1〜2。最初は`--limit 5`で応答の形を確認）。結果をPoC結果記録に追記する。
3. [ ] 本検証: 開示75件（日本株60件はIRサイトから手作業、米国株15件はEDGAR等。Q10用の買いシグナル時点の開示は別途）を集め、伏せ字にしてラベルを付け、Haiku（とJev・ローカルLLMを任意で）を比較する（検証計画 §7。ラベル付けは約20〜28時間）。
4. [ ] 合否の判定（検証計画 §5）。GOの用途だけを、組み込み設計の叩き台をもとにGate 1から進める。NO-GOなら、見送りをADRに記録する。

**本人の判断待ち**:

- [ ] PoCの合否基準の水準
- [ ] TDnetアドオンの契約（本文が必要な用途がGOになった場合だけ）
- [ ] J-Quants利用規約8条（私的利用）と、本文を外部のAI APIへ送ることの関係。不安が残るなら、本文の判定はローカルLLMに限る

**別件（2026-10-09に確認。未修正。修正には本人の承認が必要）**:

- `FundamentalIndicatorMapper::map()`は、種類を見ずに最新の開示行からPER・PBR・ROE・自己資本比率・営業利益率・配当利回りを計算している。
  - 最新の行が予想の修正（実績が空）だと、すべて空になる（いま3銘柄）。
  - 最新の行が四半期決算だと、ROE・配当が空になり、BPSも多くの場合空になる（いま52銘柄）。DBでもROEが空の銘柄が149件中70件。
  - 「16件」の切り捨て自体には実害がない（無料版は最大13行）。
- Lightに切り替えると、四半期の行が最新になる銘柄が増え、空の指標が大幅に増える。
- 対応案: 別CRで、再発防止テストを先に書いてから直す（例: ROE・BPS・配当は最新の通期の行から取り、実績が空の行は飛ばす）。財務判定・画面の値が変わるユーザー向けの変更。本人はこの方針での修正を承認済み（2026-10-09）。テストケースはGate 4で承認を得る。

docs/ai-context（glossary・module-map等）の更新は、段階1（要件化）で行う。

## 売買シグナル画面などへの業種比較の色付けの展開と買い増し判定のPER基準の業種相対化（CHG-0048・ADR-0026）実装完了（2026-10-04）

### Decision

- 発端: 本人から「整理検討以外のすべての表に反映。整理検討はラベル文字のみ」「買い増し候補のPER≦15は判定基準なので判定にも反映が妥当では」（2026-10-04）
- 設計（ADR-0026）: 利確検討・買い増し候補・キープ・ウォッチリストのPER・PBRチップに業種比較の色とラベル、整理検討はラベルのみ。買い増し判定のPER条件を、信頼度が高・中の業種で比率0.80未満に置き換え、それ以外は従来の≦15（試算: 174件中の該当36→42件、変化8件）。財務チップの二段階も整理検討以外に展開。保有一覧・銘柄詳細はCHG-0034（Gate 2承認済み）と同じサイクルで実装
- 改訂（2026-10-04、本人が方針を承認。文面は確認待ち）: 固定の15は業種を見ず粗いとの指摘を受け、日本株の基準を時価総額加重からJPXの単純平均に変更（信頼度lowが7→4業種）、買い増し判定は信頼度lowの業種で比率0.70未満・業種のない銘柄だけ従来の≦15に（試算: 該当36→40件）。コードは基準値の差し替えを別サイクル（2c）で実施
- 注意: 判定の変更は2026-09-27の「実測が先」の決定を本人の指示で先に進めるもの。実測用の記録（判定に使った基準・段階をシグナル発生記録に保存）は後続CR

### Files touched

`docs/adr/ADR-0026-*.md`（新規）、`docs/adr/ADR-0015-*.md`・`ADR-0016-*.md`（注記）、`docs/product/use-cases.md`（UC-004・UC-010・UC-011・UC-012・UC-013・承認記録）、`docs/rcid/traceability-matrix.md`（CHG-0048）。コードは未着手

### Status

実装完了（2026-10-04）。ブランチ`feat/chg0034-sector-valuation-color`で、CHG-0034とCHG-0048を一緒に実装（各Cycleで Red→Gate4→Green）。Cycle1: 判定ロジック（`ValuationBenchmarkJudge`・`FundamentalMetricToneEvaluator`）。Cycle2a/2b: 基準値の設定・保有一覧と銘柄詳細の表示。Cycle2c: 日本株の基準をJPXの単純平均へ。Cycle3a/3b: 売買シグナル画面・新規投資候補のチップ（整理検討はラベルのみ）。Cycle4: 買い増し判定のPER基準の業種相対化。フルスイート全件Green（1574件）、`/review`（強化レベル）実施済み。フォローアップ（2026-10-05、mainマージ済み2e94baa）: 利確検討の注記を業種相対にそろえ、シグナル発生記録にPERの判定の基準を保存、チップの文字色と銘柄詳細のバッジの折り返しを修正。UC-010の改訂文面と基準値の表は本人が確認済み。銘柄詳細の出典の表示は短い名前に変更（詳しい算出方法はvaluation-benchmarks.md）

## 売買シグナル画面のNISA保有区分バッジ（CHG-0047）実装完了・mainマージ済み（2026-10-04）

### Decision

- 発端: CHG-0046でキープ表のNISAのみ銘柄（AAPL等）が利確対象外なのに黄表示になる点を報告。本人要望で、NISAのみ／一部NISAを銘柄セルに表示する
- 本人判断（推奨案）: 4テーブルすべて・「NISAのみ」「NISA一部」の2種類（株数の内訳なし）・成長枠とつみたて枠は区別しない
- 設計: 判定は純関数`App\Support\NisaHoldingStatus`に集約し、4つのActionが行に`nisa_holding`を追加。表示は`x-nisa-holding-badge`を共用。データは既存の`holding_snapshot_accounts`（DB変更なし）

### Files touched

`docs/product/use-cases.md`（UC-004業務ルール・承認記録）、`docs/product/ui-guidelines.md`、`docs/rcid/traceability-matrix.md`、`app/Support/NisaHoldingStatus.php`、`app/Actions/Signal/ShowSignalListAction.php`・`ShowBuySignalListAction.php`・`ShowLossReviewListAction.php`、`app/Actions/Portfolio/ShowHoldListAction.php`、`resources/views/components/nisa-holding-badge.blade.php`、`resources/views/livewire/signal/signal-list.blade.php`、`tests/Unit/Support/NisaHoldingStatusTest.php`、`tests/Feature/CHG0047SignalNisaBadgeTest.php`

### Status

Red 16件→Gate4承認→Green。フルスイート1226 passed・pint適用済み。本人指示（小さな変更のためコミット・マージ・pushまで一括可）でmainへ`--no-ff`マージ。ブランチ`feat/chg0047-nisa-badge`

## 売買判断の対ガチホ効果検証（CHG-0033・F-017／UC-017〜019・ADR-0024）UC-017・018 Gate 2承認済み（2026-10-04）

### Decision

- 2026-10-03 本人承認の範囲を正式要件F-017へ反映（Gate 1）。今回はモックを作らず、UC本文と計算例でGate 2をレビューする（本人指示）。
- 2026-10-04 本人指示: CHG-0020の別CR候補(a)（売却済み銘柄の週足が止まる生存バイアス）をF-017の株価追跡に統合。売却済み銘柄と`signal_occurrences`に直近26週以内の発生がある銘柄を、最終売却+26週と最終シグナル発生+26週の遅いほうまで、既存`WeeklyPriceRecorder::recordHolding`の104週UPSERTで追跡する。ADR-0017 D2の改訂方針と追加取得数の見積もりは[ADR-0024](docs/adr/ADR-0024-trade-and-signal-price-tracking.md)に記録し、ADR-0017本文の追記もF-017側で実施済み。UC-014の判定ロジック・画面・閾値、別CR候補(b)(c)は対象外。
- 2026-10-04 本人選択: 運用全体の絶対リターンは年率20％を目標、25％を上位目標とし（同日、当初案の25％／30％から本人指示で引き下げ）、対ガチホ差分と別軸で表示する（例: 実績22％・ガチホ30％なら「20％達成・25％未達・対ガチホ劣後」）。短期の個別売買は期間目標への到達を参考表示に留め、売却単独の差額には年率目標を当てない。
- 2026-10-04 本人指示: 理由・判断区分・買付代替先の手入力は設けない。売買前に保存済みのシグナル・指標・保有状況を自動で紐付け、「どんな状況で売買すると結果が良かったか」を振り返る。本人の主観的な理由は推測しない。
- 売却単独・推定乗換え・買付を区別し、同じ資金を二重に集計しない。
- 2026-10-04 レビュー後の本人選択（「現実的な機能へ落とし込む」）: (1) 年率は国内・米国株の株式部分の直近52週の時間加重リターン（修正ディーツ法・配当除く）で測り、現金・入出金データを不要にする。対ガチホは52週前の保有を持ち続けた場合との差。(2) 売買全体の効果は(1)の対ガチホ差で答え、個別の乗換えは同一市場・売却から5営業日以内の買付に売却代金を割り当てる推定（参考）とする。(3) 2026-10より前の売買は価格系指標だけ事後再計算し、仮説づくりに限る。(4) 買付の主比較は既存保有の比例買増し、指数は参考。初回に約146銘柄等の週足を2021-07まで一括補完する（ADR-0024 D5）。段階1〜4の機能は[UC草案](docs/product/trade-decision-effect-gate2-uc-draft.md)の「実装する機能と段階」。

### Files touched

`docs/product/requirements.md`、`docs/product/trade-decision-effect-proposal.md`、`docs/product/trade-decision-effect-gate1-requirements-draft.md`、`docs/product/trade-decision-effect-gate2-uc-draft.md`、`docs/adr/ADR-0024-trade-and-signal-price-tracking.md`（D5 初回一括補完を追加）、`docs/adr/ADR-0017-signal-outcome-tracking.md`（D2改訂方針の追記）、`docs/product/mockups/README.md`（モック省略の記録）、`docs/rcid/traceability-matrix.md`（CHG-0033行）、`PLAN.md`。アプリコードは未変更。

### Status

**Gate 1承認済み・Gate 2（UC-017〜019草案）本文レビュー待ち。Gate 3／4未着手。** 次回は以下から再開する。

- [x] 2026-10-04 Gate 2前レビューの推奨案1〜7を本人承認（入出庫は時価で資金出入り、売買は定型／その他の2区分〔定期的少額買付の判定は実データにパターンがなく見送り〕、買い増しの比例配分に当該銘柄を含める、クラスタ定義、暦年の対象範囲、画面「売買の振り返り」3タブ、承認の進め方）。UC-017〜019を`use-cases.md`へ反映済み。
- [x] UC-017・UC-018のGate 2承認（2026-10-04、本人）。
- [ ] UC-019のGate 2承認は段階3の実件数を見てから行う。
- [x] Gate 3承認済み（2026-10-04、本人）。[data-model.md](docs/architecture/data-model.md)「trade_* ほか売買振り返り用テーブル群」8テーブル＋`index_weekly_prices`へ`usdjpy`追加、[ADR-0027](docs/adr/ADR-0027-trade-history-storage.md)。比較結果は保存せず読み取り時に算出。
- [x] Gate 4 Cycle 1（`indicator_observations`＝指標の週次追記保存）: テスト9件を本人承認（2026-10-04）。Red確認（実装の接続を外すと9件とも「行が追記されない／警告ログが出ない」で失敗）→Green（`IndicatorObservationRecorder`・モデル・マイグレーション・2アクションへの接続）。専用testing DBで全体1235件通過・失敗0。ブランチ `feat/chg0033-indicator-observations`、`/review`・マージ待ち。
- [x] main マージ・push済み（2026-10-04）: Cycle 1（`4281be3`）、Cycle 2 売買履歴CSVのパーサー `TradeHistoryCsvParser`（Unit 17件、`61cd9db`）。開発用DBに`indicator_observations`をmigrate済み。ただし共有ディレクトリ（アプリの実行コード）は別セッションの`feat/chg0034`のため、記録は同ブランチにmainが取り込まれるまで始まらない。
- [x] Gate 4 Cycle 3（UC-017 取込の保存）: テスト11件を本人承認→Green（`trade_import_batches`・`trade_executions`、`ImportTradeHistoryAction` の preview/execute）。`/review`で、想定外のDBエラー時に取込記録が`pending`のまま残る不具合を発見し、再発防止テストを追加して修正（計12件）。実データ1248行の初回取込・再取込（増えない）を専用DBで確認。ブランチ `feat/chg0033-trade-import-save`。
- [x] main マージ・push済み（2026-10-04）: Cycle 3（取込の保存、`b22c2fa`）。開発用DBに`trade_import_batches`・`trade_executions`をmigrate済み。
- [x] Gate 4 Cycle 4（UC-017 保有CSVとの照合）: テスト10件を本人承認→Green。実データ検証で、日付だけの時点判定は国内16件の不一致になる（市場が開く前の取込で当日約定まで数えるため）ことが判明し、本人承認のうえ「取込時刻が約定日の市場の取引終了（国内15:30東京・米国16:00NY）以降のときだけ含める」基準に変更（追加テスト2件。`/review`で冬時間のテスト1件を追加し、夏時間固定に変えると失敗することを確認）。実データは156件すべて一致。計13件。`trade_reconciliation_items`・`TradeHistoryReconciler`。ブランチ `feat/chg0033-reconcile`、マージ待ち。
- [x] main マージ・push済み（2026-10-05）: Cycle 4（保有CSVとの照合、`742f8fb`）。開発用DBに`trade_reconciliation_items`をmigrate済み。
- [x] Gate 4 Cycle 5（UC-017 取込画面）: テスト17件を本人承認→Green（`/trade-history-import`、UC-001画面からのリンク、プレビュー→確定、照合結果と使った保有CSVの日付、取込履歴）。`/review`でサーバー保持の状態がブラウザから書き換えられる点を発見し、再発防止テスト（計18件）を先に書いて`#[Locked]`で修正。承認済みテストは、日本語を確認できない`assertSeeInOrder`を表示テキストの検査に直した（確認内容は同じ）。画面の見た目はスクリーンショットで確認したが、ブラウザ自動操作では「プレビュー」の結果表示まで確認できていない（自動テストは通過。実機での確認は本人にお願い）。module-map・user-guide・モックREADMEを更新。ブラウザ確認の初回に、プレビュー用サーバーが開発用DBに接続し、ゲストのセッション行が作られた（業務データへの書き込みなし。以後は`.env`を専用DBに固定してから起動する）。ブランチ `feat/chg0033-import-screen`、マージ待ち。
- [x] main マージ・push済み（2026-10-05）: Cycle 5（取込画面、`8e621b3`）。実機のブラウザ確認は本人確認待ち（自動操作では「プレビュー」の結果表示まで確認できていない）。
- [x] Gate 4 Cycle 6a（価格取得のクライアント層）: テスト19件を本人承認→Green（`PriceBackfillClient`・`YahooFinanceChartClient::fetchHistory()`・`PriceHistory`。10年の週足＋分割＋取得結果の状態 ok／empty／not_found／failed）。実際のYahooで確認: `range=5y`は2021-10からで足りず`10y`にした／分割は国内・米国とも取得可／終値は分割調整済み。`/review`で、Yahooが全銘柄に本文「Not Found」だけの404を返す障害を実際に観測し、404を一律「銘柄なし」にすると実在銘柄が取得不能になると判明→「銘柄なし」はJSONの`chart.error.code=Not Found`のときだけとし、それ以外の404は「取得失敗」へ（再発防止テストを先に書いて修正）。想定外の形の応答でも例外を投げない。計23件。ブランチ `feat/chg0033-price-client`、マージ待ち。
- [x] Gate 4 Cycle 6b（価格の保存と追跡状態）: テスト27件を本人承認→Green（`price_tracking_targets`〔`track_until_week`をnullable・`splits_incomplete`追加を承認〕・`stock_splits`・`usdjpy`、`PriceTrackingTargetUpdater`・`StockSplitRecorder`）。`/review`で、分割を取得しない取得が分割情報欠損の記録を消す潜在バグを発見し、再発防止テストを先に書いて修正（`applyFetch`の`splitsFetched`）。main マージ・push済み（2026-10-09、`2b38918`）、開発DBにマイグレーション3本を適用済み。
- [x] Yahooの実データ検証: 全銘柄404の障害は約15分で復旧（2026-10-05 18:29〜18:43 UTC。直前の連続アクセスによる一時遮断の可能性）。復旧後、英字を含む国内コード（`285A.T`）は取得できた（上場が新しく96週）。米国のクラス株は売買履歴になく、6cでは変換しない（現れても「銘柄なし」として記録される）。保有20銘柄の10年分を1.5秒間隔で連続取得して全件成功（平均0.40秒）。
- [x] Gate 4 Cycle 6c（初回の一括補完）: テスト14件を本人承認→Green、`/review`で保存を確認できなかった経路のテスト1件を追加（計15件）（`BackfillPriceHistoryAction`・`php artisan price:backfill {--dry-run}`）。対象は売買履歴に現れた銘柄＋日経225・S&P500・ドル円。追跡期限を最後の売却・シグナル発生から登録し、取得ごとに1.5秒空け、取得失敗が5回続いたら中断（Yahooの応答を挟めば継続）。補完済み・取得不能は再実行で飛ばす。失敗は銘柄ID・指数名と結果だけを警告ログに残す。ブランチ `feat/chg0033-price-backfill`。**実行には先に売買履歴CSVの取込が必要**（開発DBの売買履歴は0件）。
- [x] 初回の一括補完を開発DBで実行（2026-10-09、`286c9c7`でmainマージ・push済み）: 1回目は成功138・銘柄なし1・失敗5で中断（失敗5件はこちらのネットワークの一時不調による接続タイムアウト・接続不可・名前解決失敗で、約30秒間に集中。Yahooの遮断ではなく、5回連続での中断は設計どおりに働いた）。通信の回復を確認して再実行し、未完了の8銘柄を取得（成功6・銘柄なし2）。結果: 146銘柄中144銘柄の週足を取得（最古2016-10、分割110件〔77銘柄〕、`splits_incomplete`は0件）、日経225・S&P500・ドル円は約520週。**銘柄なしは2銘柄**（米国1・国内1。いずれも上場廃止・非上場化とみられるが、未確認）で、3回連続になった時点で取得不能になる。
- [x] Gate 4 Cycle 6d（毎週の追跡）: テスト19件を本人承認→Green。定期実行の登録テストは、本人の使い方（週末中心・不定期、cronなし）に合わせて「起動スクリプトが応答確認後に`price:track`を後ろで実行する」へ置換（本人承認）。追加要望の二重起動防止・同日再起動の軽量化のテスト5件を本人承認→Green（計24件）。`TrackPriceHistoryAction`・`php artisan price:track {--dry-run} {--include-unavailable}`。追跡中の銘柄は10年分＋分割で取り直す（分割で過去の終値が遡って変わるため。ADR-0024 D6に追記）。確定後に保存済みの銘柄・指数は取得しない。`price:track`と`price:backfill`は同じロックを共有。6cの取得処理を`PriceFetchRunner`・`PriceTrackingRegistrar`に切り出し。開発DBでのdry run: 対象136銘柄。ブランチ `docs/chg0033-backfill-run`（6cの実行記録の上）。
- [x] Gate 3（2026-10-10 本人承認）→Gate 4 Cycle 6e（分割の整合）: テスト12件を本人承認→Green。6dのレビューで見つけた、保有・ウォッチリストの104週更新が分割後に古い週とずれる問題〔HIGH〕への対応。保有・ウォッチリストの更新が同じ1リクエストで分割を受け取り記録し、`price:track`が`price_tracking_targets.full_history_fetched_at`より後に記録された分割のある補完済み銘柄を10年分取り直す（マイグレーション1本）。実際のYahooで、2年分の取得に分割（5706の2026-09-29、10:1）が入ることを確認。初回の`price:track`で、分割のある補完済み銘柄（77銘柄）を1回ずつ取り直す。ブランチ `docs/chg0033-6e-and-plan-archive`。
- [x] Cycle 6eのマージ・初回の`price:track`実行（2026-10-11）: `6039fd4`でmainマージ・push済み、開発DBにマイグレーション1本を適用、共有のチェックアウトを`6039fd4`へ更新しキューを再起動（以後、アプリ起動時に`price:track`が自動で後ろ実行される）。初回の実行は約3分半・終了コード0: 追跡136銘柄（シグナル由来22銘柄を新規登録）、分割で10年分を取り直し77銘柄、保存済みで取得不要50、成功99、銘柄なし2（米国`CYBR`は3回連続で取得不能に、国内`9613`は2回目）、失敗0。記録済みの分割は127件（90銘柄）。実行後の確認だけの実行で、分割による取り直しは0件。
- [x] Gate 4 Cycle 7a（UC-018 週ごとの株式部分の復元）: テスト11件を本人承認→Green（`WeeklyPortfolioBuilder`・`PortfolioWeek`。分割調整後の株数、評価額 V_t、資金の出入り F_t〔入出庫は時価〕、算出できない銘柄の除外と見積額）。日曜の例は、実データの約定が月〜金だけで、共通の週の決まり（日曜は翌週）と逆だったため金曜に変更（本人承認）。**その過程で、「確定した最新週」が日曜だけ1週先にずれる不具合（6dから）を発見**し、再発防止テスト2件を先に書いて`WeekDateNormalizer::lastConfirmedWeek()`に一本化。**実データ検証で、国内株のCSVが分割で増えた株を印のない「入庫」として記録していると判明**（分割の1〜3日前、24件すべてが「分割前の株数×〔分割比−1〕」と一致）。二重計上と架空の入金になるため、これを分割による入庫として除くルールを本人承認→テスト3件→Green（計16件）。結果: 最新の保有CSV（10/10取込）と117銘柄すべて一致、220週、除外は終値欠損の42件（銘柄×週）だけで評価額比最大1.71%（5%超の週なし）。ブランチ `feat/chg0033-weekly-portfolio`。
- [x] Gate 4 Cycle 7b（UC-018 年率・20%/25%判定・対ガチホ）: テスト13件を本人承認→Green（`StockPerformanceCalculator`・`PeriodPerformance`。修正ディーツ法の週次リターンの連結、分母0以下の週の除外と件数、終値のない銘柄は前後週の比較から外す、欠けが評価額の5%超の週があれば判定保留、判定週数未満は期間目標との参考比較、起点週の株数による対ガチホ、市場別）。`PortfolioWeek`に銘柄ごとの評価額・資金の出入り・見積額・1株あたり円価格を追加し、1回の復元から市場別に出す（実データで3市場分約2.2秒）。実データの直近52週（2025-09-29〜2026-09-28）は全52週を使用・欠け0%で判定まで出た。手計算との突合せは7f。ブランチ `feat/chg0033-stock-performance`。
- [ ] 次: Cycle 7c（売却・推定乗換え・買付の比較）→7d（状況記録、Gate 3）→7e（画面3タブ）。UC-018の算出。**設計に入れる点（6eのレビューで判明）**: CSV取込の直後から次の`price:track`までは、保有銘柄の直近104週だけが分割後の値で古い週は分割前のまま残る。`full_history_fetched_at`より後に記録された分割がある銘柄は「取り直し待ち」として扱い、その銘柄の結果は算出不可にする。→UC-018の算出（年率・売却/推定乗換え/買付比較）。UC-019はGate 2保留。
- [ ] Gate 4でUC名から導くテストケース（26週境界、取得失敗、価格補完後のUC-014再集計、二重集計防止の計算例、年率20％／25％境界）を承認してから実装する。

## メガトレンド候補発見（CHG-0031・ADR-0022／ADR-0025・F-016／UC-016）main統合済み・情報源試行中（2026-10-09）

### Decision

- 本人が2026-10-03に「承認、Gate1の品質の件はPlan等に残しておいて。Mockは今回は不要」と指示。Gate 1草案を正式要件F-016へ反映。今回のGate 2はUC本文でレビューし、モックを省略する。この時点ではGate 2〜4の承認とは扱わなかった。
- 未登録の日米上場企業の発見を目的とし、情報源の正確さ・裏付けやすさ・発見への有用性は4週試行後に再精査する。Gate 1承認によって情報源の採否や自動取得範囲を固定しない。
- 2026-10-04 本人がUC-016とUC-012への受け渡し差分をGate 2承認。UC-012本文へ統合済み。
- 2026-10-04 Gate 3叩き台: [ADR-0025](docs/adr/ADR-0025-research-candidate-storage.md)と`data-model.md`の`research_*`節。調査記録は専用8テーブルに分け、未同定の企業では`holdings`を作らない。ウォッチリストの登録経路は`watchlist_items.source`ではなく`last_seen_in_csv_at`と受け渡し履歴から導く（CSV再取込で`source`が上書きされ、調査経路が消えるため）。既存テーブルのスキーマ変更なし。
- 2026-10-04 本人が「Ｇａｔｅ３承認でよい」と明示。ADR-0025と`data-model.md`の`research_*`8テーブル設計をGate 3承認。実装時期は未決定。引き継ぎ要約は[megatrend-discovery-handoff.md](docs/product/megatrend-discovery-handoff.md)。
- 2026-10-04 本人が「今すぐRedテストへ進み、試行は並行する」を選択。`feat/chg0031-research-candidates`を`main`から作成し、UC-016/UC-012のGate 4 Redテストを3ファイルに作成。Greenは内容と失敗結果に対する本人のGate 4承認後に進める。
- 2026-10-04 Redテスト34件の内容と失敗結果（専用MySQLテストDBで34失敗）を提示し、本人が「承認してGreen実装へ進む」を選択。Gate 4承認済み。Green実装に着手。
- 次の品質レビューは**2026-11-02以降**が目安。根拠は[試行ログ](docs/investment-research/megatrend/source-pilot-log.md)と[Gate 1再精査メモ](docs/product/megatrend-discovery-gate1-requirements-draft.md#4週間の情報源試行後に再精査するメモ2026-10-03本人指示)。対象は3テーマ・5源、試行期間2026-10-05〜11-01。週次確認とレビューは手動作業で、自動実行は設定していない。

### Files touched

`docs/product/requirements.md`、`docs/product/use-cases.md`（UC-016・UC-012統合・承認記録）、`docs/product/megatrend-discovery-handoff.md`、`docs/architecture/data-model.md`（`research_*`節）、`docs/adr/ADR-0025-research-candidate-storage.md`、`docs/product/megatrend-discovery-gate1-requirements-draft.md`、`docs/product/megatrend-discovery-change-proposal.md`、`docs/product/megatrend-source-selection.md`、`docs/adr/ADR-0022-megatrend-source-radar.md`、`docs/rcid/traceability-matrix.md`、`PLAN.md`。

### Status

**Gate 1〜4本人承認済み。追加3件も2026-10-04に本人承認。隔離MySQL DBでUC-016の37件と既存UC-012の48件、計85件（407アサーション）が通過。作業ブランチの全Pestスイートは1263件、main統合結果は1664件（7260アサーション）通過。Playwrightでも統合結果の未同定候補記録と照合未実施を確認。情報源の4週新着による品質評価は未完了。** Redの34件は未実装テーブル・画面、追加3件は複数根拠と履歴表示の不足により失敗したことを確認済み。以下を並行して進める。

- [x] UC-016の共通処理とUC-012受け渡し差分のGate 2承認（2026-10-04）。源固有の自動取得は後続差分。
- [x] Gate 3: ADR-0025と`research_*`の8テーブル定義を本人承認（2026-10-04）。
- [x] Gate 4: `feat/chg0031-research-candidates`のRedテスト34件（`tests/Feature/UC016ResearchCandidateCreationTest.php`、`UC016ResearchCandidateReviewTest.php`、`UC016ResearchWatchlistHandoffTest.php`）と失敗結果を本人に提示し、2026-10-04に承認。`audit`チャンネル新設と`in_rakuten_favorites`判定変更の回帰テストを含む。
- [x] Green: 承認された34件を通し、画面・8テーブル・監視への受け渡しを実装する。UC-012の既存48件も通過。`review-score`は197（強化レビュー必須）、`domain-boundary-check`は違反0。共有元発表・法人の訂正時に関連候補の版も更新する修正を実施。
- [x] 実ブラウザとPlaywright E2EでUC-016の未同定候補の記録→照合未実施による監視登録保留を確認。空欄の元発表日がDBで失敗する不具合を発見しNULL保存に修正後、1件通過。監視登録成功はFeatureテストで確認済みで、ブラウザE2Eでは未実施。
- [x] 画面の不足（2件目の主張、2件目の上場先、履歴の変更前後）の追加Red3件を専用DBで確認し、本人の追加Gate 4承認後にGreen実装。UC-016の37件とUC-012回帰48件が通過。
- [x] 静的検査と強化レビューの残件整理後、2コミットで保存しmainへ統合。Gate 4承認とは別に本人がコミット・マージを承認。
- [x] 2026-10-05のマージ後レビュー3点を追加TDDで修正。UC-016: 元資料訂正に紐づく確認済み主張の再確認、2件目以降の主張・上場先の画面訂正。UC-012: 直近成功CSVへの在籍と過去のCSV経路を分離する。[ADR-0028](docs/adr/ADR-0028-favorite-csv-import-membership.md)と[data-modelの追加設計](docs/architecture/data-model.md)は本人がGate 3承認済み。追加Red11件は専用DBで10失敗・既存回帰1成功を確認し、Gate 4承認後にGreenを実装。
- [x] UC-016の確認境界を追加TDDで修正。元発表URL変更時に旧`verification_status=verified`と`verified_on`が残る問題について、本人が2026-10-06に追加RedとGate 4を承認。Red3件は専用DBで意図した3失敗・7成功。GreenではURL変更時に元発表を未確認へ戻し、別操作で再確認可能にした。UC-016／UC-012関連99件・481アサーション通過。
- [x] 追加Red着手前の全Pestスイート1678件通過・1件スキップ・23件deprecated（7334アサーション）。変更PHP10ファイルのPint `--test`とVite buildも通過。`git diff --check`とDomain Boundaryチェックは通過、review-scoreは82（強化レビュー）。
- [x] 2026-10-09の追加Playwright E2Eで、UC-016の未確認主張に空の確認日を送るとMySQL日付エラーとなり、画面には誤って版競合と表示される不具合を確認。主張追記・訂正の追加Red2件は専用DBで意図した失敗となり、本人が追加Gate 4を承認。`syncClaims()`で空欄をNULLに正規化するGreen後、CorrectionTest全12件・61アサーションと追加Playwright E2E1件が通過。全Pestスイート1680件通過・1件スキップ・23件deprecated（7342アサーション）。`fix/chg0031-review-fixes`の2コミット（`61f3d99`、`2478d11`）をmainへ統合。統合結果では全Pestスイート1745件通過・1件スキップ・23件deprecated（7612アサーション）、UC-016のPlaywright E2E 2件、Vite buildが通過。pushは未実施。
- [ ] 4週の新着確認を試行ログへ記録する（10/05〜11、10/12〜18、10/19〜25、10/26〜11/01）。源ごとに元URL・発表日・照合結果・所要時間・確認できなかった理由を残す。
- [ ] 11/02以降、主張と一次資料の一致／不一致／確認不能、訂正、誤同定、転載重複、欠測、未登録企業数、テーマ・市場の偏り、確認時間を源ごとに集計する。情報源の誤りと調査時の読み違いを区別する。
- [ ] 源ごとに継続／補助参照／入替／保留を決め、根拠カードと利用条件を記録する。取得可能な源だけ自動取得の対象・頻度を提案する。
- [ ] F-016／UC-016の各要件を維持／修正／保留で再精査し、差分をCHG-0031の追跡へ反映する。変更があれば該当Gateを再レビューする。

**UC-012の直近CSV在籍:** ADR-0025 D5の`last_seen_in_csv_at IS NOT NULL`は過去のCSV経路を表し、直近成功CSVへの在籍は承認済みのADR-0028に従って取込IDで判定する。対象銘柄0件のCSVは現行UC-012どおり取込エラーとし、失敗取込では直近の在籍を変えない。

品質再精査の完了条件は、源ごとの評価と根拠、要件への維持／修正／保留判断、未解決事項と次の対応が記録されること。4週記録が不足する場合は不足分と再確認時期を残し、期間の経過だけで検証済み・採用済みにしない。4週で長期予測の的中率・投資成果を判定しない。

> 2026-08-27（フロントエンド実装Phase5完了時点。UC-010 Gate4完了・コミット`ba239fe`分も含む）以前（Gate0セットアップ〜Phase1 Gate4サイクル完了・ADR-0002 NISA区分CR・ADR-0004分析エンジン実装〔設計確定〜各TDDサイクル、UC-001配線・UC-004画面・UC-003/UC-009新指標反映を含む〕完了・関連review指摘修正2件・UC-009サンプルレポート生成、F-010（UC-010）Gate1〜3ドキュメント叩き台整備完了、NISA区分内訳の書き込み・UC-004消費完了、未知の口座区分ラベルの扱いに関する`/review`指摘修正、Phase2 UC-008（Cycle1・Cycle2）完了、Phase2「UC-008→UC-005→UC-006」全完了・UC-007市場全体指標表示実装完了・実装済み全エンドポイントのIntegrationテスト網羅性監査完了、フロントエンド実装Phase0（基盤整備）完了、フロントエンド実装Phase1+2（CSV取込画面・サマリーレポート画面）完了、利確・リバランス閾値の動的分岐ロジック検討〔検討事項の記録のみ、実装はCHG-0006として2026-08-28〜29に別途完了〕、フロントエンド実装Phase3（UC-002保有銘柄一覧画面＋UC-007ウィジェット、共通レイアウトのcsrf-tokenバグ修正含む）完了、Phase3の`/review`拡張レベル実施（コミット汚染・ビュー内クエリ修正）、フロントエンド実装Phase4（UC-003銘柄詳細画面）完了、UC-010 Gate2/Gate3正式承認（買いシグナル7種の前提条件追加）完了、UC-010 Gate4完了・コミット（`ba239fe`）、フロントエンド実装Phase5（UC-004売買シグナル一覧画面）完了、およびフロントエンド実装Phase6（UC-005セクター配分ダッシュボード画面）完了〔2026-09-05、CHG-0011作業時に退避〕等）の完了済みエントリは `docs/history/plan-archive.md` に退避済み。
> **運用ルール**: PLAN.mdは300行を超えないよう保つ。300行に近づいたら、Statusが「完了」相当（Green確認完了・マージ済み等）の最も古いエントリから`docs/history/plan-archive.md`へ退避し、本ファイル冒頭のこの注記を更新する（詳細は `.claude/rules/60-docs.md` 参照）。300行超過に伴い「数値表示フォーマット修正完了（2026-08-28）」「UC-010買い増し候補セクションのフロントエンド統合完了（2026-08-28）」の2エントリを退避済み（2026-09-06、CHG-0012 Phase 0作業時）。約298行に達したため「利確検討ラインの動的分岐 CHG-0006（2026-08-28〜29）」「売買シグナル画面の可読性改善（2026-08-28）」の2エントリを退避済み（2026-09-06、CHG-0013／ADR-0012作業時）。300行超過に伴い「取込後サマリーレポートのグローバルナビタブ化 CHG-0008（2026-09-05）」の1エントリを退避済み（2026-09-12、F-012・CHG-0012のステータス記述を実態〔mainマージ済み〕に修正した際に発生した増分に対応）。300行超過に伴い「売買シグナル画面 判定チェックリスト表示 CHG-0007（2026-08-29〜09-05）」の1エントリを退避済み（2026-09-12、CHG-0016〔新規投資候補テーブルの固定ヘッダー化〕作業時）。300行超過に伴い「整理検討（含み損）候補一覧の新設 F-011（2026-09-05〜06）」の1エントリを退避済み（2026-09-21、CHG-0017/CHG-0018マージ後の最終`/review`・コミット・push前整理時）。300行超過見込みに伴い「財務健全性フィルタに営業利益率を追加 CHG-0012（2026-09-06〜07）」の1エントリを退避済み（2026-09-27、エビデンス提言取込・CHG-0020 Phase 0作業時）。300行超過見込みに伴い「成長率算出バグの是正 CHG-0013（2026-09-06〜12）」の1エントリを退避済み（2026-09-30、集中度ダッシュボード・CHG-0026 Phase 0作業時）。300行到達に伴い「お気に入り未保有銘柄ウォッチリスト／新規投資候補画面の刷新 F-012・CHG-0014（2026-09-06〜12）」の1エントリを退避済み（2026-10-01、CHG-0026 Cycle3作業時）。300行超過に伴い「新規投資候補テーブルの固定ヘッダー化・重複列マージ CHG-0016（2026-09-12）」の1エントリを退避済み（2026-10-01、CHG-0026のmain取り込み時）。約295行に達したため「バリュー/景気敏感銘柄向け判定ロジック分岐 CHG-0017（2026-09-19）」「買い増しシグナル共通前提の緩和とPER単体シグナル CHG-0018（2026-09-19〜）」の2エントリを退避済み（2026-10-01、CHG-0030作業時）。250行超過に伴い「売買シグナル画面のPER/PBR表示をUC-004・UC-011に拡張 CHG-0019（2026-09-23）」「mainへのマージ・最終`/review`・push（2026-09-21）」「ポートフォリオ分類ダッシュボード F-013・CHG-0015（2026-09-08〜09-19）」の3エントリを退避済み（2026-10-03、CHG-0027〜0029のStatusをgit履歴で裏取りして更新した際に実施）。300行超過に伴い「売買戦略の深化ロードマップ策定（2026-09-19）」の1エントリを退避済み（2026-10-04、CHG-0034ブランチへのmain取り込み時）。300行到達に伴い「集中度ダッシュボード（CHG-0026・ADR-0019・F-015・UC-015）」の1エントリを退避済み（2026-10-04、CHG-0033 Cycle 3作業時）。300行超過見込みに伴い「エビデンス提言の取込とシグナル検証基盤（CHG-0020・ADR-0017・F-014・UC-014）」の1エントリを退避済み（2026-10-07、CHG-0049の提案エントリ追加時）。300行到達に伴い「集中度ダッシュボードの判定色とセクター配分との行き来（CHG-0030）」の1エントリを退避済み（2026-10-08、CHG-0049のエントリ整理時）。


## 業種別基準PER/PBRとの比較による割安・割高の色付け（CHG-0034・ADR-0023・UC-002／UC-003）実装完了（2026-10-04）

### Decision

- 発端: 本人から「セクターから基準PER/PBRを出し、実際の値との割合で割安・割高を色付けする。突出して良いものは色を濃くし、色付けだけか判定にも生かすか検討してほしい」（2026-10-02）
- 本人承認済み: 基準は外部の固定表（日本株=JPX月次統計の東証17業種の直近値、米国株=DamodaranとSPDR ETFの平均）。JPX資料は出典明記で利用可。色付けのみで財務健全性・売買シグナルの判定には使わない。段階は「色付け→シグナル発生記録に段階を保存して実測→判定への組み込みを検討」の順
- 設計（ADR-0023）: 比率（実際÷基準）で5段階、境界は穏やかな側。基準値ごとに信頼度（high/medium/low/none）を持ち、濃い色はhighのみ、lowは「基準が不安定」と注記。PBRは日本株全業種・米国株はUtilitiesのみ。純ロジック`ValuationBenchmarkJudge`＋`config/valuation_benchmarks.php`、DBスキーマ変更なし
- 追加（2026-10-03、本人承認）: 健全性指標（ROE・自己資本比率・営業利益率・売上成長率・営業利益成長率）にも二段階の色付け（ADR-0023 D9）。緑の下限＝現行の合格ライン。強い良好のラインは本人の指示で外部資料（JPX・Damodaran・EDINET DB）と保有銘柄の実データから根拠を取り、日本株／米国株別に設定（ROE15%／25%、自己資本比率70%／なし、営業利益率20%／30%、売上成長率15%／25%、営業利益成長率30%／30%。根拠は`valuation-benchmarks.md`7章）。**ライン値は本人が確認・承認済み（2026-10-03、米国株の営業利益率30%・売上成長率25%に本人が修正）**。銀行・金融は自己資本比率・営業利益率、不動産は自己資本比率を色なし。純ロジック`FundamentalMetricToneEvaluator`（良好の下限は`FundamentalHealthEvaluator`の定数を参照）。判定は変更しない
- 範囲外（後続CR）: 売買シグナル画面・新規投資候補への展開、`signal_occurrences`への段階の保存

### Files touched

`docs/adr/ADR-0023-sector-valuation-benchmark-color.md`（新規）、`docs/product/valuation-benchmarks.md`（新規、基準値の正本）、`docs/product/valuation-benchmarks-evidence/`（新規、7つの調査の取得結果）、`docs/product/use-cases.md`（UC-002・UC-003・承認記録）、`docs/rcid/traceability-matrix.md`（CHG-0034）。コードは未着手

### Status

実装完了（2026-10-04）。CHG-0048と一緒に実装した（同エントリのStatus参照）。電気・ガスのPBRの丸め誤差の修正、基準値の公式資料との照合（2026-10-04）も実施

## 新規投資候補の並び順で財務健全性を先頭キーに（CHG-0045・ADR-0013 D4追補）Green完了（2026-10-04）

### Decision

- 本人要望「新規投資候補もおすすめ順で」。調査の結果、UC-012は既に透明マルチキーで並んでいたが、先頭キーが押し目シグナル件数のため財務基準割れ（シグナル2件）が財務健全（シグナル1件）より上に来ていた
- 本人判断（AskUserQuestion）: 財務を先頭キーにする（①財務 passed→unavailable→failed ②シグナル件数 ③④は不変）。UC-013の`new_entry`参考リストも同じActionのため同じ並び

### Files touched

`app/Actions/Watchlist/ShowWatchlistAction.php`、`tests/Feature/UC012WatchlistScreenTest.php`（新規1件・既存テスト名更新・ヘルパーの`range(1, 0)`バグ修正）、`docs/product/use-cases.md`、`docs/adr/ADR-0013-*.md`（D4追補）、`docs/adr/ADR-0014-*.md`、`docs/architecture/data-model.md`、`docs/rcid/traceability-matrix.md`

### Status

Red 1件→Gate4承認→Green。フルスイート1178 passed・pint適用済み。開発DB（未保有105銘柄）で財務failedの先頭位置が1位→58位に下がることを確認。ブランチ`feat/chg0045-watchlist-sort-fundamental-first`（worktree `.claude/worktrees/chg0045`）、未コミット

## 売買シグナル画面キープ表の列拡充（CHG-0046）実装完了・mainマージ済み（2026-10-04）

### Decision

- 発端: キープ表が6列（ヘルスラインは1行の文字列）のみで、他3テーブルの指標・色分けが見られない。本人要望で他テーブルと同じ指標（PER/PBR・財務含む）を列に分けて色付き表示する
- ファンダメンタルズは既存データで表示可能（実データ59銘柄中ROE 40・PER 43件。nullはJ-Quantsの本決算のみ開示項目・ETF〔VYM/HDV/SPYDがstock登録〕・未取得6324による。ETF登録の件は別件として報告のみ）
- 本人判断（推奨案）: テクニカルは利確・買い増しの既存閾値の両方に照らし利確寄り＝黄／押し目寄り＝緑。PER/PBRは値のみ（業種比較色はCHG-0034後）。ヘルスライン列は廃止
- 設計: `SignalCriteriaEvaluator::evaluateHold()`を追加。行の拡充は`ShowHoldListAction`側（`ClassifyHoldingsAction`の出力・JSON APIは不変）

### Files touched

`docs/product/use-cases.md`（UC-013業務ルール・承認記録）、`docs/product/ui-guidelines.md`、`docs/rcid/traceability-matrix.md`、`app/Services/Analysis/SignalCriteriaEvaluator.php`（`evaluateHold()`）、`app/Actions/Portfolio/ShowHoldListAction.php`、`app/Actions/Portfolio/ClassifyHoldingsAction.php`（`HOLD_WATCH_GAIN_RATE_BUFFER`をpublic化のみ）、`resources/views/livewire/signal/signal-list.blade.php`、`resources/views/components/criteria-chip.blade.php`、`resources/views/components/signal-table-head.blade.php`、`tests/Unit/Services/Analysis/SignalCriteriaEvaluatorHoldTest.php`、`tests/Feature/CHG0046HoldTableRichColumnsTest.php`、`tests/Feature/CHG0028SignalHoldTableTest.php`

### Status

Red 28件→Gate4承認（2026-10-04）→Green。フルスイート1205 passed・pint適用済み。worktreeを8046番で起動し実ブラウザで表示確認済み（キープ59銘柄・黄/緑チップ描画、ヘッダー横スクロール同期OK）。`/review`（強化、スコア58）指摘2件を修正: 含み益率の利確ラインを利確検討と同じ`TakeProfitThresholdEvaluator`に揃える（高水準モード+150%。実データ6098が誤って黄だった）／分類と拡充の間に取り込みが重なり行が見つからない場合に画面が500になる経路を既定行で回避。Red 3件→Gate4承認→Green、フルスイート1209 passed。再`/review`（8ff7357）後にmainへ--no-ffマージ。ブランチ`feat/chg0046-hold-table-rich-columns`（worktree `.claude/worktrees/chg0046`）

## ウォッチリスト銘柄のセクター分類（CHG-0044・ADR-0020追補）Green完了・mainマージ済み（2026-10-03〜10-04）

### Decision

- 発端: 未分類が日本株64・米国株43残る。内訳はウォッチリスト（未保有）日本株64・米国株40と、保有の米国ETF3件。UC-012の一括更新は業種を保存しておらず、`sectors:backfill`も保有のみが対象だった
- 本人判断: Aのみ進める（ウォッチリスト銘柄を一括更新と`sectors:backfill`の対象に加える）。米国ETF専用カテゴリ（B）は今回対象外
- 設計: ADR-0020追補D6。`SectorClassificationResolver`を流用

### Files touched

`docs/adr/ADR-0020-*.md`（追補）、`docs/product/use-cases.md`（UC-012フロー・承認記録）、`app/Actions/Watchlist/RefreshWatchlistMarketDataAction.php`、`app/Console/Commands/BackfillSectorsCommand.php`、`app/Services/Sector/SectorClassificationResolver.php`、`tests/Feature/CHG0044WatchlistSectorClassificationTest.php`

### Status

Red 8件（3件は回帰ガード）→Gate4承認→Green。フルスイート1070 passed・pint適用済み。分類ロジックは`SectorClassificationResolver::classify()`に集約（一括更新・`sectors:backfill`が共用）。mainにマージ済み（`9b61d75`）。`sectors:backfill`を実データで実行した（2026-10-04）: 対象は未分類の6件（米国ETF5件〔HDV・SPYD・VYM・QQQ・VTI〕とBRK B）で、分類できたのは0件。日本株は実行前に全件分類済みだった。残り6件は本人判断で当面対応不要（ETFはB案で対象外、BRK Bは銘柄コードの表記〔半角スペース〕が原因の可能性があるが未調査）。**未実施**: `/review`

Red 8件（3件は回帰ガード）→Gate4承認→Green。フルスイート1070 passed・pint適用済み。分類ロジックは`SectorClassificationResolver::classify()`に集約（一括更新・`sectors:backfill`が共用）。**未実施**: `/review`、`sectors:backfill`の実データ実行、コミット。worktree: `.claude/worktrees/chg0044`（Vite成果物`public/build`と`vendor`のハードリンクコピーを手で持ち込んで実行。コミット対象外）

## 今後の対応（未着手）（2026-08-27追記、Phase5の実ブラウザ確認時に発見）

- **数値の未整形表示（Phase3〜5共通）**: `HoldingList`（保有一覧、Phase3）・`SignalList`（利確検討、Phase5）の含み益率・取得単価・現在値・分割指値の価格が、`{{ $value }}`で生の浮動小数点値をそのまま出力しており（例: 含み益率が`89.5793`と%記号なし表示、価格が`3632.676`のような小数点3桁表示）、実際にPlaywrightで画面を目視確認した際に発見した。レイアウト崩れではなく数値の可読性の問題。既存テストは生の数値部分文字列を検証する設計のため、これらのテストを含め画面3つ（Phase3/4/5）をまとめて後日別タスクで整形する（%サフィックス・価格の四捨五入・桁区切り等）方針とし、今回のPhase5サイクルでは対応を見送る
- **UC-004のE2Eテスト**: 一覧→詳細遷移のみの標準的な閲覧フローであり、`.claude/rules/31-e2e-testing.md`が対象とする「クリティカルフロー」に該当しないと判断し追加しない（Phase3/UC-002・Phase4/UC-003の同種の遷移もE2E化していないこととの一貫性を優先）
- **開発DBの保有データが空になっている**: Phase6の実ブラウザ確認時に発覚。`test@example.com`ユーザー自体も消えており(`db:seed`で復元済み)、CSV再取込等の保有データは未復元。並行セッションが`migrate:fresh`等を実行した際の巻き添えと推測されるが未確定。セクター配分ダッシュボードは空状態表示（「リバランス候補はありません」）のみ実ブラウザ確認済み。Phase7（`/candidate-check`）は`/verify`スキルで一時的にtinker投入した実データによりhappy path含め確認済み（検証後は削除しDBは空のまま）だが、いずれの画面も**本番相当のCSV再取込データでの確認はまだ行っていない**。実データでの最終End-to-End確認は保有データが復元された時点で改めて行う
- **重複度ラベルの閾値がBlade側とService側に分散（Phase7で発生）**: `resources/views/livewire/candidate/candidate-check.blade.php`が判定結果カードの「健全」/「やや偏り」/「偏り警告」ラベルを、`SectorAllocationCalculator`（UC-005）と同一の40%/70%閾値をBladeの`@php`ブロック内に再定義して導出している（`ShowCandidateCheckAction`/`CandidateOverlapCalculator`はラベルを返さず`overlap_rate`の数値のみ返すため）。閾値が2箇所に分散しており、将来どちらか一方だけ変更されるとラベル表示が実際の判定基準と乖離するリスクがある。是正するには`CandidateOverlapCalculator`にラベル算出を寄せるリファクタが必要（`ShowCandidateCheckAction`の出力契約変更を伴うため別途Red→Gate4→Greenサイクルが必要）。実害は表示ラベルのみ（`overlap_rate`の数値自体は正しい）のため優先度は低いが、次にこの画面に手を入れる際に解消する

## 今後の対応（未着手・スコープ確認済み）（2026-08-23追記、UC-007完了時点で更新）

- **フロントエンドUI（Livewire画面化）**: UC-001〜UC-009はこれまで全てAPIのみで実装してきた（`app/Livewire/`・`resources/views/`配下のBladeビューは0件、`docs/product/mockups/`は静的HTMLモックのみで実際に動く画面ではない）。Phase2（F-005/F-006/F-007/F-008）がAPIレベルで全完了したため、**次はLivewireコンポーネント・Bladeビューの実装（実際にブラウザでCSV取込〜各画面確認ができる状態にする）に着手する**方針をユーザーと確認済み
- **F-007（UC-007 市場全体指標表示）の3指標が未実装**: `GET /market-indicators`エンドポイント自体は実装完了したが、**米国10年債利回り・VIX指数・USD/JPY為替レートの3指標は取得ロジック自体が無く**（J-Quantsの範囲外のデータで、別途新規の外部APIクライアント選定〔ADR要〕が必要）、常に`null`のプレースホルダを返す。3指標の外部データ取得自体は別タスクとして先送り
