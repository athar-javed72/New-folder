<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces Laravel's default users migration (delete 0001_01_01_000000_create_users_table.php).
 * users.organization_id is NULL only for super admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone', 20)->nullable();
            $t->timestampTz('email_verified_at')->nullable();
            $t->string('password')->nullable();
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestampTz('two_factor_confirmed_at')->nullable();
            $t->string('locale', 10)->default('en');
            $t->boolean('is_super_admin')->default(false);
            $t->string('status', 20)->default('active');
            $t->timestampTz('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestampsTz();
            $t->softDeletesTz();

            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'status']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_chk CHECK (status IN ('active','suspended','invited'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_login_identity_chk CHECK (email IS NOT NULL OR phone IS NOT NULL)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_super_admin_org_chk CHECK ((is_super_admin AND organization_id IS NULL) OR (NOT is_super_admin AND organization_id IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX users_email_uq ON users (lower(email)) WHERE email IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX users_phone_uq ON users (phone) WHERE phone IS NOT NULL AND deleted_at IS NULL');

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->char('user_id', 26)->nullable()->index();   // ULID
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('user_campuses', function (Blueprint $t) {
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('user_id');
            $t->ulid('campus_id');
            $t->boolean('is_primary')->default(false);
            $t->timestampsTz();

            $t->primary(['user_id', 'campus_id']);
            $t->foreign(['organization_id', 'user_id'])->references(['organization_id', 'id'])->on('users')->cascadeOnDelete();
            $t->foreign(['organization_id', 'campus_id'])->references(['organization_id', 'id'])->on('campuses')->cascadeOnDelete();
            $t->index(['organization_id', 'campus_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_campuses');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
