<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $t->ulid('actor_id')->nullable();
            $t->string('action', 100);
            $t->string('subject_type', 60)->nullable();
            $t->string('subject_id', 26)->nullable();
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->jsonb('meta')->default('{}');
            $t->string('ip_address', 45)->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['organization_id', 'created_at']);
            $t->index(['organization_id', 'subject_type', 'subject_id']);
            $t->index(['organization_id', 'actor_id', 'created_at']);
        });
        DB::statement('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION prevent_row_mutation()');

        // Transactional outbox: written in the same transaction as the change, published by a worker.
        Schema::create('domain_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->string('event_type', 100);
            $t->string('aggregate_type', 60);
            $t->string('aggregate_id', 26);
            $t->jsonb('payload');
            $t->string('dedupe_key', 150)->nullable();
            $t->timestampTz('occurred_at')->useCurrent();
            $t->timestampTz('published_at')->nullable();
            $t->smallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
        });
        DB::statement('CREATE INDEX domain_events_unpublished_idx ON domain_events (occurred_at) WHERE published_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX domain_events_dedupe_uq ON domain_events (organization_id, dedupe_key) WHERE dedupe_key IS NOT NULL');
        DB::statement('CREATE INDEX domain_events_aggregate_idx ON domain_events (organization_id, aggregate_type, aggregate_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
        Schema::dropIfExists('audit_logs');
    }
};
