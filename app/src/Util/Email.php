<?php
declare(strict_types=1);

namespace Mailer\Util;

final class Email
{
    /** 窓口アドレスとみなすローカル部 */
    private const ROLE_LOCAL_PARTS = [
        'info', 'information', 'sales', 'eigyo', 'eigyou', 'contact', 'support', 'office',
        'mail', 'webmaster', 'master', 'admin', 'soumu', 'somu', 'jimu', 'jimusho',
        'otoiawase', 'toiawase', 'inquiry', 'inquiries', 'customer', 'service', 'kouji', 'koji',
        'honsha', 'head', 'hp', 'web', 'koho', 'pr', 'kanri', 'gyomu', 'gyoumu', 'center',
    ];

    /** 会社単位の停止にしてはいけないフリーメール・キャリアのドメイン */
    private const SHARED_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yahoo.co.jp', 'ymail.ne.jp', 'yahoo.com', 'outlook.com', 'outlook.jp',
        'hotmail.com', 'hotmail.co.jp', 'live.jp', 'msn.com', 'icloud.com', 'me.com', 'mac.com',
        'docomo.ne.jp', 'ezweb.ne.jp', 'au.com', 'softbank.ne.jp', 'i.softbank.jp', 'nifty.com',
        'biglobe.ne.jp', 'ocn.ne.jp', 'so-net.ne.jp', 'plala.or.jp', 'excite.co.jp', 'aol.com',
    ];

    /** 前後空白・全角記号を除き、小文字化。不正なら null。 */
    public static function normalize(string $raw): ?string
    {
        $s = trim(mb_convert_kana($raw, 'as', 'UTF-8')); // 全角英数・空白を半角に
        $s = preg_replace('/^mailto:/i', '', $s) ?? $s;
        $s = trim($s, " \t\n\r\0\x0B<>\"'");
        $s = strtolower($s);
        if ($s === '' || strlen($s) > 254) {
            return null;
        }
        if (!filter_var($s, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        [$local, $domain] = explode('@', $s, 2);
        if (strlen($local) > 64 || !str_contains($domain, '.') || str_ends_with($domain, '.')) {
            return null;
        }
        return $s;
    }

    public static function domain(string $email): string
    {
        $pos = strrpos($email, '@');
        return $pos === false ? '' : substr($email, $pos + 1);
    }

    public static function isRole(string $email): bool
    {
        $local = substr($email, 0, (int)strpos($email, '@'));
        $base = preg_replace('/[\d._+-].*$/', '', $local) ?? $local;
        return in_array($local, self::ROLE_LOCAL_PARTS, true) || in_array($base, self::ROLE_LOCAL_PARTS, true);
    }

    public static function isSharedDomain(string $domain): bool
    {
        return in_array(strtolower($domain), self::SHARED_DOMAINS, true);
    }

    /** 1社で複数アドレスがある場合の優先度 (小さいほど優先) */
    public static function priority(string $email): int
    {
        $local = substr($email, 0, (int)strpos($email, '@'));
        return match (true) {
            in_array($local, ['info', 'sales', 'eigyo', 'eigyou'], true) => 0,
            self::isRole($email) => 1,
            default => 2,
        };
    }
}
