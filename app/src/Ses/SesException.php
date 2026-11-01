<?php
declare(strict_types=1);

namespace Mailer\Ses;

final class SesException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorType,
        public readonly int $httpStatus,
        public readonly bool $retryable,
        /** アカウント全体の送信停止・上限到達など、以降の送信を止めるべきエラー */
        public readonly bool $stopSending,
    ) {
        parent::__construct($message);
    }
}
