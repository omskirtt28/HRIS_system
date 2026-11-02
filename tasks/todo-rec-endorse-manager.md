# Todo: rec-endorse-manager (plan: HRIS-Vault/05-Implementation/Plans/2026-11-02-rec-endorse-manager.md)

- [x] T1 migration 023 (application_reviewers + recruitment.review) + stage label "Manager Review"
  - Verify: stageb prepare/cycle/guard 23 on scratch; stage_label test
- [x] T2 RecruitmentRepository: reviewerChoices, defaultReviewers, endorse(reviewers), reviewQueue, reviewerApplication, decide, reviewerSchedule (+ decision/stage rules)
  - Verify: tests/rec_endorse_manager_test.php (fails first)
- [x] T3 HR applicant card: reviewer checklist + filter + results list; endorse action
- [x] T4 Reviewer portal: nav, review-candidates, review-candidate, resume route scope, CANDIDATE_REVIEW notifications
  - Verify: tests/rec_endorse_manager_http_test.php
- [x] T5 Sweep (offer types), floors in scripts/check.php, check.php task/full, ticket_lead_followup proof, vault sync, apply to pmbsi_hris
