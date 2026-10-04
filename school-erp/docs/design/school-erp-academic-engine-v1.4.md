# School ERP: Academic Engine aur Scalability Architecture (v1.4)

Aap ne jo event-based architecture di hai woh sahi direction hai aur ab hamare design ka core ban gayi hai. Is document mein: (1) uska review, (2) final Academic Engine (tables aur flows), (3) wohi "template se instance" soch baqi poore system mein, (4) scalability patterns, (5) pichle documents mein kya badla, (6) golden tests, (7) naya build order.

---

## 1. Aap Ke Architecture Ka Review

### Jo bilkul sahi hai (hum rakh rahe hain)
1. Master timetable aik jagah, aur usse **generate hone wale individual sessions (events)**.
2. Attendance event par, class par nahi. Isse "us din kya actual hua" pata chalta hai.
3. **Substitute teacher sirf us din ke event mein**, master timetable ko chhue baghair.
4. "Used for Attendance" flag, taake break/assembly ka kachra data na bane.
5. Fees ka direct link events se nahi, **calendar aur enrollment** se.
6. Exams/report card **course** se linked.

### Jo khamiyan hain (hum add kar rahe hain)
| # | Khami | Fix |
|---|---|---|
| 1 | "Time Period" ka naam do jagah (saal aur TP1 periods). Confusion | Saal = **Academic Calendar**, daily slots = **Period Slots** (bell schedule) |
| 2 | "Course" aur "English-6" ek hi cheez maan li gayi. Aik grade ke kai sections ho sakte hain, aur electives mein students cross-section hote hain | 3 levels: **Course** (catalog) > **Teaching Group** (section ka class, teacher ke sath) > **Course Enrollment** (student ki roster, dates ke sath) |
| 3 | Events generate karna manual action hai. Bhool gaye to attendance nahi hogi | **Automatic rolling generation** (roz raat ko agle 4 hafte), manual button sirf override/dry-run ke liye |
| 4 | Timetable badle to kya hoga? Purane events? | **Timetable versions** (effective date) + "sirf future aur unmarked events update hon, marked events kabhi nahi" |
| 5 | Holidays, exam weeks, sports day, Ramadan timings, Friday short day ka zikr nahi | **Calendar overrides** aur bell schedule variants (date range + weekday + priority) |
| 6 | Per-event attendance se din ke 7 marks. Parent ko 7 SMS? Leave policy din ke hisaab se hai, event ke nahi | **Daily summary (derived)**. SMS, leave count, 3-din rule, report card sab daily summary par chalte hain |
| 7 | Primary classes ko period-wise attendance nahi chahiye | Per grade range **attendance mode**: daily (homeroom session) ya period |
| 8 | Volume: har student x har period x har din = bohat rows | Exceptions-only storage + partitioning (section 4) |
| 9 | Clash detection sirf app code mein hoga, race condition mein double booking | **DB-level exclusion constraints** (teacher, room, session) |
| 10 | A/B week rotation, double periods, lab batches (half class) ka zikr nahi | `week_pattern`, period span, teaching group **splits** (parent_group) |
| 11 | Exam datesheet ka events se rishta nahi | Exams bhi **sessions** hain (type exam), rooms aur invigilators ke sath |
| 12 | Marks ko "event" se na jorain warna event reschedule par marks kho jate hain | Marks **assessment** se jurte hain, event sirf "kahan hua" ka reference hai |
| 13 | Course-fees (elective, lab) ka zikr nahi | Fees events se nahi, lekin **course enrollment** se recurring charge bana sakte hain |

---

## 2. Final Academic Engine

### 2.1 Entity map
```
Academic Calendar (saal, program wise)
  > Terms
Bell Schedule (campus, date range, weekday, priority)
  > Period Slots (P1..P5, break, assembly; used_for_attendance, start, end)
Subject > Course (subject + grade + calendar)
  > Teaching Group (section ya cross-section group, teacher, room)
      > Course Enrollment (student, start_date, end_date)
Timetable Version (effective dated)
  > Timetable Entries (group, weekday, slot, teacher, room, week_pattern)
        |
        v   GENERATOR (rolling, idempotent)
Sessions (events) : course_session, homeroom, exam, activity, assembly, trip
  > Session Changes (substitute, room change, cancel, swap)
  > Attendance Marks (sirf exceptions)
  > Resource/Teacher Bookings (DB clash guard)
Assessments (course/group se) > Results > Term Results > Report Card
Fees: Calendar + Enrollment se (events se nahi)
```

