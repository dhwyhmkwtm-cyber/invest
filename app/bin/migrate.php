<?php
// テーブル作成: php app/bin/migrate.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$db = mailer_app()->db();
$sql = file_get_contents(MAILER_ROOT . '/sql/schema.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $db->pdo->exec($stmt);
}
echo "OK: schema applied\n";
