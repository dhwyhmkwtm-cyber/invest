<?php
declare(strict_types=1);

namespace Mailer;

use Mailer\Util\Email;

/**
 * グローバル抑止リスト。一度登録された宛先は、どのリストに再登録されても送信しない。
 */
final class SuppressionList
{
    public const REASONS = [
        'unsubscribe'   => '配信停止',
        'complaint'     => '迷惑メール報告',
        'hard_bounce'   => '永久バウンス',
        'no_ad'         => 'お断り表示',
        'reply_refusal' => '返信で不要',
        'manual'        => '手動登録',
    ];

    public function __construct(private Db $db, private Clock $clock)
    {
    }

    /** @return bool 新規登録なら true */
    public function add(string $type, string $value, string $reason, string $source, ?string $note = null): bool
    {
        if (!in_array($type, ['email', 'domain'], true)) {
            throw new \InvalidArgumentException('type must be email or domain');
        }
        if (!isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException('unknown reason: ' . $reason);
        }
        $value = strtolower(trim($value));
        if ($type === 'email') {
            $norm = Email::normalize($value);
            if ($norm === null) {
                throw new \InvalidArgumentException('メールアドレスの形式が不正です: ' . $value);
            }
            $value = $norm;
        } else {
            $value = ltrim($value, '@');
            if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $value)) {
                throw new \InvalidArgumentException('ドメインの形式が不正です: ' . $value);
            }
        }
        $st = $this->db->run(
            'INSERT IGNORE INTO suppressions (type, value, reason, note, source, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$type, $value, $reason, $note, $source, $this->clock->nowSql()]
        );
        $added = $st->rowCount() > 0;
        // 未送信キューを取り消す
        $this->cancelPending($type, $value);
        return $added;
    }

    public function isSuppressed(string $email): bool
    {
        $email = strtolower($email);
        return $this->db->value(
            "SELECT 1 FROM suppressions WHERE (type = 'email' AND value = ?) OR (type = 'domain' AND value = ?) LIMIT 1",
            [$email, Email::domain($email)]
        ) !== null;
    }

    public function find(string $email): ?array
    {
        $email = strtolower($email);
        return $this->db->one(
            "SELECT * FROM suppressions WHERE (type = 'email' AND value = ?) OR (type = 'domain' AND value = ?) ORDER BY id LIMIT 1",
            [$email, Email::domain($email)]
        );
    }

    private function cancelPending(string $type, string $value): void
    {
        $col = $type === 'email' ? 'c.email' : 'c.email_domain';
        $this->db->run(
            "UPDATE send_queue q JOIN contacts c ON c.id = q.contact_id
                SET q.status = 'cancelled', q.last_error = 'suppressed', q.updated_at = ?
              WHERE {$col} = ? AND q.status IN ('queued', 'retry')",
            [$this->clock->nowSql(), $value]
        );
    }
}
