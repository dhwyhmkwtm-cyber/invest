#!/bin/bash
# GitHub Codespaces (ブラウザだけで動く。iPad可) 用の起動スクリプト
set -e
cd "$(dirname "$0")/.."
if [ -n "$CODESPACE_NAME" ]; then
  export MAILER_PUBLIC_BASE_URL="https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
fi
docker compose up -d --build
echo "起動しました: ${MAILER_PUBLIC_BASE_URL:-http://localhost:8080}/admin/  (admin / demo-pass-2026)"
