<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access\ScopeContext;
use App\Services\Ledger\DefaultChartOfAccounts;
use App\Services\Ledger\LedgerAccessDenied;
use App\Services\Ledger\LedgerPeriodService;
use App\Services\Ledger\LedgerPoster;
use App\Services\Ledger\LedgerReports;
use App\Services\Ledger\LedgerTenancy;
use App\Services\Ledger\PostingLine;
use App\Services\Ledger\PostingRequest;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function tenancyAssignRole(User $user, string $roleKey): void
{
    /** @var Role $role */
    $role = Role::query()->where('key', $roleKey)->where('is_system', true)->firstOrFail();

    RoleAssignment::query()->create([
        'organization_id' => $user->organization_id,
        'user_id' => $user->id,
        'role_id' => $role->id,
        'scope_type' => 'org',
        'scope_id' => null,
        'status' => 'active',
        'starts_at' => now()->subMinute(),
        'ends_at' => null,
    ]);
}

it('denies a non-super-admin with null organization_id from reverse, trialBalance, familyBalance and close when the schema allows that row', function () {
    // Attempt raw insert of organization_id null + is_super_admin false.
    // Schema users_super_admin_org_chk already forbids it; skip when rejected.
    $nullOrgUserId = F::id();
    try {
        DB::table('users')->insert([
            'id' => $nullOrgUserId,
            'organization_id' => null,
            'name' => 'Null Org User',
            'email' => strtolower($nullOrgUserId).'@example.test',
            'is_super_admin' => false,
        ]);
    } catch (QueryException) {
        // Schema already forbids non-super-admin users with null organization_id.
        $this->markTestSkipped('Schema already forbids non-super-admin users with null organization_id.');
    }

    $org = F::org();
    $campus = F::campus($org);
    $family = F::family($org);
    $actorId = F::user($org);
    $actor = User::query()->findOrFail($actorId);
    tenancyAssignRole($actor, 'org_admin');
    DefaultChartOfAccounts::seedFor($org);

    $poster = new LedgerPoster;
    $original = $poster->post(new PostingRequest(
        organizationId: $org,
        campusId: $campus,
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $actorId,
        memo: null,
        lines: [
            PostingLine::debit('fee_receivable', 1_000_000, $family),
            PostingLine::credit('tuition_income', 1_000_000),
        ],
    ));

    $nullOrgUser = User::query()->findOrFail($nullOrgUserId);
    $reports = app(LedgerReports::class);
    $periodService = new LedgerPeriodService;
    $period = $periodService->forDate($org, Carbon::create(2026, 8, 15));
    $scope = new ScopeContext(organizationId: $org);

    $entriesBefore = DB::table('journal_entries')->count();
    $linesBefore = DB::table('journal_lines')->count();
    $auditsBefore = DB::table('audit_logs')->where('action', 'ledger.period.closed')->count();
    $periodStatusBefore = DB::table('ledger_periods')->where('id', $period->id)->value('status');

    expect(fn () => $poster->reverse($original, $nullOrgUser, Carbon::create(2026, 8, 20)))
        ->toThrow(LedgerAccessDenied::class);
    expect(fn () => $reports->trialBalance($nullOrgUser, $org, Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $scope))
        ->toThrow(LedgerAccessDenied::class);
    expect(fn () => $reports->familyBalance($nullOrgUser, $org, $family, 'fee_receivable', $scope))
        ->toThrow(LedgerAccessDenied::class);
    expect(fn () => $periodService->close($nullOrgUser, $period, $scope))
        ->toThrow(LedgerAccessDenied::class);

    expect(DB::table('journal_entries')->count())->toBe($entriesBefore)
        ->and(DB::table('journal_lines')->count())->toBe($linesBefore)
        ->and(DB::table('audit_logs')->where('action', 'ledger.period.closed')->count())->toBe($auditsBefore)
        ->and(DB::table('ledger_periods')->where('id', $period->id)->value('status'))->toBe($periodStatusBefore);
});

it('lets a real super admin with null organization pass the helper', function () {
    $org = F::org();
    $superAdminId = F::id();
    DB::table('users')->insert([
        'id' => $superAdminId,
        'organization_id' => null,
        'name' => 'Super Administrator',
        'email' => strtolower($superAdminId).'@example.test',
        'is_super_admin' => true,
    ]);
    $superAdmin = User::query()->findOrFail($superAdminId);

    LedgerTenancy::assertActorInOrganization($superAdmin, $org);

    expect(true)->toBeTrue();
});

it('lets a user of the same organization pass the helper', function () {
    $org = F::org();
    $user = User::query()->findOrFail(F::user($org));

    LedgerTenancy::assertActorInOrganization($user, $org);

    expect(true)->toBeTrue();
});
