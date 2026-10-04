# School ERP: Dynamic Rules, Grading, Contracts, Automations (v1.5)

Is document mein: (1) aap ke 5 sawalon ke seedhe jawab, (2) system ko samajhne ke liye 7 building blocks, (3) grading aur GPA ka poora dynamic design, (4) Employment Contracts (har teacher ki alag leave/payroll), (5) aap ke paste kiye 5 advanced approaches ka honest review, (6) naye golden tests, (7) system ki honest assessment.

---

## 1. Aap Ke Sawalon Ke Jawab

| Sawal | Jawab |
|---|---|
| Late fee per day ka jarmana dynamic hai? | **Haan.** Rs 50 code mein nahi, `late_fee` policy ki value hai. Org, campus aur program level par badal sakte hain, effective date ke sath. Ab methods bhi barhe: flat, per din, percent of fee head, percent of balance, aur formula (section 3) |
| GPA points dynamic hain? Total marks aur marking bhi? | GPA points pehle bhi band-wise editable thay, lekin **sirf percentage aur A* se F tak mehdood** thay. Ab poora **Grading Profile**: GPA points, GPA scale (4.0/5.0/10), simple ya credit-weighted GPA, marks-only mode, subject-wise max marks, components ke marks, rounding, position rule. Sab dynamic (section 4) |
| Point 3 (per-subject pass 40%) dynamic? | **Haan.** Pass rules profile ka hissa hain: overall %, har subject %, component minimum (jaise final mein kam az kam 33%), compulsory subjects, grace marks, course-wise override |
| Staff casual leave har teacher ke liye alag, contract wale dinon ke hisaab se | **Haan.** Ab leave/payroll **Employment Contract** se chalte hain. Contract type (Permanent, Probation, Fixed-term, Visiting, Part-time) ke apne rules, aur har teacher par individual override (section 5) |
| Point 5 (unapproved leave / balance) | Theek hai, wohi rakha, contract type ke andar chalta hai |

---

## 2. Samajhne Ke Liye: 7 Building Blocks

Sab kuch dynamic karne se system uljha na ho, isliye har cheez in 7 mein se kisi aik mein aati hai:

| Block | Kaam | Misaal |
|---|---|---|
| **Settings** | Seedhi values | Lock window 2 din, SMS ka waqt 09:00 |
| **Policies** | Typed rules (form se bharte hain) | Late fee, discount, grading, leave |
| **Formulas** | Hisab ka advanced option | Late fee = tuition ka 2% |
| **Automations** | "Agar yeh ho to yeh karo" | 3 din absent to SMS aur ticket |
| **Workflows** | Insaani approvals | Discount: verify, approve, attach |
| **Templates** | Documents aur messages | Voucher, report card, SMS |
| **Custom Fields** | Extra data | "Allergies", "Hostel status" |

**Aik jumla jo sab yaad rakhwata hai:**
> **Policies facts tay karti hain, Automations reaction, Workflows insaani faisla, Formulas hisab.**

Misaal: "3 din absent" **Policy** ki value hai (`threshold = 3`). Jab student 3 din absent hota hai, system aik **event** fire karta hai. **Automation** bolti hai "us event par SMS bhejo". Agar Principal ka approval chahiye to **Workflow** chalta hai.

**Kaun si cheez kahan banaun (decision guide):**
- Sirf number badalna hai? **Settings/Policy.**
- Hisab ka tareeka badalna hai? **Policy method**, warna **Formula**.
- Kisi event par kuch automatically karna hai? **Automation.**
- Kisi insaan ki manzoori chahiye? **Workflow.**
- Naya data field chahiye? **Custom Field.**

---

## 3. Late Fee (Poori Tarah Dynamic)

`late_fee` policy ke methods:

| Method | Matlab | Misaal |
|---|---|---|
| `flat_once` | Aik baar fixed | Rs 500 |
| `per_day` | Har din | Rs 50 per din |
| `percent_of_head` | Fee head ka % | Tuition ka 2% |
| `percent_of_balance` | Baqi rakam ka % | Balance ka 1% |
| `slab` | Dino ke slabs | 1 se 7 din Rs 500, 8 se 15 Rs 1,000 |
| `formula` | Apni formula | `min(tuition_minor * 2 / 100, 200000)` |

