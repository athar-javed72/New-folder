# School ERP: Complete Blueprint (A to Z)

Version 1.1 (v1.4 update: sections 5.5 Timetable, 5.6 Attendance, 5.9 Exams aur section 8 ki tables ab 'Academic Engine (v1.4)' document se replace hain, jo event/session-based design hai). Yeh document ChatGPT blueprint + Gemini engineering decisions + missing cheezen + AI layer sab ko merge karke final banata hai. **Har school ka rule alag hota hai, isliye rules/fees/grading/workflows hardcode nahi hote. Unka poora design alag document 'Configuration and Rules Engine (v1.1)' mein hai. Neeche jo cheezen us se takrati thin woh theek kar di gayi hain.**

---

## 1. Product Vision aur Scope

**Kya bana rahe ho:** Multi-school SaaS ERP jo premium schools (Beaconhouse/LGS/Aitchison level) ko chalata hai. Aik platform par: students, academics, attendance, exams, family-wise fees, accounting, HR, portals, activities, analytics, aur AI assistant.

**Speed ka matlab (jo demo video ne claim kiya):**
- Attendance 30 second mein (default sab present, sirf absent tick).
- Fee voucher bulk generation 1000 families ka 1 minute se kam (queue).
- Har list page 300ms ke andar (server-side pagination, indexes).

**Differentiators (baqi systems se behtar kaise):**
1. Family-wise finance jo asli accounting (double-entry) par chalta hai, sirf fee list nahi.
2. Permission-aware AI jo har role ko uske data ke andar guide kare.
3. School Growth engine jo asli data se actions banaye.
4. Urdu/English dono, Urdu PDFs sahi.
5. Old system se data import tool (school switch asaan).

**Golden rule:** 60 modules aik saath nahi. Phase 1 (core + fees) bech sakte ho, baqi uske upar.

---

## 2. Final Architecture Decisions

| # | Decision | Final choice | Wajah |
|---|---|---|---|
| 1 | Tenancy | Aik shared database, har table par `organization_id` (school) aur zarurat par `campus_id` | Reporting, transfers, Parent SSO aasan. Har school ka alag DB operations ka bojh hai |
| 2 | Hierarchy | Organization > Campus > School Section > Program/Curriculum > Grade > Section | Multi-school SaaS + multi-campus dono |
| 3 | Database | **PostgreSQL 16** | Neeche detail |
| 4 | Isolation | Application scope + Policies + **Postgres Row Level Security** (defense in depth) | Aik jagah scope bhoola to bhi leak nahi |
| 5 | Parent login | Aik global `users` account, `guardians` se linked, sirf apne linked bachon ka data | Parent SSO |
| 6 | Academic years | Har campus/program ka apna `academic_calendar`, overlapping allowed | Matric April, O-Level August |
| 7 | Transfer accounting | Transfer effective date par revenue split, advance ko credit balance banao, inter-campus ledger entry | Gemini decision sahi tha |
| 8 | Money | Integer minor units (paisa), currency column, kabhi float nahi | Rounding bugs khatam |
| 9 | Ledger | Append-only. Edit/delete nahi, sirf reversal entry | Audit aur trust |
| 10 | Deletes | Soft deletes + `restrictOnDelete` academic/finance par, cascade nahi | Data loss se bachao |
| 11 | App style | Modular monolith | Aik dev/chhoti team ke liye sahi |
| 12 | Background work | Sab bhaari kaam queue mein | Speed |
| 13 | AI | Alag AI module, permission-aware retrieval, sab actions audited | Neeche section 9 |

**MySQL se PostgreSQL kyun (meri pehli recommendation badal raha hun):** is design mein teen cheezen chahiye jo Postgres asaani se deta hai: (1) asli Row Level Security tenant isolation ke liye, (2) partial unique index jaise "aik student ka sirf aik active enrollment", aur exclusion constraints, (3) `pgvector` AI search ke liye, alag vector DB ki zarurat nahi. Laravel Postgres ko poori tarah support karta hai.

---

