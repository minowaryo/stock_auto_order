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

### Step 1 — フロントエンド技術選定 → ai-context を埋める（最初に必ずやること）

**1a. フロントエンド技術選定**

- `meta/adr/ADR-0005-frontend-stack.md` の選定基準・比較表を確認し、このプロジェクトのフロントエンドスタックを決定する
- `/adr` コマンドで選定結果を `docs/adr/ADR-XXXX-frontend-stack-selection.md` として記録する（デフォルト推奨〔Vue 3 + Inertia.js + Pinia〕以外を選ぶ場合、または複数候補で迷った場合は理由と却下案を明記する）

**1b. 選定確定後のルールファイル反映**

- `.claude/rules/15-frontend.md` の内容を選定結果に合わせて書き換える（Vue 3 + Inertia.js + Pinia を選定した場合はデフォルト内容のまま利用可）
- `docs/ai-context/module-map.md` の Frontend セクションを実際のディレクトリ構成に書き換える（例示のままにしない）

**1c. ai-context を埋める**

> 作成にあたっては `docs/original-docs/` に一次資料（要件メモ・画面スケッチ等）を置いてから参照すること。
> Step 1 完了後は `docs/original-docs/` をデフォルト参照先としない。
> `project-summary.md` の Frontend 行には 1a の選定結果を転記する。

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

## Read when relevant (task-based)

| Task type | Read this |
|---|---|
| requirements.md / use-cases.md 作成・更新 | `docs/original-docs/`（一次資料参照） + `docs/product/requirements.md` |
| テスト実行・マイグレーション・ビルド等のコマンド操作 | `docs/ai-context/common-commands.md` |
| 要件確認・UC参照 | `docs/product/requirements.md` + `docs/product/use-cases.md` |
| コード実装（機能開発） | `docs/product/use-cases.md` + `docs/architecture/data-model.md` + `docs/product/mockups/` |
| UI実装・モックベースの開発 | `docs/product/ui-guidelines.md` + `docs/product/mockups/` |
| Frontend component changes | `.claude/rules/15-frontend.md`（選定したフロントエンドスタックの実装ルール。`meta/adr/ADR-0005-frontend-stack.md` 参照） |
| Auth / authorization changes | `docs/architecture/authz-authn.md` + `docs/product/org-permission-philosophy.md`（権限・ロールのビジネス方針） |
| DB schema changes | `docs/architecture/data-model.md` + `docs/adr/` |
| Architecture / core design changes | `docs/adr/` |
| Adding / modifying tests | `docs/development/testing-strategy.md` + `docs/product/use-cases.md` + `docs/architecture/data-model.md` |
| Security-related changes | `docs/security/secrets-handling.md` |
| 認証情報・APIキー等の作成 | `docs/credentials/`（`.claude/rules/40-security.md` の取り扱いルールに従う） |
| Release / deployment | `docs/operations/deployment.md` |
| Change request (CR) 発生時 | `docs/rcid/traceability-matrix.md` |
| ユーザー向け機能・操作方法の変更 | `docs/product/user-guide.md` |
| UAT（受け入れテスト）実施時（任意） | `docs/product/uat-scenarios.md` + `docs/product/uat-results/`（`.claude/rules/00-global.md` のUAT節を参照。非ブロッキング） |
| エラー・ライブラリ固有の詰まりに遭遇した時 | `docs/ai-context/known-pitfalls.md`（既知の事象がないか先に確認し、解決したら追記） |

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
- `.claude/rules/15-frontend.md` - フロントエンド固有ルール（内容は `meta/adr/ADR-0005-frontend-stack.md` の選定結果に応じてプロジェクトごとに書き換わる。デフォルト内容は Vue.js + Inertia.js）
- `.claude/rules/20-mysql.md` - MySQL固有ルール
- `.claude/rules/30-testing.md` - テスト方針（Feature/Unit・TDD）
- `.claude/rules/31-e2e-testing.md` - E2Eテスト方針（Playwright。`/generate-e2e-test` 実行時のみ参照）
- `.claude/rules/40-security.md` - セキュリティ
- `.claude/rules/50-review.md` - レビュー観点
- `.claude/rules/60-docs.md` - ドキュメント更新ルール
