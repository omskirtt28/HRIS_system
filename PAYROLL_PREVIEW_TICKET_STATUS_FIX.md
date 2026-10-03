# Preview and approved-ticket status follow-up

Apply this over the October 3 attendance/ticketing simplification already installed.
No new SQL is required. This patch contains four changed PHP files and this guide.

## Clear flow

1. HR Payroll uploads a biometric export and clicks **Preview file**.
2. On the preview, HR enters the export's completed-through date and clicks
   **Import logs into attendance**. Matched preview rows are not yet saved punches.
3. Open **Payroll Cutoffs → Daily Attendance** or **View attendance + approved
   tickets** to review combined device logs and final Manager/ADL-approved tickets.
4. The employee files only missing times or the appropriate OB/OT request.
   Final approval updates the matching attendance day automatically.
5. HR Payroll reviews unresolved issues and exports the attendance for manual
   salary/OT computation. Confirm all required source uploads before finalizing.

Original biometric file rows remain unchanged. An approved ticket can create a
corrected attendance day even when the original file has no row for that date.
The request now links to that day through **View updated attendance**.

## Status fix

Complete approved TA/OB attendance is APPLIED based on the matching day, rather
than being held behind cutoff-wide biometric coverage confirmation. Partial
corrections waiting for remaining raw punches stay WAITING_FOR_IMPORT; conflicting
or unresolved attendance stays NEEDS_REVIEW. Payroll finalization still requires
complete source coverage. Missing dates do not become fabricated biometric rows.

Existing stale display statuses are read from the actual saved day and linked
ticket source. No database update is needed merely to show the correct status.
Import or Refresh attendance persists updated statuses through the existing
authorized application actions.

## Installation

Copy these four files to the same relative paths on the existing live installation:

- `app/PayrollAttendanceService.php`
- `app/PayrollModule.php`
- `app/PayrollAttendanceViews.php`
- `index.php`

Localhost is patched directly under `C:/xampp/htdocs/HRIS_system`. Pre-patch code
is backed up outside the web root under the workspace
`tmp/localhost-backups/preview-ticket-status-*`. Restore the four files together
to roll back this follow-up. No upload files, employee records, approvals, wages,
or schema are changed by installing the patch.

## Evidence

Read-only inspection identified an uncommitted import preview and a saved,
corrected daily attendance record linked to its approved ticket. Source changes
were reviewed and installed file hashes checked. No application tests, PHP lint,
browser checks, or direct database mutations were run, at the user's request.
