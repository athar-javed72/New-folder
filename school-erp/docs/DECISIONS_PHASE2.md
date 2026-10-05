# Phase 2 Decisions (final)

Status: FINAL for the Phase 2 PRDs. Continues the Decision Ledger in `DOCUMENTATION_PACKAGE.md` (D-01 to D-18).
Rule for agents: implement exactly this. Anything not listed here is not decided; stop and ask.

---

## A. Access: permission catalog, role matrix, separation of duties

### D-19 Permission codes and catalog
- Format `module.resource.action`. The catalog is data, seeded by a `PermissionSeeder`, never hardcoded in controllers.
- Columns already exist (migration 05): `is_sensitive`, `is_delegable`, `allowed_scopes`, `depends_on`.
- Sensitive permissions (marked S below) can only be granted or delegated by the Org Admin. A role may contain them, but a Campus Admin cannot pass them on (`with_grant` is never set on sensitive rows).

### D-20 Scopes in the matrix
O = organization, C = campus, A = assigned classes/sections/subjects, S = own self or own children. A trailing `+` means the holder may pass it on (`with_grant`) inside what they hold. The Org Admin holds everything at O with grant. The Super Admin holds everything on the platform.

### D-21 Starting role matrix (editable per organization; these are the system roles)
Columns: CA Campus Admin, PR Principal, VP Vice Principal, AC Academic Coordinator, CT Class Teacher, ST Subject Teacher, ADM Admissions, ACC Accountant, CSH Cashier, HR HR Manager, NUR Nurse/Counsellor, P/S Parent or Student.

| Permission | S | CA | PR | VP | AC | CT | ST | ADM | ACC | CSH | HR | NUR | P/S |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| access.role.assign | | C+ | C | | | | | | | | | | |
| access.role.manage | S | | | | | | | | | | | | |
| org.settings.edit | S | | | | | | | | | | | | |
| org.campus.manage | S | | | | | | | | | | | | |
| org.policy.edit | | C | | | | | | | | | | | |
| org.policy.publish | S | | | | | | | | | | | | |
| students.profile.view | | C+ | C | C | C | A | A | C | C | C | | C | S |
| students.profile.edit | | C+ | C | C | | | | C | | | | | |
| students.admission.create | | C+ | C | | | | | C | | | | | |
| students.admission.approve | | C+ | C | | | | | | | | | | |
| students.lifecycle.transition | | C+ | C | | | | | | | | | | |
| students.ids.view | S | | C | | | | | C | | | | | |
| guardians.profile.edit | | C+ | C | | | | | C | | | | | |
| academics.calendar.manage | | C+ | C | C | C | | | | | | | | |
| academics.course.manage | | C+ | C | C | C | | | | | | | | |
| academics.timetable.manage | | | C+ | C+ | C | | | | | | | | |
| academics.timetable.publish | | | C+ | C | | | | | | | | | |
| academics.substitution.assign | | | C+ | C+ | C | | | | | | | | |
| attendance.student.mark | | | | | | A | A | | | | | | |
| attendance.student.view | | C+ | C+ | C+ | C | A | A | | | | | | S |
| attendance.student.edit_past | | | C+ | C | C | | | | | | | | |
| exams.datesheet.manage | | | C+ | C+ | C | | | | | | | | |
| exams.marks.enter | | | | | | A | A | | | | | | |
| exams.marks.edit_after_lock | S | | C | | | | | | | | | | |
| exams.results.publish | | | C+ | C | C | | | | | | | | |
| exams.results.view | | C+ | C+ | C+ | C | A | A | | | | | | S |
| exams.promotion.decide | | | C | | C | | | | | | | | |
| fees.structure.edit | S | C | | | | | | | | | | | |
| fees.voucher.generate | | C+ | | | | | | | C | | | | |
| fees.voucher.view | | C+ | C | | | | | | C | C | | | S |
| fees.discount.create | | | C | | | | | | C | | | | |
| fees.discount.approve | | C+ | C | | | | | | | | | | |
| fees.payment.receive | | | | | | | | | C | C | | | |
| fees.payment.reverse | S | | | | | | | | C | | | | |
| fees.latefee.waive | | C+ | C | | | | | | | | | | |
| fees.refund.request | | | | | | | | | C | | | | |
| fees.refund.approve | S | C | C | | | | | | | | | | |
| fees.report.view | | C+ | C | | | | | | C | | | | |
| ledger.entry.view | | C+ | C | | | | | | C | | | | |
| ledger.adjustment.post | S | | | | | | | | C | | | | |
| ledger.period.close | S | | | | | | | | C | | | | |
| hr.employee.view | | C+ | C | | | | | | | | C | | |
| hr.employee.edit | | C+ | | | | | | | | | C | | |
| hr.contract.manage | | C+ | | | | | | | | | C | | |
| hr.leave.approve | | C+ | C | C | | | | | | | C | | |
| hr.attendance.mark | | | | C | | | | | | | C | | |
| hr.salary.view | S | | | | | | | | | | C | | |
| hr.payroll.run | S | | | | | | | | | | C | | |
| hr.payroll.approve | S | C | | | | | | | | | | | |
| pastoral.medical.view | S | | C | | | | | | | | | C | |
| pastoral.medical.edit | S | | | | | | | | | | | C | |
| pastoral.counselling.view | S | | | | | | | | | | | C | |
| pastoral.safeguarding.view | S | | C | | | | | | | | | C | |
| pastoral.safeguarding.report | | all staff roles at their scope | | | | | | | | | | | |
| comms.announcement.send | | C+ | C+ | C | C | A | | | | | | | |
| comms.sms.send_bulk | | C+ | C | | | | | | | | | | |
| reports.view.academic | | C+ | C+ | C | C | | | | | | | | |
| reports.data.export | S | C | C | | | | | | | | | | |
| audit.log.view | S | C | | | | | | | | | | | |
| portal.child.view / portal.fees.pay / portal.self.view | | | | | | | | | | | | | S |

