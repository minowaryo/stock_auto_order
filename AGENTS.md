# AGENTS.md

## Project

Laravel + MySQL web application

## Required read order (start every task with these)

1. `docs/ai-context/project-summary.md`
2. `docs/ai-context/glossary.md`
3. `docs/ai-context/module-map.md`
4. `docs/ai-context/common-commands.md`
5. Relevant file(s) below depending on the task

## Task-based reading

| Task | Read |
|---|---|
| Creating / updating requirements.md or use-cases.md | `docs/original-docs/` (source materials) + `docs/product/requirements.md` |
| Requirements / UC reference | `docs/product/requirements.md` + `docs/product/use-cases.md` |
| Code implementation | `docs/product/use-cases.md` + `docs/architecture/data-model.md` + `docs/product/mockups/` |
| UI implementation / mock-based dev | `docs/product/ui-guidelines.md` + `docs/product/mockups/` |
| Frontend changes | `.claude/rules/15-frontend.md` (rules for whichever stack was selected via `docs/adr/ADR-0005-frontend-stack.md`) |
| Auth changes | `docs/architecture/authz-authn.md` |
| DB changes | `docs/architecture/data-model.md` + `docs/adr/` |
| Test changes | `docs/development/testing-strategy.md` + `docs/product/use-cases.md` + `docs/architecture/data-model.md` |
| Architecture changes | `docs/adr/` (all relevant ADRs) |
| Security changes | `docs/security/secrets-handling.md` |
| Release changes | `docs/operations/deployment.md` |
| Change request | `docs/rcid/traceability-matrix.md` |

## Quality gates

| Gate | Condition | Unlocks |
|---|---|---|
| Gate 0 | `docs/ai-context/` filled in AND a frontend stack selection ADR exists (see `docs/adr/ADR-0005-frontend-stack.md`) | AI assistance starts |
| Gate 1 | `docs/product/requirements.md` approved | use-cases.md drafting + mock generation |
| Gate 2 ★ | `docs/product/use-cases.md` final approval | **Code generation** + data-model drafting |
| Gate 3 | `docs/architecture/data-model.md` approved | DB implementation + migrations |
| Gate 4 | Failing test case(s) reviewed and approved by a human (per-feature, repeats every cycle — unlike Gate 0-3 which pass once) | Implementation (Green phase) |

**Do not generate code before Gate 2 is passed.**
**Do not write implementation code before Gate 4 is passed**: write a failing test first, stop, and wait for human approval before implementing. See `.claude/rules/30-testing.md`.

## Rules

- Do **not** read the entire docs tree unless explicitly needed
- Read only files relevant to the current task
- Do not generate code until `docs/product/use-cases.md` is approved (Gate 2)
- Mock creation (`/generate-mock`) is allowed after Gate 1 — do not wait for Gate 3
- Before any architecture change, check `docs/adr/`
- Frontend stack must be selected per the criteria in `docs/adr/ADR-0005-frontend-stack.md` and recorded as a project ADR — this is part of Gate 0, not optional
- Do not change env / secret handling casually
- Do not introduce schema-breaking migration without a migration plan
- Do not bypass authorization layer (Policy / Gate)
- Do not edit unrelated files
- `docs/original-docs/` is read-only — never edit, delete, or create files in it
- Test case names must be derived from use-cases.md UC titles
- Follow Red → Gate 4 approval → Green → Refactor for new features, not just bug fixes (`.claude/rules/30-testing.md`)

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
