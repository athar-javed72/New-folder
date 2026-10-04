# School ERP: General School Preset, Delegated Access, Fees ERD, Golden Tests (v1.2)

> **Update v1.3:** late fee, grading, pass marks, leave, payroll, transfer, refund, promotion aur calendars ki defaults naye document 'Policy Defaults Update (v1.3)' mein badal gayi hain. Jahan v1.2 aur v1.3 takrayein wahan **v1.3 sahi hai** (section 4 ke related rows aur section 7 ke late fee, grade, promotion tests).

Is document mein 5 cheezen hain: (1) delegated access model, (2) permission catalog aur default role matrix, (3) General School preset (default values), (4) config aur fees ke tables (columns ke sath), (5) golden tests (exact numbers).

**Aik correction:** pehle maine spatie/laravel-permission recommend kiya tha. Aap ka delegation model (granter apni hadd se zyada na de sake, ceilings, scoped grants, cascade revoke) spatie akela nahi deta. Isliye **apna chhota Access module** banayenge (neeche tables). Spatie optional hai, zaroori nahi.

**Doosri correction:** pichle document mein maine sample grade scale (A 3.7, A- 3.5) ko "ulja hua" kaha tha. Woh ghalat nahi, sirf non-standard hai (monotonic hai). Preset mein wohi scale rakha hai taake sample ke golden tests match karein. School apna scale badal sakta hai.

---

## 1. Delegated Access Model

### 1.1 Hierarchy
```
Super Admin (platform)   : sab kuch, sab schools. Har access audited
  Org Admin (school)     : apne school ke sab campuses, campus-wise access deta hai
    Campus Admin         : sirf apna campus, Principal/VP/staff ko access deta hai
      Principal, VP      : apne campus ke andar, jitna unhe diya gaya
        HOD, Coordinator, Teacher ...  : jitna upar se diya gaya
```

### 1.2 Ceilings (har level ki hadd)
Koi bhi banda apni upar ki hadd se zyada nahi de sakta. Effective access neeche ke sab ka intersection hai:
```
Plan features (Super Admin set)
  ∩ Org enabled modules (Org Admin)
  ∩ Campus enabled modules (Org Admin, per campus)
  ∩ Delegation ceiling of the granter
  ∩ Role permissions + direct grants
  ∩ Scope match (org / campus / program / grade / section / own children / self)
  ∩ Time window (starts_at, ends_at)
  ∩ Policies (separation of duties, sensitive gates)
```

### 1.3 Delegation rules
1. **Grant only what you hold.** Jo permission aap ke paas nahi, woh aap de nahi sakte. Jis scope mein aap ko mili, usi ya usse chhote scope mein de sakte hain.
2. **Grant option (`with_grant`).** Permission milne ka matlab aage dene ka haq nahi. Woh alag flag hai. Default: Org Admin aur Campus Admin ke paas hai. Principal/VP ko default off, Campus Admin chahe to on kar de.
3. **Delegation ceiling per campus:** Org Admin tay karta hai ke Campus Admin kaunsi permissions aage de sakta hai (boundary). Default: sab campus operations, sensitive nahi.
4. **Sensitive permissions** (fee structure/rules edit, ledger edit, payroll, salary, medical, counselling, safeguarding, user/role admin) sirf Org Admin de sakta hai. Org Admin chahe to kisi campus ke liye "delegable_sensitive" on kare.
5. **Depth limit:** grant chain max 4 levels (Org Admin > Campus Admin > Principal > HOD).
6. **Cascade:** granter ki permission hatay to us se bani hui sab downstream grants automatically suspend hoti hain aur notification jati hai (setting: revoke ya "review ke liye flag").
7. **Time-bound grants:** "Acting Principal 2 hafte" jaise. Expiry par khud khatam.
8. **No self-grant, no escalation.** Apne aap ko permission nahi de sakte. Apna role apne se upar nahi bana sakte.
9. **Last Org Admin** delete/demote nahi ho sakta. Kam az kam 2 Org Admins ki recommendation.
10. **Separation of duties (SoD):** jis ne discount/refund/expense create kiya woh approve nahi kar sakta. Cashier payment receive kare, reverse na kare. Rules table mein config hain.
11. **Approval for sensitive grants:** maker-checker (Campus Admin request kare, Org Admin approve).
12. **Custom roles:** Org Admin org-wide role bana sakta hai. Campus Admin campus-local role bana sakta hai (sirf apni hadd ke andar, agar Org Admin ne allow kiya ho).
13. **Access review:** har term "kis ke paas kya hai" report, Org Admin/Campus Admin sign-off kare.
14. **Audit:** har grant, revoke, role change, login, impersonation log. Org Admin ko dikhta hai ke Super Admin/support ne kab school ka data dekha.
15. **Super Admin:** poora access (aap ka faisla). Har access audited aur Org Admin ko visible.

