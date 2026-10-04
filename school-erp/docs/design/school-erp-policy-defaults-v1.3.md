# School ERP: Policy Defaults Update (v1.3)

> **Update v1.5:** staff leave aur payroll ab Employment Contracts se chalte hain, aur grade scale/pass rule/weightage ek **Grading Profile** mein hain (GPA, marks mode, component minimum, grace marks sab dynamic). Tafseel 'Dynamic Rules (v1.5)' document mein. Jahan takrayein wahan v1.5 sahi hai.

Aap ke 10 standard rules preset `PK_GENERAL_V1` ki defaults ban gaye hain. Machine-readable file: `pk_general_v1.preset.json` (seeder isi se load hoga). Yeh document batata hai kya badla, kaise dynamic hai, kya naya banana pare ga, aur golden tests.

**Asool:** yeh sirf **starting values** hain. Org Admin/Campus Admin apne school aur branch ke hisaab se badal sakte hain. Code change nahi.

---

## 1. Kya Badla (v1.2 se v1.3)

| # | Cheez | Pehle (v1.2) | Ab (v1.3) |
|---|---|---|---|
| 1 | Late fee | Flat Rs 1,000 (sample) | Pehle 7 din flat Rs 500, phir Rs 50 per din upar se, cumulative, per voucher |
| 2 | Discount stacking | `highest_single` | Wohi, lekin compare **amount** se hota hai (percent se nahi) |
| 3 | Grade scale | A*, A, A-, B+, B, C, D, E, F | A* 90, A 80, B 70, C 60, D 50, E 40, F below 40 |
| 3 | Pass marks | Overall 40, har subject 33 (meri assumption thi) | Overall 40, har subject 40 |
| 4 | Weightage | 20/30/50 | Koi tabdeeli nahi |
| 5 | Student leave | Rules nahi thay | 15 leave per saal (medical + casual shared), 3 consecutive bina notification absent par SMS |
| 5 | Staff leave | Casual 10 per saal | Casual 1 per month (aap ne 1 se 2 kaha, default 1) |
| 6 | Staff payroll | Sirf general | 3 late = half day, khatam leaves par har unapproved absence = 1 din salary |
| 7 | Transfer | Date-based split | Wohi, aur remaining advance auto credit ban ke naye campus mein jata hai |
| 8 | Calendars | Aik Aug to Jul default | Do parallel: Matric (Apr to Mar), Cambridge (Aug to Jul) |
| 9 | Refund | Security refundable, 30 din | Admission aur annual hamesha non-refundable. Security sirf library + lab + accounts clearance ke baad |
| 10 | Promotion | Sab propose, Principal approve | Fail student kabhi auto nahi. Retain/conditional sirf Principal ya Academic Head approve kar sakta hai |

---

## 2. Dynamic Kaise Hai (org, campus, program, grade)

Har policy par `override_levels` aur `editable_by` hai:

| Policy | Kahan override ho | Kaun edit kare |
|---|---|---|
| Late fee | Org, Campus, Program | Org Admin (campus override par Org Admin approval) |
| Discount rules, stacking | Org, Campus | Org Admin |
| Grade scale, pass rule, weightage | Org, Program, Grade | Org Admin |
| Student leave, absence alert | Org, Campus | Org Admin, Campus Admin |
| Staff leave | Org, Campus | Org Admin |
| Payroll deduction | Sirf Org | Org Admin |
| Transfer finance | Sirf Org | Org Admin |
| Refund policy | Org, Campus | Org Admin |
| Promotion | Org, Campus, Program | Org Admin |
| Calendars | Program aur campus | Org Admin, Campus Admin (campus ke terms) |

Har override par pehle wale rules lagu hain: effective date, version snapshot, draft > preview > publish, audit, rollback, aur Campus Admin ka override "delegation ceiling" ke andar.

**Aik cheez jo config nahi, code mein fixed rahegi (invariants):**
1. **Fail student auto promote nahi hota.** Config sirf yeh tay karta hai ke faisla kaun kare (roles) aur criteria kya hain, yeh nahi ke insaan ka faisla zaroori hai ya nahi.
2. Ledger balanced aur append-only.
3. Tenant isolation.
4. Payment idempotency.
5. Audit log.

---

## 3. Nayi/Update Policy Types (logic)

