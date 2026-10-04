<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave and payroll rules hang on the CONTRACT TYPE, with per-contract overrides in
 * employment_contracts.policy_overrides. leave_ledgers is append-only; balance = SUM(qty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('user_id')->nullable();
            $t->string('employee_code', 30);
            $t->string('full_name');
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->text('national_id_encrypted')->nullable();
            $t->date('joined_on')->nullable();
            $t->string('status', 20)->default('active');
            $t->jsonb('custom')->default('{}');
            $t->timestampsTz();
            $t->softDeletesTz();

            $t->foreign(['organization_id', 'user_id'])->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'employee_code']);
            $t->unique(['organization_id', 'id']);
        });
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_chk CHECK (status IN ('active','inactive','left'))");

        Schema::create('contract_types', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('key', 50);                  // permanent, probation, fixed_term, visiting, part_time
            $t->string('name');
            $t->string('pay_basis', 20);
            $t->string('deduction_policy_key', 50)->default('standard');   // standard | none
            $t->jsonb('leave_rules')->default('{}');
            $t->jsonb('pay_rules')->default('{}');
            $t->jsonb('benefits')->default('{}');
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();

            $t->unique(['organization_id', 'key']);
            $t->unique(['organization_id', 'id']);
        });
        DB::statement("ALTER TABLE contract_types ADD CONSTRAINT contract_types_pay_basis_chk CHECK (pay_basis IN ('monthly','hourly','per_session','daily'))");

        Schema::create('leave_types', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('key', 50);                  // casual, sick, annual, unpaid
            $t->string('name');
            $t->boolean('is_paid')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();

            $t->unique(['organization_id', 'key']);
            $t->unique(['organization_id', 'id']);
        });

        Schema::create('employment_contracts', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('employee_id');
            $t->ulid('campus_id');
            $t->ulid('contract_type_id');
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->date('probation_ends_on')->nullable();
            $t->string('pay_basis', 20);
            $t->bigInteger('rate_minor');            // paisa: monthly salary, hourly rate or per-session rate
            $t->char('currency', 3)->default('PKR');
            $t->jsonb('working_pattern')->default('{}');
            $t->jsonb('policy_overrides')->default('{}');   // per-contract leave/payroll overrides
            $t->string('status', 20)->default('draft');
            $t->ulid('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->string('ended_reason', 100)->nullable();
            $t->ulid('supersedes_contract_id')->nullable();
            $t->timestampsTz();

            $t->foreign(['organization_id', 'employee_id'])->references(['organization_id', 'id'])->on('employees')->restrictOnDelete();
            $t->foreign(['organization_id', 'campus_id'])->references(['organization_id', 'id'])->on('campuses')->restrictOnDelete();
            $t->foreign(['organization_id', 'contract_type_id'])->references(['organization_id', 'id'])->on('contract_types')->restrictOnDelete();
            $t->foreign('supersedes_contract_id')->references('id')->on('employment_contracts')->nullOnDelete();
            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'employee_id', 'status']);
            $t->index(['organization_id', 'campus_id', 'status']);
        });
        DB::statement("ALTER TABLE employment_contracts ADD CONSTRAINT employment_contracts_pay_basis_chk CHECK (pay_basis IN ('monthly','hourly','per_session','daily'))");
        DB::statement("ALTER TABLE employment_contracts ADD CONSTRAINT employment_contracts_status_chk CHECK (status IN ('draft','active','ended','terminated'))");
        DB::statement('ALTER TABLE employment_contracts ADD CONSTRAINT employment_contracts_rate_chk CHECK (rate_minor >= 0)');
        DB::statement('ALTER TABLE employment_contracts ADD CONSTRAINT employment_contracts_dates_chk CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement("ALTER TABLE employment_contracts ADD CONSTRAINT employment_contracts_overrides_obj_chk CHECK (jsonb_typeof(policy_overrides) = 'object')");
        // One active contract per employee per campus at any time; concurrent contracts at different campuses are allowed.
        DB::statement(<<<'SQL'
ALTER TABLE employment_contracts ADD CONSTRAINT employment_contracts_no_overlap
EXCLUDE USING gist (
    (employee_id::text) WITH =,
    (campus_id::text) WITH =,
    daterange(start_date, end_date, '[]') WITH &&
) WHERE (status = 'active')
SQL);

        Schema::create('leave_entitlements', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('contract_type_id');
            $t->ulid('leave_type_id');
            $t->string('accrual_method', 30);
            $t->decimal('qty', 8, 2)->default(0);
            $t->string('per', 20)->default('year');          // month, year
            $t->smallInteger('per_days_worked')->nullable();
            $t->string('carry_forward', 20)->default('none');
            $t->smallInteger('eligible_after_days')->default(0);
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->string('source', 20)->default('contract_type');
            $t->timestampsTz();

            $t->foreign(['organization_id', 'contract_type_id'])->references(['organization_id', 'id'])->on('contract_types')->cascadeOnDelete();
            $t->foreign(['organization_id', 'leave_type_id'])->references(['organization_id', 'id'])->on('leave_types')->cascadeOnDelete();
            $t->index(['organization_id', 'contract_type_id']);
        });
        DB::statement("ALTER TABLE leave_entitlements ADD CONSTRAINT leave_entitlements_accrual_chk CHECK (accrual_method IN ('fixed_per_period','upfront_per_year','prorated_by_contract_days','per_days_worked','none'))");
        DB::statement("ALTER TABLE leave_entitlements ADD CONSTRAINT leave_entitlements_carry_chk CHECK (carry_forward IN ('none','within_year','to_next_year'))");

        Schema::create('leave_ledgers', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->ulid('contract_id');
            $t->ulid('leave_type_id');
            $t->string('entry_type', 20);
            $t->decimal('qty', 8, 2);                // + grants, - consumption
            $t->date('entry_date');
            $t->string('period_key', 20)->nullable();
            $t->string('ref_type', 50)->nullable();
            $t->string('ref_id', 26)->nullable();
            $t->ulid('reversal_of')->nullable();
            $t->string('idempotency_key', 100)->nullable();
            $t->text('note')->nullable();
            $t->ulid('created_by')->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->foreign(['organization_id', 'contract_id'])->references(['organization_id', 'id'])->on('employment_contracts')->restrictOnDelete();
            $t->foreign(['organization_id', 'leave_type_id'])->references(['organization_id', 'id'])->on('leave_types')->restrictOnDelete();
            $t->foreign('reversal_of')->references('id')->on('leave_ledgers')->restrictOnDelete();
            $t->unique(['contract_id', 'idempotency_key']);
            $t->index(['organization_id', 'contract_id', 'leave_type_id', 'entry_date'], 'leave_ledgers_balance_idx');
        });
        DB::statement("ALTER TABLE leave_ledgers ADD CONSTRAINT leave_ledgers_entry_type_chk CHECK (entry_type IN ('accrual','used','adjustment','expiry','reversal'))");
        DB::statement('ALTER TABLE leave_ledgers ADD CONSTRAINT leave_ledgers_qty_chk CHECK (qty <> 0)');
        DB::statement('CREATE TRIGGER leave_ledgers_append_only BEFORE UPDATE OR DELETE ON leave_ledgers FOR EACH ROW EXECUTE FUNCTION prevent_row_mutation()');
        DB::statement('CREATE VIEW leave_balances AS SELECT organization_id, contract_id, leave_type_id, SUM(qty) AS balance FROM leave_ledgers GROUP BY organization_id, contract_id, leave_type_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS leave_balances');
        foreach (['leave_ledgers', 'leave_entitlements', 'employment_contracts', 'leave_types', 'contract_types', 'employees'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
