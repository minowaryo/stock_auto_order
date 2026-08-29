# wsl-migration-handoff.md — WSLネイティブ環境への移設 引き継ぎメモ

> 作成: 2026-08-29 / 対象: Windows bind-mount の遅さ解消のため開発環境を WSL2 ネイティブへ複製する作業
> このメモは移設作業が完了したら不要。完了後に `known-pitfalls.md` の該当エントリを更新し、このファイルは削除してよい。

## 背景（なぜ移設するか）

`known-pitfalls.md` の「Laravel `php artisan serve`（Windows + Docker Desktop） — 実HTTPリクエストが数秒〜十数秒かかる」記載の問題。
Docker Desktop (WSL2 backend) が Windows 側パス `c:\workspace\stock_auto_order` を bind mount しているため、1リクエストあたり 2〜14 秒かかる。
検証済み: 同じ compose スタックでもリポジトリを WSL2 ネイティブ側に置くと 0.015〜0.4 秒（100倍以上）。アプリのコード・クエリ・Livewire は原因ではない（302 リダイレクトだけでも遅い）。

## 方針（確定事項）

| 項目 | 決定 |
|---|---|
| 移設方式 | **複製**（move ではない）。Windows 側 `c:\workspace\stock_auto_order` はバックアップとして残す |
| 移設先パス | `/root/workspace/stock_auto_order`（WSL Ubuntu、デフォルトユーザー = root） |
| 会話ログ | **全 47 件（約 82MB）を移送** |
| Docker | compose プロジェクト名がフォルダ名由来で Windows 側と同じ `stock_auto_order` → **コンテナ / MySQL ボリュームを共有**。保有データは自動で維持される。Windows 側と同時起動は不可（ポート 80/3306 競合） |
| Claude Code | WSL 側に未導入 → ネイティブインストーラで導入（node 不要） |

## 実行手順

### 1. Windows 側（このセッションで実施済み）

- `.gitignore` 整理・保留ドキュメントのコミット（commit `f9d6c99`）
- `scripts/migrate-to-wsl.sh` 追加（本メモと同じコミット）
- ※ push はユーザーが実施

### 2. WSL 側（ユーザーが VS Code で実施）

1. VS Code 左下 `><` → **Connect to WSL**（Ubuntu）
2. 統合ターミナルで:
   ```bash
   bash /mnt/c/workspace/stock_auto_order/scripts/migrate-to-wsl.sh
   ```
   スクリプトが行うこと:
   - Claude Code CLI 導入
   - `git clone`（Windows 作業コピーからローカル clone → origin を GitHub URL に再設定。GitHub 認証不要）
   - `.env` / `.claude/settings.local.json` を移送（git 管理外）
   - 会話ログ 47 件・plans 10 件・グローバル設定（`settings.json` / `CLAUDE.md` / `rules/` / `commands/` / `.credentials.json` / `.claude.json`）を `/mnt/c/Users/minow/.claude` から移送
   - プロジェクト slug を `-root-workspace-stock-auto-order` に再キー
   - `chown -R 1000:1000`（コンテナ sail ユーザー用）
   - `docker compose up -d --wait` → `composer install` / `migrate --force` / `npm ci` / `npm run build`
   - `GET /holdings` の応答速度を計測
3. VS Code で **Open Folder → `/root/workspace/stock_auto_order`**
4. `cd /root/workspace/stock_auto_order && claude --resume`

### 3. 新しい Claude Code セッション（WSL 側）で最初にやること

1. **このメモを読む**（もう読んでいる）
2. `claude --resume` で過去 47 セッションが一覧に出るか確認
   - 出ない → `ls ~/.claude/projects/` で実 slug を確認し
     `mv ~/.claude/projects/-root-workspace-stock-auto-order ~/.claude/projects/<実slug>`
3. 動作確認:
   - `docker compose exec laravel.test php artisan test`（全 Green か）
   - ブラウザ or `curl http://localhost/holdings` の応答速度（0.1 秒前後になっているか）
   - ログイン: `test@example.com` / `password`
4. `docs/ai-context/known-pitfalls.md` の該当エントリ「対処」を更新:
   - 「2026-08-29、体感悪化のため WSL2 ネイティブ（`/root/workspace/stock_auto_order`）へ複製移設。応答 X 秒 → Y 秒に改善」
5. このファイル（`wsl-migration-handoff.md`）を削除し、そのコミットに含める
6. `scripts/start-app.bat` / `stop-app.bat` は Windows 用。WSL 運用に切り替えたら内容を WSL 向けに直すか、README に「非推奨」と明記する（`scripts/README.md` 更新）

## 注意・既知のリスク

- **slug 不一致**: Claude Code のバージョン差でパス→slug 変換規則が違うと `--resume` に過去ログが出ない。上記 3-2 の `mv` で対応。
- **認証**: `.credentials.json` / `.claude.json` を移送しても WSL 側で認証が通らない場合は `claude login`（または対話中に `/login`）。
- **GitHub push/pull**: ローカル clone のため直後は GitHub 認証が未設定。`gh auth login` か git credential helper を WSL 側で設定するまで push できない。
- **Sail 権限問題**: `known-pitfalls.md`「storage/配下に書き込めず 500」と同種。スクリプトは `chown -R 1000:1000` と `chown -R sail:sail storage bootstrap/cache` を実施済みだが、`docker compose exec` を root で叩いて新規ファイルを作ると再発する。都度 `chown` する。
- **Windows 側との同時起動禁止**: 同じ compose プロジェクトのため。片方を使うときは他方を `docker compose down`。
- **Windows 側フォルダ**: 動作確認が完全に済むまで削除しない。
