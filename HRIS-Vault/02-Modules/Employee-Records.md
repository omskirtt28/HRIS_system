---
title: Employee Records
type: module
status: draft
tags: [module, hris, mvp]
updated: 2026-10-17
---

# Employee Records

## Purpose

The authoritative record of every person employed by the company — the digital 201 file. Every other module references an employee by ID and never duplicates their data. If two places disagree about an employee's name, department or SSS number, this module wins.

Two properties matter more than features:

1. **Temporal correctness.** Employment status, compensation and placement all *change over time*. A record that only stores "current" values destroys the ability to reproduce a past payroll run — which breaks the core goal in [[Vision-and-Scope]]. These are dated histories, not fields.
2. **Completeness gating.** An incomplete record must not reach [[Payroll]]. Missing government IDs are discovered at remittance time today; here they are blocked at inclusion time.

## Scope

### In scope

- Personal data: names, birth date, civil status, sex, contact details, addresses, emergency contact
- Government identifiers: TIN, SSS, PhilHealth, Pag-IBIG MID
- Employment data: hire date, employment status with history, position, department, branch, reporting manager, employment type
- Compensation history: basic pay, pay basis, allowances, with effectivity dates
- Dependents and beneficiaries
- Document attachments: contract, government IDs, NBI clearance, medical, certificates
- Employee-initiated change requests for sensitive fields
- Bulk roster import with dry-run validation
- Record completeness status and expiry monitoring
- Full change history per record

### Out of scope

- Applicant data before hiring → [[Recruitment-and-Onboarding]] (separate entity; an applicant is not an employee)
- User accounts and permissions → [[Auth-and-RBAC]] (a login is not a person)
- Department and position master data → [[Org-Structure]] (referenced by ID, never copied)
- Separation processing and clearance → [[Offboarding-and-Clearance]]
- Pay computation → [[Payroll]]
- Attendance → [[Attendance-and-DTR]]

> [!note] Three identities, never merged
> `applicants`, `employees` and `users` are distinct. One person may be all three at different times. An applicant who is hired produces a *new* employee row linked back to the application; an employee who needs portal access gets a *linked* user row. Collapsing these is the most expensive mistake available in this schema.

## Key Entities

See [[Data-Model]] for full field lists and the ER diagram.

| Entity | Purpose | Notable fields |
|---|---|---|
| `employees` | The person. One row per employment relationship. | `id`, `employee_no`, `last_name`, `first_name`, `middle_name`, `suffix`, `birth_date`, `sex`, `civil_status`, `hire_date`, `separation_date`, `current_status_id`, `is_ot_exempt`, `pay_basis`, `created_by` |
| `employee_contacts` | Phone, email, addresses. Separated because an employee has several. | `employee_id`, `contact_type`, `value`, `is_primary` |
| `employee_government_ids` | One row per ID type, so missing ones are queryable. | `employee_id`, `id_type` (`TIN`/`SSS`/`PHILHEALTH`/`PAGIBIG`), `id_number`, `verified_at` |
| `employee_status_history` | Dated employment status changes. | `employee_id`, `status_id`, `effective_from`, `effective_to`, `reason`, `created_by` |
| `employee_placements` | Dated department/branch/position/manager assignment. | `employee_id`, `department_id`, `branch_id`, `position_id`, `manager_employee_id`, `effective_from`, `effective_to` |
| `employee_compensation` | Dated pay. Never updated in place. | `employee_id`, `basic_amount`, `pay_basis` (`MONTHLY`/`DAILY`/`HOURLY`), `effective_from`, `effective_to`, `approved_by` |
| `employee_allowances` | Recurring allowances, each flagged taxable or de minimis. | `employee_id`, `allowance_type_id`, `amount`, `is_taxable`, `effective_from` |
| `employee_dependents` | Dependents and beneficiaries. | `employee_id`, `full_name`, `relationship`, `birth_date`, `is_beneficiary` |
| `employee_documents` | Attachment metadata; the file lives in storage. | `employee_id`, `document_type`, `file_path`, `expires_on`, `uploaded_by`, `uploaded_at` |
| `employee_change_requests` | Self-service edits awaiting HR approval. | `employee_id`, `field_path`, `old_value`, `new_value`, `status`, `requested_by`, `decided_by` |
| `employee_bank_accounts` | Payroll disbursement details. Highly sensitive. | `employee_id`, `bank_id`, `account_number_enc`, `account_name`, `is_active` |
| `employment_statuses` | Master: probationary, regular, project-based, fixed-term, casual, seasonal, consultant. | `code`, `name`, `is_regular`, `requires_end_date` |

