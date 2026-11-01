<?php
declare(strict_types=1);

namespace Mailer;

/**
 * 送信前のアドレス有効性チェック (ドメインのMXレコード確認)。
 * MXが無くてもAレコードがあれば受信可能 (RFC 5321) とみなす。
 */
class MxChecker
{
    /** @var array<string,bool> */
    private array $cache = [];

    /** @param bool $localMode true なら .example / .test / .invalid などのテスト用ドメインを「受信可能」とみなす */
    public function __construct(private Db $db, private Clock $clock, private bool $localMode = false)
    {
    }

    public function domainAcceptsMail(string $domain): bool
    {
        if (isset($this->cache[$domain])) {
            return $this->cache[$domain];
        }
        $ok = $this->lookup($domain);
        return $this->cache[$domain] = $ok;
    }

    protected function lookup(string $domain): bool
    {
        if (preg_match('/\.(example|test|invalid|localhost)$/', $domain) || $domain === 'simulator.amazonses.com' && $this->localMode) {
            return $this->localMode;
        }
        $ascii = function_exists('idn_to_ascii') ? (idn_to_ascii($domain) ?: $domain) : $domain;
        if (@checkdnsrr($ascii . '.', 'MX')) {
            // "Null MX" (RFC 7505: MX 0 .) はメール不可
            $mx = @dns_get_record($ascii . '.', DNS_MX) ?: [];
            foreach ($mx as $r) {
                if (($r['target'] ?? '') !== '' && ($r['target'] ?? '') !== '.') {
                    return true;
                }
            }
            return $mx === [];
        }
        return @checkdnsrr($ascii . '.', 'A') || @checkdnsrr($ascii . '.', 'AAAA');
    }

    /** 未チェックの宛先を時間内でまとめて確認する。@return array{checked:int, ng:int} */
    public function checkPending(int $limit = 500, float $timeBudgetSec = 20.0): array
    {
        $start = microtime(true);
        $rows = $this->db->all(
            "SELECT id, email_domain FROM contacts WHERE mx_status = 'unchecked' ORDER BY email_domain LIMIT " . (int)$limit
        );
        $checked = 0;
        $ng = 0;
        foreach ($rows as $r) {
            if (microtime(true) - $start > $timeBudgetSec) {
                break;
            }
            $ok = $this->domainAcceptsMail($r['email_domain']);
            $this->db->run(
                'UPDATE contacts SET mx_status = ?, mx_checked_at = ?, updated_at = ? WHERE id = ?',
                [$ok ? 'ok' : 'ng', $this->clock->nowSql(), $this->clock->nowSql(), $r['id']]
            );
            $checked++;
            if (!$ok) {
                $ng++;
            }
        }
        return ['checked' => $checked, 'ng' => $ng];
    }
}