Notes: this is a starting point to be reviewed by the school owner. Because roles are data, changing a cell is a seeder or UI change, not a migration. Principal passes on only academic, attendance, exam, communication and report rights (never fees, HR, pastoral).

### D-22 Separation of duties (SoD) is checked on the RECORD, not the session
- Table `sod_rules` (organization_id nullable for system rules, permission_a, permission_b, record_type, is_active). Rule: one user may not exercise both permissions on the same record.
- Starting rules: fees.discount.create vs approve; fees.refund.request vs approve; fees.payment.receive vs reverse (same payment); hr.payroll.run vs approve (same run); exams.marks.enter vs edit_after_lock (same sheet); students.admission.create vs approve.
- Enforced in the Access resolver plus the approval workflow (maker is stored on the record: `created_by`, checker `approved_by`; `CHECK (approved_by IS DISTINCT FROM created_by)` on the approval tables).
- Ledger adjustments need approval by a second person through the workflow engine (maker-checker).

---

## B. Children's data: privacy and encryption

### D-23 What is encrypted (Laravel `encrypted` cast, ciphertext in a `text` column)
| Data | Column | Plus blind index |
|---|---|---|
| Guardian CNIC | guardians.national_id_encrypted (exists) | national_id_hash |
| Employee CNIC | employees.national_id_encrypted (exists) | national_id_hash |
| Student B-Form | students.b_form_encrypted (new) | b_form_hash (unique per org) |
| Student passport | students.passport_encrypted (new) | passport_hash (unique per org) |
| Medical details: allergies, conditions, medications, doctor notes | new table student_medical_profiles | none |
| Counselling notes | counselling_sessions.notes (Phase 2+) | none |
| Safeguarding incident details | safeguarding_incidents.details (Phase 2+) | none |
| Court order / custody details | student_custody_orders.details | none |

### D-24 What is NOT encrypted (access control and audit protect it)
Names, phone, email, home address, date of birth, roll numbers, fee data. Reason: they are needed for SMS, login, search, transport routing and reports. Encrypting them would break features without real protection.

### D-25 Plain alert flags next to encrypted details
`students.has_medical_alert` (bool), `students.has_severe_allergy` (bool), and the existing `guardian_student.pickup_restricted` stay in plain text so the gate guard, canteen and class teacher see the warning without decrypting anything. The details stay encrypted and need `pastoral.medical.view` or `pastoral.safeguarding.view`.

### D-26 Blind index
- Hash = `HMAC-SHA256(BLIND_INDEX_KEY, organization_id || '|' || normalized_value)`, stored as `char(64)`.
- `BLIND_INDEX_KEY` is a SEPARATE secret from `APP_KEY`. Reason: rotating or leaking `APP_KEY` must not break lookups. Including `organization_id` stops the same CNIC from correlating across tenants.
- Normalize before hashing: strip dashes and spaces, uppercase. `12345-1234567-1` and `1234512345671` give the same hash.
- Guardian and employee CNIC hash: normal index, NOT unique (the same parent can legitimately appear under two family records; show a duplicate warning). Student B-Form and passport hash: unique per organization.
- Never decrypt to search.

