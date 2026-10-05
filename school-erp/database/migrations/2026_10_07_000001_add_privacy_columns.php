<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2B, D-23 to D-26: encrypted identifiers with blind indexes, plain alert flags.
 *
 * - Ciphertext columns are text; blind-index columns are char(64) lowercase hex (HMAC-SHA256).
 * - Student B-Form and passport hashes are unique per organization (live rows only).
 * - Guardian and employee CNIC hashes are indexed, NOT unique (D-26).
 * - students.medical (plain jsonb from Step 1) is removed: medical details move to the encrypted
 *   student_medical_profiles table. The migration refuses to run if any row still holds data there,
 *   so nothing is ever lost silently.
 */
return new class extends Migration
{
    private const HEX64 = "~ '^[0-9a-f]{64}$'";

    public function up(): void
    {
        $leftover = DB::selectOne("SELECT COUNT(*) AS c FROM students WHERE medical IS NOT NULL AND medical <> '{}'::jsonb");
        if ((int) $leftover->c > 0) {
            throw new RuntimeException('students.medical still holds data in ' . $leftover->c . ' row(s). Move it to student_medical_profiles first.');
        }

        DB::statement('ALTER TABLE students
            ADD COLUMN b_form_encrypted text NULL,
            ADD COLUMN b_form_hash char(64) NULL,
            ADD COLUMN passport_encrypted text NULL,
            ADD COLUMN passport_hash char(64) NULL,
            ADD COLUMN has_medical_alert boolean NOT NULL DEFAULT false,
            ADD COLUMN has_severe_allergy boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE students DROP COLUMN medical');

        DB::statement('ALTER TABLE students ADD CONSTRAINT students_b_form_hash_chk CHECK (b_form_hash IS NULL OR b_form_hash ' . self::HEX64 . ')');
        DB::statement('ALTER TABLE students ADD CONSTRAINT students_passport_hash_chk CHECK (passport_hash IS NULL OR passport_hash ' . self::HEX64 . ')');
        DB::statement('CREATE UNIQUE INDEX students_b_form_hash_uq ON students (organization_id, b_form_hash) WHERE b_form_hash IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX students_passport_hash_uq ON students (organization_id, passport_hash) WHERE passport_hash IS NOT NULL AND deleted_at IS NULL');

        foreach (['guardians', 'employees'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN national_id_hash char(64) NULL");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_national_id_hash_chk CHECK (national_id_hash IS NULL OR national_id_hash " . self::HEX64 . ')');
            DB::statement("CREATE INDEX {$table}_national_id_hash_idx ON {$table} (organization_id, national_id_hash) WHERE national_id_hash IS NOT NULL");
        }
    }

    public function down(): void
    {
        foreach (['guardians', 'employees'] as $table) {
            DB::statement("DROP INDEX IF EXISTS {$table}_national_id_hash_idx");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_national_id_hash_chk");
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS national_id_hash");
        }

        DB::statement('DROP INDEX IF EXISTS students_passport_hash_uq');
        DB::statement('DROP INDEX IF EXISTS students_b_form_hash_uq');
        DB::statement('ALTER TABLE students DROP CONSTRAINT IF EXISTS students_passport_hash_chk');
        DB::statement('ALTER TABLE students DROP CONSTRAINT IF EXISTS students_b_form_hash_chk');
        DB::statement("ALTER TABLE students ADD COLUMN medical jsonb NOT NULL DEFAULT '{}'");
        DB::statement('ALTER TABLE students
            DROP COLUMN IF EXISTS has_severe_allergy,
            DROP COLUMN IF EXISTS has_medical_alert,
            DROP COLUMN IF EXISTS passport_hash,
            DROP COLUMN IF EXISTS passport_encrypted,
            DROP COLUMN IF EXISTS b_form_hash,
            DROP COLUMN IF EXISTS b_form_encrypted');
    }
};
