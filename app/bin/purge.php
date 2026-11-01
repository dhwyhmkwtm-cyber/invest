<?php
// 個人情報・ログの削除手順 (非機能要件)。
//   php app/bin/purge.php logs            … 保存期間 (既定400日) を過ぎた send_log / events を削除
//   php app/bin/purge.php contact EMAIL   … 指定アドレスの宛先データを削除 (抑止リストには残す)
//   php app/bin/purge.php batch BATCH_ID  … 取り込み単位で未送信の宛先を削除
// 抑止リスト (suppressions) は「二度と送らない」ために削除しない。
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$app = mailer_app();
$db = $app->db();
$cmd = $argv[1] ?? '';
switch ($cmd) {
    case 'logs':
        $days = max(366, (int)($argv[2] ?? 400));
        $cutoff = $app->clock->now()->modify("-{$days} days")->format('Y-m-d H:i:s');
        $a = $db->run('DELETE FROM send_log WHERE created_at < ?', [$cutoff])->rowCount();
        $b = $db->run('DELETE FROM events WHERE created_at < ?', [$cutoff])->rowCount();
        $c = $db->run('DELETE FROM login_attempts WHERE created_at < ?', [$cutoff])->rowCount();
        echo "deleted send_log={$a} events={$b} login_attempts={$c} (before {$cutoff})\n";
        break;
    case 'contact':
        $email = Mailer\Util\Email::normalize($argv[2] ?? '') ?? exit("email required\n");
        $row = $db->one('SELECT id FROM contacts WHERE email = ?', [$email]);
        if (!$row) {
            exit("not found\n");
        }
        $db->transaction(function ($db) use ($row) {
            $db->run("DELETE FROM send_queue WHERE contact_id = ? AND status IN ('queued', 'retry', 'cancelled')", [$row['id']]);
            $db->run("UPDATE send_log SET email = '(deleted)' WHERE contact_id = ?", [$row['id']]);
            $db->run('DELETE FROM contacts WHERE id = ?', [$row['id']]);
        });
        echo "deleted contact {$email}\n";
        break;
    case 'batch':
        $batch = $argv[2] ?? exit("batch id required\n");
        $n = $db->run(
            "DELETE c FROM contacts c WHERE c.import_batch = ? AND c.status = 'unsent'
               AND NOT EXISTS (SELECT 1 FROM send_queue q WHERE q.contact_id = c.id AND q.status NOT IN ('queued', 'cancelled'))",
            [$batch]
        )->rowCount();
        $db->run('DELETE q FROM send_queue q LEFT JOIN contacts c ON c.id = q.contact_id WHERE c.id IS NULL');
        echo "deleted {$n} contacts\n";
        break;
    default:
        echo "usage: purge.php logs [days] | contact EMAIL | batch BATCH_ID\n";
        exit(1);
}
