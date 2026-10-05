<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B, D-23: encrypted detail tables. Every detail column is ciphertext (Laravel `encrypted` cast).
 * Plain alert flags live on students (has_medical_alert, has_severe_allergy) and guardian_student.pickup_restricted (D-25).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_medical_profiles', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('student_id');
            $t->text('allergies')->nullable();      // encrypted
            $t->text('conditions')->nullable();     // encrypted
            $t->text('medications')->nullable();    // encrypted
            $t->text('doctor_notes')->nullable();   // encrypted
            $t->ulid('updated_by')->nullable();
            $t->timestampsTz();

            $t->foreign(['organization_id', 'student_id'])->references(['organization_id', 'id'])->on('students')->cascadeOnDelete();
            $t->foreign(['organization_id', 'updated_by'])->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'student_id']);
            $t->unique(['organization_id', 'id']);
        });

        Schema::create('student_custody_orders', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('student_id');
            $t->text('details');                    // encrypted court order / custody details
            $t->ulid('created_by')->nullable();
            $t->timestampsTz();

            $t->foreign(['organization_id', 'student_id'])->references(['organization_id', 'id'])->on('students')->cascadeOnDelete();
            $t->foreign(['organization_id', 'created_by'])->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_custody_orders');
        Schema::dropIfExists('student_medical_profiles');
    }
};
