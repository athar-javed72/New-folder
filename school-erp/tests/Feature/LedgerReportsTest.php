<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access\ScopeContext;
use App\Services\Ledger\DefaultChartOfAccounts;
use App\Services\Ledger\LedgerAccessDenied;
use App\Services\Ledger\LedgerPoster;
use App\Services\Ledger\LedgerReports;
use App\Services\Ledger\LedgerValidationException;
use App\Services\Ledger\PostingLine;
use App\Services\Ledger\PostingRequest;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function assignReportsRole(User $user, string $roleKey, string $scopeType = 'org', ?string $scopeId = null): void
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

/**
 * @return array{org: string, campus: string, actor: User, family: string, poster: LedgerPoster, reports: LedgerReports, scope: ScopeContext}
 */
function reportsFixture(): array
{
    $org = F::org();
    $campus = F::campus($org);
    $userId = F::user($org);
    $actor = User::query()->findOrFail($userId);
    assignReportsRole($actor, 'org_admin');
    $family = F::family($org);
    DefaultChartOfAccounts::seedFor($org);
    $poster = new LedgerPoster;
    $reports = app(LedgerReports::class);
    $scope = new ScopeContext(organizationId: $org);

    return compact('org', 'campus', 'actor', 'family', 'poster', 'reports', 'scope');
}

it('ensures total debit equals total credit in every trial balance returned', function () {
    $f = reportsFixture();

    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'TB test',
        lines: [
            PostingLine::debit('fee_receivable', 5_000_000, $f['family']),
            PostingLine::credit('tuition_income', 5_000_000),
        ],
    ));

    $tb = $f['reports']->trialBalance($f['actor'], $f['org'], Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $f['scope']);

    $totalDebit = collect($tb)->sum('debit_minor');
    $totalCredit = collect($tb)->sum('credit_minor');

    expect($totalDebit)->toBe(5_000_000)
        ->and($totalCredit)->toBe(5_000_000);
});

it('includes zero rows for accounts with no activity', function () {
    $f = reportsFixture();

    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'Single pair',
        lines: [
            PostingLine::debit('cash', 1_000_000),
            PostingLine::credit('other_fee_income', 1_000_000),
        ],
    ));

    $tb = $f['reports']->trialBalance($f['actor'], $f['org'], Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $f['scope']);

    expect($tb)->toHaveCount(15);

    $inactiveAccount = collect($tb)->firstWhere('system_key', 'late_fee_income');
    expect($inactiveAccount)->not->toBeNull()
        ->and($inactiveAccount['debit_minor'])->toBe(0)
        ->and($inactiveAccount['credit_minor'])->toBe(0)
        ->and($inactiveAccount['balance_minor'])->toBe(0);
});

it('filters by date range including boundaries and excluding the day before and day after', function () {
    $f = reportsFixture();

    // Day before range: 2026-07-31
    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 7, 31),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'July 31',
        lines: [
            PostingLine::debit('cash', 100_000),
            PostingLine::credit('other_fee_income', 100_000),
        ],
    ));

    // Boundary start: 2026-08-01
    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 8, 1),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'August 1',
        lines: [
            PostingLine::debit('cash', 200_000),
            PostingLine::credit('other_fee_income', 200_000),
        ],
    ));

    // Boundary end: 2026-08-31
    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 8, 31),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'August 31',
        lines: [
            PostingLine::debit('cash', 300_000),
            PostingLine::credit('other_fee_income', 300_000),
        ],
    ));

    // Day after range: 2026-09-01
    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 9, 1),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'September 1',
        lines: [
            PostingLine::debit('cash', 400_000),
            PostingLine::credit('other_fee_income', 400_000),
        ],
    ));

    $tb = $f['reports']->trialBalance($f['actor'], $f['org'], Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $f['scope']);

    $cashRow = collect($tb)->firstWhere('system_key', 'cash');
    // Only 200,000 (Aug 1) + 300,000 (Aug 31) = 500,000
    expect($cashRow['debit_minor'])->toBe(500_000);
});

it('filters by campus and returns only that campus entries', function () {
    $f = reportsFixture();
    $campusA = $f['campus'];
    $campusB = F::campus($f['org']);

    // Entry on campus A
    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $campusA,
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'Campus A entry',
        lines: [
            PostingLine::debit('cash', 100_000),
            PostingLine::credit('other_fee_income', 100_000),
        ],
    ));

    // Entry on campus B
    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $campusB,
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'Campus B entry',
        lines: [
            PostingLine::debit('cash', 250_000),
            PostingLine::credit('other_fee_income', 250_000),
        ],
    ));

    // Filter campus A
    $tbA = $f['reports']->trialBalance($f['actor'], $f['org'], Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $f['scope'], $campusA);
    $cashA = collect($tbA)->firstWhere('system_key', 'cash');
    expect($cashA['debit_minor'])->toBe(100_000);

    // Filter campus B
    $tbB = $f['reports']->trialBalance($f['actor'], $f['org'], Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $f['scope'], $campusB);
    $cashB = collect($tbB)->firstWhere('system_key', 'cash');
    expect($cashB['debit_minor'])->toBe(250_000);
});

