# meta/adr/

Architecture Decision Records for this template/harness itself — not for the project built
from it. These document why the rules, gates, and tooling in `.claude/`, `CLAUDE.md`, and
`docs/` are designed the way they are.

Do not add project-specific decisions here and do not renumber these when adopting the
template — a new project's own decisions go in [`docs/adr/`](../../docs/adr/), starting
from `ADR-0001`.

| ADR | Decision |
|---|---|
| ADR-0001 | Laravel as the backend framework |
| ADR-0002 | MySQL as the database |
| ADR-0003 | Auth strategy (Sanctum + Policy/Gate) |
| ADR-0004 | AI development policy |
| ADR-0005 | Frontend stack selection process (Gate 0) |
| ADR-0006 | Playwright for E2E testing |
| ADR-0007 | TDD enforcement tooling (Probity) |
| ADR-0008 | TDD/E2E harness tooling |
| ADR-0009 | Review escalation mechanism (review-score) |
