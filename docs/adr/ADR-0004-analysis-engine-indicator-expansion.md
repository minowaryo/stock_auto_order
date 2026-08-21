# ADR-0004: 分析エンジンの指標セット拡張とデータ取得方式の確定

## Status
Accepted

## Date
2026-08-21

## Context

UC-001〜UC-003はGate4サイクルを経て実装済み（Green完了）だが、分析エンジンの心臓部（テクニカル/ファンダメンタルズ指標の実計算・J-Quants/Yahoo Finance連携・シグナル判定ロジック）はまだ着手していなかった。`app/Services/Analysis/`・`app/Services/MarketData/`は`docs/ai-context/module-map.md`に将来の配置として記載されているのみで、実体がない状態だった。

この分析エンジンを設計するにあたり、以下を本人と協議し決定した。

1. 外部データ（J-Quants・Yahoo Finance相当）の取得タイミング・実装方式
2. `docs/original-docs/stock-portfolio-system-plan.md`で判断材料の優先順位①とされながら、指標セット・データモデルのどこにも反映されていなかった「出来高」の扱い
3. `requirements.md`のF-004スコープに記載されながらUC-004/data-model.mdに未反映だった「チャート形状パターン検出（三尊天井等）」の扱い
4. 既存の指標セット（RSI・MACD・BB・MA、PER・PBR・ROE・成長率・自己資本比率・配当）が、個人投資家（本人）の投資判断材料として妥当か、他に一般的に重要とされる指標が不足していないか

## Decision

### 1. 外部データ取得のタイミング・実装方式

- CSV取込（UC-001）フロー内で同期的に取得する。UC-001のフロー6（取込結果表示）とフロー7（旧: UC-009自動生成、新: フロー9）の間に、外部データ取得・指標計算・シグナル判定のステップ（新フロー7〜8）を追加する
- 実装はLaravelの`Illuminate\Http\Client`で各APIを直接呼び出す方式とする。Pythonスクリプト（yfinance等）へのブリッジ方式は不採用とし、Docker(Sail)構成への追加依存を発生させない
- `app/Services/MarketData/`にJ-Quantsクライアント・JP株価格クライアント・US株価格クライアント・市場指数クライアントをInterface化して配置し、テストではFake実装に差し替える（実APIをFeature Testで叩かない）

### 2. 指標セットの拡張

基礎指標は「人間が目で見て判断できる」ものに限定するという既存方針（`requirements.md` 6章、ADR-0003）を維持しつつ、以下4指標を追加する。

- **出来高**（`volume`/`volume_ma20`）: 判断材料の優先順位①として位置づけられていたが指標セットに未反映だったギャップを解消。データ取得元は他のテクニカル指標と同じ価格系列（OHLCV）のため追加のAPI連携は不要
- **52週高値・安値**（`week52_high`/`week52_low`）: 現在値の位置を直感的に把握できるシンプルな指標。MA75計算に必要な75週分の価格データを流用でき、追加のAPI呼び出しは不要
- **PEGレシオ**（`eps_growth`/`peg_ratio`）: 既存のPER単体では高PERの成長株（AI・半導体等、現在の保有の中心）の割高/割安を判断しづらいという弱点を補う。J-Quants財務諸表のEPSから算出し、追加のデータソースは不要
- **相対力**（`relative_strength_vs_market`/`relative_strength_vs_sector`）: 「メタトレンドに乗る」という投資哲学（`docs/original-docs/stock-portfolio-system-plan.md`）を定量的に裏付ける指標。対市場は日経平均/S&P500の週次騰落率との差分、対セクターはJ-Quants無料プランに業種別指数（TOPIX-17指数等）が存在しない（スタンダード/プレミアムプラン限定）ため、保有銘柄内の同一セクター平均騰落率で簡易代用する

チャート形状パターン検出（三尊天井等）は今回のスコープに含めず、ゴールデンクロス/デッドクロス判定のみで進める（引き続き持ち越し）。

### 3. シグナル判定への組み込み

`signals.signal_type`に以下4種を追加する（初期パラメータ値は叩き台、Phase 1実装時の`/tdd`サイクルで確定）。

- `week52_high_pullback`（52週高値から-10%以上下落）
- `peg_overvalued`（PEGレシオ2.0以上）
- `relative_strength_weakening`（対市場の相対力が直近4週でプラス→マイナスに転換）
- `volume_spike_decline`（出来高が20週平均の1.5倍以上、かつ株価が前週比下落）

### 4. Phase境界の依存関係（F-009と同じパターン）

