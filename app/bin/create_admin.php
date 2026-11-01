<?php
// 管理者ユーザーの作成・パスワード変更: php app/bin/create_admin.php ユーザー名
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$user = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $user)) {
    fwrite(STDERR, "usage: php create_admin.php USERNAME\n");
    exit(1);
}
fwrite(STDOUT, 'パスワード (12文字以上): ');
system('stty -echo 2>/dev/null');
$pw = trim((string)fgets(STDIN));
system('stty echo 2>/dev/null');
fwrite(STDOUT, PHP_EOL);
if (strlen($pw) < 12) {
    fwrite(STDERR, "12文字以上にしてください\n");
    exit(1);
}
$app = mailer_app();
$app->db()->run(
    'INSERT INTO admin_users (username, password_hash, created_at) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)',
    [$user, password_hash($pw, PASSWORD_DEFAULT), $app->clock->nowSql()]
);
echo "OK: {$user}\n";
