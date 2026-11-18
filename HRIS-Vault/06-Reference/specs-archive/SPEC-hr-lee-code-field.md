# SPEC: hr-lee-code-field

Status: DRAFT, awaiting John's confirmation. No code written.

## 1. Objective
HR users can view and edit an employee's Lee code in the Employee 201 employment form. Today only roster import writes `employees.lee_code`, and the employee's My Profile already shows it ("Not provided" when blank). Users: HR portal users and Admin (same access as the existing employment form).

Acceptance criteria
1. Employment form shows "Lee code" next to Employee number, prefilled from `employees.lee_code`.
2. Optional. Trimmed, max 30 chars (matches column). Longer input is rejected with a clear message, not silently cut.
3. Saved in the same transaction as the other employment fields; the change is written to the existing audit (`audit('Employees','UPDATE_EMPLOYMENT', ...)` with lee_code_before/after).
4. Blank is saved as NULL. Clearing the field clears it (HR is allowed to correct a wrong import value).
5. Employee My Profile shows the saved value; stays read-only.
6. Server enforces the same HR/Admin permission as the other employment fields; no new route.
7. Label and helper in English: "Lee code" / "Payroll code used by Lee. Leave blank if none."

## 2. Commands
- `php -l app/EmployeeRepository.php && php -l index.php`
- `php tests/employee_profile_test.php`, `php tests/employee_profile_db_test.php` (scratch DB only, never `pmbsi_hris`)

## 3. Project structure (expected files)
`index.php` (form near employee number, POST read at ~line 365), `app/EmployeeRepository.php` (update + audit), tests under `tests/`. No migration: column exists (`database/migrations/20261017_lee_code_payroll_group.sql`).

## 4. Code style
Follow the surrounding form: `<div class="field"><label for="...">` + `e()` on output, prepared statements only.

## 5. Testing strategy
Static/PHP-CLI tests, no browser. Cases: save value, clear value, 31 chars rejected, non-HR POST rejected.

## 6. Boundaries
- Always: CSRF, permission check, prepared query, audit entry, English text.
- Ask first: making it required, adding a unique index, changing import behaviour.
- Never: schema change, editing Lee code from the employee portal, overwriting other import fields.

## Open questions
None.
