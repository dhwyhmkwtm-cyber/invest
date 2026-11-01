<?php
declare(strict_types=1);

namespace Mailer\Ses;

interface Transport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, headers:array<string,string>}
     * @throws TransportException 通信エラー時
     */
    public function request(string $method, string $url, array $headers, string $body, int $timeout = 15): array;
}
