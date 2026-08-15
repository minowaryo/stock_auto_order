# testing-strategy.md — テスト戦略

> 詳細は `.claude/rules/30-testing.md` を参照。

## テストピラミッド

```
         /E2E\         ← 少数（クリティカルフローのみ）
        /------\
       / Feature \     ← メイン（HTTPリクエスト〜レスポンス）
      /------------\
     /     Unit     \  ← 複雑なロジックのみ
    /----------------\
```

## テストフレームワーク

- **PestPHP**（推奨）または PHPUnit
- Feature Test: HTTPクライアントを使ったエンドツーエンドテスト
- Unit Test: クラス単体のロジックテスト
- E2E Test: **Playwright**（`ADR-0006-e2e-testing-playwright.md` 参照）。クリティカルフローのみが対象

## TDD（Red-Green-Refactor）

- 通常の機能開発でもRed→Green→Refactorのサイクルを明示的に指示する（詳細手順・プロンプト例は `.claude/rules/30-testing.md`）
- 機械的に強制したい場合は `@nizos/probity` の導入を検討する（`ADR-0007-tdd-enforcement-probity.md` 参照、任意導入）

## 優先度

1. **Feature Test（最優先）**
   - APIエンドポイントのテスト
   - 認証・認可のテスト
   - バリデーションのテスト

2. **Unit Test（必要な場合）**
   - 複雑な計算ロジック
   - 状態機械
   - データ変換処理

3. **E2E Test（最小限・Playwright）**
   - ユーザー登録フロー
   - 決済フロー
   - クリティカルな業務フロー
   - 配置・実行コマンドは `.claude/rules/31-e2e-testing.md` を参照

## テストデータ管理

- **Factory**: テストデータ生成の標準手段
- **DatabaseTransactions**: テスト間のデータ分離
- **RefreshDatabase**: DBを初期化が必要な場合

```php
uses(RefreshDatabase::class);

test('ユーザーを作成できる', function () {
    $user = User::factory()->create([
        'name' => 'テストユーザー',
    ]);

    expect($user->name)->toBe('テストユーザー');
});
```

## CI/CD でのテスト

```yaml
# GitHub Actions での例
- name: Run tests
  run: php artisan test --parallel
```

## カバレッジ目標

| 対象 | 目標カバレッジ |
|---|---|
| 全体 | 80% 以上 |
| Services / Actions | 90% 以上 |
| Controllers | 70% 以上（Feature Testでカバー） |
