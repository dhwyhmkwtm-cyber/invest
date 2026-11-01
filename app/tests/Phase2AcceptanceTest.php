<?php
declare(strict_types=1);

// フェーズ2 受け入れ基準:
//  社内アドレス50件への送信で、二重送信ゼロ、配信停止が即時反映、バウンス・苦情のテスト通知が抑止リストに入る

use Mailer\Queue\Enqueuer;
use Mailer\Sns\SesEventHandler;
use Mailer\UnsubscribeService;

function sns_notification(array $payload, ?string $id = null): array
{
    return ['Type' => 'Notification', 'MessageId' => $id ?? bin2hex(random_bytes(8)), 'TopicArn' => 'arn:aws:sns:ap-northeast-1:123456789012:mailer',
        'Message' => json_encode($payload), 'Timestamp' => '2026-11-04T01:00:00.000Z'];
}

return [
    '50件送信: 全員に1通ずつ・二重送信ゼロ (cronを何度回しても)' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 50);
        $cid = seed_campaign($app);
        $r = (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        eq(50, $r['queued']);
        // 再投入しても増えない (1社1通)
        eq(0, (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid)['queued']);
        for ($i = 0; $i < 5; $i++) {
            worker($app);
        }
        $to = array_map([FakeTransport::class, 'recipient'], $t->sends());
        eq(50, count($to));
        eq(50, count(array_unique($to)), '同じ宛先に2通以上送っていない');
        eq('50', (string)$app->db()->value("SELECT COUNT(*) FROM send_queue WHERE status = 'sent' AND ses_message_id IS NOT NULL"));
        eq('50', (string)$app->db()->value("SELECT COUNT(*) FROM send_log WHERE result = 'sent'"));
        // MIMEに法定表示・ワンクリック停止ヘッダーが入っている
        $mime = FakeTransport::mime($t->sends()[0]);
        ok(str_contains($mime, 'List-Unsubscribe: <https://news.example.co.jp/mailer/u.php?q='));
        ok(str_contains($mime, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'));
        ok(str_contains(json_decode($t->sends()[0]['body'], true)['ConfigurationSetName'], 'mailer-events'));
    },
    '途中で落ちた送信中の行は再送せず「結果不明」にする' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 3);
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        // ワーカーが SES 呼び出し後にクラッシュした状況を再現
        $app->db()->run("UPDATE send_queue SET status = 'sending', locked_at = ?, attempts = 1 WHERE seq = 1", ['2026-11-04 09:40:00']);
        worker($app);
        eq(2, count($t->sends()), 'クラッシュした行は送らない');
        eq('unknown', $app->db()->value('SELECT status FROM send_queue WHERE seq = 1'));
    },
    '通信タイムアウト (届いたか不明) は再送しない / 接続失敗は再送待ち' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 2);
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        $t->queue[] = new Mailer\Ses\TransportException('timeout', true);
        worker($app);
        eq('unknown', $app->db()->value('SELECT status FROM send_queue WHERE seq = 1'));
        eq('sent', $app->db()->value('SELECT status FROM send_queue WHERE seq = 2'));

        [$app, $t] = fresh_app();
        seed_contacts($app, 1);
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        $t->queue[] = new Mailer\Ses\TransportException('connect failed', false);
        worker($app);
        eq('retry', $app->db()->value('SELECT status FROM send_queue'));
        eq('2026-11-04 10:30:00', $app->db()->value('SELECT scheduled_at FROM send_queue'));
    },
    'SESのスロットリングは再送待ち、3回失敗で失敗確定' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 1);
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        $err = ['status' => 429, 'body' => '{"message":"Maximum sending rate exceeded."}', 'headers' => ['x-amzn-errortype' => 'TooManyRequestsException']];
        foreach (['10:00', '10:31', '13:32'] as $i => $at) {
            $app->clock->freeze(new DateTimeImmutable("2026-11-04 {$at}:00"));
            $t->queue[] = $err;
            worker($app);
        }
        eq('failed', $app->db()->value('SELECT status FROM send_queue'));
        eq('3', (string)$app->db()->value('SELECT attempts FROM send_queue'));
    },
    '配信停止 (ワンクリック/リンク) は即時反映され、以後送られない' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 50);
        $cid = seed_campaign($app, ['daily_limit' => 10]);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        eq(10, count($t->sends()));
        // 送信済みの1件目の受信者がワンクリック停止
        $first = $app->db()->one("SELECT id, contact_id FROM send_queue WHERE status = 'sent' ORDER BY seq LIMIT 1");
        $svc = new UnsubscribeService($app->db(), $app->clock, $app->suppressions());
        $email = $svc->unsubscribeByQueue((int)$first['id'], 'one_click');
        ok($app->suppressions()->isSuppressed($email));
        eq('unsubscribed', $app->db()->value('SELECT status FROM contacts WHERE id = ?', [$first['contact_id']]));
        // まだ送っていない宛先を停止 → キューから即取り消し
        $pending = $app->db()->one("SELECT q.id, c.email FROM send_queue q JOIN contacts c ON c.id = q.contact_id WHERE q.status = 'queued' ORDER BY seq LIMIT 1");
        $app->suppressions()->add('email', $pending['email'], 'unsubscribe', 'test');
        eq('cancelled', $app->db()->value('SELECT status FROM send_queue WHERE id = ?', [$pending['id']]));
        // 別キャンペーン・別リストで再登録しても送らない
        $app->db()->run("UPDATE contacts SET status = 'unsent' WHERE email = ?", [$email]); // 取り込み直しで状態が戻ったと仮定
        $cid2 = seed_campaign($app, ['name' => '第2弾']);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid2);
        eq(null, $app->db()->value('SELECT 1 FROM send_queue q JOIN contacts c ON c.id = q.contact_id WHERE q.campaign_id = ? AND c.email = ?', [$cid2, $email]));
        $app->db()->run("UPDATE campaigns SET daily_limit = 1000");
        worker($app);
        $all = array_map([FakeTransport::class, 'recipient'], $t->sends());
        eq(1, count(array_keys($all, $email)), '停止後は一切送られない');
        ok(!in_array($pending['email'], $all, true));
    },
    '永久バウンス・苦情のSES通知が抑止リストに入る / 一時バウンスは再送予約' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 3);
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        $rows = $app->db()->all("SELECT q.id, q.ses_message_id, c.email FROM send_queue q JOIN contacts c ON c.id = q.contact_id ORDER BY q.seq");
        $h = new SesEventHandler($app);
        // SES メールボックスシミュレーター (bounce@ / complaint@) と同じ形式
        eq('bounce_hard', $h->handle(sns_notification(['notificationType' => 'Bounce', 'mail' => ['messageId' => $rows[0]['ses_message_id']],
            'bounce' => ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'bouncedRecipients' => [['emailAddress' => $rows[0]['email']]]]])));
        eq('complaint', $h->handle(sns_notification(['eventType' => 'Complaint', 'mail' => ['messageId' => $rows[1]['ses_message_id']],
            'complaint' => ['complainedRecipients' => [['emailAddress' => $rows[1]['email']]], 'complaintFeedbackType' => 'abuse']], 'dup-1')));
        // 同じ通知の再送は二重処理しない
        eq('duplicate', $h->handle(sns_notification(['eventType' => 'Complaint', 'mail' => ['messageId' => $rows[1]['ses_message_id']]], 'dup-1')));
        eq('bounce_soft: retry scheduled', $h->handle(sns_notification(['notificationType' => 'Bounce', 'mail' => ['messageId' => $rows[2]['ses_message_id']],
            'bounce' => ['bounceType' => 'Transient', 'bounceSubType' => 'MailboxFull', 'bouncedRecipients' => [['emailAddress' => $rows[2]['email']]]]])));

        $sup = $app->suppressions();
        eq('hard_bounce', $sup->find($rows[0]['email'])['reason']);
        eq('complaint', $sup->find($rows[1]['email'])['reason']);
        ok(!$sup->isSuppressed($rows[2]['email']), '一時バウンスは抑止しない');
        eq('bounced', $app->db()->value('SELECT status FROM contacts WHERE email = ?', [$rows[0]['email']]));
        eq('complained', $app->db()->value('SELECT status FROM contacts WHERE email = ?', [$rows[1]['email']]));
        eq('retry', $app->db()->value('SELECT status FROM send_queue WHERE id = ?', [$rows[2]['id']]));
        // 開封・クリックで状態が進む
        $h->handle(sns_notification(['eventType' => 'Open', 'mail' => ['messageId' => $rows[2]['ses_message_id']], 'open' => []]));
        eq('opened', $app->db()->value('SELECT status FROM contacts WHERE email = ?', [$rows[2]['email']]));
    },
    '取得元なし・お断り表示・MX不良・書式不正の宛先には送らない' => function () {
        [$app, $t] = fresh_app();
        $r = seed_contacts($app, 2, 'ok%d.example.jp', [
            ['無記録社', 'info@nosrc.example.jp', '', '', '', '', ''],
            ['お断り社', 'info@noad.example.jp', '', '', '', 'https://noad.example.jp/', '2026-10-01', '', '', '', '', 'あり'],
            ['不正社', 'not-an-email', '', '', '', 'https://x/', '2026-10-01'],
            ['重複社', 'INFO@ok1.example.jp', '山田', '', '', 'https://ok1.example.jp/', '2026-10-01'],
        ]);
        eq(4, $r['inserted']);
        eq(1, $r['merged']);
        eq(1, $r['invalid']);
        eq(1, $r['missing_source']);
        // 見出しが7列しかないので「お断り」列は無視される → ヘッダーに列を足して再取り込み
        (new Mailer\Import\ContactImporter($app->db(), $app->clock, $app->suppressions()))->import([
            ['会社名', 'メールアドレス', '取得元', '取得日', 'お断り'],
            ['お断り社', 'info@noad.example.jp', 'https://noad.example.jp/', '2026-10-01', 'あり'],
        ]);
        eq('no_ad', $app->suppressions()->find('info@noad.example.jp')['reason']);
        $app->db()->run("UPDATE contacts SET mx_status = 'ng' WHERE email = 'info@ok2.example.jp'");
        $cid = seed_campaign($app);
        eq(1, (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid)['queued']);
        worker($app);
        eq(['info@ok1.example.jp'], array_map([FakeTransport::class, 'recipient'], $t->sends()));
    },
    '1社につき1通 (窓口アドレスを優先)' => function () {
        [$app, $t] = fresh_app();
        (new Mailer\Import\ContactImporter($app->db(), $app->clock, $app->suppressions()))->import([
            ['会社名', 'メールアドレス', 'URL', '取得元', '取得日'],
            ['A社', 'yamada@a.example.jp', 'https://a.example.jp', 'https://a.example.jp/staff', '2026-10-01'],
            ['A社', 'info@a.example.jp', 'https://a.example.jp', 'https://a.example.jp/company', '2026-10-01'],
            ['A社', 'support@a.example.jp', 'https://a.example.jp', 'https://a.example.jp/company', '2026-10-01'],
        ]);
        $app->db()->run("UPDATE contacts SET mx_status = 'ok'");
        $cid = seed_campaign($app);
        eq(1, (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid)['queued']);
        worker($app);
        eq(['info@a.example.jp'], array_map([FakeTransport::class, 'recipient'], $t->sends()));
    },
    '1日上限・時間帯の外では送らない' => function () {
        [$app, $t] = fresh_app([], '2026-11-04 18:00:00');
        seed_contacts($app, 30);
        $cid = seed_campaign($app, ['daily_limit' => 12]);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        eq(0, count($t->sends()), '17時以降は送らない');
        $app->clock->freeze(new DateTimeImmutable('2026-11-05 09:30:00'));
        worker($app);
        worker($app);
        eq(12, count($t->sends()), '1日上限');
        $app->clock->freeze(new DateTimeImmutable('2026-11-06 09:30:00'));
        worker($app);
        eq(24, count($t->sends()), '翌日はまた上限まで');
    },
    '全体の一時停止中は送らない' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 5);
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        $app->settings()->pause('test');
        worker($app);
        eq(0, count($t->sends()));
    },
    '苦情率が0.1%を超えたら自動停止して管理者に通知' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 200);
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        $app->db()->run("UPDATE campaigns SET daily_limit = 150");
        worker($app);
        eq(150, count($t->sends()));
        $rows = $app->db()->all("SELECT q.ses_message_id, c.email FROM send_queue q JOIN contacts c ON c.id = q.contact_id WHERE q.status = 'sent' LIMIT 1");
        (new SesEventHandler($app))->handle(sns_notification(['notificationType' => 'Complaint', 'mail' => ['messageId' => $rows[0]['ses_message_id']],
            'complaint' => ['complainedRecipients' => [['emailAddress' => $rows[0]['email']]]]]));
        $app->db()->run("UPDATE campaigns SET daily_limit = 1000");
        $before = count($t->requests);
        worker($app); // 送信後の判定で停止 (1/150 = 0.67%)
        ok($app->settings()->isPaused(), '自動停止されている');
        $notify = array_filter(array_slice($t->requests, $before), fn($r) => !isset(json_decode($r['body'], true)['ConfigurationSetName']));
        ok(count($notify) >= 1, '管理者に通知');
        $sent = count($t->sends());
        worker($app);
        eq($sent, count($t->sends()), '停止後は送らない');
        // 再開すると集計がリセットされ、再び送れる
        (new Mailer\Guard\AutoPause($app))->resume();
        ok(!$app->settings()->isPaused());
        ok(!(new Mailer\Guard\AutoPause($app))->stats()['tripped']);
    },
    '追いメール: 7営業日後、反応のない宛先にだけ1通' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 3);
        $cid = seed_campaign($app, ['followup_enabled' => 1, 'followup_days' => 7]);
        $now = $app->clock->nowSql();
        $app->db()->insert('templates', ['campaign_id' => $cid, 'step' => 2, 'variant' => 'A', 'subject' => '先日のご案内について',
            'body_text' => '再送のご案内', 'body_html' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        $rows = $app->db()->all("SELECT q.id, q.ses_message_id, c.id cid FROM send_queue q JOIN contacts c ON c.id = q.contact_id ORDER BY q.seq");
        (new SesEventHandler($app))->handle(sns_notification(['eventType' => 'Click', 'mail' => ['messageId' => $rows[0]['ses_message_id']], 'click' => ['link' => 'https://shop/']]));
        // 6営業日後: まだ
        $app->clock->freeze(new DateTimeImmutable('2026-11-12 10:00:00'));
        eq([$cid => 0], (new Mailer\Queue\FollowupScheduler($app))->run());
        // 7営業日後: クリックした1件を除く2件
        $app->clock->freeze(new DateTimeImmutable('2026-11-13 10:00:00'));
        eq([$cid => 2], (new Mailer\Queue\FollowupScheduler($app))->run());
        eq([$cid => 0], (new Mailer\Queue\FollowupScheduler($app))->run(), '追いメールは1通まで');
        worker($app);
        eq(5, count($t->sends()));
    },
    '1年分の送信ログ: 宛先・テンプレート・日時・SES ID・結果を記録' => function () {
        [$app, $t] = fresh_app();
        seed_contacts($app, 1);
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        $log = $app->db()->one('SELECT * FROM send_log');
        eq('info@co1.example.jp', $log['email']);
        ok($log['template_id'] > 0 && str_starts_with((string)$log['ses_message_id'], 'msg-') && $log['result'] === 'sent');
        eq('2026-11-04 10:00:00', $log['created_at']);
    },
    '社内テスト: 同じドメインでもURL列が別なら別会社として1通ずつ送る' => function () {
        [$app, $t] = fresh_app();
        $rows = [['会社名', 'メールアドレス', 'URL', '取得元', '取得日']];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ["社内テスト{$i}", "user{$i}@corp.example.jp", sprintf('https://t%02d.test.invalid', $i), '社内名簿', '2026-11-01'];
        }
        (new Mailer\Import\ContactImporter($app->db(), $app->clock, $app->suppressions()))->import($rows);
        $app->db()->run("UPDATE contacts SET mx_status = 'ok'");
        $cid = seed_campaign($app);
        eq(5, (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid)['queued']);
        worker($app);
        eq(5, count($t->sends()));
    },
    'テストモード: 許可した宛先以外には送らない (許可リストが空なら一切送らない)' => function () {
        [$app, $t] = fresh_app(['environment' => 'staging', 'sending.allowed_recipients' => ['@co1.example.jp', 'info@co3.example.jp']]);
        seed_contacts($app, 4);
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        $to = array_map([FakeTransport::class, 'recipient'], $t->sends());
        sort($to);
        eq(['info@co1.example.jp', 'info@co3.example.jp'], $to);
        eq('2', (string)$app->db()->value("SELECT COUNT(*) FROM send_queue WHERE status = 'cancelled' AND last_error LIKE 'blocked by test mode%'"));

        [$app, $t] = fresh_app(['environment' => 'staging', 'sending.allowed_recipients' => []]);
        seed_contacts($app, 2);
        $cid = seed_campaign($app);
        (new Enqueuer($app->db(), $app->clock, $app->config))->enqueueInitial($cid);
        worker($app);
        eq(0, count($t->sends()));
    },
];
