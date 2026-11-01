<?php
declare(strict_types=1);

namespace Mailer\Campaign;

use Mailer\App;
use Mailer\Queue\SendWindow;

/**
 * ウォームアップ管理 (F5)。要件定義書 第7章の計画どおりに1日の上限を自動で引き上げる。
 *
 * 毎朝 (その日最初の cron で) 直近の送信日の成績を判定する:
 *  - バウンス率 2%未満 かつ 苦情率 0.1%未満 → その段階の日数を1日進め、規定日数に達したら次の段階へ
 *  - 閾値を超えた → 上限を据え置き (段階を進めない)
 *  - 2日続けて超えた → キャンペーンを一時停止し、管理者に通知
 *  - 送信数が上限の半分未満の日 (宛先不足など) → 段階の日数に数えない
 */
final class Warmup
{
    /** [その段階の営業日数 (0=最終段階), 1日の上限] */
    public const DEFAULT_PLAN = [[3, 200], [3, 500], [4, 1000], [5, 2000], [0, 3000]];

    public function __construct(private App $app)
    {
    }

    /** @return list<array{0:int,1:int}> */
    public function plan(): array
    {
        $plan = (array)$this->app->config->get('warmup.plan', self::DEFAULT_PLAN);
        return array_values(array_map(static fn($p) => [(int)$p[0], (int)$p[1]], $plan)) ?: self::DEFAULT_PLAN;
    }

    public function limitFor(int $step): int
    {
        $plan = $this->plan();
        return $plan[min($step, count($plan) - 1)][1];
    }

