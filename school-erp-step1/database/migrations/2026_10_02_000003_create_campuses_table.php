<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->string('code', 30);
            $t->string('name');
            $t->string('status', 20)->default('active');
            $t->jsonb('address')->default('{}');
            $t->string('timezone', 64)->nullable();   // null = inherit organization
            $t->jsonb('settings')->default('{}');
            $t->timestampsTz();
            $t->softDeletesTz();

            $t->unique(['organization_id', 'code']);
            $t->unique(['organization_id', 'id']);    // composite-FK target (blocks cross-tenant references)
        });

        DB::statement("ALTER TABLE campuses ADD CONSTRAINT campuses_status_chk CHECK (status IN ('active','inactive','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('campuses');
    }
};