### 3.1 Late fee (`late_fee`)
- Amount **tab compute hota hai jab payment aati hai**, voucher par fixed number nahi. Ledger posting default `on_late_payment`.
- Formula: `days_late = payment_date - due_date` (calendar din, due date holiday par shift ho chuki ho to shifted date).
  - 0 din: 0
  - 1 se 7 din: Rs 500
  - 8 se aagey: Rs 500 + Rs 50 x (days_late - 7)
- Per **voucher** (family voucher par aik baar, har bachay par nahi).
- Cap default khali. Recommend karta hun ke school cap set kare (jaise Rs 2,000), warna penalty hamesha barhti rahegi.
- Voucher par printed text: "Due date ke baad: 7 din tak Rs 500, phir Rs 50 per din." `payable_after_due` mein pehle hafte ki amount dikhti hai.
- Online channels (1Bill, Raast) ko amount har din badalni pare gi. Har provider se confirm karna hoga ke bill update kaise hota hai.
- Late fee waive: approval ke sath.
- Rs 50 ya Rs 100 per din: default 50, school change kare.

### 3.2 Discount stacking
`highest_single`, `compare_by: amount`. Sibling 20% (Rs 5,000) aur flat merit Rs 6,000 aayein to merit jeete ga (amount zyada), chahe percent kam ho.

### 3.3 Grading
Naye bands. Rounding: subject total round half up 0 decimals, phir grade lookup. F = fail, is liye "39.5" round hoke 40 = E = pass. School chahe to rounding off kar sakta hai.
**GPA points aap ne nahi diye.** Placeholder: A* 4.0, A 3.7, B 3.0, C 2.0, D 1.0, E 0.5, F 0.0. School confirm kare.

### 3.4 Leave aur Absence Alert
- **Student:** saal mein 15 (medical + casual aik pool). 16th leave par system rok kar Coordinator approval maangta hai, aur "excess leave" flag lagta hai.
- **3 consecutive absence alert:** sirf woh din count hote hain jin par leave application nahi thi. Sirf school days (weekend/holiday skip). Teesre din 09:00 par attendance verify hone ke baad SMS parent ko, aur Class Teacher + Coordinator ko notification. Approved leave wala din chain tod deta hai.
- **Staff:** casual 1 per month accrual, carry-forward nahi.

### 3.5 Staff Payroll Deductions
- **Per-day salary** = monthly gross / 30, nearest Rs 1. (Basis aur divisor config.)
- **Late arrivals:** mahine mein har 3 late = half day. Pehle staff ki leave balance se 0.5 kaate, balance na ho to half-day salary. Bacha hua (1 ya 2 late) agle mahine carry nahi.
- **Unapproved absence:** pehle leave balance, khatam ho to har din ki poori per-day salary deduction.
- HR waive kar sakta hai, reason ke sath, audited. Payroll month lock hone ke baad deduction nahi badalti.
- **Ehtiyaat:** tankhwah mein katautiyon par Pakistan ke labour laws ke rules hote hain. Launch se pehle kisi HR/legal advisor se confirm karwa lo. Isliye yeh policy sirf Org level par edit hoti hai.
- Ambiguity: "unapproved leave par pehle balance use ho ya seedha salary kate" aap ne clear nahi kiya. Maine `use_leave_balance_first = true` rakha.

### 3.6 Inter-Campus Transfer
Approval ke baad automatic:
1. Purane campus mein revenue effective date tak.
2. Unbilled advance balance naye campus mein credit.
3. Dono campuses mein inter-campus payable/receivable entries, monthly settlement.
4. Arrears: purane campus mein hi rehte hain. Transfer approval par warning, Principal override ke baghair final nahi hota (yeh meri default hai, aap ne nahi bataya).

### 3.7 Refund aur Clearance
- Admission aur annual: `refundable = false`, refund request system reject karta hai.
- Security deposit: student exit par clearance workflow: **Library, Lab, Accounts** teenon zaroori (parallel). Sab clear hone ke baad amount = deposit - dues (library fine, lab damage, arrears jo adjust hon). Phir approval chain aur payment.
- Clearance departments school badal/barha sakta hai (transport, hostel, sports).

### 3.8 Promotion
- Pass hone walon ke liye system draft banata hai, Principal ya Academic Head bulk approve karta hai.
- Fail walon ke liye system **retain propose** karta hai. Conditional promotion ke liye reason, conditions aur parent notification zaroori.
- Faisla sirf `decision_roles` (Principal, Academic Head). Coordinator/VP nahi kar sakte jab tak school role list badle na.