Steps ko jorna ho sakta hai (pehle 7 din flat, phir per din). Cap, grace days, calendar vs working days, per voucher ya per student, waiver, ledger posting waqt, sab config.

**Rule version voucher issue par pin hota hai.** Late fee ka rate baad mein badle to purane voucher par purana rate lagta hai.

---

## 4. Grading Profile (Marks, GPA, Pass Rules)

Har program/grade/course ko aik **Grading Profile** assign hoti hai. Matric aur Cambridge ki profiles alag ho sakti hain.

### 4.1 Profile mein kya hai
| Hissa | Options |
|---|---|
| `result_mode` | `percent_grade` (percent se grade), `marks_only` (sirf marks, grade nahi), `gpa`, `cgpa`, `division` (First/Second/Third) |
| Marks structure | `weights` (component ka % contribution) ya `marks` (component ke apne max marks ka jama). Course ka `max_marks` (Maths 75, English 100) |
| Components | Formative, Mid, Final ya jo school chahe, har ka max marks aur weight, aggregation (average scaled, best N of M, drop lowest) |
| Scale | Bands: min, max, grade, **points**, remark, is_fail |
| GPA | Method (`simple_average`, `credit_weighted`, `best_n`), scale max (4.0/5.0/10), credits ka source (course credits), decimals |
| Pass rules | Overall %, har subject %, **component minimum** (final mein 33%), compulsory courses, grace marks (max, kab), fail grade |
| Rounding | Subject total, overall, grade lookup kis par |
| Position | Ranking type (competition/dense), scope (section/grade), tie break |
| Result extras | Division rules, honors/distinction, remarks bank |

### 4.2 Override order
`org > program > grade > course`. Subject ka max marks aur pass % course par override ho sakta hai (Urdu mein pass 33%, baqi 40%).

### 4.3 Rules
Effective date, version, draft/preview/publish. **Result mein `config_snapshot` save hota hai**, isliye scale badalne se purane terms ke result nahi badalte.

---

## 5. Employment Contracts (Har Teacher Ki Alag Policy)

### 5.1 Concept
Leave aur payroll rules teacher par seedhe nahi, **contract par** lagte hain.
```
Employee > Employment Contract(s) > Contract Type > Policies
                                  > Individual overrides (is teacher ke liye)
```
Aik teacher ke kai contracts ho sakte hain: pehle Probation, phir Permanent (effective date ke sath). Ya aik hi waqt mein Permanent (Campus A) aur Visiting (Campus B).

### 5.2 Tables
| Table | Columns |
|---|---|
| `contract_types` | id, organization_id, key, name, pay_basis (monthly/hourly/per_session/daily), leave_policy (jsonb), deduction_policy_key, pay_rules (jsonb), benefits (EOBI/tax flags), is_active |
| `employment_contracts` | id, employee_id, contract_type_id, campus_id, start_date, end_date, pay_basis, rate_minor, working_pattern (jsonb), policy_overrides (jsonb), status, approved_by |
| `leave_types` | id, organization_id, key (casual/medical/annual/unpaid), name, is_paid |
| `leave_entitlements` | id, contract_id, leave_type_id, accrual_method, qty, per, carry_forward, eligible_after_days, expires, source (contract_type/override) |
| `leave_ledger` | id, contract_id, leave_type_id, entry_type (accrual/used/adjustment/expiry), qty, ref, date | *(append-only, balance isi se)* |
| `payroll_lines` | id, payroll_run_id, contract_id, component, qty, rate_minor, amount_minor, source |

### 5.3 Leave resolution order (specific jeet-ta hai)
```
Org default > Campus > Contract Type > Individual contract override
```
Individual override (jaise "is teacher ko casual 2 per month") sensitive permission `hr.contract.override` se hota hai, reason ke sath, audited, beyond-limit ho to approval.

### 5.4 Accrual methods (contract wale teachers ke liye)
| Method | Misaal |
|---|---|
| `fixed_per_period` | Casual 1 per month |
| `upfront_per_year` | Medical 10 saal ke shuru mein |
| `prorated_by_contract_days` | 11 mahine ke contract par saalana 12 ke hisab se kam |
| `per_days_worked` | Har 22 din kaam par 1 leave |
| `none` | Visiting teacher ko koi leave nahi |
| `eligible_after_days` | Pehle 90 din (probation) ke baad leave milti hai |

