# Spec: Offer form rate type (Daily / Monthly / Hourly) with peso input

Status: DRAFT v2 (John's answers folded in), waiting for final go-ahead. No code written. Single capability, so no capability map (Phase 0 skipped).

## Assumptions I'm making
1. Scope is the **Offer** card on the applicant page (`index.php` ~line 1685, `RecruitmentRepository::saveOffer`, `offers.offered_salary`).
2. "Same for other dropdowns" = the **Job Openings salary min/max** (`JobOpeningsViews.php:51`, `JobOpenings.php:40`) only if John confirms (see Open Questions). Employment type, start date and others are not salary fields and stay as is.
3. `offers.offered_salary` stays the canonical **monthly** amount, so every existing reader and the payroll hand-off keep working. We only add what HR typed.
4. Conversion factors are NOT known as legal fact. They are configurable constants, flagged NEEDS VERIFICATION (DOLE factors differ by pay arrangement).

## Objective
HR makes an offer by choosing **Rate type** (Daily / Monthly / Hourly), typing the amount with a ₱ prefix, and seeing the equivalent Daily / Hourly / Monthly figures update live. Saving stores the typed rate and type plus the computed monthly amount.

User: HR (Kristian Acosta role) on the Offer card. Success: HR never has to do the math by hand, and the offer shows what was actually typed.

## Behaviour
- Field order: Rate type (select, default Monthly) | Rate (₱ input, `inputmode="decimal"`, 2 dp) | read-only "Equivalent" lines: Hourly, Daily, Monthly.
- Formulas (constants in one place, default pending verification):
  - hours/day = 8; days/month = 26 (default, to confirm)
  - Daily = Monthly / days; Hourly = Daily / hours; Monthly = Daily x days = Hourly x hours x days
- Live preview in JS; **server recomputes** on save and ignores any client-computed value.
- Editing an existing offer pre-selects the saved type and typed rate.
- Offer view/print/accept screens show "₱X per day (≈ ₱Y / month)".

## Tech stack
PHP (no framework), MySQL `pmbsi_hris`, vanilla JS, `public/assets/hris-modern.css`. One migration adding columns to `offers` (needs approval, see Boundaries).

## Data change (proposed)
`offers` add: `rate_type ENUM('DAILY','MONTHLY','HOURLY') NOT NULL DEFAULT 'MONTHLY'`, `rate_amount DECIMAL(12,2) NULL`. Backfill existing rows: type MONTHLY, rate_amount = offered_salary. Down script guarded against used data.

## Commands
```
Task check:  php scripts/check.php task
Full check:  HRIS_TEST_DB=<isolated db> php scripts/check.php full
Tests:       php tests/rec_endorse_manager_test.php   (offer tests live here; add rec_offer_rate_test.php)
Migration:   php scripts/ticket_stageb_database.php (existing Stage B apply pattern, via hris-patch)
```

## Project structure
```
app/RecruitmentRepository.php   saveOffer(): validate type, compute monthly
app/OfferRate.php (new, tiny)   pure convert(type, amount) -> [hourly, daily, monthly]
index.php (~157, ~1685)         POST handler + Offer card form
public/assets/                  small JS for live preview (or inline like neighbours)
database/migrations/            offers rate columns
tests/                          unit test for OfferRate + saveOffer
HRIS-Vault/                     Data-Model, Business-Rules, Screens, Progress, CHANGELOG, Session-Log
```

## Code style
Match existing compact PHP:
```php
final class OfferRate {
    const TYPES=['DAILY'=>'Daily rate','MONTHLY'=>'Monthly rate','HOURLY'=>'Hourly rate'];
    public static function convert(string $type,float $amt): array { /* returns hourly, daily, monthly rounded to 2dp */ }
}
```
English-only UI text. Reject unknown type like `saveOffer` already rejects bad employment type.

## Testing strategy
- Unit: convert() for each type round-trips (Daily 800 -> Monthly 20,800; Hourly 100 -> Daily 800); zero, negative, non-numeric, huge values rejected.
- Integration: saveOffer stores type, rate and monthly; bad type refused with nothing saved; existing tests at `rec_endorse_manager_test.php:140-141` updated (they pass `offered_salary` only).
- Browser (isolated DB, `run-hris-system`): pick Daily, type 800, preview shows 20,800 monthly, save, reload shows Daily 800.
- Migration up / re-run / down / up on isolated DB.

## Boundaries
- Always: server-side validation, CSRF/permission checks unchanged, ₱ prefix and 2 dp, vault sync, run `scripts/check.php task`.
- Ask first: the migration (schema), Job Openings salary change, changing the conversion constants.
- Never: change payroll rules/rates, hard-code days/month as legal fact, break existing `offered_salary` readers, push/deploy, weaken CONSTRAINTS.md.

## Success criteria
1. Offer form has Rate type select + ₱ rate input + live equivalents.
2. Saved offer keeps typed type/rate and monthly `offered_salary`; old offers display unchanged.
3. Server rejects bad type / non-positive / non-numeric amounts.
4. New tests pass; `check.php task` passes; no existing business rows changed.

## Decisions (John, 2026-11-02)
- Dropdown options: Monthly rate, Daily rate, Hourly rate. Scope = Offer card only (Job Openings untouched).
- Default Monthly. If a saved offer was Daily (or Hourly), reopening it pre-selects that type and typed rate.

## Open questions
1. Days per month / hours per day not answered. Proceeding with **26 days / 8 hours** as one configurable constant, flagged NEEDS VERIFICATION, unless John says otherwise.
