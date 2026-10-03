# ADR-0020: 米国株・投資信託のセクター分類と、市場別・金額付きのセクター配分表示

## Status
Accepted（2026-10-01、本人承認。米国業種は英語のまま・市場表示順は日本株→米国株→投資信託）

## Date
2026-10-01

## Context

UC-005（セクター配分）で「未分類」が評価額ベースで約73%を占め、常に「偏り警告」になっていた（2026-10-01実測）。内訳は米国株約978万円・投資信託約157万円で、日本株は最新スナップショットでは全件分類済み。原因は、セクターを取得・保存する処理が日本株（J-Quants）にしか存在せず（`FetchExternalMarketDataAction` の `market === 'jp'` 分岐）、米国株・投信は取込を重ねても未分類のままになることだった。

あわせて本人から次の2点の要望があった（2026-10-01）。
- 米国株と日本株がそれぞれ区別できる表示にする
- 合計額（評価額）もわかるようにする
- 構成比の小数点以下が長すぎる（小数1桁にする。表示のみの修正で実施済み）

## Decision

- **D1（スキーマ）**: `sector_classifications` に `market` 列（varchar(12)・NOT NULL・既定 `jp`、値は `holdings.market` と同じ `jp` / `us` / `mutual_fund`）を追加し、一意制約を `name` 単独から `(market, name)` に変更する。既存行は `jp`。
- **D2（米国株）**: `FinnhubClient` に `fetchIndustry()`（`/stock/profile2` の `finnhubIndustry`）を追加し、外部データ取得時に `SectorClassification(market=us, name=<Finnhub業種名>)` を `firstOrCreate` して保有に紐づける。J-Quants 17業種へのマッピングはしない（別枠で表示するため。対応表は精度の検証コストが大きく、日米の業種は粒度が一致しない）。取得失敗は従来どおり処理を止めず、既存値を保持する。
- **D3（投資信託）**: 固定カテゴリ `SectorClassification(market=mutual_fund, name=投資信託)` を用意し、`instrument_type = mutual_fund` の保有に紐づける。
- **D4（表示）**: セクター配分は「日本株 / 米国株 / 投資信託」の市場別に区切って表示し、各セクター行に配分比率（小数1桁）と**評価額（円）**を出す。各市場の**小計**と全体の**合計額**も表示する。未分類は市場ごとに別行にする。偏り判定（40%/70%）は従来どおり全体に対するセクター単位の比率で行う。
- **D5（既存データ）**: 既存保有への一括反映用に `sectors:backfill` コマンドを用意する（J-Quantsマスタ1回＋Finnhubで未分類の保有のみ。冪等）。

## Rationale

- 米国業種を17業種へ寄せる案は、誤分類がそのまま偏り判定に効くため見送った。別枠なら誤差が他市場に波及しない。
- 市場を `holdings.market` と同じ語彙にしたのは、グルーピングと表示の分岐を増やさないため。
- 一意制約の変更は `.claude/rules/20-mysql.md` の「危険な操作」に準じるが、`sector_classifications` は数十行規模でロックの実害はない。

## Consequences

- 米国業種名は英語（Finnhubの値）のまま表示される。注目テーマ（`watched_themes`）との完全一致判定は日本語の日本株業種名向けのため、米国株には効かない（従来から効いていなかった）。
- 偏り判定は全体比のままなので、米国の特定業種だけで40%を超える場合のみ警告される。
- Finnhub の呼び出しが保有米国株の数だけ増える（既存の `FinnhubClient` のスロットル・リトライに従う）。
- 実装は `/tdd` で進め、Gate 4（テスト承認）を経る。

## 追補（2026-10-03、CHG-0044）: ウォッチリスト銘柄の分類

- 事象: 保有銘柄は分類済みになったが、お気に入り（ウォッチリスト）の未保有銘柄は日本株64・米国株40が未分類のままだった。`FetchExternalMarketDataAction`と`sectors:backfill`は保有（スナップショット）だけを対象にしており、`RefreshWatchlistMarketDataAction`は業種を保存していなかった
- 決定（D6）: ウォッチリストの一括更新（`RefreshWatchlistMarketDataAction`）と`sectors:backfill`の対象にウォッチリスト銘柄を加える。分類は保有と同じ`SectorClassificationResolver`を使う（日本株=J-Quants17業種、米国株=Finnhub業種）。取得失敗・業種不明は未分類のまま既存値を保持し、銘柄の更新は止めない
- 対象外: 米国ETF（HDV・SPYD・VYM等、Finnhubが業種を返さない）の専用カテゴリは別判断とする
- 影響: ウォッチリストの重複率（`overlap_rate`）が市場別のセクター行を参照できるようになる。`sectors:backfill`は対象が増える分、Finnhub・J-Quantsの呼び出しが増える

