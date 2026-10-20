CREATE TABLE IF NOT EXISTS ticket_manpower_requests (
 ticket_id BIGINT UNSIGNED PRIMARY KEY,section_branch VARCHAR(160) NOT NULL,requested_by BIGINT UNSIGNED NOT NULL,
 position_code VARCHAR(30) NOT NULL,employment_type VARCHAR(30) NOT NULL,request_type VARCHAR(30) NOT NULL,
 quantity INT UNSIGNED NOT NULL,replacement_of_employee_id BIGINT UNSIGNED NULL,
 job_description_qualifications TEXT NOT NULL,area_department VARCHAR(160) NOT NULL,date_served DATE NULL,
 FOREIGN KEY(ticket_id) REFERENCES tickets(id),FOREIGN KEY(requested_by) REFERENCES users(id),FOREIGN KEY(replacement_of_employee_id) REFERENCES employees(id),
 CHECK(quantity>=1),CHECK(employment_type IN ('Seasonal','Probationary')),CHECK(request_type IN ('Replacement','Additional')),
 CHECK((request_type='Replacement' AND replacement_of_employee_id IS NOT NULL) OR (request_type='Additional' AND replacement_of_employee_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO ticket_types(code,name,routing_profile,routing_config_json) VALUES('MANPOWER_REQUEST','Manpower Request','BRANCH_ADL_THEN_HR','{"HEAD_OFFICE":"HO_MANAGER_THEN_HR","BRANCH":"BRANCH_ADL_THEN_HR"}');
