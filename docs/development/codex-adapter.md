# Codex用の実行補足

既存の `.claude/` 制御ファイルは共通の読み取り元として利用する。Codexで手順を実行するときは、ツール固有表現だけを以下のように読み替える。業務ルール・品質ゲート・承認要件は変更しない。

- `/tdd` 等のClaudeコマンドは対応する `$tdd` 等のCodex skill、または参照先手順の実行として扱う。
- `ExitPlanMode` はClaude専用。Codexでは計画承認を求める前に変更範囲・完了条件を要約する。
- `.claude/agents/` はCodexの登録済みエージェントではない。TDDでは現在のエージェントがRed・Greenを順に担当する。許可された委譲機能がある場合のみ委譲してよい。
- TDD開始前にGate 2、DB実装前にGate 3を確認する。Redのテスト内容と失敗ログを提示してGate 4で停止し、人間の承認後にGreenへ進む。
- TDD手順中の `run` skillは存在すると仮定せず、`$verify` から `docs/development/verification.md` を実施する。
- レビューでは `.claude/rules/50-review.md` と対象ルールを読む。スクリプトは自動hookに依存せず明示実行する。
- review-scoreは主にコミット済み差分を対象とする。`git status --short`、`git diff`、`git diff --cached` と未追跡ファイルを別途確認し、スコア0を未コミット変更の検証成功とみなさない。
- Playwright MCPは `.codex/config.toml` を使う。利用できなければ画面ソースを確認し、ブラウザ未検証であることを報告する。
