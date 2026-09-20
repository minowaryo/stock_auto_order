---
name: review
description: 指定ファイルや変更差分をハーネスのレビュー強度判定・認可・テスト・設計規約に沿ってレビューする。
---

リポジトリルートの `AGENTS.md` を読み、[共通手順](../../../.claude/commands/review.md) を実行する。
パスとコマンドはリポジトリルートを基準とする。共通手順内の `/コマンド名` はCodexでは対応する `$skill-name`、または同じ手順ファイルの実行として解釈する。
Gateの正本は `.claude/rules/00-global.md`。承認済みという記録を推測で作らない。
