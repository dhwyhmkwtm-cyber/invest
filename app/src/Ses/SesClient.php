<?php
declare(strict_types=1);

namespace Mailer\Ses;

use Mailer\Clock;

/**
 * Amazon SES API v2 の最小クライアント (SendEmail を Raw MIME で呼ぶ)。
 */
class SesClient
{
    private SigV4Signer $signer;

    public function __construct(
        private string $region,
        string $accessKeyId,
        string $secretAccessKey,
        private Transport $transport,
        private Clock $clock,
    ) {
        $this->signer = new SigV4Signer($accessKeyId, $secretAccessKey, $region, 'ses');
    }

    /**
     * @param array<string,string> $tags SESのメッセージタグ (イベントに付いて返ってくる)
     * @return string SES の MessageId
     * @throws SesException|TransportException
     */
    public function sendRaw(string $from, string $to, string $rawMime, ?string $configurationSet = null, array $tags = []): string
    {
        $payload = [
            'FromEmailAddress' => $from,
            'Destination' => ['ToAddresses' => [$to]],
            'Content' => ['Raw' => ['Data' => base64_encode($rawMime)]],
        ];
        if ($configurationSet) {
            $payload['ConfigurationSetName'] = $configurationSet;
        }
        if ($tags) {
            $payload['EmailTags'] = [];
            foreach ($tags as $k => $v) {
                $payload['EmailTags'][] = ['Name' => $k, 'Value' => preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$v)];
            }
        }
        $resp = $this->call('POST', '/v2/email/outbound-emails', $payload);
        $id = $resp['MessageId'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new SesException('SES応答に MessageId がありません', 'InvalidResponse', 200, false, false);
        }
        return $id;
    }

    /** @return array<string,mixed> */
    public function getAccount(): array
    {
        return $this->call('GET', '/v2/email/account', null);
    }

    /** @return array<string,mixed> 送信用ID (ドメイン) の認証状態 */
    public function getEmailIdentity(string $identity): array
    {
        return $this->call('GET', '/v2/email/identities/' . rawurlencode($identity), null);
    }

    /** @return array<string,mixed> */
    private function call(string $method, string $path, ?array $payload): array
    {
        $url = "https://email.{$this->region}.amazonaws.com{$path}";
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = ['content-type' => 'application/json'];
        $headers = $this->signer->sign($method, $url, $headers, $body, $this->clock->now());
        unset($headers['host']);
        $resp = $this->transport->request($method, $url, $headers, $body, 20);
        $data = json_decode($resp['body'], true);
        if ($resp['status'] >= 200 && $resp['status'] < 300) {
            return is_array($data) ? $data : [];
        }
        $type = $resp['headers']['x-amzn-errortype'] ?? ($data['__type'] ?? 'Unknown');
        $type = explode(':', (string)$type)[0];
        $type = substr((string)strrchr('#' . $type, '#'), 1);
        $msg = (string)($data['message'] ?? $data['Message'] ?? $resp['body']);
        $retryable = $resp['status'] === 429 || $resp['status'] >= 500
            || in_array($type, ['TooManyRequestsException', 'ThrottlingException', 'InternalFailure', 'ServiceUnavailable'], true);
        $stop = in_array($type, ['SendingPausedException', 'AccountSuspendedException', 'LimitExceededException', 'MailFromDomainNotVerifiedException'], true)
            || $resp['status'] === 403;
        if ($type === 'LimitExceededException' || $type === 'TooManyRequestsException') {
            // 1日の送信上限到達は「少し待てば送れる」が、今回の実行では止める
            $retryable = true;
        }
        throw new SesException("SES {$resp['status']} {$type}: {$msg}", $type, $resp['status'], $retryable, $stop);
    }
}
