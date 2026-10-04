# School ERP: Final Documentation Package (Step 1)

Covers: 1. Core Architecture, 2. The 17 System Policies, 3. Preset JSON v1.5, 4. Golden Test specs, 5. Decision Ledger.
Design history lives in the blueprint documents v1.1 to v1.5; this file is the build-time reference.

---

## 1. Core Architecture

### 1.1 Stack
Laravel 12, PHP 8.2+, PostgreSQL 16 (extensions `btree_gist`, `pg_trgm`), Pest, Redis (queues and cache later). ULID primary keys everywhere.

### 1.2 Tenancy
One shared database. Every tenant table has `organization_id`, placed first in composite indexes. Composite foreign keys `(organization_id, id)` make a cross-tenant reference impossible at the database level. PostgreSQL row-level security is planned as a later hardening step.

### 1.3 Hierarchy and access
Super Admin > Organization Admin > Campus Admin > Principal and VP.
- Each level can delegate only what it holds (ceilings and grant chains; `with_grant` says whether a holder may pass a permission on).
- Sensitive permissions can only be granted by the Org Admin.
- Custom Access module (tables: permissions, roles, role_permissions, role_assignments, permission_grants, delegation_boundaries, module_enablement). Spatie is not used.
- Grants are scoped (org, campus, program, grade, section, session), time-windowed and revocable.

### 1.4 Academic structure
Program (Matric, Cambridge run in parallel) > Academic calendar (per program, optional per campus) > Terms. Grades belong to a program; sections belong to campus + calendar + grade. An Enrollment is student + campus + calendar + grade (+ section); a student has one active enrollment at a time.

### 1.5 Event-based academic engine (Phase 2 build, schema direction fixed)
Timetable versions generate sessions. Attendance is stored as exceptions only for period-based levels; daily status is derived. Substitution applies per session. Exclusion constraints prevent teacher and room clashes. Fees attach to calendar + enrollment, never to events. Exams attach to courses.

### 1.6 Money
Integer minor units (paisa) in `bigint`. No floats. Family-wise fees with a double-entry ledger (Phase 2). Leave quantity is `numeric(8,2)`.

### 1.7 Append-only data
`leave_ledgers` and `audit_logs` reject UPDATE and DELETE through trigger `prevent_row_mutation()`. Corrections are new reversal rows. Leave balance is a view: `SUM(qty)` per contract and leave type.

### 1.8 Staff and contracts
`employment_contracts` links employee + campus + contract type, with `pay_basis` (monthly, hourly, per_session, daily), `rate_minor`, and `policy_overrides` (JSONB). Leave and payroll rules hang on the contract type; a contract can override them. One active contract per employee per campus at a time (GiST exclusion); concurrent contracts at different campuses are allowed.

### 1.9 Policy engine
`presets` (immutable per key + version, SHA-256 checksum of canonical JSON) > `system_policies` (rows loaded by PresetSeeder) > `policy_overrides` (tenant overrides).
- Override scope is a morph: organization (10), campus (20), program or contract_type (30), grade (40), course (50), employment_contract (60). The more specific scope wins.
- Merge: recursive deep merge; lists and scalars are replaced; explicit null sets null; `replace` mode discards lower layers.
- Overrides are effective-dated (end exclusive), versioned, draft or published. Two published overrides for the same policy and scope cannot overlap (database constraint).

### 1.10 Dynamic layers (v1.5)
Settings, Policies, Formulas, Automations, Workflows, Templates, Custom Fields. Guardrails: nothing hardcoded that a school may reasonably change; every dynamic value validated against a schema; every change audited.

### 1.11 Schema (12 migrations, 34 tables and views)
See `docs/PART1_INIT_GUIDE.md` for the order and what each creates.

---

## 2. The 17 System Policies (preset PK_GENERAL_V1, v1.5)

| # | type / key | What it controls | Default | Override levels | Editable by |
|---|---|---|---|---|---|
| 1 | late_fee / default | Late fee steps | Days 1-7 flat Rs 500; from day 8 +Rs 50/day; cumulative; per voucher; posted on late payment | org, campus, program | org_admin |
| 2 | discount_stacking / default | How discounts combine | Highest single discount only | org, campus | org_admin |
| 3 | discount_definitions / default | Discount catalog | sibling, staff, merit, early_lump_sum | org, campus | org_admin |
| 4 | leave_policy / student | Student leave | 15 days per year, medical + casual, shared pool; over limit needs coordinator approval | org, campus | org_admin, campus_admin |
| 5 | absence_alert / consecutive_unnotified | Absence SMS | 3 consecutive school days absent without leave; SMS at 09:00 after attendance is verified; notifies class teacher and coordinator | org, campus | org_admin, campus_admin |
| 6 | transfer_finance / default | Inter-campus transfer | Revenue stays with old campus until effective date; advance moves as credit; daily proration; arrears need override | org | org_admin |
| 7 | refund_policy / default | Refunds | Only security deposit refundable, after library, lab and accounts clearance; dues deducted; pay within 30 days | org, campus | org_admin |
| 8 | promotion / default | Promotion | Pass = overall >= 40 and each subject >= 40; min attendance 75%; failed students proposed for retention, never auto-promoted; Principal or Academic Head decides | org, campus, program | org_admin |
| 9 | session_generation / default | Session generation | Rolling horizon; skips holidays; never touches marked sessions | org, campus | org_admin, campus_admin |
| 10 | attendance_policy / default | Attendance mode by level | Levels 0-8 daily (homeroom); 9-14 per period, exceptions only | org, campus, grade | org_admin, campus_admin |
| 11 | daily_status_derivation / default | Daily status from sessions | Majority of sessions; present threshold 50% | org, campus | org_admin |
| 12 | substitution_policy / default | Substitute teachers | Candidate priority, extra-period limit, approval roles | org, campus | org_admin, campus_admin |
| 13 | grading_profile / default | Marks, grades, GPA, pass | Weights 20/30/50; bands A* 90, A 80, B 70, C 60, D 50, E 40, F below 40; points 4.0, 3.7, 3.0, 2.0, 1.0, 0.5, 0.0; pass 40% overall and per subject; GPA simple_average (credit_weighted and best_n allowed) | org, program, grade, course | org_admin |
| 14 | contract_types / default | Staff contract types | permanent, probation, fixed_term (monthly); visiting (per_session); part_time (hourly) | org, campus | org_admin |
| 15 | payroll_deduction / standard | Deductions | 3 lates = half day (leave balance first, then salary); unapproved absence = 1 day; per-day = gross / 30; HR waiver with reason | org | org_admin |
| 16 | payroll_deduction / none | No deductions | For visiting and part-time contracts | org | org_admin |
| 17 | automation_templates / default | Ready-made automations | daily_absent_sms, absence_streak, voucher_due_reminder, voucher_overdue_reminder, result_published_notice, contract_expiry_alert | org, campus | org_admin, campus_admin |

