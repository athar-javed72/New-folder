<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Parallel calendars (e.g. Matric and Cambridge) = separate programs, each with its own calendars. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('key', 50);                 // matric, cambridge, ib ...
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->jsonb('settings')->default('{}');
            $t->timestampsTz();

            $t->unique(['organization_id', 'key']);
            $t->unique(['organization_id', 'id']);
        });

        Schema::create('academic_calendars', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('campus_id')->nullable();     // null = organization-wide
            $t->ulid('program_id');
            $t->string('name', 100);               // e.g. 2026-27
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 20)->default('draft');
            $t->jsonb('settings')->default('{}');
            $t->timestampsTz();

            $t->foreign(['organization_id', 'campus_id'])->references(['organization_id', 'id'])->on('campuses')->cascadeOnDelete();
            $t->foreign(['organization_id', 'program_id'])->references(['organization_id', 'id'])->on('programs')->restrictOnDelete();
            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'program_id', 'status']);
        });
        DB::statement("ALTER TABLE academic_calendars ADD CONSTRAINT academic_calendars_status_chk CHECK (status IN ('draft','active','closed'))");
        DB::statement('ALTER TABLE academic_calendars ADD CONSTRAINT academic_calendars_dates_chk CHECK (ends_on > starts_on)');
        DB::statement("CREATE UNIQUE INDEX academic_calendars_name_uq ON academic_calendars (organization_id, COALESCE(campus_id,'-'), program_id, name)");

        Schema::create('terms', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('academic_calendar_id');
            $t->string('key', 30);
            $t->string('name', 100);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->smallInteger('sort')->default(0);
            $t->timestampsTz();

            $t->foreign(['organization_id', 'academic_calendar_id'])->references(['organization_id', 'id'])->on('academic_calendars')->cascadeOnDelete();
            $t->unique(['academic_calendar_id', 'key']);
        });
        DB::statement('ALTER TABLE terms ADD CONSTRAINT terms_dates_chk CHECK (ends_on > starts_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_calendars');
        Schema::dropIfExists('programs');
    }
};