## Core Flows

### Flow 1 — Create an employee from scratch

1. HR admin opens SCR-200 and chooses **New employee**.
2. Enters personal data. System validates birth date is in the past and the employee is of legal working age.
3. Enters employment data: hire date, employment status, position, department, branch, reporting manager.
4. System generates `employee_no` from the configured pattern, or accepts a manual one if unique.
5. Enters government IDs. Each is format-validated but may be left blank, creating a completeness gap.
6. Enters compensation with an effectivity date, which defaults to the hire date.
7. On save, in one transaction: `employees` row, initial `employee_status_history` row, initial `employee_placements` row, initial `employee_compensation` row, and an audit entry.
8. Record completeness is recomputed. If incomplete, the employee appears on the completeness exception list and is flagged as not payroll-ready.

### Flow 2 — Convert an applicant to an employee

1. [[Recruitment-and-Onboarding]] marks an application as hired.
2. HR admin triggers **Convert to employee**.
3. System pre-fills personal data from the applicant record and shows it for confirmation — it does not silently copy.
4. HR completes the employment and compensation sections, which recruitment does not hold.
5. New employee row is created with `source_application_id` retained for traceability. The applicant record is *not* deleted or mutated.
6. Onboarding checklist is instantiated.

### Flow 3 — Regularisation

1. [[Notifications]] fires a probation-ending alert on a configured lead time before the probationary period ends (US-713).
2. HR admin reviews and records the decision: regularise, extend, or do not regularise.
3. On regularise: the open `employee_status_history` row is closed with `effective_to`, and a new row opens with `status = REGULAR` and `effective_from`.
4. If benefits or pay change on regularisation, a new `employee_compensation` row is created with the same effectivity date.
5. The next payroll run picks up the new status and rate automatically because it resolves both as-of the cut-off dates — see BR-501.

### Flow 4 — Employee-requested change

1. Employee edits a field in [[Self-Service-Portal]] (SCR-740).
2. Non-sensitive fields — mobile number, personal email, emergency contact — are applied immediately and audited.
3. Sensitive fields — name, birth date, civil status, government IDs, bank account — create an `employee_change_requests` row instead and remain unchanged.
4. HR admin reviews on SCR-205, approves or rejects with a reason.
5. On approval the change is applied in a transaction with the request row's decision and an audit entry. Employee is notified.

### Flow 5 — Bulk roster import

1. HR admin uploads a spreadsheet on SCR-210.
2. System parses and validates **every** row without writing anything: required fields, ID formats, duplicate `employee_no`, unknown department or position names, invalid dates.
3. A dry-run report shows accepted and rejected rows with a reason per rejection.
4. HR admin corrects the file and re-uploads, or confirms the import of accepted rows only.
5. On confirm, accepted rows are inserted in a transaction with an import batch ID so the whole batch can be reversed.

### Flow 6 — Document upload and expiry monitoring

1. HR admin attaches a document, choosing a type and an optional expiry date.
2. File is stored outside the web root with a generated name; metadata only goes in the database.
3. A scheduled job surfaces documents expiring within the configured window to the HR dashboard and [[Notifications]].

## Business Rules

Defined in [[Business-Rules]], referenced here:

- **BR-201** — `employee_no` is unique and immutable once a payroll run has referenced the employee.
- **BR-202** — An employee is *payroll-ready* only when: all four government IDs are present, an active compensation row covers the cut-off, and an active placement exists. Enforced at payroll inclusion, not at save.
- **BR-203** — Compensation, status and placement are never updated in place. A change closes the current row and opens a new one; effectivity ranges must not overlap or gap.
- **BR-204** — Resolving an employee's status, rate or placement for any computation is always *as of a date*, never "current".
- **BR-205** — `separation_date` makes the employee ineligible for payroll runs whose cut-off starts after that date, but they remain fully visible for history and final pay.
- **BR-206** — A fixed-term or project-based status requires an end date; regular must not have one.
- **BR-207** — Government ID formats are validated structurally only. The system never asserts an ID is genuine.
- **BR-208** — OT and night-differential exemption is an explicit boolean on the employee, defaulting to false, never inferred from position title. See Q7 in [[Open-Questions]].

