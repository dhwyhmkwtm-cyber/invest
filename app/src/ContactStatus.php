<?php
declare(strict_types=1);

namespace Mailer;

/**
 * 宛先の状態。反応が進むほど順位が上がり、バウンス/苦情/配信停止は終端 (以後送信しない)。
 */
final class ContactStatus
{
    public const LABELS = [
        'unsent'       => '未送信',
        'sent'         => '送信済',
        'opened'       => '開封',
        'clicked'      => 'クリック',
        'replied'      => '返信',
        'bounced'      => 'バウンス',
        'complained'   => '苦情',
        'unsubscribed' => '配信停止',
    ];

    private const RANK = ['unsent' => 0, 'sent' => 1, 'opened' => 2, 'clicked' => 3, 'replied' => 4];
    public const TERMINAL = ['bounced', 'complained', 'unsubscribed'];

    /** 状態を進める (後退はしない。終端状態は上書きしない。終端への遷移は常に行う) */
    public static function advance(Db $db, Clock $clock, int $contactId, string $to): void
    {
        $terminal = "'" . implode("','", self::TERMINAL) . "'";
        if (in_array($to, self::TERMINAL, true)) {
            // 苦情 > 配信停止 > バウンス の順で優先
            $db->run(
                "UPDATE contacts SET status = ?, updated_at = ? WHERE id = ? AND (status NOT IN ({$terminal}) OR ? = 'complained')",
                [$to, $clock->nowSql(), $contactId, $to]
            );
            return;
        }
        $rank = self::RANK[$to] ?? null;
        if ($rank === null) {
            throw new \InvalidArgumentException('unknown status ' . $to);
        }
        $lower = array_keys(array_filter(self::RANK, static fn(int $r) => $r < $rank));
        if ($lower === []) {
            return;
        }
        $in = "'" . implode("','", $lower) . "'";
        $db->run(
            "UPDATE contacts SET status = ?, updated_at = ? WHERE id = ? AND status IN ({$in})",
            [$to, $clock->nowSql(), $contactId]
        );
    }
}
