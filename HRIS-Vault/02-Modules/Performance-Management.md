---
title: Performance Management
type: module
status: idea
tags: [module, hris, later]
updated: 2026-10-17
---

# Performance Management

## Purpose

Run periodic performance reviews that produce a defensible written record: goals set, behaviour assessed, a rating arrived at, and a development plan. The record matters as much as the rating — in the Philippine context a performance record is the evidentiary basis for regularisation decisions, promotion, and any eventual termination for poor performance.

> [!note] Status: Phase 3, deliberately
> This module is `idea` status and scheduled **Later** in [[Roadmap]]. It has zero overlap with the payroll critical path and is the highest-effort, lowest-urgency area. This note exists so the eventual build has a starting point and so its data needs are visible to [[Data-Model]] now, not so it gets built early.

## Scope

### In scope (when built)

- Review cycles: annual, semi-annual, probationary
- Review templates: competencies, KPIs, weightings, rating scales
- Goal setting and tracking, cascading from department objectives
- Self-assessment, manager assessment, optional peer and skip-level input
- Rating calculation from weighted components
- Calibration across a department or the company
- Review discussion record and employee acknowledgement
- Development plans and training needs identification
- Probationary evaluation feeding the regularisation decision in [[Employee-Records]]
- Performance improvement plans
- History and trend per employee

### Out of scope

- Merit increase computation → informs [[Payroll]] via a compensation change, but the increase decision is not automated
- Training delivery and course catalogue → out of scope per [[Vision-and-Scope]]
- Disciplinary process → HR policy
- Continuous feedback / praise feeds → not worth building for this company size
- 360-degree review as a default → optional component only

## Key Entities

| Entity | Purpose | Notable fields |
|---|---|---|
| `review_cycles` | A named review period. | `id`, `name`, `cycle_type` (`ANNUAL`/`SEMI_ANNUAL`/`PROBATIONARY`/`AD_HOC`), `period_from`, `period_to`, `self_assessment_due`, `manager_assessment_due`, `calibration_due`, `status` |
| `review_templates` | The form. | `id`, `name`, `applies_to_position_level`, `rating_scale_id`, `active` |
| `review_template_sections` | Sections and their weights. | `template_id`, `section_name`, `section_type` (`COMPETENCY`/`KPI`/`GOAL`/`NARRATIVE`), `weight_percent`, `sequence_no` |
| `review_template_items` | Individual rated items. | `section_id`, `item_name`, `description`, `weight_percent` |
| `rating_scales` | Scale definition. | `id`, `name`, `min_value`, `max_value`, `labels_json` |
| `goals` | An employee goal. | `id`, `employee_id`, `review_cycle_id`, `title`, `description`, `metric`, `target_value`, `actual_value`, `weight_percent`, `status`, `parent_goal_id`, `set_by`, `agreed_at` |
| `performance_reviews` | One review instance. | `id`, `employee_id`, `review_cycle_id`, `template_id`, `reviewer_employee_id`, `status` (`NOT_STARTED`/`SELF_ASSESSMENT`/`MANAGER_ASSESSMENT`/`CALIBRATION`/`DISCUSSION`/`ACKNOWLEDGED`/`CLOSED`), `self_score`, `manager_score`, `calibrated_score`, `final_rating`, `submitted_at`, `acknowledged_at` |
| `review_item_scores` | Per-item scores from each rater. | `review_id`, `template_item_id`, `rater_type` (`SELF`/`MANAGER`/`PEER`/`SKIP_LEVEL`), `rater_employee_id`, `score`, `comments` |
| `review_discussions` | The conversation record. | `review_id`, `discussed_at`, `discussion_notes`, `employee_comments`, `manager_comments` |
| `development_plans` | Agreed actions. | `review_id`, `employee_id`, `area`, `action`, `support_needed`, `target_date`, `status` |
| `improvement_plans` | PIP with a defined end state. | `id`, `employee_id`, `initiated_at`, `concern`, `expected_outcome`, `review_dates_json`, `outcome`, `closed_at` |
| `calibration_sessions` | Cross-team rating adjustment. | `id`, `review_cycle_id`, `department_id`, `facilitated_by`, `held_at`, `adjustments_json` |

## Core Flows

### Flow 1 — Run a review cycle

1. HR head opens a cycle with its period and phase due dates.
2. Eligible employees are determined: active, past a minimum tenure, not already in a probationary cycle.
3. A `performance_reviews` row is instantiated per employee with the template matching their position level, and the reviewer resolved from the reporting line as of the cycle start.
4. [[Notifications]] informs employees and managers of each phase opening.

### Flow 2 — Goal setting

1. At the start of the cycle, the manager and employee agree goals, optionally cascading from a department goal via `parent_goal_id`.
2. Each goal has a metric, a target and a weight; weights within the goal section must total 100%.
3. Both parties mark the goals as agreed, with a timestamp. An un-agreed goal set is a common source of dispute at review time.
4. Goals may be revised mid-cycle with the revision recorded, not overwritten.

### Flow 3 — Assessment and rating

1. Employee completes the self-assessment by its due date.
2. Manager completes their assessment. The manager's view of the self-assessment may be withheld until they submit, to reduce anchoring — a configuration choice.
3. The system computes a weighted score from section and item weights.
4. Optional peer or skip-level input is collected where the template includes it.

### Flow 4 — Calibration

1. Department or company ratings are reviewed together to reduce rater leniency and severity.
2. Adjustments are recorded with a reason, so a calibrated score never silently differs from the manager's.
3. The final rating is set after calibration.

### Flow 5 — Discussion and acknowledgement

