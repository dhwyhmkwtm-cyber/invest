<?php
// フェーズ1の受け入れ確認用: 1通だけテスト送信する (法定表示・配信停止ヘッダー付き)
//   php app/bin/test_send.php you@gmail.com
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$to = Mailer\Util\Email::normalize($argv[1] ?? '') ?? exit("usage: php test_send.php TO_ADDRESS\n");
$app = mailer_app();
$cfg = $app->config;
$r = new Mailer\Template\TemplateRenderer($cfg);
$unsub = rtrim((string)$cfg->get('public_base_url'), '/') . '/u.php?test=1';
$c = $r->render(['subject' => 'テスト送信 (配信ツール)', 'body_text' => "{{会社名|テスト}} 様\n\nこれは送信基盤のテストです。", 'body_html' => null], [], $unsub);
$mime = Mailer\Ses\MimeBuilder::build((string)$cfg->get('sender.from_email'), (string)$cfg->get('sender.from_name'), $to,
    $c['subject'], $c['text'], null, (string)$cfg->get('sender.reply_to') ?: null,
    ['List-Unsubscribe' => '<' . $unsub . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'], $app->clock->now());
$id = $app->ses()->sendRaw((string)$cfg->get('sender.from_email'), $to, $mime, (string)$cfg->get('aws.configuration_set') ?: null);
echo "OK MessageId={$id}\n受信箱で「メッセージのソースを表示」し、SPF / DKIM / DMARC が pass か確認してください。\n";
