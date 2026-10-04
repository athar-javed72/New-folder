# STATE

Phase 1, Step 1: Project Setup, Database Migrations, Presets, and Golden Tests — **COMPLETED**

---

## 1. What Exists

### Stack & Infrastructure
- **Framework & Runtime:** Laravel 12 (`laravel/framework v12.69.3`) on PHP 8.2+ with PostgreSQL 16.
- **Database Environments:**
  - Local development database: `school_erp`.
  - Automated test database: `school_erp_test` (configured in `phpunit.xml` with `RefreshDatabase` trait in `tests/Pest.php`).
  - PostgreSQL Extensions: `btree_gist` (GiST indexing & exclusion constraints) and `pg_trgm` (trigram text search).
- **Tooling & Quality Gates:**
  - **Testing:** Pest v3.8.7 with Laravel plugin (`pestphp/pest-plugin-laravel v3.2.0`).
  - **Code Style:** Laravel Pint with `pint.json` configuration (excluding migrations and preset JSON).
  - **Static Analysis:** Larastan v3.12.2 running at Level 5 (`phpstan.neon`) targeting `app/`.

### Database Schema & Multi-Tenancy Architecture
- **Migrations:** 12 core package migrations (`2026_10_02_000001` through `2026_10_02_000012`) producing 40 database tables and views.
- **Multi-Tenancy:**
  - Shared database architecture enforced via `organization_id` on every tenant table.
  - Composite foreign keys `(organization_id, id)` prevent cross-tenant referencing at the PostgreSQL engine level.
- **Data Integrity & Constraints:**
  - Primary keys use ULIDs (`ulid` column type, 26-character sortable identifiers).
  - Money stored strictly in integer minor units (`bigint` paisa; never floats).
  - Append-only tables (`leave_ledgers`, `audit_logs`) protected by PostgreSQL trigger `prevent_row_mutation()` that rejects `UPDATE` and `DELETE`.
  - Transaction outbox deduplication via `(organization_id, dedupe_key)`.
  - PostgreSQL GiST exclusion constraints enforce non-overlapping published policy overrides and non-overlapping active employee contracts per campus.
  - Single active enrollment constraint per student via partial unique index.

### Domain Logic & Calculators
- **`LateFeeCalculator` (`app/Domain/LateFeeCalculator.php`):** Calendar-aware due-date shifting (Saturdays to Monday), tiered flat and per-day fees, caps, grace days, basis points (`percent_of_head`, `percent_of_balance`).
- **`ContractPayrollCalculator` (`app/Domain/ContractPayrollCalculator.php`):** Calculates session-based visiting contracts and hourly part-time contracts with exact integer paisa arithmetic.
- **`GpaCalculator` (`app/Domain/GpaCalculator.php`):** Calculates GPA via `credit_weighted`, `simple_average`, and `best_n` methods using exact integer half-up rounding.
- **`PolicyResolver` (`app/Domain/PolicyResolver.php`):** Resolves hierarchical policy cascades (System -> Organization -> Campus -> Contract Type -> Individual Contract) with deep object merging, list replacement, and effective-date windows.
- **`PresetMapper` (`app/Domain/PresetMapper.php`):** Canonical JSON canonicalization, SHA-256 checksum verification, schema validation, and policy mapping.

### Presets & Seeding
- **Canonical Preset:** `database/presets/pk_general_v1.preset.json` (`PK_GENERAL_V1@1.5`).
- **`PresetSeeder` (`database/seeders/PresetSeeder.php`):**
  - Imports preset into `presets` table.
  - Creates 17 default `system_policies`.
  - Preserves empty JSON objects (`component_min_percent: {}`) via `JsonDocument` cast.
  - Completely idempotent (subsequent runs detect existing version and create 0 policies).
  - Throws `PresetChecksumMismatch` if preset content is modified without bumping the version.

### Core Eloquent Models & Providers
- **Models:** `Organization`, `Campus`, `AcademicCalendar`, `Grade`, `Family`, `Student`, `Enrollment`, `User`, `SystemPolicy`, `PolicyOverride`, `LeaveLedger`, `AuditLog`, `OutboxEvent`, `Preset`, etc.
- **Morph Aliases:** Configured in `MorphMapServiceProvider` (`organization`, `campus`, `student`, `employee`, `user`, etc.).
- **Relations:** Integrated across tenants, campuses, families, students, and enrollments.

