# Attendance and ticketing flow — October 3, 2026

This update replaces the mandatory Payroll Setup workflow with biometric import,
employee tickets, final Manager/ADL approval, and HR Payroll attendance review.
The company has not confirmed its salary, holiday, night premium, or payroll
deduction formulas, so monetary calculation is deferred. Existing payroll
configuration and finalized history are retained.

## Company workflow

1. HR maintains Employee 201: employee number, assigned site, linked employee
   account, hire date, and Reporting To / Manager or ADL.
2. HR Payroll selects a cutoff and imports each required biometric location.
   Review the preview and commit the import. Exact employee numbers match
   automatically; saved explicit biometric ID matches take priority.
3. Punches combine by employee and duty date across locations. A Head Office
   employee can have In / Lunch Out at Head Office and Lunch In / Out at Galleria.
   The employee's Employee 201 assignment and reporting line remain authoritative.
4. A day with missing or ambiguous punches is highlighted. The employee files
   TA for only the missing fields, OB for official business, or OT for overtime,
   with the required evidence and actual dates/times.
5. The assigned Head Office Manager or branch ADL gives final approval. There is
   no additional HR ticket approval for TA/PTA/OB/POB/OT/POT. Matching draft
   attendance updates automatically; raw biometric records remain unchanged.
6. HR Payroll opens Payroll Cutoffs. **Approved tickets for HR Payroll** lists
   final Manager/ADL approvals even before a matching biometric day exists.
   HR verifies actual conflicts, completes source uploads, and exports attendance
   CSV for the company's manual payroll computation.

Payroll Setup is removed from HR and administrator navigation. Old setup URLs
redirect to biometric import, and old salary/site/rule/holiday form submissions
are rejected in this workflow. Unknown IDs can still be matched through
Biometric Import → Match an unknown biometric ID, with the existing configure
permission; this is identity matching only.

## Attendance rules

- Head Office uses the user-confirmed 8 AM–5 PM shift and 60-minute break in
  `config/payroll_attendance.php`. Lunch is flexible: 1:30 PM–2:30 PM works in
  the same way as 12 PM–1 PM. Both lunch punches are required.
- A confirmed existing branch schedule can still help interpret its logs. A
  branch without a schedule can import and review punches and tickets. No
  schedule is required to file a ticket; no unknown late/undertime rule is guessed.
- Manager/ADL routing comes from Employee 201 Reporting To. Existing branch ADL
  assignments are retained as a fallback when there is no reporting account.
  Approvers need active linked accounts and the corresponding approval permission.
  No employee or administrator is assigned automatically; self-approval is blocked.
  Leave retains its existing separate workflow and permission checks.
- An approved TA fills missing slots. A proposed change conflicting with an
  existing log keeps the biometric value until HR records a verification. Multiple
  conflicting approved corrections also require verification.
- A date before the employee's hire date is flagged for HR review. The update
  does not backdate Employee 201 or silently generate pre-employment attendance.
- Dates with no logs, approved attendance tickets, or approved leave stay
  unclassified for manual work-calendar review. No automatic absence, rest day,
  holiday classification, or salary deduction is introduced.
- Recorded work hours are the two actual work intervals excluding lunch. They
  include any matched OT time; the OT column is a subset, not an extra amount
  to add to those hours. A partial day with missing punches has no complete
  verified work-hour total yet.
- OT tickets retain approved date/time windows. Matching shows hours supported
  by complete attendance intervals; overlaps or unsupported intervals need review.
  Night/holiday categorization and rates, salary amounts, deductions, and net
  payslips are handled manually for now.
- POB/POT historical unpaid claims appear in their processing cutoff for manual
  amount review. Original finalized attendance and historical amounts stay locked.

## Cutoff review and export

For an existing draft cutoff, click **Refresh attendance** once after applying
this patch. Imports and future approvals also rebuild matching attendance.
Previously calculated draft wage amounts become NULL rather than zero; they are
not shown as payable amounts. Already finalized snapshots are not recalculated.

Confirm that all required device locations are uploaded through the cutoff end
before **Finalize attendance**. Unknown IDs, pending/returned tickets, missing
punches/conflicts, and approved tickets that did not apply must be addressed.
Finalization locks attendance evidence; it does not confirm salaries or mark
claims paid. Dates with no source records remain unclassified and visible for HR's
manual work-calendar review. There is no mandatory salary-rate configuration.

The CSV includes original and effective punches, recorded work hours, matched OT,
issues, and approved ticket references/review notes. Separate ticket rows ensure
that approved claims and tickets without a matching day remain visible. Monetary
columns are omitted. Historical locked exports label their old saved hours.

Committed original upload files still expire after 48 hours. Parsed punches,
attendance, tickets, import metadata, and audit history remain. Preview files
expire after two hours. Existing cleanup behavior is retained.

## Installation and rollback

No new SQL migration or database import is required for this update. It applies
over the existing payroll and multi-location patches already installed. Do not
re-import `database/schema.sql` or drop existing payroll tables.

The live patch ZIP contains only updated/new runtime files and this guide:

- `app/bootstrap.php`
- `app/EmployeeRepository.php`
- `app/PayrollRepository.php`
- `app/PayrollCalculator.php` (the earlier TA-preservation dependency)
- `app/PayrollAttendanceReview.php` (new)
- `app/PayrollAttendanceViews.php` (new)
- `app/PayrollAttendanceService.php`
- `app/PayrollModule.php`
- `app/View.php`
- `config/payroll_attendance.php` (new; confirmed attendance policy only)
- `index.php`

Copy the files to the same relative paths in the existing HRIS installation.
Upload the new classes/views/configuration before replacing their callers,
then replace bootstrap, module, view, and index files. Do not replace credentials,
the complete config folder, uploads, storage, or the database. Existing sessions
can refresh; no new accounts or permissions are created by the patch.

Localhost runtime files are updated directly under `C:/xampp/htdocs/HRIS_system`.
Pre-patch code backups and a file/hash manifest are saved outside the web root
under the workspace `tmp/localhost-backups/attendance-review-*` folder.
Rollback requires restoring the prior callers and services together from that
backup. No tables are removed. Any draft attendance refreshed under the new
flow must be recalculated by the restored code if the old monetary flow is used.

## Verification status

Source changes and installation file hashes were reviewed. Application tests,
PHP lint, browser checks, and database mutation commands were deliberately not
run because the user requested to do the testing themselves. Manager approval,
import, HR review, export, and finalization need the user's runtime testing.
