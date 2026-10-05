# STATE

Phase 1 (Step 1), Phase 2A (Access Module), & Phase 2B (Privacy & Encryption) — **COMPLETED**

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
  - Privacy and encryption migrations:
    - `2026_10_07_000001_add_privacy_columns.php`: drops `students.medical`, adds `b_form_encrypted`, `b_form_hash`, `passport_encrypted`, `passport_hash`, `has_medical_alert`, `has_severe_allergy` to `students`; adds `national_id_encrypted`, `national_id_hash` to `guardians` and `employees`. Includes format check constraints and multi-tenant unique indexes.
    - `2026_10_07_000002_create_student_medical_and_custody_tables.php`: creates `student_medical_profiles` (1:1 with student, composite FK) and `student_custody_orders` (1:N with student, composite FK).
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

### Core Domain, Access & Privacy Services
- **Domain Calculators:**
  - `LateFeeCalculator` (`app/Domain/LateFeeCalculator.php`): Calendar-aware fee calculation.
  - `ContractPayrollCalculator` (`app/Domain/ContractPayrollCalculator.php`): Visiting/part-time payroll in paisa.
  - `GpaCalculator` (`app/Domain/GpaCalculator.php`): Credit-weighted, simple average, best-n.
  - `PolicyResolver` (`app/Domain/PolicyResolver.php`): Hierarchical policy cascades.
  - `PresetMapper` (`app/Domain/PresetMapper.php`): JSON canonicalization & validation.
- **Access Module Services (`app/Services/Access/`):**
  - `ScopeContext`: Small readonly value object for hierarchical scope evaluation (`organizationId`, `campusId`, `programId`, `gradeId`, `sectionId`, `sessionId`).
  - `AccessResolver`: Evaluates `can()` and `canGrant()`. Enforces super-admin unconditional pass, organization tenancy isolation, module enablement checks, active role assignments validity windows, ceiling checks against `role_permissions.max_scope`, hierarchical coverage, and direct permission grants. Restricts sensitive grants to Org Admin or Super Admin.
  - `DelegationService`: Manages `grant()` and `revoke()`. Validates grantor authority, checks `delegation_boundaries.max_scope`, sets `granted_by` and `parent_grant_id`, recursively cascades revoking without deleting rows, and writes audit rows (`access.grant.created`, `access.grant.revoked`).
  - `SodGuard`: Read-only `assertAllowed()` enforcing Separation of Duties against `audit_logs` for paired actions on the same record within tenant. Enforces system and tenant rules for all users, including super admin.
- **Privacy & Encryption Services (`app/Services/Privacy/`):**
  - `BlindIndex`: Deterministic HMAC-SHA256 hashing scoped by `organization_id` using `config('privacy.blind_index_key')`. Strips non-alphanumerics, uppercases prior to hashing, returns 64 lowercase hex characters. Throws `RuntimeException` without leaking values or key when key is missing or shorter than 32 characters.
  - `PiiAccessDenied`: Specialized generic exception thrown on PII authorization or tenancy failure, leaking no plain values, record identifiers, or field names.
  - `PiiViewer`: Controlled decryptor enforcing organization tenancy check before `AccessResolver::can()` checks (`students.ids.view`, `hr.employee.view`, `pastoral.medical.view`, `pastoral.safeguarding.view`). Atomically writes exactly one `audit_logs` row (`action = 'pii.viewed'`, morph alias, meta `['field_group' => ...]`) alongside value revelation inside a database transaction.
  - `StudentMedicalService`: Atomic upsert of `StudentMedicalProfile`, updating `students` flags (`has_medical_alert`, `has_severe_allergy`) with tenancy scoping, and recording `audit_logs` (`action = 'pii.updated'`, meta `['field_group' => 'medical']`). Enforces `pastoral.medical.edit` permission and organizational tenancy.

### Security, Throttling & Key Runbook
- **Password Hashing:** `config/hashing.php` configured with `env('HASH_DRIVER', 'argon2id')` (memory: 65536, time: 4, threads: 1).
- **Login Rate Limiter:** Named rate limiter `login` registered in `AppServiceProvider::boot()` with 5 attempts per minute keyed by `strtolower(trim($email)) . '|' . $ip`.
- **Runbook:** `school-erp/docs/runbooks/KEYS_AND_BACKUPS.md` documenting `APP_KEY`, `BLIND_INDEX_KEY`, secrets store storage rules, generation procedures with placeholders, `APP_KEY` rotation via `APP_PREVIOUS_KEYS`, `BLIND_INDEX_KEY` rotation procedure, data loss warnings, and backup/restore checklists.

