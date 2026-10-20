-- Slice 15 / step 1. Additive; tested on XAMPP MariaDB 10.4. Do not seed Payroll Concern until step 2.
CREATE TABLE IF NOT EXISTS ticket_routing_profiles (
 code VARCHAR(50) PRIMARY KEY,
 steps_json JSON NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO ticket_routing_profiles(code,steps_json) VALUES
 ('HO_MANAGER_THEN_HR','["MANAGER","HR"]'),('BRANCH_ADL_THEN_HR','["ADL","HR"]'),('HR_ONLY','["HR"]');
CREATE TABLE IF NOT EXISTS ticket_types (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 name VARCHAR(160) NOT NULL,
 routing_profile VARCHAR(50) NOT NULL,
 routing_config_json JSON NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT chk_ticket_type_upper CHECK (code=UPPER(code)),
 CONSTRAINT fk_ticket_type_profile FOREIGN KEY(routing_profile) REFERENCES ticket_routing_profiles(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tickets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_no VARCHAR(50) NOT NULL UNIQUE,
 ticket_type_id BIGINT UNSIGNED NOT NULL,
 requester_user_id BIGINT UNSIGNED NOT NULL,
 subject_employee_id BIGINT UNSIGNED NOT NULL,
 company_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 payroll_period_id BIGINT UNSIGNED NULL,
 company_group_snapshot VARCHAR(30) NOT NULL,
 company_name_snapshot VARCHAR(160) NOT NULL,
 business_unit_id_snapshot BIGINT UNSIGNED NULL,
 status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'DRAFT',
 current_step_no INT UNSIGNED NOT NULL DEFAULT 0,
 payload_json JSON NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 filed_at DATETIME NULL,
 closed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT chk_ticket_status CHECK(status IN ('DRAFT','SUBMITTED','PENDING_APPROVAL','PENDING_HR','IN_PROGRESS','COMPLETED','REJECTED','RETURNED','CANCELLED')),
 CONSTRAINT fk_ticket_type FOREIGN KEY(ticket_type_id) REFERENCES ticket_types(id),
 CONSTRAINT fk_ticket_requester FOREIGN KEY(requester_user_id) REFERENCES users(id),
 CONSTRAINT fk_ticket_employee FOREIGN KEY(subject_employee_id) REFERENCES employees(id),
 CONSTRAINT fk_ticket_company FOREIGN KEY(company_id) REFERENCES legal_entities(id),
 CONSTRAINT fk_ticket_branch FOREIGN KEY(branch_id) REFERENCES branches(id),
 CONSTRAINT fk_ticket_period FOREIGN KEY(payroll_period_id) REFERENCES payroll_cutoffs(id),
 KEY idx_ticket_queue(status,company_id,branch_id,payroll_period_id),
 KEY idx_ticket_requester(requester_user_id,created_at),
 KEY idx_ticket_company_snapshot(company_group_snapshot,company_name_snapshot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ticket_steps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 step_no INT UNSIGNED NOT NULL,
 approver_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approver_user_id BIGINT UNSIGNED NULL,
 assignment_id_snapshot BIGINT UNSIGNED NULL,
 routing_profile_snapshot VARCHAR(50) NOT NULL,
 status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING',
 remarks TEXT NULL,
 acted_by BIGINT UNSIGNED NULL,
 acted_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ticket_step(ticket_id,step_no),
 KEY idx_ticket_step_inbox(approver_user_id,status),
 CONSTRAINT chk_ticket_step_type CHECK(approver_type IN ('MANAGER','ADL','HR')),
 CONSTRAINT chk_ticket_step_named CHECK(approver_type='HR' OR approver_user_id IS NOT NULL),
 CONSTRAINT chk_ticket_step_status CHECK(status IN ('PENDING','APPROVED','REJECTED','RETURNED','SKIPPED')),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 FOREIGN KEY(approver_user_id) REFERENCES users(id),
 FOREIGN KEY(acted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ticket_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(50) NOT NULL,
 from_status VARCHAR(30) NULL,
 to_status VARCHAR(30) NULL,
 details_json JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_ticket_event(ticket_id,id),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 FOREIGN KEY(actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ticket_comments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 author_user_id BIGINT UNSIGNED NOT NULL,
 body TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 FOREIGN KEY(author_user_id) REFERENCES users(id),
 KEY idx_ticket_comment(ticket_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ticket_attachments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 stored_name VARCHAR(100) NOT NULL UNIQUE,
 mime_type VARCHAR(100) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 uploaded_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 FOREIGN KEY(uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ticket_effects (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 effect_type VARCHAR(50) NOT NULL,
 target_payroll_period_id BIGINT UNSIGNED NULL,
 instruction_json JSON NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ticket_effect(ticket_id,effect_type),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 FOREIGN KEY(target_payroll_period_id) REFERENCES payroll_cutoffs(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Delimiter directives are supported by mysql/phpMyAdmin and the slice runner.
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS ticket_events_no_update BEFORE UPDATE ON ticket_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ticket events are append-only'; END$$
CREATE TRIGGER IF NOT EXISTS ticket_events_no_delete BEFORE DELETE ON ticket_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ticket events are append-only'; END$$
CREATE TRIGGER IF NOT EXISTS tickets_immutable_number BEFORE UPDATE ON tickets FOR EACH ROW
BEGIN
 IF NOT(OLD.ticket_no <=> NEW.ticket_no) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ticket number is immutable'; END IF;
 IF OLD.filed_at IS NOT NULL AND (NOT(OLD.company_id <=> NEW.company_id) OR NOT(OLD.company_group_snapshot <=> NEW.company_group_snapshot) OR NOT(OLD.company_name_snapshot <=> NEW.company_name_snapshot) OR NOT(OLD.business_unit_id_snapshot <=> NEW.business_unit_id_snapshot)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Filed company snapshot is immutable'; END IF;
END$$
CREATE TRIGGER IF NOT EXISTS ticket_steps_frozen BEFORE UPDATE ON ticket_steps FOR EACH ROW
BEGIN
 IF NOT(OLD.ticket_id <=> NEW.ticket_id) OR NOT(OLD.step_no <=> NEW.step_no) OR NOT(OLD.approver_type <=> NEW.approver_type) OR NOT(OLD.approver_user_id <=> NEW.approver_user_id) OR NOT(OLD.assignment_id_snapshot <=> NEW.assignment_id_snapshot) OR NOT(OLD.routing_profile_snapshot <=> NEW.routing_profile_snapshot) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ticket approval chain is frozen'; END IF;
END$$
CREATE TRIGGER IF NOT EXISTS ticket_steps_no_delete BEFORE DELETE ON ticket_steps FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ticket approval chain is retained'; END$$
DELIMITER ;
