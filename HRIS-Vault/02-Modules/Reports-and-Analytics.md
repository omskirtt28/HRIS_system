---
title: Reports and Analytics
type: module
status: draft
tags: [module, hris, v1, ph-statutory, compliance]
updated: 2026-10-17
---

# Reports and Analytics

## Purpose

2026-10-27 dashboard scope: [[2026-10-27-dashboard-incident-template]] / BR-788 removes the duplicate action strip while keeping the existing filters, six KPIs and lower panels. Three setup warnings target focused/expanded Ticket Viewers sections. Period picker and Download Report parity are explicitly deferred to their own patch; do not treat this cleanup as that implementation.

Generate, rather than re-key, every report the company owes to a government agency or needs for management. Two distinct families with different quality bars:

- **Statutory reports** — SSS, PhilHealth, Pag-IBIG, BIR. The bar is *exactness*: a figure that does not tie to the payroll register is a filing error with penalties attached. These must be generated from released payroll runs only.
- **Management reports** — headcount, turnover, attendance, cost. The bar is *usefulness and consistency*: the same question asked twice must give the same answer.

> [!note] The non-negotiable rule
> Statutory reports read **only from released payroll runs**. Never from draft runs, never from live employee data, never from a recomputation. If the report disagrees with the register, the report is wrong by definition. Every statutory report must display the run IDs it was built from.

## Scope

### In scope

- Statutory remittance reports: SSS contribution collection list, PhilHealth RF-1, Pag-IBIG MCRF
- BIR reports: monthly remittance return data, annual alphalist, per-employee 2316
- Payroll reports: register, payslip batch, payroll journal by cost center, YTD summary
- HR reports: headcount, movement, turnover with reasons, demographics, tenure distribution
- Attendance reports: tardiness, absence, OT hours, leave utilisation
- Compliance reports: 201 completeness, expiring documents, probationary employees due
- Export in CSV and XLSX, PDF for documents meant to be issued
- A report run log recording who generated what, when, and over which data

### Out of scope

- Actual electronic filing or portal submission → manual, outside the system
- General ledger posting → an export only
- Real-time dashboards beyond a small summary panel → later
- Predictive analytics → Phase 3 per [[Roadmap]]

## Statutory reports

> [!warning] NEEDS VERIFICATION
> Each agency prescribes a **file layout or form format**, and these change. Required columns, column order, file type, header content, and whether electronic submission is mandatory all depend on the agency's current issuance and sometimes on employer size. The descriptions below are of *content and source*, not of layout. Before implementing any of these: obtain the current official template or specification, confirm the required employer registration numbers appear in the header, and validate one period's output against a manually prepared filing. Do not infer a layout.

| Report | Purpose | Source | Grain |
|---|---|---|---|
| **SSS contribution collection list** (R-3 family) | Remit employee and employer SSS contributions | `payroll_deductions` where type = SSS, from released runs | One row per employee per month, with employee share, employer share and provident component where applicable |
| **SSS loan collection** | Remit SSS salary and calamity loan amortisations | `employee_loans` amortisations from released runs | One row per employee per loan per month |
| **PhilHealth RF-1** | Employer remittance report | `payroll_deductions` where type = PHILHEALTH | One row per employee per month, employee and employer share |
| **Pag-IBIG MCRF** | Membership contribution remittance | `payroll_deductions` where type = PAGIBIG | One row per employee per month, employee and employer share |
| **Pag-IBIG loan remittance** | MPL and calamity loan amortisations | `employee_loans` | One row per employee per loan per month |
| **BIR monthly remittance (1601-C data)** | Monthly withholding tax remitted | `payroll_deductions` where type = WITHHOLDING_TAX, grouped by tax month | Company totals plus supporting detail |
| **BIR alphalist** | Annual alphabetical list of employees with compensation and tax | All released runs for the calendar year, by employee | One row per employee per year, split into taxable, non-taxable and exempt components |
| **BIR 2316** | Per-employee certificate of compensation and tax withheld | Same as alphalist, per employee | One document per employee per year, including any prior-employer figures captured |

Critical reconciliation requirement: **the monthly total of each statutory report must equal the sum of the corresponding deduction lines in the released runs for that tax month** (BR-509, BR-601). The system shows the comparison; a mismatch blocks the report from being marked as filed.

## Management reports

