# Spec: rec-apply-job (Apply page auto position + HR applicant job details)

Status: DRAFT for John's review. No code written. Follows SPEC-rec-jobs.md (Q-J1: the public sees PMBSI only).

## Objective
1. **Careers Apply page**: the applicant must not choose "Position applying for". The job is whatever they clicked (`Apply for this role` on the job page), shown as read-only text, and submitted as a hidden field.
2. **HR Applicant Profile** (`?page=hr-applicant`): HR sees the same job details they see on Job Openings (HR side), so they do not have to open another page to know what the person applied for.

Success = an applicant can only apply to the job they came from; HR opens an applicant and sees that job's details, matching Job Openings.

## Facts found in the code
- `index.php:640` `apply` page: `<select name="job_opening_id">` over `RecruitmentRepository::publishedJobs()`, preselected from `?job_id=`.
- Public entry points that reach Apply **without** a job: header button "Apply Now" and footer "Apply now" (`app/View.php:85,91`, `url('apply')`). These need a decision (Q1).
- `index.php:71` `apply` action requires `job_opening_id`; `RecruitmentRepository::createApplication` re-checks `jobById()` with `JobOpenings::PUBLIC_WHERE`, so a closed/unpublished/forged id is already refused (`RecruitmentRepository.php:33`). Duplicate active application per job is already refused.
- `index.php:1627` `hr-applicant` header shows only job title and employer. `RecruitmentRepository::application()` already joins `job_openings` but selects only title, location_text, department_id, employment_type.
- Job Openings (HR) shows: title, employer, employment type, openings, applications count, status, closing date, salary, description, requirements (`app/JobOpeningsViews.php`).

## Assumptions (correct me now)
1. "Same as Job Openings" means a read-only **Job details** card on the applicant profile, not an editable form.
2. The applicant never sees the employer (public stays "PMBSI"); HR may see the real employer as today.
3. If the job was closed/unpublished after the person applied, HR still sees its details (it is history); the Apply page does not allow it.
4. No migration: all fields already exist on `job_openings`.

## Behaviour
### A. Apply page
- `?page=apply&job_id=N` with N public: show a "Position applying for" **read-only row** (job title, location, employment type) and a `Change` link back to Careers. Hidden `job_opening_id=N`. No dropdown.
- `?page=apply` with no/invalid/non-public `job_id`: **no form**. Show "Choose the job you are applying for" with a button to Careers (Q1 default). Never fall back to a dropdown.
- POST still trusts only the server check in `createApplication` (hidden field is never trusted).
- Visible text English only.

### B0. HR applicant profile layout, JobStreet-style (John 2026-11-02; supersedes the card placement in B)
One candidate page like JobStreet's employer view, not a stack of unrelated cards. Same data and actions, no new stage rules.

```
Header band : avatar | Full name | "Applied for: <job title>" | stage badge | application no. | applied date
              contact chips: email (mailto), mobile (tel)
              actions on the right: [Move to stage] [Schedule interview] [Download resume]
Stage tracker: Applied > Screening > Interview > Endorsed > Offer > Deployment > Hired (current highlighted;
               moving still uses the existing Move to form and its rules)
Left (main) : tabs  Resume | Application | Interviews
                Resume tab      = inline PDF preview, or Download button for DOC/DOCX; empty state "No resume uploaded"
                Application tab = screening answer + Job details card (B below)
                Interviews tab  = existing list + schedule form
Right rail  : Activity timeline (existing); Offer and Deployment cards when present
```
UX rules: stage change and Schedule interview reachable without scrolling; one primary (orange) button per area; existing Design-System tokens; responsive (rail drops below, tabs scroll sideways); tabs keyboard-focusable with aria roles; English only.

**Backend gap found:** the resume is saved in `application_documents` at apply time, but **no HR page or route opens or downloads it**. The JobStreet-style view needs one. New read-only route `hr-application-resume&id=`: same permission as `hr-applicant`; file found by application id (never a path from the request); streamed with the stored mime type, inline for PDF and attachment for DOC/DOCX, `X-Content-Type-Options: nosniff`; 404 if none; audit VIEW_RESUME; client portal gets no access. Vault: API-Surface.md and Screens.md.

### B. HR applicant profile, new "Job details" card
Fields (read-only): job code, job title, status (Draft/Published/Closed), employment type (new 8-type labels), location, openings, closes on, salary range, description, requirements (one per line). Link "Open job" to the Job Openings row/edit for users with `recruitment.manage`. Employer stays in the existing header. Placed under the header card, above Stage & actions. Shown to HR only; the **client portal** application view (`index.php:1796`) is unchanged.
- Extend `RecruitmentRepository::application()` select with the extra `j.*` columns (no new query).

## Commands
- Syntax: `php -l app/RecruitmentRepository.php`, `php -l index.php`
- Task check: `php scripts/check.php task`
- Full (scratch DB only): `HRIS_TEST_DB=<scratch copy>` then `php scripts/check.php full`

## Project structure (files expected)
- `index.php` (apply page block, hr-applicant block), `app/RecruitmentRepository.php` (`application()` select), a small view helper in `app/JobOpeningsViews.php` for the job details card (reuse the salary/type label code), `tests/` (one new case file or extend `rec_jobs_http_test.php`), `scripts/check.php` floor if tests added.

## Code style
Match the file: compact PHP, `e()` on every echoed value, `stage_label()` / `JobOpenings::TYPES` for the type label, `job_salary()` for salary.

## Testing strategy
- HTTP test (scratch DB): Apply with a public job shows title and no `<select name="job_opening_id">`; Apply with no job shows the Careers prompt and no form; POST with a forged non-public id is refused; POST with a valid id creates the application.
- HR profile test: page contains the job description, requirements, type label and salary of the applied job; closed job still renders; client portal page does not contain the new card.
- Browser check is not run unless John asks.

## Boundaries
- Always: CSRF, server re-validation of the job, escape output, English UI, no employer name on public pages.
- Ask first: changing the Apply Now header/footer links, showing applications inside the Job Openings page.
- Never: schema change, touching live `pmbsi_hris`, commit/push/deploy, weakening checks.

## Success criteria
1. Apply page has no position dropdown; the position is shown as text from the clicked job.
2. Apply without a valid public job cannot submit anything.
3. HR applicant profile shows the full job details above; closed jobs still show.
4. Existing checks pass; new tests added with raised floors.

## Decisions (John, 2026-11-02)
- Q2: summary row (type, location, openings, salary, closes on) always visible; description and requirements in a `<details>` collapsed by default.
- Q3: YES, the Applicants list gets Employment type and Location columns (from the applied job).
- Q1: explained to John, waiting for his go on the default (header/footer Apply Now go to Careers).

- Layout: JobStreet-style candidate page (B0) including the resume view/download route. John said "plan and build" after Q1-Q3 but asked for this to be in the spec first.

## Open questions (new)
- **Q4** Resume: inline PDF preview (recommended) or Download button only?
- **Q5** HR has no notes feature today. Skip a Notes tab (recommended, no new table); private HR notes can be their own slice later.

## Open questions (original wording)
- **Q1** The header "Apply Now" and footer "Apply now" have no job. Default proposed: they go to **Careers** (pick a job first); the Apply page without a job shows a "Choose a job" prompt. OK, or do you want those buttons to keep a general application with a dropdown?
- **Q2** Should the Job details card on the applicant profile be collapsed by default (`<details>`) or always open?
- **Q3** In the Applicants list, also show employment type / location columns? Default: no, profile only.
