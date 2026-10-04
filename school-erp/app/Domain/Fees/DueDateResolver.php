<?php

declare(strict_types=1);

namespace App\Domain\Fees;

use DateTimeImmutable;
use RuntimeException;

/** Moves a due date that falls on a weekly-off day or holiday to the next working day. */
final class DueDateResolver
{
    /**
     * @param  list<string>  $weeklyOff  lower-case weekday names, e.g. ['saturday','sunday']
     * @param  list<string>  $holidays  'Y-m-d' dates
     */
    public function nextWorkingDay(DateTimeImmutable $due, array $weeklyOff, array $holidays = []): DateTimeImmutable
    {
        $off = array_map('strtolower', $weeklyOff);
        $date = $due->setTime(0, 0);

        for ($i = 0; $i < 366; $i++) {
            $isOff = in_array(strtolower($date->format('l')), $off, true) || in_array($date->format('Y-m-d'), $holidays, true);
            if (! $isOff) {
                return $date;
            }
            $date = $date->modify('+1 day');
        }

        throw new RuntimeException('No working day found within 366 days; check weekly-off configuration.');
    }
}