    /** @return array<int,string> campaign_id => 判定結果 */
    public function runAll(): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT * FROM campaigns WHERE status = 'active' AND warmup_enabled = 1") as $c) {
            $r = $this->evaluate($c);
            if ($r !== null) {
                $out[(int)$c['id']] = $r;
            }
        }
        return $out;
    }

    /** @return string|null 判定結果 (今日すでに判定済み・送信しない日なら null) */
    public function evaluate(array $c): ?string
    {
        $db = $this->app->db();
        $cfg = $this->app->config;
        $now = $this->app->clock->now();
        $today = $now->format('Y-m-d');
        if ((string)$c['warmup_last_eval'] === $today) {
            return null;
        }
        $window = new SendWindow((array)$cfg->get('sending.holidays', []));
        // 送信しない日 (土日祝など) は判定しない
        $probe = $c;
        $probe['send_window_start'] = '00:00:00';
        $probe['send_window_end'] = '23:59:59';
        if (!$window->isOpen($probe, $now)) {
            return null;
        }
        $plan = $this->plan();
        $step = min((int)$c['warmup_step'], count($plan) - 1);
        $days = (int)$c['warmup_days_at_step'];
        $bad = (int)$c['warmup_bad_days'];
        $id = (int)$c['id'];

        // 判定材料: 今日より前で、まだ判定していない最新の送信日
        $target = $db->value(
            "SELECT DATE(created_at) d FROM send_log WHERE campaign_id = ? AND result = 'sent' AND created_at < ?
              GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 1",
            [$id, $today . ' 00:00:00']
        );
        $alreadyJudged = $target !== null && $db->value('SELECT 1 FROM warmup_log WHERE campaign_id = ? AND target_date = ?', [$id, $target]) !== null;

        if ($target === null || $alreadyJudged) {
            $result = $target === null ? 'start' : 'stay';
            $limit = $plan[$step][1];
            $this->save($c, $today, null, 0, 0, 0, $result, $step, $step, $days, $bad, $limit,
                $target === null ? '配信開始。段階1の上限を設定' : '前回判定後に送信がないため据え置き');
            return $result;
        }

        $from = $target . ' 00:00:00';
        $to = date('Y-m-d 00:00:00', strtotime($target . ' +1 day'));
        $sent = (int)$db->value("SELECT COUNT(*) FROM send_log WHERE campaign_id = ? AND result = 'sent' AND created_at >= ? AND created_at < ?", [$id, $from, $to]);
        $count = static fn(string $type) => (int)$db->value(
            "SELECT COUNT(DISTINCT e.queue_id) FROM events e JOIN send_queue q ON q.id = e.queue_id
              WHERE q.campaign_id = ? AND e.type = ? AND q.sent_at >= ? AND q.sent_at < ?",
            [$id, $type, $from, $to]
        );
        $bounces = $count('bounce_hard');
        $complaints = $count('complaint');
        $br = $sent ? $bounces / $sent : 0.0;
        $cr = $sent ? $complaints / $sent : 0.0;
        $maxB = (float)$cfg->get('warmup.max_bounce_rate', 0.02);
        $maxC = (float)$cfg->get('warmup.max_complaint_rate', 0.001);
        $limitBefore = $plan[$step][1];
        $stepBefore = $step;
        $rates = sprintf('送信 %d / バウンス %d (%.2f%%) / 苦情 %d (%.3f%%)', $sent, $bounces, $br * 100, $complaints, $cr * 100);

        if ($br >= $maxB || $cr >= $maxC) {
            $bad++;
            if ($bad >= 2) {
                $result = 'pause';
                $db->run("UPDATE campaigns SET status = 'paused', updated_at = ? WHERE id = ?", [$this->app->clock->nowSql(), $id]);
                $this->app->notifier()->alert('warmup-' . $id,
                    "キャンペーン「{$c['name']}」は2日続けてウォームアップの閾値 (バウンス率" . ($maxB * 100) . "%・苦情率" . ($maxC * 100) . "%) を超えたため一時停止しました。\n"
                    . "{$target}: {$rates}\nリストの質・文面を見直してから管理画面で再開してください。", true);
            } else {
                $result = 'hold';
            }
        } else {
            $bad = 0;
            if ($sent < $limitBefore * 0.5) {
                $result = 'low_volume';
            } else {
                $days++;
                $result = 'stay';
                [$stepDays] = $plan[$step];
                if ($stepDays > 0 && $days >= $stepDays && $step < count($plan) - 1) {
                    $step++;
                    $days = 0;
                    $result = 'advance';
                }
            }
        }
        $notes = [
            'advance' => "段階" . ($stepBefore + 1) . "→" . ($step + 1) . " に引き上げ。{$rates}",
            'stay' => "段階" . ($step + 1) . " の {$days}日目を完了。{$rates}",
            'hold' => "閾値超過のため上限を据え置き (1日目)。{$rates}",
            'pause' => "2日続けて閾値超過のため一時停止。{$rates}",
            'low_volume' => "送信が上限の半分未満のため日数に数えない。{$rates}",
        ];
        $this->save($c, $today, $target, $sent, $bounces, $complaints, $result, $stepBefore, $step, $days, $bad, $plan[$step][1], $notes[$result]);
        return $result;
    }

    private function save(array $c, string $today, ?string $target, int $sent, int $bounces, int $complaints, string $result,
        int $stepBefore, int $step, int $days, int $bad, int $limit, string $note): void
    {
        $db = $this->app->db();
        $now = $this->app->clock->nowSql();
        $db->run(
            'UPDATE campaigns SET warmup_step = ?, warmup_days_at_step = ?, warmup_bad_days = ?, warmup_last_eval = ?, daily_limit = ?, updated_at = ? WHERE id = ?',
            [$step, $days, $bad, $today, $limit, $now, $c['id']]
        );
        $db->run(
            'INSERT IGNORE INTO warmup_log (campaign_id, eval_date, target_date, sent, bounces, complaints, result, step_before, step_after, limit_after, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$c['id'], $today, $target, $sent, $bounces, $complaints, $result, $stepBefore, $step, $limit, $note, $now]
        );
        // 段階3以降はA/Bテストの勝ちパターンで送る
        if ($step >= 2 && $stepBefore < 2) {
            (new AbTest($this->app))->maybeDecide((int)$c['id'], true);
        }
    }
}
