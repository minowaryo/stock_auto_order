# Claude Code / Codex 共用ハーネス

関連: `meta/adr/ADR-0010-codex-harness-compatibility.md`

## 正本と入口

| 内容 | 正本 / 設定先 |
|---|---|
| Gate条件 | `.claude/rules/00-global.md` の「品質ゲート詳細」 |
| 開発ルール | `.claude/rules/*.md`。Codexは `AGENTS.md` の読込表から明示的に読む |
| 定型手順 | `.claude/commands/*.md`。両ツールの入口から同じ本文を参照 |
| 検証手順 | `docs/development/verification.md` |
| Codex skill入口 | `.agents/skills/*/SKILL.md` |
| Codex固有の読み替え | `docs/development/codex-adapter.md` |
| Playwright MCP | Claude: `.mcp.json` / Codex: `.codex/config.toml` |

既存のClaude向け制御ファイルを共通手順として維持し、Codex固有の読み替えだけをアダプターへ置く。Windowsでsymlinkを必須にしないため、Codex skillは短い通常ファイルとする。

## 呼び出し方

| Claude Code | Codex CLI / IDE |
|---|---|
| `/tdd UC-001` | `$tdd UC-001` |
| `/review` | `$review` |
| `.claude/skills/verify` または検証依頼 | `$verify` |
| `/adr` | `$adr` |
| `/generate-mock UC-001` | `$generate-mock UC-001` |
| `/generate-e2e-test UC-001` | `$generate-e2e-test UC-001` |

Codex組み込みの `/review` と、このリポジトリの `$review` は別の入口である。skillが一覧に現れない場合はリポジトリルートでセッションを開き直す。対応しないクライアントでは対象 `SKILL.md` を読むよう明示する。

`.claude/agents/` のモデル・ツール制限や `.claude/settings.local.json` の権限設定はCodexへ移植しない。Claude hookもCodexでは自動発火しないため、レビュー用スクリプトは手順から明示実行する。

## Playwright MCP

`.codex/config.toml` はホストNodeを要求せず、既存の `laravel.test` コンテナ内で `npx -y @playwright/mcp@latest --headless` を実行する。ルールやskillの共有にはNodeは不要で、Nodeが関係するのはMCPとフロントエンドビルドだけである。

前提:

1. `docker compose up -d` により `laravel.test` が起動済みであること。
2. コンテナ内でNode/npmが利用可能であること。
3. MCPのブラウザから到達できるアプリURLを使用すること。
4. 本番URL・実データ環境へ接続しないこと。

確認は、Codexセッションの再起動後にMCP一覧へPlaywrightが現れることと、ローカルのテスト画面でスナップショットを取得できることの両方で行う。設定一覧に載っただけでは接続確認済みとしない。`@latest` を固定する場合は `.mcp.json` と `.codex/config.toml` を同じ検証済みバージョンへ更新する。

## 導入後の確認シナリオ

- `$tdd UC-xxx`: 失敗テストとログを提示してGate 4で停止し、承認前に実装を変更しない。
- `$review`: 指定範囲の未コミット・未追跡ファイルもレビューする。
- `$verify`: 利用できない検証を未実施として報告し、成功扱いしない。

## 公式資料

- [Codex skills](https://developers.openai.com/codex/skills)
- [Codex MCP](https://developers.openai.com/codex/mcp)