### 1.4 Tables
| Table | Columns |
|---|---|
| `permissions` | id, code (`module.resource.action`), module, name, is_sensitive, is_delegable, allowed_scopes (json), depends_on (json) |
| `roles` | id, organization_id (null = system template), campus_id (null = org wide), key, name, is_system, is_editable, created_by |
| `role_permissions` | role_id, permission_id, max_scope, with_grant |
| `role_assignments` | id, user_id, role_id, scope_type, scope_id, granted_by, parent_grant_id, with_grant, starts_at, ends_at, status, reason |
| `permission_grants` | id, user_id, permission_id, scope_type, scope_id, granted_by, parent_grant_id, with_grant, starts_at, ends_at, status, reason |
| `delegation_boundaries` | id, organization_id, campus_id, granter_role_id, permission_id, max_scope, can_regrant |
| `module_enablement` | organization_id, campus_id (nullable), module, enabled, source (plan/org/campus) |
| `sod_rules` | id, organization_id, permission_a, permission_b, rule (`same_user_forbidden`) |
| `access_requests` | id, requester_id, target_user_id, requested (json), status, decided_by, decided_at, reason |
| `access_reviews` | id, organization_id, campus_id, period, reviewer_id, status, findings (json) |
| `user_campuses` | user_id, campus_id (users kis campuses mein hain) |

`AccessResolver::can($user, 'fees.voucher.create', $context)` sab jagah yahi. Policies, API, UI, AI tools sab isi ko call karte hain. Result per-user cache, kisi grant/role/module change par invalidate.

### 1.5 Example flow
1. Super Admin school "Alpha" banata hai, plan (modules) set, Org Admin user banata hai.
2. Org Admin 2 campuses banata hai, har campus ke modules aur ceiling set karta hai. Campus Admin B ko role "Campus Admin" `scope=campus B`, `with_grant=true`.
3. Campus Admin B Principal ko role "Principal" deta hai (academic full, finance view). Principal ko `with_grant` nahi.
4. Campus Admin B VP ko "Vice Principal" deta hai, sirf discipline aur timetable extra.
5. Principal chahta hai HOD ko `attendance.approve` de. Principal ke paas grant option nahi, to "Request Access" jata hai Campus Admin ko. Campus Admin approve kare ya Principal ko `with_grant` (sirf academic permissions par) de de.
6. Campus Admin B ne Principal se `results.publish` hata di, to us se bani hui koi downstream grant bhi suspend.

### 1.6 Access Manager (UI)
- **Users:** list, role, scope, expiry, last login.
- **Grant wizard:** user chuno > role ya custom permissions (sirf jo aap de sakte ho dikhte hain, baqi grey with reason "aap ke paas nahi / ceiling se bahar / sensitive") > scope > dates > with_grant > submit (sensitive ho to approval).
- **Effective Access viewer:** "Yeh banda X kyun kar sakta/nahi kar sakta" step-wise explanation (kis layer ne roka). Support ke liye bohat kaam ka.
- **Delegation Boundaries:** Org Admin campus-wise ceiling edit kare.
- **Access Review** aur **Audit** tabs.

---

## 2. Permission Catalog

Format `module.resource.action`. **(S)** = sensitive. Scope column: O=org, C=campus, G=grade/section, K=own children, S=self.

