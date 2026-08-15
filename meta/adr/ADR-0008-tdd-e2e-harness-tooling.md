# ADR-0008: TDD/E2E運用強化のためのハーネス拡張（MCPツール・サブエージェント・スラッシュコマンド）

## Status
Accepted

## Date
2026-07-15

## Context

[[ADR-0006-e2e-testing-playwright]] と [[ADR-0007-tdd-enforcement-probity]] により、テスト実装レベル（`@playwright/test` / `@nizos/probity`）の方針は定まった。
しかし、Claude Code側の拡張機能（MCPツール・サブエージェント・スラッシュコマンド）は未整備で、以下の課題が残っていた。

- Playwrightテストのセレクタ（`getByRole` / `getByLabel`）をAIが実際の画面を見ずに書くため、当てずっぽうになりやすい
- `.claude/rules/30-testing.md` にRed→Green→Refactorの運用ルールを明文化したが、**プロンプト運用のみでは「RedとGreenを同一リクエストにまとめない」を徹底しにくい**
- `/adr` `/review` `/generate-mock` 等の定型コマンドは既にあるが、TDDサイクルとE2Eテスト生成には対応するコマンドがなかった

## Decision

以下3点をハーネスに追加する。

### 1. Playwright MCP（`.mcp.json`、プロジェクトスコープ）
`@playwright/mcp` を追加し、Claude Codeがローカル起動中のアプリを実際にブラウザ操作できるようにする。

### 2. TDD専用サブエージェント（`.claude/agents/`）
- `test-writer` — Redフェーズ専用。失敗するテストのみを書く
- `tdd-implementer` — Greenフェーズ専用。テストを通す最小実装のみを行う

### 3. スラッシュコマンド（`.claude/commands/`）
- `/tdd` — Red→Green→Refactorをサブエージェント経由で進行する定型コマンド
- `/generate-e2e-test` — use-cases.mdからPlaywright E2Eテストの叩き台を生成する定型コマンド（`/generate-mock` と同系統）

## Rationale

### Playwright MCPを選んだ理由
- `@playwright/test`（テスト記述ライブラリ）とは別物で、**実際にブラウザを操作して確認する**用途。実装後の動作確認とセレクタの正確性向上の両方に効く
- アクセシビリティスナップショット方式のため画像解析（vision）が不要で、Playwrightのロールベースセレクタ方針（`.claude/rules/30-testing.md`）とそのまま噛み合う

### サブエージェント分離を選んだ理由
- Claude Codeはテストと実装を同一ターンで書きやすく、テストが実装に迎合してしまう傾向がある。エージェントをRed/Greenで分離し、コンテキストと役割を独立させることで迎合を防ぎやすくする
- **注意（限界）**: サブエージェントの `tools` フロントマターはツール種別（Read/Write/Bash等）の制限はできるが、**ファイルパス単位（例: `app/` 配下だけ書き込み禁止）の制限はできない**。したがって本質的な強制力ではなく、プロンプト運用の徹底を補助する位置づけである。真に機械的な強制が必要な場合は [[ADR-0007-tdd-enforcement-probity]] のProbity導入を検討する

### スラッシュコマンドを選んだ理由
- 既存の `/adr` `/review` `/generate-mock` と同じ形式に揃えることで、AIツール利用のパターンを一貫させる（`docs/ai-context/prompt-patterns.md` の思想と一致）

## Consequences

### メリット
- E2Eテストのセレクタ精度向上、実装後の動作確認の自動化
- TDDサイクルの手順が定型コマンド化され、指示漏れが減る

### デメリット・リスク
- **Playwright MCPのセキュリティ・プライバシー上の注意点**:
  - ローカル開発環境（`php artisan serve` 等）限定で使用し、本番URLや実データ環境には絶対に接続しない
  - スクリーンショット・アクセシビリティスナップショットに個人情報が写り込む可能性があるため、AIの出力をそのまま外部共有しない（`.claude/rules/40-security.md` のログ・監査ルールに準じる）
  - プロジェクトスコープの `.mcp.json` は初回利用時にClaude Codeが承認プロンプトを出す。承認前にサーバーの実体（`npx @playwright/mcp@latest`）を確認する運用とする
- **サブエージェントの限界**: 上記の通りパス単位の強制はできない。`test-writer` が誤って実装コードに触れていないか、`/tdd` コマンドの各フェーズ完了時に人間が差分を確認する運用を前提とする
- Node.js実行環境（`npx`）が前提となり、PHPのみのプロジェクトでは追加のツールチェーンが必要

## Related
- `.mcp.json`
- `.claude/agents/test-writer.md`
- `.claude/agents/tdd-implementer.md`
- `.claude/commands/tdd.md`
- `.claude/commands/generate-e2e-test.md`
- ADR-0006-e2e-testing-playwright
- ADR-0007-tdd-enforcement-probity