## 3. Tech Stack (Layer, Choice)

| Layer | Choice | Language |
|---|---|---|
| Backend | Laravel 12, PHP 8.3+ | PHP |
| API | REST JSON, versioned `/api/v1`, Laravel API Resources, OpenAPI docs (Scribe) | PHP |
| Frontend | React 19 + Vite + Inertia nahi, pure SPA | TypeScript |
| UI | Tailwind CSS + shadcn/ui, RTL support | TypeScript/CSS |
| Data tables | TanStack Table + TanStack Query | TypeScript |
| Forms | React Hook Form + Zod | TypeScript |
| Charts | Recharts | TypeScript |
| i18n | i18next (English, Urdu, RTL) | TypeScript |
| Database | PostgreSQL 16 (+ pgvector) | SQL |
| Cache/Queue/Sessions | Redis | none |
| Queue monitor | Laravel Horizon | PHP |
| Realtime | Laravel Reverb (WebSocket) | PHP |
| Auth | Laravel Sanctum (SPA cookie), 2FA (TOTP), device/session list | PHP |
| RBAC | spatie/laravel-permission with teams (team = campus) | PHP |
| Audit | spatie/laravel-activitylog + custom finance audit | PHP |
| PDF | Browsershot (headless Chrome), Urdu shaping sahi | PHP + HTML/CSS |
| Excel/CSV | Laravel Excel | PHP |
| Files | S3-compatible (Cloudflare R2 / MinIO), signed URLs, antivirus scan | none |
| Search | Postgres full-text + trigram, baad mein Meilisearch | none |
| AI gateway | Laravel service calling Claude API (LLM) | PHP |
| AI workers | Python 3.12 + FastAPI microservice sirf transcription (Whisper), OCR, embeddings | Python |
| Vector store | pgvector (same Postgres) | SQL |
| Notifications | Local SMS gateway, WhatsApp Cloud API, Firebase FCM, email (SES/Postmark) | PHP |
| Payments | 1Bill/1LINK, Raast, JazzCash, Easypaisa, bank challan file import, webhooks | PHP |
| Biometric/RFID | Device agent posts to signed API endpoint | PHP |
| Mobile | React PWA pehle, baad mein React Native (Expo) | TypeScript |
| Testing | Pest (backend), Vitest (React), Playwright (E2E) | PHP/TS |
| Monitoring | Sentry, Laravel Pulse, Grafana/Prometheus | none |
| Backups | spatie/laravel-backup + Postgres PITR, monthly restore drill | none |
| Deploy | Docker, Nginx, Ubuntu VPS/cloud, GitHub Actions CI/CD | YAML |

**Sirf 4 languages:** PHP (backend), TypeScript (frontend), SQL (DB), Python (sirf AI workers). Is se zyada mat karo.

**Repo structure (modular monolith):**
```
app/Modules/
  Core/ (organizations, campuses, users, rbac, audit, settings)
  People/ (students, families, guardians, staff)
  Admissions/
  Academics/ (calendars, grades, sections, subjects, curriculum, timetable)
  Attendance/
  Exams/
  Finance/ (fees, vouchers, payments, ledger, accounting)
  HR/ (payroll, leave, performance)
  Activities/ (houses, sports, clubs, events)
  Pastoral/ (medical, counselling, discipline)
  Operations/ (library, transport, hostel, inventory, procurement, facilities)
  Communication/
  Reports/
  Analytics/ (KPIs, School Growth)
  AI/
frontend/src/modules/... (same names)
```
Modules ek dosray ki tables direct query nahi karte, sirf Service ya Events ke through baat karte hain.

---

## 4. Tenancy aur Security Design

**Scopes:**
- `TenantScope` (organization_id) sab models par, hamesha on.
- `CampusScope` campus-level models par, user ke assigned campuses ki list se (`campus_user` pivot, aik user multiple campuses).
- Super Admin/Org Admin ka bypass **role check** se, controller mein `withoutGlobalScope` likhne se nahi.
- Parent: scope student/guardian link se, campus se nahi.

