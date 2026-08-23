# ADR-0008: financial_statements.revenue/operating_incomeのnullable化

## Status
Accepted

## Date
2026-08-23

## Context

UC-006 Cycle A（`financial_statements`書き込み経路、`docs/architecture/data-model.md` "financial_statements"）の`/review`拡張レベル実施時（コミット`2712167`）に、MEDIUM指摘として以下が判明した。

`JQuantsClient::fetchStatements()`が返す`net_sales`/`operating_profit`は`toFloatOrNull()`経由で`float|null`として型付けされており（`app/Services/MarketData/JQuantsClient.php`）、`FundamentalIndicatorMapper`側はこのnullを`calculateGrowth()`・`calculatePer()`等で一貫して考慮済みである。しかし新設した`financial_statements.revenue`/`operating_income`カラムはNOT NULLのまま定義されており（`2026_08_23_000000_create_financial_statements_table.php`）、`FetchExternalMarketDataAction`側もこの値を素通しで書き込んでいた。

この書き込みは既存の`DB::transaction()`（銘柄単位）配下・さらに外側の`try-catch`配下にあるため、J-Quantsが決算様式の違い等でいずれかの期のSales/OPを欠損（null）で返す銘柄が実在した場合、`financial_statements`のINSERTでNOT NULL制約違反の`QueryException`が発生し、`financial_statements`だけでなく同一銘柄の`technical_indicators`/`fundamental_indicators`/`signals`更新まで巻き添えでロールバックされ、`Log::warning`にしか記録が残らない（既存の`ADR-0004`回帰テストで確立された「per-holding失敗の分離」の意図に反する新たな失敗経路）。回帰テスト（`tests/Feature/FetchExternalMarketDataActionTest.php` "financial_statements.revenue/operating_incomeのNOT NULL制約とJ-Quantsのnull値の衝突"）で再現・確認済み。

## Decision

`financial_statements`テーブルの以下2カラムを`NOT NULL`から`nullable`に変更する。

- `revenue`
- `operating_income`

既存の`2026_08_23_000000_create_financial_statements_table`は編集せず、新規マイグレーション`2026_08_23_000001_nullable_revenue_operating_income_on_financial_statements_table.php`で`change()`によりnullable制約のみを変更する（`.claude/rules/20-mysql.md`）。

## Rationale

`revenue`/`operating_income`の実データソースである`net_sales`/`operating_profit`は、そもそも取得元API（J-Quants）の契約上nullを許容する値であり、同じ値を扱う`fundamental_indicators`側（`revenue_growth`/`operating_income_growth`等）も既にnullableで定義されている。`financial_statements`だけNOT NULLにする理由はなく、データソースの実際の型に合わせるのが妥当。`eps`カラムは元々nullableで定義済みであり、本ADRにより3カラムの扱いが揃う。

## Consequences

### メリット
- J-Quantsが一部期のSales/OPを欠損で返す銘柄でも、`financial_statements`の他の期・他カラムおよび同一銘柄の`technical_indicators`/`fundamental_indicators`/`signals`更新が巻き添えでロールバックされなくなる
- `financial_statements`のnull許容方針が、同じデータソースを扱う`fundamental_indicators`と一貫する

### デメリット・リスク
- MySQLの列制約変更は`.claude/rules/20-mysql.md`が定める「危険な操作（カラム型変更）」に該当し、`ALTER TABLE`実行中は対象テーブルがロックされる。ただし本テーブルは当日新設されたばかりで実データ行がまだ存在しないため、ロック時間・既存データへの影響は実質ゼロ
- `revenue`/`operating_income`がnullになりうることを、今後この値を参照する側（UC-006本体の画面表示等）が考慮する必要がある

## Related
- `docs/adr/ADR-0004-analysis-engine-indicator-expansion.md`（fundamentals取得・`FetchExternalMarketDataAction`の元ADR）
- `docs/adr/ADR-0006-widen-fundamental-growth-columns.md`（同種の「実データがスキーマ制約と衝突した」パターンの先行事例）
- `docs/architecture/data-model.md`（`financial_statements`）
- `database/migrations/2026_08_23_000000_create_financial_statements_table.php`
- `database/migrations/2026_08_23_000001_nullable_revenue_operating_income_on_financial_statements_table.php`
- `app/Actions/Analysis/FetchExternalMarketDataAction.php`
- `tests/Feature/FetchExternalMarketDataActionTest.php`（`describe('financial_statements.revenue/operating_incomeのNOT NULL制約とJ-Quantsのnull値の衝突')`）