| Module | Permissions |
|---|---|
| core | `core.org.manage`(S), `core.campus.manage`(S), `core.settings.view/edit`, `core.calendar.manage`, `core.modules.toggle`(S) |
| access | `access.users.view/create/edit/suspend`(S), `access.roles.manage`(S), `access.grants.give`, `access.grants.approve`(S), `access.review.sign`, `access.audit.view` |
| rules | `rules.fees.view/edit`(S), `rules.discounts.edit`(S), `rules.attendance.edit`, `rules.exams.edit`, `rules.timetable.edit`, `rules.workflows.edit`(S), `rules.templates.edit`, `rules.publish`(S) |
| students | `students.profile.view/create/edit/delete`, `students.documents.view/upload`, `students.family.manage`, `students.transfer.request/approve`, `students.import`, `students.export` |
| admissions | `admissions.inquiry.manage`, `admissions.application.review`, `admissions.test.manage`, `admissions.offer.issue`, `admissions.enroll` |
| academics | `academics.grades.manage`, `academics.subjects.manage`, `academics.curriculum.edit`, `academics.promotion.propose/approve` |
| timetable | `timetable.view`, `timetable.edit`, `timetable.generate`, `timetable.substitute` |
| attendance | `attendance.student.view/mark/edit_locked`, `attendance.student.approve_edit`, `attendance.staff.view/mark`, `attendance.leave.request/approve` |
| homework | `homework.create/grade`, `lms.content.manage`, `lms.recording.upload` |
| exams | `exams.schedule.manage`, `exams.marks.enter/moderate/lock/unlock`, `exams.results.calculate`, `exams.results.publish`, `exams.reportcard.generate/view` |
| fees | `fees.structure.view`, `fees.structure.edit`(S), `fees.charge.add`, `fees.voucher.generate/view/print/cancel`, `fees.payment.receive`, `fees.payment.reverse`(S), `fees.discount.request/verify/approve/attach`, `fees.refund.request/approve`(S), `fees.waiver.approve`(S), `fees.cashier.session` |
| ledger | `ledger.view`, `ledger.journal.create`(S), `ledger.reversal.post`(S), `ledger.period.close`(S), `ledger.reports.view` |
| hr | `hr.staff.view/manage`, `hr.contract.manage`(S), `hr.leave.approve`, `hr.payroll.view/run/approve`(S), `hr.recruitment.manage`, `hr.performance.manage` |
| pastoral | `pastoral.medical.view/edit`(S), `pastoral.counselling.view/edit`(S), `pastoral.discipline.view/create/action`, `pastoral.safeguarding.manage`(S) |
| activities | `houses.manage`, `sports.manage`, `clubs.manage`, `events.manage` |
| operations | `library.manage`, `transport.manage`, `hostel.manage`, `inventory.manage`, `procurement.request/approve`, `visitors.manage`, `facilities.manage` |
| comms | `comms.announce`, `comms.message`, `comms.emergency`(S), `comms.templates.edit` |
| reports | `reports.build`, `reports.export`, `reports.schedule`, `reports.finance.view`(S) |
| analytics | `analytics.dashboard.view`, `analytics.growth.manage`, `analytics.kpi.edit` |
| ai | `ai.assistant.use`, `ai.tools.data`, `ai.lecture.summarize`, `ai.settings.manage` |
| system | `system.backup.manage`(S), `system.integrations.manage`(S), `system.import.run`(S) |

**Dependencies:** `fees.voucher.generate` ke liye `fees.structure.view` zaroori. Grant karte waqt dependencies khud jud jati hain.

---

## 3. Default Role Matrix (preset)

Legend: `-` koi access nahi, `V` view, `W` create/edit, `A` approve/publish, `F` full (delete/export/manage). `*` = sensitive hissa Org Admin ke explicit grant se. Sab roles edit ho sakte hain.

| Role | Stu | Adm | Att | TT | Exm | Fee | Led | HR | Past | Com | Rpt | Rules | Users |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Org Admin | F | F | F | F | F | F | F | F | V | F | F | F | F |
| Campus Admin | F | F | F | F | F | W* | V | W* | V | F | F | W (campus) | W (ceiling ke andar) |
| Principal | W | A | V | W | A | V | - | V | V | F | F | - | - |
| Vice Principal | W | V | W | W | W | V | - | - | V | W | V | - | - |
| HOD | V | - | V | V | W | - | - | - | - | W | V (dept) | - | - |
| Coordinator | V | V | W | V | V | - | - | - | V | W | V | - | - |
| Class Teacher | W (limited) | - | W | V | W | - | - | - | - | W | V (own) | - | - |
| Subject Teacher | V | - | W (period) | V | W (marks) | - | - | - | - | W | V (own) | - | - |
| Exam Officer | V | - | V | V | F | - | - | - | - | W | F (exam) | - | - |
| Admissions Officer | V | F | - | - | - | W (admission voucher) | - | - | - | W | V | - | - |
| Accountant | V (basic) | V | - | - | - | F* | F* | - | - | W | F (fin) | - | - |
| Cashier | V (basic) | - | - | - | - | W (receive) | - | - | - | - | V (own session) | - | - |
| HR Manager | - | - | V (staff) | V | - | - | - | F* | - | W | F (hr) | - | - |
| Auditor | V | V | V | V | V | V | V | V | - | V | V | V | V |

