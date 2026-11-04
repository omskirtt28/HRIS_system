-- Retail Manager: the head of the ADLs. Adds position "Retail Manager" (Retail dept) and role RETAIL_MANAGER.
-- The role gets MANAGER's grants except recruitment.* (no new permission). Re-runnable. Select the intended database first.
-- A role or position that already exists (same code) is left exactly as HR configured it and is NOT owned by this
-- migration, so neither the grants nor the down script touch it.
CREATE TABLE IF NOT EXISTS retail_manager_seed(item ENUM('ROLE','POSITION') PRIMARY KEY,ref_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB;
SET @first_run=(SELECT COUNT(*)=0 FROM retail_manager_seed);
SET @own_role=(@first_run=1 AND NOT EXISTS(SELECT 1 FROM roles WHERE code='RETAIL_MANAGER'));
SET @own_position=(@first_run=1 AND NOT EXISTS(SELECT 1 FROM positions WHERE code='RETAIL_MANAGER') AND EXISTS(SELECT 1 FROM departments WHERE UPPER(code)='RETAIL'));

INSERT INTO roles(code,name,portal,is_system) SELECT 'RETAIL_MANAGER','Retail Manager','employee',0 FROM DUAL WHERE @own_role=1;
INSERT IGNORE INTO retail_manager_seed(item,ref_id) SELECT 'ROLE',id FROM roles WHERE code='RETAIL_MANAGER' AND @own_role=1;

INSERT INTO positions(department_id,code,name,active) SELECT id,'RETAIL_MANAGER','Retail Manager',1 FROM departments WHERE UPPER(code)='RETAIL' AND @own_position=1 LIMIT 1;
INSERT IGNORE INTO retail_manager_seed(item,ref_id) SELECT 'POSITION',id FROM positions WHERE code='RETAIL_MANAGER' AND @own_position=1;

-- Grants only for a role this migration created: MANAGER's grants minus recruitment.* (John 2026-11-04: the Retail
-- Manager has no recruitment / Hiring Review role; ADLs raise a ticket when they need something).
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT s.ref_id,rp.permission_id FROM retail_manager_seed s
 JOIN roles m ON m.code='MANAGER' JOIN role_permissions rp ON rp.role_id=m.id JOIN permissions p ON p.id=rp.permission_id
 WHERE s.item='ROLE' AND p.code NOT LIKE 'recruitment.%';
