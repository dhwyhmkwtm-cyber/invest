<?php
declare(strict_types=1);

namespace Mailer\Queue;

use Mailer\App;

/**
 * 追いメールの予約 (F4)。
 * 初回送信から N 営業日以上経ち、反応 (クリック・返信・停止・苦情・バウンス) が無い宛先に1通だけ。
 */
final class FollowupScheduler
{
    public function __construct(private App $app)
    {
    }

    /** @return array<int,int> campaign_id => 予約件数 */
    public function run(): array
    {
        $db = $this->app->db();
        $cfg = $this->app->config;
        $window = new SendWindow((array)$cfg->get('sending.holidays', []));
        $enq = new Enqueuer($db, $this->app->clock, $cfg);
        $statuses = $cfg->get('sending.followup_skip_if_opened', false) ? "('sent')" : "('sent', 'opened')";
        $out = [];
        $campaigns = $db->all("SELECT * FROM campaigns WHERE status = 'active' AND followup_enabled = 1");
        foreach ($campaigns as $c) {
            $templates = $enq->activeTemplateIds((int)$c['id'], 2);
            if ($templates === []) {
                continue;
            }
            $cutoff = $window->subtractBusinessDays($this->app->clock->now(), max(7, (int)$c['followup_days']));
            $rows = $db->all(
                "SELECT c.id, c.email_domain FROM send_queue q JOIN contacts c ON c.id = q.contact_id
                  WHERE q.campaign_id = ? AND q.step = 1 AND q.status = 'sent' AND q.sent_at < ?
                    AND c.status IN {$statuses}
                    AND " . Enqueuer::eligibilitySql((bool)$cfg->get('sending.require_mx_ok', true)) . "
                    AND NOT EXISTS (SELECT 1 FROM send_queue q2 WHERE q2.campaign_id = q.campaign_id AND q2.contact_id = q.contact_id AND q2.step = 2)
                    AND NOT EXISTS (SELECT 1 FROM events e WHERE e.contact_id = c.id AND e.type IN ('click', 'reply', 'unsubscribe', 'complaint', 'bounce_hard', 'reply_refusal'))
                  ORDER BY q.sent_at",
                [$c['id'], $cutoff->modify('+1 day')->format('Y-m-d 00:00:00')]
            );
            $out[(int)$c['id']] = $enq->insertRows((int)$c['id'], 2, $templates, Enqueuer::spreadByDomain($rows));
        }
        return $out;
    }
}
