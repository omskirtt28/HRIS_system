# Spec: Applicant page - collapse sections, lock sent offer, default deployment branch

Module: rec-applicants (follow-up to applicant-profile-polish). Status: DRAFT, waiting John approval. Root `SPEC.md` is untouched; this repo keeps one `SPEC-<slug>.md` per slice.

## Objective
HR page `index.php?page=hr-applicant` (lines ~1698-1712). John's three requests:
1. Too many sections are open at once, so the needed action is hard to find. Minimize (collapse) the secondary sections.
2. After the offer is sent/accepted, the Offer form must not stay open and clickable.
3. Deployment branch/site must come from the branch already set on the Job Opening, no manual select.
User: HR (Kristian Acosta). Success: on a candidate at any stage, HR sees ONE obvious next action and everything else is a one-line collapsed header.

## Assumptions (correct me now)
1. Pure UI/PHP in `index.php` (hr-applicant block) plus small use of existing repo data. No migration, no schema change.
2. Collapse = native `<details>` (already used for "Schedule interview"), no new JS dependency. Open/closed state is not persisted.
3. "Job Opening branch" = `manpower_requests.branch_id` (already loaded as `request_branch_id`) for Head Office jobs; for Retail jobs it is the `job_opening_branches` list (migration 031). See Open Question 1.
4. Offer "sent" = offer row exists with status SENT/ACCEPTED/DECLINED (not DRAFT). Exact status values to be confirmed in code before building.
5. English-only UI, existing tokens (`HRIS-Vault/03-Design/Design-System.md`).

## Requirement detail
### R1 Collapse
- Open by default: Stage & actions card, and the ONE section that matches the current stage (Offer at OFFER, Deployment at DEPLOYMENT, Schedule interview at INTERVIEW).
- Collapsed by default (summary line with a short status, e.g. "Offer - ACCEPTED, start 2026-02-12"): Schedule interview (when not at INTERVIEW), Offer (when done), Deployment (when not yet at it or when DEPLOYED), Resume/Application/Interviews tabs block, Interview scorecard and Hiring review in the right column, Activity timeline (keep latest 3 visible when open).
- Summary row has a visible chevron, is keyboard-operable, min 44px tap height, focus ring.
- Sweep: same pattern on other applicant-style pages (`review-candidate`, `app/CandidateReviewViews.php`) only if clearly the same class; otherwise list, don't touch.

### R2 Offer lock
- Offer status SENT/ACCEPTED/DECLINED: show the read-only summary (offer no., rate, start date, status) collapsed; the edit form is not rendered (or `disabled` inside a closed `<details>` titled "Revise offer" only for SENT, not ACCEPTED).
- Server side: `save_offer` handler rejects edits when the offer is ACCEPTED (never rely on the UI hiding a form).
- DRAFT or no offer: form shown as today.

### R3 Deployment branch default
- Branch select pre-selected from the job opening's branch. Single branch -> shown as read-only text "Branch: X (from job opening)" with hidden `branch_id`, plus a small "Change" link (collapsed select) so an exception is still possible. See Open Question 2.
- Retail job with several branches -> select limited to those branches (not all branches), first not auto-picked unless only one.
- Saved deployment branch always wins over the default on later visits.

## Commands
```
Check (task):  php scripts/check.php task
Check (full):  set HRIS_TEST_DB=<scratch db> && php scripts/check.php full
Syntax:        php -l index.php
Run/verify:    run-hris-system skill (isolated scratch DB, real browser)
```

## Project structure (touched)
```
index.php                          hr-applicant block (~1698-1712), save_offer / save_deployment handlers
app/RecruitmentRepository.php      application() select, saveDeployment(), offer save (status guard)
app/CandidateReviewViews.php       reviewer page (sweep only)
HRIS-Vault/02-Modules/             Recruitment note, Screens.md, Business-Rules.md, Decision-Log, CHANGELOG, Progress, Session-Log
```

## Code style
Match surrounding code: server-rendered PHP, `e()` on every output, `csrf_field()` on every form, inline CSS only via existing classes (`card pad`, `section-t`, `srow`, `tiny muted`), compact one-line helpers. Example:
```php
<details class="card pad ap-sec"<?=$open?' open':''?>><summary class="ap-sum"><span class="section-t">Offer</span><span class="tiny muted"><?=e($summary)?></span></summary>...</details>
```

## Testing strategy
- PHP unit/check via `scripts/check.php` (existing floors not lowered).
- New check: offer in ACCEPTED/SENT cannot be overwritten by POST `save_offer` (server guard); deployment default resolves job-opening branch (HO single, Retail list, saved value wins).
- Browser (run-hris-system, scratch DB): candidates at Screening, Interview, Hiring Review, Offer (sent), Deployment; confirm only the relevant section is open, summaries readable, keyboard toggle works, mobile width has no overflow, dark mode contrast.

## Boundaries
- Always: CSRF + permission checks stay; server-side guards for anything the UI hides; vault sync; English-only text.
- Ask first: any migration/schema change, changing offer status values, letting HR re-open an ACCEPTED offer.
- Never: push/deploy, delete data, weaken CONSTRAINTS.md, touch payroll/permission rules, change stage-flow logic.

## Success criteria
1. At OFFER stage with offer ACCEPTED: Offer shows one-line summary, no editable form, POST `save_offer` is refused server-side.
2. Deployment branch field is pre-filled from the job opening; no manual select needed in the normal case.
3. Page at first load shows at most 2 expanded sections in the left column (actions + current-stage section).
4. `php scripts/check.php task` passes; browser check passes at desktop and 390px width.

## Open Questions
1. Retail jobs have many branches (migration 031). For deployment, auto-fill only when exactly one branch, otherwise show the job's branch list as the select options? (Recommended: yes.)
2. Allow HR to override the default branch (small "Change") or lock it completely? (Recommended: allow override, since transfers happen.)
3. Offer SENT (not yet accepted): keep a "Revise offer" collapsed form, or lock fully? (Recommended: collapsed "Revise offer" for SENT, fully locked for ACCEPTED.)
4. Which sections do you want collapsed besides Schedule interview/Offer/Deployment: Resume block, Interview scorecard, Activity timeline? (Recommended: all three.)
