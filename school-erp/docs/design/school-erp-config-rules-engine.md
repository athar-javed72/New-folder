# School ERP: Configuration and Rules Engine (v1.1)

Yeh document aap ke sample documents (voucher, discounts, report card, attendance, datesheet, timetable, lifecycle) ko dekh kar bana hai. Maqsad: **har school apne rules khud set kare, code change na ho.**

---

## 1. Asool: Kya dynamic, kya fixed

**Dynamic (config) = jo schools ke beech alag ho sakta hai.** Fee heads, discount rules, late fee, grading, weightage, attendance codes, bell timings, approval chains, templates, promotion rules, notification timing, roles ke permissions.

**Fixed (code) = jo kabhi alag nahi hona chahiye.** Ledger balanced rahe (debit = credit), ledger append-only, tenant isolation, audit log, payment idempotency, voucher number duplicate na ho, sensitive data encryption.

**Test:** "Kya do schools ka jawab alag ho sakta hai?" Haan to config. Nahi to code.

**Config ka khatra:** har cheez configurable karne se system complex ho jata hai. Isliye **Presets** dete hain ("Pakistan Private School Standard", "Cambridge School", "Montessori"). School preset chun kar sirf farq badalta hai. Sab kuch shuru se khali nahi hota.

---

## 2. Config Architecture

### 2.1 Layers (specific layer general ko override karta hai)
```
1. Platform default (preset)
2. Organization (school)
3. Campus
4. Program / Grade / Section
5. Individual student ya staff exception (approval ke sath)
```
Example: late fee org level par Rs. 1000. Campus B par Rs. 1500. Grade 10 par koi late fee nahi. System sab se specific rule uthata hai.

### 2.2 Har config record mein
`scope` (org/campus/grade/section/student), `key`, `value` (JSON, schema se validate), `effective_from`, `effective_to`, `version`, `status` (draft/published/archived), `created_by`, `approved_by`.

### 2.3 Non-negotiable rules
1. **Effective dating:** naya rule "1 November se" lag sakta hai. Purane records par asar nahi.
2. **Version snapshot:** har voucher, result, payroll par woh rule version save hota hai jo us waqt use hua. Rule badalne se purana voucher kabhi nahi badalta.
3. **Draft > Simulate > Publish:** naya rule pehle draft, phir "kis par kya asar" preview, phir publish.
4. **Diff aur rollback:** purana version wapis laa sakte hain.
5. **Audit:** kis ne kya badla, kab, kyun.
6. **Approval:** finance/grading rules badalne ke liye approval chain (Principal/Org Admin).
7. **Posted data kabhi nahi badalta:** paisa ya result ban chuka to sirf reversal/re-run hoga, edit nahi.
8. **Cache:** config Redis mein, publish par invalidate.

### 2.4 Building blocks (tables)
| Block | Kaam |
|---|---|
| `settings` | Simple typed values (lock window days, SMS time, currency) |
| `policies` | Rules: type, scope, priority, conditions (JSON), actions (JSON), dates |
| `workflows` + `workflow_steps` | Approval chains aur state machines data ki tarah |
| `lookups` | Statuses, attendance codes, grade bands, fee heads, leave types, incident types |
| `formulas` | Grading, KPI, punctuality formulas (safe expression evaluator, eval nahi) |
| `templates` | Voucher, receipt, report card, datesheet, SMS/WhatsApp (English/Urdu), variables ke sath |
| `custom_fields` | Student/staff/admission par extra fields (type, validation, kis role ko dikhe) JSONB mein |
| `bell_schedules` | Period timings, seasonal variants |
| `feature_flags` | Module on/off per school/campus/plan |
| `presets` | Ready configuration bundles |

**Rule evaluation:** conditions JSON mein (JsonLogic ya Symfony ExpressionLanguage sandboxed). Koi arbitrary PHP code school ke config se nahi chalta.

**Custom fields ka faida:** school ko "Bloodline: Sayyid" ya "Transport zone" jaisa field chahiye to developer ki zarurat nahi. Report builder mein bhi automatically aata hai.

---

## 3. Har Module Mein Kya Set Karna Hai