---

## 2. What Passed

### Test Suite (`./vendor/bin/pest`)
- **Total Tests:** 63 passed (150 assertions).
- **Unit Tests (40 tests):**
  - `ContractPayrollCalculatorTest` (8 tests): Golden numbers and session calculation rules.
  - `GpaCalculatorTest` (7 tests): Golden numbers, GPA calculation methods, rounding.
  - `LateFeeCalculatorTest` (10 tests): Golden numbers, grace days, working day shift, caps.
  - `PolicyResolverTest` (7 tests): Hierarchy cascade, specificity, deep merge, date validity.
  - `PresetMapperTest` (7 tests): 17 policies, SHA-256 stability, JSON object preservation.
  - `ExampleTest` (1 test).
- **PostgreSQL Database Constraint Tests (18 tests in `DatabaseConstraintsTest`):**
  - Single active enrollment per student & closed enrollment alongside active.
  - Mandatory `end_date` on inactive enrollments.
  - Composite foreign key blocking cross-tenant references.
  - `organization_id` mandatory for standard users, forbidden for super admins.
  - Mandatory email or phone number; case-insensitive unique email constraint.
  - Exclusion constraints blocking overlapping active contracts at same campus while allowing different campuses and drafts.
  - Append-only enforcement on `leave_ledgers` and `audit_logs` rejecting updates and deletions.
  - Rejection of duplicate idempotency keys and zero quantities.
  - Leave balance derivation from ledger entries (`1.00 - 0.50 = 0.50`).
  - Exclusion constraints blocking overlapping published policy overrides.
  - Outbox event deduplication by `dedupe_key` within tenant.
- **Seeder & Integration Tests (4 tests):**
  - `PresetSeederTest` (3 tests): First import creates 17 policies, rerun creates 0, modified content triggers `PresetChecksumMismatch`.
  - `EloquentIntegrationTest` (1 test): Creation and reloading of `Organization`, `Campus`, `Family`, `Student`, and `Enrollment` through Eloquent.
  - `ExampleTest` (1 test).

### Golden Numbers Verified
1. **Late Fees:**
   - Day 8: Rs 550 (55,000 paisa)
   - Day 15: Rs 900 (90,000 paisa)
2. **Visiting Payroll:**
   - 22 completed sessions @ Rs 800 = Rs 17,600 (1,760,000 paisa)
3. **GPA Calculation:**
   - `credit_weighted`: 3.51
   - `simple_average`: 3.57

### Static Analysis & Style
- **Laravel Pint:** Passed with 0 violations (`pint --test`).
- **Larastan (Level 5):** Passed with 0 errors across 35 files analysed in `app/`.

---

## 3. Known Gaps / Decisions Pending (Not Done in Step 1)

Known gaps / decisions pending (not done in Step 1):
- Roles ka permission matrix (kaun role kaunsi permission rakhta hai) likha nahi gaya; Access module ka logic aur permission catalog seeder baqi hain.
- Bachon ke data ki privacy/encryption ka faisla baqi hai (abhi sirf national_id_encrypted columns hain); fees/attendance tables se pehle tay hona chahiye.
- Fees double-entry ledger ke tables Phase 2 mein banenge; unmein idempotency_key pehle din se hogi (leave_ledgers ki tarah).
- 'course' morph alias Phase 2 mein Course model ke saath register hoga (CHECK constraint pehle se allow karta hai).
- API contracts, auth/hashing/rate limits, health check, CI/CD, backup, queue retry/DLQ rules abhi documented nahi hain.
- Laravel ka default UserFactory hamari users table (organization_id zaroori) ke saath compatible nahi; use nahi karna.
- migrations-fix-pk-order.zip git mein commit ho gayi hai; hata sakte hain (git rm --cached). *(Note: already removed from git and working tree in commit 70fe7d3).*
- Larastan sirf app/ par chalta hai (tests par nahi).

---

## 4. Next Phase

Phase 1, Step 1 is complete. Do **NOT** start Phase 2 until explicitly requested.
