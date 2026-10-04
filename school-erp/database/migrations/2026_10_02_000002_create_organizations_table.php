<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('status', 20)->default('active');
            $t->string('plan_key', 50)->default('standard');
            $t->string('preset_key', 100)->nullable();
            $t->string('preset_version', 20)->nullable();
            $t->string('timezone', 64)->default('Asia/Karachi');
            $t->char('currency', 3)->default('PKR');
            $t->string('default_locale', 10)->default('en');
            $t->jsonb('settings')->default('{}');
            $t->timestampsTz();
            $t->softDeletesTz();
        });

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_chk CHECK (status IN ('active','suspended','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