### 3.1 Fees
| Setting | Example (aap ke sample se) |
|---|---|
| Fee heads | Tuition, Annual, Admission, Security (refundable), Transport, Lab, Exam, Fine, Arrears |
| Har head ke flags | `accounting_type` (income/liability/pass-through), `is_discountable`, `is_refundable`, `is_taxable`, `frequency` (monthly/term/yearly/one-time), `revenue_account` |
| Fee structure | Campus + Program + Grade + Term/Month + head + amount, effective dates |
| Voucher cycle | Monthly ya term-wise, issue day, due day, validity days |
| Voucher layout | Family-wise ya student-wise, copies (School/Parent/Bank), per page, kaunse fields |
| Due date on holiday | Agle working day par shift (haan/nahi) |
| Payment allocation | Oldest arrears pehle / specific head / manual |
| Partial payment | Allowed ya nahi, minimum amount |
| Late fee | Type (flat / per din / percent / slab), grace days, cap, recurring, waivable, kab ledger mein post ho |
| Advance/Lump sum | Discount %, kaunse heads par, kis window mein |
| Mid-month admission | Proration: full month / daily / half-month rule |
| Refund | Kaun se heads refundable, kitne din mein, approval chain |
| Arrears | Carry-forward, kitne mahine baad defaulter |
| Result/ID hold | Fee baqi ho to result rokna? (haan/nahi, kitne arrears par) |
| Rounding | Nearest Rs. 1 / 10 / 100 |
| Voucher numbering | Format `{ORG}-{CAMPUS}-{YYMM}-{SEQ}`, reset yearly |
| Payment channels | Cash, bank, 1Bill, Raast, JazzCash, Easypaisa, cheque |

**Voucher calculation pipeline (order config mein):**
```
Arrears + Current charges = Gross
Gross - Discounts (rules se) = Net payable by due date
Net + Late fee (rule se) = Payable after due date
```

### 3.2 Discounts
| Setting | Options |
|---|---|
| Discount types | Sibling, staff, merit, need-based, early payment, custom (school naya bana sake) |
| Applies to | Kaunse fee heads (default: sirf tuition) |
| Value | Percent, flat, tiers (rank 2 = 20%, rank 3 = 30%) |
| Sibling rank order | Admission date / age (bara pehle) / manual |
| **Stacking mode** | `highest_single` (aap ka sample) / `cumulative_with_cap` / `priority_order` |
| Cap | Max total discount % |
| Validity | Start, expiry, auto-revert to full fee |
| Approval chain | Parent application > Campus Admin verify > Principal approve > Accountant attach (config) |
| Accounting | Contra-revenue (default) ya scholarship expense |
| Eligibility conditions | Service years, designation (staff), grade minimum (merit), attendance minimum |

**Zaroori:** "sirf ek discount" aap ke sample ka rule hai, sab schools ka nahi. Isliye stacking mode config hai.

### 3.3 Attendance
| Setting | Options |
|---|---|
| Mode | Daily (class teacher) ya period-wise, section/grade ke hisaab se |
| Status codes | Har school ke apne, **unique code** (P, A, LT, HD, LV). Har status ke flags: counts_as_present, counts_as_absent, needs_reason |
| Default | Sab present, sirf exceptions mark |
| Marking window | 8:00 to 8:15 |
| Pending check time | 8:30, dashboard alert kis ko |
| Parent notification | Time (9:00), channel, template, sirf absent ya late bhi, hold until verified |
| Lock window | 2 din, baad mein edit approval se |
| Late definition | 8:15 ke baad, ya grace minutes |
| Half day rule | Kitne ghante/period |
| Low attendance threshold | 75% warning, 65% escalation |
| Punctuality formula | Config formula |
| Exam eligibility | Minimum attendance % |
| Sources | Manual, biometric, RFID, QR |
| Weekly off/holidays | Calendar (Fri half, Sat working, Sunday off) per campus |

