<?php

declare(strict_types=1);

namespace App\Domain\Fees;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Late fee in MINOR units (paisa) from a `late_fee` policy value.
 * steps[]: {from_day, to_day|null, method, amount_minor | percent_bp}; cumulative, grace_days, cap_minor|null.
 * Implemented: flat_once, per_day, percent_of_head, percent_of_balance. `slab` and `formula` are Stage 2/3 (throw).
 * daysLate = (paymentDate - dueDate) in calendar days - grace_days; <= 0 means no fee.
 */
final class LateFeeCalculator
{
    public function daysLate(array $policy, DateTimeImmutable $dueDate, DateTimeImmutable $paymentDate): int
    {
        if (($policy['day_count'] ?? 'calendar') !== 'calendar') {
            throw new UnsupportedLateFeeMethod('Only day_count=calendar is implemented.');
        }
        $signed = (int) $dueDate->setTime(0, 0)->diff($paymentDate->setTime(0, 0))->format('%r%a');

        return $signed - (int) ($policy['grace_days'] ?? 0);
    }

    public function calculate(array $policy, DateTimeImmutable $dueDate, DateTimeImmutable $paymentDate, ?LateFeeContext $ctx = null): int
    {
        $ctx ??= new LateFeeContext;
        $days = $this->daysLate($policy, $dueDate, $paymentDate);
        if ($days <= 0) {
            return 0;
        }

        $steps = $policy['steps'] ?? [];
        if (! is_array($steps) || $steps === []) {
            throw new InvalidArgumentException('late_fee policy has no steps.');
        }
        usort($steps, static fn (array $a, array $b): int => (int) $a['from_day'] <=> (int) $b['from_day']);

        $applicable = array_values(array_filter($steps, static fn (array $s): bool => $days >= (int) $s['from_day']));
        if ($applicable === []) {
            return 0;
        }
        if (! ($policy['cumulative'] ?? true)) {
            $applicable = [end($applicable)];
        }

        $total = 0;
        foreach ($applicable as $step) {
            $from = (int) $step['from_day'];
            $to = $step['to_day'] ?? null;
            $chargeableDays = ($to === null ? $days : min($days, (int) $to)) - $from + 1;

            $total += match ($step['method'] ?? null) {
                'flat_once' => (int) $step['amount_minor'],
                'per_day' => (int) $step['amount_minor'] * $chargeableDays,
                'percent_of_head' => $this->percent($ctx->tuitionMinor, (int) $step['percent_bp']),
                'percent_of_balance' => $this->percent($ctx->balanceMinor, (int) $step['percent_bp']),
                'slab', 'formula' => throw new UnsupportedLateFeeMethod("Method '{$step['method']}' is not implemented yet."),
                default => throw new InvalidArgumentException('Unknown late fee method: '.var_export($step['method'] ?? null, true)),
            };
        }

        $cap = $policy['cap_minor'] ?? null;

        return $cap !== null ? min($total, (int) $cap) : $total;
    }

    /** Basis points (1% = 100 bp), half-up, integer-only. */
    private function percent(int $baseMinor, int $basisPoints): int
    {
        return intdiv(2 * $baseMinor * $basisPoints + 10000, 20000);
    }
}
