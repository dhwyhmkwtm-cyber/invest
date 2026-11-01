<?php
declare(strict_types=1);

namespace Mailer;

use Mailer\Util\Email;

/** 配信停止の受付 (本文リンク・ワンクリック・管理画面の手動登録) */
final class UnsubscribeService
{
    public function __construct(private Db $db, private Clock $clock, private SuppressionList $suppressions)
    {
    }

    public function queueRow(int $queueId): ?array
    {
        return $this->db->one(
            'SELECT q.id, q.campaign_id, q.contact_id, q.ses_message_id, c.email, c.email_domain
               FROM send_queue q JOIN contacts c ON c.id = q.contact_id WHERE q.id = ?',
            [$queueId]
        );
    }

    /**
     * @param bool $wholeDomain 会社 (ドメイン) 全体を停止
     */
    public function unsubscribeByQueue(int $queueId, string $via, bool $wholeDomain = false): ?string
    {
        $row = $this->queueRow($queueId);
        if (!$row) {
            return null;
        }
        $this->suppressions->add('email', $row['email'], 'unsubscribe', $via);
        if ($wholeDomain && !Email::isSharedDomain($row['email_domain'])) {
            $this->suppressions->add('domain', $row['email_domain'], 'unsubscribe', $via, '配信停止ページで会社全体の停止を選択');
        }
        ContactStatus::advance($this->db, $this->clock, (int)$row['contact_id'], 'unsubscribed');
        $this->recordEvent($row, 'unsubscribe', ['via' => $via, 'domain' => $wholeDomain]);
        return $row['email'];
    }

    /** 管理画面: 返信で「不要」と言われた宛先を停止登録 */
    public function refuseByEmail(string $email, string $actor, string $reason = 'reply_refusal', bool $wholeDomain = false): void
    {
        $norm = Email::normalize($email) ?? throw new \InvalidArgumentException('アドレス不正');
        $this->suppressions->add('email', $norm, $reason, 'admin:' . $actor);
        if ($wholeDomain && !Email::isSharedDomain(Email::domain($norm))) {
            $this->suppressions->add('domain', Email::domain($norm), $reason, 'admin:' . $actor);
        }
        $contact = $this->db->one('SELECT id FROM contacts WHERE email = ?', [$norm]);
        if ($contact) {
            ContactStatus::advance($this->db, $this->clock, (int)$contact['id'], 'unsubscribed');
            $this->recordEvent(['contact_id' => $contact['id'], 'campaign_id' => null, 'id' => null, 'ses_message_id' => null], 'reply_refusal', ['actor' => $actor]);
        }
    }

    private function recordEvent(array $row, string $type, array $detail): void
    {
        $this->db->insert('events', [
            'ses_message_id' => $row['ses_message_id'] ?? null,
            'queue_id' => $row['id'] ?? null,
            'contact_id' => $row['contact_id'] ?? null,
            'campaign_id' => $row['campaign_id'] ?? null,
            'type' => $type,
            'occurred_at' => $this->clock->nowSql(),
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            'dedupe_key' => null,
            'created_at' => $this->clock->nowSql(),
        ]);
    }
}