### 3.4 Exams, Grading, Report Card
| Setting | Options |
|---|---|
| Assessment tree | Term > Component > Sub-component. Sample: Formative 20, Mid-Term 30, Final 50 |
| Formative aggregation | Average / best N of M / drop lowest / sum scaled |
| Grade scale | Table: from%, to%, grade, GPA, remark |
| Pass rules | Overall %, per-subject %, compulsory subjects |
| Rounding | Decimals, half-up |
| Position rule | Section / grade wise, tie handling |
| Absent/exempt codes | Marks mein kaise count |
| Report card template | Sections on/off, order, logo, signatures, language, QR |
| Comments | Free text / preset comment bank, kaun likh sakta hai |
| Conduct grades | Scale (A,B,C ya Excellent/Good/Needs Improvement) |
| Publish workflow | Teacher > Coordinator > Principal > Exam Officer publish (config) |
| Publish gate | Fees clear? Attendance? |
| Datesheet rules | Weekend/holiday exam nahi, ek din mein max major exams, room capacity, exams ke beech minimum gap |

### 3.5 Timetable
| Setting | Options |
|---|---|
| Bell schedules | Periods, duration, breaks, per day (Friday short), **seasonal variants** (winter, summer, Ramadan) date range ke sath |
| Period types | Normal, double/block (lab), break, assembly, activity |
| Constraints | Teacher max periods/day, consecutive limit, room booking for labs, subject frequency per week |
| Views | Class, teacher, room |
| Substitution rule | Kis ko priority (same subject, free period) |

### 3.6 Lifecycle, Admissions, Promotion
| Setting | Options |
|---|---|
| Admission steps | School khud stages banaye/hataye (test, interview, waitlist) |
| Seat matrix | Grade wise seats, sibling priority |
| Promotion criteria | Pass %, per-subject rule, attendance minimum, conditional promotion |
| Promotion action | **Auto-propose, approval ke baad commit** |
| On fail | Retain / repeat / conditional |
| Section allocation | Merit / alphabet / balanced / manual |
| Fee structure switch | Promote par naya structure auto |
| Alumni trigger | Final grade ke baad |

### 3.7 HR, Communication, Baqi
- Leave types, balances, accrual, carry-forward, approval chain.
- Payroll: salary components, tax slabs, EOBI %, overtime rate, payroll lock day.
- Notification matrix: event x channel x role x time x template.
- Roles aur permissions school edit kar sake (presets se shuru).
- Approval chains sab sensitive kaam ke liye (discount, refund, expense, salary, promotion, purchase).
- Report builder saved templates, scheduled reports.
- Branding: logo, colors, letterhead, language default, currency, date format, week start.

---

## 4. Config Examples (JSON)

### Fee head
```json
{ "key": "security_deposit", "name": {"en":"Security Deposit","ur":"سیکیورٹی"},
  "accounting_type": "liability", "is_refundable": true,
  "is_discountable": false, "frequency": "one_time" }
```

### Sibling discount
```json
{ "type": "discount", "key": "sibling",
  "applies_to": { "fee_heads": ["tuition"] },
  "rank_order": "admission_date_asc",
  "tiers": [ {"rank": 2, "percent": 20}, {"rank": 3, "percent": 30} ],
  "effective_from": "2026-08-01" }
```

### Stacking policy
```json
{ "type": "discount_stacking", "mode": "highest_single", "cap_percent": 100 }
```

### Late fee
```json
{ "type": "late_fee", "method": "flat", "amount": 1000,
  "grace_days": 0, "recurring": false, "cap": 1000,
  "post_to_ledger": "on_late_payment", "waivable_with_approval": true }
```
Other methods: `per_day`, `percent`, `slab`.

### Grade scale (aap ke sample ke 4 bands; baqi school bharega)
```json
{ "type": "grade_scale", "bands": [
  {"min": 90, "grade": "A*", "gpa": 4.0},
  {"min": 85, "grade": "A",  "gpa": 3.7},
  {"min": 80, "grade": "A-", "gpa": 3.5},
  {"min": 70, "grade": "B",  "gpa": 3.0} ] }
```
(Boundaries sample ke marks se nikali hain: 92 = A*, 85 = A, 80 = A-, 72 = B. Asli boundaries school confirm karega.)

### Assessment weights
```json
{ "type": "assessment_scheme", "components": [
  {"key":"formative","weight":20,"aggregate":"average_scaled"},
  {"key":"midterm","weight":30},
  {"key":"final","weight":50} ], "rounding": "half_up_0" }
```

