# Todo: Profile parity patch 1 (plan: tasks/plan-profile-parity-p1.md)

- [x] T1 directory-field-labels
  - Acceptance: "Directory role" / "Automatic (from position)" / "Head Office team" / "No team" with one-line helpers; POST keys and values unchanged
  - Verify: php -l index.php app/DirectorySectionViews.php; tests/oc_head_office_sections_test.php; tests/adl_reporting_test.php
  - Files: index.php, app/DirectorySectionViews.php, app/EmployeeDirectoryPlacement.php, tests/adl_reporting_test.php
- [x] T2 profile-nav-dedupe
  - Acceptance: employee portal has only the sidebar My Profile; HR/Admin keep the dropdown Profile; route guard untouched
  - Verify: php -l app/View.php; render check per portal incl. acting-as
  - Files: app/View.php, one test
- [x] T3 hr-lee-code-field
  - Acceptance: HR edits Lee code (optional, max 30, blank = NULL), audited, saved on every update path, employee view still read-only
  - Verify: php -l; save/clear/31-char/non-HR cases on scratch DB
  - Files: index.php, app/EmployeeRepository.php, tests
- [ ] T4 Fix sweep + vault checkpoint + patch package + final report
