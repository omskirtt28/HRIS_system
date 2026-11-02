# Spec: rec-endorse-manager (endorse a candidate to the Manager / ADL who asked for the hire)

Status: DRAFT for John's review. No code written. Touches permissions, notifications and a new table, so it needs approval before any build (project rule: plan first for permissions/schema).

## Objective
Today "Client endorsement" on the HR applicant page (`index.php` endorse action, `RecruitmentRepository::endorse`) writes a row in `endorsements` keyed by **client_id** and moves the stage to Client Review. Since the employer is always PMBSI, there is no real client to review. The people who actually interview and decide are the **Manager (Head Office) or ADL (branch)** who filed the Manpower Request ticket. Goal: HR endorses a candidate to that Manager/ADL, they see it in their own HRIS account, and they answer (interview result / decision) so HR can continue.

## Recommendation (UX)
**Auto-fill, searchable only when needed.**
1. The endorse card shows "Endorse to: **Sir Charles (Manager)**", already filled from the manpower request's requester (`manpower_requests.requested_by`, the ticket requester). HR just writes the optional note and clicks **Endorse**. No searching in the normal case.
2. A "Change" link opens a searchable list (type a name) limited to active users with the Manager or ADL role, for when someone else should interview. Walk-in jobs have no ticket, so there the search is required (no default).
3. One recipient per endorsement; HR can endorse the same candidate to a second person later (a new row).
Why not search-only: the ticket already says who needs this person, so searching adds clicks and the chance of picking the wrong Charles.

## Manager / ADL side (new)
- New page in their portal, "Candidates for review" (sidebar badge for new ones, plus a bell notification via `NotificationService`), listing only candidates endorsed **to them**.
- Candidate view (read-only): name, applied job, HR's endorsement note, resume view/download (same streaming rule as HR, scoped to endorsed-to-me), screening answer, interview schedule. No contact data beyond name and the job; no other applicants.
- Their answer: **Proceed** (to offer) / **Not suitable** / **Needs another interview**, with a comment. Answer is saved, notifies HR, and the HR page shows it in the timeline. Stage effect: Proceed moves the application to Offer; Not suitable moves it to Rejected? (Q3); Needs another interview keeps the stage.

## Facts found
- `endorsements(application_id, client_id, endorsed_by, endorsement_note, endorsed_at, status)`, unique (application_id, client_id). `client_reviews` holds the client answers. Client portal pages read these.
- `manpower_requests.requested_by` and `ticket_id` exist; ticket requester is the Manager/ADL.
- Stage after endorse is `CLIENT_REVIEW` (label "Client Review").

## Proposed data change (needs approval, Stage-B migration with down script)
`endorsements`: add `reviewer_user_id` BIGINT UNSIGNED NULL (FK users) and keep `client_id`. New table `endorsement_reviews(id, endorsement_id, reviewer_user_id, decision, comment, decided_at)` for the answers. Existing client rows untouched (reviewer NULL = old client flow).

## Permissions
- HR endorse: existing `recruitment.manage`.
- Reviewer page and resume route: a new permission `recruitment.review` granted to MANAGER and ADL roles, and every query filtered by `reviewer_user_id = current user` (no scope = no access). Needs John's approval of the new permission.

## Success criteria
1. Endorse card pre-fills the manpower request's requester; Change lets HR search Manager/ADL users.
2. The chosen Manager/ADL sees the candidate in their portal, gets a notification, and can open the resume.
3. Their decision is stored, shown on the HR timeline, and notifies HR.
4. A Manager/ADL cannot see candidates endorsed to someone else, applicants list, or other stages.
5. Existing client endorsements still load. Migration up/re-run/down/up clean, tests added, floors raised.

## Boundaries
- Always: server-side scope check on every read, CSRF, audit ENDORSE and REVIEW_DECISION, English UI.
- Ask first: new permission, migration, renaming the stage label, retiring the client portal.
- Never: show applicants to anyone not endorsed to them, touch live `pmbsi_hris` before the test-then-apply checks.

## Open questions
- **Q1** OK with auto-fill from the ticket requester plus a searchable Change (recommended)?
- **Q2** Should the recipient be one person, or can HR tick several (for a panel interview)? Default: one at a time.
- **Q3** Not suitable by the manager: move the candidate to Rejected automatically, or only notify HR and let HR decide? Default: notify HR, HR decides.
- **Q4** Rename the stage "Client Review" to "Manager Review" (data change in `recruitment_stages`)? Default: yes, label only.
- Q5 see Decisions.
- **Q6** Does the Manager/ADL also schedule/record the interview themselves, or does HR keep scheduling and they only give the decision? Default: HR schedules, they decide.

## Decisions (John, 2026-11-02)
- Q1 yes: auto-fill the ticket requester + searchable Change.
- Q2: **several recipients per candidate** (e.g. the Manager and a TC, or a second Manager/ADL). Each recipient has their own row and their own decision. **HR can proceed on their own** if a recipient does not answer (no one blocks the candidate); HR's Update/Continue stays available while endorsed.
- Q3: when a Manager/ADL answers **Not suitable**, the candidate **goes back to HR** (stage Returned, with the manager's comment on the timeline); HR can then endorse them to another Manager/ADL (a new endorsement), reject, or hold. No automatic rejection.
- Q4: stage label becomes "Manager Review" (was "Client Review"); label only, stage code unchanged.
- Q5: explained to John (old Client portal = outside employers logging in to review endorsed candidates; PMBSI is the only employer now). Default kept: leave it untouched, nothing new built on it. Waiting for John's word.
- Q6: Manager and ADL **can also schedule/record the interview themselves** and decide (not only HR). Reviewer actions: Schedule interview (their own interview rows, HR sees them), Proceed, Not suitable, Needs another interview.
- Recipient roles: Manager, ADL and TC (TC is named by John in Q2; confirm the TC role is a valid reviewer).

## Decisions (John, 2026-11-02, later)
- Q5: **remove the Client portal** (John's explicit word). Treated as its own slice after this one: it removes client pages/menus and the client-portal access to endorsements; client users/roles and the client_reviews data are NOT deleted without a separate check (project rule: no deleting data or roles without explicit word on the exact items).
- New request, applicant-facing: the person who applied should see their progress as plain steps: Applied, For initial interview, For mid interview, For final interview, Deployment, Start date. Separate slice (Track Application page). Wording of John's message ("employer") to be confirmed: assumed to mean the applicant, not the employer.

## Slices
1. rec-endorse-manager: this spec (endorse to Manager/ADL/TC, reviewer page, decisions, Manager Review label).
2. rec-remove-client-portal.
3. rec-track-steps: applicant-facing steps, needs interview rounds (initial / mid / final) added to the interview types.