### Attendance statuses
```json
{ "type": "attendance_codes", "codes": [
  {"code":"P","name":"Present","present":true},
  {"code":"A","name":"Absent","absent":true},
  {"code":"LT","name":"Late","present":true,"late":true},
  {"code":"HD","name":"Half Day","present":0.5},
  {"code":"LV","name":"Leave","excused":true} ] }
```

### Absent notification
```json
{ "type": "notification_rule", "event": "student_absent",
  "send_at": "09:00", "after": "attendance_verified",
  "channels": ["sms","push"], "template": "absent_alert_v2" }
```

### Approval chain
```json
{ "type": "workflow", "key": "discount_request", "steps": [
  {"role":"campus_admin","action":"verify"},
  {"role":"principal","action":"approve","when":"amount_percent > 10"},
  {"role":"accountant","action":"attach"} ] }
```

### Bell schedule
```json
{ "type": "bell_schedule", "name": "Winter", "valid": ["2026-11-01","2027-02-28"],
  "days": {"mon-thu": [ {"p":1,"start":"08:00","end":"08:45"},
                        {"p":2,"start":"08:45","end":"09:30"},
                        {"break":true,"start":"10:15","end":"10:45"} ],
           "fri": [ ... short day ... ] } }
```

### Promotion rule
```json
{ "type": "promotion", "pass_if": "overall >= 40 and compulsory_each >= 33",
  "min_attendance": 75, "on_fail": "retain",
  "conditional_promote": true, "approval": "principal", "mode": "propose" }
```
(Numbers example hain, har school apne set karega.)

### Worked example: aap ka sample voucher config se
```
Arrears 0 + Tuition 25,000 + Transport 8,000 + Lab 2,000 = Gross 35,000
Sibling rule: tuition par 20% = 5,000  > Net (10-Oct) 30,000
Late fee flat 1,000 > Payable after due date 31,000
```
Discount sirf tuition par lagi kyunke `applies_to = tuition`. Transport aur lab par nahi.

---

## 5. Rules Studio (Admin UI)

School admin ke liye ek section "School Rules" jis mein tabs: Fees, Discounts, Attendance, Exams and Grading, Timetable, Promotion, Workflows, Templates, Notifications, Custom Fields, Roles.

Har tab par:
1. **Current rules** table (effective dates ke sath).
2. **Add/Edit** form (JSON nahi, friendly form).
3. **Preview:** "Yeh rule 240 students par lagega, average fee change Rs. X."
4. **Test with a student:** student chun kar dekho voucher/result kaisa aayega.
5. **Publish** (approval, effective date).
6. **History** aur rollback.

**Setup Wizard (onboarding):** preset chuno > 25 sawal (fee cycle? sibling discount? grading scale? Saturday working? Ramadan timings?) > sample voucher aur report card preview > confirm. School bina developer ke live.

**Template editor:** voucher aur report card ke liye drag-drop blocks, variables (`{student.name}`, `{voucher.net}`), English/Urdu, live PDF preview.

---

## 6. Aap Ke Document Ki Review

**Jo sahi hai:** voucher ki calculation (sab total sahi), sibling/staff/merit/lump sum types, discount approval workflow with expiry, formative/summative report card, default-present attendance, 3 timetable views, conflict-free datesheet, end-to-end lifecycle.

