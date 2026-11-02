# Todo: bulk portal accounts (plan: tasks/plan-employee-bulk-accounts.md)
- [x] T1 Service issueForExisting/issueMissing + update keeps user_id. Verify: tests/employee_bulk_account_test.php
- [x] T2 POST actions generate_employee_account / generate_missing_accounts + permission/CSRF. Verify: HTTP test
- [x] T3 201 file: replace dropdown with Generate account. Verify: render check, no PHP warnings
- [x] T4 Directory notice + Generate all. Verify: count 0 hides, permission hides
- [x] T5 Remove link-existing UI/handler/helper. Verify: grep clean, check.php task
- [x] T6 Sweep + vault sync + checkpoint. Verify: vault files listed
