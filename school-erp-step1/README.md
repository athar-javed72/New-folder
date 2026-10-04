# School ERP: Step 1 (Foundation, Migrations, Preset Seeder, Golden Tests)

Laravel 12, PHP 8.2+, PostgreSQL 16. Everything about the schema lives in migrations: `php artisan migrate:fresh --seed` rebuilds it from nothing. No manual SQL.

## 1. Project initialization

```bash
# prerequisites: PHP 8.2+ (ext: pdo_pgsql, pgsql, intl, mbstring, xml, curl), Composer 2, Docker (for PostgreSQL)
composer create-project laravel/laravel school-erp "^12.0"
cd school-erp

# Pest
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
./vendor/bin/pest --init

# PostgreSQL 16 + Redis (creates school_erp and school_erp_test databases automatically)
# copy docker-compose.yml and docker/ from this package into the project root first
docker compose up -d
```

## 2. Environment

Copy `env.postgres.example` into `.env` (replace the sqlite defaults), then `php artisan key:generate`.
For tests, add the lines in `phpunit.env.snippet.xml` to `phpunit.xml` and merge `tests/Pest.php.example` into `tests/Pest.php`.

## 3. Copy this package into the project

| From this package | To the project |
|---|---|
| `database/migrations/2026_10_02_*.php` (12 files) | `database/migrations/` |
| `database/seeders/*` | `database/seeders/` |
| `database/presets/pk_general_v1.preset.json` | `database/presets/` |
| `app/**` | `app/` |
| `tests/**` | `tests/` |

**Delete** Laravel's default `database/migrations/0001_01_01_000000_create_users_table.php` (our migration 04 replaces it: ULID ids, global identity, `sessions`, `password_reset_tokens`). **Keep** the default cache and jobs migrations (`0001_01_01_000001`, `0001_01_01_000002`).

The `users` table is now ULID-based: update `app/Models/User.php` to use `Illuminate\Database\Eloquent\Concerns\HasUlids`, `SoftDeletes`, and remove the default `email` unique assumptions (uniqueness is a partial index on `lower(email)`).

## 4. Migration order

| # | File | Creates |
|---|---|---|
| 01 | enable_postgres_extensions | `btree_gist`, `pg_trgm`, function `prevent_row_mutation()` |
| 02 | organizations | `organizations` |
| 03 | campuses | `campuses` |
| 04 | users_tables | `users`, `password_reset_tokens`, `sessions`, `user_campuses` |
| 05 | access_control_tables | `permissions`, `roles`, `role_permissions`, `role_assignments`, `permission_grants`, `delegation_boundaries`, `module_enablement` |
| 06 | academic_calendar_tables | `programs`, `academic_calendars`, `terms` |
| 07 | grades_and_sections | `grades`, `sections` |
| 08 | families_students_guardians | `families`, `students`, `guardians`, `guardian_student` |
| 09 | enrollments | `enrollments` |
| 10 | staff_and_leave_tables | `employees`, `contract_types`, `leave_types`, `employment_contracts`, `leave_entitlements`, `leave_ledgers`, view `leave_balances` |
| 11 | policy_engine_tables | `presets`, `system_policies`, `policy_overrides` |
| 12 | audit_and_outbox_tables | `audit_logs`, `domain_events` |

## 5. Run

```bash
php artisan migrate:fresh --seed      # schema + PK_GENERAL_V1 v1.5 preset (17 system policies)
./vendor/bin/pest                     # 39 unit tests + 18 database constraint tests
```
Expected seeder output: `Preset PK_GENERAL_V1@1.5: imported (17/17 policies created)`. Running `db:seed` again prints `already present`.

## 6. Morph map (add to `AppServiceProvider::boot()` when the models exist)

```php
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::enforceMorphMap([
    'organization' => \App\Models\Organization::class,
    'campus' => \App\Models\Campus::class,
    'program' => \App\Models\Program::class,
    'grade' => \App\Models\Grade::class,
    'contract_type' => \App\Models\ContractType::class,
    'employment_contract' => \App\Models\EmploymentContract::class,
]);
```
Aliases come from the policy_overrides_scope_chk CHECK; 'course' is added in Phase 2 with its model.

## 7. Design rules baked into the schema

- ULID primary keys everywhere; `organization_id` on every tenant table and first in composite indexes.
- Composite foreign keys `(organization_id, id)` so a row can never reference another organization's campus, grade, student, etc.
- Money is `bigint` minor units (paisa). Leave quantity is `numeric(8,2)` days.
- `leave_ledgers` and `audit_logs` are append-only (trigger). Balance = `SUM(qty)` (view `leave_balances`).
- One active enrollment per student (partial unique index). No overlapping active contracts per employee+campus, no overlapping published policy overrides per scope (exclusion constraints).
- `ON DELETE RESTRICT` on academic and people data; cascade only on pure pivot tables.
- Presets are immutable per `(key, version)`; changing content requires a version bump (checksum guard).
- Never edit a migration that has been committed; add a new one.

## 8. What was verified, and what was not

Verified in a sandbox: all 12 migrations executed against real PostgreSQL 16.15; all constraints triggered by the intended rule (see `docs/GOLDEN_TESTS.md`); the mapper output inserts into `presets`/`system_policies` (17 rows, `{}` preserved as a jsonb object); 39 unit + 18 constraint tests pass; all PHP files lint on PHP 8.3.

**Not verified (Composer/Packagist was unreachable in the sandbox):** an actual `php artisan migrate:fresh --seed` on Laravel 12, the Eloquent models, `PresetImporter` and `PresetSeeder` under Laravel, and the Pest runner itself. The migrations and tests were executed through a small compatibility shim for `Schema`/`Blueprint`/`DB`, so Laravel's own SQL generation (index naming, `foreignUlid`) was not exercised. Treat the first real `migrate:fresh --seed` as the final check and send any error text back.

Idempotency check after seeding: run `php artisan db:seed --class=PresetSeeder` again, it must print `already present`.