**Specialist roles** (Librarian, Nurse, Counsellor, Transport Manager, Warden/House Master, Sports/Club Coordinator, Receptionist): apne module mein `F`, students ki basic profile `V`, baqi `-`. Nurse aur Counsellor ko medical/counselling sirf assigned scope mein.

**Parent:** scope K. Apne bachon ka attendance, homework, results, fees + payment, timetable, leave request, messages, transport, documents, AI assistant.
**Student:** scope S. Timetable, homework, LMS, results, library, AI study helper. Fees default off.

**Default delegation:**
| Kaun | Kise de sakta hai | Kya |
|---|---|---|
| Super Admin | Org Admin | Sab, plan ke andar |
| Org Admin | Campus Admin aur koi bhi | Sab, campus scope ke sath. Sensitive sirf yehi |
| Campus Admin | Principal, VP, HOD, staff (apne campus mein) | Sab jo ceiling mein hai, non-sensitive. Sensitive ke liye Org Admin request |
| Principal | Sirf jo `with_grant` mila (default kuch nahi) | Campus Admin ke diye hue subset |
| VP | Default kuch nahi | Same |

---

## 4. General School Preset (`PK_GENERAL_V1`)

Yeh **starting values** hain jo onboarding par school ke config mein copy hoti hain. Har value school badal sakta hai. Preset update aaye to school ko diff dikhta hai, khud overwrite nahi hota. Numbers placeholders hain jo school confirm karega.

| Area | Default |
|---|---|
| General | Currency PKR, date DD-MMM-YYYY, week start Monday, languages English + Urdu (RTL), timezone Asia/Karachi |
| Calendar | Aug to Jul, Term 1 (Aug to Dec), Term 2 (Jan to May), summer break Jun to Jul |
| Weekly off | Saturday, Sunday |
| Bell schedule | 08:00 start, 45 min periods, P1 08:00, P2 08:45, P3 09:30, break 10:15 to 10:45, P4 10:45, P5 11:30 to 12:15. Winter/Summer/Ramadan variants khali (school bharega) |
| Attendance mode | Daily (class teacher) Playgroup to Grade 8, period-wise Grade 9+ |
| Attendance codes | P Present, A Absent, LT Late, HD Half Day (0.5), LV Leave (excused) |
| Attendance rules | Default present, window 08:00 to 08:15, late after 08:15, pending alert 08:30, parent SMS 09:00 after verify, lock 2 din, warn 75%, escalate 65%, exam eligibility 75% (warning only) |
| Punctuality | `100 * (1 - LT / (P + LT))` |
| Fee heads | Tuition, Annual, Admission, Security (liability, refundable), Transport, Lab, Exam, Fine, Arrears |
| Fee cycle | Monthly, issue day 1, due day 10, validity day 20 |
| Due date on holiday | Next working day |
| Voucher layout | Family-wise, 2 copies (School, Parent), 2 vouchers per page, Bank copy off |
| Voucher number | `{CAMPUS}-{YYMM}-{SEQ:5}`, sequence yearly reset |
| Late fee | Flat Rs 1,000 per voucher, grace 0, non-recurring, cap 1,000, ledger posting on late payment, waivable with approval |
| Discounts | Sibling: tuition only, rank 2 = 20%, rank 3 = 30%, rank by admission date. Staff: 50% tuition. Merit: 50% for 1 year. Early lump sum: 5% annual fee |
| Stacking | `highest_single` |
| Discount accounting | Contra-revenue "Fee Discounts" |
| Discount workflow | Campus Admin verify > Principal approve > Accountant attach. Expiry par auto full fee |
| Payment allocation | Oldest arrears first, partial allowed, min 0 |
| Channels | Cash, bank, cheque on. 1Bill, Raast, JazzCash, Easypaisa off jab tak keys na dalein |
| Refund | Security deposit refundable, exit ke 30 din ke andar, Campus Admin > Principal approval |
| Proration (mid-month admission) | Daily calendar days |
| Rounding | Nearest Rs 1 |
| Result hold on fee | Off |
| Assessment scheme | Formative 20 (average scaled), Mid-Term 30, Final 50 |
| Pass rule | Overall 40%, each compulsory subject 33% |
| Rounding (marks) | Half up, 0 decimals for grade lookup, 2 decimals display for overall % |
| Grade scale | A* 90 to 100 (4.0), A 85 to 89 (3.7), A- 80 to 84 (3.5), B+ 75 to 79 (3.3), B 70 to 74 (3.0), C 60 to 69 (2.0), D 50 to 59 (1.0), E 40 to 49 (0.5), F below 40 (0.0) |
| Position | Competition ranking (tie par same position, agla skip), section-wise |
| Conduct scale | Excellent, Good, Needs Improvement |
| Report card | Profile, Attendance, Academics, Co-curricular and Conduct, Remarks, Signatures, QR verify |
| Result workflow | Teacher marks > Coordinator review > Principal approve > Exam Officer publish |
| Datesheet rules | Weekend/holiday par exam nahi, per grade per day max 1 major exam, room capacity check, din tarikh se auto |
| Promotion | Overall 40%, each compulsory 33%, attendance 75%, mode `propose`, approval Principal, on fail retain |
| Leave | Student: parent apply > Class Teacher > Coordinator. Staff: Casual 10, Medical 10, balance carry-forward off |
| Notifications | Absent (09:00), voucher issued, due reminder (3 din pehle), overdue (due + 3), result published, announcement |
| Roles | Section 3 ke default roles |

