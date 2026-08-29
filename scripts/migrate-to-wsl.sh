#!/usr/bin/env bash
#
# migrate-to-wsl.sh — Duplicate the stock_auto_order dev environment into the
# WSL2-native filesystem to escape the slow Windows bind-mount.
# See docs/ai-context/known-pitfalls.md ("実HTTPリクエストが数秒〜十数秒かかる")
# and docs/ai-context/wsl-migration-handoff.md for the full context.
#
# Run this from a WSL (Ubuntu) shell — e.g. the integrated terminal of a
# VS Code window connected through the "WSL" extension:
#
#     bash /mnt/c/workspace/stock_auto_order/scripts/migrate-to-wsl.sh
#
# The Windows-side checkout (c:\workspace\stock_auto_order) and the Windows
# C:\Users\minow\.claude data are READ ONLY here — never modified. The script
# is safe to re-run: anything already in place is skipped or refreshed.
#
set -euo pipefail

# --- paths -----------------------------------------------------------------
WIN_REPO="/mnt/c/workspace/stock_auto_order"
WIN_CLAUDE="/mnt/c/Users/minow/.claude"
WIN_CLAUDE_JSON="/mnt/c/Users/minow/.claude.json"
DEST="/root/workspace/stock_auto_order"
REPO_URL="https://github.com/minowaryo/stock_auto_order.git"
OLD_SLUG="c--workspace-stock-auto-order"
NEW_SLUG="-root-workspace-stock-auto-order"
APP_UID=1000   # matches WWWUSER/WWWGROUP in .env (the container's "sail" user)

say() { printf '\n\033[1;36m== %s\033[0m\n' "$*"; }

# --- 0. preconditions ----------------------------------------------------
say "0/7  前提チェック"
[ -d "$WIN_REPO" ]   || { echo "NG: $WIN_REPO が見えません。VS Code の WSL 接続ターミナルで実行してください"; exit 1; }
[ -d "$WIN_CLAUDE" ] || { echo "NG: $WIN_CLAUDE が見えません"; exit 1; }
command -v git    >/dev/null || { echo "NG: git 未導入 → sudo apt-get update && sudo apt-get install -y git"; exit 1; }
command -v curl   >/dev/null || { echo "NG: curl 未導入 → sudo apt-get install -y curl"; exit 1; }
command -v docker >/dev/null || { echo "NG: docker が使えません。Docker Desktop → Settings → Resources → WSL Integration で Ubuntu を有効化してください"; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "NG: 'docker compose' が使えません"; exit 1; }
echo "OK  (実行ユーザー: $(id -un) / HOME=$HOME)"
echo
echo "重要: このあと Windows 側と同じ compose プロジェクト名で Docker を操作します。"
echo "      先に Windows 側で 'docker compose down'（またはデスクトップの停止バッチ）を"
echo "      実行してコンテナを止めておいてください。"
read -r -p "止めてあれば Enter で続行 / 中断は Ctrl-C > " _

# --- 1. Claude Code CLI -------------------------------------------------
say "1/7  Claude Code CLI"
if command -v claude >/dev/null; then
  echo "導入済み: $(command -v claude)  ($(claude --version 2>/dev/null || echo '?'))"
else
  echo "ネイティブインストーラで導入します (node 不要)..."
  curl -fsSL https://claude.ai/install.sh | bash
  export PATH="$HOME/.local/bin:$PATH"
  grep -qs '.local/bin' "$HOME/.bashrc" 2>/dev/null || echo 'export PATH="$HOME/.local/bin:$PATH"' >> "$HOME/.bashrc"
  echo "導入完了: $(command -v claude || echo '(要 source ~/.bashrc)')"
fi

# --- 2. clone (from the local Windows checkout, no GitHub auth needed) --
say "2/7  リポジトリを clone → $DEST"
mkdir -p "$(dirname "$DEST")"
if [ -d "$DEST/.git" ]; then
  echo "既存の作業コピーあり。git fetch のみ実行します"
  git -C "$DEST" fetch --all --prune || true
else
  # Local clone: fast, offline, carries every local commit and all history.
  git clone "$WIN_REPO" "$DEST"
  git -C "$DEST" remote set-url origin "$REPO_URL"
  echo "origin を GitHub に再設定: $(git -C "$DEST" remote get-url origin)"
  git -C "$DEST" fetch origin || echo "  (GitHub fetch は後で 'gh auth login' 等の設定後に)"
fi

# --- 3. repo-local files that git does not track ----------------------
say "3/7  .env / .claude/settings.local.json を移送 (git 管理外)"
cp -v "$WIN_REPO/.env" "$DEST/.env"
mkdir -p "$DEST/.claude"
cp -v "$WIN_REPO/.claude/settings.local.json" "$DEST/.claude/settings.local.json"

# --- 4. Claude user data (transcripts / plans / global config) --------
say "4/7  Claude 会話ログ・plans・グローバル設定を移送"
mkdir -p "$HOME/.claude/projects/$NEW_SLUG" "$HOME/.claude/plans"

