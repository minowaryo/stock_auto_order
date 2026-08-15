# project-summary.md — プロジェクト全体要約

> AIが最初に読むファイルです。3〜5分で全体像を把握できるように保ちます。
> 詳細は各 `docs/` ファイルを参照してください。

## プロジェクト概要

| 項目 | 内容 |
|---|---|
| プロジェクト名 | stock_auto_order（株式ポートフォリオ管理システム） |
| 目的 | 楽天証券の保有銘柄CSVを取り込み、テクニカル/ファンダメンタルズ指標を計算して、(1)利益確定タイミングの規律化、(2)セクター偏りのリバランス提案、(3)新規投資候補の重複・分散影響判定を支援する |
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

要件定義フェーズ（Gate 1）。`docs/product/requirements.md` の叩き台をレビュー中。承認後、`docs/product/use-cases.md`（Gate 2）に進む。

## 読む順序（AIへの案内）

1. このファイル（概要把握）
2. `docs/ai-context/module-map.md`（構造把握）
3. タスクに応じた詳細ドキュメント（CLAUDE.md の対応表を参照）