---

## 5. Config Engine Schema

| Table | Columns |
|---|---|
| `presets` | id, key, name, version, description |
| `preset_items` | id, preset_id, type, key, value (jsonb) |
| `config_settings` | id, organization_id, scope_type, scope_id, key, value (jsonb), effective_from, effective_to, version, status, source_preset_version, created_by, approved_by |
| `policies` | id, organization_id, scope_type, scope_id, type (`late_fee`, `discount_stacking`, `promotion`, `attendance`, ...), key, value (jsonb), priority, effective_from, effective_to, version, status |
| `lookups` | id, organization_id, type (`attendance_code`, `grade_band`, `leave_type`, `incident_type`), code, name (jsonb), attrs (jsonb), sort, is_active |
| `formulas` | id, organization_id, key, expression, variables (jsonb), version |
| `workflows` | id, organization_id, key, name, applies_to, version, status |
| `workflow_steps` | id, workflow_id, step_no, role_id, action, condition (jsonb), sla_hours |
| `workflow_instances` | id, workflow_id, subject_type, subject_id, current_step, status, requested_by, started_at |
| `workflow_actions` | id, instance_id, step_no, actor_id, action, note, acted_at |
| `templates` | id, organization_id, type (`voucher`,`receipt`,`report_card`,`datesheet`,`sms`,`whatsapp`), key, locale, body, variables (jsonb), version |
| `custom_fields` | id, organization_id, entity, key, label (jsonb), type, validation (jsonb), visible_roles (jsonb), is_required |
| `bell_schedules` | id, organization_id, campus_id, name, valid_from, valid_to, days (jsonb), periods (jsonb) |
| `holidays` | id, organization_id, campus_id, date, name, type |
| `feature_flags` | id, organization_id, campus_id, key, enabled |
| `config_audit` | id, config_type, config_id, action, old_value, new_value, changed_by, reason, changed_at |

`custom_values` alag table nahi. Entity table par `custom jsonb` column (GIN index). Indexes: `(organization_id, type, key, effective_from)` policies aur settings par.

**Resolver:** `ConfigResolver::get(key, ctx)`, ctx = org, campus, grade, section, date. Sab se specific + effective + published rule uthata hai, cache karta hai.

---

## 6. Fees ERD (columns)

Sab tables par common: `organization_id`, `campus_id` (jahan lagu), `created_at`, `updated_at`, `created_by`, `deleted_at` (sirf non-financial). Money hamesha `*_minor` bigint (paisa), `currency` char(3).

