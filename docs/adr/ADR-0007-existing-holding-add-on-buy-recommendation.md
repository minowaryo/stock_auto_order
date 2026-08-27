# ADR-0007: 既存保有株の買い増し（押し目）タイミングレコメンド機能の追加

## Status
Accepted

## Date
2026-08-23

## Context

`docs/product/requirements.md` 2章OUT（52行目）は次のように定めていた。

> 含み益が薄い/マイナスの既存保有銘柄に対する売買判断支援（対象範囲は含み益+20%超の"ラストワンマイル"利確判断に限定）。ただし新規投資候補に関しては、F-008の軽量レコメンド（注目テーマ合致＋財務健全性フィルタ＋小口の購入額目安）の範囲に限り対象とする

Phase 1（F-001〜F-004・F-009）は全て実装完了し、実データ134銘柄の取込結果（`PLAN.md` 2026-08-22エントリ）で判明したのは、優先度上位20件（`UC-009`のレコメンド）が**全て「利確検討」で占められ、含み益が薄い/マイナスの大多数の銘柄に対する判断支援が完全に空白**という状態だった。本システムの基本戦略は中長期の積立・長期保有（`BACKGROUND.md`）であり、買い増し判断は本来この運用の中心にあるべきだが、現行の機能一覧（F-001〜F-009）にはこれを扱うものが存在しない。

さらにUC-004実装の副産物として、押し目判定に使える指標（`TechnicalIndicatorCalculator`の`bb_lower`・`week52_low`）が既に算出済みであることが判明した。現状これらはどの判定ロジックからも参照されておらず（売り側`SignalDeterminationService`は`bb_upper`・`week52_high`のみ使用）、追加の外部データ取得なしに買い増し判定を実装できる下地が既に整っている。

ユーザーと協議した結果、「押し目買いシグナル＋ファンダメンタルズ健全性フィルタ」の範囲に限り、既存保有銘柄への買い増しタイミングレコメンドをPhase 2の最優先機能として追加する方針で合意した。なお、別途検討中の「利確閾値の動的分岐」（`PLAN.md`先頭エントリ）は本ADRのスコープ外とし、UC-004の閾値記述は変更しない。

## Decision

以下を決定する。

- **D1**: `requirements.md` 2章OUT（52行目）を分割改訂する。「損切り判断・機械的なナンピン推奨・含み損銘柄の売却判断支援」は引き続きOUTのまま維持し、「押し目買いシグナル＋ファンダメンタルズ健全性フィルタの範囲に限る買い増しタイミング提示」をINへ移し、新機能**F-010**として追加する
- **D2**: 買いシグナルは既存の`signals`テーブルを拡張せず、**`buy_signals`新テーブルに分離**する（Rationale・Consequences参照）
- **D3**: 判定ロジックは`app/Services/Analysis/BuySignalDeterminationService`を新設する。既存の`SignalDeterminationService`（利確専用）には手を加えない
- **D4**: ファンダメンタルズ健全性フィルタは表示時（Action層）に適用する。閾値は`ShowImportSummaryReportAction`の新規投資候補フィルタ（ROE≧10%・自己資本比率≧40%）と同一値を用い、UC-008/UC-009との一貫性を保つ。評価ロジックは`app/Services/Analysis/FundamentalHealthEvaluator`として、将来的に他の呼び出し元からも使える汎用クラスとして設計する（買い専用に限定した設計にしない）
- **D5**: NISA区分は買い側では除外要因にしない。ADR-0002が既に定めている「中長期保有推奨候補にはNISA枠購入を推奨する」方針を適用し、`nisa_recommended`フィールドを付与する
- **D6**: フェーズ計画上、F-010をPhase 2の最優先（F-005/F-006/F-007/F-008より先）とする
- **D7**: 「利確閾値の動的分岐」検討は本ADRのスコープ外とする。`use-cases.md` UC-004の閾値記述・「検討中」注記は変更しない

## Rationale

