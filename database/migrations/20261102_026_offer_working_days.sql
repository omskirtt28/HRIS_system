-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- offer-working-days (SPEC-offer-working-days.md): HR picks 22 (Mon-Fri) or 26 (Mon-Sat) working days per month for the offer maths.
-- Existing offers get 26, the divisor they were already computed with, so no existing row changes meaning.
ALTER TABLE offers
  ADD COLUMN IF NOT EXISTS working_days TINYINT UNSIGNED NOT NULL DEFAULT 26 AFTER rate_amount;
