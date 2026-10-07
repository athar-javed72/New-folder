<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\LedgerPeriod;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access\ScopeContext;
use App\Services\Ledger\LedgerAccessDenied;
use App\Services\Ledger\LedgerPeriodService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

/** Same pattern as the Access tests: a real role assignment, resolved by AccessResolver. */
function assignLedgerTestRole(User $user, string $roleKey, string $scopeType = 'org', ?string $scopeId = null): void
{
    /** @var Role $role */
    $role = Role::query()->where('key', $roleKey)->where('is_system', true)->firstOrFail();

    RoleAssignment::query()->create([
        'organization_id' => $user->organization_id,
        'user_id' => $user->id,
        'role_id' => $role->id,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'status' => 'active',
        'starts_at' => now()->subMinute(),
        'ends_at' => null,
    ]);
}

function ledgerTestUser(string $org): User
{
    /** @var User $user */
    $user = User::query()->findOrFail(F::user($org));

    return $user;
}

it('creates a month period for a date and reuses the same row on a second call', function () {
    $org = F::org();
    $svc = new LedgerPeriodService;

    $first = $svc->forDate($org, Carbon::create(2026, 8, 14));
    $second = $svc->forDate($org, Carbon::create(2026, 8, 30));

    expect($first->name)->toBe('2026-08')
        ->and($first->starts_on->toDateString())->toBe('2026-08-01')
        ->and($first->ends_on->toDateString())->toBe('2026-08-31')
        ->and($first->status)->toBe('open')
        ->and($second->id)->toBe($first->id)
        ->and(DB::table('ledger_periods')->where('organization_id', $org)->count())->toBe(1);
});

it('gets month boundaries right for January 31, February 2027, February 2028 and December 31', function (array $ymd, string $name, string $start, string $end) {
    $org = F::org();

    $period = (new LedgerPeriodService)->forDate($org, Carbon::create(...$ymd));

    expect($period->name)->toBe($name)
        ->and($period->starts_on->toDateString())->toBe($start)
        ->and($period->ends_on->toDateString())->toBe($end);
})->with([
    'January 31' => [[2027, 1, 31], '2027-01', '2027-01-01', '2027-01-31'],
    'February 28, 2027' => [[2027, 2, 28], '2027-02', '2027-02-01', '2027-02-28'],
    'February 29, 2028 (leap year)' => [[2028, 2, 29], '2028-02', '2028-02-01', '2028-02-29'],
    'December 31' => [[2027, 12, 31], '2027-12', '2027-12-01', '2027-12-31'],
]);

it('returns an existing closed period for a date inside it and creates nothing', function () {
    $org = F::org();
    $closer = F::user($org);
    $closed = F::period($org, [
        'name' => '2026-06',
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-30',
        'status' => 'closed',
        'closed_at' => now(),
        'closed_by' => $closer,
    ]);

    $period = (new LedgerPeriodService)->forDate($org, Carbon::create(2026, 6, 15));

    expect($period->id)->toBe($closed)
        ->and($period->status)->toBe('closed')
        ->and(DB::table('ledger_periods')->where('organization_id', $org)->count())->toBe(1);
});

it('gives two organizations separate periods for the same date', function () {
    $orgA = F::org();
    $orgB = F::org();
    $svc = new LedgerPeriodService;

    $a = $svc->forDate($orgA, Carbon::create(2026, 9, 10));
    $b = $svc->forDate($orgB, Carbon::create(2026, 9, 10));

    expect($a->id)->not->toBe($b->id)
        ->and($a->organization_id)->toBe($orgA)
        ->and($b->organization_id)->toBe($orgB)
        ->and($a->name)->toBe('2026-09')
        ->and($b->name)->toBe('2026-09');
});

it('lets an actor holding ledger.period.close close the period', function () {
    $org = F::org();
    $actor = ledgerTestUser($org);
    assignLedgerTestRole($actor, 'org_admin');
    $svc = new LedgerPeriodService;
    $period = $svc->forDate($org, Carbon::create(2026, 8, 14));

    $closed = $svc->close($actor, $period, new ScopeContext(organizationId: $org));

    $row = DB::table('ledger_periods')->where('id', $period->id)->first();
    expect($closed->status)->toBe('closed')
        ->and($row->status)->toBe('closed')
        ->and($row->closed_at)->not->toBeNull()
        ->and($row->closed_by)->toBe($actor->id);
});

