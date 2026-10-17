---
title: Glossary
type: reference
status: draft
tags: [meta, glossary, ph-statutory]
updated: 2026-10-17
---

# Glossary

HR and Philippine payroll vocabulary used across this vault. Terms are defined as they are used *in this system*, so specs and code can share one meaning.

## System / HR terms

| Term | Meaning here |
|---|---|
| **201 file** | The employee's complete personnel record: personal data, employment history, government IDs, documents, dependents. The subject of [[Employee-Records]]. |
| **Rank-and-file** | Non-managerial employee. Entitled to statutory premiums such as overtime and night differential. Distinction matters in [[Business-Rules]]. |
| **Managerial / supervisory** | Employees whose primary duty is management. Commonly exempt from OT and night differential — exemption must be a field, not an assumption. |
| **Probationary** | Employee under evaluation before regularisation. Max period under the Labor Code is normally six months unless an apprenticeship applies. |
| **Regularisation** | Conversion from probationary to regular status, with its own effectivity date and benefits start. |
| **Cut-off** | The attendance window a payroll run pays for, e.g. 11th–25th. Distinct from the pay date. |
| **Pay period** | The accounting period a payroll run belongs to. Semi-monthly for most PH employers. |
| **DTR** | Daily Time Record — the per-day attendance record with time in/out, breaks, and derived hours. Core of [[Attendance-and-DTR]]. |
| **Biometrics** | Fingerprint/face terminal producing raw punch logs. Raw logs are immutable; the DTR is derived. |
| **Undertime** | Hours short of the required shift hours, employee-caused. |
| **Tardiness / late** | Arrival after shift start plus grace period. |
| **Absent without leave (AWOL)** | Unexcused absence, unpaid, and a disciplinary trigger. |
| **Night differential** | Premium for hours worked during the statutory night window. |
| **Overtime (OT)** | Work beyond the normal eight hours, paid at a multiplier that depends on the day type. |
| **Rest day** | The employee's scheduled weekly rest day; work on it carries a premium. |
| **Day type** | Regular / rest day / special non-working / regular holiday / double holiday. The single biggest driver of pay multipliers. |
| **Final pay** | The last payment on separation: unpaid salary, pro-rated 13th month, unused convertible leave, less accountabilities. See [[Offboarding-and-Clearance]]. |
| **Clearance** | The sign-off process confirming the employee has returned assets and settled accountabilities. |
| **Payroll register** | The per-run summary of every employee's gross, deductions and net. The auditable output of a run. |
| **Payslip** | The per-employee statement of earnings and deductions for a period. |

## Statutory / government terms

| Term | Meaning here |
|---|---|
| **SSS** | Social Security System. Mandatory social insurance for private-sector employees; employee and employer both contribute, from a contribution schedule keyed on monthly salary credit. |
| **MSC** | Monthly Salary Credit — the bracketed salary figure SSS contributions and benefits are computed from, not the raw salary. |
| **PhilHealth** | National health insurance. Premium is a percentage of monthly basic salary within a floor and ceiling, shared employee/employer. |
| **Pag-IBIG / HDMF** | Home Development Mutual Fund. Mandatory savings; percentage of monthly compensation with a cap, employee and employer share. |
| **BIR** | Bureau of Internal Revenue. The tax authority; owns withholding tax rules and the forms below. |
| **Withholding tax on compensation** | Income tax deducted per payroll period using the BIR withholding tax table for the applicable payroll frequency. |
| **Annualisation** | Year-end recomputation of an employee's full-year tax against tax actually withheld, producing a refund or additional withholding. |
| **BIR Form 2316** | Certificate of Compensation Payment/Tax Withheld, issued per employee per year; also used as proof of prior-employer income for new hires. |
| **BIR Form 1601-C** | Monthly remittance return for income taxes withheld on compensation. |
| **Alphalist** | The BIR annual alphabetical list of employees and their compensation/tax, filed with the annual information return. |
| **De minimis benefits** | Small benefits exempt from income tax and withholding up to prescribed ceilings, e.g. rice subsidy, uniform allowance, medical cash allowance. Amounts above the ceiling become taxable. |
| **13th month pay** | Mandatory annual benefit equal to at least one-twelfth of basic salary earned in the calendar year, payable on or before 24 December. A portion of 13th month plus other benefits is tax-exempt up to a ceiling. |
| **SIL** | Service Incentive Leave — statutory minimum paid leave for qualifying employees, convertible to cash if unused. |
| **VL / SL** | Vacation leave / sick leave. Company-granted, not statutory by name; many employers grant these in place of bare SIL. |
| **Maternity leave** | Expanded maternity benefit, SSS-reimbursed, with a salary differential obligation on the employer. |
| **Paternity leave** | Paid leave for married male employees for the first four deliveries of the legitimate spouse. |
| **Solo parent leave** | Additional paid leave for employees with a valid Solo Parent ID. |
| **Special leave for women** | Paid leave following surgery for gynecological disorders, under the Magna Carta of Women. |
| **Bereavement leave** | Company-granted leave on the death of a covered relative. Not statutory. |
| **R-3 / contribution collection list** | SSS employer report of contributions per employee per period. |
| **RF-1** | PhilHealth Employer's Remittance Report. |
| **MCRF** | Pag-IBIG Membership Contribution Remittance Form. |
| **RA 10173** | Data Privacy Act of 2012. Governs how this system may handle employee personal data. See [[Security-and-Compliance]]. |
| **NPC** | National Privacy Commission — the regulator under RA 10173. |
| **TIN / SSS No. / PhilHealth No. / Pag-IBIG MID** | The four government identifiers every PH employee record needs. All are PII; see [[Employee-Records]]. |

> [!warning] NEEDS VERIFICATION
> Every rate, ceiling, bracket, floor and cap implied above is governed by the issuing agency's current circular and changes between years. None is stated numerically in this glossary on purpose. Confirm against the live issuance before coding — see [[PH-Labor-and-Payroll-Notes]].

Related: [[PH-Labor-and-Payroll-Notes]] · [[Payroll]] · [[Business-Rules]] · [[Leave-Management]] · [[Conventions]]
