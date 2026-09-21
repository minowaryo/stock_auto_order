# ADR-0010: Claude Code / Codex のハーネス共用

## Status
Accepted（ハーネス互換化のユーザー依頼に基づく。アプリ機能のGate承認を意味しない）

## Date
2026-09-19

## Context
開発ルールは両ツールで共用できるが、Claude固有の自動読込・コマンド・サブエージェント・MCP設定をCodexがそのまま認識することは前提にできない。Gate表の複製も同期漏れを招く。

## Decision
- `.claude/` の既存ルールとコマンドを共通手順として参照し、Codex固有の読み替えは `docs/development/codex-adapter.md` に集める。
- Gate条件の正本を `.claude/rules/00-global.md` とし、`AGENTS.md` の重複表を削除する。
- `.agents/skills/` に薄いCodex入口を置く。既に導入済みのアプリなので、既存コードベース導入用skillは追加しない。
- TDDはツールごとに実行主体を読み替えても、Gate 4で停止する。
- Playwright MCPは既存Laravelコンテナ内のNodeを使用し、ホストへのNode導入を要求しない。
- hook・権限・モデル設定を自動移植したとは扱わず、レビュー用スクリプトは明示実行する。

## Rationale
既存のClaude向け資産とパスを保ち、Codex側には短い入口だけを追加することで差分と保守負担を抑える。symlink必須案はWindows環境での負担があるため採用しない。MCP以外のルール共有にNodeは不要である。

## Consequences
Codexが既存Gateと手順を参照でき、Claude側の動作も維持される。一方、Claude専用サブエージェントやhookの自動発火まで同等になるわけではない。MCPの実接続はDocker、コンテナ内Node、ブラウザ依存関係に左右されるため、設定構文とは別に実接続確認が必要となる。

## Related
- `meta/adr/ADR-0004-ai-development-policy.md`
- `meta/adr/ADR-0008-tdd-e2e-harness-tooling.md`
- `docs/development/harness-compatibility.md`