### 3.9 Parallel Calendars
- Matric aur Cambridge programs ke apne calendar, terms, exams, promotion dates.
- Student ka enrollment apne program ke calendar se bandha hai.
- Family voucher calendar-independent hai: Oct ka aik voucher, jis mein Matric wale bachay ki line aur Cambridge wale ki line dono hoti hain.
- Terms ki dates placeholders hain, school confirm kare.

---

## 4. Naye Roles

| Role | Default access |
|---|---|
| **Academic Head** | Principal jaisa academic access: results approve/publish, promotion decision, curriculum, teacher performance. Finance sirf view. Users/rules nahi |
| **Lab In-charge** | Lab module, lab clearance, lab damage charges. Baqi students ki basic view |

Dono roles editable hain aur delegation model (Campus Admin de sakta hai, ceiling ke andar) ke mutabiq milte hain.

---

## 5. Golden Tests (Naye aur Badle Hue)

**Test rule:** har golden test apni config **khud pin** karta hai (fixture), preset par depend nahi karta. Warna kal preset badalne se tests toot jayenge. Purane sample wale tests (late fee Rs 1,000, sample grade scale A-) fixtures ban gaye, delete nahi hue.

### 5.1 Late fee (preset)
Due date 10-Oct-2026 Saturday, weekly off Sat/Sun, is liye shifted due date 12-Oct-2026 (Monday).

| ID | Payment date | Expected late fee |
|---|---|---|
| L1 | 12-Oct (due date) | 0 |
| L2 | 13-Oct (din 1) | Rs 500 |
| L3 | 19-Oct (din 7) | Rs 500 |
| L4 | 20-Oct (din 8) | Rs 550 |
| L5 | 27-Oct (din 15) | Rs 900 (500 + 50 x 8) |
| L6 | Late fee waived (approval ke sath) | 0, audit entry |
| L7 | Cap Rs 2,000 set, din 60 | Rs 2,000 |
| L8 | Family voucher 3 bachay, din 3 | Rs 500 (voucher par aik baar) |

Re-baselined: **V1b** (sample voucher, preset late fee, payment din 3): net 30,000 + 500 = **30,500**. **V2b** (family voucher V2, din 3): 69,000 + 500 = **69,500**.
**V3b:** tuition 25,000, sibling 20% (5,000) aur flat merit 6,000, `highest_single` by amount: discount **6,000**.

### 5.2 Grading (general scale, GPA placeholders pinned in fixture)
| ID | Input | Expected |
|---|---|---|
| G1 | English 18+25+42 | 85, A, 3.7 |
| G2 | Maths 15+22+35 | 72, B, 3.0 |
| G3 | Science 19+28+45 | 92, A*, 4.0 |
| G4 | Urdu 16+24+40 | 80, **A** (purane scale mein A- tha), 3.7 |
| G5 | G1 se G4 overall | 329/400 = 82.25%, GPA (3.7+3.0+4.0+3.7)/4 = 3.6, grade A |
| G6 | 89.5 | Round 90, A* |
| G7 | 79.5 | Round 80, A |
| G8 | 69.5 | Round 70, B |
| G9 | 39.5 | Round 40, E, pass |
| G10 | 39.4 | Round 39, F, fail |
| G11 | Maths 38, overall 70 | Subject fail (per-subject 40) |

### 5.3 Leave aur Absence
| ID | Setup | Expected |
|---|---|---|
| LV1 | Student ki 15 leaves poori, 16th apply | Coordinator approval chahiye, excess flag |
| LV2 | 3 consecutive school days absent, koi leave application nahi | 3rd din 09:00 par SMS (verify ke baad), Class Teacher + Coordinator notify |
| LV3 | 2 absent + teesre din approved leave | SMS nahi |
| LV4 | Thursday, Friday absent, Sat/Sun off, Monday absent | Monday ko SMS (school days consecutive) |
| LV5 | Staff casual: Jan mein 1 use nahi kiya | Feb mein balance 1 hi (carry nahi) |

### 5.4 Payroll (salary 60,000, per day 2,000)
| ID | Setup | Expected |
|---|---|---|
| PD1 | 3 late, leave balance 0 | Salary deduction 1,000 |
| PD2 | 3 late, leave balance 1 din | Balance 0.5, salary deduction 0 |
| PD3 | 7 late, balance 0 | 2 penalties = 1 din = 2,000, bacha hua 1 late carry nahi |
| PD4 | 2 unapproved absence, leaves khatam | 4,000 |
| PD5 | PD3 + PD4 | 6,000, net before tax 54,000 |
| PD6 | HR PD1 waive kare reason ke sath | Deduction 0, audit entry |
| PD7 | Payroll month locked, deduction badalne ki koshish | Reject |

