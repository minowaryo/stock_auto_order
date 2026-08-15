# authz-authn.md — 認証・認可方針

> 認証・認可に関わる変更前に必ず参照すること。
> 関連ADR: `meta/adr/ADR-0003-auth-strategy.md`

## 認証（Authentication）

### 方式
- **API**: Laravel Sanctum（トークンベース）
- **Web**: Laravelセッション認証

### ログインフロー
```
POST /login
  → FormRequestバリデーション
  → Auth::attempt()
  → セッション再生成（セッション固定攻撃対策）
  → APIの場合: Sanctumトークン発行
```

### セキュリティ設定
- セッション: HttpOnly + Secure + SameSite=Strict
- CSRF: `VerifyCsrfToken` ミドルウェア有効
- レート制限: `/login` エンドポイントに `throttle:5,1` 適用
- パスワードリセット: 署名付きURL + 1時間有効期限

---

## 認可（Authorization）

### 基本方針

**Policy / Gate を必ず使う。直接ロールチェック禁止。**

```php
// OK
$this->authorize('update', $post);

// NG（直接ロールチェック）
if ($user->role === 'admin') { ... }
```

### ロール定義

| ロール | 説明 | 権限範囲 |
|---|---|---|
| admin | システム管理者 | 全リソースへのフルアクセス |
| [role2] | [説明] | [権限] |
| user | 一般ユーザー | 自分のリソースのみ |

### Policyの実装パターン

```php
class PostPolicy
{
    // 管理者は全操作可能
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    // 所有者のみ更新可能
    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }
}
```

### Controllerでの認可呼び出し

```php
public function update(UpdatePostRequest $request, Post $post): JsonResponse
{
    $this->authorize('update', $post);  // 必ず明示的に呼ぶ

    // 処理...
}
```

---

## 変更ルール

認証・認可基盤を変更する場合:
1. `docs/adr/` に新しいADRを作成する
2. セキュリティレビューを受ける
3. ステージングで十分にテストしてから本番適用
