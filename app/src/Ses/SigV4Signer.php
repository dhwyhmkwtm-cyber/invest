<?php
declare(strict_types=1);

namespace Mailer\Ses;

/**
 * AWS Signature Version 4 の署名 (SES API 呼び出し用)。
 * 外部ライブラリ無しで動かすため自前実装。共用サーバーへの配置を簡単にする。
 */
final class SigV4Signer
{
    public function __construct(
        private string $accessKeyId,
        private string $secretAccessKey,
        private string $region,
        private string $service,
        private ?string $sessionToken = null,
    ) {
    }

    /**
     * @param array<string,string> $headers host を含まなくてよい (URLから補う)
     * @return array<string,string> Authorization 等を追加したヘッダー
     */
    public function sign(string $method, string $url, array $headers, string $body, \DateTimeImmutable $now): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';

        $amzDate = $now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);

        $headers['host'] = $host;
        $headers['x-amz-date'] = $amzDate;
        if ($this->sessionToken) {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }
        $canon = [];
        foreach ($headers as $k => $v) {
            $canon[strtolower($k)] = preg_replace('/\s+/', ' ', trim($v));
        }
        ksort($canon);
        $canonicalHeaders = '';
        foreach ($canon as $k => $v) {
            $canonicalHeaders .= $k . ':' . $v . "\n";
        }
        $signedHeaders = implode(';', array_keys($canon));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::canonicalPath($path),
            self::canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $body),
        ]);
        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));

        $headers['Authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKeyId,
            $scope,
            $signedHeaders,
            $signature
        );
        return $headers;
    }

    public function signingKey(string $date): string
    {
        $k = hash_hmac('sha256', $date, 'AWS4' . $this->secretAccessKey, true);
        $k = hash_hmac('sha256', $this->region, $k, true);
        $k = hash_hmac('sha256', $this->service, $k, true);
        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    private static function canonicalPath(string $path): string
    {
        $segments = explode('/', $path);
        return implode('/', array_map(static fn($s) => rawurlencode(rawurldecode($s)), $segments)) ?: '/';
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $p) {
            [$k, $v] = array_pad(explode('=', $p, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
        }
        usort($pairs, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return implode('&', array_map(static fn($p) => $p[0] . '=' . $p[1], $pairs));
    }
}
