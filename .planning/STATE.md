# STATE

Phase 1 (Step 1), Phase 2A (Access Module), Phase 2B (Privacy & Encryption), & Phase 2C-1 (Ledger Core) — **COMPLETED**

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

### Ledger Core Module (Phase 2C-1)
- **Tables (5 core tables):**
  - `accounts`: Chart of accounts with type (`asset`, `liability`, `income`, `expense`, `equity`), unique `(organization_id, system_key)`, `requires_family`, and guard trigger blocking type mutation once lines exist.
  - `ledger_periods`: Date ranges per organization with GiST non-overlap exclusion constraint, auto-created calendar months, and guard trigger preventing reopen or date alteration once entries exist.
  - `journal_entries`: Append-only entries with ULID keys, unique `(organization_id, source_type, source_id, kind)`, `kind IN ('posting', 'reversal')`, `reversal_of`, shape declarations (`line_count`, `total_minor`), and `lines_hash` (SHA-256 canonical hash). Checked via deferred balance constraints at commit.
  - `journal_lines`: Double-entry lines with `debit_minor` / `credit_minor` paisa checks (exactly one side > 0), unique `(entry_id, line_no)`, composite FKs, and family sub-ledger enforcement.
  - `number_sequences`: Gap-free sequential numbering per `(organization_id, campus_id, key, fiscal_year)` via atomic upsert with row locks held until commit; trigger blocks decrements, jumps, and deletes.
- **Services (`app/Services/Ledger/`):**
  - `DefaultChartOfAccounts`: Idempotent seeding of 15 standard D-48 accounts via `seedFor(string $organizationId)`.
  - `FiscalYear`: `startYearFor(CarbonInterface $date, ?int $startMonth = null)` resolving fiscal start years based on configurable start month (default 7).
  - `NumberSequenceService`: `next(string $organizationId, string $campusId, string $key, CarbonInterface $date)` atomic sequence generation.
  - `LedgerPeriodService`: `forDate(string $organizationId, CarbonInterface $date)` period resolution/creation and `close(User $actor, LedgerPeriod $period, ScopeContext $scope)` audit-logged closing with concurrency locks.
  - `LedgerPoster`: Internal append-only poster (`post(PostingRequest)`, `reverse(JournalEntry, User, ?CarbonInterface, ?string)`) with PHP pre-validation, canonical line hashing, idempotent replay, raw SQL insertion with conflict handling, mirrored reversal line construction, and immediate deferred constraint checks.
  - `LedgerReports`: Internal reporting service (`trialBalance`, `familyBalance`) with DB-level SQL aggregation, tenancy and permission authorization, and strict scope coverage checks.
- **Exceptions (`app/Services/Ledger/`):**
  - `LedgerAccessDenied`: RuntimeException with generic `"Not allowed."` message.
  - `LedgerValidationException`: Validation exceptions with specific reason codes.
  - `LedgerConflict`: Thrown on concurrent posting with conflicting lines hash.
  - `LedgerPeriodClosed`: Thrown when posting into a closed period.

### Security, Throttling & Key Runbook
- **Password Hashing:** `config/hashing.php` configured with `env('HASH_DRIVER', 'argon2id')` (memory: 65536, time: 4, threads: 1).
- **Login Rate Limiter:** Named rate limiter `login` registered in `AppServiceProvider::boot()` with 5 attempts per minute keyed by `strtolower(trim($email)) . '|' . $ip`.
- **Runbook:** `school-erp/docs/runbooks/KEYS_AND_BACKUPS.md` documenting `APP_KEY`, `BLIND_INDEX_KEY`, secrets store storage rules, generation procedures with placeholders, `APP_KEY` rotation via `APP_PREVIOUS_KEYS`, `BLIND_INDEX_KEY` rotation procedure, data loss warnings, and backup/restore checklists.

