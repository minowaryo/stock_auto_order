# ADR-0006: fundamental_indicators成長率カラムのdecimal桁数拡張

## Status
Accepted

## Date
2026-08-22

## Context

`tests/Feature/FetchExternalMarketDataActionTest.php`のADR-0004回帰テスト（`describe('ADR-0004 再発防止: 実データ由来のバグの回帰テスト')`）を書く過程で、実際のユーザーCSV（`docs/original-docs/assetbalance*.csv`、134銘柄）をUC-001経由でインポートした際に発生した実バグが判明した。

ほぼゼロ近辺のEPSから回復した銘柄（前期EPS 8.8円 → 当期EPS 108.8円）で、`FundamentalIndicatorMapper::calculateGrowth()`が算出したEPS成長率が約1136.36%に達した。しかし`fundamental_indicators.eps_growth`（および同じ成長率系カラムである`revenue_growth`/`operating_income_growth`）は`decimal(7,4)`（最大±999.9999%）で定義されていたため、MySQLのstrict modeが`Out of range value for column 'eps_growth'`エラーを返しINSERTが失敗していた。

## Decision

`fundamental_indicators`テーブルの以下3カラムを`decimal(7,4)`から`decimal(10,4)`（最大±999999.9999%）に拡張する。

- `eps_growth`
- `revenue_growth`
- `operating_income_growth`

既存の`2026_08_16_030020_create_fundamental_indicators_table`（`revenue_growth`/`operating_income_growth`を定義）・`2026_08_22_000001_add_adr0004_columns_to_fundamental_indicators_table`（`eps_growth`を定義）は編集せず、新規マイグレーション`2026_08_22_000004_widen_growth_columns_on_fundamental_indicators_table.php`で`change()`により列幅のみを変更する（`.claude/rules/20-mysql.md`）。

## Rationale

前期比較の基準値（分母）がゼロに近い小型株・回復銘柄では、成長率（%）が数百〜千%超に達しうる。個人投資家の実運用ポートフォリオで実際に999.9999%を超える値が発生したことから、`decimal(10,4)`まで拡張し実用上十分な余裕を持たせる。`revenue_growth`/`operating_income_growth`は本ADR時点では999.9999%超の実例は未確認だが、同じ計算パターン（`(当期−前期)÷前期×100`）で算出される兄弟カラムであり、将来同種の値が発生しうるため、`eps_growth`と同じ幅に揃えて一貫性を保つ。

## Consequences

### メリット
- 実データに基づく成長率が丸め・エラーなく正しく保存されるようになる
- 3カラムの桁数を揃えることで、将来同様の「基準値ゼロ近辺」ケースが再発しにくくなる

### デメリット・リスク
- MySQLの`decimal`列型変更は`.claude/rules/20-mysql.md`が定める「危険な操作（カラム型変更）」に該当し、`ALTER TABLE`実行中は対象テーブルがロックされる。ただし個人利用規模（保有銘柄は最大でも数百件程度）のテーブルサイズのため、ロック時間は実害なし
- 列幅拡大（7,4→10,4）は既存データの精度・値そのものには影響しない。既存の全データは`decimal(7,4)`の範囲（絶対値999.9999未満）に収まっていたため、変更後も値は変わらず後方互換

## Related
- `docs/adr/ADR-0004-analysis-engine-indicator-expansion.md`（PEGレシオ・成長率指標の追加を決定した元ADR）
- `docs/architecture/data-model.md`（`fundamental_indicators`）
- `database/migrations/2026_08_16_030020_create_fundamental_indicators_table.php`
- `database/migrations/2026_08_22_000001_add_adr0004_columns_to_fundamental_indicators_table.php`
- `database/migrations/2026_08_22_000004_widen_growth_columns_on_fundamental_indicators_table.php`
- `app/Actions/Analysis/FetchExternalMarketDataAction.php`（per-holding例外分離の関連修正）
- `tests/Feature/FetchExternalMarketDataActionTest.php`（`describe('ADR-0004 再発防止: 実データ由来のバグの回帰テスト')`）
