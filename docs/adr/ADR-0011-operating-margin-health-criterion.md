# ADR-0011: 財務健全性フィルタへの営業利益率（operating_margin）追加

## Status
Proposed（Phase 0 ドキュメント先行。Gate 3 レビュー待ち。実装は F-011〔`feat/f011-loss-review-list`〕マージ後）

## Date
2026-09-06

## Context

`FundamentalHealthEvaluator` が判定する財務健全性は現在 **ROE・自己資本比率・成長率（売上高または営業利益成長率のいずれかプラス）の3項目**。この3項目は「資本効率」「BSの頑丈さ」「伸びているか」を見ているが、**事業そのものの稼ぐ力（利益率）を測る指標が欠けている**。

- ROE は借入レバレッジで嵩上げできるため、「本当に強い事業か」を単独では判断できない
- 成長率は「伸びているか」だけを見ており、薄利のまま売上を伸ばしている構造的にきつい会社を弾けない

本人の要望は「財務健全性を確認できるわかりやすい項目を、中長期目線での評価がしやすくなるようもう1項目足したい」。営業利益率（営業利益 ÷ 売上高）は「100円売って何円の営業利益が残るか」という直感的な指標で、競争力・価格決定力・参入障壁を素直に映すため、中長期評価の材料として3項目の穴をちょうど埋める。

### 実装前に完了した実測検証（2026-09-06、保有128銘柄）

| 検証項目 | 結果 |
|---|---|
| US: Finnhub `stock/metric` に営業利益率があるか | ✅ `operatingMarginTTM` / `operatingMarginAnnual` が存在（`metric=all` の133キーに安定して含まれる）。AAPL 33.17 / MSFT 46.73 とパーセントスケールで実態と一致 |
| JP: J-Quants から算出できるか | ✅ `FundamentalIndicatorMapper` が既に取得済みの `net_sales`(Sales) / `operating_profit`(OP) から算出可。実データで 4.4〜34.9% と妥当 |
| JP保有72銘柄の営業利益率分布 | min -1.9 / p25 7.5 / median 10.4 / p75 17.3 / max 67.4 |
| 桁あふれリスク | ⚠️ ACHR（プレレベニュー）で Finnhub が `operatingMarginAnnual = -243100` を返す。`decimal(7,4)` だと ADR-0006 の `eps_growth` と同じ INSERT エラーが再発する |

### 閾値の実測比較（現在 `passed` の27銘柄がいくつ残るか）

| 閾値 | JP passed | US passed | 合計 | 追加で `failed` に落ちる銘柄 |
|---|---|---|---|---|
| 現状（3項目） | 15 | 12 | 27 | — |
| **8%** | 14 | 11 | **25** | 3088 マツキヨココカラ 7.6% / IONQ -408%（プレレベニュー） |
| **10%（採用）** | 11 | 11 | **22** | ↑ に加えて 7867 タカラトミー 9.0% / 5288 アジアパイルHD 9.4% / 5805 SWCC 9.8% |

影響が小さいのは、`passed` が既に ROE≥10% かつ 自己資本比率≥40% かつ 成長率>0 を要求しており、これを通る企業は営業利益率10%も概ね満たす（条件が相関している）ため。

## Decision

### D1. 営業利益率を財務健全性フィルタの4条件目として `FundamentalHealthEvaluator` に組み込む

表示のみ（`SignalCriteriaEvaluator` のチェックリスト行だけ追加）ではなく、`evaluate()` の判定に組み込む。本人が「中長期評価がしやすくなるように」と明示しており、買い増し候補（UC-010）・新規投資候補（UC-008/009）・リバランス候補（UC-005）の抽出そのものに効かせる意図。

### D2. 閾値は 10%以上

8%案（落ちるのは実質2銘柄）と 10%案（5銘柄）を実測比較し 10% を採用。ROE の 10% と数字が揃い説明しやすいこと、追加で落ちる3銘柄（タカラトミー・アジアパイルHD・SWCC）がいずれも 9〜10% の境界上で「中長期の主力にするには利益率がもう一段ほしい」という位置づけに合うことを許容。値は叩き台で、Gate4 実装時に `/tdd` サイクルで確定する（`accuracy-improvement-backlog.md` の閾値キャリブレーションと同じ扱い）。

### D3. `operating_margin` を `fundamental_indicators` に1列追加（`decimal(10,4)` nullable）

CHG-0009（Finnhub）以降で唯一のスキーマ変更。マイグレーションが必要。`decimal(10,4)`（±999999.9999%）は成長率3列（ADR-0006 で拡張済み）と揃える。`after('equity_ratio')`。

### D4. 算出方法：JP＝実測算出、US＝Finnhub の TTM 採用

- JP: `FundamentalIndicatorMapper` の最新期（index 0）から `operating_profit ÷ net_sales × 100`。`net_sales` が null/0以下・`operating_profit` が null なら null（`calculatePer()` / `calculatePbr()` と同じガード）
- US: `UsFundamentalIndicatorMapper` で `metrics['operatingMarginTTM'] ?? metrics['operatingMarginAnnual'] ?? null`。既にパーセントスケールのため ×100 しない（`roeTTM` / `revenueGrowthTTMYoy` と同じ扱い）

`equity_ratio` / `operating_income_growth` は ADR-0009 D3 で「近似不採用・実測算出」としたが、それは metric に信頼できる値が無い／期ズレするためだった。営業利益率は metric に直接あり、`roe` 等と同じく採用してよい。**同一カラムに算出方法が異なる値が混在する点は `peg_ratio`（ADR-0009）と同じ構図なので、data-model.md に同じ形で注記する。**