- 押し目判定に必要な指標（`bb_lower`・`week52_low`・RSI・MACD・出来高）が既に算出済みで未使用のまま眠っており、実装コストに対して機能価値の空白を埋める効果が大きい
- `signals`を拡張せず`buy_signals`を新設する理由: `signals`に方向カラムを追加してenum拡張する案（選択肢A）を検証したところ、買いシグナル行が混入すると壊れる既存箇所が実測で3箇所存在した
  1. `FetchExternalMarketDataAction:206`の`Signal::where(...)->delete()`が買いシグナルを巻き込んで削除する。しかもこの削除は含み益+20%ゲートの内側にあるため、消える銘柄と消えない銘柄が混在し不具合が発見しにくい
  2. `ShowSignalListAction:36,57`の`signal_types`/`signal_reason_summary`に買いシグナルが混入する
  3. `ShowImportSummaryReportAction:128,144`の`composite_score`に買いシグナル件数が+15/件加算され、利確優先度の順位がサイレントに壊れる
  別テーブルであればこれら3箇所を一切変更せずに済み、UC-004・UC-009の既存テストも無改修でGreenのまま維持できる。加えて`CREATE TABLE`は純粋な追加操作であり、`.claude/rules/20-mysql.md`が定める「危険な操作（カラム型変更）」に該当しない。同じ危険な操作の例外扱いをADR-0004に続けて2度使うのは運用として望ましくない
- **保留中の「利確閾値の動的分岐」検討との相互作用も確認済み**: 動的分岐が「売りシグナル0件なら利確ラインを引き上げる」判定を`signals`件数で行う設計であるため、仮に選択肢Aを採っていた場合、買いシグナルの混入によって「押し目が出ている＝下落兆候あり」と誤認し利確ラインを不当に下げてしまう致命的なバグになり得た。`buy_signals`分離（D2）はこのリスクも同時に排除している
- 判定サービスを新設する理由: `SignalDeterminationService`のクラスDocblockが"take-profit signal types"とスコープを宣言しており、`determine()`の戻り値が`FetchExternalMarketDataAction`で直接`signals`テーブルに書き込まれる契約になっている。買いシグナルを混ぜるには戻り値に方向情報を追加する必要があり、既にGreenのUnit Test 21件を改修することになる
- 合成スコアを導入しない理由: ADR-0003（スコアリング透明性）の3原則の適用対象になり検討コストが増す。ソートを「ファンダ状態→買いシグナル件数→含み益率の低い順」という単純キーにすることで、この論点自体を回避する
- NISA区分を除外要因にしない理由: 利確側は非課税メリットを維持するため売却提案しないが、買い増しは非課税メリットを増やす方向の行為であり、ADR-0002の「買い側はNISA枠推奨」という既存決定の範囲を、新規投資候補（UC-008）から既存保有の買い増し（UC-010）に拡張するだけで矛盾しない。副次的な利点として、UC-004が抱える「`holding_snapshot_accounts`書き込み未実装のためNISA除外がスコープ外」という保留事項が、UC-010では`holding_snapshot_accounts`を一切参照しないため発生しない

### 採用しなかった代替案

- **`signals`テーブルに`direction`カラムを追加してenum拡張する（選択肢A）**: Rationale記載の3箇所の副作用と、危険な操作の例外扱いの重複により不採用
- **`SignalDeterminationService`に買い判定メソッドを追加する**: クラスのスコープ宣言・既存契約・既存テストへの影響から不採用
- **ファンダメンタルズ健全性フィルタを永続化時（`buy_signals`保存時）に適用する**: `fundamental_indicators`は現在値キャッシュ（UPSERT、時点データではない）であるため、判定時にフィルタ結果を焼き込むと後日の指標更新や閾値変更が再取込なしに反映されない。技術的な押し目シグナルは時点の事実として永続化し、健全性は現在値による判断として表示時評価する、と責務を分離した
- **損切り・売却判断支援まで含めてOUTスコープを広く見直す**: スコープ拡散を避けるため、今回は「押し目買い＋ファンダ健全性フィルタ」の範囲に限定し、損切り判断は引き続きOUTのまま維持する

## Consequences