### 2.2 Tables
| Table | Columns |
|---|---|
| `academic_calendars` | id, organization_id, campus_id (null = org wide), program_id, name, start_date, end_date, status (draft/active/closed) |
| `terms` | id, calendar_id, key, name, start_date, end_date |
| `bell_schedules` | id, organization_id, campus_id, name, valid_from, valid_to, weekdays (int[]), priority, status |
| `period_slots` | id, bell_schedule_id, key (P1), name, sort, start_time, end_time, slot_type (lesson/break/assembly/prayer/activity), used_for_attendance |
| `calendar_overrides` | id, organization_id, campus_id, grade_id (null), date, type (holiday/half_day/exam_day/event_day), bell_schedule_id (null), name |
| `courses` | id, organization_id, subject_id, grade_id, calendar_id, code (ENG-6), name, is_elective, scheme_id (assessment scheme), weight |
| `teaching_groups` | id, course_id, section_id (null for electives), parent_group_id (lab batches), name (ENG-6-A), teacher_id, default_resource_id, capacity, status |
| `course_enrollments` | id, student_id, enrollment_id, teaching_group_id, start_date, end_date, source (section_auto/elective_choice/manual) |
| `timetable_versions` | id, campus_id, calendar_id, name, effective_from, effective_to, status (draft/published/archived), published_by |
| `timetable_entries` | id, version_id, teaching_group_id, weekday, period_slot_id, span (1 ya 2 slots), teacher_id, resource_id, week_pattern (every/odd/even), valid_from, valid_to |
| `resources` | id, campus_id, type (room/lab/ground/bus/hall), name, capacity, attrs |
| `sessions` | id, organization_id, campus_id, type, calendar_id, teaching_group_id, source_type, source_id, session_date, starts_at, ends_at, period_slot_id, planned_teacher_id, actual_teacher_id, resource_id, status (scheduled/completed/cancelled), cancel_reason, attendance_required, attendance_state (not_required/pending/marked/verified), roster_count, absent_count, marked_by, marked_at, verified_by, lock_at, version |
| `session_changes` | id, session_id, type (substitute/room_change/time_change/cancel/swap), from_value, to_value, reason, requested_by, approved_by, created_at |
| `attendance_marks` | id, session_id, student_id, status_code, minutes_late, source (manual/biometric/rfid/qr/leave), marked_by, marked_at, remarks, client_uuid |
| `attendance_daily` | organization_id, campus_id, student_id, date, status (derived), sessions_total, sessions_present, source_rule, finalized_at |
| `bookings` | id, kind (teacher/resource), ref_id, session_id, during (tstzrange), status |
| `generation_runs` | id, type, scope, from_date, to_date, dry_run, status, created, updated, skipped, conflicts, diff (jsonb), started_by |
| `assessments` | id, course_id, teaching_group_id (null = course wide), component_key, name, max_marks, date, session_id (null), status |
| `assessment_results` | id, assessment_id, student_id, marks, status (present/absent/exempt), entered_by, locked_at |
| `term_results` | id, student_id, course_id, term_id, breakdown (jsonb), total, percent, grade, gpa, config_snapshot |
| `domain_events` | id, organization_id, type, aggregate_type, aggregate_id, payload, occurred_at, published_at |

`sessions` aur `attendance_marks` **month ya date se partitioned**. Har table mein `organization_id` pehle index mein.

### 2.3 Session generation (core engine)
```
Roz raat 02:00 (aur manual button, dry-run ke sath)
Window: aaj se agle 28 din
Har campus, har date:
  1. Holiday/weekly off? skip (override table dekho)
  2. Bell schedule resolve: campus + date + weekday, sab se ooncha priority
  3. Published timetable version resolve: effective_from <= date
  4. Har timetable entry jo weekday/week_pattern/valid dates match kare:
       natural key = (source_type, source_id, session_date)
       - session nahi hai: banao
           * used_for_attendance = slot flag AND attendance_policy(grade)
           * teacher, room copy karo
           * bookings banao (teacher + room)
           * approved student leaves apply karo (marks status LV)
       - session hai, scheduled aur unmarked, entry badli: update
       - session hai aur marked: haath mat lagao, conflict log karo
  5. Calendar overrides (exam day, sports day): sessions cancel/convert
  6. generation_runs mein summary
```
**Idempotent:** dobara chalane se duplicate nahi banta (unique natural key). **Dry-run:** sirf diff dikhata hai, kuch likhta nahi.

