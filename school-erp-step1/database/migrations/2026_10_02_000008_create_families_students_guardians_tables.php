<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Fees are family-wise, so `families` is the billing anchor; students belong to exactly one family. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('family_code', 30);
            $t->string('display_name');
            $t->jsonb('address')->default('{}');
            $t->string('status', 20)->default('active');
            $t->jsonb('custom')->default('{}');
            $t->timestampsTz();
            $t->softDeletesTz();

            $t->unique(['organization_id', 'family_code']);
            $t->unique(['organization_id', 'id']);
        });
        DB::statement("ALTER TABLE families ADD CONSTRAINT families_status_chk CHECK (status IN ('active','inactive'))");

        Schema::create('students', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('family_id');
            $t->string('registration_number', 40);
            $t->string('first_name');
            $t->string('last_name')->nullable();
            $t->string('gender', 10)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->date('admission_date')->nullable();
            $t->string('lifecycle_status', 20)->default('inquiry');
            $t->jsonb('medical')->default('{}');
            $t->jsonb('custom')->default('{}');
            $t->timestampsTz();
            $t->softDeletesTz();

            $t->foreign(['organization_id', 'family_id'])->references(['organization_id', 'id'])->on('families')->restrictOnDelete();
            $t->unique(['organization_id', 'registration_number']);
            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'family_id']);
            $t->index(['organization_id', 'lifecycle_status']);
        });
        DB::statement("ALTER TABLE students ADD CONSTRAINT students_gender_chk CHECK (gender IS NULL OR gender IN ('male','female','other'))");
        DB::statement("ALTER TABLE students ADD CONSTRAINT students_lifecycle_chk CHECK (lifecycle_status IN ('inquiry','applicant','active','transferred','withdrawn','graduated','alumni'))");
        DB::statement("CREATE INDEX students_name_trgm_idx ON students USING gin (lower(first_name || ' ' || COALESCE(last_name,'')) gin_trgm_ops)");
        DB::statement('CREATE INDEX students_custom_gin_idx ON students USING gin (custom jsonb_path_ops)');

        Schema::create('guardians', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('family_id');
            $t->ulid('user_id')->nullable();       // portal login
            $t->string('relation', 20);
            $t->string('full_name');
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('occupation')->nullable();
            $t->text('national_id_encrypted')->nullable();   // encrypted at app layer (CNIC)
            $t->boolean('is_fee_payer')->default(false);
            $t->boolean('is_primary')->default(false);
            $t->jsonb('custom')->default('{}');
            $t->timestampsTz();

            $t->foreign(['organization_id', 'family_id'])->references(['organization_id', 'id'])->on('families')->restrictOnDelete();
            $t->foreign(['organization_id', 'user_id'])->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'family_id']);
            $t->index(['organization_id', 'phone']);
        });
        DB::statement("ALTER TABLE guardians ADD CONSTRAINT guardians_relation_chk CHECK (relation IN ('father','mother','guardian','other'))");

        Schema::create('guardian_student', function (Blueprint $t) {
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('guardian_id');
            $t->ulid('student_id');
            $t->boolean('can_pickup')->default(true);
            $t->boolean('pickup_restricted')->default(false);
            $t->boolean('is_emergency_contact')->default(false);
            $t->timestampsTz();

            $t->primary(['guardian_id', 'student_id']);
            $t->foreign(['organization_id', 'guardian_id'])->references(['organization_id', 'id'])->on('guardians')->cascadeOnDelete();
            $t->foreign(['organization_id', 'student_id'])->references(['organization_id', 'id'])->on('students')->cascadeOnDelete();
            $t->index(['organization_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_student');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('students');
        Schema::dropIfExists('families');
    }
};
