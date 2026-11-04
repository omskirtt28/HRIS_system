# Spec: Position/Department mismatch + keep typed input after an error

Status: DRAFT 2026-11-04, waiting for John's answers (Open Questions) and approval. No code written.
Related: `SPEC-retail-manager.md` (migration 032 created the position), `CONSTRAINTS.md`.

## Capability map (Phase 0)
Two independently testable capabilities, one request:

| Module id | Responsibility | Depends on |
|---|---|---|
| position-dept-guard | Position list/validation agrees with the chosen Department (Retail Manager error) | none |
| form-keep-input | A failed save, or an accidental refresh, never loses what was typed | none |

Build order: position-dept-guard, then form-keep-input (the guard is small; keep-input touches many forms and benefits from the guard's clearer error message). Either can ship alone.

## Facts found (code and DB, 2026-11-04)
- **Where Retail Manager is mapped:** table `positions` id 62, code `RETAIL_MANAGER`, `department_id` = 2 (**Retail**, code RETAIL, company 2LOG). Role `RETAIL_MANAGER` (portal employee) is separate. Both from migration `20261104_032_retail_manager.sql`.
- Two other Retail-ish departments exist: id 22 "Retail & Development" and id 29 "Ho Retail And Development". Each position row has ONE department_id, so Retail Manager is valid only under department "Retail".
- **Why the error:** the Position dropdown lists ALL active positions as "Name · Department" and is NOT filtered by the selected Department (JS only filters by Work Company, `index.php:1432`). HR can pick Department "Retail & Development" + Position "Retail Manager" and only learns at save: "Selected position does not belong to the selected department." (`app/EmployeeRepository.php:696`; same rule at `:330` for the convert/import path with a different message).
- **Why typed values vanish:** every form is a plain POST. On error the generic `catch` (`index.php:506`) does `flash('error')` and `redirect()` to a fresh GET, so the page re-renders blank. Only two forms already keep input via `$_SESSION[...]_old`: `create_payroll_request` and `save_job`. Employee forms do not.
- Forms affected: Add Employee (`create_employee`, `index.php:383`, page `hr-employee-new`), Employee 201 employment/personal tabs (`update_employee_employment` `:425`, `update_employee_personal` `:413`, page `hr-employee`), Convert application to employee (`convert_application_to_employee` `:188`, page `hr-convert-employee`).

## Objective
HR should never lose typing because of a validation error or a stray refresh, and should not be able to build an impossible Department + Position pair in the first place.

Users: HR (Employee 201, Add Employee, Convert to Employee).

## Module 1: position-dept-guard
Acceptance criteria:
1. Choosing a Department limits the Position list to positions of that department (plus "Unassigned"). A currently selected position that no longer fits is cleared, with the existing small note under the field saying why.
2. (If John picks it, see Q1) Choosing a Position while Department is blank fills Department with the position's department.
3. Server-side rule stays (trust boundary). Both messages (`:330`, `:696`) say which two values clash, e.g. `Position "Retail Manager" belongs to department "Retail", not "Retail & Development".`
4. PMBSI exception at `:696` (rule skipped for PMBSI) is unchanged.
5. Fix sweep: same behaviour on all three forms and the Department/Position pair anywhere else it appears (grep `name="position_id"`).

## Module 2: form-keep-input
Acceptance criteria:
1. After a failed POST (any exception caught at `index.php:506`) for the employee forms listed above, the user lands on the same form with EVERY field refilled: text, textarea, select, radio, checkbox, date. The error banner still shows.
2. Dependent selects (Work Company, Department, Position, Branch, Reporting To) are refilled in the right order so the JS filters do not wipe the restored values.
3. Accidental refresh or navigating back/closing the tab by mistake on an unsaved form restores the draft (browser `sessionStorage`, per form + record id). The draft is cleared after a successful save or an explicit Cancel.
4. Not restored, by design: file inputs (browser limit; show a one-line "re-attach the file" note if the failed POST had a file), password fields, CSRF token (always fresh).
5. Works with no new dependency; one shared helper, not per-form copies.
6. Sensitive fields (see Q3) are excluded from the browser draft.

Approach (proposal, Ponytail-small): reuse the existing session pattern. In the generic `catch`, for a whitelist of actions store `$_SESSION['form_old'][<action>] = $_POST` minus `csrf`, `action`, passwords. On render, the page emits it once as JSON and one small JS function (in `public/assets/hris-app.js`) sets values by field `name` before the dependent-select scripts run. The sessionStorage draft reuses the same function. This avoids editing every hand-written `value="..."` input.

## Tech Stack
PHP (plain, `index.php` router + `app/` services), MySQL `pmbsi_hris` (XAMPP), vanilla JS in `public/assets/hris-app.js`, CSS `public/assets/app.css`. No migration, no schema change, no new dependency.

## Commands
```
Syntax/static + constraints:  php scripts/check.php task
Before shipping:              set HRIS_TEST_DB=<scratch db> ; php scripts/check.php full
Lint one file:                php -l index.php
New test (to write):          php tests/form_keep_input_test.php
Browser check:                run-hris-system skill (isolated scratch DB, never pmbsi_hris)
```

## Project Structure
```
index.php                      -> catch block (~506) stores old input; the 3 form pages read it once; Position select markup (data-department-id)
app/EmployeeRepository.php     -> clearer mismatch messages (:330, :696)
public/assets/hris-app.js      -> department->position filter; restore-by-name helper; sessionStorage draft
tests/form_keep_input_test.php -> new
HRIS-Vault/                    -> Progress, Session-Log, 02-Modules/Employee-Records, Business-Rules, Decision-Log, CHANGELOG
```

## Code Style
Match the file: terse guards, `e()` on output, plain English messages.
```php
throw new RuntimeException('Position "'.$pn.'" belongs to department "'.$pd.'", not "'.$dn.'".');
```

## Testing Strategy
- Scratch DB only. New `tests/form_keep_input_test.php`: (a) mismatch POST returns the clear message; (b) failed POST stores old input without `csrf`/passwords and the next GET of the form contains the typed values; (c) old input is consumed once (second GET is blank); (d) a successful save leaves nothing stored.
- Browser check (`run-hris-system`): Department "Retail & Development" hides Retail Manager; Department "Retail" shows it; force an error, confirm all fields and the dependent selects keep values; refresh mid-typing restores the draft.
- `php scripts/check.php task` must pass; no floor lowered, no test skipped.

## Boundaries
- Always: server-side validation stays; CSRF stays; English-only UI text; modern `.ap-sec` look for any new note; vault sync + final report lists vault files; fix sweep of the same pattern.
- Ask first: moving the Retail Manager position to another department or duplicating it; any DB/schema change; storing drafts server-side in the database; extending keep-input beyond the forms listed.
- Never: store passwords or CSRF in a draft; weaken or remove the position-department check; touch live `pmbsi_hris` rows; commit or push without your word.

## Success Criteria
1. Department "Retail & Development" + Retail Manager cannot be selected together in the UI; if forced by POST, the error names both values.
2. After any save error on the 3 employee forms, 100% of non-file fields are still filled and the error shows.
3. Refresh on an unsaved form restores the typed values; a successful save clears the draft.
4. `php scripts/check.php task` and the new test pass on the scratch DB.

## Open Questions (need John)
1. **Position/Department UX:** (A) department filters positions only (recommended), or (B) A plus picking a position auto-fills its department?
2. **Retail Manager in the other two Retail departments** ("Retail & Development", "Ho Retail And Development"): leave valid only under "Retail" (recommended; one position = one department), or should it also be allowed there? The second needs a data decision from you.
3. **Browser draft and sensitive fields:** exclude government IDs (SSS/TIN/PhilHealth/Pag-IBIG), bank and birth date from the sessionStorage draft (recommended), or keep everything? The server-side retry (session) restores all except passwords either way.
4. **Scope of keep-input:** the 3 employee forms now (recommended), or every form in the app (sweep, larger, more risk)?