### D5. `|営業利益率| > 999%` は両 Mapper で null 化する

ACHR の -243100% のような売上ほぼゼロのプレレベニュー企業は、営業利益率が数値として情報を持たない。`decimal(7,4)` を避けて `decimal(10,4)` にした上で、さらに Mapper 側で異常値を null 化して INSERT しない（DB に極端値を残さない）。チップ表示は `unavailable`（—）。

### D6. null は判定に使わない（`unavailable` 扱い）

実測値があり 10%未満のときのみ `failed`。null（JP で当期の営業利益開示なし等）は既存の成長率と同じく `unavailable`。判定順序は次のとおりで、`/review` 修正1（基準割れをデータ欠損より優先）の不変条件を踏襲する：

```
equity_ratio == null || roe == null            → unavailable
equity_ratio < 40 || roe < 10                    → failed
operating_margin != null && operating_margin < 10 → failed   ← 追加
operating_margin == null                          → unavailable   ← 追加
revenue_growth == null && operating_income_growth == null → unavailable
（成長率判定）
```

### D7. UC-011（整理検討）では既存の反転フラグに乗せる

ADR-0010 D6（2026-09-06 改訂）で財務健全性3項目は整理検討テーブルでは判定の向きを反転し「基準割れ＝投資根拠の毀損」を `met`（赤チップ）とする。営業利益率も同じ反転フラグに乗せ、10%未満で `met`（赤）。サマリは「投資根拠の毀損 ◯/4」。UC-004/UC-010 の配色・挙動は不変（緑系、10%以上で met）。

### D8. NISA推奨（`nisa_recommended`）の追加基準は変更しない

現状の「自己資本比率50%以上・ROE15%以上」のまま。営業利益率をここに足すかは本CRのスコープ外（必要になれば別CR）。

### D9. 実装着手は F-011 マージ後

`feat/f011-loss-review-list`（F-011）が `SignalCriteriaEvaluator::fundamentalRows()` と、`SignalCriteriaEvaluatorTest` の `'total' => 3` を多数アサートするテスト群を触っている最中。営業利益率追加は同じ箇所を触るため、F-011 をマージしてから独立CRとして着手する。本ADRと data-model.md / use-cases.md / requirements.md / ui-guidelines.md の改訂（Phase 0）のみ先行する。

## Rationale

### 営業利益率を選んだ理由（他の候補との比較）

| 候補 | 却下理由 |
|---|---|
| フリーキャッシュフローがプラスか | 長期存続力の指標として有力だが、キャッシュフロー計算書の取得実装（J-Quants／Finnhub 別エンドポイント）が必要でコスト中〜高。営業利益率が低コストで穴を埋められるため後回し |
| 有利子負債 / D-E レシオ | 自己資本比率と情報が重複し、追加の情報量が限定的 |
| 配当性向 | `dividend_payout_ratio` 列は既にあるが、配当銘柄限定で汎用性が低い |
| EPS成長率 | `eps_growth` 列は既にあるが、売上・営業利益成長率と重複気味 |

### 「表示のみ」ではなく「判定に組み込む」を選んだ理由

表示のみなら影響範囲ゼロで安全だが、本人の意図は「抽出そのものを中長期向きにしたい」。実測で影響が 5 銘柄に留まること・落ちる銘柄がいずれも妥当な検出であることを確認できたため、判定組み込みのリスクは許容範囲と判断した。

## Consequences

### メリット

- 中長期評価の軸が「効率・安全・成長・**収益性**」の4本になり、薄利成長企業を買い増し／新規候補から外せる
- チェックリスト表示は `criteria` 配列駆動のため、財務グループが自動で4列に増える（ヘッダー `colspan`・`colgroup`・チップセル・サマリ「◯/4」がすべて追従）

### デメリット・リスク

- `FundamentalHealthEvaluator::evaluate()` のシグネチャに5つ目の引数が増え、**呼び出し元6機能すべての改修が必須**：`TakeProfitThresholdEvaluator`（UC-004）／`NewCandidateFinder`（UC-005/008/009）／`ShowImportSummaryReportAction`（UC-009）／`ShowBuySignalListAction`（UC-010）／`ShowLossReviewListAction`（UC-011）。`FundamentalIndicator::healthEvaluatorArgs()` を4→5要素に拡張して重複を抑える
- **`NewCandidateFinder` の SQL 事前絞り込み（`equity_ratio >= 40 AND roe >= 10`）に `operating_margin >= 10` を足してはいけない**。NULL 行が SQL レベルで落ち、evaluator の「null は unavailable」判定に到達しなくなる
- 算出方法の混在（JP=決算期実測 / US=TTM）。`peg_ratio` と同じ既知の割り切り
- マイグレーションが1本増える（CHG-0010・CHG-0011 が「スキーマ変更なし」だったのに対し、本CRは1列追加）

## Related

- ADR-0004（分析エンジン指標拡張、CHG-0003）
- ADR-0006（`fundamental_indicators` 成長列の桁拡張 — 本ADRの `decimal(10,4)` / 異常値null化の先例）
- ADR-0007（買い増し健全性フィルタ、CHG-0004）
- ADR-0009（US株ファンダは Finnhub、CHG-0009 — `stock/metric` 採用 vs `financials-reported` 実測の判断先例）
- ADR-0010（整理検討候補一覧、CHG-0010 — D6 の反転フラグに営業利益率も乗せる）
- CHG-0012（トレーサビリティ）
