-- Incident Report module, phase I1. Additive and re-runnable (MariaDB 10.4 / MySQL 8).
-- Incident Reports stay tickets (type INCIDENT_REPORT). This adds the case fields, the people on a case and the HR-set status flow.
-- No existing row is rewritten: the status CHECK only gains values, and the live database held no Incident Reports when this was written.

-- 1. Case status flow: SUBMITTED -> UNDER_REVIEW -> IN_PROGRESS -> RESOLVED -> CLOSED, plus DISMISSED. The five old values stay valid.
ALTER TABLE tickets DROP CONSTRAINT IF EXISTS chk_ticket_status,
 ADD CONSTRAINT chk_ticket_status CHECK(status IN ('DRAFT','SUBMITTED','PENDING_APPROVAL','PENDING_HR','IN_PROGRESS','COMPLETED','REJECTED','RETURNED','CANCELLED','UNDER_REVIEW','RESOLVED','CLOSED','DISMISSED'));

-- 2. What happened, when and where. The narrative and title stay in tickets.payload_json.
-- category is a code validated against the list in TicketIncident (the list is an assumption John has not confirmed); the CHECK only guards the shape.
CREATE TABLE IF NOT EXISTS incident_details (
 ticket_id BIGINT UNSIGNED NOT NULL,
 incident_at DATETIME NOT NULL,
 location_text VARCHAR(200) NOT NULL,
 branch_id BIGINT UNSIGNED NULL,
 category VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(ticket_id),
 KEY idx_incident_details_branch(branch_id),
 CONSTRAINT fk_incident_details_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE RESTRICT,
 CONSTRAINT fk_incident_details_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
 CONSTRAINT chk_incident_category CHECK(category REGEXP '^[A-Z_]{2,30}$'),
 CONSTRAINT chk_incident_location CHECK(CHAR_LENGTH(TRIM(location_text))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Incident case fields; readable only through the incident access rule in TicketIncident';

-- 3. People on a case besides the reporter (the reporter is tickets.requester_user_id) and HR (derived from the HR role).
-- has_access=1 lets the person read the case and its thread. The reporter's own picks get access only as CC; anyone HR adds gets access.
-- No soft delete: removing a person deletes the row, and the audit event in ticket_events keeps who and when.
CREATE TABLE IF NOT EXISTS incident_participants (
 ticket_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 employee_id BIGINT UNSIGNED NULL,
 role VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 has_access TINYINT(1) NOT NULL DEFAULT 0,
 added_by BIGINT UNSIGNED NOT NULL,
 added_by_hr TINYINT(1) NOT NULL DEFAULT 0,
 added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(ticket_id,user_id),
 KEY idx_incident_participants_user(user_id,ticket_id),
 KEY idx_incident_participants_employee(employee_id),
 KEY idx_incident_participants_added_by(added_by),
 CONSTRAINT fk_incident_participants_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE RESTRICT,
 CONSTRAINT fk_incident_participants_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_incident_participants_employee FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
 CONSTRAINT fk_incident_participants_added_by FOREIGN KEY(added_by) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT chk_incident_participant_role CHECK(role IN ('CC','INVOLVED','WITNESS')),
 CONSTRAINT chk_incident_participant_access CHECK(has_access=0 OR role='CC' OR added_by_hr=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Case participants; access without HR is possible only for a CC picked by the reporter';
