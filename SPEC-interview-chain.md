# Spec: interview-chain (pass the candidate to the next interviewer; Final hands back to HR)

Status: APPROVED by John 2026-11-02 (Q9 yes, Q10 default, Q11 yes); migration still needs his go. No code written. Module of SPEC-interview-map.md (second in build order, after interview-invite). Needs a migration, so approval is required before any build.
Date: 2026-11-02. Author: Claude.

## Objective
Today HR endorses a candidate to Manager/ADL/TC reviewers, and any one reviewer's Proceed moves the application straight to Offer. John wants a real chain: Initial -> Mid -> Final. After a round (e.g. Mid Interview) the reviewer can pass the candidate to another named person, and one person is marked the **final interviewer**. When that person answers Proceed, the candidate goes back to HR with "Final interview done, next: Offer", and HR continues. Nobody except HR moves a candidate to Offer.

Decisions (John, 2026-11-02): Q2 yes, Manager/ADL (not only HR) can pass a candidate on. Q3 yes, a final reviewer's Proceed means "ready for HR", not auto-Offer.
Out of scope: the applicant-facing steps page (separate slice `rec-track-steps`: Applied, For initial interview, For mid interview, For final interview, Deployment, Start date; nothing else shown to the applicant).

## Tech stack
PHP (existing app), MySQL `pmbsi_hris` (isolated DB for tests), existing `application_reviewers`, `interviews`, `NotificationService`. No new dependency.

## Proposed data change (needs approval; Stage-B migration with guarded down)
`application_reviewers`: add `is_final` TINYINT(1) NOT NULL DEFAULT 0 and `passed_by` BIGINT UNSIGNED NULL (user who passed the candidate on; NULL = HR endorsed). Existing rows get 0 and NULL, so nothing existing changes meaning. Down script refuses if any row has `is_final=1` or `passed_by` set. No new permission: `recruitment.review` already covers Manager/ADL/TC.

## Behaviour
1. **Pass on (reviewer):** on the reviewer's candidate page, a card "Pass to next interviewer": searchable list of active Manager/ADL/TC users (same `reviewerChoices()` as HR), optional note, tick "Final interviewer". Only a reviewer who was endorsed this candidate can pass it; the new person gets a bell notification and sees only this candidate. Max 10 reviewers per application, same as HR.
2. **HR endorse** gets the same "Final interviewer" tick so HR can name the final person up front.
3. **Proceed rules:**
   - Non-final reviewer's Proceed: saves the decision and a timeline note; stage and Offer unchanged.
   - Final reviewer's Proceed: stage stays "Manager Review", timeline note "Final interview done", notification to HR "Ready for HR: {candidate}", and the HR applicant page shows a card "Final interview done, next: Offer" with the existing Offer form. HR must act; nothing auto-moves to Offer.
   - Applications with no final reviewer (all legacy endorsements): any reviewer's Proceed behaves like the final rule (ready for HR), so no old path still auto-jumps to Offer.
4. **Not suitable / Needs another interview / all-declined to Returned:** unchanged.
5. **Interview emails:** a reviewer scheduling a round after being passed the candidate triggers interview-invite as usual.
6. A reviewer cannot mark themselves or others final after answering; only HR or the person who passed it can change `is_final` while the row is still unanswered.

## Commands
```
Check: php scripts/check.php task
Full:  HRIS_TEST_DB=<isolated db> php scripts/check.php full
Test:  php tests/interview_chain_test.php
HTTP:  php tests/interview_chain_http_test.php
Migration: scripts/ticket_stageb_database.php style up / re-run / down / up on the isolated DB first
```

## Project structure
```
database/migrations/20261102_0xx_interview_chain.sql (+ .down.sql)
app/RecruitmentRepository.php   passOn(), decide() change, markFinal(), endorse() gets $finalIds
app/CandidateReviewViews.php    "Pass to next interviewer" card, Final badge on reviewers list
app/RecruitmentViews / HR page  Final tick on the endorse card; "Final interview done" card
index.php                       action review_pass_on (recruitment.review, CSRF)
tests/                          interview_chain_test.php (+ http); update rec_endorse_manager_test.php (Proceed no longer auto-Offer)
HRIS-Vault/                     Progress, Data-Model (migration), Business-Rules, Screens, API-Surface, Decision-Log, CHANGELOG, Session-Log
```

## Code style
```php
public static function passOn(int $applicationId, int $fromUserId, array $toIds, bool $final, string $note): void {
    if (!Auth::can('recruitment.review')) throw new RuntimeException('You do not have permission to review candidates.');
    if (!self::reviewerRow($applicationId, $fromUserId)) throw new RuntimeException('This candidate was not endorsed to you.');
    // validate $toIds against reviewerChoices(), insert with passed_by, notify, timeline note, audit PASS_ON
}
```

## Testing strategy
- Unit (isolated DB): endorsed reviewer can pass on, a stranger cannot; invalid or inactive target refused; final Proceed leaves stage and notifies HR; non-final Proceed does not move stage; legacy application with no final flagged is ready-for-HR on Proceed and never auto-Offer; limit of 10; migration up/re-run/down/up and down guarded.
- HTTP: reviewer B cannot open candidate A until passed; after pass-on B sees only that candidate and never the applicant email; CSRF on pass-on.
- Update the existing Proceed test; do not lower any floor.

## Boundaries
- Always: server-side scope on every read, CSRF, audit PASS_ON and FINAL_DONE, English UI, tests first on the scratch DB, vault sync.
- Ask first: the migration, applying it to `pmbsi_hris`, any new permission, changing Proceed behaviour for existing live applications.
- Never: let a non-HR user move a stage to Offer, show applicants to people not endorsed to them, delete data or roles, push or deploy.

## Success criteria
1. A Manager/ADL passes a candidate to a named person; that person gets a notification and sees the candidate, nobody else does.
2. A final-flagged reviewer's Proceed shows HR "Final interview done, next: Offer" and the stage does not change by itself.
3. No code path moves an application to Offer except HR's Offer action.
4. Existing endorsements and the all-declined Returned flow still work.
5. Migration up/re-run/down/up is clean on the isolated DB; `php scripts/check.php task` passes with no floor lowered.

## Open questions
- **Q9:** OK that a non-final reviewer's Proceed only records an opinion (candidate stays in Manager Review) until someone passes it on or HR acts? Default yes.
- **Q10:** Legacy rows with no final person: any Proceed = "ready for HR". OK?
- **Q11:** Should HR see the full chain on the applicant page (who passed to whom, each answer in order)? Default: yes, as a short timeline, using existing timeline notes.
