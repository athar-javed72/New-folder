# Part 1 of 3: Project Initialization + Database Migrations

Part 2 = PresetSeeder, models, Pest golden tests. Part 3 = documentation package + Antigravity prompt.

## 1. Prerequisites
PHP 8.2+ (extensions: pdo_pgsql, pgsql, intl, mbstring, xml, curl), Composer 2, Docker (for PostgreSQL 16).

## 2. Create the project
```bash
composer create-project laravel/laravel school-erp "^12.0"
cd school-erp
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
./vendor/bin/pest --init
```

## 3. Database
Copy `docker-compose.yml` and the `docker/` folder from this package into the project root, then:
```bash
docker compose up -d        # PostgreSQL 16 (databases school_erp and school_erp_test) + Redis 7
```

## 4. .env (replace the sqlite defaults)
```
APP_NAME="School ERP"
APP_TIMEZONE=Asia/Karachi

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=school_erp
DB_USERNAME=school_erp
DB_PASSWORD=change_me
DB_SCHEMA=public
DB_SSLMODE=prefer

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```
```bash
php artisan key:generate
```

## 5. Install the migrations
1. Delete `database/migrations/0001_01_01_000000_create_users_table.php` (migration 04 replaces it: ULID ids, nullable organization for super admins, sessions, password resets).
2. Keep Laravel's cache and jobs migrations (`0001_01_01_000001`, `0001_01_01_000002`).
3. Copy the 12 files `database/migrations/2026_10_02_0000NN_*.php` into the project.
4. Run:
```bash
php artisan migrate:fresh
```
(The seeder arrives in Part 2, so there is no `--seed` yet.)

## 6. Migration order (exactly as you requested)
| # | Group | Creates |
|---|---|---|
| 01 | Foundation | extensions `btree_gist`, `pg_trgm`; function `prevent_row_mutation()` |
| 02 | Foundation | `organizations` |
| 03 | Foundation | `campuses` |
| 04 | Foundation | `users`, `password_reset_tokens`, `sessions`, `user_campuses` |
| 05 | Foundation | `permissions`, `roles`, `role_permissions`, `role_assignments`, `permission_grants`, `delegation_boundaries`, `module_enablement` |
| 06 | Foundation | `programs`, `academic_calendars`, `terms` |
| 07 | Core | `grades`, `sections` |
| 08 | Core | `families`, `students`, `guardians`, `guardian_student` |
| 09 | Core | `enrollments` |
| 10 | Staff and HR | `employees`, `contract_types`, `leave_types`, `employment_contracts`, `leave_entitlements`, `leave_ledgers`, view `leave_balances` |
| 11 | Policy engine | `presets`, `system_policies`, `policy_overrides` |
| 12 | Audit | `audit_logs`, `domain_events` (outbox) |

## 7. What the database enforces by itself
- Every tenant table carries `organization_id`; composite foreign keys `(organization_id, id)` block cross-tenant references.
- One active enrollment per student (partial unique index).
- No overlapping active contracts for one employee at the same campus (GiST exclusion); different campuses are allowed.
- `leave_ledgers` and `audit_logs` are append-only (trigger); leave balance is `SUM(qty)` in the view `leave_balances`.
- No two published overrides for the same policy and scope may overlap in time (GiST exclusion).
- Money is bigint paisa; leave quantity is numeric(8,2).

## 8. Verification status (honest)
The 12 migrations were executed against a real PostgreSQL 16 server: up, full rollback (0 tables left), up again. The 18 constraint tests above passed against it. They were run through a small stand-in for Laravel's Schema/DB classes because Composer/Packagist is blocked in my sandbox, so your first `php artisan migrate:fresh` on real Laravel 12 is the final check.
