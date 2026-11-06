#!/bin/bash
# GitHub Codespaces (ブラウザだけで動く。iPad可) 用の起動スクリプト
set -e
cd "$(dirname "$0")/.."
if [ -n "$CODESPACE_NAME" ]; then
  export MAILER_PUBLIC_BASE_URL="https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
fi
# Codespaces の起動直後は Docker の準備が終わっていないことがあるので、最大2分待つ
echo "Docker の準備を待っています..."
for i in $(seq 1 60); do
  docker info >/dev/null 2>&1 && break
  sleep 2
done
if ! docker info >/dev/null 2>&1; then
  echo "Docker が起動していません。少し待ってから、もう一度このコマンドを実行してください: bash docker/codespaces-start.sh"
  exit 1
fi
docker compose up -d --build
echo "体験版の起動を待っています (初回は数分かかります)..."
for i in $(seq 1 90); do
  docker compose logs web 2>/dev/null | grep -q "ready:" && break
  sleep 2
done
echo "起動しました: ${MAILER_PUBLIC_BASE_URL:-http://localhost:8080}/admin/  (admin / demo-pass-2026)"
