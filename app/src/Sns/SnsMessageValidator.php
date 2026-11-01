<?php
declare(strict_types=1);

namespace Mailer\Sns;

/**
 * Amazon SNS のメッセージ署名を検証する。
 * https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html
 */
class SnsMessageValidator
{
    private const CERT_HOST = '/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/';

    /** @var callable(string):string 証明書URL → PEM */
    private $certFetcher;

    public function __construct(private string $cacheDir, ?callable $certFetcher = null)
    {
        $this->certFetcher = $certFetcher ?? [$this, 'fetchCert'];
    }

    public static function isAmazonUrl(string $url): bool
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? '') === 'https' && preg_match(self::CERT_HOST, strtolower($p['host'] ?? '')) === 1;
    }

    /** @throws \RuntimeException 検証失敗 */
    public function validate(array $msg): void
    {
        foreach (['Type', 'MessageId', 'Timestamp', 'Signature', 'SigningCertURL', 'SignatureVersion', 'TopicArn'] as $k) {
            if (!isset($msg[$k]) || !is_string($msg[$k])) {
                throw new \RuntimeException("SNS: {$k} がありません");
            }
        }
        $certUrl = $msg['SigningCertURL'];
        if (!self::isAmazonUrl($certUrl) || !str_ends_with(parse_url($certUrl, PHP_URL_PATH) ?: '', '.pem')) {
            throw new \RuntimeException('SNS: 証明書URLが不正です');
        }
        $algo = match ($msg['SignatureVersion']) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => throw new \RuntimeException('SNS: 未対応の SignatureVersion'),
        };
        $pem = ($this->certFetcher)($certUrl);
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \RuntimeException('SNS: 証明書を読めません');
        }
        $sig = base64_decode($msg['Signature'], true);
        if ($sig === false || openssl_verify(self::stringToSign($msg), $sig, $key, $algo) !== 1) {
            throw new \RuntimeException('SNS: 署名が一致しません');
        }
    }

    public static function stringToSign(array $msg): string
    {
        $keys = $msg['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $s = '';
        foreach ($keys as $k) {
            if (array_key_exists($k, $msg) && $msg[$k] !== null) {
                $s .= $k . "\n" . $msg[$k] . "\n";
            }
        }
        return $s;
    }

    private function fetchCert(string $url): string
    {
        $file = rtrim($this->cacheDir, '/') . '/sns-cert-' . sha1($url) . '.pem';
        if (is_file($file) && filemtime($file) > time() - 86400) {
            return (string)file_get_contents($file);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $pem = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($pem) || $code !== 200 || !str_contains($pem, 'BEGIN CERTIFICATE')) {
            throw new \RuntimeException('SNS: 証明書を取得できません');
        }
        @file_put_contents($file, $pem, LOCK_EX);
        return $pem;
    }
}
