# Capability Map: Applicant access and offer response

Status: APPROVED by John 2026-11-05; tracking-access BUILT (migration 038). Next: offer-response spec. Author: Codex, 2026-11-05. Existing SPEC-applicant-tracking-link.md remains DRAFT; no Plan/tasks/build approval inferred.

## Confirmed request and proposed boundaries
John wants applicants to return to their job application, with Gmail as intended delivery and a website route usable while localhost. He clarified that acceptance of an HR-issued offer should originate from the applicant through this experience. This adds a separately testable write capability to the original status-only proposal.

| Module id | Responsibility | Depends on |
|---|---|---|
| tracking-access | Saved-link and manual reference/email entry to one applicant-safe application status view; delivery target Gmail, localhost entry available without real sends. | None |
| offer-response | Applicant views an explicitly issued offer and confirms acceptance; HR sees the applicant-originated result. Requires verified private offer access, not manual reference/email proof alone. | tracking-access |

Build order: tracking-access -> offer-response. Gmail configuration/sending is an integration concern of these modules; no third module or account system proposed. Exact routes and access credential design are deferred to reviewed module specs/Plan.

## Proposed connected experience for review
1. Applicant submits -> confirmation with View status/Copy tracking link. Later return through website Track Application or Gmail link when mail is configured.
2. HR explicitly issues an offer -> applicant can open a protected offer view. A public/manual status view may say Offer available; it does not disclose salary/terms or authorize acceptance by itself.
3. Applicant reviews the issued terms and explicitly confirms Accept -> persist applicant-originated acceptance and timestamp for that exact issued offer, visible to HR; never accept merely by opening an email/link.
4. Preserve the intended post-acceptance Deployment step, subject to the offer-response spec reviewing existing stage rules. No automatic employee conversion or account creation.
5. Localhost development must offer a local entry mechanism without claiming Gmail delivery or public reachability. Identity proof and any local preview/test mechanism need explicit design; no insecure public bypass or test credential in production.

## Verified current sources and gaps
- index.php:183 save_offer and :188 accept_offer require recruitment.manage. Thus the current acceptance action is HR-driven.
- RecruitmentRepository.php:577 saveOffer records SENT/sent_at and audit OFFER_SENT; it does not call a mail sender. Do not claim actual delivery from this status/audit.
- RecruitmentRepository.php:595 acceptOffer updates every offers row with application_id and moves the application to DEPLOYMENT. Do not expose/reuse this action directly as a public applicant endpoint: exact offer/version ownership, state, replay and atomicity need later design.
- Existing public track is read-only ref/email lookup and renders history comments. Both entry methods need safe public output as recorded in SPEC-applicant-tracking-link.md.

## Questions for later module specification, not implementation decisions
- Offer-specific credential/identity verification, expiry/revocation/reissue and treatment of changed, withdrawn, expired or already accepted offers; manual lookup must not become acceptance authority.
- Exact issued terms snapshot/version, explicit confirmation wording, CSRF, atomic state transition, idempotent retries and audit actor/evidence. A bearer link alone is not identity proof or an e-signature guarantee.
- Whether HR manual Mark accepted remains as a separately labeled assisted action or is removed; John has not approved changing that existing authority yet.
- Decline/request-changes/download/signature are not requested capabilities; ask during offer-response Specify, do not add silently.
- Gmail delivery, resend/failure semantics and localhost verification method; no actual mail send/setup authorized now.

## Gate and evidence
Review module responsibilities/dependency direction/build order first. After approval, revise tracking-access Specify as needed and write SPEC-offer-response.md in a later turn. Do not jump to Plan/tasks/code. No schema, permissions, offer data, money values, migration, runtime tests, package, dependency, deploy or commit changes. Source/caller inspection only; Graphify query returned stale/pending; no graph freshness claim. Existing baseline remains Claude's My Team compact v4/sidebar and migration 037 recorded local, hosting not deployed.

## Confirmed tracking-access refinement - John, 2026-11-05
Applicant progress has five labels only: Screening, Interview, Offer, Deployment, Deployed. HR Interview/Endorsed/Hiring Review all map to applicant Interview; internal HR stages stay intact. Detailed mapping and unlisted-outcome question in SPEC-applicant-tracking-link.md. This confirms a display requirement only, not the whole map/spec or build. Offer-response dependency unchanged.
