<?php
declare(strict_types=1);

namespace Mailer;

/**
 * テスト環境の安全装置。
 * environment が 'production' 以外のときは、allowed_recipients に一致する宛先にしか送らない。
 * 許可リストが空なら一切送らない (設定ミスで顧客に送ってしまわないよう、安全側に倒す)。
 */
final class RecipientGuard
{
    public function __construct(private Config $config)
    {
    }

    public function isProduction(): bool
    {
        return $this->config->get('environment', 'staging') === 'production';
    }

    public function allows(string $email): bool
    {
        if ($this->isProduction()) {
            return true;
        }
        $email = strtolower($email);
        foreach ((array)$this->config->get('sending.allowed_recipients', []) as $rule) {
            $rule = strtolower(trim((string)$rule));
            if ($rule === '') {
                continue;
            }
            // '@example.co.jp' や 'example.co.jp' はドメイン全体、それ以外は完全一致
            if (str_contains($rule, '@') && !str_starts_with($rule, '@')) {
                if ($email === $rule) {
                    return true;
                }
            } elseif (str_ends_with($email, '@' . ltrim($rule, '@'))) {
                return true;
            }
        }
        return false;
    }
}
