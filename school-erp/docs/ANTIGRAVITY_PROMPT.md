# Prompt for Antigravity (copy everything below the line)

---

ROLE
You are the coding agent for a multi-school, multi-campus School ERP for Pakistani schools. Stack: Laravel 12, PHP 8.2+, PostgreSQL 16, Pest. Work only inside the project folder. Do exactly the steps below, in order, and stop to report if a step fails.

INPUT PACKAGE
The folder `school-erp-step1/` (from the zip) contains the finished, reviewed source of truth:
- `database/migrations/2026_10_02_000001..000012_*.php` (12 migrations)
- `database/presets/pk_general_v1.preset.json` (v1.5, 17 policies)
- `database/seeders/PresetSeeder.php`, `DatabaseSeeder.php`
- `app/Casts/JsonDocument.php`, `app/Domain/**`, `app/Models/**`, `app/Providers/MorphMapServiceProvider.php`, `app/Services/PresetImporter.php`, `app/Exceptions/PresetChecksumMismatch.php`
- `tests/Unit/*`, `tests/Feature/DatabaseConstraintsTest.php`, `tests/Support/*`
- `docker-compose.yml`, `docker/postgres-init.sql`, `env.postgres.example`, `phpunit.env.snippet.xml`, `tests/Pest.php.example`
- `docs/*` (read `docs/DOCUMENTATION_PACKAGE.md` first)

HARD RULES
1. No raw SQL or manual database setup. The schema comes only from the migration files. `php artisan migrate:fresh --seed` must rebuild everything.
2. Do NOT edit, rename or reorder the provided migrations. If one fails, stop and report the exact error text and file. Never "fix" by changing the schema silently. A needed schema change is a NEW migration with a later timestamp.
3. Do NOT invent tables, columns, policies or business rules that are not in the package.
4. Money is integer paisa. No floats for money. Keep `declare(strict_types=1)`.
5. Do not touch `database/presets/pk_general_v1.preset.json` content. A changed preset needs a new version number.
6. Keep every change small and reviewable. Commit after each numbered step.

STEPS

Step 1. Create the project
```
composer create-project laravel/laravel school-erp "^12.0"
cd school-erp
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
./vendor/bin/pest --init
```

Step 2. Database and environment
- Copy `docker-compose.yml` and `docker/` into the project root, then `docker compose up -d`.
- Replace the DB and driver lines of `.env` with the contents of `env.postgres.example`. Run `php artisan key:generate`.
- Add the lines of `phpunit.env.snippet.xml` inside `<php>` in `phpunit.xml`.
- Merge `tests/Pest.php.example` into `tests/Pest.php` (Feature tests use RefreshDatabase and TestCase).

Step 3. Copy the package in
- Delete `database/migrations/0001_01_01_000000_create_users_table.php`. Keep the cache and jobs migrations.
- Copy `database/migrations/2026_10_02_*`, `database/seeders/*`, `database/presets/*`, `app/**` (overwrite `app/Models/User.php`), `tests/**`, `docs/**`.
- Register the provider: add `App\Providers\MorphMapServiceProvider::class` to `bootstrap/providers.php`.
- Check `config/auth.php` still points at `App\Models\User`.

Step 4. Migrate and seed
```
php artisan migrate:fresh --seed
```
Expected: 12 package migrations plus Laravel's cache and jobs migrations succeed, and the seeder prints `Preset PK_GENERAL_V1@1.5: imported (17/17 policies created)`.
Then run `php artisan db:seed --class=PresetSeeder` again. Expected output: `already present`.
Verify in the database: `presets` has 1 row; `system_policies` has 17 rows; the `grading_profile` policy value contains `"component_min_percent": {}` as an object.

Step 5. Run tests
```
./vendor/bin/pest
```
Expected: all pass (39 unit and 18 database constraint tests). Golden numbers that must hold: late fee Day 8 = 55000, Day 15 = 90000; visiting payroll 22 sessions = 1760000; GPA credit_weighted 3.51 vs simple_average 3.57.

Step 6. Add one Pest feature test for the seeder (new file `tests/Feature/PresetSeederTest.php`)
- After `migrate:fresh`, call `PresetImporter::importFile(database_path('presets/pk_general_v1.preset.json'))`: expect `created = true`, `policies_created = 17`.
- Call it again: expect `created = false`, `policies_created = 0`.
- Copy the file to a temp path, change one policy value but keep version 1.5, import: expect `PresetChecksumMismatch`.

Step 7. Models sanity (no new business logic)
- Confirm each model in `app/Models` boots: write one small Pest test that creates an organization, campus, family, student and enrollment through Eloquent and reloads them. Fix model bugs only (casts, relations, fillable). Do not alter migrations.

REPORT FORMAT (return exactly this)
1. Result per step: PASS or FAIL.
2. For any FAIL: the full error text, the file and line, and what you tried. Do not guess fixes to migrations.
3. Output of `php artisan migrate:fresh --seed`, `php artisan db:seed --class=PresetSeeder` (second run) and `./vendor/bin/pest`.
4. List of files you added or changed beyond the package.

DO NOT START PHASE 2 (timetable engine, attendance, fees ledger, payroll runs, access module logic). Stop after the report.
