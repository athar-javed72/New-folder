<?php

declare(strict_types=1);

use App\Domain\Payroll\ContractPayrollCalculator;

/**
 * GOLDEN: visiting teacher is paid completed sessions x rate (Rs 800 = 80,000 paisa per session).
 */
function visitingContract(string $cancelledRule = 'paid'): array
{
    return ['pay_basis' => 'per_session', 'rate_minor' => 80000, 'pay_rules' => ['cancelled_by_school' => $cancelledRule, 'substituted' => 'substitute_paid']];
}

function teacherSessions(int $completed, int $cancelled = 0, string $teacher = 'T1'): array
{
    $out = [];
    for ($i = 0; $i < $completed; $i++) {
        $out[] = ['status' => 'completed', 'planned_teacher_id' => $teacher, 'actual_teacher_id' => null, 'minutes' => 40];
    }
    for ($i = 0; $i < $cancelled; $i++) {
        $out[] = ['status' => 'cancelled_by_school', 'planned_teacher_id' => $teacher, 'actual_teacher_id' => null, 'minutes' => 40];
    }

    return $out;
}

it('GOLDEN: 22 completed sessions x Rs 800 = Rs 17,600', function () {
    $r = (new ContractPayrollCalculator)->calculate(visitingContract(), 'T1', teacherSessions(22));
    expect($r['total_minor'])->toBe(1760000);
});

it('pays sessions cancelled by the school when the rule is paid', function () {
    $r = (new ContractPayrollCalculator)->calculate(visitingContract('paid'), 'T1', teacherSessions(22, 2));
    expect($r['total_minor'])->toBe(1920000);
});

it('does not pay cancelled sessions when the rule is unpaid', function () {
    $r = (new ContractPayrollCalculator)->calculate(visitingContract('unpaid'), 'T1', teacherSessions(22, 2));
    expect($r['total_minor'])->toBe(1760000);
});

it('pays the substitute, not the planned teacher', function () {
    $session = [['status' => 'completed', 'planned_teacher_id' => 'T1', 'actual_teacher_id' => 'T2', 'minutes' => 40]];
    $calc = new ContractPayrollCalculator;
    expect($calc->calculate(visitingContract(), 'T2', $session)['total_minor'])->toBe(80000);
    expect($calc->calculate(visitingContract(), 'T1', $session)['total_minor'])->toBe(0);
});

it('ignores other teachers sessions', function () {
    $r = (new ContractPayrollCalculator)->calculate(visitingContract(), 'T1', teacherSessions(5, 0, 'T9'));
    expect($r['total_minor'])->toBe(0);
});

it('part-time hourly: 20 x 45 min at Rs 600/h = Rs 9,000', function () {
    $contract = ['pay_basis' => 'hourly', 'rate_minor' => 60000, 'pay_rules' => []];
    $list = [];
    for ($i = 0; $i < 20; $i++) {
        $list[] = ['status' => 'completed', 'planned_teacher_id' => 'T1', 'actual_teacher_id' => null, 'minutes' => 45];
    }
    expect((new ContractPayrollCalculator)->calculate($contract, 'T1', $list)['total_minor'])->toBe(900000);
});

it('reports a line per bucket', function () {
    $r = (new ContractPayrollCalculator)->calculate(visitingContract('paid'), 'T1', teacherSessions(3, 1));
    expect($r['lines'])->toHaveCount(2);
    expect($r['lines'][0]['kind'])->toBe('completed');
});

it('rejects monthly contracts (those use the salary run, not sessions)', function () {
    $contract = ['pay_basis' => 'monthly', 'rate_minor' => 5000000];
    expect(fn () => (new ContractPayrollCalculator)->calculate($contract, 'T1', []))->toThrow(InvalidArgumentException::class);
});