**Ghaltiyan/khamiyan jo mili:**
1. **Datesheet ke din ghalat hain.** 15-Nov-2026 Sunday hai (sample mein Monday). Chaaron dates ka din aik din aagey likha hai (17 Nov = Tuesday, 18 = Wednesday, 20 = Friday). Sunday weekly off bhi hai. **Fix:** din system tarikh se khud nikalta hai, haath se nahi likhte. Validation: weekend/holiday par exam nahi.
2. **Attendance mein "L" do jagah:** Late aur Leave dono. **Fix:** unique codes (LT, LV).
3. **GPA scale ulja hua:** A = 3.7 lekin A- = 3.5 aur A* = 4.0. Aam scale mein A = 4.0, A- = 3.7. **Fix:** scale table-driven, school confirm kare.
4. **Security Deposit income nahi, liability hai.** Ledger mein alag treat hoga. **Fix:** fee head accounting_type.
5. **Late fee ka trigger define nahi.** Voucher par 31,000 pehle se likha hai lekin 1,000 kab accounting mein aata hai? **Fix:** `post_to_ledger` setting.
6. **"Sirf highest discount" ek school ka rule hai.** Dusray stack karte hain. **Fix:** stacking mode.
7. **"Pehla bacha" kaun?** Bara, ya pehle admit hua? **Fix:** `rank_order` config.
8. **Voucher student-wise hai, family-wise nahi.** Aap ka core feature family-wise hai. **Fix:** family voucher jis mein student sections, sirf tuition par sibling discount.
9. **Voucher mein payment reference/QR nahi.** 1Bill ID, Raast QR, bank account. **Fix:** template variables.
10. **Timetable mein Science Lab ka teacher nahi** aur double period ka concept nahi. Saturday/Friday short day aur Ramadan timings bhi nahi. **Fix:** period types, bell schedule variants.
11. **Datesheet mein Grade/Section column nahi**, invigilator aur seating bhi nahi.
12. **Result publish "Admin" karta hai**, jabke doosre schools mein Principal ya Exam Officer. **Fix:** workflow config.
13. **Auto-promotion bina review ke risky hai** (galat marks se galat promotion). **Fix:** system propose kare, Principal approve kare.
14. **Lifecycle mein missing:** withdrawal/transfer, refunds, re-admission, alumni, staff lifecycle.

---

## 7. Pehle Blueprint Mein Jo Theek Kiya

| Kya ghalat/kamzor tha | Ab kya hai |
|---|---|
| Late fee "due date ke baad auto post" | Voucher par dual amount, ledger posting config se |
| Fee heads sab income maan liye | `accounting_type`: income / liability / pass-through |
| Discount ko "Discount Expense" kaha | Default contra-revenue "Fee Discounts", scholarship expense optional |
| Absent SMS "hote hi" | School ke set waqt par, verify ke baad |
| Discount stacking define nahi thi | Configurable mode |
| "Invoice payment ke baad" (video ki galat terminology) | Voucher = demand, Receipt = proof, Invoice optional |
| Hardcoded numbers (lock 2 din, 3 terms, fixed grade cuts) | Sab config, defaults presets mein |
| Roles fixed | Presets, school edit kar sake |
| Promotion auto | Propose + approve |

Baqi architecture (Postgres, RLS, ledger append-only, modular monolith, stack) mein koi tabdeeli nahi.

---

## 8. Engineering Notes

- **Config service:** `ConfigResolver::get('late_fee', $ctx)` (ctx = org, campus, grade, date). Sab modules yahi call karte hain, direct settings table nahi.
- **Rule engine:** `RuleEvaluator` conditions JSON par chalta hai, unit tests har rule type ke.
- **Snapshots:** voucher/result/payroll mein `config_version_ids` JSON.
- **Migrations for config:** schema versioning (config schema v2 aaye to auto-migrate purane values).
- **Tests:** har preset ke liye golden tests (aap ka sample voucher 30,000/31,000 dega, sample report card ke marks/grades bilkul match honge). Yeh regression se bachata hai.
- **Performance:** resolved config request-level cache aur Redis.
- **Limits:** custom fields per entity limit (e.g. 30), rules per type limit, taake system slow na ho.
- **Security:** finance/grading config sirf permission wale badal sakte hain, har change audited.

---

## 9. Ab Next

1. **Sample ko golden tests banao:** voucher (35,000 / 30,000 / 31,000), report card (85/72/92/80), 4 grades.
2. **Asli school se confirm** karo: unka grade scale, late fee, discount stacking, Saturday/Ramadan timing, promotion criteria.
3. **Phase 0 ke sath Config Engine banao** (settings + policies + workflows + versioning). Yeh foundation hai, baad mein jodna mushkil hai.
4. Fees ka ERD ab in config tables ke sath likho (`fee_heads`, `policies`, `vouchers.config_snapshot`).
5. Rules Studio ki 3 screens wireframe karo: Fee Heads, Discount Rules, Grade Scale.
