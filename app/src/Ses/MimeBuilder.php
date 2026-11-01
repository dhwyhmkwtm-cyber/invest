<?php
declare(strict_types=1);

namespace Mailer\Ses;

/**
 * 日本語メールの MIME を組み立てる (UTF-8, base64)。
 */
final class MimeBuilder
{
    /**
     * @param array<string,string> $extraHeaders 例: List-Unsubscribe
     */
    public static function build(
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $text,
        ?string $html,
        ?string $replyTo = null,
        array $extraHeaders = [],
        ?\DateTimeImmutable $date = null,
    ): string {
        $eol = "\r\n";
        $headers = [];
        $headers[] = 'From: ' . self::address($fromEmail, $fromName);
        $headers[] = 'To: ' . self::assertHeaderSafe($to);
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . self::assertHeaderSafe($replyTo);
        }
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'Date: ' . ($date ?? new \DateTimeImmutable())->format(DATE_RFC2822);
        $headers[] = 'MIME-Version: 1.0';
        foreach ($extraHeaders as $k => $v) {
            $headers[] = self::assertHeaderSafe($k) . ': ' . self::assertHeaderSafe($v);
        }

        $textPart = 'Content-Type: text/plain; charset=UTF-8' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol . $eol
            . chunk_split(base64_encode(self::crlf($text)), 76, $eol);

        if ($html === null || trim($html) === '') {
            return implode($eol, $headers) . $eol . $textPart;
        }
        $boundary = '=_alt_' . bin2hex(random_bytes(12));
        $htmlPart = 'Content-Type: text/html; charset=UTF-8' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol . $eol
            . chunk_split(base64_encode(self::crlf($html)), 76, $eol);
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        return implode($eol, $headers) . $eol . $eol
            . '--' . $boundary . $eol . $textPart
            . '--' . $boundary . $eol . $htmlPart
            . '--' . $boundary . '--' . $eol;
    }

    public static function encodeHeader(string $value): string
    {
        self::assertHeaderSafe($value);
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    public static function address(string $email, string $name): string
    {
        self::assertHeaderSafe($email);
        if ($name === '') {
            return $email;
        }
        if (preg_match('/^[A-Za-z0-9 .\-]*$/', $name)) {
            return '"' . $name . '" <' . $email . '>';
        }
        return self::encodeHeader($name) . ' <' . $email . '>';
    }

    private static function assertHeaderSafe(string $v): string
    {
        if (preg_match('/[\r\n]/', $v)) {
            throw new \InvalidArgumentException('ヘッダーに改行を含めることはできません');
        }
        return $v;
    }

    private static function crlf(string $s): string
    {
        return preg_replace("/\r\n|\r|\n/", "\r\n", $s) ?? $s;
    }
}