1. Manager holds the discussion and records notes.
2. Employee may add their own comments — important for a defensible record.
3. Employee acknowledges. Acknowledgement means *received*, not *agreed*, and the UI must say so.
4. A development plan is agreed and tracked.

### Flow 6 — Probationary evaluation

1. A probationary cycle is instantiated automatically at a configured point before the probation end date, triggered from [[Employee-Records]].
2. The evaluation uses a probationary template with the agreed standards communicated at hire.
3. The outcome — regularise, extend, do not regularise — feeds the regularisation flow in [[Employee-Records]].
4. This is the one part of this module with a real compliance edge: the standards must have been communicated at the start of employment for a non-regularisation to be defensible.

## Business Rules

- **BR-820** — Section and item weights within a template must total 100%.
- **BR-821** — The reviewer is resolved from the reporting line as of the cycle start date, not the submission date.
- **BR-822** — A review cannot be closed without employee acknowledgement, or an explicitly recorded refusal to acknowledge.
- **BR-823** — Acknowledgement records receipt, not agreement.
- **BR-824** — A calibrated score differing from the manager's score must carry a recorded reason.
- **BR-825** — Goals must be marked agreed by both parties before the assessment phase opens.
- **BR-826** — A probationary evaluation must reference the performance standards communicated at hire.
- **BR-827** — A completed review is immutable; a correction is an appended addendum.
- **BR-828** — An employee may not be their own reviewer; such cases escalate one level up.
- **BR-829** — Review content is visible only to the employee, their reviewer, the reviewer's manager, and HR.

## Functional Requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-850 | The system shall define review cycles with phase due dates. (US-820) | Won't (MVP) |
| FR-851 | The system shall define review templates with weighted sections and items and a rating scale. (BR-820) | Won't (MVP) |
| FR-852 | The system shall instantiate reviews for eligible employees with the reviewer resolved from the reporting line at cycle start. (BR-821) | Won't (MVP) |
| FR-853 | The system shall support goal setting with metrics, targets, weights and cascading, requiring agreement by both parties. (US-821, BR-825) | Won't (MVP) |
| FR-854 | The system shall record goal revisions without overwriting the original. | Won't (MVP) |
| FR-855 | The system shall collect self and manager assessments and compute a weighted score. (US-820) | Won't (MVP) |
| FR-856 | The system shall optionally withhold the self-assessment from the manager until they submit. | Won't (MVP) |
| FR-857 | The system shall support optional peer and skip-level input per template. | Won't (MVP) |
| FR-858 | The system shall support calibration with recorded reasons for any adjustment. (BR-824) | Won't (MVP) |
| FR-859 | The system shall record the review discussion including employee comments. | Won't (MVP) |
| FR-860 | The system shall require employee acknowledgement, or a recorded refusal, before closing a review. (BR-822) | Won't (MVP) |
| FR-861 | The system shall support development plans with actions and target dates. | Won't (MVP) |
| FR-862 | The system shall support performance improvement plans with defined review dates and an outcome. | Won't (MVP) |
| FR-863 | The system shall instantiate a probationary evaluation ahead of the probation end date and feed its outcome to the regularisation decision. (US-203) | Won't (MVP) |
| FR-864 | The system shall display an employee's review history and rating trend. (US-821) | Won't (MVP) |
| FR-865 | The system shall make a completed review immutable, allowing only appended addenda. (BR-827) | Won't (MVP) |
| FR-866 | The system shall restrict review visibility to the employee, reviewer, reviewer's manager and HR. (BR-829) | Won't (MVP) |
| FR-867 | The system shall audit all review state transitions and score changes. | Won't (MVP) |

All marked `Won't (MVP)` per [[Roadmap]]. Priorities get re-set when the module is scheduled.

## Edge Cases

| Case | Expected handling |
|---|---|
| Employee changed managers mid-cycle | Both managers contribute; the reviewer of record is the one at cycle start per BR-821, with the previous manager's input captured as a separate rater. |
| Employee on extended leave for most of the cycle | Cycle marked not applicable with a reason, rather than rated on no data. |
| Manager refuses or fails to complete a review | Escalates to their manager; an unreviewed employee is a managerial failure, not an employee one, and should be reported as such. |
| Employee refuses to acknowledge | Record the refusal with a timestamp and a witness. BR-822. |
| Employee separates mid-cycle | Review closed as incomplete; any probationary evaluation still completes if it drove the separation. |
| Employee is their own manager (department head reporting to nobody) | Escalate to the next level or to HR head. BR-828. |
| Rating distribution is implausible, e.g. everyone rated top | Calibration exists precisely for this; surface the distribution before calibration. |
| Template changed mid-cycle | Instantiated reviews keep their original template version. Versioning required. |
| Probation standards were never documented at hire | The system should have captured them at onboarding; flag the gap rather than producing an evaluation that cannot be defended. |

## Open Questions

- [ ] Does the company run formal reviews today, and on what cycle?
- [ ] Are ratings tied to merit increases, and should the system compute a suggestion?
- [ ] Is calibration realistic at this company size?
- [ ] Is 360-degree feedback wanted, or does it create more conflict than value here?
- [ ] Are probationary standards currently documented at hire? (compliance question, worth answering now even though the module is deferred)
- [ ] Should this module be built at all, or is a documented offline process enough?

## Related

[[Employee-Records]] · [[Org-Structure]] · [[Recruitment-and-Onboarding]] · [[Offboarding-and-Clearance]] · [[Notifications]] · [[Auth-and-RBAC]] · [[Reports-and-Analytics]] · [[Roadmap]] · [[Data-Model]] · [[Business-Rules]] · [[Screens]] · [[Self-Service-Portal]]