### Core Eloquent Models & Morph Map
- **Access Models:** `Permission`, `Role`, `RolePermission`, `RoleAssignment`, `PermissionGrant`, `DelegationBoundary`, `SodRule`.
- **Privacy Models:** `StudentMedicalProfile`, `StudentCustodyOrder`.
- **Domain & System Models:** `User`, `Organization`, `Campus`, `AcademicCalendar`, `Grade`, `Family`, `Student`, `Guardian`, `Employee`, `Enrollment`, `SystemPolicy`, `PolicyOverride`, `LeaveLedger`, `AuditLog`, `Preset`.
- **Encrypted Columns & Scopes:**
  - `Student`, `Guardian`, `Employee`: Encrypted casts on ciphertext columns, `$guarded` protecting ciphertext and hash columns against mass-assignment, `$hidden` preventing exposure in arrays/JSON (D-36), atomic helpers (`setBForm`, `setPassport`, `setNationalId`), and blind index lookup query scopes (`whereBForm`, `wherePassport`, `whereNationalId`).
  - `StudentMedicalProfile`: Encrypted `allergies`, `conditions`, `medications`, `doctor_notes`, `$hidden`.
  - `StudentCustodyOrder`: Encrypted `details`, `$hidden`.
- **Morph Aliases:** Explicitly mapped in `MorphMapServiceProvider` (`student`, `guardian`, `employee`, `student_medical_profile`, `student_custody_order`, `user`, etc.).

---

## 2. What Passed

### Test Suite (`./vendor/bin/pest`)
- **Total Tests:** 187 passed (1190 assertions).
- **Unit Tests (60 tests):**
  - `AccessMatrixTest` (8 tests): Matrix invariants, code format, unique codes, scope types, no sensitive with_grant, SoD rules validation, 13 role keys, principal grant scope restrictions.
  - `BlindIndexTest` (12 tests): Dash/space stripping, case normalization, cross-tenant isolation, key sensitivity, output length, empty/null handling, key length enforcement without leaks.
  - `ContractPayrollCalculatorTest` (8 tests): Golden numbers and session calculation rules.
  - `GpaCalculatorTest` (7 tests): Golden numbers, GPA calculation methods, rounding.
  - `LateFeeCalculatorTest` (10 tests): Golden numbers, grace days, working day shift, caps.
  - `PolicyResolverTest` (7 tests): Hierarchy cascade, specificity, deep merge, date validity.
  - `PresetMapperTest` (7 tests): 17 policies, SHA-256 stability, JSON object preservation.
  - `ExampleTest` (1 test).
- **Feature & Constraint Tests (127 tests):**
  - `PrivacyConstraintsTest` (17 tests): Dropped `medical` column verification, default flags, column-free inserts, duplicate and cross-tenant `b_form_hash` / `passport_hash`, soft-delete hash release, malformed hash check constraints, guardian and employee duplicate hash allowances, single medical profile constraint, cross-tenant composite FK rejection.
  - `EncryptedModelsTest` (10 tests): Ciphertext verification, round-trip decryption, formatted lookups, cross-org denial, empty lookup denial, serialization hiding, clearing, LogicException on missing org, mass-assignment ignoring, model relations.
  - `PiiViewerTest` (13 tests): Controlled access across all 5 methods, audit logging verification, permission denial, cross-tenant denial, campus-scoped isolation, exception message safety, transactional abort on audit failure, null-scope fail-closed assertion.
  - `StudentMedicalServiceTest` (11 tests): Profile creation and flag management, clearing flags, severe allergy argument tracking, permission/cross-tenant denial, upsert uniqueness (single row), meta privacy, ciphertext verification, doctor_notes isolation from alert flag, unknown key ignoring, transactional rollback on audit write failure.
  - `AccessResolverTest` (10 tests): Campus isolation, expired/suspended assignments, section isolation, super admin bypass, cross-org denial, module enablement overrides, ceiling enforcement, direct grants, derived campus module enablement and rejection of unresolvable scopes (D-41).
  - `DelegationServiceTest` (14 tests): Campus admin delegation, cross-campus denial, sensitive grant denial, org_admin sensitive grant, principal fees denial, cross-org denial, cascade revoke with audit logs, delegation boundaries max_scope cap, direct canGrant verification, unauthorized revoke denial, parent grant lifetime capping and no self-delegation (D-37, D-38), SELECT FOR UPDATE row locking and stale model handling (D-39), system role enforcement for org_admin (D-40), scope ID existence validation across tables (D-42).
  - `SodGuardTest` (15 tests): Same-record dual action blocking, distinct actor allowance, distinct record allowance, data-driven tests across all 6 seeded system SoD pairs in both directions, org-specific rules, inactive rule handling, multi-tenant isolation, same permission repeated allowance, super admin enforcement, super admin with null organization with/without organizationId parameter (D-43).
  - `SodRulesConstraintTest` (4 tests): System & org rule inserts, reversed pair rejection (`permission_a >= permission_b`), duplicate system rule rejection, different record_type allowance.
  - `AccessSeederTest` (3 tests): Exact seed counts (62 permissions, 14 system roles, 6 SoD rules), idempotency across re-runs, sensitive grant constraints.
  - `HashingAndThrottlingTest` (7 tests): Rate limiter key generation, normalization, fallback, 5-attempt limit per minute, Argon2id hashing and verification.
  - `DatabaseConstraintsTest` (18 tests): Multi-tenancy composite FKs, single active enrollment, mandatory end_date, user organization requirements, append-only ledgers and audit logs, contract GiST exclusion constraints, policy override GiST exclusion constraints, outbox deduplication.
  - `PresetSeederTest` (3 tests): First import creates 17 policies, rerun creates 0, modified content triggers `PresetChecksumMismatch`.
  - `EloquentIntegrationTest` (1 test): Creation and reloading of core models through Eloquent.
  - `ExampleTest` (1 test).

