<?php
declare(strict_types=1);

namespace Mailer\Queue;

/** 送信時間帯 (平日9〜17時など) と営業日の計算 */
final class SendWindow
{
    /** @param list<string> $holidays YYYY-MM-DD */
    public function __construct(private array $holidays = [])
    {
    }

    public function isOpen(array $campaign, \DateTimeImmutable $now): bool
    {
        $days = array_map('intval', array_filter(explode(',', (string)($campaign['send_days'] ?? '1,2,3,4,5'))));
        if (!in_array((int)$now->format('N'), $days, true)) {
            return false;
        }
        if (in_array($now->format('Y-m-d'), $this->holidays, true)) {
            return false;
        }
        $t = $now->format('H:i:s');
        $start = substr((string)($campaign['send_window_start'] ?? '09:00:00') . ':00', 0, 8);
        $end = substr((string)($campaign['send_window_end'] ?? '17:00:00') . ':00', 0, 8);
        return $t >= $start && $t < $end;
    }

    public function isBusinessDay(\DateTimeImmutable $d): bool
    {
        return (int)$d->format('N') <= 5 && !in_array($d->format('Y-m-d'), $this->holidays, true);
    }

    /** $d から n 営業日前の日付 */
    public function subtractBusinessDays(\DateTimeImmutable $d, int $n): \DateTimeImmutable
    {
        $cur = $d->setTime(0, 0);
        while ($n > 0) {
            $cur = $cur->modify('-1 day');
            if ($this->isBusinessDay($cur)) {
                $n--;
            }
        }
        return $cur;
    }
}