| Report | Question it answers | Grain | Notes |
|---|---|---|---|
| Headcount | How many employees, where, in what status, as of a date | As-of snapshot by department, branch, status, employment type | Must be *as of a date*, derived from dated placement and status history — not a count of current rows |
| Movement | Who joined, moved and left in a period | Per employee per event | Joins hires, placement changes and separations |
| Turnover | What is the attrition rate and why | Rate per period with reason breakdown | Needs a stated formula — see BR-603 |
| Tenure distribution | How long do people stay | Buckets by years of service | Useful as a histogram |
| Demographics | Age, sex, civil status, location distribution | Aggregate only | **Aggregate only**; see the privacy note below |
| Attendance summary | Who is late, absent, or working heavy OT | Per employee and per department per period | Reads locked DTR snapshots |
| Leave utilisation | What leave is being used, what liability is accruing | Per leave type per department | Unused convertible leave is a financial liability worth surfacing |
| Payroll cost summary | What does payroll cost by department and cost center | Per cost center per period | Includes employer share, which is a real cost not visible on payslips |
| Payroll journal export | Accounting entry for the period | Per cost center per account hint | The export in US-609 |
| 201 completeness | Who cannot be paid and why | Per employee with missing items | Operational, used weekly by HR |
| Expiring documents | What needs renewing in the next 60 days | Per employee per document | Feeds [[Notifications]] |
| Probationary due | Whose probation ends soon | Per employee with date | Feeds [[Notifications]] |

> [!note] Privacy in reporting
> Demographic and health-adjacent reporting must be aggregate, with a minimum group size so a small department does not identify an individual. Medical certificates, VAWC leave and exit interview comments never appear in any report. See [[Security-and-Compliance]].

## Key Entities

| Entity | Purpose | Notable fields |
|---|---|---|
| `report_definitions` | Registry of available reports. | `id`, `code`, `name`, `category` (`STATUTORY`/`PAYROLL`/`HR`/`ATTENDANCE`/`COMPLIANCE`), `required_permission`, `supported_formats`, `active` |
| `report_runs` | Every generation, logged. | `id`, `report_definition_id`, `parameters_json`, `generated_by`, `generated_at`, `row_count`, `output_path`, `source_run_ids`, `reconciliation_status` |
| `statutory_filings` | What was actually filed, and when. | `id`, `agency` (`SSS`/`PHILHEALTH`/`PAGIBIG`/`BIR`), `report_code`, `period_from`, `period_to`, `amount_due`, `amount_remitted`, `filed_at`, `filed_by`, `reference_no`, `report_run_id`, `status` |
| `report_schedules` | Optional recurring generation. | `report_definition_id`, `frequency`, `recipients`, `next_run_at`, `active` |

## Core Flows

### Flow 1 — Generate a statutory remittance report

1. Payroll officer picks the report and the tax month on SCR-600.
2. System identifies all **released** runs whose `tax_month` matches. Any unreleased run in that month produces a blocking warning, since the filing would be incomplete.
3. Aggregates the relevant deduction lines per employee.
4. Runs reconciliation: report total versus the sum of deduction lines in those runs. A mismatch is displayed and blocks the filing record.
5. Produces the output and writes a `report_runs` row recording `source_run_ids`, so the figures are traceable years later.
6. Officer files externally, then records the filing in `statutory_filings` with the agency reference number.

### Flow 2 — Generate the annual alphalist and 2316 forms

1. HR or payroll selects the calendar year.
2. System aggregates all released runs for the year per employee, splitting compensation into taxable, non-taxable and exempt components per the earning type classification in [[Payroll]].
3. Captures prior-employer figures for mid-year hires from their submitted 2316, where recorded.
4. Validates: every employee with compensation has a TIN, every amount reconciles to the register, and the year's tax withheld ties to the monthly remittance totals.
5. Produces the alphalist file and one 2316 document per employee.
6. 2316 forms are issued to employees via [[Self-Service-Portal]] and acknowledged.

### Flow 3 — Headcount as of a date

1. User picks a date and grouping dimensions.
2. System resolves, for every employee, their status and placement **as of that date** from the dated histories (BR-204).
3. An employee hired after the date, or separated on or before it, is excluded.
4. The result is reproducible: the same date always returns the same count, which a count of "current" rows would not.

### Flow 4 — Export

1. Any report exports to CSV and XLSX; issued documents export to PDF.
2. Export of a report containing PII is itself logged as a sensitive-data access, with the row count (FR-612). An exported spreadsheet of 300 employees' salaries is the most likely data-loss vector in the whole system.

## Business Rules

- **BR-601** — Statutory reports read only from released payroll runs and must display the run IDs used.
- **BR-602** — A statutory report's monthly total must reconcile to the sum of the corresponding payroll deduction lines for that tax month; a mismatch blocks recording the filing.
- **BR-603** — Turnover rate uses a single declared formula, stated on the report: separations in the period divided by average headcount in the period. Any other formula must be a separate, differently named report.
- **BR-604** — Headcount and any other point-in-time figure resolves status and placement as of the requested date, never from current values.
- **BR-605** — Demographic and health-adjacent reports are aggregate only and suppress groups below a minimum size.
- **BR-606** — Every report generation is logged with actor, parameters, row count and source run IDs.
- **BR-607** — Export of PII is logged as a sensitive-data access event.
- **BR-608** — A filing recorded in `statutory_filings` is immutable; a correction is a new filing row referencing the original.

