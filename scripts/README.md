# scripts — 開発環境スクリプト

## アプリ起動 / 停止バッチ（Windows）

ローカル開発環境（Docker Sail）をダブルクリックで起動・停止するための Windows バッチ。

| ファイル | デスクトップ上の名前 | 役割 |
|---|---|---|
| `start-app.bat` | `stock_auto_order 起動.bat` | Docker Desktop 起動 → `docker compose up -d` → 応答待ち → ブラウザで http://localhost を開く |
| `stop-app.bat` | `stock_auto_order 停止.bat` | `docker compose stop`（コンテナ停止のみ。DB・データは保持） |

## `migrate-to-wsl.sh` — WSLネイティブ環境への複製移設（1回限り）

Windows bind-mount による実HTTPリクエストの遅さ（`docs/ai-context/known-pitfalls.md` 参照）を解消するため、
開発環境一式を WSL2 ネイティブ側 `/root/workspace/stock_auto_order` へ複製する。
手順・引き継ぎ内容は `docs/ai-context/wsl-migration-handoff.md` を参照。

```bash
# WSL (Ubuntu) の VS Code 統合ターミナルで実行
bash /mnt/c/workspace/stock_auto_order/scripts/migrate-to-wsl.sh
```

Windows 側（`c:\workspace\stock_auto_order`）は読み取りのみで変更しない。移設完了後、Windows 用バッチ
（`start-app.bat` / `stop-app.bat`）は WSL 運用では使わない（内容を WSL 向けに直すか非推奨とする）。

## 使い方

デスクトップの `stock_auto_order 起動.bat` / `stock_auto_order 停止.bat` をダブルクリックするだけ。

- 起動完了後、ブラウザで `http://localhost` が開く
- ログイン: `test@example.com` / `password`
- 正常時はコンソールが数秒後に自動で閉じる。エラー時は `pause` で止まりメッセージを表示する

## メモ

- バッチ本体はこの `scripts/` がマスター。内容を直したらデスクトップへコピーし直す:
  ```powershell
  $d = [Environment]::GetFolderPath('Desktop')
  Copy-Item scripts\start-app.bat (Join-Path $d 'stock_auto_order 起動.bat') -Force
  Copy-Item scripts\stop-app.bat  (Join-Path $d 'stock_auto_order 停止.bat') -Force
  ```
- コンソールメッセージは文字化け回避のため英語（バッチの日本語表示は環境依存で壊れやすいため）
- コンテナごと破棄したい場合は `stop-app.bat` の `docker compose stop` を `docker compose down` に変更する（DB データは名前付きボリュームに残る）
- `start-app.bat` 内の `PROJECT_DIR` / `DOCKER_EXE` は環境に合わせて編集可能
