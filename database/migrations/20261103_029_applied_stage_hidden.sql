-- Select the intended database first (never run blind on pmbsi_hris: dump first).
-- rec-screening-first (SPEC-stage-flow.md): hide the Applied stage; new applications start at Screening.
-- Only the stage row's active flag changes. Applications and history that point at Applied are untouched (code treats them as Screening).
-- Re-runnable. The ledger records that this run hid it, so the down script never re-activates a stage someone else hid.
CREATE TABLE IF NOT EXISTS stage_029_seed(code VARCHAR(30) NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO stage_029_seed(code) SELECT 'APPLIED' FROM recruitment_stages WHERE code='APPLIED' AND active=1;
UPDATE recruitment_stages SET active=0 WHERE code='APPLIED';
