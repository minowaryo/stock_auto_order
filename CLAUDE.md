# CLAUDE.md

## Project

- **Project name**: [PROJECT_NAME]
- **Stack**: [例: Laravel + MySQL]
- **Type**: [例: Monolith web application / API / etc.]
- **Main domains**: [例: 在庫管理 / 受注処理 / etc.]
- **Repository**: [GitLab/GitHub URL]

---

## ⚠️ プロジェクト開始前の必須手順（Gate 0）

このリポジトリをクローンしたら、コードに触れる前に以下を順番に埋めること。
AIはこれらのファイルが埋まっていない状態では正確な支援ができない。

### Step 1 — ai-context を埋める（最初に必ずやること）

> 作成にあたっては `docs/original-docs/` に一次資料（要件メモ・画面スケッチ等）を置いてから参照すること。
> Step 1 完了後は `docs/original-docs/` をデフォルト参照先としない。

| ファイル | 内容 | 優先度 |
|---|---|---|
| `docs/ai-context/project-summary.md` | プロジェクト全体の概要・目的・技術スタック | 必須 |
| `docs/ai-context/glossary.md` | プロジェクト固有の用語・略語 | 必須 |
| `docs/ai-context/module-map.md` | ディレクトリ構成と各モジュールの責務 | 必須 |
| `docs/ai-context/do-not-touch.md` | AIが変更してはいけない領域・ファイル | 必須 |
| `docs/ai-context/common-commands.md` | よく使うコマンド（migrate / test / lint 等） | 推奨 |
| `docs/ai-context/prompt-patterns.md` | 定型プロンプト集 | 任意 |

### Step 2 — 要件定義ドキュメントを作成する

```
docs/product/requirements.md        ← ビジネスチーム・BAが作成
    ↓ Gate 1: レビュアー承認
docs/product/use-cases.md           ← ビジネスチーム・BAが作成（AIによる叩き台生成可）
    ↓
docs/product/mockups/               ← AIによる叩き台生成可（/generate-mock コマンド利用）
    ビジネス側レビュー → フィードバックを use-cases.md に反映
    ↓ Gate 2: レビュアー最終承認 ★ここを通過するまでコード生成禁止
docs/product/acceptance-criteria.md ← AIによる叩き台生成可
```

> **モック作成タイミングの原則**: モックはGate 1通過後〜Gate 2の間に作成する。
> ビジネス側との要件認識合わせが目的であり、Gate 3（データモデル承認）を待つ必要はない。
> モックフィードバックをuse-cases.mdに反映してからGate 2承認を行う。

### Step 3 — アーキテクチャ設計

```
docs/architecture/data-model.md  ← 開発者が作成（AIによる叩き台生成可）
docs/architecture/overview.md    ← 開発者が作成
docs/adr/ADR-xxxx-[title].md     ← 技術選定の都度作成
    ↓ Gate 3: レビュアー承認
```

### Step 4 — コード生成・実装（Gate 2・3 通過後のみ）

実装は `/tdd` コマンドで **TDD（Red → Green → Refactor）** で進める。

```
Red → [Gate 4: テストケース承認 ★実装(Green)着手禁止] → Green → Refactor → /review
```

> Gate 4 は Gate 0〜3（プロジェクトで1度だけ通過）と異なり、機能・UC単位でTDDサイクルのたびに繰り返す。
> フェーズごとの手順・サブエージェント構成・スキル実行タイミングは `.claude/rules/30-testing.md` を参照。

---

## Read first (every session)

- `docs/ai-context/project-summary.md`
- `docs/ai-context/glossary.md`
- `docs/ai-context/module-map.md`
- `docs/ai-context/common-commands.md`

## Read when relevant (task-based)

| Task type | Read this |
|---|---|
| requirements.md / use-cases.md 作成・更新 | `docs/original-docs/`（一次資料参照） + `docs/product/requirements.md` |
| 要件確認・UC参照 | `docs/product/requirements.md` + `docs/product/use-cases.md` |
| コード実装（機能開発） | `docs/product/use-cases.md` + `docs/architecture/data-model.md` + `docs/product/mockups/` |
| UI実装・モックベースの開発 | `docs/product/ui-guidelines.md` + `docs/product/mockups/` |
| Frontend / Vue component changes | `.claude/rules/15-vue.md` |
| Auth / authorization changes | `docs/architecture/authz-authn.md` |
| DB schema changes | `docs/architecture/data-model.md` + `docs/adr/` |
| Architecture / core design changes | `docs/adr/` |
| Adding / modifying tests | `docs/development/testing-strategy.md` + `docs/product/use-cases.md` + `docs/architecture/data-model.md` |
| Security-related changes | `docs/security/secrets-handling.md` |
| Release / deployment | `docs/operations/deployment.md` |
| Change request (CR) 発生時 | `docs/rcid/traceability-matrix.md` |

## Global rules

- **Workflow**: Explore → Plan → Implement → Test の順で進める
- セッション開始時は必ず `docs/ai-context/` を読む
- **Gate 2（use-cases.md 承認）が完了するまでコード生成を行わない**
- **Gate 4（テストケース承認）が完了するまで実装（Greenフェーズ）に着手しない**（`.claude/rules/30-testing.md`）
- `docs/original-docs/` は参照のみ（編集・削除・ファイル作成禁止）
- 先にドキュメントを確認してからコードに触る
- 大規模変更の前は必ず `docs/adr/` を確認する
- Authorization は Policy / Gate を必ず通す（バイパス禁止）
- DBスキーマ変更はマイグレーション計画なしに行わない
- 小さい差分を優先する（大きな1コミットより小さな複数コミット）
- 設計意図が変わるときはドキュメントも更新する

## Detailed rules

詳細ルールは `.claude/rules/` を参照:

- `.claude/rules/00-global.md` - 全体方針・開発フロー・品質ゲート
- `.claude/rules/10-laravel.md` - Laravel固有ルール
- `.claude/rules/15-vue.md` - Vue.js + Inertia.js 固有ルール
- `.claude/rules/20-mysql.md` - MySQL固有ルール
- `.claude/rules/30-testing.md` - テスト方針
- `.claude/rules/40-security.md` - セキュリティ
- `.claude/rules/50-review.md` - レビュー観点
- `.claude/rules/60-docs.md` - ドキュメント更新ルール