`carry_forward`: `none` (period ke end par expire), `within_year`, `to_next_year`. Expiry aur encashment (leave ke paise) bhi config.

### 5.5 Payroll methods
| Pay basis | Hisab |
|---|---|
| `monthly` | Mahana salary, deductions contract type ke mutabiq |
| `hourly` | Rate x ghante (ghante sessions ke minutes se) |
| `per_session` (visiting) | Rate x **completed sessions** |
| `daily` | Rate x present din |

**Visiting/hourly teacher ki payroll Academic Engine se aati hai:** `sessions` jahan `actual_teacher = us teacher` aur `status = completed`. Substitute ho to substitute ko paisa milta hai, asli teacher ko nahi. School ne session cancel kiya to paid ya unpaid, config (`pay_rules.cancelled_by_school`).

Deductions (late arrival, unapproved absence) contract type par attach hote hain (`standard` ya `none`). Visiting teachers par late deduction nahi lagti, kyunke unhein sirf conducted sessions ke paise milte hain.

### 5.6 Default contract types (preset)
| Type | Pay basis | Leave (placeholders, school confirm kare) | Deductions |
|---|---|---|---|
| Permanent | monthly | Casual 1 per month, Medical 10 per year | standard |
| Probation | monthly | Casual 1 per month, 90 din baad | standard |
| Fixed-term (Contract) | monthly | Casual `per_days_worked` har 22 din 1 | standard |
| Visiting / Per Lecture | per_session | koi nahi | none |
| Part-time (Hourly) | hourly | koi nahi | none |

Contract expiry se 30 din pehle alert (automation).

---

## 6. Aap Ke 5 Advanced Approaches Ka Review

Pehle aik baat: "top 10% ya Workday/SAP level" jaisi tareef maine verify nahi ki aur woh meri nazar mein useful measure nahi. Asli sawal yeh hai ke har idea hamare problem ko kitna theek hal karta hai aur kya risk laata hai.

| # | Approach | Hamare paas pehle se | Kya add kiya | Khatra / Meri raay |
|---|---|---|---|---|
| 1 | Rule Engine / Expression Evaluator | `formulas` table (v1.1), typed policies | Policies ke methods mein `formula` option, variable catalog, test cases | **Haan lekin typed policies pehle, formula advanced option.** Formula se sab kuch likhna samajhna aur debug karna mushkil karta hai. Guardrails neeche (section 7) |
| 2 | Automations (IF-THEN) | Notification rules, domain events (v1.4) | Naya **Automations** module, event triggers, action catalog | **Haan, bohat faida.** Lekin SMS spam, loops aur galat actions ka khatra. Guardrails section 8 |
| 3 | Contract-based Payroll/Leave | Sirf generic staff leave | **Employment Contracts** (section 5) | **Haan, zaroori.** Aap ne khud yeh point uthaya tha |
| 4 | Dynamic fields (JSON/EAV) | `custom_fields` + `custom jsonb` + GIN index (v1.1) | Form builder, schema versioning, PII encrypt, "column mein promote" rule | **JSONB haan, asli EAV nahi** (EAV mein joins aur reports slow). Field **school ka Org Admin** banaye, Super Admin nahi (platform level cheez nahi) |
| 5 | CQRS / Materialized Views | Read models, summaries (v1.4) | Hybrid refresh, as_of timestamp, reconciliation | **Sirf raat ko calculate karna kaafi nahi.** Defaulters list payment ke foran baad update honi chahiye. Section 9 |

---

## 7. Formula Engine (Guardrails)

**Library:** Symfony ExpressionLanguage (whitelisted functions) ya JsonLogic.
**Allowed:** `min`, `max`, `round`, `floor`, `ceil`, `if`, `abs`, variables policy ke catalog se (jaise `late_days`, `tuition_minor`, `voucher_total_minor`).
**Not allowed:** DB access, objects/methods, loops, file/network.

