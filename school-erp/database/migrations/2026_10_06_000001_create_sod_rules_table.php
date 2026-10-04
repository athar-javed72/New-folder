<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-22 Separation of duties: one user may not exercise both permissions on the same record.
 * organization_id NULL = system rule (applies to every organization).
 * permission_a < permission_b (alphabetical) so each pair is stored once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sod_rules', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so FKs can rely on it (fluent ->primary() is emitted last)
            $t->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $t->string('permission_a', 100);
            $t->string('permission_b', 100);
            $t->string('record_type', 50);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
        });

        DB::statement('ALTER TABLE sod_rules ADD CONSTRAINT sod_rules_pair_order_chk CHECK (permission_a < permission_b)');
        DB::statement("CREATE UNIQUE INDEX sod_rules_unique ON sod_rules (COALESCE(organization_id, '-'), permission_a, permission_b, record_type)");
    }

    public function down(): void
    {
        Schema::dropIfExists('sod_rules');
    }
};
