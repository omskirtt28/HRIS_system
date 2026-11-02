# Plan: Convert to Employee, automatic portal account

Status: DRAFT for John's approval. Spec: `../SPEC-convert-auto-account.md`. Mode: plan only, no code changed.
Companies: **2LOG and PMBSI only** (John, 2026-11-02). Employer label = PMBSI.
Rules: one slice, vault sync before the final report, no commit/push/deploy, no live DB write without the standing test-then-apply gate, `php scripts/check.php task` before handing back.
Coding workflow John asked for: during coding use the agent-skills build, test, constraints and code-simplify steps (see "How we code it").

## What exists (read from code)
- Form: `index.php` ~L1737 (Company email input), ~L1750-1757 (Employment assignment card), ~L1759-1766 (portal access card), JS for the dropdown ~L1789.
- Handler: `index.php` L178-204 posts `account_mode`, `user_id`, `account_email`, `temporary_password`, `company_email`.
- `RecruitmentRepository::convertToEmployee` L460-: branches `existing` / `create` / `later` at L480-498; `employeeData` L500 has no `work_company`.
- Reuse as is: `EmployeeAccountService::issue()` (builds `first+last@domain`, unique number suffix, random password, `must_change_password=1`, encrypted copy for reveal, audit `AUTO_CREATE_USER`, sets `employees.user_id` and `company_email`) and `domainFor()` (2LOG, PMBSI only).
- `EmployeeRepository::create()` already accepts `work_company` (validates it); `EmployeeAccountService::createEmployee()` is the pattern: `user_id=0`, `company_email=''`, then `issue()` in the same transaction.
- Create Employee already has the Company select and an email preview script (`index.php` ~L1385-1402) to copy.

## Dependency graph
```
convertToEmployee (work_company + issue())  ->  handler (post fields)  ->  form (Company select, preview, remove account card)
```
Backend first because the form posts into its new contract.

## Decisions
- Company required, defaults from the chosen Department's company, editable (D1).
- Server builds the email; posted `company_email`, `account_mode`, `user_id`, `account_email`, `temporary_password` are ignored (same as Create Employee).
- Employee role only. Password visible only through the existing reveal button (D3).
- No migration.

## Task list
See `todo-convert-auto-account.md`.
- T1 Backend: convert always issues the account (M: 2-3 files)
- T2 Form: Company + Employer label + email preview, remove account card (S: 1 file)
- T3 End-to-end HTTP test, sweep, vault sync (M)
- Checkpoint after T1, after T2, final.

## How we code it (John's standing request)
For every slice, when coding: **build** in thin steps, **test** with the isolated DB tests first (red then green), run **constraints** (`CONSTRAINTS.md`, `scripts/check.php`; never lower a floor or skip a test), then **code-simplify** pass on the diff. Per John's request these are applied automatically at code time; they are not run during planning.

## Risks
| Risk | Impact | Mitigation |
|---|---|---|
| Credential key missing in `config.local.php` | Convert fails after employee insert | One transaction, rollback; clear error message; test case |
| Hand-made user already uses `name@domain` | Account cannot be issued | `issue()` numbers duplicates (`name2@`); never links an existing user |
| Existing tests post `account_mode` | Red tests | Update them to the new contract, do not delete or weaken |
| Document files copied before failure | Orphan files | Keep existing `$copiedFiles` cleanup in the catch |
| Department company blank/mismatched | Wrong domain | Company is required and shown with a live email preview |

## Open questions
None blocking. (Ocampos / Silverworks: out of scope until John gives a domain.)