Flag copy ho jata hai session par. Baad mein slot ka flag badle to **purane sessions nahi badalte**.

### 2.4 Timetable badalne par
Calendar app jaisa edit mode:
- **Sirf is din** (aik session, session_changes mein).
- **Is din aur aagey** (naya timetable version, effective date).
- **Poora** (current version edit, sirf draft mein).
Kabhi bhi past ya marked sessions nahi badalte.

### 2.5 Attendance flow
1. **Attendance mode per grade range** (policy): Playgroup se Grade 8 daily (homeroom session, class teacher subah), Grade 9+ period-wise. Daily mode mein lesson sessions par `used_for_attendance = false`.
2. Teacher apna session kholta hai, roster = `course_enrollments` jo us date par active hon. Default sab present, sirf exceptions toggle.
3. **Storage:** period sessions mein **sirf exceptions** (absent, late, leave) rows banti hain. Session par `roster_count`, `absent_count`, `marked_at` hota hai. Present ka saboot session ka `marked` state hai. Homeroom (daily) mein poori rows.
4. **Offline/mobile:** har mark ke sath `client_uuid`, dobara sync par duplicate nahi.
5. Marking window ke baad pending sessions ki list Coordinator ko (policy se).
6. **Daily summary (derived):** period mode mein din ka status rule se nikalta hai, default "din ke 50% ya zyada sessions mein present". Rules: majority / first session / homeroom only.
7. **Parent SMS, leave count (15), 3 consecutive din ka alert, report card attendance, KPIs sab `attendance_daily` par chalte hain**, event-level marks par nahi. Isse parent ko din mein aik hi SMS jati hai.
8. Approved leave: us tareekh ke sessions khud LV, aur baad mein generate hone wale sessions bhi.
9. Lock window (default 2 din), baad mein edit approval se.

### 2.6 Substitution flow
```
Teacher ki leave approved (ya sick call)
> system us din ke sessions list karta hai (sessions where planned_teacher = us teacher)
> candidates: bookings overlap nahi, same subject pehle, free period, lowest workload (policy)
> Coordinator/VP confirm karta hai
> session_changes (substitute) + session.actual_teacher_id set, booking update
> substitute ko notification
> substitute ko sirf us session ki attendance ka access (session scope, lock tak)
> master timetable bilkul nahi badla
> payroll/overtime ke liye substitution count, parent ko optional notification
> koi candidate na mile: session "self study" ya cancelled (reason ke sath)
```

### 2.7 Assessments, exams, report card
- Course ka **assessment scheme** (20/30/50 etc.) config se.
- Assessment course ya teaching group se jura hai, **event se nahi**. Optional `session_id` sirf "kahan conduct hua".
- Marks `assessment_results` mein. Event reschedule/cancel hone par marks mehfooz.
- Term end par `TermResultCalculator` assessments ko scheme se aggregate karta hai: percent, grade, GPA, position, `config_snapshot` ke sath.
- **Exams bhi sessions:** datesheet entries se generator exam sessions banata hai (rooms, invigilators ke bookings ke sath). Seating roster `course_enrollments` se. Student ke do exams clash check roster join se.
- Report card: term_results + attendance_daily summary + conduct + remarks.

### 2.8 Fees ka rishta (aap ke point 4 se agree)
- Fee structure `calendar` aur `enrollment` se.
- **Calendar activation job:** calendar `active` hone par us calendar ke enrolled students ke liye fee schedules bante hain, aur monthly/term voucher runs automatic.
- Do parallel calendars (Matric, Cambridge) alag chalte hain, family voucher dono ki lines rakhta hai.
- Timetable ya events badalne se **koi financial entry nahi** banti (test FEE1).
- Elective/lab course fee: `course_enrollment` se `recurring_charges` (source = course), enrollment close hone par agle period se band.

