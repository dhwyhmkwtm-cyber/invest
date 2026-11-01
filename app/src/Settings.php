<?php
declare(strict_types=1);

namespace Mailer;

/** DBに保存する実行時設定 (全体の一時停止フラグなど) */
final class Settings
{
    public const PAUSED = 'global_paused';
    public const PAUSE_REASON = 'global_pause_reason';

    public function __construct(private Db $db, private Clock $clock)
    {
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $v = $this->db->value('SELECT value FROM settings WHERE name = ?', [$name]);
        return $v === null ? $default : (string)$v;
    }

    public function set(string $name, ?string $value): void
    {
        $this->db->run(
            'INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, $value, $this->clock->nowSql()]
        );
    }

    public function isPaused(): bool
    {
        return $this->get(self::PAUSED, '0') === '1';
    }

    public function pause(string $reason): void
    {
        $this->set(self::PAUSED, '1');
        $this->set(self::PAUSE_REASON, $this->clock->nowSql() . ' ' . $reason);
    }

    public function resume(): void
    {
        $this->set(self::PAUSED, '0');
        $this->set(self::PAUSE_REASON, null);
    }
}