## Functional Requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-201 | The system shall maintain one employee record per employment relationship, identified by a unique immutable `employee_no`. (US-201) | Must |
| FR-202 | The system shall store personal data, employment data, government IDs, compensation, dependents and documents for each employee. (US-201) | Must |
| FR-203 | The system shall record employment status changes as a dated history with non-overlapping effectivity ranges. (US-203) | Must |
| FR-204 | The system shall record compensation changes as a dated history and shall never overwrite a prior compensation row. (US-204) | Must |
| FR-205 | The system shall record department, branch, position and reporting manager as a dated placement history. (US-111, US-115) | Must |
| FR-206 | The system shall resolve an employee's status, compensation and placement as of a supplied date for any computation. (BR-204) | Must |
| FR-207 | The system shall compute a payroll-readiness flag per employee and shall prevent a non-ready employee from being included in a payroll run. (US-202) | Must |
| FR-208 | The system shall list employees with incomplete records, naming the missing items. (US-202) | Must |
| FR-209 | The system shall store each government ID as a separate typed row so missing IDs are queryable. | Must |
| FR-210 | The system shall structurally validate TIN, SSS, PhilHealth and Pag-IBIG number formats on entry. (BR-207) | Should |
| FR-211 | The system shall allow document attachment with a type and optional expiry, storing files outside the web root. (US-205) | Must |
| FR-212 | The system shall record dependents and beneficiaries with relationship and birth date. (US-208) | Should |
| FR-213 | The system shall allow an employee to update non-sensitive contact fields directly. (US-206) | Should |
| FR-214 | The system shall route employee edits to sensitive fields through an HR approval queue, leaving the record unchanged until approved. (US-207) | Should |
| FR-215 | The system shall report contracts, probationary periods and documents expiring within a configurable window. (US-209) | Should |
| FR-216 | The system shall import an employee roster from a spreadsheet, producing a dry-run validation report before any write. (US-210) | Should |
| FR-217 | The system shall allow reversal of an entire import batch. | Should |
| FR-218 | The system shall log every create, update and delete on employee data with actor, timestamp, and before/after values. (US-104) | Must |
| FR-219 | The system shall display a full chronological change history for one employee record. (US-211) | Could |
| FR-220 | The system shall store bank account numbers encrypted at rest and shall mask them in all list views. | Must |
| FR-221 | The system shall pre-fill an employee record from a hired applicant without mutating the applicant record. (US-802) | Must |
| FR-222 | The system shall hold an explicit OT/night-differential exemption flag per employee. (BR-208) | Must |
| FR-223 | The system shall support archiving an employee record so it leaves active lists without being deleted. | Should |
| FR-224 | The system shall prevent deletion of any employee referenced by a released payroll run. | Must |

## Edge Cases

| Case | Expected handling |
|---|---|
| Employee rehired after separation | New `employees` row, linked via `previous_employee_id`. Do **not** reopen the old row — tenure, leave accrual and 13th month all restart, and the old record must stay frozen for its payroll history. |
| Two employees with identical full names | Allowed. `employee_no` disambiguates. The UI must show `employee_no` and birth date wherever a name is selected. |
| Employee with no middle name | Must be representable as explicitly empty, not as a blank that looks like missing data. Government forms care. |
| Name change on marriage | A change request on a sensitive field. The old name is retained in history because past payslips and 2316 forms bear it. |
| Government ID issued after hiring | Expected and common. The employee is simply not payroll-ready until it is entered. |
| Mid-cut-off transfer between departments | Placement history has two rows. Payroll must attribute cost by day, not by the placement at run time. See BR-204 and [[Payroll]]. |
| Mid-cut-off salary increase | Two compensation rows within one cut-off. Payroll prorates by effectivity date. This is a common bug source — test it explicitly. |
| Backdated hire date entered after payroll already ran | System must warn and refuse to silently alter a released run. Correction becomes an adjustment per Q9 in [[Open-Questions]]. |
| Employee is also a manager being separated | All employees reporting to them must be reassigned before separation completes. See [[Offboarding-and-Clearance]]. |
| Duplicate record created by accident | Needs a merge tool, which is dangerous. MVP: prevent via `employee_no` uniqueness and a duplicate-name warning at creation. Merge is deferred. |
| Employee under 18 | Allowed with a flag; some statutory rules differ. Flag it rather than block it. |
| Consultant or contractor paid without statutory deductions | Employment type drives whether statutory deduction applies. Must be a type attribute, not a per-run manual exclusion. |

## As built: employee master and 201 file rules (moved from the retired root RULES.md, 2026-10-25)

