# /tdd — TDD（Red→Green→Refactor）実行コマンド

指定した機能・UC番号に対してTDDサイクルを進行してください。

## 実行前に読み込むファイル

- `docs/product/use-cases.md`
- `.claude/rules/30-testing.md`
- `docs/development/git-workflow.md`（ブランチ・コミット・マージの手順。プロファイルは `.claude/rules/70-git.md`）

## 手順

0. **ブランチ確認**: 現在のブランチが `main` の場合は、ステップ1の前にブランチに移る——`lite` ではブランチを作成してその旨を伝え、`standard` ではブランチ名を提案して同意を待つ（`docs/development/git-workflow.md` §0 プロファイル、§1 ブランチ）
1. **Red**: `test-writer` サブエージェントに失敗するテストの作成を依頼する（実装コードには触れさせない）
2. **Gate 4（テストケース承認）**: 作成されたテストの内容と失敗ログをユーザーに提示し、意図通りの仕様になっているかレビューを求める。**承認を得るまで次のステップに進まない**（`.claude/rules/00-global.md` の品質ゲート）
3. **Green**: Gate 4承認後、`tdd-implementer` サブエージェントにテストを通す最小実装を依頼する
4. 実行結果（Green確認）をユーザーに提示する。`standard` では、この時点で **`/commit`** を提案する（Red + Greenで1コミットとし、Refactorは別コミットのまま）。`lite` では、ステップ7の後にサイクル全体を1回でコミットする（`docs/development/git-workflow.md` §0 プロファイル）
5. **実挙動の確認**: `run` スキルの実行をユーザーに推奨し、実際にアプリを起動して機能が動作することを確認してもらう——バンドルされたスキルは人間が明示的に呼び出した場合にのみ実行されるため、AIが自動実行せず推奨する旨を伝える（`.claude/rules/30-testing.md`）
6. **E2E追加（該当する場合のみ）**: 対象がUCのクリティカルフロー（`docs/product/use-cases.md` 参照）かつUI変更を含む場合、`/generate-e2e-test` を実行してPlaywright E2Eテストを追加する
7. **Refactor**: 必要に応じてリファクタを行う。リファクタ後は必ずテストを再実行し、Greenが維持されていることを確認する
8. 最終的な差分とテスト実行結果をまとめて報告し、**`/commit`** を提案する（`standard` ではRefactorの変更について）。ブランチの作業が完了したら **`prepare-merge`** を提案する（`/review` の要否はこれが判定する——`docs/development/git-workflow.md` §6 マージ前チェック）。`lite` では、ユーザーは commit → merge → push の一括実行を依頼できる（§4 権限）

## 制約

- 上記の手順・順序を省略しない（特にステップ2のGate 4承認）
- 各フェーズの完了後、対応するテストコマンド（`php artisan test` 等、詳細は `.claude/rules/30-testing.md`）を実行して結果を提示する

## 使用例

```
/tdd UC-006 受注一覧のフィルタ機能
```
