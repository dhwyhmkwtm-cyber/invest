<?php
// 未チェック宛先のMX確認を手動で実行: php app/bin/check_mx.php [件数]
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$app = mailer_app();
$r = (new Mailer\MxChecker($app->db(), $app->clock, Mailer\Local\LocalMailbox::isLocal($app)))->checkPending((int)($argv[1] ?? 2000), 600.0);
echo json_encode($r), PHP_EOL;
