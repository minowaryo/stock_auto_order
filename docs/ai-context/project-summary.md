# project-summary.md — プロジェクト全体要約

> AIが最初に読むファイルです。3〜5分で全体像を把握できるように保ちます。
> 詳細は各 `docs/` ファイルを参照してください。

## プロジェクト概要

| 項目 | 内容 |
|---|---|
| プロジェクト名 | [PROJECT_NAME] |
| 目的 | [何のために作るか] |
| 対象ユーザー | [誰が使うか] |
| フェーズ | [MVP / Beta / Production] |

## 技術スタック

| 層 | 技術 |
|---|---|
| Backend | Laravel [バージョン] |
| DB | MySQL [バージョン] |
| Frontend | [例: Blade / Vue / React] |
| Auth | [例: Laravel Sanctum] |
| Queue | [例: Laravel Horizon + Redis] |
| Storage | [例: S3] |

## 主要ドメイン

| ドメイン | 説明 | 主なモデル |
|---|---|---|
| users | ユーザー管理・認証 | User, Role |
| [domain2] | [説明] | [Model] |
| [domain3] | [説明] | [Model] |

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

[現在スプリントで取り組んでいる機能・修正を記載]

## 読む順序（AIへの案内）

1. このファイル（概要把握）
2. `docs/ai-context/module-map.md`（構造把握）
3. タスクに応じた詳細ドキュメント（CLAUDE.md の対応表を参照）
