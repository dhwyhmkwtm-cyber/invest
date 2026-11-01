<?php
declare(strict_types=1);

namespace Mailer\Guard;

use Mailer\App;

/**
 * 自動停止 (F6): 直近の苦情率・バウンス率が閾値を超えたら全体を一時停止し、管理者に通知する。
 * 管理者が再開すると、その時点から集計し直す (過去の数値で即再停止しないため)。
 */
final class AutoPause
{
    public const BASELINE = 'guard_baseline_at';

    public function __construct(private App $app)
    {
    }

    /** @return array{sent:int, bounces:int, complaints:int, bounce_rate:float, complaint_rate:float, judged:bool, tripped:bool} */
    public function stats(): array
    {
        $db = $this->app->db();
        $cfg = $this->app->config;
        $from = $this->app->clock->now()->modify('-' . (int)$cfg->get('guard.window_days', 7) . ' days')->format('Y-m-d H:i:s');
        $baseline = $this->app->settings()->get(self::BASELINE);
        $op = '>=';
        if ($baseline !== null && $baseline >= $from) {
            // 再開した時点より後の送信だけを数える
            $from = $baseline;
            $op = '>';
        }
        $sent = (int)$db->value("SELECT COUNT(*) FROM send_queue WHERE status = 'sent' AND sent_at {$op} ?", [$from]);
        $bounces = (int)$db->value(
            "SELECT COUNT(DISTINCT e.queue_id) FROM events e JOIN send_queue q ON q.id = e.queue_id
              WHERE e.type = 'bounce_hard' AND q.sent_at {$op} ?",
            [$from]
        );
        $complaints = (int)$db->value(
            "SELECT COUNT(DISTINCT e.queue_id) FROM events e JOIN send_queue q ON q.id = e.queue_id
              WHERE e.type = 'complaint' AND q.sent_at {$op} ?",
            [$from]
        );
        $br = $sent > 0 ? $bounces / $sent : 0.0;
        $cr = $sent > 0 ? $complaints / $sent : 0.0;
        $judged = $sent >= (int)$cfg->get('guard.min_sample', 100);
        $tripped = $judged && ($cr > (float)$cfg->get('guard.max_complaint_rate', 0.001) || $br > (float)$cfg->get('guard.max_bounce_rate', 0.05));
        return [
            'sent' => $sent, 'bounces' => $bounces, 'complaints' => $complaints,
            'bounce_rate' => $br, 'complaint_rate' => $cr, 'judged' => $judged, 'tripped' => $tripped,
        ];
    }

    public function check(): bool
    {
        $s = $this->stats();
        if (!$s['tripped']) {
            return false;
        }
        $settings = $this->app->settings();
        if ($settings->isPaused()) {
            return true;
        }
        $msg = sprintf(
            '直近の苦情率 %.3f%% (%d件) / バウンス率 %.2f%% (%d件) / 送信 %d件 が閾値を超えたため、配信を自動停止しました。',
            $s['complaint_rate'] * 100, $s['complaints'], $s['bounce_rate'] * 100, $s['bounces'], $s['sent']
        );
        $settings->pause($msg);
        $this->app->notifier()->alert('autopause', $msg . "\nリストの質・文面を見直してから管理画面で再開してください。", true);
        return true;
    }

    /** 管理者による再開。集計の起点をリセットする。 */
    public function resume(): void
    {
        $this->app->settings()->resume();
        $this->app->settings()->set(self::BASELINE, $this->app->clock->nowSql());
    }
}