### 2.9 DB-level safety
```
CREATE EXTENSION btree_gist;
bookings: EXCLUDE USING gist (kind WITH =, ref_id WITH =, during WITH &&) WHERE (status = 'active')
sessions: UNIQUE (source_type, source_id, session_date)
attendance_marks: UNIQUE (session_id, student_id), UNIQUE (client_uuid)
```
Isse do admin ek hi waqt teacher/room double-book nahi kar sakte, chahe app code mein race ho.

---

## 3. Scalability Patterns (pure system ke liye)

Aap ki "master se instance generate" soch ek pattern hai. Isse hum har jagah lagayenge.

| # | Pattern | Kahan lagu | Faida |
|---|---|---|---|
| 1 | **Template > Generator > Instances** (idempotent, rolling window, dry-run, diff, audit) | Timetable > sessions. Fee structure > charges > vouchers. Datesheet > exam sessions. Transport route > daily trips. Duty roster > duty instances. Hostel roll-call. Staff shift > expected attendance. Promotion > next-year enrollments | Aik hi framework, naye modules asaan |
| 2 | **Unified Session model** (type, time, resources, roster, attendance state) | Class, exam, activity, assembly, trip, PTM, hostel night check | Attendance, clash, substitution sab aik logic se |
| 3 | **Effective-dated membership** (start_date, end_date, kabhi delete nahi) | Course enrollment, section, house, club, transport, hostel bed, discount | "Us date par kaun tha" ka sahi jawab |
| 4 | **Resource calendar** (rooms, labs, buses, grounds, teachers aik booking system) | Timetable, exams, events, transport | Universal clash detection |
| 5 | **Domain events + transactional outbox** (`student.absent`, `payment.posted`, `session.substituted`, `result.published`) | Notifications, KPIs, AI, webhooks, audit | Modules aik dosre ko jaante nahi, extension asaan, retry safe |
| 6 | **Read models** (summary tables event/job se update) | `attendance_daily`, fee collection summary, KPI snapshots, dashboards | Dashboards raw tables par heavy query nahi |
| 7 | **Partitioning + retention** | sessions, attendance_marks, audit_logs, notifications, gateway_events, device scans | Bara data tez rehta hai, purana archive |
| 8 | **Exceptions-only storage** jahan default sab present ho | Period attendance | Rows 95% kam |
| 9 | **Effective-dated + versioned config/master data** | Timetable versions, fee structures, policies | Kal ka data aaj ki tabdeeli se kharab nahi |
| 10 | **Async job framework** (progress, partial failure report, retry, undo window) | Voucher runs, event generation, promotion, imports, report cards, bulk SMS | UI hang nahi, 202 + job status API |
| 11 | **Module Kit** (har module ka manifest: permissions, migrations, menu, settings, events, policies) | Naye module ka standard saancha | Team scale kar sakti hai |
| 12 | **Tenant scale path:** ULID/UUIDv7 ids, `organization_id` har index ka pehla column, cross-org join kabhi nahi | Bare school ko baad mein alag database par move karna aasan | Sharding ka rasta khula |
| 13 | **Offline-first sync** (client_uuid, idempotent apply, conflict rules) | Mobile attendance, marks entry | Kamzor internet par bhi kaam |
| 14 | **Notification Engine as a service** (event > rule > template > channel, batching, quiet hours, provider failover, delivery receipt, cost per school) | Sab alerts | Aik jagah control, SMS cost qaboo mein |
| 15 | **API standards** (idempotency keys, cursor pagination, bulk endpoints, per-tenant rate limit, ETag, versioning) | Poora API | Mobile, integrations, partners ke liye |
| 16 | **Analytics separation:** read replica pehle, baad mein warehouse (ClickHouse/BigQuery) events se | Heavy reports, AI, School Growth | Production DB par bojh nahi |
| 17 | **Safe migrations** (expand then contract, zero downtime) | Har deploy | Live schools ke sath asaan upgrade |

---

## 4. Volume Hisab (kyun partition/exceptions zaroori hain)

Aik school: 3,000 students, 7 lesson periods, 190 din.

| Cheez | Full rows | Exceptions-only (5% non-present) |
|---|---|---|
| Aik school, saal mein attendance rows | ~4.0 million | ~200,000 |
| 100 schools | ~400 million | ~20 million |
| Sessions (events), aik school | ~133,000 (100 sections x 7 x 190) | wohi |

