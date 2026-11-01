<?php
declare(strict_types=1);

// 自宅・ローカル体験モード (aws.mode = 'local')

use Mailer\Local\LocalMailbox;
use Mailer\Queue\Enqueuer;

return [
    'ローカルモード: メールは外に出ずテスト受信箱へ、SESシミュレーター宛ては本物と同じ結果' => function () {
        [$app, $t] = fresh_app(['aws.mode' => 'local', 'environment' => 'local']);
        seed_contacts($app, 2);
        // シミュレーターの2アドレスは同じドメインなので、URL列で別会社にする (サンプルCSVと同じ)
        (new Mailer\Import\ContactImporter($app->db(), $app->clock, $app->suppressions()))->import([
            ['会社名', 'メールアドレス', 'URL', '取得元', '取得日'],
            ['バウンス試験', 'bounce@simulator.amazonses.com', 'https://bounce.test', 'SESシミュレーター', '2026-11-01'],
            ['苦情試験', 'complaint@simulator.amazonses.com', 'https://complaint.test', 'SESシミュレーター', '2026-11-01'],
        ]);
        $app->db()->run("UPDATE contacts SET mx_status = 'ok'");
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        eq(0, count($t->requests), '外部への通信はゼロ');
        eq('4', (string)$app->db()->value('SELECT COUNT(*) FROM local_mailbox WHERE is_campaign = 1'));
        // 通知は次の cron で届く (本物の SES と同じく非同期)
        ok(!$app->suppressions()->isSuppressed('bounce@simulator.amazonses.com'));
        eq(5, (new LocalMailbox($app))->flush()); // 配信3件 (通常2 + 苦情先1) + バウンス1 + 苦情1
        eq('hard_bounce', $app->suppressions()->find('bounce@simulator.amazonses.com')['reason']);
        eq('complaint', $app->suppressions()->find('complaint@simulator.amazonses.com')['reason']);
        eq('3', (string)$app->db()->value("SELECT COUNT(*) FROM events WHERE type = 'delivery'"));
        eq(0, (new LocalMailbox($app))->flush(), '二度処理しない');
        // 受信者の行動を模擬
        $m = $app->db()->one("SELECT * FROM local_mailbox WHERE to_email = 'info@co1.example.jp'");
        eq('click', (new LocalMailbox($app))->emit('Click', $m['message_id'], $m['to_email']));
        eq('clicked', $app->db()->value("SELECT status FROM contacts WHERE email = 'info@co1.example.jp'"));
        $parsed = LocalMailbox::parse($m['raw']);
        ok(str_contains($parsed['headers'], 'Subject: 石綿廃棄物用袋のご案内'));
        ok(str_contains($parsed['text'], '配信停止: https://news.example.co.jp/mailer/u.php?q='));
    },
    'ローカルモード: テスト用ドメイン (.example 等) はMXチェックで受信可能扱い、本番では不可' => function () {
        [$app] = fresh_app();
        $local = new Mailer\MxChecker($app->db(), $app->clock, true);
        $prod = new Mailer\MxChecker($app->db(), $app->clock, false);
        ok($local->domainAcceptsMail('kaitai1.example'));
        ok(!$prod->domainAcceptsMail('kaitai1.example'));
        ok($local->domainAcceptsMail('simulator.amazonses.com'));
    },
];
