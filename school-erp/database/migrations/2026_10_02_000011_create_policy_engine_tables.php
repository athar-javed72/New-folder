<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * presets / system_policies : immutable per (key, version), loaded by PresetSeeder.
 * policy_overrides          : tenant overrides, morph-scoped, effective-dated, versioned, draft/published.
 * Precedence (specific wins): organization < campus < program/contract_type < grade < course < employment_contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presets', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->string('key', 100);
            $t->string('version', 20);
            $t->string('name');
            $t->jsonb('payload');
            $t->char('checksum', 64);
            $t->string('status', 20)->default('active');
            $t->timestampTz('imported_at')->useCurrent();
            $t->timestampsTz();

            $t->unique(['key', 'version']);
        });
        DB::statement("ALTER TABLE presets ADD CONSTRAINT presets_status_chk CHECK (status IN ('active','deprecated'))");

        Schema::create('system_policies', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->ulid('preset_id');
            $t->string('type', 60);
            $t->string('key', 100);
            $t->jsonb('value');
            $t->jsonb('override_levels')->default('[]');
            $t->jsonb('editable_by')->default('[]');
            $t->smallInteger('schema_version')->default(1);
            $t->char('checksum', 64);
            $t->timestampsTz();

            $t->foreign('preset_id')->references('id')->on('presets')->cascadeOnDelete();
            $t->unique(['preset_id', 'type', 'key']);
            $t->index(['type', 'key']);
        });

        Schema::create('policy_overrides', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');   // explicit, so self-referencing FKs below can rely on it (fluent ->primary() is emitted last)
            $t->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('policy_type', 60);
            $t->string('policy_key', 100);
            $t->string('scopeable_type', 40);       // morph alias
            $t->string('scopeable_id', 26);
            $t->string('merge_mode', 20)->default('deep_merge');
            $t->jsonb('value');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();   // exclusive
            $t->integer('version')->default(1);
            $t->string('status', 20)->default('draft');
            $t->string('base_preset_version', 20)->nullable();
            $t->text('reason')->nullable();
            $t->ulid('created_by')->nullable();
            $t->ulid('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->timestampsTz();

            $t->unique(['organization_id', 'policy_type', 'policy_key', 'scopeable_type', 'scopeable_id', 'version'], 'policy_overrides_version_uq');
            $t->index(['organization_id', 'policy_type', 'policy_key', 'status'], 'policy_overrides_lookup_idx');
            $t->index(['scopeable_type', 'scopeable_id']);
        });
        DB::statement("ALTER TABLE policy_overrides ADD CONSTRAINT policy_overrides_scope_chk CHECK (scopeable_type IN ('organization','campus','program','grade','course','contract_type','employment_contract'))");
        DB::statement("ALTER TABLE policy_overrides ADD CONSTRAINT policy_overrides_merge_chk CHECK (merge_mode IN ('deep_merge','replace'))");
        DB::statement("ALTER TABLE policy_overrides ADD CONSTRAINT policy_overrides_status_chk CHECK (status IN ('draft','pending_approval','published','archived'))");
        DB::statement('ALTER TABLE policy_overrides ADD CONSTRAINT policy_overrides_dates_chk CHECK (effective_to IS NULL OR effective_to > effective_from)');
        // No two PUBLISHED overrides for the same policy+scope may overlap in time.
        DB::statement(<<<'SQL'
ALTER TABLE policy_overrides ADD CONSTRAINT policy_overrides_no_overlap
EXCLUDE USING gist (
    (organization_id::text) WITH =,
    (policy_type::text) WITH =,
    (policy_key::text) WITH =,
    (scopeable_type::text) WITH =,
    (scopeable_id::text) WITH =,
    daterange(effective_from, effective_to, '[)') WITH &&
) WHERE (status = 'published')
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_overrides');
        Schema::dropIfExists('system_policies');
        Schema::dropIfExists('presets');
    }
};