| Guardrail | Detail |
|---|---|
| Money integers | Sab amounts paisa. 2% = `tuition_minor * 2 / 100`. Float nahi |
| Variable catalog | Har policy type ke dropdown mein sirf allowed variables |
| Validation | Publish se pehle syntax, unknown variables, division by zero |
| **Test cases** | Admin 3 se 5 sample inputs aur expected output likhe, pass hone par hi publish |
| Preview | "Is formula se 240 vouchers par kya amount aayegi" |
| Approval | Finance/grading formulas par approval chain |
| Limits | Execution time, expression size, no recursion |
| Versioning | Formula version snapshot (voucher/result ke sath) |
| Compile cache | Parsed expression Redis mein, har request par parse nahi |
| Audit | Kis ne kab badli |

Admin ko hamesha **insaani jumla** dikhta hai: "Due date ke 7 din baad tak Rs 500, phir Rs 50 per din."

---

## 8. Automations Module

### 8.1 Design
`automations`: id, organization_id, scope, name, trigger_event, params, conditions (jsonb), actions (jsonb list), enabled, effective dates, created_by, approved_by, run_as (service identity), limits (jsonb).
`automation_runs`: id, automation_id, event_id, dedupe_key, status, actions_result, ran_at.

**Triggers (outbox events se):** `student.absence_streak_reached`, `attendance.day_verified`, `voucher.due_in_days`, `voucher.overdue_days`, `payment.posted`, `result.published`, `leave.requested`, `session.substituted`, `staff.late_count_reached`, `contract.expiring`.

**Actions (catalog, arbitrary code nahi):** `send_notification` (channel, template, recipients), `notify_roles`, `create_task`, `create_discipline_case`, `add_flag`, `start_workflow`, `assign_to_role`, `webhook`.
**Financial actions** (charge lagana, waive) automations se nahi, sirf Workflow/approval ke zariye.

### 8.2 Guardrails
| Khatra | Bachao |
|---|---|
| Automation creator ki hadd se zyada kaam | `run_as` service identity creator ke permission ceiling ke andar. Jo creator nahi kar sakta woh automation bhi nahi |
| Infinite loops | Max depth 3, apne output events par re-trigger nahi |
| Spam/SMS cost | `dedupe_key` (aik student, aik streak, aik SMS), per school monthly SMS budget, quiet hours |
| Galat rule se nuqsan | **Dry-run** pichle 30 din ke data par: "yeh 47 baar fire hoti" |
| Parents ko message ya cases | Enable karne se pehle approval |
| Emergency | Kill switch (aik click par band) |
| Debug | `automation_runs` log, kyun fire hui/nahi hui |

### 8.3 Preset automation templates
Admin sirf on/off aur tweak kare: daily absent SMS, 3-din absence alert, voucher due reminder (3 din pehle), overdue reminder (due + 3), result published notice, contract expiry alert. Jo kuch custom chahiye woh **Automation Builder** (dropdown se: When, If, Then) se.

**Ab policies thresholds rakhti hain (`absence_alert.threshold_days = 3`), automations reactions.** Pehle ka `notification_rule` ab automation ban gaya.

---

## 9. Custom Fields aur Read Models (Refinement)

### Custom fields
- School ka **Org Admin** (ya delegated role) fields banata hai. Types: text, number, dropdown, multi-select, date, file, yes/no, **conditional visibility** (sirf agar "Hostel = Yes" to dikhao).
- **Form Builder:** admission form, student profile, employee profile sections ke sath.
- Har field: validation, required, kaun role dekh/edit kare, **PII flag** (encrypt), report builder mein khud aaye.
- Schema versioning: field hatao to purana data archive, delete nahi.
- Storage: `custom jsonb` + GIN index. **Jo field baar baar filter ho ya finance/legal logic mein ho woh asli column ban jaye** (promote rule).

### Read models (hybrid)
| Data | Tareeqa |
|---|---|
| Live (aaj ki attendance, defaulters, aaj ki collection) | **Event-driven incremental update** (payment post hote hi `family_balances` update, usi transaction ya foran baad) |
| Historical reports (term, saal) | **Raat ko rebuild** + materialized views (`REFRESH ... CONCURRENTLY`) |
| Reconciliation | Hafta/raat ka job read model ko ledger se milata hai, farq mile to alert aur theek |
| UI | "Data updated: 02:00" ya "live" ka as_of label |
| **Rule** | **Paisa ka sach sirf ledger hai.** Read model sirf dikhane ke liye, kabhi accounting nahi |

