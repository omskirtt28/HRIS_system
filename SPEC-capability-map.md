# Capability Map: HR/Employee profile parity (2026-10-31)

Status: APPROVED by John on 2026-10-31 (modules and build order). Individual module specs are still DRAFT until John confirms each.
Root `SPEC.md` (sidebar rail) and `SPEC-my-profile.md` are other features and are not changed.

| Module id | Responsibility | Depends on |
|---|---|---|
| `hr-lee-code-field` | Lee code input in the HR employment form; HR/Admin edit, optional, max 30, audited | none |
| `profile-nav-dedupe` | Remove the duplicate Profile link (employee portal dropdown) | none |
| `directory-field-labels` | Clear labels and helper text for Directory role and Head Office team | none |
| `hr-role-consolidation` | One normal HR user account can do payroll, recruitment and all other HR work (additive role_permissions data change) | none |
| `profile-shortcuts-shell` | Shared page/route/permission pattern for Payslip, Deductions, Additional, Downloads, FAQs; no more "Soon" on either side | none |
| `profile-downloads` | HR uploads files, employees download | profile-shortcuts-shell |
| `profile-faqs` | HR manages Q&A, employees read | profile-shortcuts-shell |
| `profile-payslip` | Page for Payslip. No manual entry now; computation arrives in a later patch | profile-shortcuts-shell |
| `profile-deductions` | Same as payslip | profile-shortcuts-shell |
| `profile-additional` | Same as payslip | profile-shortcuts-shell |

Build order: hr-lee-code-field, profile-nav-dedupe, directory-field-labels (independent, patch 1) then hr-role-consolidation (own patch, needs approval: permission change), then profile-shortcuts-shell, then profile-downloads and profile-faqs, then payslip, deductions, additional.

## Decisions from John (2026-10-31)
- Directory role / Head Office team are kept, not removed: they drive ADL/Manager grouping and Reporting To (`EmployeeDirectoryPlacement::isAdlSql`). Only relabel (Claude's decision, delegated by John).
- Profile link: sidebar stays for Employee, dropdown Profile removed there. HR/Admin keep the dropdown (Claude's decision, delegated by John).
- Payslip, Deductions, Additional: no manual encoding. Payroll computation comes in the next patch. For now each is a real page (not "Soon") with an honest empty state.
- Only HR edits. Any HR-portal user has full access to the HR side of these modules. No separate "HR Payroll" or "HR Recruitment" gating.

## Open questions
1. John (2nd answer): separate payroll/recruitment access should live in the normal HR user account. This is module `hr-role-consolidation`. Before its spec is final, read-only check which permissions RECRUITER, RECRUITMENT_MANAGER, COORDINATOR and any payroll role have that HR_USER lacks (`role_permissions`). Existing accounts on those roles are not converted automatically and no role is deleted without a separate OK.
2. "Additional" = bonuses and allowances (retail), 13th month pay and other extra pay (Head Office too). Confirmed by John. In this phase it is only a page label with an empty state.
