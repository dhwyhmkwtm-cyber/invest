<?php
declare(strict_types=1);

namespace Mailer;

/** 現在時刻の取得元 (テストで固定できるようにする) */
class Clock
{
    private ?\DateTimeImmutable $fixed = null;

    public function now(): \DateTimeImmutable
    {
        return $this->fixed ?? new \DateTimeImmutable('now');
    }

    public function nowSql(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public function freeze(\DateTimeImmutable|string $at): void
    {
        $this->fixed = is_string($at) ? new \DateTimeImmutable($at) : $at;
    }

    public function unfreeze(): void
    {
        $this->fixed = null;
    }
}