### Core Eloquent Models & Morph Map
- **Access Models:** `Permission`, `Role`, `RolePermission`, `RoleAssignment`, `PermissionGrant`, `DelegationBoundary`, `SodRule`.
- **Privacy Models:** `StudentMedicalProfile`, `StudentCustodyOrder`.
- **Ledger Models:** `Account`, `LedgerPeriod`, `JournalEntry`, `JournalLine`, `NumberSequence` (all with ULIDs, strict casting, immutable triggers, no updated_at on entries/lines).
- **Domain & System Models:** `User`, `Organization`, `Campus`, `AcademicCalendar`, `Grade`, `Family`, `Student`, `Guardian`, `Employee`, `Enrollment`, `SystemPolicy`, `PolicyOverride`, `LeaveLedger`, `AuditLog`, `Preset`.
- **Encrypted Columns & Scopes:**
  - `Student`, `Guardian`, `Employee`: Encrypted casts on ciphertext columns, `$guarded` protecting ciphertext and hash columns against mass-assignment, `$hidden` preventing exposure in arrays/JSON (D-36), atomic helpers (`setBForm`, `setPassport`, `setNationalId`), and blind index lookup query scopes (`whereBForm`, `wherePassport`, `whereNationalId`).
  - `StudentMedicalProfile`: Encrypted `allergies`, `conditions`, `medications`, `doctor_notes`, `$hidden`.
  - `StudentCustodyOrder`: Encrypted `details`, `$hidden`.
- **Morph Aliases:** Explicitly mapped in `MorphMapServiceProvider` (`student`, `guardian`, `employee`, `student_medical_profile`, `student_custody_order`, `user`, `account`, `ledger_period`, `journal_entry`, `journal_line`).

---

## 2. What Passed

### Test Suite (`./vendor/bin/pest`)
- **Total Tests:** 301 passed (1714 assertions).
- **Ledger Core Tests (114 tests):**
  - `LedgerConstraintsTest` (21 tests): Balanced entry acceptance, unbalanced entry trigger rejection, zero lines rejection, line count mismatch rejection, total minor mismatch rejection, post-commit line addition rejection, immutability of entries and lines, invalid debit/credit combinations rejection, family requirement enforcement, inactive account rejection, cross-tenant isolation, period date boundaries, closed period immutability and reopen prevention, exclusion constraint on overlapping periods, unique source duplicate rejection, comprehensive reversal rules, account type mutation prevention, unique system_key per organization, number sequence advance rules.
  - `DefaultChartOfAccountsTest` (7 tests): Idempotent seeding of 15 accounts with types and requires_family flags, type preservation on re-run, separate sets across organizations, system_key uniqueness and scope queries.
  - `LedgerModelsTest` (8 tests): Model ULID creation, relations, casts, append-only exception throwing on update/delete for JournalEntry and JournalLine, and delete prevention on NumberSequence.
  - `FiscalYearTest` (4 tests): Fiscal year start calculations for July and January starts, config default handling, invalid month rejection.
  - `NumberSequenceServiceTest` (12 tests): Sequential number generation (1, 2, 3), independence per campus/key/fiscal year, cross-org campus rejection, rollback number recovery, invalid key validation.
  - `LedgerPeriodServiceTest` (13 tests): Month period auto-creation and reuse, boundary date handling, closed period retrieval, cross-org isolation, closing with permission, permission/cross-org denial, single audit log row, DB reopen prevention.
  - `LedgerPosterTest` (23 tests): P3 voucher issue and P4 payment postings, idempotency on re-post, conflict on changed lines, hash invariance to line order/date/memo, retry in closed periods, closed period first-post rejection, missing period auto-creation, PHP pre-validation datasets (unbalanced, both sides, zero amount, single line, floats, strings, inactive account, foreign account, foreign campus, missing family, foreign family, invalid source, long memo, overflow).
  - `LedgerReversalTest` (12 tests): Mirrored lines and swapped sides, original byte-identical preservation, idempotent second reverse, lines_hash divergence, reversal of reversal rejection, date before original rejection, long memo rejection, auto-created period on closed original, closed reversal period rejection, cross-org actor denial, super admin reversal, SQL trial balance netting to zero, null date today default.
  - `LedgerGoldenTest` (6 tests): P3 voucher issue trial balance, P4 payment netting family balance to 0, P5 append-only update/delete query exceptions, P6 unbalanced entry rejections via PHP and DB trigger, P7 reversal netting trial balance to zero, P8 security deposit refund with credit-normal negative balance.
  - `LedgerReportsTest` (8 tests): Trial balance debit equals credit, zero rows for inactive accounts, inclusive date range filtering, campus filtering, invalid input exceptions, permission and scope denial scenarios with exact message "Not allowed.", super admin access, multi-tenant data isolation.
