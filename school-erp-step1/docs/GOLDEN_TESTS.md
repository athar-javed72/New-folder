# Golden Test Specs

"Golden" = the expected number is fixed in the test and any change to it needs a deliberate decision. All money is paisa (Rs 1 = 100).

## G1. Late fee (tests/Unit/LateFeeCalculatorTest.php)
Policy (preset `late_fee/default`): days 1 to 7 flat Rs 500; from day 8 add Rs 50 per day; cumulative; per voucher; no cap.
Voucher due date 10-Oct-2026 falls on a Saturday, so the effective due date is Monday 12-Oct-2026 (weekly off = Saturday, Sunday).

| Payment date | Days late | Expected |
|---|---|---|
| 12-Oct-2026 | 0 | Rs 0 |
| 13-Oct-2026 | 1 | Rs 500 (50,000) |
| 19-Oct-2026 | 7 | Rs 500 (50,000) |
| **20-Oct-2026** | **8** | **Rs 550 (55,000)** = 500 + 1 x 50 |
| **27-Oct-2026** | **15** | **Rs 900 (90,000)** = 500 + 8 x 50 |

Also covered: cap, grace days, percent_of_head in basis points, and `formula`/`slab` refusing to run instead of guessing.

## G2. Visiting teacher payroll (tests/Unit/ContractPayrollCalculatorTest.php)
Contract: `per_session`, rate Rs 800 (80,000).

| Case | Expected |
|---|---|
| 22 completed sessions | **Rs 17,600** (1,760,000) |
| 22 completed + 2 cancelled by school, rule `paid` | Rs 19,200 (1,920,000) |
| same, rule `unpaid` | Rs 17,600 |
| substituted session | substitute gets Rs 800, planned teacher gets Rs 0 |
| part-time hourly Rs 600/h, 20 x 45 min | Rs 9,000 (900,000) |
| `monthly` contract | refused (salary run, not session payroll) |

## G3. GPA: credit-weighted vs simple average (tests/Unit/GpaCalculatorTest.php)
Courses: (3.7, 3 credits), (3.0, 4 credits), (4.0, 3 credits).

| Method | Calculation | Expected |
|---|---|---|
| credit_weighted | (11.1 + 12.0 + 12.0) / 10 | **3.51** |
| simple_average | (3.7 + 3.0 + 4.0) / 3 | **3.57** |
| best_n (N = 2) | (4.0 + 3.7) / 2 | 3.85 |

The two main methods must differ. Rounding is half-up on exact integer maths (no float drift).

## Supporting suites
- PresetMapperTest (7): 17 policies, version 1.5, unique ids, `{}` preserved, checksum stable and tamper-sensitive, bad input rejected.
- PolicyResolverTest (7): campus override changes day 15 from Rs 900 to Rs 1,300; precedence organization < campus < program/contract_type < grade < course < employment_contract; effective dates (end exclusive); draft ignored; null, replace and list semantics.
- DatabaseConstraintsTest (18, needs PostgreSQL): enrollments, tenancy, users, contract overlap, append-only ledgers, override overlap, outbox.
