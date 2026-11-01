<?php
declare(strict_types=1);

namespace Mailer;

/** 配信停止URLの生成と検証 (HMACで改ざん防止) */
final class UnsubscribeToken
{
    public function __construct(private string $secret, private string $baseUrl)
    {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('app_secret は32文字以上にしてください');
        }
    }

    public function token(int $queueId): string
    {
        $mac = hash_hmac('sha256', 'unsub|' . $queueId, $this->secret, true);
        return rtrim(strtr(base64_encode(substr($mac, 0, 18)), '+/', '-_'), '=');
    }

    public function url(int $queueId): string
    {
        return rtrim($this->baseUrl, '/') . '/u.php?q=' . $queueId . '&t=' . $this->token($queueId);
    }

    public function verify(int $queueId, string $token): bool
    {
        return $queueId > 0 && hash_equals($this->token($queueId), $token);
    }
}
