# Spec: Applicant saved tracking link

Status: APPROVED by John 2026-11-05 and BUILT (tracking-access, migration 038). Build: Claude. Author: Codex, 2026-11-05. Phase 1 Specify only; one capability: return access to a single application's public status.

## Latest clarification from John (2026-11-05, supersedes delivery assumptions below)
Gmail is the intended delivery channel, but the current environment is localhost; John needs two entry routes. Proposed interpretation for approval: (1) the private tracking URL delivered by Gmail once mail and a public base URL are configured, (2) the existing public Track Application page using reference + email for localhost/current manual access. Both resolve to the same applicant-safe status view. Submission confirmation also offers View application status and Copy tracking link so local development does not depend on mail. These are two entry paths, not duplicate application records or a separate employee login.
No real Gmail sending, credentials or public hosting setup in this draft. A localhost URL works only where that local server is reachable; cross-device/public acceptance requires a reachable configured host. The 90-day lifetime and temporary-account alternative below remain unapproved design proposals, not John's decision. Final mail enablement/error handling and link lifecycle need later Plan review. Questions for this draft: approve these two entry paths; choose private-link lifetime/recovery in Plan. Gmail delivery is the target, not a rejected feature.
## Assumptions for review
- John wants an applicant to return days later without remembering the reference number and email. Screenshot shows the existing public Track Application page after submission.
- Recommended direction, NOT approved: a reusable private tracking link and Copy link action; no temporary username/password or applicant account in this slice.
- Keep the public Track Application header entry and existing reference + email lookup as fallback. Email ownership is not currently proven by that lookup; do not describe it as verified identity.
- Proposed link lifetime: 90 days from issue, displayed to the applicant. This duration and recovery policy need approval.
- Email delivery is outside the first slice unless John explicitly selects it; copy/save must work without configured mail.

## Objective
An applicant can save one private URL immediately after applying and later open it on another device to see the current, applicant-safe status of that application without logging in. Success means a clear return path with limited disclosure and unchanged HR stage-management rules.

## Current source evidence
- index.php action apply calls RecruitmentRepository::createApplication, then redirects to track with ref and email in the query string.
- index.php page track accepts GET ref/email, renders the matching job/current stage/history and prints non-empty history comments.
- app/RecruitmentRepository.php::track uses prepared queries scoped by application reference and applicant email, then fetches all stage-history rows without applicant_visible filtering.
- app/RecruitmentRepository.php::createApplication returns a reference string; preserve existing callers when designing the later Plan.
- Existing recruitment map SPEC-recruitment-map.md already names rec-track; this draft refines return access only, not all future interview/deployment milestones.
- Graph query ran with Budget 1000 and reported stale/pending. Exact sources above were inspected instead.

## Proposed experience / success criteria
1. After successful submission, show a clear confirmation, job title, reference number, current status and a Save your tracking link panel. Copy private link gives truthful success/failure feedback; provide a selectable link when clipboard access fails. Browser bookmark/save is sufficient; no programmatic bookmark promise.
2. Opening the saved link on another browser/device shows the same application's latest allowed status without requiring ref/email entry. It must not grant access to other applications, even for the same email.
3. Keep Track Application in public navigation. Reference + email lookup remains usable for existing applicants and lost/expired links; do not fabricate tokens or backfill old applications silently. Issuing/replacing a private link for old applicants remains subject to an approved recovery policy.
4. Both tracking entry methods use the same safe display: job title, application reference, submitted date, applicant-visible current status and dated public milestones only. Respect applicant_visible and define a neutral public label when an internal stage is hidden; never infer advancement. Do not show raw history comments, screening ratings, scorecards, reviewer names/decisions, resume, contact details, salary/offer documents or internal HR notes. New applicant-facing messaging is outside scope.
5. Invalid, expired and revoked links reveal no application data and give a generic unable-to-open message with a route back to manual tracking. A terminal application remains viewable until expiry; no HR workflow change.
6. Copy control works with keyboard, associated labels, visible focus and announced feedback; long links wrap on a 360px screen and use existing public PMBSI styling. Scope UI work to confirmation/tracking.

