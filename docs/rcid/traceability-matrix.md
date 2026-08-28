# traceability-matrix.md — トレーサビリティマトリクス

> 要件ID ↔ ユースケース ↔ コード ↔ テスト の対応関係を管理します。
> 変更管理・影響分析・監査対応に使用します。

## マトリクス

| 要件ID | ユースケース | 実装ファイル | テストファイル | ステータス |
|---|---|---|---|---|
| F-001 | UC-001 | `app/Http/Controllers/CsvImportController.php`, `app/Actions/Import/ImportCsvAction.php` | `tests/Feature/UC001CsvImportTest.php` | 完了 |
| F-002 | UC-002 | `app/Http/Controllers/HoldingListController.php`, `app/Actions/Holding/ListHoldingsAction.php` | `tests/Feature/UC002HoldingListTest.php` | 完了 |
| F-003 | UC-003 | `app/Http/Controllers/HoldingDetailController.php`, `app/Actions/Holding/ShowHoldingDetailAction.php`, `app/Actions/Holding/SaveHoldingMemoAction.php` | `tests/Feature/UC003HoldingDetailTest.php` | 実装中（Green完了・`/review`未実施） |
| F-009 | UC-009 | `app/Http/Controllers/ImportSummaryReportController.php`, `app/Actions/ImportSummaryReport/ShowImportSummaryReportAction.php` | `tests/Feature/UC009ImportSummaryReportTest.php` | 完了 |
| F-004 | UC-004 | `app/Http/Controllers/SignalListController.php`, `app/Actions/Signal/ShowSignalListAction.php` | `tests/Feature/UC004SignalListTest.php` | 完了（NISA区分除外はスコープ外、`holding_snapshot_accounts`実装後に別対応） |
| F-010 | UC-010 | `app/Http/Controllers/BuySignalListController.php`, `app/Actions/Signal/ShowBuySignalListAction.php`, `app/Services/Analysis/BuySignalDeterminationService.php`, `app/Services/Analysis/FundamentalHealthEvaluator.php`, `app/Livewire/Signal/SignalList.php` | `tests/Feature/UC010BuySignalListTest.php`, `tests/Unit/Services/Analysis/BuySignalDeterminationServiceTest.php`, `tests/Unit/Services/Analysis/FundamentalHealthEvaluatorTest.php`, `tests/Feature/SignalListTest.php` | 完了（バックエンド・フロントエンドとも。実データE2E確認済み） |

## 変更追跡

