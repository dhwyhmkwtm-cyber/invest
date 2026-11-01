<?php
declare(strict_types=1);

namespace Mailer;

use Mailer\Ses\MimeBuilder;
use Mailer\Ses\SesClient;
use Mailer\Ses\Transport;

/**
 * 管理者への通知 (メールは SES 経由。ロリポップのSMTPは使わない) + 任意で Slack。
 * 同じ種類の警告は6時間に1回までに抑える。
 */
class Notifier
{
    public function __construct(
        private Config $config,
        private SesClient $ses,
        private Transport $transport,
        private Clock $clock,
        private ?Settings $settings = null,
    ) {
    }

    public function alert(string $key, string $message, bool $force = false): void
    {
        $settings = $this->settings;
        if ($settings && !$force) {
            $last = $settings->get('alert_last_' . $key);
            if ($last !== null && strtotime($last) > $this->clock->now()->getTimestamp() - 6 * 3600) {
                return;
            }
        }
        $settings?->set('alert_last_' . $key, $this->clock->nowSql());
        $this->send('【配信ツール警告】' . $this->subjectFor($key), $message);
    }

    public function send(string $subject, string $body): void
    {
        $from = (string)$this->config->get('sender.from_email', '');
        foreach ((array)$this->config->get('notify.admin_emails', []) as $to) {
            try {
                $mime = MimeBuilder::build($from, '配信ツール', (string)$to, $subject, $body, null, null, [], $this->clock->now());
                $this->ses->sendRaw($from, (string)$to, $mime);
            } catch (\Throwable $e) {
                error_log('[mailer] notify failed: ' . $e->getMessage());
            }
        }
        $hook = (string)$this->config->get('notify.slack_webhook_url', '');
        if ($hook !== '') {
            try {
                $this->transport->request('POST', $hook, ['content-type' => 'application/json'],
                    json_encode(['text' => $subject . "\n" . $body], JSON_UNESCAPED_UNICODE), 10);
            } catch (\Throwable $e) {
                error_log('[mailer] slack failed: ' . $e->getMessage());
            }
        }
    }

    private function subjectFor(string $key): string
    {
        return match ($key) {
            'autopause' => '苦情率・バウンス率超過で自動停止',
            'ses-stop' => 'SESが送信を拒否',
            'stale', 'unknown' => '送信結果不明の宛先あり',
            'config' => '設定不足',
            default => $key,
        };
    }
}