it('denies an actor without ledger.period.close, writes no audit row and leaves the period open', function () {
    $org = F::org();
    $campus = F::campus($org);
    $actor = ledgerTestUser($org);
    assignLedgerTestRole($actor, 'campus_admin', 'campus', $campus); // campus admin does not hold ledger.period.close
    $svc = new LedgerPeriodService;
    $period = $svc->forDate($org, Carbon::create(2026, 8, 14));

    expect(fn () => $svc->close($actor, $period, new ScopeContext(organizationId: $org, campusId: $campus)))
        ->toThrow(LedgerAccessDenied::class, 'Not allowed.');

    expect(DB::table('ledger_periods')->where('id', $period->id)->value('status'))->toBe('open')
        ->and(AuditLog::query()->where('action', 'ledger.period.closed')->count())->toBe(0);
});

it('denies an actor of another organization, writes no audit row and leaves the period open', function () {
    $orgA = F::org();
    $orgB = F::org();
    $outsider = ledgerTestUser($orgB);
    assignLedgerTestRole($outsider, 'org_admin');
    $svc = new LedgerPeriodService;
    $period = $svc->forDate($orgA, Carbon::create(2026, 8, 14));

    expect(fn () => $svc->close($outsider, $period, new ScopeContext(organizationId: $orgA)))
        ->toThrow(LedgerAccessDenied::class, 'Not allowed.');

    expect(DB::table('ledger_periods')->where('id', $period->id)->value('status'))->toBe('open')
        ->and(AuditLog::query()->where('action', 'ledger.period.closed')->count())->toBe(0);
});

it('leaves exactly one audit row when a period is closed twice', function () {
    $org = F::org();
    $actor = ledgerTestUser($org);
    assignLedgerTestRole($actor, 'org_admin');
    $svc = new LedgerPeriodService;
    $period = $svc->forDate($org, Carbon::create(2026, 8, 14));
    $scope = new ScopeContext(organizationId: $org);

    $first = $svc->close($actor, $period, $scope);
    $closedAt = DB::table('ledger_periods')->where('id', $period->id)->value('closed_at');
    $second = $svc->close($actor, $period, $scope);

    expect($second->status)->toBe('closed')
        ->and($second->id)->toBe($first->id)
        ->and(DB::table('ledger_periods')->where('id', $period->id)->value('closed_at'))->toBe($closedAt)
        ->and(AuditLog::query()->where('action', 'ledger.period.closed')->where('subject_id', $period->id)->count())->toBe(1);
});

it('writes an audit row with action ledger.period.closed, subject ledger_period and only the period name in meta', function () {
    $org = F::org();
    $actor = ledgerTestUser($org);
    assignLedgerTestRole($actor, 'org_admin');
    $svc = new LedgerPeriodService;
    $period = $svc->forDate($org, Carbon::create(2026, 8, 14));

    $svc->close($actor, $period, new ScopeContext(organizationId: $org));

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->where('action', 'ledger.period.closed')->firstOrFail();
    expect($audit->organization_id)->toBe($org)
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->subject_type)->toBe('ledger_period')
        ->and($audit->subject_id)->toBe($period->id)
        ->and($audit->meta)->toBe(['period' => '2026-08']);
});

it('rejects a raw update that reopens a closed period', function () {
    $org = F::org();
    $actor = ledgerTestUser($org);
    assignLedgerTestRole($actor, 'org_admin');
    $svc = new LedgerPeriodService;
    $period = $svc->forDate($org, Carbon::create(2026, 8, 14));
    $svc->close($actor, $period, new ScopeContext(organizationId: $org));

    expect(fn () => DB::transaction(function () use ($period) {
        DB::table('ledger_periods')->where('id', $period->id)->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
    }))->toThrow(QueryException::class);

    expect(LedgerPeriod::query()->findOrFail($period->id)->status)->toBe('closed');
});
