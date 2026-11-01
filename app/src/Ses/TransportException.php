<?php
declare(strict_types=1);

namespace Mailer\Ses;

final class TransportException extends \RuntimeException
{
    /**
     * @param bool $requestMayHaveBeenSent true の場合、相手に届いた可能性があり再送は二重送信の恐れがある
     */
    public function __construct(string $message, public readonly bool $requestMayHaveBeenSent, int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