**Tenant context sab jagah:** HTTP, queue jobs, console commands, AI tools. Job dispatch par `organization_id` payload mein, job start par context set. Bina context ke query fail ho (silent all-data nahi).

**Postgres RLS:** har request par `SET app.current_org = ...`. Policy: `organization_id = current_setting('app.current_org')`.

**Mandatory tests:** har naye model ke liye "school A ka user school B ka data nahi dekh sakta" test. CI mein fail hone par merge block.

**Security list:**
- 2FA staff/admin par compulsory, login history, session revoke, IP log.
- Password policy, rate limiting, account lockout.
- Encryption at rest (DB/disk), sensitive columns (medical, counselling, CNIC) application-level encrypted.
- Counselling/safeguarding: alag permission, "break glass" access jo log hota hai aur counsellor ko notify karta hai.
- Signed URLs documents ke liye, file type/size validation, antivirus.
- Webhooks: signature verify, idempotency key, replay protection.
- Audit log: who, what, when, IP, old/new value, reason. Tamper-evident (hash chain finance par).
- Backups encrypted, restore test mahine mein aik baar.
- Privacy: bachon ka data minimal, consent records, data export/delete request workflow, retention policy.
- Secrets .env mein nahi balkay secret manager.

---

## 5. Domain Blueprint (Modules)

Har module ka format: entities, workflow, key rules.

### 5.1 Core: Organization, Campus, Settings
- **Entities:** organizations, campuses, school_sections (Junior/Prep/Senior), buildings, rooms, settings (per org/campus override), feature_flags (module on/off per school), subscriptions (SaaS plan).
- **Rule:** Master config org level par, campus override kar sakta hai.
- **Onboarding wizard:** school create > campus > sections > grades > fee heads > users import.

### 5.2 Academic Calendar
- **Entities:** curricula/programs (Matric, O-Level, A-Level, Montessori), academic_calendars (campus + program), terms, holidays, working_days.
- **Rule:** Term/grade/section calendar se bandhay hain, global year se nahi.

### 5.3 People: Students, Families, Guardians
- **Entities:** families, guardians (father/mother/guardian, alag record, user se link), students, student_documents, medical_profiles (encrypted), emergency_contacts, guardian_student pivot (relation, is_fee_payer, can_pickup, is_primary).
- **Student ID:** organization-wide unique, format configurable, kabhi reuse nahi.
- **Enrollments:** student_id, campus_id, calendar_id, grade_id, section_id, house_id, start_date, end_date, status.
- **DB rule:** partial unique index: aik student ka aik waqt mein sirf aik `active` enrollment.
- **Lifecycle status:** inquiry > applicant > enrolled > active > transferred/withdrawn/graduated > alumni.
- **Duplicate detection:** naya student add karte waqt naam+DOB+B-Form check.
- **Guardian conflicts:** custody/pickup restrictions (court order wale cases) flag.

### 5.3a Admissions
Inquiry > registration fee > application > documents verify > test > interview > assessment > waitlist > offer letter > admission fee > enrollment > ID + class/section allocation. Configurable test engine, seat matrix per grade, sibling priority, offer expiry, admission fee auto voucher.

### 5.4 Academics: Grades, Sections, Subjects, Curriculum
- **Entities:** grades, sections, subjects, subject_groups, subject_types (compulsory/optional/elective), prerequisites, class_subjects (section + subject + teacher), curriculum units/topics/learning outcomes, syllabus_coverage.
- **Rule:** section capacity limit, subject combination validation (A-Level rules).
- **Year-end promotion engine:** rules (pass/fail/conditional), bulk promote, section reshuffle, fee structure auto-switch, alumni trigger for final grade. Dry-run preview pehle, phir commit, rollback possible.

### 5.5 Timetable
- Periods, breaks, teacher availability, room constraints.
- **Auto-generator** (constraint solver, background job) + manual drag-drop + conflict detection.
- Substitution management: teacher absent > available teachers suggest > parent/student ko update.