### 6.1 Setup
| Table | Columns |
|---|---|
| `accounts` | id, code, name, type (asset/liability/income/expense/contra_income), parent_id, campus_id (null = org wide), is_system |
| `fee_heads` | id, key, name (jsonb), accounting_type (income/liability/pass_through), income_account_id, liability_account_id, is_discountable, is_refundable, is_taxable, frequency, sort, is_active |
| `fee_structures` | id, program_id, grade_id (null = sab), calendar_id, name, effective_from, effective_to, status, version |
| `fee_structure_items` | id, structure_id, fee_head_id, amount_minor, term_id (null), frequency_override, per (`student`/`family`), condition (jsonb) |
| `student_fee_assignments` | id, enrollment_id, structure_id, overrides (jsonb), valid_from, valid_to |
| `recurring_charges` | id, enrollment_id, fee_head_id, amount_minor, valid_from, valid_to, source (`transport_route`, `hostel`, `manual`), source_id |
| `charges` | id, enrollment_id, family_id, fee_head_id, description, amount_minor, status (pending/billed/cancelled), voucher_line_id |
| `discount_definitions` | id, key, name (jsonb), type (percent/flat/tier), applies_to_heads (jsonb), priority, conditions (jsonb), workflow_id, accounting_treatment (`contra_revenue`/`expense`), is_active |
| `discount_tiers` | id, discount_id, rank, percent_bp (basis points), amount_minor |
| `discount_applications` | id, student_id, discount_id, requested_by, workflow_instance_id, status, documents (jsonb) |
| `student_discounts` | id, student_id, discount_id, value_override, valid_from, valid_to, status, approved_by, application_id |

Late fee, stacking, allocation, proration, rounding `policies` table mein (type-wise). Alag tables nahi.

### 6.2 Transactions
| Table | Columns |
|---|---|
| `voucher_runs` | id, billing_period, scope (jsonb), status, total, generated, failed, started_at, finished_at, started_by |
| `vouchers` | id, run_id, family_id, voucher_no, billing_period, issue_date, due_date, validity_date, status (`draft`,`issued`,`partial`,`paid`,`overdue`,`cancelled`), currency, arrears_minor, gross_minor, discount_minor, net_minor, late_fee_minor, payable_after_due_minor, paid_minor, balance_minor, config_snapshot (jsonb), template_id, pdf_path, superseded_by |
| `voucher_lines` | id, voucher_id, student_id, enrollment_id, fee_head_id, description, quantity, unit_minor, gross_minor, discount_minor, net_minor, discount_breakdown (jsonb), source_type, source_id |
| `voucher_payment_refs` | id, voucher_id, channel (`1bill`,`raast`,`bank`), reference, qr_payload |
| `cashier_sessions` | id, cashier_id, opened_at, closed_at, opening_minor, expected_minor, counted_minor, difference_minor |
| `payments` | id, family_id, method, channel, amount_minor, received_at, reference, idempotency_key, status (`posted`,`reversed`), cashier_session_id, reversed_by, reversal_reason |
| `payment_allocations` | id, payment_id, voucher_id, voucher_line_id, amount_minor |
| `receipts` | id, payment_id, receipt_no, pdf_path |
| `credit_balances` | id, family_id, amount_minor, source_type, source_id, campus_id |
| `refunds` | id, family_id, fee_head_id, amount_minor, workflow_instance_id, status, payment_id |
| `gateway_events` | id, provider, event_id, payload (jsonb), status, processed_at |
| `number_sequences` | id, campus_id, type, period, prefix, next_value |
| `journal_entries` | id, entry_date, source_type, source_id, status (`posted`), reversal_of, prev_hash, hash, created_by |
| `journal_lines` | id, entry_id, account_id, debit_minor, credit_minor, family_id, student_id, campus_id |
| `inter_campus_adjustments` | id, from_campus_id, to_campus_id, student_id, amount_minor, journal_entry_id, effective_date |

### 6.3 Constraints (DB level)
- `vouchers`: unique `(organization_id, voucher_no)`.
- `payments`: unique `(organization_id, idempotency_key)`. `gateway_events`: unique `(provider, event_id)`.
- `journal_lines`: check `debit_minor >= 0 and credit_minor >= 0` aur exactly one non-zero.
- Deferred trigger: har `journal_entries` ka `sum(debit) = sum(credit)`, warna commit fail.
- `journal_entries`/`journal_lines`: UPDATE aur DELETE trigger se block. Sirf reversal.
- `payment_allocations`: sum allocations `<= payment.amount_minor`.
- `enrollments`: partial unique `(student_id) where status='active'`.
- Voucher `config_snapshot` issue ke baad immutable.
- Sab foreign keys `restrict`, financial tables par soft delete nahi.

