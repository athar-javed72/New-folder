<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Plain-SQL row builders for constraint tests. Deliberately bypasses Eloquent so the database itself is under test. */
final class DbFactory
{
    public static function id(): string
    {
        return (string) Str::ulid();
    }

    public static function org(?string $slug = null): string
    {
        $id = self::id();
        DB::table('organizations')->insert(['id' => $id, 'name' => 'Org '.$id, 'slug' => $slug ?? strtolower($id)]);

        return $id;
    }

    public static function campus(string $org, ?string $code = null): string
    {
        $id = self::id();
        DB::table('campuses')->insert(['id' => $id, 'organization_id' => $org, 'code' => $code ?? substr($id, -8), 'name' => 'Campus']);

        return $id;
    }

    public static function user(string $org, array $over = []): string
    {
        $id = self::id();
        DB::table('users')->insert($over + ['id' => $id, 'organization_id' => $org, 'name' => 'User', 'email' => strtolower($id).'@example.test']);

        return $id;
    }

    /** @return array{program:string, calendar:string, grade:string, section:string} */
    public static function academics(string $org, string $campus): array
    {
        $program = self::id();
        DB::table('programs')->insert(['id' => $program, 'organization_id' => $org, 'key' => 'p'.substr($program, -6), 'name' => 'Matric']);
        $calendar = self::id();
        DB::table('academic_calendars')->insert([
            'id' => $calendar, 'organization_id' => $org, 'campus_id' => $campus, 'program_id' => $program,
            'name' => '2026-27', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'active',
        ]);
        $grade = self::id();
        DB::table('grades')->insert(['id' => $grade, 'organization_id' => $org, 'program_id' => $program, 'key' => 'g5', 'name' => 'Grade 5', 'level' => 5]);
        $section = self::id();
        DB::table('sections')->insert([
            'id' => $section, 'organization_id' => $org, 'campus_id' => $campus, 'academic_calendar_id' => $calendar, 'grade_id' => $grade, 'name' => 'A',
        ]);

        return compact('program', 'calendar', 'grade', 'section');
    }

    public static function student(string $org): string
    {
        $family = self::id();
        DB::table('families')->insert(['id' => $family, 'organization_id' => $org, 'family_code' => 'F'.substr($family, -8), 'display_name' => 'Khan Family']);
        $id = self::id();
        DB::table('students')->insert([
            'id' => $id, 'organization_id' => $org, 'family_id' => $family, 'registration_number' => 'R'.substr($id, -8), 'first_name' => 'Ali', 'last_name' => 'Khan',
        ]);

        return $id;
    }

    public static function enrollment(string $org, string $campus, string $student, array $ac, array $over = []): string
    {
        $id = self::id();
        DB::table('enrollments')->insert($over + [
            'id' => $id, 'organization_id' => $org, 'student_id' => $student, 'campus_id' => $campus,
            'academic_calendar_id' => $ac['calendar'], 'grade_id' => $ac['grade'], 'section_id' => $ac['section'],
            'status' => 'active', 'start_date' => '2026-04-01',
        ]);

        return $id;
    }

    /** @return array{employee:string, contract_type:string, leave_type:string} */
    public static function staff(string $org): array
    {
        $employee = self::id();
        DB::table('employees')->insert(['id' => $employee, 'organization_id' => $org, 'employee_code' => 'E'.substr($employee, -8), 'full_name' => 'Sara Ahmed']);
        $type = self::id();
        DB::table('contract_types')->insert(['id' => $type, 'organization_id' => $org, 'key' => 'visiting', 'name' => 'Visiting', 'pay_basis' => 'per_session']);
        $leave = self::id();
        DB::table('leave_types')->insert(['id' => $leave, 'organization_id' => $org, 'key' => 'casual', 'name' => 'Casual']);

        return ['employee' => $employee, 'contract_type' => $type, 'leave_type' => $leave];
    }

    public static function contract(string $org, string $campus, array $staff, array $over = []): string
    {
        $id = self::id();
        DB::table('employment_contracts')->insert($over + [
            'id' => $id, 'organization_id' => $org, 'employee_id' => $staff['employee'], 'campus_id' => $campus, 'contract_type_id' => $staff['contract_type'],
            'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'pay_basis' => 'per_session', 'rate_minor' => 80000, 'status' => 'active',
        ]);

        return $id;
    }

    public static function override(string $org, array $over = []): string
    {
        $id = self::id();
        DB::table('policy_overrides')->insert($over + [
            'id' => $id, 'organization_id' => $org, 'policy_type' => 'late_fee', 'policy_key' => 'default',
            'scopeable_type' => 'campus', 'scopeable_id' => 'CAMPUS1', 'value' => '{}',
            'effective_from' => '2026-04-01', 'effective_to' => null, 'version' => 1, 'status' => 'published',
        ]);

        return $id;
    }

    /** @param array<string, mixed> $over */
    public static function family(string $org, array $over = []): string
    {
        $id = self::id();
        DB::table('families')->insert($over + [
            'id' => $id,
            'organization_id' => $org,
            'family_code' => 'F'.substr($id, -8),
            'display_name' => 'Family '.substr($id, -6),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $over */
    public static function account(string $org, array $over = []): string
    {
        $id = self::id();
        DB::table('accounts')->insert($over + [
            'id' => $id,
            'organization_id' => $org,
            'code' => substr($id, -8),
            'name' => 'Account '.substr($id, -6),
            'type' => 'asset',
            'system_key' => null,
            'requires_family' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $over */
    public static function period(string $org, array $over = []): string
    {
        $id = self::id();
        DB::table('ledger_periods')->insert($over + [
            'id' => $id,
            'organization_id' => $org,
            'name' => '2026-07',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-31',
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $over */
    public static function entry(string $org, string $campus, string $period, string $user, array $over = []): string
    {
        $id = self::id();
        DB::table('journal_entries')->insert($over + [
            'id' => $id,
            'organization_id' => $org,
            'campus_id' => $campus,
            'period_id' => $period,
            'entry_date' => '2026-07-15',
            'source_type' => 'payment',
            'source_id' => self::id(),
            'kind' => 'posting',
            'reversal_of' => null,
            'line_count' => 2,
            'total_minor' => 100000,
            'lines_hash' => hash('sha256', $id),
            'memo' => 'Test entry',
            'created_by' => $user,
            'created_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $over */
    public static function line(string $org, string $entry, string $account, array $over = []): string
    {
        $id = self::id();
        DB::table('journal_lines')->insert($over + [
            'id' => $id,
            'organization_id' => $org,
            'entry_id' => $entry,
            'line_no' => 1,
            'account_id' => $account,
            'family_id' => null,
            'debit_minor' => 100000,
            'credit_minor' => 0,
            'description' => 'Test line',
            'created_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $over */
    public static function sequence(string $org, string $campus, array $over = []): string
    {
        $id = self::id();
        DB::table('number_sequences')->insert($over + [
            'id' => $id,
            'organization_id' => $org,
            'campus_id' => $campus,
            'key' => 'receipt',
            'fiscal_year' => 2026,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Runs $fn inside a savepoint so a failed statement does not poison the surrounding test transaction. */
    public static function exception(callable $fn): ?QueryException
    {
        try {
            DB::transaction($fn);
        } catch (QueryException $e) {
            return $e;
        }

        return null;
    }

    public static function fails(callable $fn): bool
    {
        return self::exception($fn) !== null;
    }
}
