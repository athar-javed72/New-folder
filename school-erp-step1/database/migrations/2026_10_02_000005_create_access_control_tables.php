<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom Access module (not spatie): ceilings, grant chains, with_grant, sensitive permissions.
 * roles.organization_id NULL = system role; roles.campus_id NULL = org-wide role.
 */
return new class extends Migration
{
    private const SCOPES = "('org','campus','program','grade','section','session')";
    private const STATUSES = "('active','pending_approval','suspended','revoked','expired')";

    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 100)->unique();          // e.g. fees.voucher.waive
            $t->string('module', 50)->index();
            $t->string('name');
            $t->boolean('is_sensitive')->default(false);  // only Org Admin may grant
            $t->boolean('is_delegable')->default(true);
            $t->jsonb('allowed_scopes')->default('["org","campus","program","grade","section","session"]');
            $t->jsonb('depends_on')->default('[]');
            $t->timestampsTz();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $t->ulid('campus_id')->nullable();
            $t->string('key', 60);
            $t->string('name');
            $t->boolean('is_system')->default(false);
            $t->boolean('is_editable')->default(true);
            $t->ulid('created_by')->nullable();
            $t->timestampsTz();

            $t->unique(['organization_id', 'id']);
            $t->foreign(['organization_id', 'campus_id'])->references(['organization_id', 'id'])->on('campuses')->cascadeOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX roles_scope_key_uq ON roles (COALESCE(organization_id,'-'), COALESCE(campus_id,'-'), key)");
        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_campus_requires_org_chk CHECK (campus_id IS NULL OR organization_id IS NOT NULL)');

        Schema::create('role_permissions', function (Blueprint $t) {
            $t->ulid('role_id');
            $t->ulid('permission_id');
            $t->string('max_scope', 20)->default('org');   // ceiling for this role
            $t->boolean('with_grant')->default(false);     // may the holder pass it on?
            $t->timestampsTz();

            $t->primary(['role_id', 'permission_id']);
            $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $t->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE role_permissions ADD CONSTRAINT role_permissions_scope_chk CHECK (max_scope IN ' . self::SCOPES . ')');

        Schema::create('role_assignments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('user_id');
            $t->ulid('role_id');
            $t->string('scope_type', 20)->default('org');
            $t->ulid('scope_id')->nullable();
            $t->ulid('granted_by')->nullable();
            $t->ulid('parent_grant_id')->nullable();
            $t->boolean('with_grant')->default(false);
            $t->timestampTz('starts_at')->useCurrent();
            $t->timestampTz('ends_at')->nullable();
            $t->string('status', 20)->default('active');
            $t->text('reason')->nullable();
            $t->timestampsTz();

            $t->foreign(['organization_id', 'user_id'])->references(['organization_id', 'id'])->on('users')->cascadeOnDelete();
            $t->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $t->foreign('parent_grant_id')->references('id')->on('role_assignments')->nullOnDelete();
            $t->index(['organization_id', 'user_id', 'status']);
            $t->index(['organization_id', 'scope_type', 'scope_id']);
        });
        DB::statement('ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_chk CHECK (scope_type IN ' . self::SCOPES . ')');
        DB::statement('ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_status_chk CHECK (status IN ' . self::STATUSES . ')');
        DB::statement('ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_window_chk CHECK (ends_at IS NULL OR ends_at > starts_at)');

        Schema::create('permission_grants', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('user_id');
            $t->ulid('permission_id');
            $t->string('scope_type', 20)->default('org');
            $t->ulid('scope_id')->nullable();
            $t->ulid('granted_by')->nullable();
            $t->ulid('parent_grant_id')->nullable();
            $t->boolean('with_grant')->default(false);
            $t->timestampTz('starts_at')->useCurrent();
            $t->timestampTz('ends_at')->nullable();
            $t->string('status', 20)->default('active');
            $t->text('reason')->nullable();
            $t->timestampsTz();

            $t->foreign(['organization_id', 'user_id'])->references(['organization_id', 'id'])->on('users')->cascadeOnDelete();
            $t->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $t->foreign('parent_grant_id')->references('id')->on('permission_grants')->nullOnDelete();
            $t->index(['organization_id', 'user_id', 'status']);
        });
        DB::statement('ALTER TABLE permission_grants ADD CONSTRAINT permission_grants_scope_chk CHECK (scope_type IN ' . self::SCOPES . ')');
        DB::statement('ALTER TABLE permission_grants ADD CONSTRAINT permission_grants_status_chk CHECK (status IN ' . self::STATUSES . ')');
        DB::statement('ALTER TABLE permission_grants ADD CONSTRAINT permission_grants_window_chk CHECK (ends_at IS NULL OR ends_at > starts_at)');

        // Caps what a grantor may hand out (e.g. Campus Admin cannot grant beyond own campus).
        Schema::create('delegation_boundaries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('grantor_role_id');
            $t->ulid('grantee_role_id');
            $t->string('max_scope', 20)->default('campus');
            $t->boolean('requires_approval')->default(false);
            $t->jsonb('rules')->default('{}');
            $t->timestampsTz();

            $t->foreign('grantor_role_id')->references('id')->on('roles')->cascadeOnDelete();
            $t->foreign('grantee_role_id')->references('id')->on('roles')->cascadeOnDelete();
            $t->unique(['organization_id', 'grantor_role_id', 'grantee_role_id']);
        });
        DB::statement('ALTER TABLE delegation_boundaries ADD CONSTRAINT delegation_boundaries_scope_chk CHECK (max_scope IN ' . self::SCOPES . ')');

        Schema::create('module_enablement', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->ulid('campus_id')->nullable();
            $t->string('module', 50);
            $t->boolean('enabled')->default(true);
            $t->string('source', 10)->default('org');
            $t->jsonb('config')->default('{}');
            $t->timestampsTz();

            $t->foreign(['organization_id', 'campus_id'])->references(['organization_id', 'id'])->on('campuses')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE module_enablement ADD CONSTRAINT module_enablement_source_chk CHECK (source IN ('plan','org','campus'))");
        DB::statement("CREATE UNIQUE INDEX module_enablement_uq ON module_enablement (organization_id, COALESCE(campus_id,'-'), module)");
    }

    public function down(): void
    {
        foreach (['module_enablement', 'delegation_boundaries', 'permission_grants', 'role_assignments', 'role_permissions', 'roles', 'permissions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
