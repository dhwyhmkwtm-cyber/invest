<?php
declare(strict_types=1);

namespace Mailer\Queue;

use Mailer\App;
use Mailer\ContactStatus;
use Mailer\Guard\AutoPause;
use Mailer\Ses\MimeBuilder;
use Mailer\Ses\SesException;
use Mailer\Ses\TransportException;
use Mailer\Template\TemplateRenderer;

/**
 * cron から数分ごとに起動され、キューを少量ずつ SES へ送る (F4)。
 *
 * 二重送信防止:
 *  1. 送信前に行を 'sending' にしてロック (コミット済み)
 *  2. SES 成功で 'sent' に確定
 *  3. 途中で落ちて 'sending' のまま残った行は自動再送せず 'unknown' にして管理者が確認する
 *  4. 通信タイムアウト等「届いたか分からない」失敗も 'unknown' (再送しない)
 */
final class Worker
{
    /** @var callable(float):void */
    private $sleeper;
    private float $startedAt;

    public function __construct(private App $app, ?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (float $sec): void {
            if ($sec > 0) {
                usleep((int)($sec * 1_000_000));
            }
        };
    }

    /** @return array<string,int|string> 実行結果の集計 */
    public function run(): array
    {
        $this->startedAt = microtime(true);
        $stats = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'unknown' => 0, 'skipped' => 0, 'stale' => 0, 'stop' => 0, 'note' => ''];
        $db = $this->app->db();
        $cfg = $this->app->config;
        $clock = $this->app->clock;
        $settings = $this->app->settings();

        $stats['stale'] = $this->recoverStale();

        if ($settings->isPaused()) {
            $stats['note'] = 'paused';
            return $stats;
        }
        $missing = TemplateRenderer::missingSenderInfo($cfg);
        if ($missing) {
            $this->app->notifier()->alert('config', '法定表示の設定不足のため送信を停止しています: ' . implode('、', $missing));
            $stats['note'] = 'missing sender info';
            return $stats;
        }

        $now = $clock->now();
        $window = new SendWindow((array)$cfg->get('sending.holidays', []));
        $todayStart = $now->format('Y-m-d 00:00:00');
        $hourAgo = $now->modify('-1 hour')->format('Y-m-d H:i:s');

        $sentToday = (int)$db->value("SELECT COUNT(*) FROM send_log WHERE result = 'sent' AND created_at >= ?", [$todayStart]);
        $sentHour = (int)$db->value("SELECT COUNT(*) FROM send_log WHERE result = 'sent' AND created_at >= ?", [$hourAgo]);
        $globalBudget = min(
            (int)$cfg->get('sending.max_per_run', 100),
            (int)$cfg->get('sending.daily_limit', 3000) - $sentToday,
            (int)$cfg->get('sending.hourly_limit', 600) - $sentHour,
        );
        if ($globalBudget <= 0) {
            $stats['note'] = 'global limit reached';
            return $stats;
        }

        $campaigns = $db->all("SELECT * FROM campaigns WHERE status = 'active' AND (start_date IS NULL OR start_date <= ?) ORDER BY id", [$now->format('Y-m-d')]);
        $perSecond = max(0.1, (float)$cfg->get('sending.per_second', 5));
        $interval = 1.0 / $perSecond;
        $lastSendAt = 0.0;
        $lastDomain = null;

