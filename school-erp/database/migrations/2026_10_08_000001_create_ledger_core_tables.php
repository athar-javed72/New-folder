<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C-1: ledger core (D-28, D-44 to D-52).
 * accounts, ledger_periods, journal_entries, journal_lines, number_sequences.
 * New tables only. Nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- accounts
        Schema::create('accounts', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->string('code', 30);
            $t->string('name');
            $t->string('type', 20);
            $t->string('system_key', 40)->nullable();      // services look accounts up by this, never by code
            $t->boolean('requires_family')->default(false); // lines on this account must carry family_id (sub-ledger)
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();

            $t->unique(['organization_id', 'code']);
            $t->unique(['organization_id', 'id']);
        });
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_chk CHECK (type IN ('asset','liability','income','expense','equity'))");
        DB::statement('CREATE UNIQUE INDEX accounts_system_key_uq ON accounts (organization_id, system_key) WHERE system_key IS NOT NULL');

        // ---------------------------------------------------------------- ledger_periods
        Schema::create('ledger_periods', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->string('name', 60);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 10)->default('open');
            $t->timestampTz('closed_at')->nullable();
            $t->ulid('closed_by')->nullable();
            $t->timestampsTz();

            $t->unique(['organization_id', 'id']);
            $t->foreign('closed_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE ledger_periods ADD CONSTRAINT ledger_periods_status_chk CHECK (status IN ('open','closed'))");
        DB::statement('ALTER TABLE ledger_periods ADD CONSTRAINT ledger_periods_range_chk CHECK (ends_on >= starts_on)');
        DB::statement("ALTER TABLE ledger_periods ADD CONSTRAINT ledger_periods_closed_chk CHECK ((status = 'closed') = (closed_at IS NOT NULL))");
        DB::statement("ALTER TABLE ledger_periods ADD CONSTRAINT ledger_periods_no_overlap EXCLUDE USING gist (organization_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&)");

        // ---------------------------------------------------------------- journal_entries
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->ulid('campus_id');
            $t->ulid('period_id');
            $t->date('entry_date');
            $t->string('source_type', 60);
            $t->string('source_id', 26);
            $t->string('kind', 10);
            $t->ulid('reversal_of')->nullable();
            $t->smallInteger('line_count');                 // declared shape, verified at commit
            $t->bigInteger('total_minor');                  // declared total debit (= total credit), verified at commit
            $t->char('lines_hash', 64);                     // canonical hash of the lines, for idempotent re-posting
            $t->string('memo')->nullable();
            $t->ulid('created_by');
            $t->timestampTz('created_at')->useCurrent();

            $t->unique(['organization_id', 'id']);
            $t->unique(['organization_id', 'source_type', 'source_id', 'kind']);
            $t->index(['organization_id', 'entry_date']);
            $t->index(['organization_id', 'campus_id', 'entry_date']);
            $t->foreign(['organization_id', 'campus_id'])->references(['organization_id', 'id'])->on('campuses')->restrictOnDelete();
            $t->foreign(['organization_id', 'period_id'])->references(['organization_id', 'id'])->on('ledger_periods')->restrictOnDelete();
            $t->foreign(['organization_id', 'reversal_of'])->references(['organization_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_kind_chk CHECK (kind IN ('posting','reversal'))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_reversal_chk CHECK ((kind = 'reversal') = (reversal_of IS NOT NULL))");
        DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_shape_chk CHECK (line_count >= 2 AND total_minor > 0)');
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_hash_chk CHECK (lines_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('CREATE UNIQUE INDEX journal_entries_one_reversal_uq ON journal_entries (organization_id, reversal_of) WHERE reversal_of IS NOT NULL');

        // ---------------------------------------------------------------- journal_lines
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->ulid('entry_id');
            $t->smallInteger('line_no');
            $t->ulid('account_id');
            $t->ulid('family_id')->nullable();
            $t->bigInteger('debit_minor')->default(0);
            $t->bigInteger('credit_minor')->default(0);
            $t->string('description')->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->unique(['entry_id', 'line_no']);
            $t->index(['organization_id', 'account_id']);
            $t->index(['organization_id', 'family_id', 'account_id']);
            $t->foreign(['organization_id', 'entry_id'])->references(['organization_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $t->foreign(['organization_id', 'account_id'])->references(['organization_id', 'id'])->on('accounts')->restrictOnDelete();
            $t->foreign(['organization_id', 'family_id'])->references(['organization_id', 'id'])->on('families')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_amount_chk CHECK (debit_minor >= 0 AND credit_minor >= 0 AND ((debit_minor > 0 AND credit_minor = 0) OR (debit_minor = 0 AND credit_minor > 0)))');

        // ---------------------------------------------------------------- number_sequences
        Schema::create('number_sequences', function (Blueprint $t) {
            $t->ulid('id');
            $t->primary('id');
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->ulid('campus_id');
            $t->string('key', 40);                           // 'receipt', 'voucher', ...
            $t->smallInteger('fiscal_year');                 // calendar year in which the fiscal year STARTS
            $t->bigInteger('last_number')->default(0);
            $t->timestampsTz();

            $t->unique(['organization_id', 'campus_id', 'key', 'fiscal_year']);
            $t->foreign(['organization_id', 'campus_id'])->references(['organization_id', 'id'])->on('campuses')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE number_sequences ADD CONSTRAINT number_sequences_chk CHECK (last_number >= 0 AND fiscal_year BETWEEN 2000 AND 2100)');

        $this->createFunctionsAndTriggers();
    }

    private function createFunctionsAndTriggers(): void
    {
        // accounts: type cannot change once the account has lines
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION accounts_guard() RETURNS trigger AS $$
BEGIN
    IF NEW.type <> OLD.type AND EXISTS (SELECT 1 FROM journal_lines WHERE organization_id = OLD.organization_id AND account_id = OLD.id) THEN
        RAISE EXCEPTION 'account type cannot change after it has journal lines' USING ERRCODE = 'restrict_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement('CREATE TRIGGER accounts_guard_trg BEFORE UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION accounts_guard()');

        // ledger_periods: a closed period never reopens; dates are frozen once entries exist
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION ledger_periods_guard() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'closed' THEN
        RAISE EXCEPTION 'a closed ledger period cannot be changed' USING ERRCODE = 'restrict_violation';
    END IF;
    IF (NEW.starts_on <> OLD.starts_on OR NEW.ends_on <> OLD.ends_on)
       AND EXISTS (SELECT 1 FROM journal_entries WHERE organization_id = OLD.organization_id AND period_id = OLD.id) THEN
        RAISE EXCEPTION 'period dates cannot change after entries exist' USING ERRCODE = 'restrict_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement('CREATE TRIGGER ledger_periods_guard_trg BEFORE UPDATE ON ledger_periods FOR EACH ROW EXECUTE FUNCTION ledger_periods_guard()');

        // journal_entries: period must be open and contain the date; reversal must mirror its posting
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_entries_guard() RETURNS trigger AS $$
DECLARE
    p ledger_periods%ROWTYPE;
BEGIN
    -- FOR SHARE makes a concurrent period close wait until this transaction ends
    SELECT * INTO p FROM ledger_periods WHERE organization_id = NEW.organization_id AND id = NEW.period_id FOR SHARE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'ledger period not found' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF p.status <> 'open' THEN
        RAISE EXCEPTION 'cannot post into a closed ledger period' USING ERRCODE = 'restrict_violation';
    END IF;
    IF NEW.entry_date < p.starts_on OR NEW.entry_date > p.ends_on THEN
        RAISE EXCEPTION 'entry_date is outside the ledger period' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.kind = 'reversal' THEN
        PERFORM 1 FROM journal_entries o
         WHERE o.organization_id = NEW.organization_id AND o.id = NEW.reversal_of AND o.kind = 'posting'
           AND o.source_type = NEW.source_type AND o.source_id = NEW.source_id AND o.campus_id = NEW.campus_id
           AND o.total_minor = NEW.total_minor AND o.line_count = NEW.line_count;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'a reversal must mirror the posting of the same source and campus' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement('CREATE TRIGGER journal_entries_guard_trg BEFORE INSERT ON journal_entries FOR EACH ROW EXECUTE FUNCTION journal_entries_guard()');

        // journal_lines: account must be active; sub-ledger accounts need a family
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_lines_guard() RETURNS trigger AS $$
DECLARE
    a accounts%ROWTYPE;
BEGIN
    SELECT * INTO a FROM accounts WHERE organization_id = NEW.organization_id AND id = NEW.account_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'account not found' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF NOT a.is_active THEN
        RAISE EXCEPTION 'cannot post to an inactive account' USING ERRCODE = 'restrict_violation';
    END IF;
    IF a.requires_family AND NEW.family_id IS NULL THEN
        RAISE EXCEPTION 'this account requires a family on every line' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement('CREATE TRIGGER journal_lines_guard_trg BEFORE INSERT ON journal_lines FOR EACH ROW EXECUTE FUNCTION journal_lines_guard()');

        // append-only
        DB::statement('CREATE TRIGGER journal_entries_append_only BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION prevent_row_mutation()');
        DB::statement('CREATE TRIGGER journal_lines_append_only BEFORE UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION prevent_row_mutation()');

        // balance: checked at COMMIT (deferred). Entry and lines must match the declared shape and debit must equal credit.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_entry_assert_balanced() RETURNS trigger AS $$
DECLARE
    eid char(26);
    e journal_entries%ROWTYPE;
    d bigint;
    c bigint;
    n integer;
BEGIN
    IF TG_TABLE_NAME = 'journal_entries' THEN
        eid := NEW.id;
    ELSE
        eid := NEW.entry_id;
    END IF;
    SELECT * INTO e FROM journal_entries WHERE id = eid;
    SELECT COALESCE(SUM(debit_minor), 0), COALESCE(SUM(credit_minor), 0), COUNT(*) INTO d, c, n
      FROM journal_lines WHERE entry_id = eid;
    IF n < 2 OR n <> e.line_count THEN
        RAISE EXCEPTION 'journal entry has % lines but declares %', n, e.line_count USING ERRCODE = 'check_violation';
    END IF;
    IF d <> c THEN
        RAISE EXCEPTION 'journal entry is not balanced (debit %, credit %)', d, c USING ERRCODE = 'check_violation';
    END IF;
    IF d <> e.total_minor THEN
        RAISE EXCEPTION 'journal entry total % differs from declared total %', d, e.total_minor USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER journal_entries_balanced AFTER INSERT ON journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_entry_assert_balanced()');
        DB::statement('CREATE CONSTRAINT TRIGGER journal_lines_balanced AFTER INSERT ON journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_entry_assert_balanced()');

        // number_sequences: gap-free. Only +1 steps, identity never changes, rows never deleted.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION number_sequences_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'number sequences cannot be deleted' USING ERRCODE = 'restrict_violation';
    END IF;
    IF NEW.organization_id <> OLD.organization_id OR NEW.campus_id <> OLD.campus_id
       OR NEW.key <> OLD.key OR NEW.fiscal_year <> OLD.fiscal_year THEN
        RAISE EXCEPTION 'number sequence identity cannot change' USING ERRCODE = 'restrict_violation';
    END IF;
    IF NEW.last_number <> OLD.last_number + 1 THEN
        RAISE EXCEPTION 'number sequence must advance by exactly 1' USING ERRCODE = 'restrict_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement('CREATE TRIGGER number_sequences_guard_trg BEFORE UPDATE OR DELETE ON number_sequences FOR EACH ROW EXECUTE FUNCTION number_sequences_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_periods');
        Schema::dropIfExists('accounts');
        foreach (['number_sequences_guard', 'journal_entry_assert_balanced', 'journal_lines_guard', 'journal_entries_guard', 'ledger_periods_guard', 'accounts_guard'] as $fn) {
            DB::statement("DROP FUNCTION IF EXISTS {$fn}()");
        }
    }
};
