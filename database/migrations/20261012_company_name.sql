-- Company Group -> Company Name (formerly "Brand / Client"); Employer is always PMBSI.
-- Source: 2LOG Company Directory 2024-2026.xlsx (CORPORATE NAME TIN & ADD, RETAIL DIRECTORY NEW). Idempotent.

-- 1. Company Group on the Company Name master (SW / OC / UF).
SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'business_units' AND column_name = 'company_group');
SET @ddl := IF(@has = 0, 'ALTER TABLE business_units ADD COLUMN company_group VARCHAR(8) NULL AFTER name', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Official company names.
INSERT INTO business_units (code, name, company_group, active, sort_order) VALUES
  ('2LOG',             '2L.O.G., INC.',                    'SW', 1, 10),
  ('CORTONA',          'Cortona Trading Corporation',      'SW', 1, 20),
  ('GRECO',            'Greco Industry OPC',               'SW', 1, 30),
  ('MONTEPULCIANO',    'Montepulciano Trading Corporation','SW', 1, 40),
  ('ORVIETO',          'Orvieto Trading Corporation',      'SW', 1, 50),
  ('PERUGIA',          'Perugia Ventures, Inc.',           'SW', 1, 60),
  ('POSITANO',         'Positano Trading Corporation',     'SW', 1, 70),
  ('STERLING',         'Sterling Warehouse, Inc.',         'SW', 1, 80),
  ('VICENZA',          'Vicenza Trading Corporation',      'SW', 1, 90),
  ('ALMANDITE',        'Almandite Ventures, Inc.',         'OC', 1, 110),
  ('OCAMPOS_VENTURES', 'Ocampo''s Ventures, Inc.',         'OC', 1, 120),
  ('CRYSSOBERYL',      'Cryssoberyl Ventures, Inc.',       'OC', 1, 130),
  ('ARTCHIC',          'Artchic Ventures OPC',             'OC', 1, 140),
  ('OCAMPOS',          'Ocampo''s',                        'OC', 1, 150),
  ('URBAN_FARM',       'Urban Farm',                       'UF', 1, 210)
ON DUPLICATE KEY UPDATE name = VALUES(name), company_group = VALUES(company_group), active = 1, sort_order = VALUES(sort_order);

-- 3. Move employees from the old short roster brands to the official company rows.
UPDATE employees e
  JOIN business_units old ON old.id = e.business_unit_id
  JOIN business_units new ON new.code = CASE UPPER(old.name)
        WHEN 'CORTONA' THEN 'CORTONA' WHEN 'MONTEPULCIANO' THEN 'MONTEPULCIANO' WHEN 'ORVIETO' THEN 'ORVIETO'
        WHEN 'POSITANO' THEN 'POSITANO' WHEN 'STERLING' THEN 'STERLING' WHEN 'VISCENZA' THEN 'VICENZA' WHEN 'VICENZA' THEN 'VICENZA' END
   SET e.business_unit_id = new.id
 WHERE old.code NOT IN ('2LOG','CORTONA','GRECO','MONTEPULCIANO','ORVIETO','PERUGIA','POSITANO','STERLING','VICENZA','ALMANDITE','OCAMPOS_VENTURES','CRYSSOBERYL','ARTCHIC','OCAMPOS','URBAN_FARM');

-- Retire the legacy brand rows (kept for history).
UPDATE business_units SET active = 0
 WHERE company_group IS NULL AND (code IN ('SILVERWORKS','OCAMPOS_FINE_JEWELLERY') OR code LIKE 'RI\_%');

-- 4. Employer is always PMBSI.
INSERT INTO legal_entities (code, name, active) VALUES ('PMBSI', 'PMBSI', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), active = 1;
UPDATE employees SET legal_entity_id = (SELECT id FROM legal_entities WHERE code = 'PMBSI') WHERE legal_entity_id IS NULL OR legal_entity_id <> (SELECT id FROM legal_entities WHERE code = 'PMBSI');
UPDATE branches SET default_legal_entity_id = (SELECT id FROM legal_entities WHERE code = 'PMBSI');

-- 5. Default company per branch (RETAIL DIRECTORY NEW).
UPDATE branches SET default_business_unit_id=(SELECT id FROM business_units WHERE code='ALMANDITE') WHERE code IN ('OBIC');
UPDATE branches SET default_business_unit_id=(SELECT id FROM business_units WHERE code='ARTCHIC') WHERE code IN ('OMEG');
UPDATE branches SET default_business_unit_id=(SELECT id FROM business_units WHERE code='CORTONA') WHERE code IN ('ASIA','CLRK','LIM2','LIP2','LZRO','MAIN','ROSA','SCAB','SCDO','TRIO');
UPDATE branches SET default_business_unit_id=(SELECT id FROM business_units WHERE code='CRYSSOBERYL') WHERE code IN ('OFAR','ORMI');
UPDATE branches SET default_business_unit_id=(SELECT id FROM business_units WHERE code='MONTEPULCIANO') WHERE code IN ('ANGO','BALI','FAR3','KINA','NOM2','NOMA','SBAC','SCBU','SMCL','SNAG','STAY');
UPDATE branches SET default_business_unit_id=(SELECT id FROM business_units WHERE code='OCAMPOS_VENTURES') WHERE code IN ('OGAL','OMOA');
UPDATE branches SET default_business_unit_id=(SELECT id FROM business_units WHERE code='ORVIETO') WHERE code IN ('BTGS','DSMA','GEN3','LCNA','MKT2','MLAO','MRKT','POLO','SPAB','VENI');
UPDATE branches SET default_business_unit_id=(SELECT id FROM business_units WHERE code='POSITANO') WHERE code IN ('BICU','CENT','FISH','MAN2','MEGA','SGEN','SOUT');
UPDATE branches SET default_business_unit_id=(SELECT id FROM business_units WHERE code='STERLING') WHERE code IN ('ERMI','GALE','JOSE','NOVA','PAMP','SMBB','SMLU','STAR','STLU');
UPDATE branches SET default_business_unit_id=(SELECT id FROM business_units WHERE code='VICENZA') WHERE code IN ('AYLA','BACO','FILI','GLOR','HILS','JARO','MANI','NAGA','PTX2','SDAV','SIDE','SILO','TNZA');
-- Branches that are not in the Excel store list.
UPDATE branches SET default_business_unit_id = (SELECT id FROM business_units WHERE code = 'VICENZA') WHERE code = 'VIX';
UPDATE branches SET default_business_unit_id = (SELECT id FROM business_units WHERE code = 'ORVIETO') WHERE code IS NULL AND name = 'Market Market';
UPDATE branches SET default_business_unit_id = (SELECT id FROM business_units WHERE code = '2LOG') WHERE site_type = 'HEAD_OFFICE';

-- 6. Backfill employees that have no company yet.
-- Head Office staff: Sw -> 2L.O.G., INC.; the known Ocampo's Head Office staff -> Ocampo's.
UPDATE employees e JOIN branches b ON b.id = e.branch_id AND b.site_type = 'HEAD_OFFICE'
   SET e.business_unit_id = (SELECT id FROM business_units WHERE code = IF(
        CONCAT(UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(e.first_name),' ',''),'.',''),'-',''),',',''),'Ñ','N')),'|',
               UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(e.last_name),' ',''),'.',''),'-',''),',',''),'Ñ','N')))
        IN ('RACHEL|BREGUERA','STEPHANIEANN|PALALON','ROSELYN|PARINA','JENELYN|GARROTE','ANGELYN|PLANCO','CHERRYMEI|SOLABAR','LEOBERTJHON|AMERICA'),
        'OCAMPOS','2LOG'))
 WHERE e.business_unit_id IS NULL;
-- Retail staff: the branch's company.
UPDATE employees e JOIN branches b ON b.id = e.branch_id
   SET e.business_unit_id = b.default_business_unit_id
 WHERE e.business_unit_id IS NULL AND b.default_business_unit_id IS NOT NULL;
-- Company Group follows the company.
UPDATE employees e JOIN business_units bu ON bu.id = e.business_unit_id
   SET e.directory_company_group = bu.company_group
 WHERE bu.company_group IS NOT NULL AND (e.directory_company_group IS NULL OR e.directory_company_group = '');
