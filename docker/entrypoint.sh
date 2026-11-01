#!/bin/sh
set -e
APP=/var/www/app
mkdir -p /tmp/mailer-var && chown www-data:www-data /tmp/mailer-var

echo "[mailer] waiting for database..."
i=0
until php -r 'new PDO("mysql:host=" . (getenv("MAILER_DB_HOST") ?: "db") . ";dbname=mailer", "mailer", "mailer");' >/dev/null 2>&1; do
  i=$((i+1)); [ $i -gt 90 ] && { echo "[mailer] database did not start"; exit 1; }
  sleep 2
done
php $APP/bin/migrate.php
php $APP/bin/demo_seed.php

# ロリポップの cron の代わり: 1分ごとに cron.php を実行
(
  while true; do
    su -s /bin/sh www-data -c "MAILER_CONFIG=$MAILER_CONFIG MAILER_PUBLIC_BASE_URL=$MAILER_PUBLIC_BASE_URL MAILER_DB_HOST=${MAILER_DB_HOST:-db} php $APP/bin/cron.php" > /dev/null 2>&1 || true
    sleep 60
  done
) &

echo "[mailer] ready: ${MAILER_PUBLIC_BASE_URL:-http://localhost:8080}/admin/  (admin / demo-pass-2026)"
exec apache2-foreground
