# project-summary.md — プロジェクト全体要約

> AIが最初に読むファイルです。3〜5分で全体像を把握できるように保ちます。
> 詳細は各 `docs/` ファイルを参照してください。

## プロジェクト概要

| 項目 | 内容 |
|---|---|
| プロジェクト名 | Philippines Travel Concierge（フィリピン旅行コンシェルジュ／Clark & Angeles Travel Concierge） |
| 目的 | 日本人旅行者向けに、マニラ・クラーク・アンヘレス周辺の旅行前相談、Grab利用支援、旅程提案、アクティビティ情報を日本語で提供するWebサービス |
| 対象ユーザー | メイン: 30代前半〜50代前半の日本人男性（一人旅または2〜4名程度の少人数）。管理者: 事業者本人（Ryo） |
| フェーズ | MVP（Phase 1）。仕様書上のPhase 2（マイページ・決済・チャット・レビュー）／Phase 3（ドライバーポータル・自動マッチング等）は対象外 |

一次資料: `docs/original-docs/philippines_travel_concierge_system_spec_v3.txt`（参照のみ、編集禁止）

## サービスの要点（Phase 1 MVPの境界）

- **提供する**: 旅行前コンサル、Grab利用支援、旅程・アクティビティ情報、Zoom相談申込、事前アンケート、記事コンテンツ、管理画面
- **提供しない（Phase 1）**: ユーザーアカウント／マイページ、決済、滞在中チャット、レビュー機能、ドライバー紹介・予約・自動マッチング
- **恒久的に対象外**（Phase問わず）: 女性紹介、性的サービスの斡旋・価格交渉、違法サービスの予約・手配、無許可・無保険の有償送迎の紹介（詳細は一次資料 1.4 節）
- ドライバー候補情報は Phase 1 では**管理者専用の内部台帳**としてのみ保持可能（ユーザー非公開、予約・マッチング機能なし）

## 技術スタック

| 層 | 技術 |
|---|---|
| Backend | Laravel（[[ADR-0001-use-laravel]]） |
| DB | MySQL（[[ADR-0002-use-mysql]]） |
| Frontend | Vue 3 + Inertia.js + Pinia（[[ADR-0005-frontend-stack]]） |
| Auth | Laravel Sanctum + Policy/Gate（[[ADR-0003-auth-strategy]]、管理者ログインのみ。Phase 1に一般ユーザー認証はない） |
| Queue | Laravel Horizon + Redis（申込完了メール通知等） |
| Storage | S3想定 |

> 一次資料の「18. 技術構成案」は Next.js + PostgreSQL + Prisma を例示しているが、本プロジェクトではADRにより Laravel + MySQL + Vue3/Inertia/Pinia を正式採用している。

## 主要ドメイン（Phase 1 スコープ）

| ドメイン | 説明 | 主なモデル |
|---|---|---|
| content | 記事CMS・アクティビティ情報・モデルプラン紹介 | Article, Activity |
| consultation | Zoom相談申込・事前アンケート | Consultation, TravelProfile |
| trip-planning | 管理者による旅行プラン作成（ユーザーはメール/PDF共有で受領） | Trip, TripDay, TripActivity |
| admin | 管理者向け顧客・相談・売上管理 | User(管理者のみ), Consent |
| driver-candidates（任意・内部限定） | 将来の検証用ドライバー候補台帳 | Driver, Vehicle |

## ディレクトリ構成（概要）

```
app/
  Http/Controllers/   - コントローラ（薄く保つ）
  Services/           - ビジネスロジック
  Actions/            - 単一責務アクションクラス
  Models/             - Eloquent モデル
  Policies/           - 認可ポリシー
docs/                 - 設計ドキュメント全体
.claude/              - Claude Code 用ルール・コマンド
```

詳細は `docs/ai-context/module-map.md` を参照。

## 現在のフォーカス

Gate 0（ai-context整備）完了 → Gate 1（`docs/product/requirements.md`）2026-07-19 承認済み → `docs/product/use-cases.md` の叩き台作成中（Gate 2待ち）。

## 読む順序（AIへの案内）

1. このファイル（概要把握）
2. `docs/ai-context/module-map.md`（構造把握）
3. タスクに応じた詳細ドキュメント（CLAUDE.md の対応表を参照）