### 6.4 Voucher generation (queue)
```
VoucherRun start > enrollments chunk karo (500 per job)
> har family: charges (structure + recurring + one-time) + arrears
> ConfigResolver se discount rules, stacking, late fee policy
> lines + totals compute (integer paisa)
> voucher_no sequence (lock) > config_snapshot save
> journal entry post (accrual) > PDF job > notification job
```
Accrual entry issue par: Dr Student Receivable (net), Dr Fee Discounts, Cr Income accounts (head ke hisaab se), Cr Liability (security).

---

## 7. Golden Tests (Pest)

Yeh tests **exact numbers** dete hain. Agar code inhe fail kare to release block. Sab amounts Rs mein (code mein paisa).

### 7.1 Voucher
| ID | Setup | Expected |
|---|---|---|
| V1 | Arrears 0, Tuition 25,000, Transport 8,000, Lab 2,000. Sibling rank 2 (20% tuition). Late fee flat 1,000 | Gross 35,000. Discount 5,000. Net by due date 30,000. After due date 31,000 |
| V2 | Family voucher: A (rank 1) tuition 25,000 + transport 8,000. B (rank 2) tuition 25,000 + lab 2,000. C (rank 3) tuition 20,000. Late fee per voucher | A gross 33,000 net 33,000. B gross 27,000 discount 5,000 net 22,000. C gross 20,000 discount 6,000 net 14,000. Family gross 80,000, discount 11,000, net 69,000, after due 70,000 |
| V3 | Tuition 25,000. Sibling 20% aur staff 50% dono eligible, mode `highest_single` | Discount 12,500 (staff) |
| V4 | Same, mode `cumulative_additive_capped` (cap 100%) | Discount 17,500 |
| V5 | Same, mode `sequential` (staff phir sibling) | Discount 15,000 (12,500 + 20% of 12,500) |
| V6 | Merit 50% valid till 31-Jul-2027. Aug 2027 voucher | Full tuition, discount 0 |
| V7 | Due date 10-Oct-2026 (Saturday), Saturday weekly off | Due date 12-Oct-2026 (Monday) |
| V8 | Admission 16-Oct-2026, tuition 25,000, daily proration | 25,000 x 16 / 31 = 12,903 |
| V9 | Voucher issue ke baad late fee policy 1,000 se 1,500 (effective 1-Nov) badli. Oct voucher dobara dekho | Oct voucher par 1,000 hi rahe (snapshot) |
| V10 | Security deposit 10,000 (liability) | Income mein nahi, liability account mein |

### 7.2 Payments aur Ledger
| ID | Setup | Expected |
|---|---|---|
| P1 | Arrears 10,000, current 30,000. Payment 15,000, oldest first | Arrears 0, current balance 25,000 |
| P2 | Same gateway `event_id` do baar | 1 payment, 1 journal entry |
| P3 | V2 voucher issue | Dr Receivable 69,000 + Dr Fee Discounts 11,000 = Cr Tuition 70,000 + Transport 8,000 + Lab 2,000 (80,000 = 80,000) |
| P4 | V2 family after due date pay 70,000, late fee `on_late_payment` | Dr Cash 70,000. Cr Receivable 69,000. Cr Late Fee Income 1,000 |
| P5 | Journal entry UPDATE/DELETE try | DB error |
| P6 | Unbalanced journal entry | Commit fail |
| P7 | Payment reverse | Reversal entry, original untouched |
| P8 | Security deposit refund 10,000 | Dr Liability 10,000, Cr Cash 10,000 |
| P9 | Same user discount create + approve | Blocked (SoD) |

### 7.3 Report Card
| ID | Setup | Expected |
|---|---|---|
| R1 | Quizzes 8/10, 9/10, 10/10, formative 20 (average scaled) | 18 |
| R2 | English 18 + 25 + 42 | 85, A, 3.7 |
| R3 | Maths 15 + 22 + 35 | 72, B, 3.0 |
| R4 | Science 19 + 28 + 45 | 92, A*, 4.0 |
| R5 | Urdu 16 + 24 + 40 | 80, A-, 3.5 |
| R6 | R2 se R5 ka overall | 329/400 = 82.25%, GPA (3.7+3.0+4.0+3.5)/4 = 3.55, grade A- |
| R7 | Total 84.5 | Round 85 > A |
| R8 | Total 79.5 | Round 80 > A- |
| R9 | Do students 85.00 aur teesra 84.00 | Position 1, 1, 3 (competition ranking) |