### メリット
- 既に算出済みで未使用だった`bb_lower`・`week52_low`を活用でき、追加の外部データ取得コストなしに機能を追加できる
- テーブル分離により、既にGreen・マージ済みのUC-004（`ShowSignalListAction`）・UC-009（`ShowImportSummaryReportAction`）への改修が一切発生しない。ADR-0002/ADR-0003（CHG-0001/CHG-0002）が既存実装への追加改修とGate 4再承認を要したのと対照的
- 保留中の「利確閾値の動的分岐」を将来実装する際も、`buy_signals`分離により売り側の`signals`件数カウントへの汚染リスクがない

### デメリット・リスク
- `signals`と`buy_signals`のテーブル構造がほぼ同型で重複する。ただし判定サービス・Actionも別クラスにする以上、テーブルだけ統合しても得るものがないと判断した
- ファンダメンタルズ健全性フィルタの閾値定数（ROE 10%・自己資本比率40%）が`ShowImportSummaryReportAction`と`FundamentalHealthEvaluator`の2箇所に重複する。今サイクルでは共通化しない（スコープ外実装の禁止）。将来、動的分岐サイクルや新規投資候補（F-008）実装時に3箇所目が生まれる可能性があり、その時点で`FundamentalHealthEvaluator`への集約を検討する
- Gate 1（requirements.md）・Gate 2（use-cases.md）・Gate 3（data-model.md）で一度承認された内容を覆すため、3ドキュメントすべてで再度レビューが必要になる
- US株はファンダメンタルズ指標が未実装、JP株も一部J-Quantsレート制限で取得失敗するため、`fundamental_status='unavailable'`の銘柄が一覧に一定数残る。これは既知の制約であり今回は対応しない

## Addendum（2026-08-23、Gate2/3承認時の設計修正）

Gate2（use-cases.md）・Gate3（data-model.md）の正式承認レビューで、ユーザーから本機能の意図が改めて確認された: 「健全で好調だった銘柄が、市場全体・セクター全体の調整で一時的に下げた場面」を拾う設計であり、長期低迷銘柄や個別要因で下落している銘柄を拾う設計ではない。

当初のD2で定めた7シグナル種別（`rsi_oversold_rebound`等）は、UC-004の売りシグナルと面対称にするため汎用的な「売られすぎ・反発」の技術指標のみで構成されており、(1)直前まで好調だったか、(2)下落が市場全体・セクター全体の連れ安によるものか、を確認する要素を欠いていた。特に`week52_low_proximity`は52週安値圏という条件のみでは長期低迷銘柄も拾ってしまう。

そのため、7シグナル共通の前提条件として以下2点を追加する（`use-cases.md` UC-010業務ルール、`data-model.md`の`buy_signals`節・初期パラメータ表を参照）:

- 直近13週以内に`week52_high`の-15%以内に到達していたこと
- `relative_strength_vs_market`が-5pt以上であること

いずれも既存の算出済みデータ（`TechnicalIndicatorCalculator`が保持する週次価格系列、および`relative_strength_vs_market`カラム）で実現でき、追加の外部データ取得は発生しない。7シグナル種別自体（D2）・テーブル分離方針（D2〜D3）・ファンダメンタルズフィルタ（D4）・NISA方針（D5）は変更しない。

## Related
- `docs/product/requirements.md`（2章・4章・6章・7章）
- `docs/product/use-cases.md`（UC-001・UC-004・UC-010新設）
- `docs/architecture/data-model.md`（`buy_signals`テーブル新設）
- `docs/rcid/traceability-matrix.md`（CHG-0004）
- `docs/adr/ADR-0002-nisa-account-type-tracking.md`（NISA区分の買い側方針の先例、同種のGate承認済みドキュメントを覆すCRの先例）
- `docs/adr/ADR-0003-f009-scoring-transparency-relaxation.md`（同種のGate承認済みドキュメントを覆すCRの先例）
- `docs/adr/ADR-0004-analysis-engine-indicator-expansion.md`（`bb_lower`・`week52_low`を算出している指標セット拡張の先例、enum拡張を危険な操作として例外扱いした先例）
- `PLAN.md`先頭エントリ（「利確・リバランス閾値の動的分岐ロジック検討」、本ADRのスコープ外だが相互作用を確認済み）