### 5.6 Attendance
- **Student:** period-wise ya daily (school section ke hisaab se), default present, sirf exceptions mark. Statuses: present, absent, late, leave, medical, half day.
- **Sources:** teacher app, biometric/RFID, QR, bulk.
- **Rules (sab config):** attendance lock window (default 2 din, baad mein sirf approval se edit), leave approved to auto status, status codes school khud define kare.
- **Alerts:** absent guardian ko SMS/push school ke set kiye waqt par (default: attendance verify hone ke baad, jaise 9:00 AM), consecutive absence escalation, low attendance warning, late pattern.
- **Staff attendance** HR module se sync.

### 5.7 Leave
Student leave (parent apply > class teacher > coordinator). Staff leave (line manager > HR). Balances, carry-forward, holidays exclude, attendance se auto link.

### 5.8 Homework/LMS
- Assignments, submissions (file/online), rubric, marks, feedback, resubmission, late policy.
- LMS: lesson content, video/PDF, quizzes (auto-graded), discussion.
- Lecture recordings AI summary ke liye (section 9).
- Parent ko homework completion visibility.

### 5.9 Exams
- **Setup:** exam types (quiz to final, board mock), weightage, grading schemes (per program/campus), pass rules.
- **Schedule:** datesheet, rooms, seating plan, invigilator duty, clash check.
- **Marks entry:** teacher entry, lock, moderation, HOD approval, absent/exempt codes, re-check workflow.
- **Calculation:** percentage, grade, GPA, position, term weightage combine, background job.
- **Report cards:** template per program, teacher/coordinator/principal remarks, PDF (Urdu OK), bulk generate, publish gate (fees clear condition optional), parent portal download, digital verification QR.
- **Board exams:** Cambridge/Edexcel/BISE registration, candidate numbers, fee, results import.

### 5.10 Finance (Section 6 mein full detail)
**Documents ki terminology:** Voucher/Challan = payment ki demand (payment se pehle). Receipt = payment ka saboot (payment ke baad). Invoice optional (tax wale schools ke liye). Video mein jise 'invoice' kaha gaya woh asal mein receipt hai.

### 5.11 HR and Payroll
- Employees, contracts, documents, departments, designations, recruitment (jobs, applicants, interview), onboarding/offboarding.
- Payroll: salary structure, allowances, deductions, overtime, loans/advances, tax (income tax withholding), EOBI/social security, payslip PDF, bank transfer file, payroll approval workflow, lock month.
- Teacher workload, observation, KPIs, training, appraisal cycle.

### 5.12 Activities
Houses (points engine, leaderboard, captain), sports (teams, fixtures, results), clubs/societies, events (budget, participants, consent forms, photos), achievements/awards on student profile.

### 5.13 Pastoral
- **Medical:** profile, allergies, visits, medication, vaccination, injury, medical leave. Emergency card printable.
- **Counselling:** cases, sessions notes (encrypted), action plans, follow-up. Sirf counsellor + explicit grant.
- **Discipline:** incidents, severity, actions, parent notification, appeals, points system, pattern detection.
- **Safeguarding:** alag restricted workflow, mandatory escalation.

### 5.14 Operations
- **Library:** catalog, copies, barcode/QR, issue/return/fine, reservation, e-books.
- **Transport:** vehicles, drivers, routes, stops, student assignment, GPS tracking, fee link, boarding attendance, maintenance, fuel.
- **Hostel/Boarding:** houses, rooms, beds, wardens, meals, night attendance, leave/exeat, visitors, incidents, hostel fee.
- **Inventory:** items, stock, issue, damage, uniform/books shop sale (fee voucher se link).
- **Procurement/Vendors:** request > approval > quotes > PO > GRN > invoice > payment, vendor performance.
- **Facilities:** maintenance tickets, asset register, depreciation.
- **Visitor management:** gate pass, pickup authorization, QR.
- **Alumni:** graduate records, transcripts, certificates, alumni portal, donations.