Late-fee methods implemented: flat_once, per_day, percent_of_head, percent_of_balance. `slab` and `formula` are allowed in the schema but refuse to run until implemented (no silent guessing).

---

## 3. Preset JSON v1.5 (`database/presets/pk_general_v1.preset.json`)

- Top level: `preset`, `version`, `notes`, `general`, `calendars`, `fee_heads`, `policies`, `bell_schedules`.
- `general`: PKR, Asia/Karachi, DD-MMM-YYYY, week starts Monday, languages en and ur, weekly off Saturday and Sunday.
- `calendars`: Matric (Apr to Mar, two terms) and Cambridge (Aug to Jul).
- `fee_heads`: tuition, admission, annual, security_deposit (liability, refundable), transport and others.
- `policies`: the 17 above, each with `type`, `key`, `value`, `override_levels`, `editable_by`.
- `bell_schedules`: Standard Day, periods P1.. with `used_for_attendance`.

Import rules (PresetImporter):
1. Same key + version + same content: no-op (safe to re-run `db:seed`).
2. Same key + version + changed content: `PresetChecksumMismatch`. Bump the version in the JSON instead.
3. New version: new rows; organizations stay pinned to their version until upgraded.
4. Empty JSON objects stay objects (`{}`), through the `JsonDocument` cast.

---

## 4. Golden Test Specs

Full tables in `docs/GOLDEN_TESTS.md`. Summary:
- G1 Late fee: due 10-Oct-2026 (Saturday) shifts to 12-Oct. Day 8 = Rs 550 (55,000 paisa). Day 15 = Rs 900 (90,000).
- G2 Visiting payroll: 22 completed sessions x Rs 800 = Rs 17,600 (1,760,000).
- G3 GPA: courses (3.7, 3 cr), (3.0, 4 cr), (4.0, 3 cr). credit_weighted = 3.51, simple_average = 3.57.

---

## 5. Decision Ledger

| ID | Decision | Reason |
|---|---|---|
| D-01 | PostgreSQL, not MySQL | Exclusion constraints, partial and expression indexes, JSONB with GIN, triggers |
| D-02 | Schema only through Laravel migrations; `migrate:fresh --seed` rebuilds everything | Reproducible on any machine; no manual SQL |
| D-03 | ULID primary keys | Safe to expose, sortable, no enumeration |
| D-04 | Shared DB with `organization_id` and composite FKs | Cheap to run, tenant isolation enforced by the database |
| D-05 | Money as bigint paisa; leave as numeric(8,2) | No float errors |
| D-06 | Append-only ledgers with trigger; balance is a view | Auditability; corrections by reversal |
| D-07 | Custom Access module instead of spatie permission | Ceilings, grant chains, scopes and time windows do not fit spatie |
| D-08 | Fees family-wise; "invoice" is really a receipt | Matches how Pakistani schools bill |
| D-09 | Presets immutable per version, checksummed | Reliable upgrades; tampering is detected |
| D-10 | Overrides are morph-based, effective-dated, versioned | One mechanism for org, campus, program, grade, course, contract |
| D-11 | Specific scope wins; deep merge, lists replaced, null explicit | Predictable resolution |
| D-12 | Leave and payroll rules hang on contract type, with per-contract override | Permanent, probation, visiting and part-time staff differ |
| D-13 | One active contract per employee per campus; different campuses allowed | Staff may teach at two campuses |
| D-14 | Attendance stored as exceptions for period levels; daily status derived | Smaller tables, faster writes |
| D-15 | GPA scale (A = 3.7, A* = 4.0) kept as the user's configured default, editable per org | It is policy data, not code |
| D-16 | Unimplemented rule methods throw instead of guessing | Money rules must never silently approximate |
| D-17 | Phased build, data model first, visual builders later | Avoid over-engineering; get a working MVP |
| D-18 | `course` is already allowed by the scope CHECK; its morph-map alias is registered in Phase 2 with the Course model | Model does not exist yet; no schema change needed |
