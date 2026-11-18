# Spec: Fix a reviewer's portal role right inside "Endorse to Hiring review"

Status: IMPLEMENTED locally 2026-11-04 with a narrow permission (recruitment.set_reviewer_role, migration 035) instead of users.manage; SC and MT only, OIC stays Super Admin. See Session-Log and Decision-Log.
Follows `SPEC-endorse-sc-oic-visibility.md` (that one added the "Cannot be endorsed yet" list; this one makes it actionable).

## Objective
HR opens an applicant (example: Adrian Domingo, Sales Associate, Robinsons Antipolo). The card lists "MYRA RETCHIE AZCUNA (Sales Captain, Robinsons Antipolo): portal account role is Employee, change it to a reviewer role in Users & Access." Today HR must leave the page, find Myra in Users & Access > Change role, pick a role, then come back.
Goal: HR fixes it on the same card with one click, and Myra then appears in the endorse list immediately.

User: HR (has `recruitment.manage` and `users.manage`). Success: from the Applicant Profile, HR sets Myra to Sales Captain, lands back on the same profile, and Myra is a checkbox in the list.

## Assumptions (correct me now)
1. The inline action reuses the existing "Change role" rules (`FoundationRepository::changeUserRole`). No new permission path. Only HR who already hold `users.manage` see the control; everyone else keeps the current text.
2. The suggested role comes from the person's position: Sales Captain -> SC, Officer In Charge / "...OIC..." -> OIC, Management Trainee -> MT. HR may not pick any other role here; the full list stays in Users & Access.
3. Changing the role signs that account out (existing behaviour). The card says so before the click.
4. Only the "account role is not a reviewer role" case gets the button. "No portal account" and "account not active" keep the current text (different fixes, out of scope).
5. English-only UI text. No migration, no new table.

## Proposed change (smallest complete)
1. `RecruitmentRepository::scopeStaffWithoutAccount()` also returns `user_id` (already there) and a `suggested_role` code, from a small constant map `POSITION_ROLE=['Sales Captain'=>'SC','Officer In Charge'=>'OIC','Management Trainee'=>'MT']` (OIC-like names fall back to OIC).
2. `CandidateReviewViews.php` (line 35-37): for a row with a `suggested_role` and `Auth::can('users.manage')`, render a small inline form instead of the "change it in Users & Access" sentence: "Account role is Employee. [Set role to Sales Captain]". Hidden fields: `action=change_user_role`, `user_id`, `role_id` (resolved server-side from the code, never a free choice), `return_page=hr-applicant`, `return_id`, CSRF.
3. `index.php:341` `change_user_role`: after success, if `return_page` is exactly `hr-applicant` with a numeric `return_id`, redirect there; otherwise `admin-users` as now. Whitelist only, no open redirect. Flash text unchanged.
4. Server re-checks that `user_id` really is a reviewer-position staff at one of the application's branches before applying the suggested role, so the endpoint cannot be used to give anyone SC/OIC/MT by posting random ids. `changeUserRole` keeps its Super Admin, HR/Admin-portal and "permissions you do not hold" guards.
5. Fix-sweep check (list only): other places that tell HR to "change it in Users & Access" for the same gap, and the pass-on screens that use `targetsFor()`.

Not doing: auto-changing roles, deriving eligibility from position, a role dropdown on the card, creating accounts inline, new migration.

## Tech Stack
PHP (plain, XAMPP), MySQL `pmbsi_hris`, server-rendered views, existing `.card` / `.btn` styles per `HRIS-Vault/03-Design/Design-System.md`.

## Commands
- Static check: `php scripts/check.php task`
- Tests: `php tests/rec_endorse_manager_test.php`, `php tests/rec_endorse_manager_http_test.php` (add cases here), `php tests/hiring_review_visibility_test.php`
- Pre-ship: `php scripts/check.php full` with `HRIS_TEST_DB` set. Browser check on the isolated scratch DB via `run-hris-system`.

## Project structure
- `app/RecruitmentRepository.php`: `scopeStaffWithoutAccount()`, new position->role map
- `app/CandidateReviewViews.php`: the card
- `index.php`: `change_user_role` handler (return redirect only)
- `tests/rec_endorse_manager_http_test.php`: new cases
- Vault: `02-Modules/Recruitment-and-Onboarding.md`, `02-Modules/Auth-and-RBAC.md`, `Business-Rules.md`, `Screens.md`, `Decision-Log.md`, `Progress.md`, `Session-Log.md`, `CHANGELOG.md`

## Code style
Match the surrounding file: compact PHP, `e()` on every output, `csrf_field()`, prepared statements.
```php
$r['suggested_role']=self::POSITION_ROLE[$r['position']]??(stripos($r['position'],'OIC')!==false?'OIC':null);
```

## Testing strategy
HTTP/integration tests on the isolated DB (existing harness):
- HR with `users.manage`: card shows the button for an Employee-role Sales Captain; POST sets SC; redirect goes back to `hr-applicant`; Myra now in `targetsFor()`; audit row `CHANGE_USER_ROLE` written; her sessions revoked.
- No `users.manage`: no button, and a hand-posted request is refused (403/permission error).
- POST for a user who is not reviewer-position staff at this job's branches: refused, role unchanged.
- `return_page` other than `hr-applicant` (e.g. a URL): falls back to `admin-users`.
- Non-HR-assignable case (HR-portal account, Super Admin): `changeUserRole` error shown, nothing changed.
- Existing Users & Access "Change role" still works.

## Boundaries
- Always: CSRF, server-side permission check, resolve role id on the server, audit log, English-only text, sweep for the same sentence elsewhere.
- Ask first: letting HR choose any role inline, creating accounts inline, any schema change, changing what SC/OIC/MT roles can do.
- Never: grant a role from position alone without HR's click, bypass `roleAssignmentError`, redirect to a posted URL, touch live `pmbsi_hris` rows during tests, commit/push without John.

## Success Criteria
- On Adrian Domingo's profile, HR clicks "Set role to Sales Captain" for Myra and returns to the same profile with Myra checkable under Endorse.
- Users & Access shows Myra as Sales Captain; one audit entry exists.
- All tests above pass; `php scripts/check.php task` passes with no constraint weakened.
- Nobody without `users.manage` can change a role through this path.

## Open Questions: answered (John, 2026-11-04)
1. Permission-flow change: approved in principle, delegated to me. Decision: same guards as Users & Access, no weaker path (see Boundaries).
2. Portal and access: Sales Captain stays in the **Employee portal** (same My Workspace as now). Checked: `20261024_011_lead_roles.sql` (SC = portal `employee`, base employee permissions) and `20261102_023_application_reviewers.sql` (SC gets `recruitment.review`). The only visible change is one extra sidebar item, **Candidates for review**, same as the Manager's (`app/View.php:102`, shown by `recruitment.review`). No new menu code needed.
3. ADL left out of the position map.
4. John only needs the role switch, nothing more. The sign-out note on the card stays (existing behaviour of a role change).

Still to verify at build time (scratch DB, read-only): diff of Employee role permissions vs SC role permissions, so nothing Myra uses today disappears. If something is missing I list it and ask; I do not add permissions on my own.