### Seed Idempotency & Database Row Counts
- Verified via `php artisan migrate:fresh --seed` (run twice):
  - `permissions`: 62
  - `roles (system)`: 14 (13 system roles + `org_admin`)
  - `role_permissions`: 219
  - `sod_rules`: 6
  - `system_policies`: 17
- Verified database schema:
  - `student_medical_profiles` table exists.
  - `student_custody_orders` table exists.
  - `students` table contains `b_form_encrypted`, `b_form_hash`, `passport_encrypted`, `passport_hash`, `has_medical_alert`, `has_severe_allergy`.

### Static Analysis, Linting & Style Gates
- **PHP Syntax:** All PHP files under `app/`, `database/`, and `tests/` linted cleanly (`php -l`).
- **Laravel Pint:** Passed with 0 violations (`pint --test`).
- **Larastan (Level 5):** Passed with 0 errors across 54 files analysed in `app/`.

---

## 3. Consolidated Known Gaps

The following consolidated known gaps remain out of scope for Phase 2B:
1. **`BLIND_INDEX_KEY` rotation command not built:** Key rotation requires an offline, batch-recomputing command per organization with count and unique index verification; currently unsupported until built.
2. **Re-encrypt command not built (D-31):** Key rotation for `APP_KEY` decrypts old rows via `APP_PREVIOUS_KEYS`, but old rows stay encrypted with the previous key until rewritten; `APP_PREVIOUS_KEYS` must stay until a full re-encrypt command exists and is executed.
3. **Counselling and safeguarding tables are later phases:** Custody orders have a model and viewer (`PiiViewer::custody`), but no dedicated writer service or workflow engine yet.
4. **No HTTP layer yet:** When built, `ScopeContext` must be derived from the record's own campus, never from user-supplied request input.
5. **`PiiViewer` scope parameter is nullable:** Passing `null` fails closed (denied and tested for campus-scoped roles); consider making it strictly required when the HTTP layer is built.
6. **Self-scope resolution for parent/student deferred (from 2A):** Dynamic resolution of `self` scope is deferred pending student/parent authentication and guardian-user relationship linking.
7. **Role-assignment delegation not built (from 2A):** Delegating entire role assignments (`access.role.assign`) is not built (only single permission grants via `DelegationService::grant`).
8. **`delegation_boundaries.requires_approval` ignored (from 2A):** Bypassed pending multi-step workflow approval engine.
9. **Audit row writing by feature modules (from 2A):** `SodGuard` is read-only. Feature modules must write the audit row named after the permission code upon executing an action and must invoke `SodGuard::assertAllowed()` prior to acting.
10. **No per-IP aggregate login cap (from 2A):** Rate limiting is enforced per `email + IP` (5/min). An aggregate per-IP cap is deferred to be decided together with public API contracts.
11. **grants made through a role assignment are not capped by the assignment's end date**
12. **can() runs several queries per call, list endpoints must filter by scope in the query, never call can() per row**
13. **SoD plus action are not yet atomic, to be solved in Phase 2C (record row lock and ActionGate)**

---

## 4. Next Phase

Phase 2B (Privacy & Encryption) is complete. Do **NOT** start Phase 2C until a new `PRD.md` is provided.