Sessions kam hain, **marks bare hain**. Isliye marks par partitioning aur exceptions-only. Postgres partitioned tables ke sath yeh scale aaram se sambhal leta hai.

---

## 5. Pichle Documents Mein Kya Badla

| Purana | Ab |
|---|---|
| Blueprint 5.5 Timetable (sirf conflict detection) | Timetable versions + generator + sessions (is document ka section 2) |
| Blueprint 5.6 Attendance (daily ya period-wise, table `attendance`) | Session-based, exceptions-only, daily summary derived |
| Blueprint 5.9 Exams (`class_subjects` se) | Courses, teaching groups, assessments, exam sessions |
| Tables `class_subjects`, `attendance`, `attendance_records`, `timetables`, `lessons` | Is document ki tables (2.2) |
| Config: bell schedule jsonb | Normalized `bell_schedules` + `period_slots` (used_for_attendance), calendar overrides |
| Access scopes (org/campus/grade/section/child/self) | Naya relational scope **`session`**: teacher apne (ya substitute ke taur par) sessions ki attendance mark kare. Yeh static grant nahi, relation se compute hota hai (teacher of session, class teacher of section, own children) |
| Leave/absence rules (v1.3) | Wohi rules, lekin `attendance_daily` par chalte hain |
| Roadmap Phase 2 (Attendance) | Ab Timetable + Sessions + Attendance ek phase (section 7) |
| Preset JSON | v1.4: `bell_schedules`, `session_generation`, `attendance_policy`, `daily_status_derivation`, `substitution_policy` add |

Baqi (finance, ledger, access delegation, config engine, Postgres, stack) mein koi tabdeeli nahi.

---

## 6. Naye Policies (preset mein add)

| Policy | Default |
|---|---|
| `session_generation` | Window 28 din, run 02:00, holidays skip, unmarked future sessions regenerate, marked kabhi nahi, approved leaves apply |
| `attendance_policy` | Level 0 se 8: daily/homeroom, storage full. Level 9 se 14: period, storage exceptions_only |
| `daily_status_derivation` | `majority_of_sessions`, present threshold 50% |
| `substitution_policy` | Candidates: same subject, free period, lowest workload. Max extra 2 periods/day. Approval: Coordinator ya VP. Notify: substitute, class teacher. Access session ke lock tak |
| `bell_schedules` | Standard day: P1 08:00, P2 08:45, P3 09:30, BRK 10:15 (attendance off), P4 10:45, P5 11:30 se 12:15. Winter/Summer/Ramadan variants school bharega |

Sab org, campus aur grade level par override ho sakti hain, wohi publish/version/audit rules ke sath.

---

## 7. Golden Tests (Naye)

Dates: Oct 2026, 1-Oct Thursday. Mangalwar: 6, 13, 20, 27.

### Generation
| ID | Setup | Expected |
|---|---|---|
| GEN1 | Entry: ENG-6-A, Tuesday, P2. Generate 1 se 31 Oct | 4 sessions (6, 13, 20, 27) |
| GEN2 | GEN1 dobara chalao | Phir 4 (idempotent) |
| GEN3 | 13-Oct holiday override | 3 sessions |
| GEN4 | Slot BRK `used_for_attendance = false` | Break par attendance_required false |
| GEN5 | Dry-run | Diff milta hai, DB mein kuch nahi |
| GEN6 | Naya timetable version effective 20-Oct, 6 aur 13 marked | 6, 13 untouched. 20, 27 naye entry se |
| GEN7 | 20-Oct pehle se marked, version badla | 20-Oct untouched, conflict log |
| GEN8 | Ramadan bell schedule (date range) | Us range ke sessions naye time par |
| GEN9 | Slot flag baad mein true se false | Purane sessions ka flag nahi badla |
| GEN10 | Odd/even week entry | Sirf matching hafton ke sessions |