## Security and lifecycle requirements (proposed, not implemented)
- Treat the URL as a bearer credential: anyone holding it can see the allowed status. Clearly say Keep this link private.
- Use an unpredictable cryptographic token scoped to one application; store only a verifier/hash, with expiry and revocation support. Do not build a credential out of email, reference or sequential IDs. Exact storage and authorization for revocation/reissue belong to the later approved Plan.
- Never create an employee/HR user or assign roles. A token cannot edit, withdraw, upload, approve or access HR/private-file endpoints.
- Use HTTPS and a configured trusted base URL on hosting, no-store caching, no-referrer, token-log redaction and no third-party resources on credential-bearing pages. Do not put email in newly issued private URLs or expose token values in analytics/errors/audit messages. Audit lifecycle events without secrets.
- Validate token format, lengths and expiry server-side, apply bounded abuse protection to both lookup paths, use prepared queries and escaped output. State-changing issue/revoke actions require CSRF and an explicitly approved authorization boundary. A GET must not rotate/revoke access.
- Reference + email fallback retains its existing weaker knowledge-based boundary and must show only the same limited data. No bulk lookup or cross-application dashboard.
- OWASP URL-token guidance informed entropy, secure storage, HTTPS, trusted URL generation and referrer protection: https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html . This is a reusable read-only tracking credential, not a single-use password-reset token; reuse until expiry is intentional and requires John's review.

## Tech stack / project structure
Existing PHP/PDO and MySQL/MariaDB HRIS, server-rendered public pages and existing CSS; no dependency addition. Installed runtime versions not revalidated.
- index.php: public apply/track routes and templates.
- app/RecruitmentRepository.php: createApplication/track and prepared data access.
- app/View.php: public header/footer and assets; inspect only if Plan needs changes.
- public/assets/: existing public styles/scripts; exact asset chosen during Plan.
- tests/: existing rec_screening_first_test.php is a createApplication caller; future focused checks must preserve its return contract.
- database/migrations/: possible additive token-storage migration; exact path/number pending Plan, NOT authorized or authored here.
- SPEC-applicant-tracking-link.md and HRIS-Vault/02-Modules/Recruitment-and-Onboarding.md: specification and shared context.
These are inspected sources/continuation candidates, not an implementation file commitment or technical Plan.

## Commands
Documentation review now: `git diff --check -- SPEC-applicant-tracking-link.md "HRIS-Vault/00-Meta/Shared Project Handoff.md"`.
After approved implementation, applicable syntax checks only:
```powershell
& 'C:\xampp\php\php.exe' -l index.php
& 'C:\xampp\php\php.exe' -l app/RecruitmentRepository.php
```
No build command is needed for this server-rendered scope. If JavaScript is added, specify the exact node --check command in Plan. No dev server, browser, app/DB tests or migration execution authorized by this spec.

## Code style
Preserve existing CRLF and targeted edits. Reuse PDO placeholders and e() output escaping, for example the existing lookup style:
```php
$st->execute([$ref,$email]); $row=$st->fetch(); if (!$row) return null;
```
Use project service/repository boundaries and existing UI tokens. No new framework or unrelated refactor.

## Testing strategy
Maximum three scenarios, specified here for later authorization; none executed now:
1. Fresh submission -> copy/fallback selection -> saved link reopens only that application on another device, status reflects subsequent approved HR updates.
2. Invalid/expired/revoked link -> no disclosure; manual lookup remains available and cannot enumerate other applications.
3. Both entry methods -> applicant-visible milestones only; no internal comments/private artifacts; keyboard/mobile/clipboard failure states remain usable.
Current project rules permit syntax/static inspection only. Browser/device/clipboard, endpoint/app/DB and migration-cycle evidence will remain unverified until separately authorized; static checks cannot prove these acceptance criteria. No coverage percentage invented. Review all affected createApplication/track callers and matching public render paths before implementation.

