# ADR-0012: 成長率（売上高・営業利益・EPS）を「本決算どうしの前期比」で算出する

## Status
Accepted

## Date
2026-09-06

## Context

`FundamentalIndicatorMapper::calculateGrowth()`（および `FetchExternalMarketDataAction::calculateStatementGrowth()` の同一ロジックの private 複製）は、
`revenue_growth` / `operating_income_growth` / `eps_growth` を **「`fetchStatements()` が返す配列の index 0 と index 4 の比較」** で算出していた。
data-model.md 上の定義は「前年同期比」であり、実装は「四半期開示が年4回・重複なし」であれば index 4 ＝ 前年同期になる、という暗黙の前提に立っていた。

実データ（J-Quants `/v2/fins/summary`）を確認したところ、この前提が崩れていた:

1. **同一決算の重複開示**: 例）伊藤忠（8001）は 3Q 決算が `DiscDate=2026-02-06` と `2026-02-13` の2行で返る（`Sales`/`OP`/`EPS` 等すべて同値）。重複1件ぶん index がずれる。
2. **累計期の種類が混在**: `/fins/summary` は 1Q / 2Q / 3Q / FY（本決算）を時系列で返す。最新行が FY（通期累計）でも、index 4 は 1Q（四半期単独〜累計）になり得る。

結果、伊藤忠では `revenue_growth` として **「通期売上 14.8兆 ÷ 1Q売上 3.56兆 → +316%」** という無意味な値が算出され、
`financial_statements.revenue_yoy_change = 316.5037` として永続化されていた。
この値は下流の `FundamentalHealthEvaluator`（passed/failed 判定）→ `TakeProfitThresholdEvaluator`（+150%ライン）→
UC-005 / 008 / 009 / 010 / 011 の候補抽出・整理検討画面・判定チェックリスト「成長率」行に波及する。

`/fins/summary` の生レスポンスには使える識別子がある:

- `CurPerType`: `1Q` / `2Q` / `3Q` / `FY`
- `CurFYEn`: 当該会計年度の期末日（例 `2026-03-31`）
- `CurPerEn`: 当該開示対象期間の期末日

`fetchStatements()` はこれらを読み取らずに捨てていた。

## Decision

### D1. 成長率は「最新の本決算（FY）」と「その前期の本決算（FY）」の比較で算出する

`calculateGrowth()` を次のロジックに変更する:

1. `fetchStatements()` の結果から `period_type === 'FY'` の行だけを抽出する
2. `fiscal_year_end` 単位で重複排除する（`DiscDate` 降順で最初に現れた行＝最も新しく開示された行を採用）
3. 残った FY 行を新しい順に並べ、先頭（最新FY）と2番目（前期FY）を比較する
4. FY 行が2期ぶんそろわない場合、または比較対象の値が null / 前期値が 0 の場合は `null`

四半期どうしの前年同期比（例: 最新2Q vs 前年2Q）ではなく **通期どうしの比較** に倒す。理由:

- 個人が中長期目線で「この会社は伸びているか」を見るのが目的（ADR-0011 と同じ動機）であり、通期実績どうしの比較のほうがノイズが少ない
- `CurPerType` が一致する行を年をまたいで突き合わせる実装より単純で、重複開示・予想修正の別行混入に強い
- トレードオフ: 直近が四半期開示のみの時期は、通期の数字が最大1年弱古くなる。四半期ベースの鮮度は捨てる

### D2. `JQuantsClient::fetchStatements()` に `period_type` / `fiscal_year_end` を追加し、取得件数を拡大する

- 返却する各行に `period_type`（`CurPerType`）と `fiscal_year_end`（`CurFYEn`）を追加する
- 取得件数の既定を 5 → 16 に拡大する（重複開示・四半期行をはさんでも FY を2期ぶん window 内に収めるため。`/fins/summary` は概ね2年ぶん≒8〜12行しか返さないため実質は全件）
- `map()` の点情報系（`per` / `pbr` / `roe` / `operating_margin` / `dividend_*`）は従来どおり最新開示（index 0）を使う。変更なし
- `financial_statements`（UC-006 履歴）には拡大後の件数ぶん保存する（履歴が増えるだけで判定への影響はなし）

### D3. `FetchExternalMarketDataAction::calculateStatementGrowth()` の複製を廃し、Mapper のロジックを共有する

`FundamentalIndicatorMapper` に `public function annualGrowth(array $statements, string $field): ?float` を公開し、
`map()` と `FetchExternalMarketDataAction`（`financial_statements.revenue_yoy_change` / `operating_income_yoy_change` の算出）の双方から呼ぶ。閾値・ロジックの二重定義を解消する（CHG-0005 で起きた定数ドリフトと同種の予防）。

### D4. 併せて `BuySignalDeterminationService::determinePegUndervalued()` に PEG 下限を入れる（バグB）

`if ($pegRatio <= 1.0)` に下限がなく、**マイナス PEG（＝減益・赤字成長）を「割安」と誤判定**して `peg_undervalued` を出していた。
JP 株は Mapper が `eps_growth <= 0` で `peg_ratio` を null 化するため無害だが、**US 株は Finnhub `pegTTM` の負値をそのまま採用**するため実害がある（DB 実データに WIT -34.7 / RGTI -0.98 等）。

- `BuySignalDeterminationService::determinePegUndervalued()`: `0 < $pegRatio && $pegRatio <= self::PEG_UNDERVALUED_THRESHOLD`
- `SignalCriteriaEvaluator` の買いチェックリスト PEG 行: 0以下の PEG は `met` にしない（`unmet` として実測値は表示する）
- 利確側（`determinePegOvervalued()` / `>= 2.0`）は負値が条件に一致しないため変更不要

## Rationale

- **却下案: `CurPerType` を一致させて年跨ぎで突合（四半期ベース前年同期比）** — data-model.md の文言には忠実だが、重複開示・予想修正行・決算期変更で崩れやすく、実装も複雑。個人の中長期評価という目的に対して四半期の鮮度は過剰
- **却下案: 重複排除だけ入れて index 4 比較は維持** — 8001 の重複ケースは直るが、決算期変更や予想修正が別行で入ると再発する。位置ベースの前提自体を捨てるべき
- **却下案: `fetchStatements` を FY 限定にする** — `per` 等が最新四半期の株価に追随できなくなる（PER の分母が古い通期 EPS になる）。点情報系と成長率で必要な期が違う

## Consequences

- `revenue_growth` / `operating_income_growth` / `eps_growth` / `peg_ratio` / `financial_statements.*_yoy_change` の意味が「前年同期比（四半期）」から **「前期本決算比（通期）」** に変わる。data-model.md の該当カラム説明を更新する
- 直近が四半期開示のみの銘柄は、次の本決算が出るまで成長率が最大1年弱古い通期実績のままになる（許容）
- `financial_statements` の保存件数が 5 → 最大16 に増える（UC-006 の履歴表示が長くなるだけ）
- 閾値（成長率 > 0）は変更しない。値のキャリブレーションは `accuracy-improvement-backlog.md` の扱い
- US 株の成長率（`revenueGrowthTTMYoy` 等 Finnhub 由来）はこの変更の対象外（`UsFundamentalIndicatorMapper` は無改修）。同一カラムに JP=通期比 / US=TTM YoY が混在する点は `peg_ratio` と同じ構図として data-model.md に注記する
