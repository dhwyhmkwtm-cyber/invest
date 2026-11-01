<?php
declare(strict_types=1);

namespace Mailer\Report;

use Mailer\Db;

/** 日次レポート・A/B比較の集計 (F7) */
final class Stats
{
    private const EVENT_TYPES = ['delivery', 'open', 'click', 'reply', 'bounce_hard', 'bounce_soft', 'complaint', 'unsubscribe'];

    public function __construct(private Db $db)
    {
    }

    /** 1日分 (送信日ではなく発生日ベース) */
    public function day(string $date, ?int $campaignId = null): array
    {
        $from = $date . ' 00:00:00';
        $to = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
        $campSql = $campaignId ? ' AND campaign_id = ' . (int)$campaignId : '';
        $row = ['date' => $date];
        $row['sent'] = (int)$this->db->value("SELECT COUNT(*) FROM send_log WHERE result = 'sent' AND created_at >= ? AND created_at < ?{$campSql}", [$from, $to]);
        $row['failed'] = (int)$this->db->value("SELECT COUNT(*) FROM send_log WHERE result IN ('failed', 'unknown') AND created_at >= ? AND created_at < ?{$campSql}", [$from, $to]);
        $counts = $this->db->all(
            "SELECT type, COUNT(DISTINCT COALESCE(queue_id, id)) AS n FROM events
              WHERE occurred_at >= ? AND occurred_at < ?{$campSql} GROUP BY type",
            [$from, $to]
        );
        foreach (self::EVENT_TYPES as $t) {
            $row[$t] = 0;
        }
        foreach ($counts as $c) {
            if (isset($row[$c['type']])) {
                $row[$c['type']] = (int)$c['n'];
            }
        }
        $row['unsubscribe'] += (int)$this->db->value(
            "SELECT COUNT(*) FROM events WHERE type = 'reply_refusal' AND occurred_at >= ? AND occurred_at < ?{$campSql}", [$from, $to]
        );
        $row['bounce_rate'] = $row['sent'] ? $row['bounce_hard'] / $row['sent'] : 0.0;
        $row['complaint_rate'] = $row['sent'] ? $row['complaint'] / $row['sent'] : 0.0;
        $row['open_rate'] = $row['sent'] ? $row['open'] / $row['sent'] : 0.0;
        $row['click_rate'] = $row['sent'] ? $row['click'] / $row['sent'] : 0.0;
        return $row;
    }

    /** @return list<array> 直近 n 日 (新しい順) */
    public function days(string $today, int $n = 30, ?int $campaignId = null): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->day(date('Y-m-d', strtotime($today . " -{$i} day")), $campaignId);
        }
        return $out;
    }

    /** A/Bパターンごとの成績 (送信ベース) */
    public function variants(int $campaignId): array
    {
        return $this->db->all(
            "SELECT t.id, t.step, t.variant, t.subject,
                    COUNT(DISTINCT q.id) AS sent,
                    COUNT(DISTINCT CASE WHEN e.type = 'open' THEN q.id END) AS opened,
                    COUNT(DISTINCT CASE WHEN e.type = 'click' THEN q.id END) AS clicked,
                    COUNT(DISTINCT CASE WHEN c.status = 'replied' THEN q.id END) AS replied,
                    COUNT(DISTINCT CASE WHEN e.type = 'bounce_hard' THEN q.id END) AS bounced,
                    COUNT(DISTINCT CASE WHEN e.type = 'complaint' THEN q.id END) AS complained,
                    COUNT(DISTINCT CASE WHEN e.type = 'unsubscribe' THEN q.id END) AS unsubscribed
               FROM templates t
               LEFT JOIN send_queue q ON q.template_id = t.id AND q.status = 'sent'
               LEFT JOIN contacts c ON c.id = q.contact_id
               LEFT JOIN events e ON e.queue_id = q.id
              WHERE t.campaign_id = ?
              GROUP BY t.id, t.step, t.variant, t.subject
              ORDER BY t.step, t.variant",
            [$campaignId]
        );
    }

    /** @return array<string,int> */
    public function queueCounts(?int $campaignId = null): array
    {
        $rows = $campaignId
            ? $this->db->all('SELECT status, COUNT(*) n FROM send_queue WHERE campaign_id = ? GROUP BY status', [$campaignId])
            : $this->db->all('SELECT status, COUNT(*) n FROM send_queue GROUP BY status');
        $out = ['queued' => 0, 'retry' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'unknown' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    public static function formatDay(array $r): string
    {
        return sprintf(
            "%s  送信 %d / 到達 %d / 開封 %d (%.1f%%) / クリック %d (%.1f%%) / 返信 %d / バウンス %d (%.2f%%) / 苦情 %d (%.3f%%) / 配信停止 %d / 失敗 %d",
            $r['date'], $r['sent'], $r['delivery'], $r['open'], $r['open_rate'] * 100, $r['click'], $r['click_rate'] * 100,
            $r['reply'], $r['bounce_hard'], $r['bounce_rate'] * 100, $r['complaint'], $r['complaint_rate'] * 100, $r['unsubscribe'], $r['failed']
        );
    }
}
