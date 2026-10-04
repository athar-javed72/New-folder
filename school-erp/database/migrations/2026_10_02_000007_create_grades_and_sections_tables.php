<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('program_id');
            $t->string('key', 30);                 // grade-5, o-level-1
            $t->string('name', 100);
            $t->smallInteger('level');             // ordering for promotion
            $t->smallInteger('sort')->default(0);
            $t->timestampsTz();

            $t->foreign(['organization_id', 'program_id'])->references(['organization_id', 'id'])->on('programs')->restrictOnDelete();
            $t->unique(['organization_id', 'program_id', 'key']);
            $t->unique(['organization_id', 'id']);
        });

        Schema::create('sections', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('campus_id');
            $t->ulid('academic_calendar_id');
            $t->ulid('grade_id');
            $t->string('name', 50);                // A, B, Blue ...
            $t->smallInteger('capacity')->nullable();
            $t->timestampsTz();

            $t->foreign(['organization_id', 'campus_id'])->references(['organization_id', 'id'])->on('campuses')->restrictOnDelete();
            $t->foreign(['organization_id', 'academic_calendar_id'])->references(['organization_id', 'id'])->on('academic_calendars')->restrictOnDelete();
            $t->foreign(['organization_id', 'grade_id'])->references(['organization_id', 'id'])->on('grades')->restrictOnDelete();
            $t->unique(['organization_id', 'campus_id', 'academic_calendar_id', 'grade_id', 'name']);
            $t->unique(['organization_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
        Schema::dropIfExists('grades');
    }
};