### 5.15 Communication
Announcements/circulars targeting (campus, grade, section, house, individual), channels (portal, push, SMS, WhatsApp, email), templates (English/Urdu), delivery status, parent-teacher messaging with school hours and moderation, PTM booking, emergency alert, polls/consent forms, notification preferences.

### 5.16 Portals
- **Parent:** children switcher, attendance, homework, results, fees + online payment, timetable, leave, messages, transport tracking, documents, PTM, AI assistant.
- **Student:** timetable, homework, LMS, results, library, clubs, AI study helper.
- **Teacher:** today's classes, attendance, marks, lesson plans, homework, leave, AI lesson assistant, lecture summary.
- **Admin/Principal/Accountant dashboards:** role-wise widgets.

### 5.17 Reports and Data
- **Report builder:** module > columns > filters > sort > preview > export (PDF/Excel/CSV), saved templates, scheduled email reports, row-level permission respect.
- **Import tool:** Excel/CSV/old software, mapping screen, validation report, dry-run, rollback. Yeh sales ke liye critical hai.
- Bulk export, data retention.

### 5.18 Analytics, KPI aur School Growth
- KPIs: attendance %, fee collection %, arrears %, retention, admission conversion, academic average, teacher attendance, homework completion, incidents, engagement.
- **School Growth engine:** KPI target vs actual > gap > recommendation (rule-based data se, AI sirf explanation likhta hai) > action item > owner > deadline > progress > recheck next term.
- Trend charts, cohort analysis, at-risk student prediction (attendance + marks + fee + incidents ka score, explainable).

### 5.19 System
Approval workflow engine (fee discount, refund, expense, promotion, salary, purchase: configurable chains), notifications, document management (versions, expiry), scheduled tasks, API keys and webhooks for integrations, error logging, health dashboard, backup/restore UI, super-admin SaaS panel (schools, plans, usage, impersonate with audit).

---

## 6. Finance Design (Sab se Important)

### 6.1 Core tables
`fee_heads`, `fee_structures` (campus, grade, calendar, term, head, amount), `fee_plans` (installments), `student_fee_assignments`, `discount_rules` (sibling, staff child, scholarship, merit, need-based), `student_discounts`, `charges` (ad-hoc: books, uniform), `vouchers` (family level), `voucher_lines` (student level), `payments`, `payment_allocations`, `receipts`, `refunds`, `credit_balances`, `late_fee_rules`, `accounts` (chart of accounts), `journal_entries`, `journal_lines`, `inter_campus_adjustments`, `payment_gateway_events`, `bank_reconciliation_items`.

### 6.2 Rules (non-negotiable)
1. **Money integer paisa**, currency column.
2. **Ledger append-only.** Galti hui to reversal + naya entry. Kabhi UPDATE/DELETE nahi (DB trigger se block).
3. **Double-entry hamesha balanced:** debit total = credit total, DB constraint.
4. **Voucher number** sequence per org/campus/year, gap-free, duplicate impossible (DB unique + sequence table with lock).
5. **Payments idempotent:** `idempotency_key` unique. Webhook do baar aaye to aik payment.
6. **Allocation:** payment oldest arrears se pehle, ya voucher line specific, rule configurable.
7. **Period close:** month close ke baad us mahine mein entry nahi (adjustment agle mahine mein).
8. **Approval:** discount, waiver, refund, write-off approval chain se.
9. **Cash handling:** cashier session, day-end close, cash vs system difference report.

### 6.3 Flow
```
Fee structure set
  > month start par scheduler: har active enrollment ke liye charges generate (queue)
  > family level voucher merge (siblings aik voucher, student-wise lines)
  > discounts apply (rules school ke config se), arrears carry-forward. Late fee voucher par 'due date ke baad payable' amount ke taur par dikhti hai. Ledger mein woh tab post hoti hai jab payment late aaye ya due date guzre (school config karta hai)
  > voucher publish: PDF + SMS/WhatsApp/portal (1Bill number, Raast QR, bank challan)
  > payment aaya (counter/bank file/gateway webhook)
  > idempotency check > allocation > receipt
  > journal: Dr Cash/Bank, Cr Student Receivable
  > voucher status: paid/partial/overdue
  > reminders auto (due se pehle, due par, overdue)
  > defaulter list, fee freeze/result hold policy (optional)
```

