# ADR-0007: TDD強制ツールの選定（Probity）

## Status
Accepted

## Date
2026-07-15

## Context

`.claude/rules/30-testing.md` では「バグ修正時は再発防止テストを先に書く（TDD）」とだけ記載されており、通常の機能開発における Red → Green → Refactor サイクルの運用方法が定義されていなかった。
AIコーディングエージェント（Claude Code）は「実装を先に書いてから後付けでテストを書く」挙動を取りやすく、明示的なプロンプト指示だけでは徹底されないことが知られている。人間が毎回プロンプトでTDDを指示する運用はレビュー負荷・指示漏れのリスクがある。

検討した選択肢:

1. **ガイドライン化のみ**（`.claude/rules/30-testing.md` にRed-Green-Refactorの手順を明文化し、プロンプトパターンを整備）
2. **[nizos/tdd-guard](https://github.com/nizos/tdd-guard)** — Claude Code専用のTDD強制フック
3. **[nizos/probity](https://github.com/nizos/probity)** — tdd-guardの後継。Claude Code / Codex / GitHub Copilot CLI に対応する汎用ガードレールツール

tdd-guard自身のREADMEにおいて「新規プロジェクトはProbityから始めるべき」と明記されており、tdd-guardは既存利用者向けの互換維持フェーズに入っている。

## Decision

**Probity（`@nizos/probity`）をオプションの強制レイヤーとして採用し、`.claude/rules/30-testing.md` にガイドラインとして明文化した運用と併用する。**

- 強制力: Gate化はしない。プロジェクト側の判断で `probity.config.ts` に `enforceTdd()` ルールを追加するかを選択できる任意導入とする（[[ADR-0004-ai-development-policy]] の「AI全自動禁止・人間レビュー必須」方針を踏まえ、まずは人間の運用合意を優先する）
- 対応エージェント: Claude Code に加えて Codex（`AGENTS.md` 経由の利用）でも同一ルールを適用できる点を評価
- 導入コマンド: `npm install -D @nizos/probity`

## Rationale

### Probity を選んだ理由
- ファイル書き込み・シェルコマンド実行を検査し、ルール違反時にエージェントへ理由と対応方法を提示して遮断する「ポリシーエンジン」であり、TDD強制以外にも `git commit` 前のテスト成功チェック等、本リポジトリの既存ルール（00-global.mdの品質ゲート、50-review.mdのレビュー観点）と親和性が高い
- テストランナー非依存（tdd-guardはVitest/Jest/pytestごとにreporter設定が必要だったが、Probityはセッション履歴を読む方式でセットアップが軽い）
- Claude CodeとCodexの併用を前提とする本リポジトリの `AGENTS.md` 運用と一致する（tdd-guardはClaude Code専用）

### 採用しなかった代替案
- **ガイドラインのみ**: 明文化だけでは「実装を先に書いてからテストを後付けする」挙動を防げない。ただし強制ツール未導入のプロジェクトでも最低限の規律を保てるよう、ガイドライン自体は本ADRとは独立して維持する（`.claude/rules/30-testing.md`）
- **tdd-guard**: 開発元が後継のProbityへの移行を推奨しており、新規採用は非推奨

## Consequences

### メリット
- テストなし実装を機械的に検知でき、「テストなし実装」を絶対禁止とする `.claude/rules/00-global.md` の実効性が上がる
- Claude Code / Codex 双方で同一ルールを共有できる

### デメリット・リスク
- 誤検知（正当な理由でテストなし変更が必要なケース、例: 純粋なドキュメント変更）でエージェントの作業がブロックされる可能性がある。ルール側で対象パス（`docs/`, `*.md` 等）を除外する設定が必要
- 導入・ルール定義のメンテナンスコストが増える
- Node.js実行環境が前提（PHPのみのプロジェクトでは追加のツールチェーンが必要）

### 運用ルール
- 導入するかはプロジェクト単位の判断とし、導入する場合は本ADRを参照の上 `probity.config.ts` を作成する
- 導入しない場合も、`.claude/rules/30-testing.md` のRed-Green-Refactorガイドラインは全プロジェクト共通で適用する

## Related
- `.claude/rules/30-testing.md`
- `docs/development/ai-workflow.md`
- `docs/ai-context/common-commands.md`
- ADR-0004-ai-development-policy