it('throws LedgerValidationException with appropriate reasons for invalid inputs', function () {
    $f = reportsFixture();

    // 1. Invalid date range (from after to)
    expect(fn () => $f['reports']->trialBalance(
        $f['actor'],
        $f['org'],
        Carbon::create(2026, 8, 31),
        Carbon::create(2026, 8, 1),
        $f['scope'],
    ))->toThrow(function (LedgerValidationException $e) {
        expect($e->reason)->toBe('invalid_range');
    });

    // 2. Unknown campus ID
    expect(fn () => $f['reports']->trialBalance(
        $f['actor'],
        $f['org'],
        Carbon::create(2026, 8, 1),
        Carbon::create(2026, 8, 31),
        $f['scope'],
        F::id(),
    ))->toThrow(function (LedgerValidationException $e) {
        expect($e->reason)->toBe('unknown_campus');
    });

    // 3. Unknown family ID in familyBalance
    expect(fn () => $f['reports']->familyBalance(
        $f['actor'],
        $f['org'],
        F::id(),
        'fee_receivable',
        $f['scope'],
    ))->toThrow(function (LedgerValidationException $e) {
        expect($e->reason)->toBe('unknown_family');
    });

    // 4. Unknown system key in familyBalance
    expect(fn () => $f['reports']->familyBalance(
        $f['actor'],
        $f['org'],
        $f['family'],
        'non_existent_key',
        $f['scope'],
    ))->toThrow(function (LedgerValidationException $e) {
        expect($e->reason)->toBe('unknown_account');
    });
});

it('denies access with exact message "Not allowed." in all denial scenarios', function () {
    $f = reportsFixture();
    $from = Carbon::create(2026, 8, 1);
    $to = Carbon::create(2026, 8, 31);

    // Scenario 1: User without permission
    $unpermittedUser = User::query()->findOrFail(F::user($f['org']));
    expect(fn () => $f['reports']->trialBalance($unpermittedUser, $f['org'], $from, $to, $f['scope']))
        ->toThrow(LedgerAccessDenied::class, 'Not allowed.');

    // Scenario 2: Actor belonging to another organization
    $otherOrg = F::org();
    $foreignUser = User::query()->findOrFail(F::user($otherOrg));
    assignReportsRole($foreignUser, 'org_admin');
    expect(fn () => $f['reports']->trialBalance($foreignUser, $f['org'], $from, $to, $f['scope']))
        ->toThrow(LedgerAccessDenied::class, 'Not allowed.');

    // Scenario 3: Campus-scoped caller asking for the whole organization (campusId null)
    $campusUser = User::query()->findOrFail(F::user($f['org']));
    assignReportsRole($campusUser, 'campus_admin', 'campus', $f['campus']);
    $campusScope = new ScopeContext(organizationId: $f['org'], campusId: $f['campus']);

    expect(fn () => $f['reports']->trialBalance($campusUser, $f['org'], $from, $to, $campusScope, null))
        ->toThrow(LedgerAccessDenied::class, 'Not allowed.');

    // Scenario 4: Campus-scoped caller asking for familyBalance
    expect(fn () => $f['reports']->familyBalance($campusUser, $f['org'], $f['family'], 'fee_receivable', $campusScope))
        ->toThrow(LedgerAccessDenied::class, 'Not allowed.');

    // Scenario 5: Campus-scoped caller asking for another campus
    $otherCampus = F::campus($f['org']);
    expect(fn () => $f['reports']->trialBalance($campusUser, $f['org'], $from, $to, $campusScope, $otherCampus))
        ->toThrow(LedgerAccessDenied::class, 'Not allowed.');
});

it('allows super admin with null organization to view reports', function () {
    $f = reportsFixture();

    $superAdminId = F::id();
    DB::table('users')->insert([
        'id' => $superAdminId,
        'organization_id' => null,
        'name' => 'Super Administrator',
        'email' => 'superreports@example.test',
        'is_super_admin' => true,
    ]);
    $superAdmin = User::query()->findOrFail($superAdminId);

    $tb = $f['reports']->trialBalance($superAdmin, $f['org'], Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $f['scope']);
    expect($tb)->toBeArray()->toHaveCount(15);

    $bal = $f['reports']->familyBalance($superAdmin, $f['org'], $f['family'], 'fee_receivable', $f['scope']);
    expect($bal)->toBe(0);
});

it('never leaks another organizations data into trial balance', function () {
    $f1 = reportsFixture();
    $f2 = reportsFixture();

    // Post 1,000,000 in org 1
    $f1['poster']->post(new PostingRequest(
        organizationId: $f1['org'],
        campusId: $f1['campus'],
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f1['actor']->id,
        memo: 'Org 1',
        lines: [
            PostingLine::debit('cash', 1_000_000),
            PostingLine::credit('other_fee_income', 1_000_000),
        ],
    ));

    // Post 9,000,000 in org 2
    $f2['poster']->post(new PostingRequest(
        organizationId: $f2['org'],
        campusId: $f2['campus'],
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f2['actor']->id,
        memo: 'Org 2',
        lines: [
            PostingLine::debit('cash', 9_000_000),
            PostingLine::credit('other_fee_income', 9_000_000),
        ],
    ));

    // Org 1 trial balance must have exactly 1,000,000
    $tb1 = $f1['reports']->trialBalance($f1['actor'], $f1['org'], Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $f1['scope']);
    $cash1 = collect($tb1)->firstWhere('system_key', 'cash');
    expect($cash1['debit_minor'])->toBe(1_000_000);

    // Org 2 trial balance must have exactly 9,000,000
    $tb2 = $f2['reports']->trialBalance($f2['actor'], $f2['org'], Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $f2['scope']);
    $cash2 = collect($tb2)->firstWhere('system_key', 'cash');
    expect($cash2['debit_minor'])->toBe(9_000_000);
});