## Functional Requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-601 | The system shall generate the SSS contribution collection list for a tax month from released payroll runs. (US-601) | Should |
| FR-602 | The system shall generate the PhilHealth RF-1 remittance report. (US-602) | Should |
| FR-603 | The system shall generate the Pag-IBIG MCRF. (US-603) | Should |
| FR-604 | The system shall generate SSS and Pag-IBIG loan remittance reports from loan amortisations. | Should |
| FR-605 | The system shall generate monthly withholding tax remittance data grouped by tax month. | Should |
| FR-606 | The system shall generate the annual BIR alphalist with compensation split into taxable, non-taxable and exempt components. (US-604) | Should |
| FR-607 | The system shall generate a BIR 2316 per employee per year and issue it through the portal. (US-605) | Should |
| FR-608 | The system shall capture prior-employer compensation and tax from a new hire's submitted 2316 for inclusion in annualisation and the alphalist. | Should |
| FR-609 | The system shall reconcile every statutory report against the source payroll deduction lines and shall block recording a filing on mismatch. (BR-602) | Must |
| FR-610 | The system shall warn when an unreleased payroll run exists in the period being reported. (BR-601) | Must |
| FR-611 | The system shall record each report generation with actor, parameters, row count and source run IDs. (BR-606) | Must |
| FR-612 | The system shall log export of any report containing personal data as a sensitive-data access with the row count. (BR-607) | Must |
| FR-613 | The system shall record statutory filings with agency reference numbers, immutably. (BR-608) | Should |
| FR-614 | The system shall produce headcount by department, branch, status and employment type as of any date. (US-606, BR-604) | Should |
| FR-615 | The system shall produce a movement report of hires, transfers and separations for a period. | Should |
| FR-616 | The system shall produce a turnover report with a stated formula and a reason breakdown. (US-607, BR-603) | Could |
| FR-617 | The system shall produce an attendance summary of tardiness, absence and overtime hours per employee and department. (US-608) | Should |
| FR-618 | The system shall produce a leave utilisation report including the accrued liability for convertible leave. | Should |
| FR-619 | The system shall produce a payroll cost summary by cost center including employer share. | Should |
| FR-620 | The system shall export a payroll journal grouped by cost center for the accounting system. (US-609) | Should |
| FR-621 | The system shall produce a 201 completeness report naming the missing items per employee. | Must |
| FR-622 | The system shall produce reports of expiring documents and employees whose probation is ending. | Should |
| FR-623 | The system shall export any report to CSV and XLSX, and issued documents to PDF. (US-610) | Should |
| FR-624 | The system shall suppress aggregate groups below a configurable minimum size in demographic reports. (BR-605) | Should |
| FR-625 | The system shall restrict each report by a required permission. | Must |

## Edge Cases

| Case | Expected handling |
|---|---|
| Pay date in a different month from the cut-off | The `tax_month` on the payroll period governs which filing the amounts belong to, not the cut-off dates. This is the single most common statutory reporting error. |
| Two payroll runs in one tax month (regular plus 13th month) | Both are included. The report sums across runs, which is why `source_run_ids` is a list. |
| Employee with multiple compensation rows in a month | The statutory base is the monthly compensation per BR-515, not a per-cut-off figure. |
| Employee separated mid-year requesting 2316 | Generated from the released runs covering their employment. Possible because records are archived, not deleted. |
| Employee hired mid-year with prior employment | Alphalist and 2316 need prior-employer figures from their submitted 2316. If not captured, the report must flag it rather than silently understating. |
| Adjustment posted in a later month for an earlier month | The adjustment belongs to the tax month in which it was *paid*. Document this and apply it consistently, or remittances will never tie. |
| Agency changes the file layout mid-year | Report definitions must be versioned; an old period must still be reproducible in its old layout. |
| Report requested over a period containing a voided run | Voided runs are excluded entirely. The report should note that a voided run exists in the period. |
| Headcount for a date before any data was migrated | Return a clear "no data before X" rather than zero, which looks like a real answer. |
| Very large export timing out | Queue it and notify when ready rather than blocking the request. |
| Turnover for a department with 3 employees | One departure is 33%. Suppress or annotate small-denominator rates; they mislead. |

## Open Questions

- [ ] Which reports are actually filed, in which format, and electronically or over the counter? → Q21 in [[Open-Questions]]
- [ ] Are the current official templates for R-3, RF-1, MCRF and the alphalist available to work from?
- [ ] Is prior-employer 2316 data collected from new hires today?
- [ ] What accounting system consumes the payroll journal, and in what format?
- [ ] Which turnover formula does management actually want?
- [ ] Who is allowed to export PII, and should exports be watermarked with the exporter's identity?
- [ ] Is there a reporting period before which no data exists, and how is that communicated?

## Related

[[Payroll]] · [[Attendance-and-DTR]] · [[Leave-Management]] · [[Employee-Records]] · [[Org-Structure]] · [[Offboarding-and-Clearance]] · [[Self-Service-Portal]] · [[Security-and-Compliance]] · [[Auth-and-RBAC]] · [[Business-Rules]] · [[Data-Model]] · [[Screens]] · [[API-Surface]] · [[PH-Labor-and-Payroll-Notes]] · [[Glossary]]

Hub for HR requests: [[Employee-Tickets]] · UI rules: [[Design-System]]
