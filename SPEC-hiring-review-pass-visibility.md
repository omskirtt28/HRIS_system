# Spec: Hiring Review "Pass to next interviewer" visibility (Head Office vs Retail)

Status: DRAFT, waiting for John's approval. No code written. (Taglish notes where John's words matter.)

## Assumptions (correct me now)
1. Single capability, so no capability map needed.
2. Site type of a candidate = `RecruitmentRepository::siteTypeOf()` (job opening site_type, else manpower request branch): `HEAD_OFFICE` | `RETAIL` | `OTHER`/unknown.
3. Current bug: `reviewerChoices()` returns ALL of MANAGER, ADL, SC, TC, OIC, MT for every candidate, so a Head Office candidate shows ADL (Cherrymei Solabar), Sales Captain, etc. to Charles Chan (screenshot).
4. HR (`recruitment.manage`) is not a "reviewer role"; HR starts the chain. The rules below apply to the "Pass to next interviewer" card and to HR's endorse picker.
5. "TC" = Talent Acquisition/Team Coordinator role code `TC` as already used in `SCORER_ROLES`; I did not verify the full name.

## Objective
Show each person only the next interviewers that make sense for the candidate's site type, so Head Office candidates never reach retail people and retail candidates are narrowed by area/branch.

Users: HR (endorses), TC and Manager (Head Office chain), ADL/OIC/MT/SC (Retail chain).

### Rules (from John)
**Head Office candidate**
- HR endorses to TC. TC sees only MANAGERs in the pass list and passes to them.
- ADL, SC, OIC, MT are never shown. Manager is the end of the chain (no retail roles).
- Mirrors existing `SCORER_ROLES['HEAD_OFFICE']=[MANAGER, TC]`.

**Retail candidate**
- HR endorses to the Retail chain by choosing a list of Area(s) and Branch(es). (ADL is the retail head per `EmployeeApproverAssignmentService`.)
- ADL gets it first and can pass on. After a pass into an Area/Branch, the list shows only OIC, MT, SC whose branch/area is within the chosen scope.
- MANAGER and TC are never shown for retail.
- Mirrors `SCORER_ROLES['RETAIL']=[ADL, OIC, SC, MT]`.

**Unknown/OTHER site type**: HR only, no pass-on choices (matches current `canScore` behaviour).

### Acceptance criteria
- [ ] HO candidate: Pass card for TC lists only MANAGER users; for MANAGER lists nobody from retail roles (empty-state text shown).
- [ ] Retail candidate: pass card never lists MANAGER or TC.
- [ ] Retail pass-on list for ADL is limited to OIC/MT/SC in the candidate's chosen area/branch scope.
- [ ] `passOn()` re-validates server-side (a forged `to[]` id outside the allowed set is rejected with the existing-style RuntimeException).
- [ ] HR endorse picker uses the same filter (no HO candidate endorsed to ADL, etc.).
- [ ] Self, already-endorsed, inactive/removed users still excluded (unchanged).
- [ ] UI text stays English.

## Tech Stack
PHP 8 (XAMPP), MySQL `pmbsi_hris`, server-rendered views, no framework. Tests: custom `tests/*_test.php` with `check()`.

## Commands
- Task check: `php scripts/check.php task`
- Full (before shipping, `HRIS_TEST_DB` set): `php scripts/check.php full`
- Focused: `php tests/interview_chain_http_test.php` (needs isolated test DB per `run-hris-system`)

## Project Structure
- `app/RecruitmentRepository.php` : `reviewerChoices()`, `passOn()`, `REVIEWER_ROLES`, `SCORER_ROLES`, `siteTypeOf()` (change here, one shared filter).
- `app/CandidateReviewViews.php:101-111` : Pass card (should call the filtered list).
- HR endorse picker view/handler (to locate: grep `review_endorse`/`reviewerChoices` callers).
- `tests/interview_chain_http_test.php` : extend scenarios.
- Vault: `HRIS-Vault/02-Modules/Recruitment-and-Onboarding.md`, Business-Rules.md, Decision-Log.md, CHANGELOG.md, Progress.md, Session-Log.md.

## Code Style
Match existing compact style, one shared method instead of per-view filters:
```php
/** Reviewers the current person may pass this candidate to, by site type. */
public static function passTargets(int $applicationId, int $fromUserId): array {
    $site = self::siteTypeOf($applicationId);
    $roles = self::PASS_TO[$site][Auth::user()['role_code']] ?? [];
    // filter reviewerChoices() by $roles (+ area/branch scope for retail)
}
```

## Testing Strategy
Extend `interview_chain_http_test.php`: HO (TC sees managers only, no ADL/SC), Retail (no MANAGER/TC; scoped OIC/MT/SC), forged `to[]` rejected, unknown site = empty. Isolated DB only; never `pmbsi_hris`.

## Boundaries
- Always: server-side validation in `passOn()`, CSRF unchanged, run `check.php task`, vault sync, fix sweep (HR endorse picker, any other `reviewerChoices()` caller).
- Ask first: schema change (storing HR's area/branch list per application needs a new table/columns, so a migration), changing role codes/permissions.
- Never: commit/push/deploy, touch live data, weaken tests, change payroll/permissions.

## Decisions (John, 2026-11-03)
- Q1 (REVISED by John): HR sets the Area and Branch on the Job Opening itself when the job is Retail. Today `job_openings` only has `site_type` (branch comes from the manpower request, and walk-in jobs have none), so this NEEDS a migration (next number after 030): job opening <-> area/branch link (e.g. `job_opening_scopes(job_opening_id, area_id NULL, branch_id NULL)`, one or more rows). Pass list and HR endorse picker filter OIC/MT/SC/ADL by these rows. Retail job with no scope rows = HR only until HR sets it. Needs `backend-design:migration-safety` + `data-modeling-discipline`, mysqldump first, up/re-run/down/up on isolated DB, down guarded for used data, Data-Model.md updated.
- Q2: HR may endorse directly to the ADL of that area. If the area has no ADL, HR endorses to OIC or SC (and MT) of that branch instead.
- Q3: Yes, OIC/MT/SC are matched by their employee branch/area.
- Q5: TC = Team Coordinator.
- Q4: Manager is the end of the HO chain, with this twist:
  - **Direct manager** (the department's manager who owns the opening, e.g. the manpower-request requester) who **Proceeds** = locked/final. No pass-on card, nobody to pass to.
  - Direct manager who does **not** Proceed (Not suitable / Needs another interview) may pass the candidate to **another Manager or a TC**.
  - A non-direct Manager (passed in by TC) is just an interviewer: Proceed is their opinion; they are the end, no retail roles ever shown.

## Open Questions (need John)
4a. How do I know who the "direct manager" is? Assumed: the requester of the Manpower Request ticket (`defaultReviewers()` already uses that); a walk-in opening with no requester has no direct manager (every Manager acts as non-direct). OK?
4b. Does "Not suitable" by the direct manager also let him pass on, or only "Needs another interview"? Assumed: both.
1. **Retail area/branch list**: where does HR pick it? Per application at endorse time (new table, migration) or derived from the job's branch/area (no migration)? I recommend deriving from the job/manpower-request branch, no migration.
2. **Retail first hop**: does HR endorse straight to ADL (who then passes to OIC/MT/SC), or HR picks OIC/MT/SC directly from the area/branch list?
3. **Retail scoping source**: are OIC/MT/SC tied to a branch via their employee record (`employees.branch_id`) so I can match by branch/area?
4. **HO final**: should Manager be able to pass anywhere, or is Manager always the end (only Final)?
5. Confirm "TC" role meaning.
