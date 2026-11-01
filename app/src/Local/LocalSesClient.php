<?php
declare(strict_types=1);

namespace Mailer\Local;

use Mailer\App;
use Mailer\Ses\SesClient;

/**
 * 自宅・ローカル用の「なんちゃって SES」。メールを外に出さず、DB のテスト受信箱に保存する。
 * 本物の SES と同じく、送信の少し後 (次の cron) に配信・バウンス・苦情の通知を発生させる。
 * SES のメールボックスシミュレーターと同じアドレスで、同じ結果になる:
 *   bounce@simulator.amazonses.com     → 永久バウンス
 *   complaint@simulator.amazonses.com  → 配信 + 迷惑メール報告
 *   suppressionlist@simulator.amazonses.com → 永久バウンス
 *   それ以外 → 配信
 */
final class LocalSesClient extends SesClient
{
    public function __construct(private App $app)
    {
        parent::__construct('local', 'local', 'local', $app->transport(), $app->clock);
    }

    public function sendRaw(string $from, string $to, string $rawMime, ?string $configurationSet = null, array $tags = []): string
    {
        $id = 'local-' . bin2hex(random_bytes(12));
        $to = strtolower($to);
        $events = match ($to) {
            'bounce@simulator.amazonses.com', 'suppressionlist@simulator.amazonses.com' => 'Bounce',
            'complaint@simulator.amazonses.com' => 'Delivery,Complaint',
            default => 'Delivery',
        };
        $this->app->db()->insert('local_mailbox', [
            'message_id' => $id,
            'to_email' => $to,
            'from_email' => $from,
            'subject' => mb_substr(LocalMailbox::subjectOf($rawMime), 0, 500),
            'raw' => $rawMime,
            'is_campaign' => $configurationSet ? 1 : 0,
            'pending_events' => $configurationSet ? $events : null, // 設定セットなし (管理者通知) はイベントなし
            'created_at' => $this->app->clock->nowSql(),
        ]);
        return $id;
    }

    public function getAccount(): array
    {
        return ['ProductionAccessEnabled' => false, 'SendingEnabled' => true, 'Local' => true,
            'SendQuota' => ['Max24HourSend' => 200, 'MaxSendRate' => 1, 'SentLast24Hours' => 0]];
    }

    public function getEmailIdentity(string $identity): array
    {
        return ['VerifiedForSendingStatus' => true, 'DkimAttributes' => ['Status' => 'SUCCESS'],
            'MailFromAttributes' => ['MailFromDomain' => 'bounce.' . $identity, 'MailFromDomainStatus' => 'SUCCESS']];
    }
}