| 変更ID (RCID) | 変更内容 | 影響要件 | 変更日 | 承認者 |
|---|---|---|---|---|
| CHG-001 | [変更概要] | F-001 | YYYY-MM-DD | [名前] |
| CHG-0001 | 口座区分（特に NISA成長投資枠/NISAつみたて投資枠）の内訳をCSV取込時に`holding_snapshot_accounts`へ保存し、利確シグナル・リバランス提案からNISA区分を除外、新規投資候補でNISA枠購入を推奨するロジックを追加（ADR-0002）。Gate 3承認済みだった「口座区分を保持しない」方針を覆すCR | F-001, F-004, F-005, F-008 | 2026-08-16 | minowaryo（2026-08-19） |
| CHG-0002 | F-009の合成スコアリング方針を部分的に緩和（ADR-0003）。従来「算出過程・重み付けはユーザーに開示しないブラックボックスとしてよい」としていた例外規定を改め、詳細な計算式・重み付けは非開示のままとしつつ`portfolio_headline`・`reason_summary`に判定の主要因となった代表指標を含めることを必須化。この考え方をF-009固有の例外ではなくプロジェクト全体の設計方針（requirements.md 6章）として一般化。Gate 1（requirements.md）/Gate 2（use-cases.md）/Gate 3（data-model.md）で承認済みだった内容を覆すCR。UC-009はPhase 1未着手のため既存実装への影響なし | F-009 | 2026-08-21 | minowaryo（2026-08-21） |
| CHG-0003 | 分析エンジンの指標セットを拡張（ADR-0004）。テクニカル指標に出来高・52週高値/安値・相対力（対市場・対セクター）、ファンダメンタルズ指標にEPS成長率・PEGレシオを追加。UC-001に外部データ取得フロー（J-Quants/Yahoo Finance相当をLaravel HTTPクライアントで同期取得）を追加、UC-004のシグナル種別を4種追加（`week52_high_pullback`/`peg_overvalued`/`relative_strength_weakening`/`volume_spike_decline`）、UC-007の市場指標取得・保存ロジック（nikkei225/sp500分のみ）をF-009と同じパターンでPhase1に先行実装。Gate 2（use-cases.md）/Gate 3（data-model.md）で承認済みだった内容を覆すCR。**UC-003・UC-009は既にGreen完了・マージ済みのため既存実装への影響あり**（`ShowHoldingDetailAction`/`UC003HoldingDetailTest.php`、`ShowImportSummaryReportAction`/`UC009ImportSummaryReportTest.php`への追加改修が必要。CHG-0001と同様、既にGreenだったUCへの追加CRの扱い。UC-009への影響は本CR起票と並行して別セッションでUC-009 Greenフェーズが完了していたことが後日判明し追記）。新カラムはいずれも追加のnullable列で、UC-003/UC-009の現行実装は`signal_type`・`market_indicator_snapshots`を参照していないため、コードレベルの後方互換性は壊れていない。`signals.signal_type`のENUM拡張は`.claude/rules/20-mysql.md`の「危険な操作」に該当（ADR-0004に理由記録） | F-003, F-004, F-007, F-009 | 2026-08-21 | minowaryo（2026-08-21） |
| CHG-0004 | 既存保有株の買い増し（押し目）タイミングレコメンド機能を新規追加（ADR-0007、F-010・UC-010新設）。Gate 1（requirements.md 2章OUTスコープ「含み益が薄い/マイナスの既存保有銘柄に対する売買判断支援」）で承認済みだった決定を覆すCR。Gate 2（use-cases.md）・Gate 3（data-model.md）も再承認が必要。押し目買いシグナル7種（`rsi_oversold_rebound`等）の判定・保存先として`buy_signals`新テーブルを設け、既存の`signals`（利確シグナル）とは意図的に分離した。**この分離により、既にGreen・マージ済みのUC-004（`ShowSignalListAction`）・UC-009（`ShowImportSummaryReportAction`）への改修は発生しない**（CHG-0001/CHG-0003が既存実装への追加改修とGate 4再承認を要したのと対照的）。UC-001に買いシグナル判定・保存フロー（フロー9）を追加。ナビゲーション上はUC-004と同一画面（「売買シグナル」タブ）に統合し、`ui-guidelines.md`のタブ数上限方針を維持 | F-010, F-001, F-004 | 2026-08-23 | minowaryo（2026-08-23、前提条件2点追加の上で承認） |
| CHG-0005 | UC-010実装完了後の`/review`で、ファンダメンタルズ健全性フィルタにUC-010のみ成長率条件（売上高成長率または営業利益成長率のいずれかプラス）を追加した結果、UC-008/UC-009（自己資本比率・ROEの2条件のみ）と判定結果が乖離し得ることが判明。UC-008の業務ルールを改訂し、UC-005/UC-008/UC-009/UC-010の4UC全てで自己資本比率・ROE・成長率の3条件に統一する。`FundamentalHealthEvaluator`を`NewCandidateFinder`（UC-008、UC-005が直接利用）・`ShowImportSummaryReportAction`（UC-009）から共通利用する形に実装を統合し、閾値の二重管理も解消する。UC-005/UC-008/UC-009は既にGreen完了のため、既存実装への追加のTDDサイクル（Red→Green、Gate4再承認）が必要 | F-005, F-008, F-009, F-010 | 2026-08-27 | minowaryo（2026-08-27） |

## RCID命名規則

```
CHG-[連番4桁]
例: CHG-0001, CHG-0042
```

## ステータス定義

| ステータス | 意味 |
|---|---|
| 未着手 | use-casesに定義済みだが実装していない |
| 実装中 | 現在開発中 |
| 完了 | 実装・テスト・レビュー完了 |
| 保留 | 優先度変更等で一時停止 |
| 廃止 | 要件削除・変更により不要になった |

## 使い方

1. 新機能追加時: 要件IDとUCIDを先に決めてからコード生成を依頼する
2. バグ修正時: 関連する要件IDを特定してトレーサビリティを維持する
3. 変更管理: RCID を発行してコードと要件の変更を紐づける
