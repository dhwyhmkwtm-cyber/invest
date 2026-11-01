<?php
declare(strict_types=1);

namespace Mailer\Local;

use Mailer\App;
use Mailer\Sns\SesEventHandler;

/** テスト受信箱の読み出しと、SES イベントの模擬 */
final class LocalMailbox
{
    public function __construct(private App $app)
    {
    }

    public static function isLocal(App $app): bool
    {
        return $app->config->get('aws.mode') === 'local';
    }

    /** 保留中の SES イベント (配信・バウンス・苦情) を発生させる。cron の最初に呼ぶ。 */
    public function flush(): int
    {
        $db = $this->app->db();
        $n = 0;
        foreach ($db->all('SELECT id, message_id, to_email, pending_events FROM local_mailbox WHERE pending_events IS NOT NULL ORDER BY id LIMIT 500') as $m) {
            // 送信ワーカーが SES の ID をキューに記録する前なら、次回に回す
            if ($db->value('SELECT 1 FROM send_queue WHERE ses_message_id = ?', [$m['message_id']]) === null
                && strtotime((string)$db->value('SELECT created_at FROM local_mailbox WHERE id = ?', [$m['id']])) > $this->app->clock->now()->getTimestamp() - 600) {
                continue;
            }
            foreach (explode(',', (string)$m['pending_events']) as $type) {
                $this->emit(trim($type), $m['message_id'], $m['to_email']);
                $n++;
            }
            $db->run('UPDATE local_mailbox SET pending_events = NULL WHERE id = ?', [$m['id']]);
        }
        return $n;
    }

    /** 受信者の行動を模擬する: Open / Click / Complaint / Bounce / Delivery */
    public function emit(string $type, string $messageId, string $email, string $link = 'https://www.example.co.jp/products/'): string
    {
        $payload = ['eventType' => $type, 'mail' => ['messageId' => $messageId, 'destination' => [$email]]];
        $payload += match ($type) {
            'Bounce' => ['bounce' => ['bounceType' => 'Permanent', 'bounceSubType' => 'General',
                'bouncedRecipients' => [['emailAddress' => $email, 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown (ローカル模擬)']]]],
            'Complaint' => ['complaint' => ['complainedRecipients' => [['emailAddress' => $email]], 'complaintFeedbackType' => 'abuse']],
            'Open' => ['open' => ['userAgent' => 'local-simulator']],
            'Click' => ['click' => ['link' => $link]],
            'Delivery' => ['delivery' => ['smtpResponse' => '250 OK (ローカル模擬)']],
            default => [],
        };
        return (new SesEventHandler($this->app))->handle([
            'Type' => 'Notification', 'MessageId' => 'local-evt-' . bin2hex(random_bytes(8)),
            'TopicArn' => 'local', 'Message' => json_encode($payload), 'Timestamp' => gmdate('c'),
        ]);
    }

    public static function subjectOf(string $raw): string
    {
        [$head] = self::split($raw);
        $head = self::unfold($head);
        if (preg_match('/^Subject:[ \t]*([^\r\n]*)/mi', $head, $m)) {
            return self::decode($m[1]);
        }
        return '';
    }

    /** @return array{headers:string, text:string, html:?string} */
    public static function parse(string $raw): array
    {
        [$head, $body] = self::split($raw);
        $text = '';
        $html = null;
        if (preg_match('/boundary="([^"]+)"/i', $head, $b)) {
            foreach (explode('--' . $b[1], $body) as $part) {
                [$ph, $pb] = self::split(ltrim($part, "\r\n"));
                $decoded = stripos($ph, 'base64') !== false ? base64_decode(preg_replace('/\s+/', '', $pb) ?? '') : $pb;
                if (stripos($ph, 'text/plain') !== false) {
                    $text = (string)$decoded;
                } elseif (stripos($ph, 'text/html') !== false) {
                    $html = (string)$decoded;
                }
            }
        } else {
            $text = stripos($head, 'base64') !== false ? (string)base64_decode(preg_replace('/\s+/', '', $body) ?? '') : $body;
        }
        $headers = preg_replace('/^Content-(Type|Transfer-Encoding):[^\r\n]*/mi', '', self::unfold($head)) ?? $head;
        $headers = implode("\r\n", array_map([self::class, 'decode'], explode("\r\n", $headers)));
        return ['headers' => trim(preg_replace("/(\r\n)+/", "\n", $headers) ?? $headers), 'text' => str_replace("\r\n", "\n", $text), 'html' => $html];
    }

    /** 折り返されたヘッダー行を1行に戻す */
    private static function unfold(string $head): string
    {
        return preg_replace('/\r\n[ \t]+/', ' ', $head) ?? $head;
    }

    /** =?UTF-8?B?...?= を日本語に戻す (隣り合うエンコード語の間の空白は詰める) */
    private static function decode(string $v): string
    {
        $v = preg_replace('/\?=\s+=\?/', '?==?', $v) ?? $v;
        return preg_replace_callback('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/i', static fn($m) => (string)base64_decode($m[1]), $v) ?? $v;
    }

    /** @return array{0:string,1:string} */
    private static function split(string $s): array
    {
        $pos = strpos($s, "\r\n\r\n");
        return $pos === false ? [$s, ''] : [substr($s, 0, $pos), substr($s, $pos + 4)];
    }
}