### 6.4 Accounting entries (examples)
- Voucher issue: Dr Student Receivable, Cr Tuition Income (ya Unearned Revenue agar advance term).
- Refundable security deposit: Cr Security Deposit Liability, income nahi. Har fee head ka accounting type config hai (income / liability / pass-through).
- Payment: Dr Cash/Bank, Cr Student Receivable.
- Advance payment: Dr Cash, Cr Advance Fee Liability.
- Discount: Dr Fee Discounts (contra-revenue account), Cr Student Receivable. School apni funding se scholarship de to Scholarship Expense (config).
- Refund: Dr Advance Liability/Income, Cr Cash (approval ke baad).
- Campus transfer: Campus A Unearned Revenue Dr, Inter-campus Payable Cr, Campus B Inter-campus Receivable Dr, Advance Fee Cr. `EnrollmentTransferService` DB transaction mein.
- Reports: student ledger, family ledger, trial balance, P&L, balance sheet, cash flow, aging report, collection report, defaulters, discount report.

### 6.5 Pakistan-specific
1Bill/1LINK, Raast, JazzCash, Easypaisa, bank challan CSV import + auto reconcile, payroll tax/EOBI, fee receipts par tax rules jahan applicable, Urdu voucher, **2-per-page voucher** (School copy + Family copy), 4-copy option (bank, school, parent, office).

---

## 7. Roles aur Permissions

- Roles: Super Admin (SaaS owner), Org Admin, Campus Admin, Principal, Vice Principal, HOD, Coordinator, Class Teacher, Subject Teacher, Exam Officer, Accountant, Cashier, HR, Librarian, Nurse, Counsellor, Transport Manager, Warden, House Master, Sports Coordinator, Receptionist, Parent, Student, Auditor (read-only).
- Permissions format: `module.resource.action` (e.g. `fees.voucher.create`, `exams.marks.approve`). Actions: view, create, edit, delete, approve, export, print, assign, publish.
- Data scope per role: own org / assigned campuses / assigned classes / own children / self.
- Sensitive permissions (medical, counselling, salary, ledger edit) alag group, default off.
- Custom roles school khud bana sake, user-level override.
- Har permission change audited.

---

## 8. Data Model Summary

Sab tables: `id` (ULID/bigint), `organization_id`, `campus_id` (jahan lagu), timestamps, `created_by`, `updated_by`, `deleted_at`.

**Key relationships:**
```
Organization > Campuses > Rooms/Buildings
Campus + Program > AcademicCalendar > Terms
Calendar > Grades > Sections > ClassSubjects > Teacher
Family > Guardians (users)
Family > Students > Enrollments (campus, calendar, grade, section, house)
Enrollment > Attendance, Marks, Charges
Family > Vouchers > VoucherLines(student) > PaymentAllocations > Payments
Payments/Vouchers > JournalEntries > JournalLines > Accounts
```
**Indexes:** `(organization_id, campus_id, calendar_id)` composite sab transactional tables par, plus date columns. Attendance ke liye partitioning (by month) jab bara ho.

**Naming/conventions:** snake_case tables, enums PHP backed enums, migrations reversible, seeders demo school ke liye.

---

## 9. AI Layer (Design)

### 9.1 AI kya karega
| User | AI kaam |
|---|---|
| Parent | "Ahmed ki attendance kaisi hai?", fee balance, results samajhna, next actions, Urdu mein jawab |
| Student | Homework help, topic explain, quiz practice, study plan, lecture summary |
| Teacher | Lecture summary, lesson plan draft, quiz generation, marks ke comments draft, parent message draft, at-risk students |
| Accountant | Defaulters summary, reconciliation mismatch explain, reminder drafts |
| Principal/Admin | KPI explain, School Growth recommendations ki wajah, natural language reports ("is mahine Grade 8 ki attendance") |
| Sab | "Yeh kaam kaise karun" system guide (help center RAG) |