- `employee_no` is the stable human-readable identifier and unique. One employee-portal user links to at most one employee (unlinking by authorised HR is allowed). `employees.id` is the permanent parent for every child record; future modules reference it and never duplicate identity data.
- Department, position, branch and employment type are master-data references, never hard-coded labels; position/department selections must be consistent. Creating or editing an employee needs `employees.manage` and is audited. Changes to department, position, branch, employment type or status write an `employee_employment_history` event; history is never overwritten. Deleting a 201 child record is an audited HR action.
- Documents live outside the public directory and are reached through authenticated routes; uploads are allow-listed by extension/MIME and size, stored under randomized names; profile photos accept JPG, PNG or WEBP only. A government ID type is unique per employee (saving the same type updates it).
- Table list: [[Data-Model]] ("tables outside the design inventory"). Ticket viewers panel on the Employment tab: [[Employee-Tickets]].

## Open Questions

- [ ] Does `employee_no` follow an existing company pattern that must be preserved? (affects BR-201)
- [ ] For an agency/manpower setup, does an employee belong to the company or to a client deployment? → [[ADR-002-Multi-Tenancy]]
- [ ] Which 201 documents are mandatory before payroll-ready, beyond government IDs?
- [ ] How much historical employee data is migrated? → Q17 in [[Open-Questions]]
- [ ] Is a record merge tool needed for existing duplicate data?
- [ ] Who may see bank account details — payroll only, or HR too?

## Related

[[Org-Structure]] · [[Attendance-and-DTR]] · [[Leave-Management]] · [[Payroll]] · [[Recruitment-and-Onboarding]] · [[Offboarding-and-Clearance]] · [[Self-Service-Portal]] · [[Auth-and-RBAC]] · [[Data-Model]] · [[Business-Rules]] · [[Security-and-Compliance]] · [[Screens]] · [[API-Surface]] · [[ADR-002-Multi-Tenancy]]

Hub for HR requests: [[Employee-Tickets]] · UI rules: [[Design-System]]

## 2026-10-28 - Employee work company
- Source patch: 2LOG / PMBSI work assignment in Add and Edit, conditional fields and 2LOG HR position filtering; legacy records remain unassigned until HR selects. PMBSI remains legal employer.
- Additive migration and guarded rollback prepared; not applied. PHP/JS syntax checked; runtime, database and browser acceptance not verified. No deployment or commit.


## 2026-10-28 - PMBSI position correction
- Add/Edit now filter PMBSI to exact HR / Human Resource / Human Resources existing master aliases using the same eligibility helper as save validation. 2LOG excludes HR variants including singular Human Resource. Unassigned remains optional.
- Missing active HR master shows a setup message; no invented ID or master-data write. Initial incompatible saved position requires explicit correction; company switching clears incompatible selections.
- Existing work-company migration and other pending changes retained; no database or browser execution. Runtime and actual HR-master availability remain unverified.

## 2026-10-28 - Work company verification
- Migration cycle passed on isolated DB (fresh mysqldump restore); live DB untouched. Open question: "Human Resources Assistant" is the only active HR-type position and is excluded from both PMBSI (exact aliases) and 2LOG (HR variants); John to decide.

- Applied to localhost pmbsi_hris (John: "patch mo na"): `employees.work_company VARCHAR(10) NULL`. Basis: verified dump before_stageb_118 (restore verified), isolated-DB migration cycle + repository update test OK. After: 370 employees, department/position counts unchanged (370/370), work_company all NULL (legacy unassigned). Browser check not done. No commit/push.

## Organization Setup add forms (2026-10-28)
Codes auto-generated from names; HR position for PMBSI is added by HR via Organization Setup > Positions (name "Human Resource"). Employee form shows a link when none is active.

## 2026-10-29 - Account linking and PMBSI position
- Employee forms include active unlinked employee accounts and, for Super Admin, HR/Admin accounts. Existing links remain visible; changes validate account status and HR/Admin restrictions on the server. Recruitment keeps its employee-portal-only picker.
- Users requires an explicit employee or unlink choice. The shared company filter accepts Human Resource PMBSI and Human Resources PMBSI. Human Resources Assistant remains outside the exact PMBSI rule and is excluded from 2LOG; business classification remains open.
- PHP lint and diff check passed. No browser, runtime or database verification; no DB write, deployment or commit. See [[2026-10-29-employee-account-linking]].

## 2026-10-29 - PMBSI departments (prepared)
- Employee 201 Add/Edit supports PMBSI departments, company-matching validation and existing employment history. Existing unchanged legacy/retired assignments remain editable. Roster import is a separate flow and may produce a company mismatch. Runtime and DB unverified.