### D-27 Keys, audit, backups
- Cipher: Laravel default (AES-256-CBC with HMAC) is accepted; AES-256-GCM is allowed by config. Rotate `APP_KEY` with `APP_PREVIOUS_KEYS`.
- `APP_KEY` and `BLIND_INDEX_KEY` live in a secrets store, backed up separately from database backups. Losing the key means losing the encrypted data forever.
- Every read of decrypted IDs, medical, counselling or safeguarding data writes an `audit_logs` row (`pii.viewed`, who, which subject, which field group).
- Database backups contain ciphertext only; keys are never stored in the same bucket.
- Later hardening (not now): per-organization data keys (envelope encryption) when a customer needs per-tenant key destruction.

---

## C. Payments, ledger and idempotency

### D-28 Tables (created in the Phase 2 fees PRD, as migrations, never ALTER on existing tables)
- `accounts` (chart of accounts per org): code, name, type (asset, liability, income, expense, equity). Unique (organization_id, code).
- `ledger_periods`: status open or closed. Posting into a closed period is blocked by a trigger.
- `journal_entries`: organization_id, campus_id, entry_date, period_id, source_type, source_id, kind (posting, reversal), reversal_of, created_by. **Unique (organization_id, source_type, source_id, kind)** so one payment can post only once and be reversed only once. Append-only trigger.
- `journal_lines`: entry_id, account_id, family_id (sub-ledger), debit_minor, credit_minor, both bigint >= 0, exactly one of them > 0. **Deferred constraint trigger: total debit = total credit per entry at commit.** Append-only trigger.
- `payments`: organization_id, family_id, campus_id, amount_minor > 0, method (cash, bank_challan, 1bill, raast, jazzcash, easypaisa), gateway, gateway_txn_id, `idempotency_key varchar(100) not null`, `request_hash char(64) not null`, received_by, received_at. **Unique (organization_id, idempotency_key)** and **unique (organization_id, gateway, gateway_txn_id) where gateway is not null**. Payments are immutable; a reversal is a separate row in `payment_reversals` plus a reversal journal entry. Payments are never updated or deleted.
- `payment_allocations`: payment_id, voucher_line_id, amount_minor. Sum of allocations cannot exceed the payment. Any remainder becomes family advance credit (a liability account).
- `gateway_events`: raw webhook payloads, unique (gateway, event_id). Signature is verified BEFORE storing; processing happens in a queued job.
- `number_sequences`: gap-free receipt numbers per campus and fiscal year, taken with a row lock (`UPDATE ... RETURNING`). Contention rule: take the number as the LAST step before commit so the lock is held for milliseconds, keep the transaction short, and online gateway payments get their receipt number inside the posting job, not while waiting on external calls. Bulk imports (batch files from 1Bill or Raast) process in small batches, each batch in its own short transaction. Advisory locks give no gain (they serialize the same way) and are not used.

### D-29 Idempotency rules
1. Insert first, do not check first. In one DB transaction: `INSERT payment ... ON CONFLICT (organization_id, idempotency_key) DO NOTHING`. If nothing was inserted, load the existing payment.
2. Compare `request_hash` (hash of family_id, amount, method). Same key but different hash = reject with an error. Never return an old receipt for a different request.
3. Key rules: gateway payments use `<gateway>:<gateway_event_or_txn_id>` (for example `raast:txn_98234710923`). Counter payments use a UUID created **when the payment form opens**, not when it is submitted, so a double click or retry reuses the same key.
4. Allocation, journal entry and receipt run in the SAME transaction as the payment insert. Failure rolls everything back.
5. Same pattern for every money-moving write (refunds, adjustments, voucher generation): each gets an idempotency key.
6. **Transactional outbox, no direct queue dispatch inside the money transaction.** Receipt PDF, SMS and email are written as rows in the existing `domain_events` table (migration 12) in the SAME transaction as payment, allocation and journal entry (`dedupe_key` such as `payment:<id>:receipt`). A worker reads unpublished rows with `FOR UPDATE SKIP LOCKED`, dispatches, and sets `published_at`. If Redis is down at commit time, nothing is lost. No new outbox table is created.
7. **Concurrency uses the database only.** No Redis or Cache locks. When two webhooks carry the same key, the second `INSERT` waits on the unique index until the first transaction commits or rolls back, then sees the committed row and its receipt. Set `SET LOCAL lock_timeout = '5s'` inside the transaction; on timeout return HTTP 409 so the gateway retries later.