### 9.2 Architecture
```
React chat UI > /api/v1/ai/chat (Laravel)
  > AI Gateway: auth, org/campus/role context, rate limit, cost limit
  > Tool layer (function calling): getAttendance(student), getFeeBalance(family), getResults(...)
     har tool andar se wohi Policies/Scopes use karta hai jo normal API karti hai
  > Knowledge (RAG): pgvector, chunks par metadata (org, role_visibility, campus, grade)
     retrieval filter pehle lagta hai, phir similarity
  > LLM (Claude API) jawab likhta hai, sirf tools/RAG ke data se
  > Output filter (PII/permission check), audit log (prompt, tools called, tokens, user)
```

**Sabse important rule:** AI ko permission **model ke dimaag mein nahi**, code mein enforce hoti hai. Tool sirf wohi data lautata hai jo user ko milna chahiye. Parent A ka AI parent B ke bachay ka data kabhi dekh hi nahi sakta kyunke tool layer usay deta hi nahi.

### 9.3 Lecture Summarizer Pipeline
```
Teacher recording upload / live class audio
  > storage (signed) > queue job
  > Python worker: Whisper transcription (Urdu/English mix), speaker cleanup
  > chunking by topic, LLM summary: key points, definitions, formulas, homework mentioned, quiz questions
  > teacher review/edit (draft state) > publish to section
  > students/parents ko summary + Urdu/English option, syllabus topic se link
  > embeddings pgvector mein taake student "yeh topic kya tha" pooch sake
```
Consent: recording par notice, retention policy, sirf class ke members ko access.

### 9.4 Guardrails
- Counselling/medical/safeguarding notes AI ko by default nahi diye jate.
- Bachay (student role) ke liye safe mode: age-appropriate, no personal data of others, no exam cheating (jawab deta nahi jo "assessment" mode mein ho).
- AI kabhi paisa/ledger nahi badalta. Write actions sirf "draft" banate hain (message draft, plan draft), insaan confirm karta hai.
- Hallucination control: numbers hamesha tools se, jawab mein source dikhao ("attendance report, Sep 2026").
- Prompt injection: uploaded documents/lectures ko untrusted data treat karo, tool calls confirm rules.
- Cost control: per school monthly token budget, plan-wise, caching, chhota model routine kaam par, bara model complex par.
- Sab AI usage audited, school admin AI ko module-wise on/off kar sake.
- Evaluation set (100+ sawal role-wise) jo har release par chalta hai, leak test include.

### 9.5 AI Phases
1. Help-center RAG chatbot (sab roles, low risk).
2. Read-only data assistant (parent/teacher/principal tools).
3. Lecture summarizer + quiz generator.
4. Drafting (messages, remarks, lesson plans).
5. Predictive at-risk + School Growth explanation.

---

## 10. Non-Functional Requirements

| Area | Target |
|---|---|
| Speed | API p95 < 400ms, list pages < 300ms, PDF bulk async |
| Scale | Pehle 50 schools/50k students, design 500 schools |
| Uptime | 99.5% pehle, 99.9% baad mein |
| Backups | Daily full + PITR, restore drill monthly, RPO 15 min, RTO 2 hr |
| Accessibility | Keyboard, contrast, Urdu RTL |
| Mobile | Fully responsive PWA, offline attendance (sync later) |
| Data privacy | Consent, export/delete, retention, encrypted sensitive fields |
| Observability | Sentry, Pulse, slow query log, audit dashboards |
| Testing | Unit + feature (Pest), tenant isolation suite, ledger invariants tests, E2E Playwright |
| Search/tables | Server-side filter/sort/paginate, saved views, export |

**Performance tactics:** eager loading (N+1 lint in CI), cached aggregates for dashboards (materialized/nightly), Redis cache with tenant-prefixed keys, chunked bulk jobs, read replica jab zarurat.

---

## 11. DevOps

