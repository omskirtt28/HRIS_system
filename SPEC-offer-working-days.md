# Spec: Offer form "Working days per month" (22 or 26)

Status: APPROVED by John 2026-11-02 (recommendations used); built locally. Single capability, no capability map (Phase 0 skipped).
Builds on the shipped offer-rate-type slice (`SPEC-offer-rate-type.md`, migration `20261102_025`, `app/OfferRate.php`).

## Assumptions I'm making
1. Scope is the **Offer** card only (`index.php` ~158 POST, ~1686 form; `RecruitmentRepository::saveOffer` ~353; `app/OfferRate.php`).
2. "Pasok 22 days and 26 days" = a **Working days per month** select with two options: 22 days (Mon-Fri) and 26 days (Mon-Sat). It replaces the hard-coded `DAYS_PER_MONTH=26`.
3. It changes how Monthly <-> Daily <-> Hourly are converted (Daily 800 x 22 = 17,600; x 26 = 20,800). `offers.offered_salary` stays the canonical monthly figure.
4. Default is 26 so existing behaviour and existing offers do not change. Old offers (no stored value) are treated as 26.
5. Hours per day stays 8.
6. This is only the offer calculation; payroll rules, rates and cutoffs are not touched. Which divisor payroll itself uses stays NEEDS VERIFICATION (see Open Questions).

## Objective
When HR makes an offer, they pick Rate type, Working days per month (22 / 26) and type the rate. The form shows the live Hourly / Daily / Monthly equivalents using the chosen days, so the monthly salary is computed correctly for Mon-Fri and Mon-Sat work schedules. Saving keeps the typed rate, type, days and the computed monthly amount.

Success: HR never does the math by hand and the offer records which schedule was used.

## Behaviour
- Field order: Rate type | Rate (₱) | **Working days per month** (select: "26 days (Mon-Sat)" default, "22 days (Mon-Fri)") | Equivalent lines.
- Formulas: Monthly = Daily x days; Daily = Monthly / days; Hourly = Daily / 8.
- Live JS preview updates on any of the three inputs; server recomputes and ignores client values.
- Server accepts only 22 or 26; anything else is rejected with nothing saved.
- Reopening an offer pre-selects the saved days.
- Offer view/print/accept text shows "₱X per day, 22 working days (about ₱Y / month)" where it already shows the daily figure.
- English-only UI text.

## Data change (needs approval)
`offers` add `working_days TINYINT UNSIGNED NOT NULL DEFAULT 26` (additive, idempotent `ADD COLUMN IF NOT EXISTS`). Existing rows get 26, so no business row changes meaning. Down script guarded: refuses if any row has a value other than 26.

## Commands
```
Task check:  php scripts/check.php task
Full check:  HRIS_TEST_DB=<isolated db> php scripts/check.php full
Tests:       php tests/rec_offer_rate_test.php   (extend) ; php tests/rec_endorse_manager_test.php
Migration:   scripts/ticket_stageb_database.php pattern via hris-patch (dump + restore check first)
```

## Project structure
```
app/OfferRate.php               TYPES, DAYS=[22,26], convert(type, amount, days=26)
app/RecruitmentRepository.php   saveOffer: validate days, store working_days
index.php (~158, ~1686)         POST field + select + JS preview
database/migrations/            20261102_026_offer_working_days(.down).sql
tests/                          extend OfferRate + saveOffer tests
HRIS-Vault/                     Data-Model, Business-Rules, Screens, Progress, CHANGELOG, Session-Log, module note
```

## Code style
Match existing compact PHP:
```php
public static function convert(string $type,float $amount,int $days=26): array {
    if(!in_array($days,self::DAYS,true)) throw new RuntimeException('Choose 22 or 26 working days.');
    // ...same checks; replace self::DAYS_PER_MONTH with $days
}
```

## Testing strategy
- Unit: Daily 800 -> Monthly 17,600 @22 and 20,800 @26; Hourly 100 -> Daily 800 -> Monthly 17,600 @22; Monthly 26,000 -> Daily 1,181.82 @22 and 1,000 @26; days 21/0/"abc" rejected; default stays 26.
- Integration: saveOffer stores working_days and monthly; bad days saves nothing; old offers read as 26.
- Browser (isolated DB via `run-hris-system`): choose Daily, 800, switch 26 -> 22, preview changes, save, reload keeps 22.
- Migration up / re-run / down / up on isolated DB.

## Boundaries
- Always: server-side validation, CSRF/permission unchanged, ₱ and 2 dp, vault sync, `check.php task`.
- Ask first: the migration, changing allowed days beyond 22/26, applying to localhost `pmbsi_hris`.
- Never: change payroll rules/rates/cutoffs, hard-code days as legal fact, break `offered_salary` readers, push/deploy, weaken CONSTRAINTS.md.

## Success criteria
1. Offer form has a Working days per month select (22 / 26, default 26) with live equivalents.
2. Saved offer keeps days; monthly `offered_salary` matches the chosen days.
3. Server rejects any days other than 22 or 26.
4. Existing offers display and compute unchanged (26).
5. New tests and `check.php task` pass; no existing business rows changed.

## Open questions
1. Should the days select show for **all** rate types, or only Daily/Hourly? (Monthly -> Daily/Hourly equivalents still depend on it, so I propose showing it always.)
2. Labels: "22 days (Mon-Fri)" / "26 days (Mon-Sat)" OK, or plain "22 days" / "26 days"?
3. Should the offered days later feed payroll (employee's day divisor) on hire, or stay informational on the offer only? Proposed: informational only in this slice.