### D-30 Allocation is a policy, not code
New policy `payment_allocation/default` in a NEW preset version **1.6** (presets are immutable per version, so v1.5 is not edited). Default value: oldest due first, partial payment of a voucher allowed, overpayment goes to family credit. Alternatives selectable per org or campus: current period first; by head priority list. The preset file for v1.6 is created in the fees PRD and imported by the existing PresetImporter.

---

## D. Phase 2 build order (one PRD per block, each with small tasks)

1. **P2-A Access module:** PermissionSeeder (D-19/21), role seeder, assignment and grant resolver with ceilings, `sod_rules`, tests. Also auth hardening: Argon2id or bcrypt cost >= 12, rate limits (login 5/min per IP plus per account), Form Request validation.
2. **P2-B Privacy:** migrations for D-23 columns and `student_medical_profiles`, encrypted casts, `BLIND_INDEX_KEY`, blind-index service, `pii.viewed` audit, tests.
3. **P2-C Fees and ledger:** D-28 migrations, payment service with D-29, preset v1.6, golden tests (payment retry creates no duplicate, mismatched request_hash rejected, debit = credit enforced, closed period blocks posting).
4. **P2-D Academic engine:** timetable, sessions, attendance, substitution (design in `docs/design/school-erp-academic-engine-v1.4.md`).
5. **Docs in parallel:** API contracts for the endpoints each block exposes, health check `/healthz`, CI pipeline (Pint, Larastan, Pest on every push), backup and restore plan, queue retry and dead-letter rules.

---

## E. Reviewed and not adopted (kept here so nobody re-proposes them)

### D-31 Rejected or deferred proposals
| Proposal | Decision | Why |
|---|---|---|
| Redis distributed lock plus check-then-insert for payments | Rejected | PostgreSQL's unique index already makes a concurrent duplicate wait for the first commit and then see the finished row. A Redis lock adds a failure point (Redis down means payments down) and a 5 second lock can expire while the transaction is still running. |
| Advisory lock for receipt numbers | Rejected | It serializes exactly like the row lock; there is no contention gain. The fix is a short transaction with the number taken last (D-28). |
| Separate `outbox_events` table | Rejected as duplicate | `domain_events` (migration 12) is already the outbox. The outbox idea itself is adopted (D-29 rule 6). |
| `v1:` prefix on every ciphertext | Deferred | Laravel's `APP_PREVIOUS_KEYS` already decrypts old data during rotation and a re-encrypt command can loop over rows. A prefix would label rows but would not tie to a real key unless we also build a key map. Revisit with per-organization keys. |

---

## F. Phase 2B implementation decisions (privacy)

### D-32 Plain `students.medical` is removed
Step 1 left a plain `students.medical` jsonb column. It contradicts D-23 (medical details are encrypted). Migration `2026_10_07_000001_add_privacy_columns.php` drops it. The migration refuses to run if any row still has data in it, so nothing is lost silently. Medical details live only in `student_medical_profiles`.

### D-33 Who may reveal what (every reveal writes `audit_logs` action `pii.viewed`)
| Field group | Permission needed | Audit meta `field_group` |
|---|---|---|
| Student B-Form, passport | students.ids.view | student_ids |
| Guardian CNIC | students.ids.view | guardian_national_id |
| Employee CNIC | hr.employee.view | employee_national_id |
| Medical profile (allergies, conditions, medications, doctor notes) | pastoral.medical.view | medical |
| Custody / court order details | pastoral.safeguarding.view | custody |
The audit row stores who, which subject (type and id) and the field group. It never stores the value. A denied attempt writes no `pii.viewed` row. Alert flags (D-25) are plain and need no permission.

### D-34 Medical profile writes and alert flags
- Saving a medical profile needs `pastoral.medical.edit` and writes an `audit_logs` row `pii.updated` (field group only, never values).
- In the same transaction: `students.has_medical_alert` = true when any of allergies, conditions or medications is non-empty, else false. `students.has_severe_allergy` is set explicitly by the caller (default false); it is a human judgment, not computed from text.

### D-35 Blind index key and normalization
- `config/privacy.php` reads `BLIND_INDEX_KEY`. Outside the testing environment the app must fail loudly at first use if the key is missing or shorter than 32 characters. phpunit.xml carries a fixed test-only key.
- Normalize: remove every character that is not a letter or digit, then uppercase. An empty result is treated as null (no hash stored).
- Generate a key with: `php -r "echo base64_encode(random_bytes(32));"`. Never commit it.

### D-36 Hidden attributes
Ciphertext and hash columns are listed in each model's `$hidden`, so they never appear in JSON or arrays. Plain text is returned only through the PII viewer (D-33).
