<?php
declare(strict_types=1);

// フェーズ3 受け入れ基準:
//  法定表示のないテンプレートは保存できない。閾値超過で翌日の上限が上がらない。

use Mailer\Campaign\AbTest;
use Mailer\Campaign\Warmup;
use Mailer\Queue\Enqueuer;
use Mailer\Sns\SesEventHandler;

function at(Mailer\App $app, string $dt): void
{
    $app->clock->freeze(new DateTimeImmutable($dt));
}

/** その日の朝に判定し、日中に送信する */
function business_day(Mailer\App $app, string $date): ?string
{
    at($app, "{$date} 08:50:00");
    $c = $app->db()->one('SELECT * FROM campaigns ORDER BY id LIMIT 1');
    $r = (new Warmup($app))->evaluate($c);
    at($app, "{$date} 10:00:00");
    worker($app);
    return $r;
}

function campaign_row(Mailer\App $app): array
{
    return $app->db()->one('SELECT * FROM campaigns ORDER BY id LIMIT 1');
}

/** その日送った分のうち n 件を永久バウンスにする */
function bounce_some(Mailer\App $app, string $date, int $n, string $type = 'Bounce'): void
{
    $rows = $app->db()->all("SELECT q.ses_message_id, c.email FROM send_queue q JOIN contacts c ON c.id = q.contact_id
                              WHERE q.status = 'sent' AND DATE(q.sent_at) = ? ORDER BY q.id LIMIT {$n}", [$date]);
    foreach ($rows as $r) {
        $payload = $type === 'Bounce'
            ? ['notificationType' => 'Bounce', 'mail' => ['messageId' => $r['ses_message_id']],
                'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => $r['email']]]]]
            : ['notificationType' => 'Complaint', 'mail' => ['messageId' => $r['ses_message_id']],
                'complaint' => ['complainedRecipients' => [['emailAddress' => $r['email']]]]];
        (new SesEventHandler($app))->handle(sns_notification($payload));
    }
}

function warm_setup(int $contacts = 2000): array
{
    [$app, $t] = fresh_app(['guard.min_sample' => 100000], '2026-11-04 08:00:00');
    seed_contacts($app, $contacts);
    $cid = seed_campaign($app, ['daily_limit' => 99999]);
    (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
    return [$app, $t, $cid];
}

return [
    'ウォームアップ: 初日は200件、3営業日問題なければ500件に上がる' => function () {
        [$app, $t] = warm_setup();
        eq('start', business_day($app, '2026-11-04'));
        eq(200, count($t->sends()), '初日は200件');
        eq('stay', business_day($app, '2026-11-05'));
        eq('stay', business_day($app, '2026-11-06'));
        eq(600, count($t->sends()));
        // 土日は判定も送信もしない
        at($app, '2026-11-07 08:50:00');
        eq(null, (new Warmup($app))->evaluate(campaign_row($app)));
        eq('advance', business_day($app, '2026-11-09'));
        eq(500, (int)campaign_row($app)['daily_limit']);
        eq(1100, count($t->sends()));
        $log = $app->db()->all('SELECT result, limit_after FROM warmup_log ORDER BY id');
        eq(['start', 'stay', 'stay', 'advance'], array_column($log, 'result'));
    },
    '【受け入れ基準】閾値超過で翌日の上限が上がらない / 2日続いたら停止' => function () {
        [$app, $t] = warm_setup();
        business_day($app, '2026-11-04');
        business_day($app, '2026-11-05');
        business_day($app, '2026-11-06');           // 3日目まで200件
        bounce_some($app, '2026-11-06', 4);          // 4/200 = 2.0% (2%未満でないので超過)
        eq('hold', business_day($app, '2026-11-09')); // 本来は500に上がる日
        $c = campaign_row($app);
        eq(200, (int)$c['daily_limit'], '上限は据え置き');
        eq(0, (int)$c['warmup_step']);
        eq(800, count($t->sends()));
        bounce_some($app, '2026-11-09', 1);          // 1件だけ (0.5%) → 正常に戻る
        eq('advance', business_day($app, '2026-11-10'));
        eq(500, (int)campaign_row($app)['daily_limit']);
        // 苦情が2日続く → 停止
        $app->db()->run('UPDATE campaigns SET warmup_days_at_step = 0');
        bounce_some($app, '2026-11-10', 1, 'Complaint');
        eq('hold', business_day($app, '2026-11-11'));
        $sent = count($t->sends());
        $rows = $app->db()->all("SELECT q.ses_message_id, c.email FROM send_queue q JOIN contacts c ON c.id = q.contact_id
                                  WHERE q.status = 'sent' AND DATE(q.sent_at) = '2026-11-11' ORDER BY q.id DESC LIMIT 1");
        (new SesEventHandler($app))->handle(sns_notification(['notificationType' => 'Complaint', 'mail' => ['messageId' => $rows[0]['ses_message_id']],
            'complaint' => ['complainedRecipients' => [['emailAddress' => $rows[0]['email']]]]]));
        eq('pause', business_day($app, '2026-11-12'));
        eq('paused', campaign_row($app)['status']);
        eq($sent, count($t->sends()), '停止したので送っていない');
        $notify = array_filter($t->requests, fn($r) => !isset(json_decode($r['body'], true)['ConfigurationSetName'])
            && str_contains(FakeTransport::mime($r), 'Subject:'));
        ok(count($notify) >= 1, '管理者に通知');
    },
    'ウォームアップ: 送信が上限の半分未満の日は日数に数えない' => function () {
        [$app, $t] = warm_setup(250);   // 宛先が足りない
        business_day($app, '2026-11-04'); // 200
        eq('stay', business_day($app, '2026-11-05')); // 50件送る
        eq('low_volume', business_day($app, '2026-11-06'));
        eq(1, (int)campaign_row($app)['warmup_days_at_step']);
    },
    'ウォームアップ: 1日に何度 cron が動いても判定は1回' => function () {
        [$app, $t] = warm_setup();
        business_day($app, '2026-11-04');
        at($app, '2026-11-05 08:50:00');
        eq('stay', (new Warmup($app))->evaluate(campaign_row($app)));
        at($app, '2026-11-05 09:30:00');
        eq(null, (new Warmup($app))->evaluate(campaign_row($app)));
        eq(1, (int)campaign_row($app)['warmup_days_at_step']);
    },
    'A/B: 各パターン規定数に達したら成績の良い方に決定し、未送信を付け替える' => function () {
        [$app, $t] = fresh_app(['guard.min_sample' => 100000], '2026-11-04 10:00:00');
        seed_contacts($app, 1000);
        $cid = seed_campaign($app, ['daily_limit' => 400]);
        $now = $app->clock->nowSql();
        $b = $app->db()->insert('templates', ['campaign_id' => $cid, 'step' => 1, 'variant' => 'B', 'subject' => '石綿含有廃棄物の梱包資材',
            'body_text' => 'B本文', 'body_html' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $app->db()->run('UPDATE campaigns SET ab_min_per_variant = 200 WHERE id = ?', [$cid]);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        eq(400, count($t->sends()));
        eq([1 => 200, $b => 200], array_map('intval', array_column($app->db()->all("SELECT template_id, COUNT(*) n FROM send_queue WHERE status = 'sent' GROUP BY template_id"), 'n', 'template_id')), 'A/Bは均等');
        // B のほうがクリックが多い
        foreach ($app->db()->all("SELECT ses_message_id FROM send_queue WHERE status = 'sent' AND template_id = ? LIMIT 10", [$b]) as $r) {
            (new SesEventHandler($app))->handle(sns_notification(['eventType' => 'Click', 'mail' => ['messageId' => $r['ses_message_id']], 'click' => ['link' => 'https://shop/']]));
        }
        eq(null, (new AbTest($app))->maybeDecide($cid), '24時間経つまでは決めない');
        at($app, '2026-11-05 10:30:00');
        eq($b, (new AbTest($app))->maybeDecide($cid));
        eq('decided', campaign_row($app)['ab_status']);
        eq('0', (string)$app->db()->value('SELECT active FROM templates WHERE id = 1'));
        eq('0', (string)$app->db()->value("SELECT COUNT(*) FROM send_queue WHERE status = 'queued' AND template_id <> ?", [$b]), '未送信はすべてBに');
        worker($app);
        $last = FakeTransport::mime(array_slice($t->sends(), -1)[0]);
        ok(str_contains(base64_decode(preg_replace('/\s+/', '', explode("\r\n\r\n", $last, 2)[1])), 'B本文'));
    },
    'A/B: 件数が足りなくても段階3に入った時点で決定する' => function () {
        [$app, $t] = fresh_app(['guard.min_sample' => 100000, 'warmup.plan' => [[1, 10], [1, 20], [0, 50]]], '2026-11-04 08:00:00');
        seed_contacts($app, 200);
        $cid = seed_campaign($app);
        $now = $app->clock->nowSql();
        $app->db()->insert('templates', ['campaign_id' => $cid, 'step' => 1, 'variant' => 'B', 'subject' => '別件名', 'body_text' => 'B',
            'body_html' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        business_day($app, '2026-11-04');
        business_day($app, '2026-11-05');
        eq('testing', campaign_row($app)['ab_status']);
        business_day($app, '2026-11-06'); // 段階3へ
        eq('decided', campaign_row($app)['ab_status']);
        eq(50, (int)campaign_row($app)['daily_limit']);
    },
    '【受け入れ基準】法定表示はテンプレートから外せない (保存内容に関係なく必ず付く)' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 1);
        $cid = seed_campaign($app);
        $app->db()->run("UPDATE templates SET body_text = '本文のみ。住所も配信停止も書いていない', body_html = '<p>HTML本文</p>'");
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        $mime = FakeTransport::mime($t->sends()[0]);
        preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/', $mime, $m);
        foreach ($m[1] as $part) {
            $body = base64_decode(preg_replace('/\s+/', '', $part));
            foreach (['テスト資材株式会社', '〒100-0001', '03-0000-0000', 'sales@example.co.jp', '/u.php?q='] as $must) {
                ok(str_contains($body, $must), "本文パートに {$must} が無い");
            }
        }
        eq(2, count($m[1]), 'テキストとHTMLの両方');
    },
    '取り込み直後のキュー: 自社収集の宛先を先に送る' => function () {
        [$app, $t] = fresh_app();
        $imp = new Mailer\Import\ContactImporter($app->db(), $app->clock, $app->suppressions());
        $imp->import([['会社名', 'メールアドレス', '取得元', '取得日'], ['収集1', 'info@agent1.example.jp', 'https://agent1.example.jp', '2026-10-01'],
            ['収集2', 'info@agent2.example.jp', 'https://agent2.example.jp', '2026-10-01']], 'agent');
        $imp->import([['会社名', 'メールアドレス', '取得元', '取得日'], ['自社1', 'info@own1.example.jp', '名簿', '2026-10-01']], 'own');
        $app->db()->run("UPDATE contacts SET mx_status = 'ok'");
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        eq('info@own1.example.jp', FakeTransport::recipient($t->sends()[0]));
    },
    'マイグレーション: 何度実行しても安全' => function () {
        [$app] = fresh_app();
        $m = new Mailer\Migrator($app->db(), $app->clock);
        eq([], $m->migrate());
        eq([], $m->migrate());
        ok($app->db()->value("SHOW COLUMNS FROM campaigns LIKE 'warmup_step'") !== null);
    },
];
