-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- offer-rate-type (SPEC-offer-rate-type.md): HR types the offer as a Monthly, Daily or Hourly rate; offered_salary stays the monthly figure.
-- Existing offers keep rate_type MONTHLY and rate_amount NULL (readers fall back to offered_salary), so no existing row changes meaning.
ALTER TABLE offers
  ADD COLUMN IF NOT EXISTS rate_type ENUM('MONTHLY','DAILY','HOURLY') NOT NULL DEFAULT 'MONTHLY' AFTER offered_salary,
  ADD COLUMN IF NOT EXISTS rate_amount DECIMAL(12,2) NULL AFTER rate_type;
