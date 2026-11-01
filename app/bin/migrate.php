<?php
// テーブル作成・更新: php app/bin/migrate.php (何度実行しても安全)
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$app = mailer_app();
$applied = (new Mailer\Migrator($app->db(), $app->clock))->migrate();
echo $applied ? 'OK: applied ' . implode(', ', $applied) . "\n" : "OK: schema is up to date\n";
