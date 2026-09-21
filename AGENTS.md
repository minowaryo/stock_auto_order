# AGENTS.md

## Project

Laravel + MySQL web application

## Required read order (start every task with these)

1. `docs/ai-context/project-summary.md`
2. `docs/ai-context/glossary.md`
3. `docs/ai-context/module-map.md`
4. `.claude/rules/00-global.md`（共通ポリシーと品質ゲートの正本）
5. `.claude/rules/40-security.md` と `docs/ai-context/do-not-touch.md`
6. Relevant file(s) below depending on the task

Codex は `.claude/rules/` を自動読込しないため、以下の表に従って必要なファイルを明示的に読む。

## Task-based reading

| Task | Read |
|---|---|
| Creating / updating requirements.md or use-cases.md | `docs/original-docs/` (source materials) + `docs/product/requirements.md` |
| Running commands (tests, migrations, build, etc.) | `docs/ai-context/common-commands.md` |
| Requirements / UC reference | `docs/product/requirements.md` + `docs/product/use-cases.md` |
| Code implementation | `docs/product/use-cases.md` + `docs/architecture/data-model.md` + `docs/product/mockups/` |
| UI implementation / mock-based dev | `docs/product/ui-guidelines.md` + `docs/product/mockups/` |
| Frontend changes | `.claude/rules/15-frontend.md` (rules for whichever stack was selected via `meta/adr/ADR-0005-frontend-stack.md`) |
| Auth changes | `docs/architecture/authz-authn.md` |
| DB changes | `docs/architecture/data-model.md` + `docs/adr/` |
| Test changes | `docs/development/testing-strategy.md` + `docs/product/use-cases.md` + `docs/architecture/data-model.md` |
| Architecture changes | `docs/adr/` (all relevant ADRs) |
| Security changes | `docs/security/secrets-handling.md` |
| Release changes | `docs/operations/deployment.md` |
| Change request | `docs/rcid/traceability-matrix.md` |
| Laravel implementation / review | `.claude/rules/10-laravel.md` |
| DB implementation / review | `.claude/rules/20-mysql.md` |
| Tests / TDD | `.claude/rules/30-testing.md` |
| E2E tests / browser verification | `.claude/rules/31-e2e-testing.md` |
| Code review | `.claude/rules/50-review.md` |
| Documentation / harness changes | `.claude/rules/60-docs.md` |
| Requesting plan approval | `.claude/rules/05-plan-approval.md`（`ExitPlanMode` は Claude 固有。Codex は承認依頼前に変更範囲・完了条件を要約する） |
| User-facing behavior / instructions | `docs/product/user-guide.md` |
| UAT (optional, non-blocking) | `docs/product/uat-scenarios.md` + `docs/product/uat-results/` |
| Library errors / troubleshooting | `docs/ai-context/known-pitfalls.md` |
| Harness architecture | `meta/adr/README.md` + relevant `meta/adr/` decisions + `docs/development/harness-compatibility.md` |

## Quality gates

Gate 条件・適用範囲の正本は `.claude/rules/00-global.md` の「品質ゲート詳細」とする。ここには重複する Gate 表を持たない。

**Do not generate code before Gate 2 is passed.**
**Do not write implementation code before Gate 4 is passed**: write a failing test first, stop, and wait for human approval before implementing. See `.claude/rules/30-testing.md`.

## Codex workflows

Codex用の入口は `.agents/skills/` に置く。`$tdd`、`$review`、`$verify`、`$adr`、`$generate-mock`、`$generate-e2e-test` を利用できる。選択した skill の `SKILL.md` と、そこから参照される共通手順を読んでから実行する。

Claude の slash command と `.claude/agents/` は、Codexのコマンドや登録済みエージェントではない。実行時の読み替えは `docs/development/codex-adapter.md` に従う。読み替えはツール呼出しだけを適応し、品質ゲートを緩和しない。

Playwright MCP は `.codex/config.toml` に設定する。Claude hook はCodexでは自動発火しないため、レビュー用スクリプトはワークフローから明示的に実行する。

## Rules

- Do **not** read the entire docs tree unless explicitly needed
- Read only files relevant to the current task
- Do not generate code until `docs/product/use-cases.md` is approved (Gate 2)
- Mock creation (`/generate-mock`) is allowed after Gate 1 — do not wait for Gate 3
- Before any architecture change, check `docs/adr/`
- Frontend stack must be selected per the criteria in `meta/adr/ADR-0005-frontend-stack.md` and recorded as a project ADR (in `docs/adr/`) — this is part of Gate 0, not optional
- Do not change env / secret handling casually
- Do not introduce schema-breaking migration without a migration plan
- Do not bypass authorization layer (Policy / Gate)
- Do not edit unrelated files
- `docs/original-docs/` is read-only — never edit, delete, or create files in it
- Test case names must be derived from use-cases.md UC titles
- Follow Red → Gate 4 approval → Green → Refactor for new features, not just bug fixes (`.claude/rules/30-testing.md`)
- Before asking the user to approve a plan, output a bullet-point summary of it first (see `.claude/rules/05-plan-approval.md`)

## Output expectations

For every non-trivial change:
- Summarize changed files
- Mention risks
- Propose tests (reference the UC that the test covers)
- Reference related ADR / UC IDs when available

## Prohibited actions

- Code generation before Gate 2 approval
- Implementation before Gate 4 (test case) approval
- Committing secrets or credentials
- Bypassing Gate / Policy for authorization
- Schema-breaking migrations without migration plan
- Force-pushing to main/master
- Editing, deleting, or creating files under `docs/original-docs/`
