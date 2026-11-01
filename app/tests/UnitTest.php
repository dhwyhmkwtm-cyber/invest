<?php
declare(strict_types=1);

use Mailer\Config;
use Mailer\Queue\Enqueuer;
use Mailer\Queue\SendWindow;
use Mailer\Ses\MimeBuilder;
use Mailer\Ses\SigV4Signer;
use Mailer\Template\TemplateRenderer;
use Mailer\Template\TemplateValidator;
use Mailer\Util\Email;

return [
    'SigV4: AWS公式テストスイート get-vanilla と一致する' => function () {
        $s = new SigV4Signer('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service');
        $h = $s->sign('GET', 'https://example.amazonaws.com/', [], '', new DateTimeImmutable('2015-08-30T12:36:00Z'));
        eq('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $h['Authorization']);
    },
    'SigV4: 署名鍵の導出がAWSドキュメント例と一致する' => function () {
        $s = new SigV4Signer('x', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'iam');
        eq('c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9', bin2hex($s->signingKey('20150830')));
    },
    'Email: 正規化と書式不正の除外' => function () {
        eq('info@example.co.jp', Email::normalize(' ＩＮＦＯ＠example.co.jp '));
        eq('sales@example.jp', Email::normalize('mailto:Sales@Example.jp'));
        eq(null, Email::normalize('info@example'));
        eq(null, Email::normalize('foo bar@example.jp'));
        eq(null, Email::normalize('info@@example.jp'));
        ok(Email::isRole('info@a.jp') && Email::isRole('sales2@a.jp') && !Email::isRole('yamada@a.jp'));
    },
    'テンプレート: 法定表示と配信停止URLが必ず末尾に付く' => function () {
        $r = new TemplateRenderer(test_config());
        $out = $r->render(['subject' => '{{会社名}}様へ', 'body_text' => '{{担当者名|ご担当者}} 様', 'body_html' => '<p>{{会社名}}</p>'],
            ['company_name' => '<A&B>建設', 'person_name' => ''], 'https://x/u.php?q=1&t=abc');
        eq('<A&B>建設様へ', $out['subject']);
        ok(str_starts_with($out['text'], 'ご担当者 様'));
        foreach (['テスト資材株式会社', '〒100-0001', '03-0000-0000', 'sales@example.co.jp', '配信停止: https://x/u.php?q=1&t=abc'] as $must) {
            ok(str_contains($out['text'], $must), "text に {$must} が無い");
        }
        ok(str_contains($out['html'], '&lt;A&amp;B&gt;建設'), 'HTMLはエスケープされる');
        ok(str_contains($out['html'], 'ses:no-track href="https://x/u.php?q=1&amp;t=abc"'), 'HTMLにも配信停止リンク');
    },
    'テンプレート: 送信者情報が欠けていると送信できない' => function () {
        $r = new TemplateRenderer(test_config(['sender.address' => '']));
        try {
            $r->render(['subject' => 's', 'body_text' => 'b'], [], 'u');
            ok(false, '例外が出るべき');
        } catch (RuntimeException $e) {
            ok(str_contains($e->getMessage(), '住所'));
        }
    },
    'テンプレート: Re: 偽装・誇大表現・未知の差し込みは保存できない' => function () {
        ok(TemplateValidator::validate(['subject' => 'Re: お見積り', 'body_text' => 'x']) !== []);
        ok(TemplateValidator::validate(['subject' => 'RE：ご連絡', 'body_text' => 'x']) !== []);
        ok(TemplateValidator::validate(['subject' => '【重要】お知らせ', 'body_text' => 'x']) !== []);
        ok(TemplateValidator::validate(['subject' => 'ご案内', 'body_text' => '{{社長名}}']) !== []);
        ok(TemplateValidator::validate(['subject' => 'ご案内', 'body_text' => '']) !== []);
        eq([], TemplateValidator::validate(['subject' => '石綿廃棄物用袋のご案内', 'body_text' => '{{会社名}} {{担当者名|ご担当者}} 様']));
    },
    'MIME: 日本語件名のエンコードとヘッダーインジェクション防止' => function () {
        $m = MimeBuilder::build('info@news.example.jp', '営業部', 'to@example.jp', '石綿の袋', "本文\n2行目", null, 'r@example.jp',
            ['List-Unsubscribe' => '<https://x/u>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
        ok(str_contains($m, 'Subject: =?UTF-8?B?'));
        ok(str_contains($m, "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n"));
        ok(str_contains($m, 'Reply-To: r@example.jp'));
        [$head, $body] = explode("\r\n\r\n", $m, 2);
        eq("本文\r\n2行目", base64_decode(str_replace("\r\n", '', $body)));
        try {
            MimeBuilder::build('a@b.jp', '', "x@y.jp\r\nBcc: evil@z.jp", 's', 't', null);
            ok(false, '改行入りヘッダーは拒否されるべき');
        } catch (InvalidArgumentException) {
        }
    },
    '送信順: 同じドメインが連続しない' => function () {
        $rows = [];
        foreach (['a.jp' => 5, 'b.jp' => 5, 'c.jp' => 4, 'd.jp' => 1] as $d => $n) {
            for ($i = 0; $i < $n; $i++) {
                $rows[] = ['id' => count($rows) + 1, 'email_domain' => $d];
            }
        }
        $out = Enqueuer::spreadByDomain($rows);
        eq(count($rows), count($out));
        for ($i = 1; $i < count($out); $i++) {
            ok($out[$i]['email_domain'] !== $out[$i - 1]['email_domain'], "位置{$i}で {$out[$i]['email_domain']} が連続");
        }
    },
    '送信時間帯: 平日9-17時のみ、祝日除外' => function () {
        $w = new SendWindow(['2026-11-03']);
        $c = ['send_days' => '1,2,3,4,5', 'send_window_start' => '09:00:00', 'send_window_end' => '17:00:00'];
        $tz = new DateTimeZone('Asia/Tokyo');
        ok($w->isOpen($c, new DateTimeImmutable('2026-11-04 09:00', $tz)));
        ok(!$w->isOpen($c, new DateTimeImmutable('2026-11-04 08:59', $tz)));
        ok(!$w->isOpen($c, new DateTimeImmutable('2026-11-04 17:00', $tz)));
        ok(!$w->isOpen($c, new DateTimeImmutable('2026-11-07 10:00', $tz)), '土曜');
        ok(!$w->isOpen($c, new DateTimeImmutable('2026-11-03 10:00', $tz)), '祝日');
        eq('2026-11-04', $w->subtractBusinessDays(new DateTimeImmutable('2026-11-13 10:00', $tz), 7)->format('Y-m-d'), '7営業日前');
    },
    'IP制限: CIDR 判定' => function () {
        ok(Mailer\Admin\Auth::ipMatch('203.0.113.5', '203.0.113.0/24'));
        ok(!Mailer\Admin\Auth::ipMatch('203.0.114.5', '203.0.113.0/24'));
        ok(Mailer\Admin\Auth::ipMatch('10.0.0.1', '10.0.0.1'));
        ok(!Mailer\Admin\Auth::ipMatch('10.0.0.2', '10.0.0.1'));
    },
    '配信停止トークン: 改ざんを検出' => function () {
        $t = new Mailer\UnsubscribeToken(str_repeat('k', 40), 'https://x');
        ok($t->verify(12, $t->token(12)));
        ok(!$t->verify(13, $t->token(12)));
        ok(!$t->verify(12, 'AAAA'));
        ok(str_starts_with($t->url(5), 'https://x/u.php?q=5&t='));
    },
    'CSV読み込み: Shift_JIS も読める' => function () {
        $f = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($f, mb_convert_encoding("会社名,メールアドレス\n株式会社テスト,info@t.jp\n", 'SJIS-win', 'UTF-8'));
        $rows = Mailer\Import\SpreadsheetReader::readCsv($f);
        eq('会社名', $rows[0][0]);
        eq('info@t.jp', $rows[1][1]);
        unlink($f);
    },
];
