# Project Requirements Document (PRD)

## Overview
Multi-school, multi-campus School ERP for Pakistani schools. Everything is configurable per organization, campus, program and grade. This PRD covers **Phase 1, Step 1 only**: project setup, database migrations, preset seeding and golden tests. Do NOT start Phase 2.

## Stack and environment (fixed)
- Laravel 12, PHP 8.2+, PostgreSQL 16, Pest. Money = integer paisa, never float.
- PostgreSQL 16 is already installed natively on this machine (NOT Docker). Databases `school_erp` and `school_erp_test` must exist. Extension `btree_gist` is enabled; `pg_trgm` is created by migration 01. Credentials come from `.env`; never hardcode or commit them.
- Laravel project lives in `./school-erp/` (create it in Task 1). The finished, reviewed source package is in `./school-erp-step1/`. Treat it as the source of truth.
- Read first (short): `school-erp-step1/docs/DOCUMENTATION_PACKAGE.md`. Read other docs only when a task needs them.
- Windows note: PHP needs `pdo_pgsql`, `pgsql`, `intl`, `mbstring`, `fileinfo`, `zip` enabled in php.ini.

## Hard rules (apply to every task)
1. NEVER edit, rename or reorder the provided migrations `2026_10_02_000001..000012`. If one fails: stop, write the exact error to `progress.txt` under `BLOCKED`, and do not continue. A needed schema change is a NEW migration with a later timestamp, and only after the user approves.
2. No raw SQL or manual database setup. `php artisan migrate:fresh --seed` must rebuild everything from nothing.
3. Do not invent tables, columns, policies or business rules that are not in the package docs.
4. Do not change the content of `database/presets/pk_general_v1.preset.json`. A changed preset needs a new version number.
5. One task per turn. Verify, then one atomic git commit, then log to `progress.txt`.
6. Verification gate for PHP: `php -l` on changed files, `./vendor/bin/pint --test`, `./vendor/bin/pest`. Run Larastan once it is installed (Task 9).

## Tasks
- [x] Task 1: Create the Laravel 12 project in `./school-erp` (`composer create-project laravel/laravel school-erp "^12.0"`), install Pest (`pestphp/pest`, `pestphp/pest-plugin-laravel`, `./vendor/bin/pest --init`), run `git init` at the workspace root if needed. Done when: `php artisan --version` shows Laravel 12.x and `./vendor/bin/pest` runs.
- [ ] Task 2: Configure the database. Set `.env` from `school-erp-step1/env.postgres.example` using the local PostgreSQL credentials; add `phpunit.env.snippet.xml` lines to `phpunit.xml` (test DB `school_erp_test`); merge `tests/Pest.php.example` into `tests/Pest.php`. Done when: `php artisan migrate:status` connects, and `select extname from pg_extension` shows `btree_gist`.
- [ ] Task 3: Install migrations. Delete `database/migrations/0001_01_01_000000_create_users_table.php` (keep the cache and jobs migrations). Copy the 12 files `school-erp-step1/database/migrations/2026_10_02_*.php`. Run `php artisan migrate:fresh`. Done when: it succeeds with no errors. If it fails, follow Hard rule 1.
- [ ] Task 4: Copy the code in: `school-erp-step1/app/**` (overwrite `app/Models/User.php`), `database/seeders/*`, `database/presets/*`, `tests/**`, `docs/**`. Add `App\Providers\MorphMapServiceProvider::class` to `bootstrap/providers.php`. Check `config/auth.php` uses `App\Models\User`. Done when: `php artisan about` runs without errors.
- [ ] Task 5: Seed. Run `php artisan migrate:fresh --seed`; expected line `Preset PK_GENERAL_V1@1.5: imported (17/17 policies created)`. Run `php artisan db:seed --class=PresetSeeder` again; expected `already present`. Verify in DB: `presets` = 1 row, `system_policies` = 17 rows, and the `grading_profile` value contains `"component_min_percent": {}` as an object.
- [ ] Task 6: Run `./vendor/bin/pest`. Expected: 39 unit tests and 18 database constraint tests pass. Golden numbers: late fee Day 8 = 55000, Day 15 = 90000; visiting payroll 22 sessions = 1760000; GPA credit_weighted 3.51 vs simple_average 3.57. If a test fails, report it; do not weaken the test.
- [ ] Task 7: Add `tests/Feature/PresetSeederTest.php`: first import creates 17 policies; second import creates 0; same version with changed content raises `PresetChecksumMismatch`.
- [ ] Task 8: Add one Pest test that creates organization, campus, family, student and enrollment through Eloquent and reloads them. Fix only model bugs (casts, relations, fillable). Do not touch migrations.
- [ ] Task 9: Add Pint and Larastan (`larastan/larastan`, level 5), run both, fix style and type issues only. Done when: both pass and `pest` still passes.
- [ ] Task 10: Write `.planning/STATE.md` summary (what exists, what passed, known gaps) and stop. Do NOT start Phase 2.

## Out of scope for this PRD
Timetable and attendance engine, fees ledger, payroll runs, Access module logic, API endpoints, AI layer, frontend.