### Attendance
| ID | Setup | Expected |
|---|---|---|
| ATT1 | Grade 6 (daily mode) | Lesson sessions par attendance_required false, homeroom par true |
| ATT2 | Grade 10 period mode, roster 30, absent 2, late 1 | 3 rows store, roster_count 30, absent_count 2 |
| ATT3 | Student 15-Oct ko course join kare | 15 se pehle ke sessions ke roster mein nahi |
| ATT4 | 7 sessions, 4 mein absent | Din ka status absent (present 3/7 = 42.9% < 50%) |
| ATT5 | 3 mein absent | Din present (4/7 = 57.1%) |
| ATT6 | Din mein 4 absent sessions | Parent ko **aik** SMS |
| ATT7 | Leave 14 se 16 Oct approved | Un dino ke sessions LV, baad mein generate hone wale bhi |
| ATT8 | Same `client_uuid` do baar | Aik mark |
| ATT9 | Lock window ke baad edit | Approval chahiye |
| ATT10 | 15 leaves, 3-din rule | `attendance_daily` par chalen (v1.3 tests LV1 se LV5 pass) |

### Substitution aur Clash
| ID | Setup | Expected |
|---|---|---|
| SUB1 | Teacher ki leave 13-Oct approved | Us din ke uske sessions list hon |
| SUB2 | Candidates | Booking overlap wale nahi, same subject pehle |
| SUB3 | Substitute assign | Master timetable unchanged, session.actual_teacher = substitute |
| SUB4 | Substitute | Sirf us session ki attendance mark kar sakta hai, group ke baqi sessions nahi |
| SUB5 | Session ke lock ke baad | Substitute ka access khatam |
| SUB6 | Candidate nahi mila | Session self-study ya cancelled reason ke sath |
| CLH1 | Teacher ko ek hi waqt do sessions | DB exclusion error |
| CLH2 | Room double book | DB exclusion error |
| CLH3 | Cancelled session ki booking | Slot dobara free |

### Exams, Fees, Platform
| ID | Setup | Expected |
|---|---|---|
| EXM1 | Exam session reschedule | Assessment marks safe |
| EXM2 | Term aggregate | v1.3 ke G1 se G5 numbers (82.25%) |
| EXM3 | Datesheet entries | Exam sessions, room aur invigilator bookings ban jayein |
| EXM4 | Student ke do exams same waqt | Reject |
| FEE1 | Timetable ya sessions badlo | Koi voucher/journal entry nahi |
| FEE2 | Calendar activate | Sirf us calendar ke enrollments par fee schedule |
| FEE3 | Elective lab fee course enrollment se | Enrollment close hone par agle period se band |
| OUT1 | Payment post | Outbox row same transaction mein |
| OUT2 | Consumer fail, retry, duplicate delivery | Effect aik hi baar |
| PERF1 | 100 sections x 4 hafte generation | 60 second se kam |
| PERF2 | 30 students ki attendance submit | 300 ms se kam |
| ISO1 | Org A ke sessions Org B ko | Kabhi nahi |

---

## 8. Naya Build Order

| Phase | Kya |
|---|---|
| 0 | Foundation, tenancy, Access, Config Engine, audit, outbox, job framework |
| 1 | Org, campuses, calendars, grades, sections, students, families, enrollments, import tool |
| 2 | **Academic Engine:** bell schedules, courses, teaching groups, course enrollments, timetable versions, generator, sessions, attendance, daily summary, leave, substitution |
| 3 | Fees, vouchers, payments, ledger (bechne wala MVP) |
| 4 | Assessments, exams, report cards |
| 5 | Portals, notifications engine, communication |
| 6 | HR, payroll, homework/LMS |
| 7 | AI |
| 8+ | Baqi modules, analytics, School Growth |

**MVP shortcut:** chhote schools ke liye timetable module optional. Sirf **daily attendance** (homeroom sessions calendar se auto-generate, bina timetable ke). Timetable on karte hi period mode available. Yeh feature flag se hota hai, is se MVP jaldi bik sakta hai.

**Risk:** Academic Engine sab se complex hissa hai. Isliye Phase 2 mein generator, clash constraints aur attendance ke golden tests pehle likho, phir code.

---

## 9. Next
1. Preset JSON v1.4 load karo (naye policies).
2. Phase 0 mein outbox aur job framework bhi banao (yeh baad mein jodna mushkil hai).
3. Phase 2 ke tables migrations likho, `btree_gist` extension aur exclusion constraints ke sath.
4. Tests GEN, ATT, SUB, CLH pehle likho.
5. Asli school se confirm: daily vs period attendance kis grade se, substitution kaun approve karta hai, Ramadan/winter timings.
