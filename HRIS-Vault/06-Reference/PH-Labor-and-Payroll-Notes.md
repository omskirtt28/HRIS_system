---
title: PH Labor and Payroll Notes
type: reference
status: draft
tags: [reference, ph-statutory, payroll, needs-verification]
updated: 2026-10-17
---

# PH Labor and Payroll Notes

## Purpose

Working reference on how Philippine payroll mechanisms work, so the specs and tests make sense. It records **mechanisms and sources to check**, not figures.

> [!warning] NEEDS VERIFICATION
> Nothing here is legal or tax advice, and no rate, bracket, ceiling or percentage is stated. Confirm every item against the issuing agency's current circular or the latest DOLE guidance, record the circular number and effectivity date in the table at the bottom, and load it as a `rate_tables` row ([[ADR-003-Payroll-Engine-Approach]]). Last human verification: **none yet**.

## Sources to consult

| Topic | Authority | What to obtain |
|---|---|---|
| Labor standards (hours, OT, holidays, leave, 13th month, final pay) | DOLE, Labor Code of the Philippines, DOLE handbook on workers' statutory benefits | Current text and any wage orders applicable to your region |
| Minimum wage | Regional Tripartite Wages and Productivity Boards (RTWPB) | Current wage order for the employer's region |
| SSS contributions | Social Security System | Current contribution schedule circular |
| PhilHealth | PhilHealth | Current premium circular |
| Pag-IBIG | Home Development Mutual Fund (HDMF) | Current contribution circular |
| Income tax withholding, de minimis, 13th-month exemption ceiling | Bureau of Internal Revenue (BIR) | Current withholding tax table, revenue regulations on fringe/de minimis benefits |
| Data privacy | National Privacy Commission | See [[Security-and-Compliance]] |

## Mechanisms

### Pay basis and rate derivation

- Employees may be monthly-paid or daily-paid. The **divisor** (working days per year or per month) converts monthly pay to a daily rate, and the daily rate to an hourly rate. Different divisors reflect whether rest days and holidays are already paid in the monthly salary. This is the most disputed area in PH payroll (Q8). Store the divisor on the employee's compensation row with the basis, never as a global constant.
- Hourly rate = daily rate / hours per day (commonly eight, but make it a field).

### Premiums on worked time

- **Overtime**: hours beyond the normal daily hours, paid at a premium over the hourly rate; the premium differs on ordinary days, rest days, special non-working days, regular holidays, and combinations (BR-304).
- **Night shift differential**: an additional percentage for hours within a statutory night window; combines with OT (BR-306).
- **Holiday pay**: regular holidays are paid even if unworked (for covered employees), with a higher premium if worked; special non-working days follow "no work, no pay" unless company policy says otherwise, with a premium if worked. Applicability depends on employee category and pay basis.
- **Exemptions**: managerial employees and certain others are not entitled to some premiums. The exemption is an explicit employee flag (BR-208), per company policy and law (Q7).

### Tardiness, undertime, absences

Deducted proportionally from the rate derived above. Grace periods are company policy (BR-301). Absences for unpaid days reduce pay by the daily rate.

### Statutory contributions

- SSS, PhilHealth and Pag-IBIG each use a **compensation base** mapped to a contribution through an agency table or rate-with-floor-and-ceiling, split into employee and employer shares. The base (basic pay vs gross, monthly vs per period) differs per agency and is a configuration choice per rate table, which is why the engine stores `extra_json` on rows.
- Deduction timing (once a month vs split across both cut-offs) is a company policy field; the **monthly** contribution is what the agency table defines.
- Maternity and sickness benefits are advanced by the employer and reimbursed through SSS in the cases the law provides; see [[Leave-Management]] for the flow.

### Withholding tax on compensation

- Compute taxable compensation = gross compensation minus non-taxable items (statutory contributions, de minimis within ceilings, exempt portion of 13th month and other benefits) .
- Apply the BIR withholding table for the payroll frequency used, which yields a fixed amount plus a percentage of the excess over a bracket floor.
- Year-end: annualisation reconciles tax withheld against tax due; the employer issues **BIR Form 2316** and files an alphalist. Annualisation automation is Phase 3 ([[Roadmap]]).
- Taxability classification of each earning is a field, not a guess (Q12).

### 13th month pay

- Mandatory for rank-and-file employees who worked at least a minimum period in the calendar year. Computed from total basic salary earned in the year divided by twelve. Which earnings count as "basic" is a policy-and-law question; premiums, OT and allowances are generally excluded unless integrated into basic. Verify. Separation mid-year means a prorated amount in final pay. See [[Payroll]].
- Paid on or before the statutory deadline.

### Service incentive leave and other leave

- Service incentive leave for eligible employees after a qualifying service period, convertible to cash if unused under the rules. Other statutory leaves (maternity, paternity, solo parent, VAWC, special leave for women, and others) each have eligibility and duration rules. Company VL/SL are policy. Full list and entities in [[Leave-Management]]. Q6.

### Final pay

Last salary earned, prorated 13th month, cash conversion of eligible unused leave, less accountabilities and loans, subject to clearance policy; issue COE on request and BIR 2316. Timing expectations come from DOLE guidance. See [[Offboarding-and-Clearance]].

### De minimis benefits

Small benefits (e.g. certain allowances, rice subsidy, uniform, medical, gifts) are exempt from tax up to ceilings set by BIR; amounts above the ceilings join taxable compensation. Ceilings are per benefit type and may change. Store ceilings in rate tables (Q26).

## Calendar mechanics

| Item | Mechanism |
|---|---|
| Holidays | Annual proclamations for regular and special days, plus local holidays; kept in the holiday calendar ([[Org-Structure]]); typed per day for premium selection |
| Cut-off vs pay date | Cut-off defines the DTR window; pay date defines the tax month and the posting of contributions. Keep `tax_month` on `payroll_periods` |
| Remittance deadlines | Each agency has monthly deadlines; reports module reminds, does not assume (verify) |

## Verification register

Fill one row per figure the system uses. A row blocks the matching rate table version from being verified.

| Table code | Figure | Value | Source circular | Effectivity | Verified by | Verified on |
|---|---|---|---|---|---|---|
| SSS | contribution schedule | pending | | | | |
| PHILHEALTH | premium rate, floor, ceiling | pending | | | | |
| PAGIBIG | rate and compensation cap | pending | | | | |
| BIR_WITHHOLDING | table per frequency | pending | | | | |
| BIR_DEMINIMIS | ceilings | pending | | | | |
| PAY_RULES | OT multipliers, night window, holiday rules | pending | | | | |
| MIN_WAGE | regional wage order | pending | | | | |

## Related

[[Payroll]] · [[Business-Rules]] · [[Glossary]] · [[Open-Questions]] · [[Leave-Management]] · [[Reports-and-Analytics]] · [[Decision-Log]]
