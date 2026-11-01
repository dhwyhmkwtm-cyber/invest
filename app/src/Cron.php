<?php
declare(strict_types=1);

namespace Mailer;

use Mailer\Queue\FollowupScheduler;
use Mailer\Queue\Worker;
use Mailer\Report\Stats;

/**
 * cron 1本で全ての定期処理を回す (ロリポップの cron 登録数の制限に合わせる)。
 *  - 毎回: 送信ワーカー
 *  - 1時間に1回: 追いメールの予約
 *  - 送信が無い時間帯: MXチェック
 *  - 1日1回 (daily_report_hour 以降の最初の実行): 日次レポート
 */
final class Cron
{
    public function __construct(private App $app)
    {
    }

    public function run(): array
    {
        $lockFile = $this->app->varDir() . '/cron.lock';
        $fh = fopen($lockFile, 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            return ['note' => 'another run in progress'];
        }
        try {
            $settings = $this->app->settings();
            $now = $this->app->clock->now();
            $settings->set('cron_last_run', $this->app->clock->nowSql());
            $out = [];
            // 毎朝の判定 (ウォームアップの上限引き上げ) と A/B テストの自動決定は送信の前に行う
            $out['warmup'] = (new Campaign\Warmup($this->app))->runAll();
            foreach ($this->app->db()->all("SELECT id FROM campaigns WHERE status = 'active' AND ab_status = 'testing'") as $c) {
                if (($w = (new Campaign\AbTest($this->app))->maybeDecide((int)$c['id'])) !== null) {
                    $out['ab_decided'][(int)$c['id']] = $w;
                }
            }
            $out['worker'] = (new Worker($this->app))->run();

            $lastFollow = $settings->get('cron_last_followup');
            if ($lastFollow === null || strtotime($lastFollow) < $now->getTimestamp() - 3600) {
                $settings->set('cron_last_followup', $this->app->clock->nowSql());
                $out['followups'] = (new FollowupScheduler($this->app))->run();
            }

            if (($out['worker']['sent'] ?? 0) === 0) {
                $out['mx'] = (new MxChecker($this->app->db(), $this->app->clock))->checkPending(300, 15.0);
            }

            $reportHour = (int)$this->app->config->get('notify.daily_report_hour', 18);
            $today = $now->format('Y-m-d');
            if ((int)$now->format('G') >= $reportHour && $settings->get('cron_last_report') !== $today) {
                $settings->set('cron_last_report', $today);
                $this->sendDailyReport($today);
                $out['report'] = 'sent';
            }
            $this->app->log('cron', json_encode($out, JSON_UNESCAPED_UNICODE));
            return $out;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function sendDailyReport(string $date): void
    {
        $db = $this->app->db();
        $stats = new Stats($db);
        $lines = ['■ 全体', Stats::formatDay($stats->day($date)), ''];
        foreach ($db->all("SELECT id, name, status, daily_limit FROM campaigns WHERE status IN ('active', 'paused') ORDER BY id") as $c) {
            $q = $stats->queueCounts((int)$c['id']);
            $lines[] = "■ {$c['name']} (状態: {$c['status']} / 1日上限 {$c['daily_limit']})";
            $lines[] = Stats::formatDay($stats->day($date, (int)$c['id']));
            $lines[] = sprintf('  キュー: 未送信 %d / 再送待ち %d / 送信済 %d / 失敗 %d / 結果不明 %d', $q['queued'], $q['retry'], $q['sent'], $q['failed'], $q['unknown']);
            $lines[] = '';
        }
        $guard = (new Guard\AutoPause($this->app))->stats();
        $lines[] = sprintf('■ 自動停止の判定 (直近): 送信 %d / バウンス率 %.2f%% / 苦情率 %.3f%%', $guard['sent'], $guard['bounce_rate'] * 100, $guard['complaint_rate'] * 100);
        $settings = $this->app->settings();
        if ($settings->isPaused()) {
            $lines[] = '※ 現在、配信は一時停止中です: ' . $settings->get(Settings::PAUSE_REASON, '');
        }
        $this->app->notifier()->send('【配信ツール】日次レポート ' . $date, implode("\n", $lines));
    }
}
