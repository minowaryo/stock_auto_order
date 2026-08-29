# scripts — 開発環境スクリプト

## アプリ起動 / 停止バッチ（Windowsデスクトップ用）

ローカル開発環境をダブルクリックで起動・停止するための Windows バッチ。
**WSL2 ネイティブ側（`/root/workspace/stock_auto_order`）の高速スタックを操作する。**

| ファイル | デスクトップ上の名前 | 役割 |
|---|---|---|
| `start-app.bat` | `stock_auto_order 起動.bat` | Docker Desktop 起動確認 → WSL内で `docker compose up -d` → 応答待ち → ブラウザで http://localhost を開く |
| `stop-app.bat` | `stock_auto_order 停止.bat` | WSL内で `docker compose stop`（コンテナ停止のみ。DB・データは保持） |

いずれも中身は `wsl -d Ubuntu -- bash -lc "cd /root/workspace/stock_auto_order && docker compose ..."` を実行する。
Docker Desktop は Windows / WSL で共有されるため、`docker info` による起動確認は Windows 側でそのまま機能する。

> ⚠️ **Windows パスで `docker compose` を実行しないこと。** compose プロジェクト名（`stock_auto_order`）が
> WSL 側と同じため、Windows フォルダ（`c:\workspace\stock_auto_order`）で起動すると同じコンテナを
> 遅い Windows bind mount 版に作り直してしまう（`GET /holdings` が 0.015 秒 → 3〜7 秒に悪化）。
> 経緯は `docs/ai-context/known-pitfalls.md` 参照。

### 使い方

デスクトップの `stock_auto_order 起動.bat` / `stock_auto_order 停止.bat` をダブルクリックするだけ。

- 起動完了後、ブラウザで `http://localhost` が開く
- ログイン: `test@example.com` / `password`
- 正常時はコンソールが数秒後に自動で閉じる。エラー時は `pause` で止まりメッセージを表示する

### メモ

- バッチ本体はこの `scripts/` がマスター。内容を直したらデスクトップへコピーし直す（PowerShell）:
  ```powershell
  $s = '\\wsl.localhost\Ubuntu\root\workspace\stock_auto_order\scripts'
  $d = [Environment]::GetFolderPath('Desktop')
  Copy-Item (Join-Path $s 'start-app.bat') (Join-Path $d 'stock_auto_order 起動.bat') -Force
  Copy-Item (Join-Path $s 'stop-app.bat')  (Join-Path $d 'stock_auto_order 停止.bat') -Force
  ```
- コンソールメッセージは文字化け回避のため英語
- コンテナごと破棄したい場合は `stop-app.bat` の `docker compose stop` を `docker compose down` に変更（DB データは名前付きボリューム `stock_auto_order_sail-mysql` に残る）
- 別ディストロ／別パスに置いた場合は各バッチ冒頭の `WSL_DISTRO` / `WSL_PROJECT_DIR` を編集
- VS Code の WSL 統合ターミナルからは `cd /root/workspace/stock_auto_order && docker compose up -d` / `stop` を直接叩けばよい（バッチ不要）

## `migrate-to-wsl.sh` — WSLネイティブ環境への複製移設（1回限り・実施済み）

Windows bind-mount による実HTTPリクエストの遅さ（`docs/ai-context/known-pitfalls.md` 参照）を解消するため、
開発環境一式を WSL2 ネイティブ側 `/root/workspace/stock_auto_order` へ複製したときのスクリプト。
手順・引き継ぎ内容は `docs/ai-context/wsl-migration-handoff.md` を参照。

```bash
# WSL (Ubuntu) のシェルで実行
bash /mnt/c/workspace/stock_auto_order/scripts/migrate-to-wsl.sh
```

Windows 側（`c:\workspace\stock_auto_order`）は読み取りのみで変更しない。