---

## 10. Samajhne Mein Asaan Kaise Rakhenge

1. **3 tiers UI:** Simple Form (values bharo) > Rule Builder (When/If/Then) > Formula (advanced). Aksar schools pehle tier par rahenge.
2. **Insaani jumla** har rule ke upar auto-generate.
3. **Presets** aur "Preset se compare" (kya badla hua hai).
4. **Preview aur simulate** har publish se pehle ("kitne students, kitna asar").
5. **Impact summary** aur **history/rollback**.
6. **AI "Explain this rule"** aur "Is student ka fee kaise bana" (Effective access aur calculation trace). `config_snapshot` is liye bhi kaam aata hai.
7. **Config budget:** max 4 override levels, aik hi Rules Studio pattern, naya concept tab hi jab upar ke 7 blocks mein na aaye.
8. **Developers ke liye:** har policy type ka ek file/schema/test, aur "Module Kit" manifest.

---

## 11. Naye Golden Tests

### Late fee (dynamic)
| ID | Setup | Expected |
|---|---|---|
| LF1 | Formula `tuition_minor * 2 / 100`, tuition Rs 25,000 | Rs 500 |
| LF2 | Formula `50000 + max(late_days - 7, 0) * 5000`, din 15 | Rs 900 (preset L5 ke barabar) |
| LF3 | Campus override per din Rs 100, din 15 | Rs 500 + 8 x 100 = Rs 1,300. Org default par Rs 900 |
| LF4 | Formula mein unknown variable | Publish reject |
| LF5 | Formula test case fail | Publish reject |
| LF6 | Rate Nov mein badla, Oct voucher Nov mein paid | Purana rate (snapshot) |

### Grading
| ID | Setup | Expected |
|---|---|---|
| GR1 | Student 35/100. Matric profile (pass 33, marks only) aur Cambridge profile (pass 40) | Matric: pass. Cambridge: F, fail |
| GR2 | Maths max 75, obtained 60 | 80%, A |
| GR3 | Components marks mode 20+30+50 | Weights mode jaisa hi total |
| GR4 | Credit weighted GPA: Eng 3.7 (3 credits), Maths 3.0 (4), Science 4.0 (3) | (11.1+12+12)/10 = 3.51 |
| GR5 | Wohi marks simple average | (3.7+3.0+4.0)/3 = 3.57 |
| GR6 | Total 38, grace marks max 2 enabled | 40, E, pass, "grace applied" flag. Disabled to fail |
| GR7 | Component min: final mein 33% (16.5/50). Final 15, total 55 | Fail (component rule) |
| GR8 | Scale badli (next term se) | Purane term ke result nahi badle |
| GR9 | Urdu course par pass 33 override, student Urdu 35, baqi 40 par pass | Urdu pass |
| GR10 | GPA scale max 5.0 profile | Points profile ke mutabiq, 4.0 hardcoded nahi |

### Contracts, Leave, Payroll
| ID | Setup | Expected |
|---|---|---|
| CT1 | Permanent, casual 1 per month, `carry_forward = none` | Har mahine balance 1, unused expire |
| CT2 | Fixed-term, `per_days_worked` har 22 din, 44 din kaam | 2 leaves |
| CT3 | Teacher X par override casual 2 per month | X ko 2, baqi ko 1. Audit entry, reason zaroori |
| CT4 | Override bina `hr.contract.override` permission | Reject |
| CT5 | Visiting, Rs 800 per session, 22 completed sessions | Rs 17,600 |
| CT6 | CT5 + 2 sessions school ne cancel, `cancelled_by_school = paid` | 24 x 800 = Rs 19,200. `unpaid` ho to Rs 17,600 |
| CT7 | Substitute (visiting) ne 1 session padhaya | Rs 800 substitute ko, asli teacher ko nahi |
| CT8 | Part-time hourly Rs 600, 20 sessions x 45 min | 900 min = 15 ghante = Rs 9,000 |
| CT9 | Visiting teacher late | Deduction 0 (deductions `none`) |
| CT10 | Probation se Permanent, 1-Mar se | 1-Mar se naye rules, history mehfooz |
| CT11 | Aik teacher ke do concurrent contracts (A permanent, B visiting) | Alag payroll lines aur alag leave balances |
| CT12 | Contract khatam hone se 30 din pehle | Expiry alert automation |

