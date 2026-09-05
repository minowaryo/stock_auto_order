# ADR-0009: 米国株ファンダメンタルズ指標データソースとしてFinnhubを採用

## Status
Accepted

## Date
2026-09-05

## Context

ADR-0004（分析エンジン指標拡張）・ADR-0007（買い増しレコメンド）はいずれも「US株はファンダメンタルズ指標が未実装、`fundamental_status='unavailable'`のまま許容する」ことを既知の制約として明記していた。原因はJ-Quants APIが日本株専用のデータソースであり、米国株のPER・PBR・ROE・売上高/営業利益成長率・自己資本比率・配当利回り/性向・EPS成長率・PEGレシオを取得する手段がそもそも存在しなかったため。

この結果、`docs/product/use-cases.md`のUC-003（銘柄詳細）・UC-004（利確検討）・UC-007（市場全体指標）・UC-010（買い増しレコメンド）は米国保有銘柄（現在44銘柄）についてファンダメンタルズ欄が常に「取得不可」表示となり、財務健全性フィルタ（`FundamentalHealthEvaluator`のROE≧10%・自己資本比率≧40%・成長率>0%判定）が技術指標のみで判定され、日本株と対等に比較できない状態が続いていた。

ユーザーの依頼により、この既知の制約を解消する代替データソースを調査した。

## Decision

- **D1**: 米国株ファンダメンタルズ指標のデータソースとして**Finnhub API**（無料枠）を採用する
- **D2**: J-Quants用の`FundamentalIndicatorMapper`（多期間の`/fins/summary`統計列からPER/PBR/成長率を計算するJP専用ロジック）は変更せず、**Finnhub用に新規`UsFundamentalIndicatorMapper`（仮称）を並立**させる。既存の`JpStockPriceClientInterface`/`UsStockPriceClientInterface`が同一の`YahooFinanceChartClient`を市場別インターフェースで使い分けている先例（ADR-0004）と同じ設計方針を踏襲する
- **D3**: フィールドマッピングは次の通りとする
  - `per`/`pbr`/`roe`/`revenue_growth`/`eps_growth`/`dividend_yield`/`dividend_payout_ratio`/`peg_ratio`: `stock/metric`（`metric=all`）エンドポイントの`peTTM`/`pbAnnual`/`roeTTM`/`revenueGrowthTTMYoy`/`epsGrowthTTMYoy`/`dividendYieldIndicatedAnnual`/`payoutRatioTTM`/`pegTTM`をそのまま採用（Finnhub側で計算済みの値を使う。JP株のようにEPS・簿価から自前計算しない）
  - `equity_ratio`（自己資本比率）: `stock/financials-reported`（10-KのXBRL実データ）の`us-gaap_Assets`（総資産）・`us-gaap_StockholdersEquity`（自己資本）から`自己資本 ÷ 総資産`で実測計算する。`totalDebt/totalEquity`からの近似は採用しない（Rationale参照）
  - `operating_income_growth`（営業利益成長率）: Finnhubの`metric`にYoY値の直接提供がないため、`stock/financials-reported`の`ic`（損益計算書）セクションの`us-gaap_OperatingIncomeLoss`を直近期・前期（`data[0]`/`data[1]`）で比較し、JP側`FundamentalIndicatorMapper::calculateGrowth()`と同じ考え方（前年同期比）でYoY成長率を算出する
- **D4**: DBスキーマ変更は行わない。`fundamental_indicators`テーブルは既に市場非依存のnullable列構成（`per`/`pbr`/`roe`/`revenue_growth`/`operating_income_growth`/`equity_ratio`/`dividend_yield`/`dividend_payout_ratio`/`eps_growth`/`peg_ratio`）になっており、米国株の行にも同じスキーマでUPSERTするだけで対応できる
- **D5**: レート制限（無料枠60リクエスト/分、全プラン共通30リクエスト/秒上限）に対しては、レスポンスヘッダー`X-Ratelimit-Remaining`を見て閾値に近づいたら次のリクエストを待機する自己スロットリングを行う。429（レート制限超過）が返った場合は例外で処理を中断せず、指数バックオフでリトライする。リトライしても取得できない銘柄は既存の`unavailable`表示にフォールバックする（新しい失敗モードを増やさない）
- **D6**: APIキーは`JQUANTS_API_KEY`と同様の扱いとする。`.env`にのみ実値を設定し（設定済み・`.gitignore`対象であることを確認済み）、`.env.example`にはプレースホルダとコメントのみを追加した。`docs/ai-context/do-not-touch.md`「外部連携」節にFinnhub APIキーの扱いを追記する
- **D7**: 段階導入ではなく、対象8指標（PER・PBR・ROE・売上高成長率・営業利益成長率・自己資本比率・配当利回り/性向・EPS成長率・PEGレシオ）を一括で実数値化する（ユーザー承認事項）

## Rationale

