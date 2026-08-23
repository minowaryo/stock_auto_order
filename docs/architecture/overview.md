# overview.md — システム全体像

## システム概要

楽天証券のCSVエクスポート（国内株式・米国株式・投資信託）を取り込み、テクニカル/ファンダメンタルズ指標を計算して、保有銘柄の利益確定タイミング判断・セクターリバランス・新規投資候補の検討を支援する、本人1人のみが使う個人利用ツールである。フロントエンドはLivewire（`docs/adr/ADR-0001-frontend-stack-selection.md`）で、チーム開発・外部API公開は想定しない単一Laravelアプリケーションとして構成する。

## アーキテクチャ図

```
[ブラウザ（本人のみ）]
        ↓ HTTP（同一オリジン、Livewireのwire:navigate/AJAX含む）
[Laravel Application（単一プロセス、Docker Sail）]
   ├─ routes/web.php
   │     ├─ ページルート（Livewireフルページコンポーネント、
   │     │   /login, /holdings, /holdings/{holding}, /signals,
   │     │   /sector-dashboard, /candidate-check, /csv-import 等）
   │     └─ /api/* （既存の内部向けJSON API。Livewireページからは
   │         呼ばれず〔下記「フロントエンド構成」参照〕、自動テストの
   │         回帰資産として維持している）
   ├─ app/Http/Controllers（/api/*専用、薄い層）
   ├─ app/Livewire（ページ単位コンポーネント、状態保持・入力受付のみ）
   ├─ app/Actions・app/Services（ビジネスロジック本体）
   └─ app/Models（Eloquent）
        ↓
[MySQL 8.4]

[外部API（J-Quants等）] ← app/Services/MarketData/* が
                            Illuminate\Http\Clientで直接呼び出す
```

Redis・S3・Horizon・CDN・ロードバランサ・ステージング環境は使用しない（個人利用規模のツールであり、キュー/キャッシュ/非同期ジョブ・複数環境運用の必要が無いため）。

## フロントエンド構成（Livewire、`docs/adr/ADR-0001-frontend-stack-selection.md`）

- **Livewireコンポーネントは既存の`app/Actions/**`を直接呼び出す**（HTTPで自身のJSON APIを叩かない）。根拠はADR-0001 Rationale「API層を別途設計する必要がない」、`.claude/rules/15-frontend.md`「ロジックは`app/Services/`や`app/Actions/`に委譲する」。
- **既存JSON API（UC-001〜UC-009のバックエンド実装時にTDDで先行構築したもの）は`/api`配下に維持**している。Livewireページ自身はこれを呼ばないが、既存の自動テスト資産（233件超）をそのまま活かすため削除・置換はしていない。
- ディレクトリ構成は`docs/ai-context/module-map.md`のFrontendセクション参照（`app/Livewire/`・`resources/views/livewire/`・`resources/views/components/`）。
- 認証は最小限の自作Livewireログイン画面（`/login`）+ セッション認証。Breeze/Fortify等のスキャフォールドは導入していない（`app/Livewire/Auth/Login.php`）。

詳細な実装フェーズ計画は `stock_auto_order-frontend-implementation-phase.md`（Planモードで作成）参照。

## 主要コンポーネント

| コンポーネント | 役割 | 技術 |
|---|---|---|
| Web Application | 画面表示・APIサーバー | Laravel 13 + Livewire 4 |
| Database | データ永続化 | MySQL 8.4 |
| 開発環境 | ローカルコンテナ実行 | Docker Sail |

## 外部連携

| 外部サービス | 連携目的 | 方向 |
|---|---|---|
| J-Quants API | 株価履歴・財務諸表・セクター情報の取得（`app/Services/MarketData/JQuantsClient.php`） | 受信 |

## デプロイ構成

本人のローカル環境（Docker Sail）でのみ稼働する。ステージング・本番環境は設けない。

| 環境 | 用途 | URL |
|---|---|---|
| local | 開発・本番利用（唯一の環境） | http://localhost |

## 関連ドキュメント

- 詳細設計: `docs/architecture/data-model.md`
- ADR: `docs/adr/`
- フロントエンド実装計画: `stock_auto_order-frontend-implementation-phase.md`