# 4a. all conversation transcripts (*.jsonl + per-session sidecar dirs + memory/)
echo "  会話ログをコピー中 ... (約 82MB、少し待ちます)"
cp -a "$WIN_CLAUDE/projects/$OLD_SLUG/." "$HOME/.claude/projects/$NEW_SLUG/"
echo "  → $(ls "$HOME/.claude/projects/$NEW_SLUG"/*.jsonl 2>/dev/null | wc -l) 件の *.jsonl を配置"

# 4b. plan files
cp -v "$WIN_CLAUDE"/plans/stock_auto_order-*.md "$HOME/.claude/plans/" 2>/dev/null || echo "  (対象 plan なし)"

# 4c. global config — only copy what the WSL side is missing (never overwrite)
copy_if_absent() {  # $1 = path relative to ~/.claude
  local rel="$1"
  if [ -e "$HOME/.claude/$rel" ]; then
    echo "  skip (既存): ~/.claude/$rel"
  elif [ -e "$WIN_CLAUDE/$rel" ]; then
    cp -a "$WIN_CLAUDE/$rel" "$HOME/.claude/$rel"
    echo "  copied  : ~/.claude/$rel"
  fi
}
copy_if_absent settings.json
copy_if_absent settings.local.json
copy_if_absent CLAUDE.md
copy_if_absent rules
copy_if_absent commands
copy_if_absent .credentials.json

# 4d. ~/.claude.json — auth account + onboarding flags. Copy the whole file
#     if absent; Claude Code adds the /root/workspace/... project entry itself
#     on first launch. If auth misbehaves, run `claude login` (or `/login`).
if [ -e "$HOME/.claude.json" ]; then
  echo "  skip (既存): ~/.claude.json"
elif [ -e "$WIN_CLAUDE_JSON" ]; then
  cp "$WIN_CLAUDE_JSON" "$HOME/.claude.json"
  echo "  copied  : ~/.claude.json"
fi

# --- 5. ownership so the container's sail user (uid 1000) can write ---
say "5/7  ファイル所有者を ${APP_UID}:${APP_UID} に調整"
chown -R "${APP_UID}:${APP_UID}" "$DEST" 2>/dev/null || echo "  WARN: chown 失敗 (root 以外で実行中?)。storage/ の書き込みで詰まったら手動で対応"
# The repo is now owned by uid 1000 but git runs as root -> "dubious ownership".
git config --global --add safe.directory "$DEST" 2>/dev/null || true

# --- 6. bring the stack up -------------------------------------------
say "6/7  Docker スタック起動 + 依存インストール"
cd "$DEST"
echo "note: compose プロジェクト名は Windows 側と同じ 'stock_auto_order' です。"
echo "      → 同じコンテナ / 同じ MySQL ボリュームを共有します (保有データは維持されます)。"
echo "      → 初回 up で laravel.test コンテナが WSL パスのマウントに作り直されます (正常動作)。"
echo "      → Windows 側と WSL 側の同時起動は不可 (ポート競合)。"
docker compose up -d --wait || docker compose up -d
docker compose exec -T laravel.test chown -R sail:sail storage bootstrap/cache || true
docker compose exec -T laravel.test composer install --no-interaction
docker compose exec -T laravel.test php artisan migrate --force
docker compose exec -T laravel.test npm ci
docker compose exec -T laravel.test npm run build
docker compose exec -T laravel.test php artisan optimize:clear
# The first `up` above starts `php artisan serve` before composer install has
# produced vendor/autoload.php, so supervisor crash-loops and permanently
# gives up on it. Restart once deps exist so the web server actually runs.
docker compose restart laravel.test
sleep 5

# --- 7. speed check -------------------------------------------------
say "7/7  応答速度チェック (GET /holdings ×3)"
for i in 1 2 3; do
  curl -s -o /dev/null -w "  #$i  %{time_total}s  (http %{http_code})\n" http://localhost/holdings || true
done
echo
echo "目安: Windows bind-mount では 2〜14 秒。WSL ネイティブなら 0.1 秒前後になれば成功。"

say "完了"
cat <<EOF

次の手順:
  1. VS Code で「Open Folder」→  $DEST  を開く
  2. 統合ターミナルで:   cd $DEST && claude --resume
       - 過去セッション (47件) が一覧に出れば slug 一致で成功
       - 出ない場合:  ls ~/.claude/projects/  で実際の slug を確認し
         mv ~/.claude/projects/$NEW_SLUG ~/.claude/projects/<実際のslug>
  3. 新セッションにはまず docs/ai-context/wsl-migration-handoff.md を読ませる
  4. 動作に問題なければ known-pitfalls.md の該当エントリに「WSL移設で解消」を追記

Windows 側 (c:\\workspace\\stock_auto_order) は変更していません。
確認が済むまでバックアップとして残し、不要になったら任意で削除してください。
EOF
