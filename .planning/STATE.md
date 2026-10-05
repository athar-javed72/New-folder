# STATE

Phase 1 (Step 1) & Phase 2A (Access Module) — **COMPLETED**

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
- **Migrations:**
  - 12 core package migrations (`2026_10_02_000001` through `2026_10_02_000012`).
  - Access control separation of duties migration: `2026_10_06_000001_create_sod_rules_table.php`.
- **Multi-Tenancy:**
  - Shared database architecture enforced via `organization_id` on every tenant table.
  - System definitions (`system_policies`, system `roles`, system `sod_rules`) carry `organization_id = NULL`.
  - Composite foreign keys `(organization_id, id)` prevent cross-tenant referencing at the PostgreSQL engine level.
- **Data Integrity & Constraints:**
  - Primary keys use ULIDs (`ulid` column type, 26-character sortable identifiers).
  - Money stored strictly in integer minor units (`bigint` paisa; never floats).
  - Append-only tables (`leave_ledgers`, `audit_logs`) protected by PostgreSQL trigger `prevent_row_mutation()` rejecting `UPDATE` and `DELETE`.
  - Transaction outbox deduplication via `(organization_id, dedupe_key)`.
  - PostgreSQL GiST exclusion constraints enforce non-overlapping published policy overrides and non-overlapping active employee contracts per campus.
  - Single active enrollment constraint per student via partial unique index.
  - SoD pair canonical ordering constraint (`permission_a < permission_b`) and uniqueness on `(COALESCE(organization_id, '...'), record_type, permission_a, permission_b)`.

### Presets, Access Matrix & Seeding
- **Canonical Preset:** `database/presets/pk_general_v1.preset.json` (`PK_GENERAL_V1@1.5`).
- **Access Matrix Definition:** `database/data/access_matrix.json` (62 permissions, 13 system roles, 6 system SoD rules).
- **Seeders (`database/seeders/`):**
  - `PresetSeeder`: Imports preset into `presets` table and creates 17 default `system_policies`. Idempotent checksum verification.
  - `PermissionSeeder`: Upserts 62 permissions by `code` with `is_sensitive`, `is_delegable`, and `module`.
  - `RoleSeeder`: Upserts 13 system roles with mapped `max_scope` and `with_grant`, adds system `org_admin` holding all 62 permissions at org scope with `with_grant = true` (only role carrying `with_grant` on sensitive permissions), and seeds 6 system `sod_rules`.
  - `DatabaseSeeder`: Coordinates `PresetSeeder`, `PermissionSeeder`, and `RoleSeeder`. Fully idempotent.

### Core Domain & Access Services
- **Domain Calculators:**
  - `LateFeeCalculator` (`app/Domain/LateFeeCalculator.php`): Calendar-aware fee calculation.
  - `ContractPayrollCalculator` (`app/Domain/ContractPayrollCalculator.php`): Visiting/part-time payroll in paisa.
  - `GpaCalculator` (`app/Domain/GpaCalculator.php`): Credit-weighted, simple average, best-n.
  - `PolicyResolver` (`app/Domain/PolicyResolver.php`): Hierarchical policy cascades.
  - `PresetMapper` (`app/Domain/PresetMapper.php`): JSON canonicalization & validation.
- **Access Module Services (`app/Services/Access/`):**
  - `ScopeContext`: Small readonly value object for hierarchical scope evaluation (`organizationId`, `campusId`, `programId`, `gradeId`, `sectionId`, `sessionId`).
  - `AccessResolver`: Evaluates `can()` and `canGrant()`. Enforces super-admin unconditional pass, organization tenancy isolation, module enablement checks (campus override > org override > default enabled), active role assignments validity windows, ceiling checks against `role_permissions.max_scope`, hierarchical coverage (`org > campus > program > grade > section > session`), and direct permission grants. Restricts sensitive grants to Org Admin or Super Admin.
  - `DelegationService`: Manages `grant()` and `revoke()`. Validates grantor grant authority, checks `delegation_boundaries.max_scope`, sets `granted_by` and `parent_grant_id`, recursively cascades revoking without deleting rows, and writes audit rows (`access.grant.created`, `access.grant.revoked`).
  - `SodGuard`: Read-only `assertAllowed()` enforcing Separation of Duties against `audit_logs` for paired actions on the same record within tenant. Enforces system and tenant rules for all users, including super admin.

### Security & Throttling Configuration
- **Password Hashing:** `config/hashing.php` configured with `env('HASH_DRIVER', 'argon2id')` (memory: 65536, time: 4, threads: 1).
- **Login Rate Limiter:** Named rate limiter `login` registered in `AppServiceProvider::boot()` with 5 attempts per minute keyed by `strtolower(trim($email)) . '|' . $ip` (safe handling for missing/non-string emails).

### Core Eloquent Models
- **Access Models:** `Permission`, `Role`, `RolePermission`, `RoleAssignment`, `PermissionGrant`, `DelegationBoundary`, `SodRule`.
- **Domain & System Models:** `User`, `Organization`, `Campus`, `AcademicCalendar`, `Grade`, `Family`, `Student`, `Enrollment`, `SystemPolicy`, `PolicyOverride`, `LeaveLedger`, `AuditLog`, `OutboxEvent`, `Preset`.
- **Morph Aliases:** Configured in `MorphMapServiceProvider`.