- **Access, Privacy & Domain Tests (187 tests):**
  - All existing unit, feature, and constraint tests continue to pass with 100% green status.

### Seed Idempotency & Database Row Counts
- Verified via `php artisan migrate:fresh --seed` (run twice):
  - `permissions`: 62
  - `roles (system)`: 14 (13 system roles + `org_admin`)
  - `role_permissions`: 219
  - `sod_rules`: 6
  - `system_policies`: 17
  - `accounts`: 0
  - `ledger_periods`: 0
  - `journal_entries`: 0
  - `journal_lines`: 0
  - `number_sequences`: 0

### Static Analysis, Linting & Style Gates
- **PHP Syntax:** All PHP files under `app/`, `database/`, and `tests/` linted cleanly (`php -l`).
- **Laravel Pint:** Passed with 0 violations (`pint --test`).
- **Larastan (Level 5):** Passed with 0 errors across 71 files analysed in `app/`.

---

## 3. Consolidated Known Gaps

The following consolidated known gaps remain for Phase 2C-1:
1. **no vouchers, payments or fee tables yet (2C-2 to 2C-4)**
2. **DefaultChartOfAccounts is not wired to organization creation**
3. **manual adjustments (ledger.adjustment.post) not built**
4. **custom period ranges not built**
5. **ledger reports have no HTTP layer**
6. **familyBalance and organization-wide trial balance require an organization-wide scope (campus-scoped variants not built)**
7. **LedgerPoster is internal and must never be called from a controller directly**
8. **the config value fiscal_year_start_month is global, not per organization**
9. **`BLIND_INDEX_KEY` rotation command not built:** Key rotation requires an offline, batch-recomputing command per organization with count and unique index verification; currently unsupported until built.
10. **Re-encrypt command not built (D-31):** Key rotation for `APP_KEY` decrypts old rows via `APP_PREVIOUS_KEYS`, but old rows stay encrypted with the previous key until rewritten; `APP_PREVIOUS_KEYS` must stay until a full re-encrypt command exists and is executed.
11. **Counselling and safeguarding tables are later phases:** Custody orders have a model and viewer (`PiiViewer::custody`), but no dedicated writer service or workflow engine yet.
12. **No HTTP layer yet for privacy:** When built, `ScopeContext` must be derived from the record's own campus, never from user-supplied request input.
13. **`PiiViewer` scope parameter is nullable:** Passing `null` fails closed (denied and tested for campus-scoped roles); consider making it strictly required when the HTTP layer is built.
14. **Self-scope resolution for parent/student deferred (from 2A):** Dynamic resolution of `self` scope is deferred pending student/parent authentication and guardian-user relationship linking.
15. **Role-assignment delegation not built (from 2A):** Delegating entire role assignments (`access.role.assign`) is not built (only single permission grants via `DelegationService::grant`).
16. **`delegation_boundaries.requires_approval` ignored (from 2A):** Bypassed pending multi-step workflow approval engine.
17. **Audit row writing by feature modules (from 2A):** `SodGuard` is read-only. Feature modules must write the audit row named after the permission code upon executing an action and must invoke `SodGuard::assertAllowed()` prior to acting.
18. **No per-IP aggregate login cap (from 2A):** Rate limiting is enforced per `email + IP` (5/min). An aggregate per-IP cap is deferred to be decided together with public API contracts.
19. **grants made through a role assignment are not capped by the assignment's end date**
20. **can() runs several queries per call, list endpoints must filter by scope in the query, never call can() per row**
21. **SoD plus action are not yet atomic, to be solved in Phase 2C (record row lock and ActionGate)**

---

## 4. Next Phase

Phase 2C-1 (Ledger Core) is complete. Do **NOT** start Phase 2C-2 until a new PRD is provided.