- Environments: local (Docker), staging, production.
- CI: lint (Pint, ESLint), static analysis (Larastan), tests, tenant-isolation suite, build, deploy.
- Zero-downtime deploy, migrations backward-compatible.
- Feature flags per school, staged rollout.
- Infra as code (Terraform) jab team bare.

---

## 12. Build Roadmap

| Phase | Kya | Result |
|---|---|---|
| 0 (2 hafte) | Repo, CI, Docker, tenancy skeleton + RLS, RBAC, audit log, settings | Foundation, isolation tests green |
| 1 (4 hafte) | Org/campus, calendars, grades/sections, students, families, guardians, enrollment, import tool | Data andar aa sakta hai |
| 2 (3 hafte) | Attendance + leave + parent notifications | Daily use shuru |
| 3 (6 hafte) | Fees, vouchers, payments, ledger, receipts, gateway/challan, reports | **Bechne wala MVP** |
| 4 (4 hafte) | Exams, marks, report cards | Academic cycle complete |
| 5 (4 hafte) | Parent/Student/Teacher portals, notifications, communication | Users engage |
| 6 (4 hafte) | HR, payroll, timetable, homework/LMS | Staff side |
| 7 (4 hafte) | AI phase 1 to 3 | Differentiator |
| 8 (ongoing) | Houses, sports, clubs, pastoral, library, transport, hostel, inventory | Premium features |
| 9 (ongoing) | Analytics, KPI, School Growth, AI phase 4 to 5, multi-campus finance transfers | Complete ERP |

Phase 3 tak (~19 hafte solo, kam agar team) ka system school ko bech sakte ho.

**Definition of Done har feature ka:** migration, model, policy, service, request validation, API resource, UI, permission seed, audit hook, tests (including tenant isolation), docs.

---

## 13. Risks aur Unka Ilaaj

| Risk | Ilaaj |
|---|---|
| Scope bohat bara | Phase discipline, feature flags |
| Tenant data leak | RLS + scope + mandatory isolation tests |
| Finance galti | Append-only ledger, invariants tests, pilot school par parallel run |
| School data migration mushkil | Import tool pehle phase mein |
| AI galat jawab/leak | Tool-based data, guardrails, eval suite |
| Adoption (teachers) | 30 second attendance, mobile-first, training videos |
| Cost (AI/SMS) | Budgets, caching, plan-based limits |
| Single developer burnout | Modules independent, pilot school se feedback |

---

## 14. A to Z: Ek Din System Mein

1. **Subah:** Teacher app kholta hai, class list dikhti hai, 2 bachay absent tick, submit. Parents ko SMS/push queue se.
2. **Ghar:** Parent aik login se dono campuses ke bachay dekhta hai, AI se poochta hai "Ahmed ki fees kitni baqi hai" > AI tool se balance, source ke saath.
3. **Class:** Teacher lecture record karta hai > raat ko transcription + summary draft > teacher approve > students ko summary.
4. **Fee counter:** Cashier family search, voucher open, cash receive, receipt print, ledger entry auto.
5. **Raat:** Gateway webhook aata hai, idempotent payment, voucher paid, parent ko receipt.
6. **Mahine ki 1 tareekh:** Scheduler sab families ke vouchers bana deta hai, arrears/discounts/late fee apply.
7. **Term end:** Marks entry > approval > report cards generate > parent portal.
8. **Principal:** Dashboard par KPI, School Growth "attendance 89% vs target 95%" ki asli wajah aur action assigned coordinator ko.
9. **Saal end:** Promotion engine dry-run, commit, naye fees, alumni.
10. **Hamesha:** Har change audit mein, har school ka data alag, backups chal rahe.

---

## 15. Pehla Kaam (Kal se)

1. Laravel 12 + PostgreSQL + Redis Docker setup.
2. Tenancy: `organizations`, `campuses`, `campus_user`, `TenantScope`, RLS policy, test suite.
3. Auth (Sanctum + 2FA), spatie permissions with teams, activitylog.
4. Phir students/families/guardians/enrollments migrations (partial unique index sath).
5. Ledger tables invariants ke sath, phir fees.