### 5.5 Transfer
Setup: parent ne Aug se Dec (5 mahine) tuition 125,000 advance di. Aug aur Sep ke vouchers advance se settle (50,000 earned). Transfer effective 1-Oct.

| ID | Expected |
|---|---|
| TR1 | Remaining advance 75,000 naye campus mein credit |
| TR2 | Campus A: Dr Advance Fee Liability 75,000, Cr Inter-campus Payable 75,000. Campus B: Dr Inter-campus Receivable 75,000, Cr Advance Fee Liability 75,000 |
| TR3 | Campus A ka recognised income 50,000 hi rahe |
| TR4 | Arrears 5,000 hon | Warning, Principal override ke baghair final approval nahi |
| TR5 | Transfer mid-month (16-Oct), daily proration | Campus A income Oct ka 15/31, baqi credit |

### 5.6 Refund
Setup: security deposit 10,000.

| ID | Setup | Expected |
|---|---|---|
| RF1 | Admission fee refund request | Reject (non-refundable) |
| RF2 | Annual charges refund request | Reject |
| RF3 | Library aur accounts clear, lab pending | Refund blocked |
| RF4 | Teenon clear, library fine 500, lab damage 1,500 | Refund 8,000 |
| RF5 | RF4 ki journal | Dr Security Liability 10,000. Cr Library Fine Income 500, Cr Lab Damage Income 1,500, Cr Cash 8,000 (balanced) |
| RF6 | Refund creator hi approve kare | Blocked (SoD) |

### 5.7 Promotion
| ID | Setup | Expected |
|---|---|---|
| PR1 | Overall 82.25, sab subjects >= 40, attendance 87.5 | Promote propose |
| PR2 | Overall 55, Maths 30 | Retain propose |
| PR3 | Overall 60, attendance 70 | Blocked, Principal/Academic Head decision |
| PR4 | Overall 70, Maths 38 | Retain propose (per-subject 40) |
| PR5 | Fail student par bulk approve chalao | Fail students bulk mein shamil nahi, alag review |
| PR6 | Coordinator fail student ka promotion approve kare | Reject (role decision_roles mein nahi) |
| PR7 | Academic Head conditional promotion (reason + conditions + parent notify) | Allow |
| PR8 | Conditional promotion bina reason | Reject |
| PR9 | Koi bhi config se fail student auto commit ka option | Nahi milta (invariant) |

### 5.8 Calendars
| ID | Expected |
|---|---|
| CAL1 | Matric aur Cambridge students ek hi din active, alag calendars |
| CAL2 | Oct family voucher mein dono programs ke bachon ki lines |
| CAL3 | Cambridge promotion run Jul/Aug mein, Matric mein Mar/Apr, aik dusre ko asar nahi |
| CAL4 | Matric ka enrollment date Cambridge calendar se bahar ho | Validation error |

---

## 6. Jo Maine Khud Decide Kiya (Aap Confirm Karein)

1. Late fee per din **Rs 50** (aap ne 50 ya 100 kaha), cap khali, per voucher.
2. GPA points (A* 4.0, A 3.7, B 3.0, C 2.0, D 1.0, E 0.5, F 0.0).
3. Per-subject pass 40% (pehle 33 tha).
4. Staff casual **1** per month (1 se 2 mein se).
5. Unapproved absence pehle leave balance khaye, phir salary.
6. Transfer par arrears purane campus mein, warning + override.
7. Student ki 15 leaves ke baad excess ke liye Coordinator approval.
8. Attendance minimum 75% promotion ke liye (v1.2 se).
9. Matric aur Cambridge ki term dates.
10. Staff medical leave 10 per saal (placeholder).

Yeh sab preset JSON mein hain, aur kisi ka jawab badalne par sirf value badlegi.

---

## 7. Next

1. `pk_general_v1.preset.json` ko `PresetSeeder` se load karo.
2. Tests 5.1 se 5.8 Pest mein likho, har test apni fixture config ke sath.
3. Phase 0 ke Config Engine mein naye policy types register karo: `late_fee`, `payroll_deduction`, `transfer_finance`, `refund_policy`, `absence_alert`, `leave_policy`, `promotion`.
4. Roles seeder mein Academic Head aur Lab In-charge add karo.
5. Asli school se sirf section 6 ki 10 cheezen confirm karwao.
