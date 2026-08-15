# PLAN.md

## MVP〜段階拡充のフェーズ計画策定（2026-08-15）

### Decision

- モックレビュー（`docs/product/mockups/`）を進める中で機能追加の議論が広がったため、`docs/product/requirements.md` 4章の優先度（高/中/低）をもとに、実装順を明示的にPhase 1（MVP）/Phase 2に分割し、requirements.md 7章「フェーズ計画」として文書化した。
  - Phase 1（MVP）: F-001（CSV取込）/ F-002（保有銘柄一覧表示）/ F-003（銘柄詳細表示）/ F-004（利確シグナル一覧） — 優先度「高」のみ。「取り込む→見る→利確判断する」の中核ループ
  - Phase 2: F-005（セクター配分ダッシュボード）/ F-006（新規投資候補の重複チェック）/ F-007（市場全体指標表示）/ F-008（新規投資候補レコメンド・軽量版）
- 分析の過程で、優先度「低」のF-008が、優先度「中」のF-005（リバランス候補抽出ロジックの流用元）・F-006（画面統合先）双方の依存元になっていることが`docs/product/use-cases.md`（UC-005/UC-006）から判明した。本人に確認のうえ、F-008をPhase 2に繰り上げる方針を決定した（use-cases.mdの再設計は不要と判断）。
- バックテスト機能・システム内蔵LLMチャット（既にrequirements.md 2章OUTスコープに記載）は、今回のフェーズ計画には含めず、未計画・スコープ外のまま据え置いた。

### Files touched

`docs/product/requirements.md`（4章にフェーズ列を追加、7章「フェーズ計画」を新設）

### Status

完了。次のアクションはPhase 1対象UC（UC-001〜UC-004）からGate4（TDD Redフェーズ）サイクルを開始すること。

## 楽天証券CSVのデータモデル方針決定・Gate2以降フォローアップ計画（2026-08-15）

### Decision

- `docs/original-docs/` の楽天証券CSV実データ（JP株/US株/投資信託）を分析し、以下を決定して `docs/product/use-cases.md`（UC-001/UC-002/UC-004）に反映した:
  - 複数口座区分（特定口座/一般口座/NISA枠）にまたがる同一銘柄は銘柄コード単位で合算表示（数量合算・取得単価は加重平均）
  - テクニカル/ファンダメンタルズ指標・利確シグナルはJP株・US株の個別株のみを対象とし、ETF・投資信託は一覧表示のみで指標対象外
  - US株の円換算はCSV取込時にCSVヘッダー記載の参考為替レートで行う
- `docs/product/use-cases.md` は現時点でGate 2（最終承認）未通過のため、`docs/architecture/data-model.md` の正式作成・マイグレーション・モデル実装等はGate制約により今回実施していない。フォローアップ項目一覧を `C:\Users\minow\.claude\plans\stock_auto_order-requirements-phase.md` に記録した。

### Files touched

`docs/product/use-cases.md`（UC-001/UC-002/UC-004の業務ルール・出力項目を追記）

### Status

進行中。Gate 2承認後、`stock_auto_order-requirements-phase.md` のフォローアップ項目（data-model.md正式ドラフト作成等）に着手する。

## Separate template/harness ADRs from project ADRs (2026-08-15)

### Decision

- `docs/adr/` is reserved exclusively for the ADRs of the project built from this template. It now starts empty; the first project ADR should be `ADR-0001`.
- The 9 ADRs that document this template/harness's own design (ADR-0001 through ADR-0009) were moved to `meta/adr/`, a new top-level directory outside `docs/`. This keeps them out of any future "reset project docs" sweep of `docs/`, and out of the project's own ADR numbering sequence.
- All cross-references to these 9 files (in `CLAUDE.md`, `AGENTS.md`, `.claude/rules/`, `docs/ai-context/`, `docs/architecture/`, `docs/development/`) were repointed to `meta/adr/`. References to `docs/adr/` that describe creating a *new* project ADR (e.g. `/adr` command, `CLAUDE.md` Step 1a/3, Gate rules) were left unchanged.
- Added `docs/adr/README.md` and `meta/adr/README.md` explaining the split so it isn't rediscovered by accident later.

### Files touched

`meta/adr/ADR-0001` through `ADR-0009` (moved from `docs/adr/`), `docs/adr/README.md` (new), `meta/adr/README.md` (new), `README.md`, `CLAUDE.md`, `AGENTS.md`, `.claude/rules/00-global.md`, `.claude/rules/15-frontend.md`, `.claude/rules/30-testing.md`, `.claude/rules/31-e2e-testing.md`, `.claude/rules/50-review.md`, `docs/ai-context/common-commands.md`, `docs/ai-context/module-map.md`, `docs/development/ai-workflow.md`, `docs/architecture/authz-authn.md`.

### Status

Completed. No open follow-ups.

## Frontend stack selection process built into Gate 0 (2026-08-03)

### Decision

- `docs/adr/ADR-0005-frontend-stack.md` was changed from a fixed decision (Vue 3 + Inertia.js + Pinia for all projects) to a per-project selection framework within the PHP/Laravel ecosystem (Blade / Livewire / Vue+Inertia+Pinia / React+Inertia / SPA+API), with Vue+Inertia+Pinia kept as the default recommendation.
- The selection process is now an explicit part of Gate 0 (`CLAUDE.md` Step 1a/1b/1c): select stack → record a project ADR via `/adr` → rewrite `.claude/rules/15-frontend.md` for the chosen stack → reflect the result in `docs/ai-context/`.
- `.claude/rules/15-vue.md` was renamed to `.claude/rules/15-frontend.md` so the rule file path stays stable regardless of which stack is selected — projects choosing a non-default stack rewrite this file's contents instead of creating a new file and updating every cross-reference.
- Backend (Laravel + MySQL, ADR-0001/0002) and auth strategy (Sanctum + Policy/Gate, ADR-0003) remain fixed template decisions — out of scope for this flexibility.

### Files touched

`docs/adr/ADR-0005-frontend-stack.md`, `docs/adr/ADR-0006-e2e-testing-playwright.md`, `CLAUDE.md`, `AGENTS.md`, `README.md`, `.claude/rules/00-global.md`, `.claude/rules/15-frontend.md` (renamed from `15-vue.md`), `.claude/rules/30-testing.md`, `.claude/rules/50-review.md`, `.claude/rules/60-docs.md`, `.claude/agents/tdd-implementer.md`, `docs/ai-context/module-map.md`.

### Status

Completed. No open follow-ups.
