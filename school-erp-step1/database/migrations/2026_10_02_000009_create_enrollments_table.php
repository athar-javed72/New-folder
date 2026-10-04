<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** An enrollment = student in a campus + calendar + grade (+ section). Fees and attendance hang off it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('student_id');
            $t->ulid('campus_id');
            $t->ulid('academic_calendar_id');
            $t->ulid('grade_id');
            $t->ulid('section_id')->nullable();
            $t->string('roll_number', 30)->nullable();
            $t->string('status', 20)->default('active');
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->string('ended_reason', 100)->nullable();
            $t->ulid('previous_enrollment_id')->nullable();
            $t->jsonb('custom')->default('{}');
            $t->timestampsTz();
            $t->softDeletesTz();

            $t->foreign(['organization_id', 'student_id'])->references(['organization_id', 'id'])->on('students')->restrictOnDelete();
            $t->foreign(['organization_id', 'campus_id'])->references(['organization_id', 'id'])->on('campuses')->restrictOnDelete();
            $t->foreign(['organization_id', 'academic_calendar_id'])->references(['organization_id', 'id'])->on('academic_calendars')->restrictOnDelete();
            $t->foreign(['organization_id', 'grade_id'])->references(['organization_id', 'id'])->on('grades')->restrictOnDelete();
            $t->foreign(['organization_id', 'section_id'])->references(['organization_id', 'id'])->on('sections')->restrictOnDelete();
            $t->foreign('previous_enrollment_id')->references('id')->on('enrollments')->nullOnDelete();

            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'campus_id', 'academic_calendar_id', 'grade_id', 'status'], 'enrollments_roster_idx');
            $t->index(['organization_id', 'student_id', 'status']);
            $t->index(['organization_id', 'section_id']);
        });

        DB::statement("ALTER TABLE enrollments ADD CONSTRAINT enrollments_status_chk CHECK (status IN ('active','promoted','retained','transferred','withdrawn','graduated'))");
        DB::statement('ALTER TABLE enrollments ADD CONSTRAINT enrollments_dates_chk CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement("ALTER TABLE enrollments ADD CONSTRAINT enrollments_active_end_chk CHECK ((status = 'active' AND end_date IS NULL) OR (status <> 'active' AND end_date IS NOT NULL))");
        // A student can have only one live enrollment at a time.
        DB::statement("CREATE UNIQUE INDEX enrollments_one_active_per_student_uq ON enrollments (student_id) WHERE status = 'active' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
