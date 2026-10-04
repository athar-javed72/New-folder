<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use InvalidArgumentException;

/**
 * Payroll for session-based contracts (visiting = per_session, part-time = hourly). Money in MINOR units.
 * Session: {status, planned_teacher_id, actual_teacher_id|null, minutes}
 *  status: completed | cancelled_by_school | cancelled_other | scheduled
 * pay_rules: cancelled_by_school = paid|unpaid (default paid); substituted = substitute_paid (only the substitute is paid).
 * per_session: rate_minor per payable session. hourly: rate_minor per hour, minutes/60 half-up.
 */
final class ContractPayrollCalculator
{
    /**
     * @param  array{pay_basis:string, rate_minor:int, pay_rules?:array<string,string>}  $contract
     * @param  list<array{status:string, planned_teacher_id:string, actual_teacher_id?:?string, minutes?:int}>  $sessions
     * @return array{total_minor:int, lines:list<array{kind:string, sessions:int, minutes:int, amount_minor:int}>}
     */
    public function calculate(array $contract, string $teacherId, array $sessions): array
    {
        $basis = $contract['pay_basis'] ?? null;
        if (! in_array($basis, ['per_session', 'hourly'], true)) {
            throw new InvalidArgumentException("Unsupported pay_basis '{$basis}' for session-based payroll.");
        }
        $rate = (int) $contract['rate_minor'];
        $rules = $contract['pay_rules'] ?? [];
        $cancelledRule = $rules['cancelled_by_school'] ?? 'paid';
        $substitutedRule = $rules['substituted'] ?? 'substitute_paid';
        if (! in_array($cancelledRule, ['paid', 'unpaid'], true)) {
            throw new InvalidArgumentException("Invalid cancelled_by_school rule '{$cancelledRule}'.");
        }
        if ($substitutedRule !== 'substitute_paid') {
            throw new InvalidArgumentException("Unsupported substituted rule '{$substitutedRule}'.");
        }

        $buckets = [
            'completed' => ['sessions' => 0, 'minutes' => 0],
            'substitution' => ['sessions' => 0, 'minutes' => 0],
            'cancelled_by_school' => ['sessions' => 0, 'minutes' => 0],
        ];

        foreach ($sessions as $s) {
            $planned = $s['planned_teacher_id'];
            $actual = $s['actual_teacher_id'] ?? null;
            $minutes = (int) ($s['minutes'] ?? 0);

            if ($s['status'] === 'completed') {
                if (($actual ?? $planned) !== $teacherId) {
                    continue;
                }
                $kind = ($actual !== null && $actual !== $planned) ? 'substitution' : 'completed';
                $buckets[$kind]['sessions']++;
                $buckets[$kind]['minutes'] += $minutes;
            } elseif ($s['status'] === 'cancelled_by_school' && $cancelledRule === 'paid' && $planned === $teacherId) {
                $buckets['cancelled_by_school']['sessions']++;
                $buckets['cancelled_by_school']['minutes'] += $minutes;
            }
        }

        $lines = [];
        $total = 0;
        foreach ($buckets as $kind => $b) {
            if ($b['sessions'] === 0) {
                continue;
            }
            $amount = $basis === 'per_session' ? $rate * $b['sessions'] : intdiv(2 * $rate * $b['minutes'] + 60, 120);
            $lines[] = ['kind' => $kind, 'sessions' => $b['sessions'], 'minutes' => $b['minutes'], 'amount_minor' => $amount];
            $total += $amount;
        }

        return ['total_minor' => $total, 'lines' => $lines];
    }
}
