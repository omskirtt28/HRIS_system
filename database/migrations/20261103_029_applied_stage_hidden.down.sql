-- Select the intended database first. Safe to run any time: it only re-activates the Applied stage this migration hid.
UPDATE recruitment_stages SET active=1 WHERE code='APPLIED' AND EXISTS(SELECT 1 FROM stage_029_seed WHERE code='APPLIED');
DROP TABLE IF EXISTS stage_029_seed;
