# Todo: Convert to Employee, automatic portal account

Plan: `plan-convert-auto-account.md` | Spec: `../SPEC-convert-auto-account.md` | Status: waiting for John's approval

## Task 1: Convert always issues the account (backend)
**Description:** `convertToEmployee` stops reading account_mode/user_id/account_email/temporary_password. It requires `work_company` (2LOG or PMBSI), creates the employee with `user_id=0`, `company_email=''`, then calls `EmployeeAccountService::issue(..., 'EMPLOYEE')` in the same transaction. Handler posts `work_company` only.
**Acceptance criteria:**
- [x] PMBSI convert gives linked user, email `firstnamelastname@pmbsi.com`, `work_company='PMBSI'`, `must_change_password=1`
- [x] 2LOG convert gives `@2log.com`; duplicate name gives `name2@`
- [x] Missing/invalid Company, or missing credential key, saves nothing (no employee, no user)
- [x] Posted `company_email`, `user_id`, `account_mode` are ignored; second convert of same application still blocked
**Verification:**
- [x] New `tests/rec_convert_auto_account_test.php` (isolated DB) red first, then green
- [x] Existing convert-related tests updated to the new contract (grep `account_mode` in `tests/`), none skipped or deleted
- [x] `php scripts/check.php task`
**Dependencies:** None
**Files likely touched:** `app/RecruitmentRepository.php`, `index.php` (handler L178-204 only), `tests/rec_convert_auto_account_test.php`, any test that posts `account_mode`
**Estimated scope:** Medium

## Checkpoint: After Task 1
- [x] Tests pass, check.php task green
- [ ] Review with John before the form change

## Task 2: Form: Company, Employer label, email preview
**Description:** On the Convert screen remove the "Employee portal access" card and its JS; add a required Company select (2LOG, PMBSI) on Employment assignment, defaulting from the selected Department's company and editable; show "Employer: PMBSI" as a read-only label; make Company email a read-only live preview (`firstnamelastname@domain`, note "Created automatically"). English-only text.
**Acceptance criteria:**
- [x] No Account setup dropdown, existing-account or temporary-password field renders
- [ ] Changing Company or names updates the preview domain (`2log.com` / `pmbsi.com`)
- [ ] Choosing a Department fills Company; user can still change it
- [ ] Matches `Design-System.md` spacing, labels, focus and error states; works at narrow width
**Verification:**
- [x] `php -l index.php` and `php scripts/check.php task`
- [ ] Rendered-HTML check in the HTTP test (Task 3); John does the visual check
**Dependencies:** Task 1
**Files likely touched:** `index.php`
**Estimated scope:** Small

## Checkpoint: After Task 2
- [~] No dead handlers left (grep clean); screen render not yet checked (grep `phase2cAccountMode`, `phase2cExistingAccount`, `phase2cCreateAccount`)

## Task 3 (done 2026-11-02): HTTP test, sweep, vault sync
**Description:** End-to-end HTTP test of convert (CSRF, permission, forged account fields ignored, reveal button works for HR). Sweep: any other screen still offering link-later / link-existing choices or posting `account_mode`. Vault sync.
**Acceptance criteria:**
- [x] HTTP test passes on the isolated DB
- [x] Sweep list written: fixed / found-and-fixed / left alone
- [x] Vault updated: Recruitment-and-Onboarding, Business-Rules, Screens, Progress, Session-Log, Decision-Log, CHANGELOG
**Verification:**
- [x] `HRIS_TEST_DB=<isolated> php scripts/check.php full`
- [~] constraints not weakened (floors only raised); no separate code-simplify run
**Dependencies:** Tasks 1-2
**Files likely touched:** `tests/rec_convert_auto_account_http_test.php`, vault notes
**Estimated scope:** Medium

## Checkpoint: Complete
- [~] Spec criteria met by tests; visual and browser check by John still pending
- [x] Final report lists skills used and vault files changed
- [ ] No commit/push/deploy without John's word (not done yet)