### 7.4 Attendance, Datesheet, Promotion
| ID | Setup | Expected |
|---|---|---|
| A1 | 20 din: P 15, LT 2, A 2, HD 1 | Attendance 87.5%, punctuality 88.2% |
| A2 | Attendance 3 din baad edit | Approval chahiye (lock 2 din) |
| A3 | Absent alert | 09:00 par, verify ke baad, pehle nahi |
| A4 | Codes `L` do baar define | Validation error (unique code) |
| D1 | Exam 15-Nov-2026 | Sunday, reject (weekly off) |
| D2 | 16-Nov Monday | Accept, din auto = Monday |
| D3 | Grade 8 ke 2 major exams same din | Reject |
| D4 | Room capacity se zyada students | Warning/reject |
| PR1 | Overall 82.25, sab subjects >= 33, attendance 87.5 | Promote propose |
| PR2 | Overall 55, Maths 30 | Retain propose |
| PR3 | Overall 60, attendance 70 | Blocked, Principal decision |

### 7.5 Access aur Tenancy
| ID | Setup | Expected |
|---|---|---|
| X1 | Campus Admin ek permission de raha hai jo uske paas nahi | Reject |
| X2 | Campus Admin ceiling se bahar ki permission de | Reject |
| X3 | Sensitive permission (payroll) Campus Admin ne Principal ko di, Org Admin ne allow nahi kiya | Reject ya approval request |
| X4 | Principal (grant option nahi) HOD ko permission de | Reject |
| X5 | Campus Admin ki permission hata do | Principal ki downstream grant suspend |
| X6 | Time-bound grant expiry | Expiry ke baad access nahi |
| X7 | Org Admin apne aap ko Super Admin banaye | Reject |
| X8 | Last Org Admin remove | Reject |
| X9 | Campus B ka admin Campus A ke students dekhe | Empty/403 |
| X10 | Org 1 ka user Org 2 ka data | Empty/403 (RLS bhi) |
| X11 | Parent ka doosre bachay ka data | 403 |
| X12 | Campus mein module off | API 403, menu hidden |
| X13 | Super Admin school data dekhe | Allowed, audit entry Org Admin ko visible |
| X14 | Effective Access viewer | Sahi layer ka sabab bataye |

### 7.6 Config
| ID | Setup | Expected |
|---|---|---|
| C1 | Campus override late fee 1,500, org 1,000 | Campus par 1,500, baqi 1,000 |
| C2 | Grade 10 par late fee rule off | Grade 10 par 0 |
| C3 | Rule draft | Voucher par asar nahi jab tak publish na ho |
| C4 | Effective date future | Purani date ke voucher par purana rule |
| C5 | Rule rollback | Purana version wapis |
| C6 | Preset update aaye | School ko diff dikhe, overwrite nahi |
| C7 | Invalid JSON config | Publish reject with error |

---

## 8. Build Order (Phase 0)

1. Docker, Laravel 12, PostgreSQL 16, Redis, CI.
2. `organizations`, `campuses`, `users`, `user_campuses`, tenancy (scope + RLS) aur X9 X10 tests.
3. **Access module** (section 1.4), `AccessResolver`, seeders (permissions catalog, system roles), X1 se X14 tests.
4. **Config engine** (section 5), `ConfigResolver`, preset loader (`PK_GENERAL_V1`), C1 se C7 tests.
5. Audit log, workflow engine (chains as data).
6. Rules Studio aur Access Manager ki basic screens.
7. Phir students, families, enrollments, calendars (Phase 1).
8. Phir fees (section 6), V, P tests pehle likh kar.

**Asli schools se confirm karne ke sawal (10 minute):**
1. Fee cycle: monthly/term? Issue, due, validity days?
2. Late fee: flat/per din/percent? Kitna?
3. Discounts: kaunse aur kitne? Stack hote hain ya nahi? Sibling rank kaise?
4. Security deposit aur admission fee: kab, refundable?
5. Grade scale, pass marks, weightage.
6. Saturday working? Ramadan timings? Winter/summer bell.
7. Promotion criteria.
8. Kaun result publish karta hai, kaun discount approve karta hai?
9. Attendance: daily ya period-wise? SMS kab?
10. Voucher/report card ka asli sample.

Jawab milne par sirf preset ki values badlengi, code nahi.