### Automations
| ID | Setup | Expected |
|---|---|---|
| AU1 | Absence streak 3 par automation | SMS **aik baar**, 4th din dubara nahi (dedupe) |
| AU2 | Creator ke paas `pastoral.discipline.create` nahi, automation mein create_discipline_case | Publish reject |
| AU3 | Automation ka output usi ko trigger kare | Depth limit par ruk jaye |
| AU4 | School ka SMS budget khatam | Actions rukein, admin ko alert |
| AU5 | Dry-run pichle 30 din par | Kitni baar fire hoti, kuch bheja nahi |
| AU6 | Kill switch | Foran band, queued actions cancel |

### Custom fields aur Read models
| ID | Setup | Expected |
|---|---|---|
| CF1 | "Allergies" dropdown add (migration nahi) | Form aur report mein aaye |
| CF2 | Field sirf Nurse ko visible | Baqi ko nahi |
| CF3 | Required field khali | Validation error |
| CF4 | Org A ka custom field | Org B ko nahi |
| CF5 | PII field | DB mein encrypted |
| RM1 | Payment post | Defaulters list foran update |
| RM2 | Read model ko jaan boojh kar kharab karo | Reconciliation job farq pakre aur theek kare |
| RM3 | Dashboard | as_of label sahi |

---

## 12. Honest Assessment

| Pehlu | Ab | Nuqta |
|---|---|---|
| Dynamic | 9/10 | Sab kuch typed policies, formulas, automations, contracts, custom fields se |
| Scalable (design) | 9/10 | Events, partitioning, read models, tenant sharding path |
| Samajhne mein asaan | **7/10** | Concepts zyada hain. 7 blocks aur 3-tier UI se bachayenge |
| **Implementation** | **1/10** | Abhi koi chalta hua code nahi |
| Asli school se validation | 0/10 | Abhi tak koi school ne dekha nahi |

**Teen khatre jo mujhe nazar aate hain:**
1. **Over-engineering.** Sab dynamic karne mein MVP kabhi nahi niklega. **3 stages:**
   - **Stage 1 (MVP bechne se pehle):** typed policies + forms + presets. Formulas aur Automation builder nahi.
   - **Stage 2:** Contracts, Automation builder, custom field form builder.
   - **Stage 3:** Formula editor, advanced reporting.
   **Data model ab design karo, UI baad mein.** Lekin dhyan rahe: Phase 3 ke MVP mein late fee, grading profile aur leave policy typed forms se chalein.
2. **Config ka combinatorial testing.** Har school ki alag config se bugs. Har preset ke liye golden tests, aur rule engine par property-based tests.
3. **Performance.** Rule/formula har voucher par chalne se slow na ho. Compiled cache, batch evaluation, aur 1000 families voucher run ka benchmark.

---

## 13. Preset v1.5 Mein Kya Badla
- `grade_scale`, `pass_rule`, `assessment_scheme` ko milakar **`grading_profile`** bana diya.
- `late_fee` mein `allowed_methods` list.
- Staff `leave_policy` aur `payroll_deduction` ki jagah **`contract_types`** aur deduction bundles (`standard`, `none`).
- **`automation_templates`** add.
- Student leave aur absence alert wahi.

Preset file: `pk_general_v1.preset.json` (v1.5).

## 14. Next
1. Preset v1.5 load karo.
2. Phase 0 ke Config Engine mein `grading_profile`, `contract_types`, `automations`, `formulas` ke schemas register karo.
3. Phase 1 mein `employment_contracts` aur `leave_ledger` tables (HR module se pehle, kyunke substitution aur payroll inhi par jurte hain).
4. Stage 1 UI: Late Fee form, Grading Profile form, Contract Types form.
5. Tests LF, GR, CT pehle likho.