        foreach ($campaigns as $campaign) {
            if ($globalBudget <= 0 || $this->outOfTime()) {
                break;
            }
            if (!$window->isOpen($campaign, $clock->now())) {
                continue;
            }
            $campSent = (int)$db->value(
                "SELECT COUNT(*) FROM send_log WHERE campaign_id = ? AND result = 'sent' AND created_at >= ?",
                [$campaign['id'], $todayStart]
            );
            $campBudget = min($globalBudget, (int)$campaign['daily_limit'] - $campSent);

            while ($campBudget > 0 && !$this->outOfTime()) {
                if ($settings->isPaused()) {
                    $stats['note'] = 'paused during run';
                    break 2;
                }
                $batch = $this->claim((int)$campaign['id'], min(10, $campBudget));
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $i => $row) {
                    if ($this->outOfTime() || $settings->isPaused()) {
                        $this->release(array_slice($batch, $i));
                        break 3;
                    }
                    // 同一ドメインが連続する場合は少し間隔をあける
                    $wait = $interval - (microtime(true) - $lastSendAt);
                    if ($lastDomain === $row['email_domain']) {
                        $wait = max($wait, 2.0);
                    }
                    ($this->sleeper)($wait);
                    $result = $this->sendOne($row, $campaign);
                    $lastSendAt = microtime(true);
                    $lastDomain = $row['email_domain'];
                    $stats[$result]++;
                    if ($result === 'sent') {
                        $campBudget--;
                        $globalBudget--;
                    }
                    if ($result === 'stop') {
                        $this->release(array_slice($batch, $i + 1));
                        break 3;
                    }
                }
            }
        }
        unset($stats['stop']);

        // 自動停止の判定 (苦情率・バウンス率)
        (new AutoPause($this->app))->check();
        return $stats;
    }

    private function outOfTime(): bool
    {
        return microtime(true) - $this->startedAt > (float)$this->app->config->get('sending.run_time_budget', 25);
    }

    /** @return list<array<string,mixed>> */
    private function claim(int $campaignId, int $limit): array
    {
        $db = $this->app->db();
        $now = $this->app->clock->nowSql();
        $token = bin2hex(random_bytes(16));
        $db->run(
            "UPDATE send_queue SET status = 'sending', lock_token = ?, locked_at = ?, attempts = attempts + 1, updated_at = ?
              WHERE campaign_id = ? AND status IN ('queued', 'retry') AND scheduled_at <= ?
              ORDER BY step DESC, seq, id LIMIT " . (int)$limit,
            [$token, $now, $now, $campaignId, $now]
        );
        return $db->all(
            "SELECT q.*, c.email, c.email_domain, c.person_name, c.company_id,
                    co.name AS company_name, co.region, co.industry
               FROM send_queue q
               JOIN contacts c ON c.id = q.contact_id
               JOIN companies co ON co.id = c.company_id
              WHERE q.lock_token = ? AND q.status = 'sending' ORDER BY q.step DESC, q.seq, q.id",
            [$token]
        );
    }

    /** 時間切れ等で処理しなかった行を元に戻す */
    private function release(array $rows): void
    {
        foreach ($rows as $r) {
            $this->app->db()->run(
                "UPDATE send_queue SET status = IF(attempts > 1, 'retry', 'queued'), attempts = attempts - 1,
                        lock_token = NULL, locked_at = NULL, updated_at = ?
                  WHERE id = ? AND status = 'sending' AND lock_token = ?",
                [$this->app->clock->nowSql(), $r['id'], $r['lock_token']]
            );
        }
    }

    /** @return 'sent'|'retry'|'failed'|'unknown'|'skipped'|'stop' */
    private function sendOne(array $row, array $campaign): string
    {
        $app = $this->app;
        $db = $app->db();
        $cfg = $app->config;
        $clock = $app->clock;
        $requireMx = (bool)$cfg->get('sending.require_mx_ok', true);

        // 送信直前の再確認 (抑止リスト・お断り表示・取得元)
        $eligible = $db->value(
            'SELECT 1 FROM contacts c WHERE c.id = ? AND ' . Enqueuer::eligibilitySql($requireMx),
            [$row['contact_id']]
        );
        if ($eligible === null) {
            $this->finish($row, 'cancelled', null, 'not eligible at send time (suppressed or missing source)');
            $this->log($row, 'skipped', null, 'not eligible');
            return 'skipped';
        }
        // 1社1通 (別アドレスで同じ会社に今回のステップを送っていないか)
        $dup = $db->value(
            "SELECT 1 FROM send_queue q JOIN contacts c ON c.id = q.contact_id
              WHERE q.campaign_id = ? AND q.step = ? AND c.company_id = ? AND q.id <> ?
                AND q.status IN ('sent', 'sending', 'unknown') LIMIT 1",
            [$row['campaign_id'], $row['step'], $row['company_id'], $row['id']]
        );
        if ($dup !== null) {
            $this->finish($row, 'cancelled', null, 'company already contacted in this campaign');
            $this->log($row, 'skipped', null, 'same company');
            return 'skipped';
        }

        $template = $db->one('SELECT * FROM templates WHERE id = ?', [$row['template_id']]);
        if (!$template) {
            $this->finish($row, 'failed', null, 'template missing');
            $this->log($row, 'failed', null, 'template missing');
            return 'failed';
        }

        $unsubUrl = $app->unsubscribeToken()->url((int)$row['id']);
        $content = (new TemplateRenderer($cfg))->render($template, $row, $unsubUrl);
        $from = (string)$cfg->get('sender.from_email');
        $mime = MimeBuilder::build(
            $from,
            (string)$cfg->get('sender.from_name', ''),
            $row['email'],
            $content['subject'],
            $content['text'],
            $content['html'],
            (string)$cfg->get('sender.reply_to', '') ?: null,
            [
                // RFC 8058 ワンクリック配信停止
                'List-Unsubscribe' => '<' . $unsubUrl . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
            $clock->now(),
        );

        try {
            $messageId = $app->ses()->sendRaw(
                $from,
                $row['email'],
                $mime,
                (string)$cfg->get('aws.configuration_set', '') ?: null,
                ['campaign' => (string)$row['campaign_id'], 'queue' => (string)$row['id'], 'variant' => (string)$template['variant']]
            );
        } catch (SesException $e) {
            if ($e->stopSending && !$e->retryable) {
                // アカウント側の問題 (停止・認証エラー等)。宛先の問題ではないのでキューに戻して全体停止。
                $app->settings()->pause('SESエラーのため自動停止: ' . $e->getMessage());
                $app->notifier()->alert('ses-stop', "SESが送信を受け付けないため配信を一時停止しました。\n" . $e->getMessage());
                $this->release([$row]);
                $this->log($row, 'retry', null, $e->getMessage());
                return 'stop';
            }
            if ($e->retryable) {
                $this->scheduleRetry($row, $e->getMessage());
                $this->log($row, 'retry', null, $e->getMessage());
                // スロットリング・上限到達は今回の実行を打ち切る
                return in_array($e->errorType, ['TooManyRequestsException', 'ThrottlingException', 'LimitExceededException'], true) ? 'stop' : 'retry';
            }
            $this->finish($row, 'failed', null, $e->getMessage());
            $this->log($row, 'failed', null, $e->getMessage());
            return 'failed';
        } catch (TransportException $e) {
            if ($e->requestMayHaveBeenSent) {
                $this->finish($row, 'unknown', null, $e->getMessage());
                $this->log($row, 'unknown', null, $e->getMessage());
                $app->notifier()->alert('unknown', "送信結果が不明な宛先があります (再送はしていません)。管理画面の「キュー」で確認してください。\n" . $e->getMessage());
                return 'unknown';
            }
            $this->scheduleRetry($row, $e->getMessage());
            $this->log($row, 'retry', null, $e->getMessage());
            return 'stop';
        }

        $now = $clock->nowSql();
        $db->run(
            "UPDATE send_queue SET status = 'sent', ses_message_id = ?, sent_at = ?, lock_token = NULL, last_error = NULL, updated_at = ?
              WHERE id = ? AND status = 'sending'",
            [$messageId, $now, $now, $row['id']]
        );
        $db->run('UPDATE contacts SET last_sent_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $row['contact_id']]);
        ContactStatus::advance($db, $clock, (int)$row['contact_id'], 'sent');
        $this->log($row, 'sent', $messageId, null);
        return 'sent';
    }

    private function scheduleRetry(array $row, string $error): void
    {
        $max = (int)$this->app->config->get('sending.max_attempts', 3);
        $attempts = (int)$row['attempts'];
        if ($attempts >= $max) {
            $this->finish($row, 'failed', null, 'max attempts: ' . $error);
            return;
        }
        $delays = (array)$this->app->config->get('sending.retry_delay_minutes', [30, 180, 720]);
        $delay = (int)($delays[$attempts - 1] ?? end($delays) ?: 60);
        $at = $this->app->clock->now()->modify("+{$delay} minutes")->format('Y-m-d H:i:s');
        $this->app->db()->run(
            "UPDATE send_queue SET status = 'retry', scheduled_at = ?, lock_token = NULL, last_error = ?, updated_at = ?
              WHERE id = ? AND status = 'sending'",
            [$at, mb_substr($error, 0, 1000), $this->app->clock->nowSql(), $row['id']]
        );
    }

    private function finish(array $row, string $status, ?string $messageId, ?string $error): void
    {
        $this->app->db()->run(
            "UPDATE send_queue SET status = ?, ses_message_id = COALESCE(?, ses_message_id), lock_token = NULL,
                    last_error = ?, updated_at = ? WHERE id = ? AND status = 'sending'",
            [$status, $messageId, $error === null ? null : mb_substr($error, 0, 1000), $this->app->clock->nowSql(), $row['id']]
        );
    }

    private function log(array $row, string $result, ?string $messageId, ?string $error): void
    {
        $this->app->db()->insert('send_log', [
            'queue_id' => $row['id'],
            'campaign_id' => $row['campaign_id'],
            'contact_id' => $row['contact_id'],
            'template_id' => $row['template_id'],
            'email' => $row['email'],
            'attempt' => $row['attempts'],
            'ses_message_id' => $messageId,
            'result' => $result,
            'error' => $error === null ? null : mb_substr($error, 0, 1000),
            'created_at' => $this->app->clock->nowSql(),
        ]);
    }

    /** 'sending' のまま10分以上経った行は自動再送せず 'unknown' にする */
    private function recoverStale(): int
    {
        $db = $this->app->db();
        $cutoff = $this->app->clock->now()->modify('-10 minutes')->format('Y-m-d H:i:s');
        $n = $db->run(
            "UPDATE send_queue SET status = 'unknown', lock_token = NULL,
                    last_error = 'worker stopped while sending (not retried to avoid duplicates)', updated_at = ?
              WHERE status = 'sending' AND locked_at < ?",
            [$this->app->clock->nowSql(), $cutoff]
        )->rowCount();
        if ($n > 0) {
            $this->app->notifier()->alert('stale', "送信中に処理が止まった宛先が {$n} 件あります (二重送信を避けるため再送していません)。管理画面の「キュー」で確認してください。");
        }
        return $n;
    }
}
