# project-summary.md — プロジェクト全体要約

> AIが最初に読むファイルです。3〜5分で全体像を把握できるように保ちます。
> 詳細は各 `docs/` ファイルを参照してください。

## プロジェクト概要

| 項目 | 内容 |
|---|---|
| プロジェクト名 | stock_auto_order（株式ポートフォリオ管理システム） |
| 目的 | 楽天証券の保有銘柄CSVを取り込み、テクニカル/ファンダメンタルズ指標を計算して、(1)利益確定タイミングの規律化、(2)セクター偏りのリバランス提案、(3)新規投資候補の重複・分散影響判定を支援する。基本戦略（積立・長期保有）の上に、ガチホ継続との差分で年率+5%程度（目標。下限ではない）の追加収益獲得を狙う中長期売買判断支援として位置づける（詳細は`BACKGROUND.md`参照） |
| 対象ユーザー | 本人1人（個人利用。中長期〔3ヶ月〜1年程度〕の株式投資を行う個人投資家） |
| フェーズ | MVP（要件定義フェーズ） |

## 技術スタック

| 層 | 技術 |
|---|---|
| Backend | Laravel（`meta/adr/ADR-0001-use-laravel.md`） |
| DB | MySQL（`meta/adr/ADR-0002-use-mysql.md`） |
| Frontend | Livewire（`docs/adr/ADR-0001-frontend-stack-selection.md`。個人利用ツールのためJS専任構成を避け、サーバー駆動のリアクティブUIを採用） |
| Auth | Laravel Sanctum（`meta/adr/ADR-0003-auth-strategy.md`。ただし利用者は本人1人のため認可要件は最小限） |
| Queue | 未定（CSV取込・外部API連携が重くなる場合に検討） |
| Storage | ローカルディスク（アップロードCSVの一時保存。個人利用のためS3等は不要） |

## 主要ドメイン

| ドメイン | 説明 | 主なモデル |
|---|---|---|
| holdings（保有銘柄） | 楽天証券CSVから取り込んだ保有銘柄・数量・取得価格 | Holding, HoldingSnapshot |
| import（CSV取込） | 楽天証券CSV（JP株/US株/投資信託）のアップロード・パース | ImportBatch |
| analysis（分析） | テクニカル指標（RSI/MACD/BB/移動平均）・ファンダメンタルズ指標（PER/PBR/ROE等）の計算とシグナル判定 | TechnicalIndicator, FundamentalIndicator, Signal |
| sector（セクター分類・リバランス） | J-Quants等から取得した業種分類とセクター配分の集計・偏り検出 | SectorClassification |
| memo（メモ） | 銘柄ごとの自由記述メモ（LLMとの壁打ち内容の手動転記用） | HoldingMemo |
| market_overview（市場全体指標） | 日経平均・S&P500・米国10年債利回り・VIX指数・USD/JPY為替レートの取得・週次記録・参考表示（個別銘柄シグナルへの自動反映はしない） | MarketIndicatorSnapshot |
| watchlist（注目テーマ・新規投資候補レコメンド） | 本人が手動登録した「注目テーマ・セクター」への合致判定・財務健全性フィルタによる新規投資候補の軽量レコメンド（テーマ自体の自動発見はしない） | WatchedTheme, RecommendationCandidate |
| research（市場からの調査候補） | 公開資料から本人が手動で記録した未登録企業を、元発表・上場主体・根拠とともに確認してからウォッチリストへ渡す（UC-016、CHG-0031）。起動時の公開note RSS確認はADR-0031で追加、ほかの源の取得経路と品質は別途評価 | ResearchCandidate, ResearchEvent, ResearchEntity, ResearchSourceCheck, ResearchSourceItem |

## ディレクトリ構成（概要）

```
app/
  Livewire/            - Livewireコンポーネント（画面）
  Services/Import/     - CSVパーサ
  Services/Analysis/   - テクニカル/ファンダメンタルズ指標計算
  Services/MarketData/ - J-Quants/Yahoo Finance相当の外部データ取得クライアント
  Actions/             - 単一責務アクションクラス
  Models/               - Eloquent モデル
  Policies/             - 認可ポリシー
resources/views/livewire/ - Livewireコンポーネントに対応するBladeビュー
docs/                 - 設計ドキュメント全体
.claude/              - Claude Code 用ルール・コマンド
```

詳細は `docs/ai-context/module-map.md` を参照。

## 現在のフォーカス

CHG-0031（UC-016）の手動記録/本人確認/UC-012受け渡し、ADR-0031の起動時確認はmain反映済み。FDAもmain統合済み。NEDO一覧取得は専用`feat/chg0031-nedo-source`でGreen検証済み（main/実アプリ未反映）。2026-10-19、企業下書き自動生成・NEDO結果HTML限定取得・新3画面を追加Gate 2本人承認。[ADR-0033](../adr/ADR-0033-research-candidate-automation.md)と[追加保存定義](../architecture/research-automation-data-model.md)は同日追加Gate 3本人承認済み。FDA追加79件は同日Gate 4本人承認、初期Green（関連301件/E2E1件成功）。[検証記録](../development/chg0031-automation-fda-green-review.md)。JPX正式5桁の補足と追加3件も同日別途承認、原コード保持/自動照合4桁限定へ修正し実JPX4,447行解析成功。関連304件全成功、専用ブランチのみ（main未反映）。企業集約/追加絞り込み等は後続。情報源品質の4週試行（10-05〜11-01）と11-02以降の再精査、YouTube取得条件はPLANで継続管理。

## 読む順序（AIへの案内）

1. このファイル（概要把握）
2. `docs/ai-context/module-map.md`（構造把握）
3. タスクに応じた詳細ドキュメント（CLAUDE.md の対応表を参照）
