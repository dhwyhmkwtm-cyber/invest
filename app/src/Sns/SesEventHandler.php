<?php
declare(strict_types=1);

namespace Mailer\Sns;

use Mailer\App;
use Mailer\ContactStatus;
use Mailer\Util\Email;

/**
 * SES の通知 (SNS 経由) を処理する (F6)。
 * - 永久バウンス・苦情 → 即座に抑止リストへ
 * - 一時バウンス → 時間をあけて最大3回まで再送
 * - 配信・開封・クリック → 記録と状態更新
 *
 * 「通知 (notificationType)」と「イベント発行 (eventType)」のどちらの形式にも対応する。
 */
final class SesEventHandler
{
    public function __construct(private App $app)
    {
    }

    /** @param array $sns SNS の外側のメッセージ (署名検証済み) */
    public function handle(array $sns): string
    {
        $payload = json_decode((string)($sns['Message'] ?? ''), true);
        if (!is_array($payload)) {
            return 'ignored: not json';
        }
        $type = (string)($payload['eventType'] ?? $payload['notificationType'] ?? '');
        $mail = $payload['mail'] ?? [];
        $messageId = (string)($mail['messageId'] ?? '');
        $dedupe = 'sns:' . ($sns['MessageId'] ?? sha1((string)$sns['Message']));

        $db = $this->app->db();
        if ($db->value('SELECT 1 FROM events WHERE dedupe_key = ?', [$dedupe]) !== null) {
            return 'duplicate';
        }
        $queue = $messageId !== ''
            ? $db->one('SELECT * FROM send_queue WHERE ses_message_id = ?', [$messageId])
            : null;

        switch ($type) {
            case 'Bounce':
                return $this->bounce($payload, $queue, $messageId, $dedupe);
            case 'Complaint':
                return $this->complaint($payload, $queue, $messageId, $dedupe);
            case 'Delivery':
                $this->record('delivery', $queue, $messageId, $dedupe, $payload['delivery'] ?? []);
                return 'delivery';
            case 'Send':
                $this->record('send', $queue, $messageId, $dedupe, []);
                return 'send';
            case 'Open':
                $this->record('open', $queue, $messageId, $dedupe, ['ua' => $payload['open']['userAgent'] ?? null]);
                if ($queue) {
                    ContactStatus::advance($db, $this->app->clock, (int)$queue['contact_id'], 'opened');
                }
                return 'open';
            case 'Click':
                $link = (string)($payload['click']['link'] ?? '');
                $this->record('click', $queue, $messageId, $dedupe, ['link' => $link]);
                if ($queue && !str_contains($link, '/u.php')) {
                    ContactStatus::advance($db, $this->app->clock, (int)$queue['contact_id'], 'clicked');
                }
                return 'click';
            case 'Reject':
            case 'RenderingFailure':
                $this->record('reject', $queue, $messageId, $dedupe, $payload['reject'] ?? $payload['failure'] ?? []);
                if ($queue) {
                    $db->run("UPDATE send_queue SET status = 'failed', last_error = ?, updated_at = ? WHERE id = ?",
                        ['SES ' . $type, $this->app->clock->nowSql(), $queue['id']]);
                }
                return 'reject';
            case 'DeliveryDelay':
                $this->record('delay', $queue, $messageId, $dedupe, $payload['deliveryDelay'] ?? []);
                return 'delay';
            default:
                $this->record(strtolower($type ?: 'unknown'), $queue, $messageId, $dedupe, []);
                return 'ignored: ' . $type;
        }
    }

    private function bounce(array $p, ?array $queue, string $messageId, string $dedupe): string
    {
        $b = $p['bounce'] ?? [];
        $bounceType = (string)($b['bounceType'] ?? 'Undetermined');
        $recipients = array_filter(array_map(
            static fn($r) => Email::normalize((string)($r['emailAddress'] ?? '')),
            (array)($b['bouncedRecipients'] ?? [])
        ));
        $detail = ['bounceType' => $bounceType, 'subType' => $b['bounceSubType'] ?? null, 'recipients' => array_values($recipients),
            'diagnostic' => $b['bouncedRecipients'][0]['diagnosticCode'] ?? null];
        $db = $this->app->db();
        $clock = $this->app->clock;

        if ($bounceType === 'Permanent') {
            $this->record('bounce_hard', $queue, $messageId, $dedupe, $detail);
            foreach ($recipients as $email) {
                $this->app->suppressions()->add('email', $email, 'hard_bounce', 'sns', (string)($b['bounceSubType'] ?? ''));
                $c = $db->one('SELECT id FROM contacts WHERE email = ?', [$email]);
                if ($c) {
                    ContactStatus::advance($db, $clock, (int)$c['id'], 'bounced');
                }
            }
            return 'bounce_hard';
        }

        // 一時エラー: 時間をあけて最大 max_attempts 回まで再送
        $this->record('bounce_soft', $queue, $messageId, $dedupe, $detail);
        if ($queue && $queue['status'] === 'sent') {
            $max = (int)$this->app->config->get('sending.max_attempts', 3);
            if ((int)$queue['attempts'] < $max) {
                $delays = (array)$this->app->config->get('sending.retry_delay_minutes', [30, 180, 720]);
                $delay = max(60, (int)($delays[(int)$queue['attempts']] ?? 720));
                $db->run(
                    "UPDATE send_queue SET status = 'retry', scheduled_at = ?, last_error = ?, updated_at = ?
                      WHERE id = ? AND status = 'sent'",
                    [$clock->now()->modify("+{$delay} minutes")->format('Y-m-d H:i:s'), 'soft bounce: ' . ($b['bounceSubType'] ?? ''), $clock->nowSql(), $queue['id']]
                );
                return 'bounce_soft: retry scheduled';
            }
            $db->run("UPDATE send_queue SET status = 'failed', last_error = 'soft bounce: max attempts', updated_at = ? WHERE id = ?",
                [$clock->nowSql(), $queue['id']]);
        }
        return 'bounce_soft';
    }

    private function complaint(array $p, ?array $queue, string $messageId, string $dedupe): string
    {
        $c = $p['complaint'] ?? [];
        $recipients = array_filter(array_map(
            static fn($r) => Email::normalize((string)($r['emailAddress'] ?? '')),
            (array)($c['complainedRecipients'] ?? [])
        ));
        if ($recipients === [] && $queue) {
            $email = $this->app->db()->value('SELECT email FROM contacts WHERE id = ?', [$queue['contact_id']]);
            if ($email) {
                $recipients = [(string)$email];
            }
        }
        $this->record('complaint', $queue, $messageId, $dedupe, ['type' => $c['complaintFeedbackType'] ?? null, 'recipients' => array_values($recipients)]);
        foreach ($recipients as $email) {
            $this->app->suppressions()->add('email', $email, 'complaint', 'sns');
            $row = $this->app->db()->one('SELECT id FROM contacts WHERE email = ?', [$email]);
            if ($row) {
                ContactStatus::advance($this->app->db(), $this->app->clock, (int)$row['id'], 'complained');
            }
        }
        return 'complaint';
    }

    private function record(string $type, ?array $queue, string $messageId, string $dedupe, array $detail): void
    {
        $now = $this->app->clock->nowSql();
        $this->app->db()->run(
            'INSERT IGNORE INTO events (ses_message_id, queue_id, contact_id, campaign_id, type, occurred_at, detail, dedupe_key, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$messageId ?: null, $queue['id'] ?? null, $queue['contact_id'] ?? null, $queue['campaign_id'] ?? null,
             $type, $now, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $dedupe, $now]
        );
    }
}