## Boundaries
- Always: approve this spec before Plan; approve Plan before Tasks, and Tasks before implementation per the invoked skill. Inspect exact sources, targeted edits, same-pattern security sweep and vault/checkpoint sync.
- Ask first: link-versus-account choice, expiry/recovery details, any token-storage schema and auth-boundary change, mail delivery, dependencies or runtime verification beyond project rules. Spec approval alone does not execute migrations or grant permissions.
- Never: change employee roles, hiring decisions/stage transitions, payments or existing application data; silently backfill; deploy/commit/push/delete; expose tokens/private HR data.
- List only: unrelated public about/contact client-portal copy, richer initial/mid/final interview tracker and other recruitment cleanup.

## Open questions / approval gate
- Q1: Approve Gmail tracking link (when configured) + public Track Application reference/email route (usable on localhost), sharing one safe status page? Temporary account remains an unselected alternative.
- Q2: Is 90-day link validity sufficient? If expired/lost, manual fallback stays available; any private-link reissue needs approved identity verification and revocation rules in Plan.
- Q3 resolved in direction: Gmail delivery is the intended final channel. Localhost must work without sending mail; actual mail integration/configuration scope and failure behavior need the later Plan, no credentials or real sends now.
Approve or revise this draft first. Stop at Phase 1; no technical Plan, task breakdown, code or migration in this turn.

## Scope extension - applicant-originated offer acceptance (2026-11-05)
John clarified that the applicant should receive/open the HR offer and acceptance must originate from the applicant. This is a second capability beyond this read-only tracker. See SPEC-applicant-access-map.md (PROPOSED) for tracking-access -> offer-response. Review the map before drafting offer-response; no approval or implementation inferred. The private offer view is separate from public/manual status and its privacy boundary. Existing read-only/offer-exclusion requirements above still govern tracking-access only; the future offer-response capability may display issued terms and accept after its own approved spec/Plan. Do not let reference/email lookup authorize acceptance. Current HR-only accept_offer and saveOffer SENT status are not proof of applicant response or email delivery. No code fixed now.

## Confirmed applicant stage mapping - John, 2026-11-05
John explicitly requested only these five applicant-facing progress labels: Screening -> Interview -> Offer -> Deployment -> Deployed. This mapping is a confirmed requirement; it is not approval to implement the full draft or change internal HR stages.

| HR internal stage/code | Applicant progress label |
|---|---|
| Screening / SCREENING | Screening |
| Interview / INTERVIEW | Interview |
| Endorsed / ENDORSED | Interview |
| Hiring Review / CLIENT_REVIEW | Interview |
| Offer / OFFER | Offer |
| Deployment / DEPLOYMENT | Deployment |
| Deployed / DEPLOYED | Deployed |

Apply this public projection to the current badge and progress/history through both applicant entry paths. Preserve HR internal stages, permissions, stage transitions and audit records. Never expose Endorsed or Hiring Review as applicant labels. Consecutive internal events mapping to Interview must not produce repeated Interview milestones or leak internal event details; a genuine return to an earlier public stage must remain truthful, not be flattened into forward completion. Show only reached public steps, with current versus future steps distinguished and no invented dates. Continue to exclude raw comments, scores and private reviewer information.
This explicit public mapping governs these named stages even where internal visibility differs; do not change recruitment_stages.applicant_visible flags or apply this display rule to HR screens. For unlisted/legacy/hold/terminal-failure states, public outcome wording remains a question for review: do not map them to a successful stage or falsely imply the application is still progressing. Five progress labels does not authorize hiding rejection/withdrawal/hold outcomes. Offer acceptance remains the separate protected offer-response capability, not an action granted by manual tracking.

Acceptance checks remain at most three, not executed: (1) Screening maps to Screening; (2) INTERVIEW/ENDORSED/CLIENT_REVIEW all display a single ongoing Interview step without internal labels/comments; (3) OFFER/DEPLOYMENT/DEPLOYED map exactly, and unlisted outcomes never fabricate advancement. Current status-only source path is index.php page track and RecruitmentRepository::track. No code/schema/data change. Next: review the updated capability map/spec and decide safe outcome messaging for unlisted states before Plan.
