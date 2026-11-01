<?php
// ロリポップの cron に登録するスクリプト (例: 5分ごと)
//   /usr/local/php/8.3/bin/php /home/users/0/LOGIN/mailer/app/bin/cron.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
set_time_limit(60);
try {
    $out = (new Mailer\Cron(mailer_app()))->run();
    echo json_encode($out, JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    mailer_app()->log('error', 'cron: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