---

## 2. What Passed

### Test Suite (`./vendor/bin/pest`)
- **Total Tests:** 118 passed (844 assertions).
- **Unit Tests (48 tests):**
  - `AccessMatrixTest` (8 tests): Matrix invariants, code format, unique codes, scope types, no sensitive with_grant, SoD rules validation, 13 role keys, principal grant scope restrictions.
  - `ContractPayrollCalculatorTest` (8 tests): Golden numbers and session calculation rules.
  - `GpaCalculatorTest` (7 tests): Golden numbers, GPA calculation methods, rounding.
  - `LateFeeCalculatorTest` (10 tests): Golden numbers, grace days, working day shift, caps.
  - `PolicyResolverTest` (7 tests): Hierarchy cascade, specificity, deep merge, date validity.
  - `PresetMapperTest` (7 tests): 17 policies, SHA-256 stability, JSON object preservation.
  - `ExampleTest` (1 test).
- **Feature & Constraint Tests (70 tests):**
  - `AccessResolverTest` (9 tests): Campus isolation, expired/suspended assignments, section isolation, super admin bypass, cross-org denial, module enablement overrides, ceiling enforcement, direct grants.
  - `DelegationServiceTest` (10 tests): Campus admin delegation, cross-campus denial, sensitive grant denial, org_admin sensitive grant, principal fees denial, cross-org denial, cascade revoke with audit logs, delegation boundaries max_scope cap, direct canGrant verification, unauthorized revoke denial.
  - `SodGuardTest` (14 tests): Same-record dual action blocking, distinct actor allowance, distinct record allowance, data-driven tests across all 6 seeded system SoD pairs in both directions, org-specific rules, inactive rule handling, multi-tenant isolation, same permission repeated allowance, super admin enforcement.
  - `SodRulesConstraintTest` (4 tests): System & org rule inserts, reversed pair rejection (`permission_a >= permission_b`), duplicate system rule rejection, different record_type allowance.
  - `AccessSeederTest` (3 tests): Exact seed counts (62 permissions, 14 system roles, 6 SoD rules), idempotency across re-runs, sensitive grant constraints.
  - `HashingAndThrottlingTest` (7 tests): Rate limiter key generation (same IP/email, different IP, different email), case and whitespace normalization, null/missing email fallback, 5-attempt limit per minute, Argon2id password hashing and verification.
  - `DatabaseConstraintsTest` (18 tests): Multi-tenancy composite FKs, single active enrollment, mandatory end_date, user organization requirements, append-only ledgers and audit logs, contract GiST exclusion constraints, policy override GiST exclusion constraints, outbox deduplication.
  - `PresetSeederTest` (3 tests): First import creates 17 policies, rerun creates 0, modified content triggers `PresetChecksumMismatch`.
  - `EloquentIntegrationTest` (1 test): Creation and reloading of core models through Eloquent.
  - `ExampleTest` (1 test).

### Seed Idempotency & Database Row Counts
- Verified via `php artisan migrate:fresh --seed` followed by `php artisan db:seed`:
  - `permissions`: 62
  - `roles (system)`: 14 (13 system roles + `org_admin`)
  - `role_permissions`: 219
  - `sod_rules`: 6
  - `system_policies`: 17

### Static Analysis, Linting & Style Gates
- **PHP Syntax:** All PHP files under `app/`, `database/seeders/`, and `tests/` linted cleanly (`php -l`).
- **Laravel Pint:** Passed with 0 violations (`pint --test`).
- **Larastan (Level 5):** Passed with 0 errors across 48 files analysed in `app/`.

---

## 3. Consolidated Known Gaps

The following consolidated known gaps remain out of scope for Phase 2A and are deferred to their designated future phases:
1. **Self-scope resolution for parent/student:** Dynamic resolution of `self` scope (parent viewing own children via `guardian_student`, student viewing own records) is deferred pending student/parent authentication and guardian-user relationship linking.
2. **Role-assignment delegation (`access.role.assign`):** Delegating entire role assignments is not supported in Phase 2A (only single permission grants via `DelegationService::grant`).
3. **`delegation_boundaries.requires_approval`:** Flag is stored in schema but currently bypassed pending implementation of a multi-step workflow approval engine.
4. **Audit row writing by feature modules:** `SodGuard` is read-only. Feature modules must write the audit row (`action = permission code`) upon executing an action and must invoke `SodGuard::assertAllowed()` prior to acting.
5. **No per-IP aggregate login cap:** Rate limiting is enforced per `email + IP` (5/min). An aggregate per-IP cap is deferred to be decided together with public API contracts.
6. **No HTTP layer yet:** No HTTP routes, controllers, middleware wiring, login/registration endpoints, or API resources are implemented in Phase 2A.
7. **Field-level privacy and encryption belong to Phase 2B:** Sensitive student/guardian fields (e.g., national ID encryption, blind indexes) are deferred to Phase 2B.

---

## 4. Next Phase

Phase 2A (Access Module) is complete. Do **NOT** start Phase 2B (privacy and encryption) until explicitly requested.
