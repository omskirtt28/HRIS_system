# Spec: Mapped Sales Captain / OIC missing from "Endorse to Hiring review"

Status: DRAFT, waiting for John's approval. No code changed.

## Objective
HR opens an applicant (example: Adrian Domingo, SALES ASSOCIATE, Robinsons Antipolo, app 17) and the "Endorse to Hiring review" card says "No one with a portal account can be endorsed for this job yet", even though the branch already has a Sales Captain mapped. HR must either see the right person, or see exactly why that person cannot be picked and what to do.

## Root cause (checked read-only on local `pmbsi_hris`)
- Job 9 is RETAIL with one branch, Robinsons Antipolo (branch 39, area MM3).
- `RecruitmentRepository::targetsFor()` (app/RecruitmentRepository.php:216) offers Retail people by **portal role code** (`ADL`, `OIC`, `SC`, `MT`) via `reviewerChoices()` (line 192), not by job position.
- Myra Retchie Azcuna is the Sales Captain at branch 39 and has a portal account (user 29), but that account's role is **EMPLOYEE**, not **SC**. So she is filtered out.
- The "No portal account yet" hint (`scopeStaffWithoutAccount()`, line 237) only lists staff with `user_id IS NULL`. Myra has an account, so she is not listed either. HR sees nothing and no reason.
- ADL route: area MM3 has no ADL. The only ADL account (Cherrymei Solabar) sits at Head Office with no area. So HR cannot go through an ADL.
- OIC: no active employee at Antipolo holds "Officer In Charge", so there is no OIC to find.
- Branch 39 has no other reviewer-position staff (the rest are Brand Stewards with no accounts).

"Mapped" in the branch/position sense does not make someone endorsable. Only the account role does, and the role is what also grants `recruitment.review` access to the reviewer's own pages.

## Assumptions (correct me now)
1. A person can only receive an endorsement if their portal account role is ADL/OIC/SC/MT, because the reviewer pages need `recruitment.review`. So the code must not grant eligibility from position alone.
2. Changing Myra's role is HR's action in Users & Access, and it counts as a permission change that needs John's explicit word.
3. English-only UI text.

## Proposed change (smallest complete)
1. **Data (no code):** set Myra Azcuna's account role to Sales Captain (SC). She then appears for Antipolo jobs immediately. Needs John's OK.
2. **Code, one function plus one message:** extend `scopeStaffWithoutAccount()` into a "reviewer positions that cannot be endorsed" list. It also returns branch staff in a reviewer position (Sales Captain, Officer In Charge, Management Trainee, Area Development Leader, or "OIC") who **have an account whose role is not a reviewer role**. The card then shows, for example: "Myra Retchie Azcuna (Sales Captain, Robinsons Antipolo): account role is Employee. Change it to Sales Captain in Users & Access." Accounts-less staff keep the current wording.
3. **Fix-sweep check:** same position-vs-role gap on the reviewer pass-on screens that use `targetsFor()` for non-HR actors (no change expected, list only).

Not doing: deriving eligibility from position, auto-changing roles, new tables, new migration.

## Commands
- Static check: `php scripts/check.php task`
- Tests: `php tests/retail_manager_test.php` and a new case added to the existing recruitment endorse test (find with Grep `targetsFor` in tests/)
- Pre-ship: `php scripts/check.php full` with `HRIS_TEST_DB` set

## Project structure
- `app/RecruitmentRepository.php`: lookup (`scopeStaffWithoutAccount`)
- `app/CandidateReviewViews.php:33-40`: card text
- `tests/`: new test case
- Vault: `HRIS-Vault/02-Modules/Recruitment-and-Onboarding.md`, `Business-Rules.md`, `Progress.md`, `Session-Log.md`, `CHANGELOG.md`

## Code style
Match the file: single-line prepared statements, `e()` on every output, one-line `/** */` doc comment.

## Testing strategy
Isolated test DB only (run-hris-system skill). Cases: (a) Retail job, branch has Sales Captain with EMPLOYEE role gives the hint naming the role; (b) same person with role SC gives a selectable choice and no hint; (c) staff with no account keeps the old hint; (d) Head Office job unchanged.

## Boundaries
- Always: escape output, keep `targetsFor()` role-based, run checks, update the vault.
- Ask first: changing any user's role, any migration.
- Never: let position alone grant review access, push or deploy, touch live data without the word.

## Success criteria
1. Adrian Domingo's card shows Myra's reason (or Myra as a choice after her role is set to SC).
2. No existing choice list changes for jobs whose reviewers already have the right role.
3. Tests above pass; `check.php task` is clean.

## Open questions for John
1. OK to set Myra Azcuna's role to Sales Captain (SC)? Also, are other Sales Captains / OICs in the same situation? I can list them read-only.
2. Antipolo has no OIC and no ADL for MM3. Do you want the hint to also say "No ADL for this area"?