相対力（対市場）の算出には市場指数（日経平均・S&P500）の週次時系列が必要だが、これを保存する`market_indicator_snapshots`テーブルは本来UC-007（Phase2）専用である。F-009がF-005/F-008の軽量ロジックをPhase1に先行実装したのと同じパターンで、`market_indicator_snapshots`の**取得・保存ロジックのみ**（`index_name`が`nikkei225`/`sp500`の2件分）をPhase1に先行実装する。UC-007のダッシュボード画面本体（前日比・移動平均乖離の可視化、米国10年債利回り/VIX指数/USD-JPYを含む全指標表示）はPhase2のまま変更しない。

## Rationale

- 出来高・52週高値安値・PEGレシオ・相対力はいずれも個人投資家が実務で広く使う標準的な指標であり、「人間が目で見て判断できるシンプルさ」という設計方針（ADR-0003）を損なわない。ML的な合成特徴量やDCF法等の高度な分析は見送り、既存の設計哲学を維持する
- 相対力（対セクター）を業種別指数ではなく保有銘柄内平均で代用するのは、J-Quants無料プラン（`requirements.md`の前提）の制約による現実的な妥協である。保有銘柄が少ないセクターではサンプルが偏る弱点があるが、画面上にその旨を明示することで対応する
- Phase1へのmarket_indicator_snapshots軽量先行実装は、F-009のときと同様「取込直後の分析価値を優先し、依存関係の逆転を許容する」という既存の意思決定パターンを踏襲したものであり、新たな設計思想の追加ではない

### 採用しなかった代替案

- **相対力算出のために市場指数データをUC-007実装（Phase2）まで待つ**: 相対力（対市場）自体をPhase1のスコープから除外する案。本人の合意により不採用（「F-009と同じパターンで軽量先行実装」を選択）
- **新指標をUC-004のシグナル判定に組み込まず、UC-003の参考表示のみに留める**: スコープを抑えられる案だったが、本人が「シグナル判定にも組み込む」ことを選択したため不採用
- **Pythonスクリプト（yfinance）へのブリッジ方式でのデータ取得**: `docs/original-docs/stock-portfolio-system-plan.md`の初期構想に近いが、Python環境への追加依存が生じるため不採用

## Consequences

### メリット
- 分析エンジンの心臓部（`app/Services/Analysis/`・`app/Services/MarketData/`）の設計方針が確定し、実装に着手できる
- 判断材料の優先順位①（出来高）の未反映という既知のギャップが解消される
- 指標セットが「投資歴5年程度の個人投資家が実務で使う標準的な指標」の水準に近づく

### デメリット・リスク
- UC-003は既にGreen完了・マージ済みのため、本CRの反映には`ShowHoldingDetailAction`・`tests/Feature/UC003HoldingDetailTest.php`への追加改修（新たなRed→Green、Gate4再承認）が必要になる（`traceability-matrix.md`のCHG-0001と同様の既存実装への追加CR）
- **UC-009も本ADR確定時点で既にGreen完了・`/review`対応・マージ済みだったことが判明した**（`2f6a56c`、本ADR起票と並行して別セッションで進行）。`ShowImportSummaryReportAction`は利確検討で`unrealized_gain_rate`/`rsi`のみ、新規投資候補で`equity_ratio`/`roe`のみを`composite_score`・`reason_summary`の根拠にしており、出来高・52週高値安値・PEG・相対力は未反映。UC-003と同様、既存実装への追加改修（新たなRed→Green、Gate4再承認）が必要になる。ただし新カラムはいずれも追加のnullable列であり、`signals.signal_type`・`market_indicator_snapshots`のいずれもUC-009の現行実装は参照していないため、コードレベルの後方互換性は壊れていない
- `signals.signal_type`のENUM拡張は`.claude/rules/20-mysql.md`が定める「危険な操作（カラム型変更）」に該当する。個人利用規模のため実害は軽微と判断するが、本ADRを変更理由の記録とする
- 相対力（対セクター）の簡易算出は業種別指数を使う場合より精度が落ちる。将来J-Quantsを有償プランに切り替えた場合は業種別指数ベースに置き換える余地を残す

## Related
- `docs/product/requirements.md`（2章・6章・7章）
- `docs/product/use-cases.md`（UC-001・UC-003・UC-004・UC-007・UC-009）
- `docs/architecture/data-model.md`（`technical_indicators`/`fundamental_indicators`/`financial_statements`/`signals`/`market_indicator_snapshots`）
- `docs/ai-context/module-map.md`（`app/Services/Analysis/`・`app/Services/MarketData/`）
- `docs/rcid/traceability-matrix.md`（CHG-0003）
- `app/Actions/Holding/ShowHoldingDetailAction.php`（UC-003、追加改修対象）
- `app/Actions/ImportSummaryReport/ShowImportSummaryReportAction.php`（UC-009、追加改修対象）
- `docs/adr/ADR-0002-nisa-account-type-tracking.md`（既にGreenだったUCへの追加CRの先例）
- `docs/adr/ADR-0003-f009-scoring-transparency-relaxation.md`（Gate承認済みドキュメントを覆すCRの先例）