- **データソース候補の比較検証**（実際にHTTPで疎通確認）:
  - Alpha Vantage: 無料枠が25リクエスト/日に制限されており、米国保有44銘柄を1回の取込で同期取得する現行アーキテクチャ（`FetchExternalMarketDataAction`がCSV取込時に全銘柄を同期的に取得する設計）と根本的に相性が悪い。即座に日次上限を超える
  - Financial Modeling Prep・Twelve Data: リクエスト数の枠自体は足りそうだが、必要な比率系エンドポイント（ratios/key-metrics/fundamentals）が無料プランの対象かどうかをドキュメント上で確認できなかった
  - Finnhub: 無料枠60リクエスト/分。`stock/metric`を実際にAAPLで叩き、HTTP 200・PER/PBR/ROE/成長率/配当関連を含む133フィールドが返ることを実データで確認した。44銘柄×2エンドポイント（`metric`+`financials-reported`）=88リクエストでも2分弱で完了する規模であり、現行の同期取得アーキテクチャに最も適合する
- **自己資本比率の近似不採用の根拠**: `totalDebt/totalEquity`から`1/(1+D/E)`で近似する案を検証したところ、AAPL・ACN・AMD・AMZN・ACHRの5銘柄で近似値が実測値を最大で約2倍過大評価することが判明した（例: AAPLは実測20.52%に対し近似42.47%）。財務健全性フィルタの閾値（自己資本比率≧40%）を挟むと判定が逆転しかねない致命的な誤差のため、近似ではなく`financials-reported`からの実測計算を採用した。この実測値はSEC提出済み10-K（Apple FY2025、2025-10-31提出）の公表値と完全一致することをWeb検索で追加確認済み

### 採用しなかった代替案

- **Alpha Vantage**: 無料枠25リクエスト/日が現行の同期取込アーキテクチャと相容れないため不採用
- **Financial Modeling Prep / Twelve Data**: 必要なエンドポイントの無料プラン可否が実機検証前で確定できず、Finnhubで実データ確認が取れた時点でこれ以上の比較検証は行わないと判断
- **`totalDebt/totalEquity`からの自己資本比率近似**: 実測との誤差が最大約2倍に達し、財務健全性フィルタの40%閾値判定を逆転させ得るため不採用
- **既存`FundamentalIndicatorMapper`にFinnhub分岐を追加する案**: J-Quants用は「多期間の統計列から自前計算する」設計、Finnhubは「事前計算済みの比率をそのまま使う」設計で入力形状が根本的に異なるため、1クラスに両方の分岐を持たせるとテスト・可読性が悪化する。市場別に責務を分離する既存の設計方針（`JpStockPriceClientInterface`/`UsStockPriceClientInterface`）を踏襲し、新規クラスとして分離する

## Consequences

### メリット
- UC-003（銘柄詳細）・UC-004（利確検討）・UC-007（市場全体指標）・UC-010（買い増しレコメンド）で米国保有銘柄のファンダメンタルズ指標・財務健全性フィルタが日本株と対等に機能するようになる
- `fundamental_indicators`テーブルが既に市場非依存スキーマのため、DBマイグレーション不要で対応できる
- 自己資本比率を近似ではなく実測（SEC提出書類ベース）で計算するため、日本株（J-Quants）と同水準の精度を確保できる

### デメリット・リスク
- 銘柄あたりのAPI呼び出しが`metric`+`financials-reported`の2回に増える。米国保有銘柄数が将来大きく増えた場合、無料枠60リクエスト/分でも取込処理時間が伸びる可能性がある（D5のスロットリング設計で対応）
- 無料枠であるため、Finnhub側の提供内容・レート制限が将来変更されるリスクがある（有料プランへの移行検討が必要になる可能性）
- `operating_income_growth`の算出ロジックがJP側（`FundamentalIndicatorMapper::calculateGrowth()`、`/fins/summary`の統計列を使用）とUS側（`financials-reported`のXBRL損益計算書を使用）でデータソース・実装が異なる二重管理になる。両者は入力形状が違うため統合は見送るが、将来どちらかの計算式だけ変更されると定義が乖離するリスクは残る
- 米国株の指標名の一部（PEGレシオ`pegTTM`等）はFinnhub側で独自に計算されたものであり、JP株の計算式（PER÷EPS成長率、`FundamentalIndicatorMapper::calculatePegRatio()`）と算出方法が完全には一致しない可能性がある。表示上は同一の`peg_ratio`列に格納されるため、算出方法の違いをUIまたはドキュメントで注記する必要がある

## Related
- `docs/adr/ADR-0004-analysis-engine-indicator-expansion.md`（US株ファンダメンタルズを「今回未対応」とした先例、市場別インターフェース分離の設計方針の先例）
- `docs/adr/ADR-0007-existing-holding-add-on-buy-recommendation.md`（US株ファンダメンタルズ未実装を既知の制約として明記した先例）
- `docs/product/use-cases.md`（UC-003/UC-004/UC-007/UC-010のファンダメンタルズ`unavailable`記述、Gate2更新対象）
- `docs/architecture/data-model.md`（`fundamental_indicators`テーブル、Gate3更新対象）
- `docs/ai-context/do-not-touch.md`「外部連携」節（Finnhub APIキー管理の追記対象）
- `.env.example`（`FINNHUB_API_KEY`プレースホルダ追加済み）
