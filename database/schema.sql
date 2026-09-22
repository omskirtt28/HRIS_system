CREATE DATABASE IF NOT EXISTS pmbsi_hris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pmbsi_hris;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS application_documents;
DROP TABLE IF EXISTS deployments;
DROP TABLE IF EXISTS offers;
DROP TABLE IF EXISTS client_reviews;
DROP TABLE IF EXISTS endorsements;
DROP TABLE IF EXISTS interviews;
DROP TABLE IF EXISTS screening_reviews;
DROP TABLE IF EXISTS application_stage_history;
DROP TABLE IF EXISTS applications;
DROP TABLE IF EXISTS applicants;
DROP TABLE IF EXISTS job_openings;
DROP TABLE IF EXISTS manpower_requests;
DROP TABLE IF EXISTS recruitment_stages;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS branches;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS clients;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE clients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(30) NOT NULL UNIQUE,
 name VARCHAR(180) NOT NULL,
 contact_email VARCHAR(190) NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE departments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(30) NOT NULL UNIQUE,
 name VARCHAR(120) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE branches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 client_id BIGINT UNSIGNED NULL,
 code VARCHAR(30) NOT NULL UNIQUE,
 name VARCHAR(160) NOT NULL,
 address_text VARCHAR(255) NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_branches_client FOREIGN KEY(client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

CREATE TABLE roles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(50) NOT NULL UNIQUE,
 name VARCHAR(120) NOT NULL,
 portal VARCHAR(30) NOT NULL,
 is_system TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 role_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NULL,
 full_name VARCHAR(160) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
 last_login_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_users_role FOREIGN KEY(role_id) REFERENCES roles(id),
 CONSTRAINT fk_users_client FOREIGN KEY(client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

CREATE TABLE recruitment_stages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(40) NOT NULL UNIQUE,
 name VARCHAR(80) NOT NULL,
 sequence_no INT NOT NULL,
 stage_type VARCHAR(30) NOT NULL DEFAULT 'NORMAL',
 client_visible TINYINT(1) NOT NULL DEFAULT 0,
 applicant_visible TINYINT(1) NOT NULL DEFAULT 1,
 active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE manpower_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_no VARCHAR(50) NOT NULL UNIQUE,
 client_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NULL,
 department_id BIGINT UNSIGNED NULL,
 position_title VARCHAR(160) NOT NULL,
 requested_headcount INT NOT NULL,
 filled_headcount INT NOT NULL DEFAULT 0,
 employment_type VARCHAR(40) NOT NULL,
 priority VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
 target_start_date DATE NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'SUBMITTED',
 requested_by BIGINT UNSIGNED NULL,
 approved_by BIGINT UNSIGNED NULL,
 approved_at DATETIME NULL,
 notes TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_mr_client_status(client_id,status),
 CONSTRAINT fk_mr_client FOREIGN KEY(client_id) REFERENCES clients(id),
 CONSTRAINT fk_mr_branch FOREIGN KEY(branch_id) REFERENCES branches(id),
 CONSTRAINT fk_mr_department FOREIGN KEY(department_id) REFERENCES departments(id),
 CONSTRAINT fk_mr_requested_by FOREIGN KEY(requested_by) REFERENCES users(id),
 CONSTRAINT fk_mr_approved_by FOREIGN KEY(approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE job_openings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 manpower_request_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 department_id BIGINT UNSIGNED NULL,
 job_code VARCHAR(50) NOT NULL UNIQUE,
 slug VARCHAR(180) NOT NULL UNIQUE,
 title VARCHAR(180) NOT NULL,
 description TEXT NOT NULL,
 requirements TEXT NULL,
 location_text VARCHAR(180) NULL,
 employment_type VARCHAR(40) NOT NULL,
 salary_min DECIMAL(12,2) NULL,
 salary_max DECIMAL(12,2) NULL,
 openings INT NOT NULL DEFAULT 1,
 status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
 is_featured TINYINT(1) NOT NULL DEFAULT 0,
 published_at DATETIME NULL,
 closes_at DATETIME NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_jobs_status(status,published_at),
 CONSTRAINT fk_jobs_mr FOREIGN KEY(manpower_request_id) REFERENCES manpower_requests(id),
 CONSTRAINT fk_jobs_client FOREIGN KEY(client_id) REFERENCES clients(id),
 CONSTRAINT fk_jobs_department FOREIGN KEY(department_id) REFERENCES departments(id),
 CONSTRAINT fk_jobs_created_by FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE applicants (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 applicant_no VARCHAR(50) NOT NULL UNIQUE,
 first_name VARCHAR(100) NOT NULL,
 middle_name VARCHAR(100) NULL,
 last_name VARCHAR(100) NOT NULL,
 suffix VARCHAR(30) NULL,
 email VARCHAR(190) NOT NULL,
 mobile_no VARCHAR(50) NOT NULL,
 source VARCHAR(50) NULL,
 consent_at DATETIME NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_applicant_email(email),
 INDEX idx_applicant_mobile(mobile_no)
) ENGINE=InnoDB;

CREATE TABLE applications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_no VARCHAR(60) NOT NULL UNIQUE,
 applicant_id BIGINT UNSIGNED NOT NULL,
 job_opening_id BIGINT UNSIGNED NOT NULL,
 manpower_request_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 current_stage_id BIGINT UNSIGNED NOT NULL,
 assigned_recruiter_id BIGINT UNSIGNED NULL,
 screening_score DECIMAL(5,2) NULL,
 why_fit TEXT NULL,
 applied_at DATETIME NOT NULL,
 last_stage_changed_at DATETIME NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_active_job_application(applicant_id,job_opening_id,status),
 INDEX idx_app_stage(current_stage_id,last_stage_changed_at),
 INDEX idx_app_client_stage(client_id,current_stage_id),
 INDEX idx_app_recruiter(assigned_recruiter_id,current_stage_id),
 CONSTRAINT fk_app_applicant FOREIGN KEY(applicant_id) REFERENCES applicants(id),
 CONSTRAINT fk_app_job FOREIGN KEY(job_opening_id) REFERENCES job_openings(id),
 CONSTRAINT fk_app_mr FOREIGN KEY(manpower_request_id) REFERENCES manpower_requests(id),
 CONSTRAINT fk_app_client FOREIGN KEY(client_id) REFERENCES clients(id),
 CONSTRAINT fk_app_stage FOREIGN KEY(current_stage_id) REFERENCES recruitment_stages(id),
 CONSTRAINT fk_app_recruiter FOREIGN KEY(assigned_recruiter_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE application_stage_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 from_stage_id BIGINT UNSIGNED NULL,
 to_stage_id BIGINT UNSIGNED NOT NULL,
 reason_code VARCHAR(50) NULL,
 comment TEXT NULL,
 changed_by BIGINT UNSIGNED NULL,
 changed_at DATETIME NOT NULL,
 INDEX idx_stage_history(application_id,changed_at),
 CONSTRAINT fk_hist_app FOREIGN KEY(application_id) REFERENCES applications(id),
 CONSTRAINT fk_hist_from FOREIGN KEY(from_stage_id) REFERENCES recruitment_stages(id),
 CONSTRAINT fk_hist_to FOREIGN KEY(to_stage_id) REFERENCES recruitment_stages(id),
 CONSTRAINT fk_hist_user FOREIGN KEY(changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE screening_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 reviewer_id BIGINT UNSIGNED NOT NULL,
 recommendation VARCHAR(30) NOT NULL,
 score DECIMAL(5,2) NULL,
 summary TEXT NULL,
 completed_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_screen_app FOREIGN KEY(application_id) REFERENCES applications(id),
 CONSTRAINT fk_screen_user FOREIGN KEY(reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE interviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 interview_type VARCHAR(80) NOT NULL,
 scheduled_at DATETIME NOT NULL,
 location_or_link VARCHAR(255) NULL,
 interviewer_id BIGINT UNSIGNED NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'SCHEDULED',
 notes TEXT NULL,
 result VARCHAR(30) NULL,
 rating DECIMAL(5,2) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_interviews_sched(scheduled_at,status),
 CONSTRAINT fk_interview_app FOREIGN KEY(application_id) REFERENCES applications(id),
 CONSTRAINT fk_interview_user FOREIGN KEY(interviewer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE endorsements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 endorsed_by BIGINT UNSIGNED NOT NULL,
 endorsement_note TEXT NULL,
 endorsed_at DATETIME NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'SENT',
 UNIQUE KEY uq_endorse_application_client(application_id,client_id),
 CONSTRAINT fk_endorse_app FOREIGN KEY(application_id) REFERENCES applications(id),
 CONSTRAINT fk_endorse_client FOREIGN KEY(client_id) REFERENCES clients(id),
 CONSTRAINT fk_endorse_user FOREIGN KEY(endorsed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE client_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 reviewer_user_id BIGINT UNSIGNED NOT NULL,
 decision VARCHAR(30) NOT NULL,
 remarks TEXT NULL,
 decided_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_review_app FOREIGN KEY(application_id) REFERENCES applications(id),
 CONSTRAINT fk_review_client FOREIGN KEY(client_id) REFERENCES clients(id),
 CONSTRAINT fk_review_user FOREIGN KEY(reviewer_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE offers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 offer_no VARCHAR(60) NOT NULL UNIQUE,
 offered_salary DECIMAL(12,2) NULL,
 employment_type VARCHAR(40) NULL,
 start_date DATE NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
 sent_at DATETIME NULL,
 responded_at DATETIME NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_offer_application(application_id),
 CONSTRAINT fk_offer_app FOREIGN KEY(application_id) REFERENCES applications(id),
 CONSTRAINT fk_offer_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE deployments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 deployment_no VARCHAR(60) NOT NULL UNIQUE,
 branch_id BIGINT UNSIGNED NULL,
 scheduled_date DATE NULL,
 actual_date DATE NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'PREPARING',
 notes TEXT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_deployment_application(application_id),
 CONSTRAINT fk_deploy_app FOREIGN KEY(application_id) REFERENCES applications(id),
 CONSTRAINT fk_deploy_branch FOREIGN KEY(branch_id) REFERENCES branches(id),
 CONSTRAINT fk_deploy_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE application_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 document_type VARCHAR(50) NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 stored_name VARCHAR(255) NOT NULL,
 mime_type VARCHAR(120) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_doc_app FOREIGN KEY(application_id) REFERENCES applications(id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 module VARCHAR(80) NOT NULL,
 action VARCHAR(80) NOT NULL,
 record_type VARCHAR(80) NULL,
 record_id BIGINT UNSIGNED NULL,
 details_json JSON NULL,
 ip_address VARCHAR(64) NULL,
 created_at DATETIME NOT NULL,
 INDEX idx_audit_time(created_at),
 INDEX idx_audit_record(record_type,record_id),
 CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO clients(code,name,contact_email) VALUES
('ABC','ABC Retail Corporation','hr@abcretail.com'),
('METROFOOD','Metro Food Services','hr@metrofood.com'),
('PRIMELOG','Prime Logistics Solutions','ops@primelogistics.com'),
('SILVERPEAK','Silver Peak Holdings','hr@silverpeak.com'),
('APEX','Apex Manufacturing Corporation','hr@apexmfg.com');

INSERT INTO departments(code,name) VALUES
('OPS','Operations'),('RETAIL','Retail'),('PROD','Production'),('MFG','Manufacturing'),('FIN','Finance'),('LOG','Logistics'),('HR','Human Resources');

INSERT INTO branches(client_id,code,name,address_text) VALUES
(NULL,'MKT-HQ','Makati HQ','Makati City'),
(NULL,'CEB','Cebu Branch','Cebu City'),
(NULL,'DAV','Davao Branch','Davao City'),
(3,'PL-LAG','Prime Logistics - Laguna','Laguna'),
(1,'ABC-MKT','ABC Retail - Makati','Makati City');

INSERT INTO roles(code,name,portal) VALUES
('SUPER_ADMIN','Super Administrator','admin'),
('HRIS_ADMIN','PMBSI Administrator','admin'),
('RECRUITMENT_MANAGER','Recruitment Manager','hr'),
('RECRUITER','Recruiter','hr'),
('COORDINATOR','Coordinator','hr'),
('CLIENT_USER','Client User','client');

INSERT INTO users(role_id,client_id,full_name,email,password_hash,status) VALUES
(1,NULL,'Winston Cruz','winston.cruz@pmbsi.com','$2y$12$Y6/lCPEgBs7GHo69Na/4D.r5j18Etgw2RETi0sDHYY1zilso7iRcK','ACTIVE'),
(3,NULL,'Andrea Domingo','a.domingo@pmbsi.com','$2y$12$Y6/lCPEgBs7GHo69Na/4D.r5j18Etgw2RETi0sDHYY1zilso7iRcK','ACTIVE'),
(4,NULL,'Rafael Villamor','r.villamor@pmbsi.com','$2y$12$Y6/lCPEgBs7GHo69Na/4D.r5j18Etgw2RETi0sDHYY1zilso7iRcK','ACTIVE'),
(4,NULL,'Camille Ledesma','c.ledesma@pmbsi.com','$2y$12$Y6/lCPEgBs7GHo69Na/4D.r5j18Etgw2RETi0sDHYY1zilso7iRcK','ACTIVE'),
(6,3,'Prime Logistics Client','ops@primelogistics.com','$2y$12$Y6/lCPEgBs7GHo69Na/4D.r5j18Etgw2RETi0sDHYY1zilso7iRcK','ACTIVE'),
(6,1,'ABC Retail Client','hr@abcretail.com','$2y$12$Y6/lCPEgBs7GHo69Na/4D.r5j18Etgw2RETi0sDHYY1zilso7iRcK','ACTIVE');

INSERT INTO recruitment_stages(code,name,sequence_no,stage_type,client_visible,applicant_visible) VALUES
('APPLIED','Applied',10,'NORMAL',0,1),
('SCREENING','Screening',20,'NORMAL',0,1),
('INTERVIEW','Interview',30,'NORMAL',0,1),
('ENDORSED','Endorsed',40,'NORMAL',1,1),
('CLIENT_REVIEW','Client Review',50,'NORMAL',1,1),
('OFFER','Offer',60,'NORMAL',1,1),
('DEPLOYMENT','Deployment',70,'NORMAL',1,1),
('DEPLOYED','Deployed',80,'TERMINAL_SUCCESS',1,1),
('REJECTED','Rejected',90,'TERMINAL_FAIL',0,1),
('WITHDRAWN','Withdrawn',91,'TERMINAL_FAIL',0,1),
('ON_HOLD','On Hold',92,'HOLD',0,1),
('RETURNED','Returned',93,'NORMAL',0,1);

INSERT INTO manpower_requests(request_no,client_id,branch_id,department_id,position_title,requested_headcount,filled_headcount,employment_type,priority,target_start_date,status,requested_by,approved_by,approved_at,notes) VALUES
('MR-20260901-0001',3,4,1,'Warehouse Supervisor',4,0,'FULL_TIME','URGENT','2026-10-01','OPEN',2,1,NOW(),'Priority warehouse expansion.'),
('MR-20260901-0002',1,5,2,'Retail Store Associate',12,2,'FULL_TIME','NORMAL','2026-10-05','PARTIALLY_FILLED',2,1,NOW(),'Retail ramp-up.'),
('MR-20260901-0003',2,NULL,3,'Food Production Staff',20,0,'CONTRACT','NORMAL','2026-10-10','OPEN',3,1,NOW(),'Production demand.'),
('MR-20260901-0004',5,NULL,4,'Production Line Operator',8,0,'FULL_TIME','NORMAL','2026-10-15','OPEN',4,1,NOW(),'Plant expansion.'),
('MR-20260901-0005',4,NULL,5,'Accounts Officer',2,0,'FULL_TIME','NORMAL','2026-10-01','OPEN',2,1,NOW(),'Finance vacancy.'),
('MR-20260901-0006',3,4,6,'Delivery Driver (Professional)',15,0,'FULL_TIME','URGENT','2026-09-30','OPEN',3,1,NOW(),'Last-mile delivery expansion.');

INSERT INTO job_openings(manpower_request_id,client_id,department_id,job_code,slug,title,description,requirements,location_text,employment_type,salary_min,salary_max,openings,status,is_featured,published_at,created_by) VALUES
(1,3,1,'JOB-WS-001','warehouse-supervisor','Warehouse Supervisor','Lead daily warehouse operations, manage inventory accuracy, and supervise a team across shifts.','3+ years warehouse/logistics supervision\nKnowledge of WMS systems\nWilling to work shifting schedules','Laguna, PH','FULL_TIME',32000,40000,4,'PUBLISHED',1,NOW()-INTERVAL 3 DAY,2),
(2,1,2,'JOB-RSA-001','retail-store-associate','Retail Store Associate','Deliver excellent customer service, handle POS transactions, and maintain store merchandising standards.','Customer-facing experience preferred\nFlexible schedule including weekends\nGood communication skills','Makati, PH','FULL_TIME',18000,22000,12,'PUBLISHED',1,NOW()-INTERVAL 1 DAY,2),
(3,2,3,'JOB-FPS-001','food-production-staff','Food Production Staff','Operate food production lines following strict HACCP and food-safety standards.','Food-handling certificate a plus\nAble to stand for long periods\nAttention to hygiene standards','Pasig, PH','CONTRACT',17500,20000,20,'PUBLISHED',1,NOW()-INTERVAL 5 DAY,3),
(4,5,4,'JOB-PLO-001','production-line-operator','Production Line Operator','Operate and monitor assembly line equipment; perform routine quality checks.','Vocational/technical background\nMachine operation experience\nShift work availability','Cavite, PH','FULL_TIME',19000,24000,8,'PUBLISHED',1,NOW()-INTERVAL 6 DAY,4),
(5,4,5,'JOB-AO-001','accounts-officer','Accounts Officer','Manage accounts payable/receivable, reconcile ledgers, and support monthly close.','Accountancy graduate\n2+ years AP/AR experience\nProficient in ERP/accounting software','BGC, Taguig','FULL_TIME',28000,34000,2,'PUBLISHED',1,NOW()-INTERVAL 2 DAY,2),
(6,3,6,'JOB-DD-001','delivery-driver','Delivery Driver (Professional)','Perform last-mile deliveries safely and on schedule across Metro Manila.','Professional driver’s license\nClean driving record\nFamiliarity with Metro Manila routes','Quezon City','FULL_TIME',18000,NULL,15,'PUBLISHED',1,NOW()-INTERVAL 4 DAY,3);

INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at) VALUES
('APP-2026-000901','Juan','Santos','juan.santos@email.com','+63 917 100 0001','CAREERS_SITE',NOW()),
('APP-2026-000902','Maria','Reyes','maria.reyes@email.com','+63 917 100 0002','REFERRAL',NOW()),
('APP-2026-000903','Jose','Cruz','jose.cruz@email.com','+63 917 100 0003','CAREERS_SITE',NOW()),
('APP-2026-000904','Anna','Bautista','anna.bautista@email.com','+63 917 100 0004','JOBSTREET',NOW()),
('APP-2026-000905','Mark','Garcia','mark.garcia@email.com','+63 917 100 0005','CAREERS_SITE',NOW()),
('APP-2026-000906','Grace','Mendoza','grace.mendoza@email.com','+63 917 100 0006','WALK_IN',NOW()),
('APP-2026-000907','Paolo','Torres','paolo.torres@email.com','+63 917 100 0007','REFERRAL',NOW()),
('APP-2026-000908','Liza','Flores','liza.flores@email.com','+63 917 100 0008','CAREERS_SITE',NOW()),
('APP-2026-000909','Ramon','Ramos','ramon.ramos@email.com','+63 917 100 0009','JOBSTREET',NOW()),
('APP-2026-000910','Carla','Aquino','carla.aquino@email.com','+63 917 100 0010','CAREERS_SITE',NOW()),
('APP-2026-000911','Nathan','Villanueva','nathan.v@email.com','+63 917 100 0011','REFERRAL',NOW()),
('APP-2026-000912','Bea','Castillo','bea.castillo@email.com','+63 917 100 0012','CAREERS_SITE',NOW());

INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,assigned_recruiter_id,screening_score,why_fit,applied_at,last_stage_changed_at,status) VALUES
('PMBSI-2026-000901',1,1,1,3,1,2,76,'Warehouse operations experience.',NOW()-INTERVAL 12 DAY,NOW()-INTERVAL 12 DAY,'ACTIVE'),
('PMBSI-2026-000902',2,2,2,1,2,3,82,'Retail customer service background.',NOW()-INTERVAL 11 DAY,NOW()-INTERVAL 9 DAY,'ACTIVE'),
('PMBSI-2026-000903',3,6,6,3,3,3,88,'Professional driving experience.',NOW()-INTERVAL 10 DAY,NOW()-INTERVAL 7 DAY,'ACTIVE'),
('PMBSI-2026-000904',4,1,1,3,4,2,91,'Warehouse lead with WMS knowledge.',NOW()-INTERVAL 9 DAY,NOW()-INTERVAL 5 DAY,'ACTIVE'),
('PMBSI-2026-000905',5,2,2,1,5,4,84,'Retail and POS experience.',NOW()-INTERVAL 8 DAY,NOW()-INTERVAL 4 DAY,'ACTIVE'),
('PMBSI-2026-000906',6,3,3,2,6,3,79,'Food production and hygiene.',NOW()-INTERVAL 8 DAY,NOW()-INTERVAL 3 DAY,'ACTIVE'),
('PMBSI-2026-000907',7,4,4,5,7,4,86,'Machine operations experience.',NOW()-INTERVAL 7 DAY,NOW()-INTERVAL 2 DAY,'ACTIVE'),
('PMBSI-2026-000908',8,5,5,4,8,2,90,'Accounting and reconciliation.',NOW()-INTERVAL 7 DAY,NOW()-INTERVAL 1 DAY,'ACTIVE'),
('PMBSI-2026-000909',9,6,6,3,4,3,83,'Driver with clean record.',NOW()-INTERVAL 6 DAY,NOW()-INTERVAL 2 DAY,'ACTIVE'),
('PMBSI-2026-000910',10,1,1,3,5,2,87,'Warehouse supervisory experience.',NOW()-INTERVAL 5 DAY,NOW()-INTERVAL 1 DAY,'ACTIVE'),
('PMBSI-2026-000911',11,2,2,1,3,4,80,'Retail experience.',NOW()-INTERVAL 4 DAY,NOW()-INTERVAL 1 DAY,'ACTIVE'),
('PMBSI-2026-000912',12,1,1,3,3,2,92,'Four years warehouse operations and WMS.',NOW()-INTERVAL 3 DAY,NOW()-INTERVAL 1 DAY,'ACTIVE');

INSERT INTO application_stage_history(application_id,from_stage_id,to_stage_id,comment,changed_by,changed_at)
SELECT id,NULL,current_stage_id,'Seeded application status',assigned_recruiter_id,applied_at FROM applications;

INSERT INTO interviews(application_id,interview_type,scheduled_at,location_or_link,interviewer_id,status,notes) VALUES
(3,'Initial Interview',NOW()+INTERVAL 1 DAY,'Makati HQ',3,'SCHEDULED','Bring valid ID.'),
(11,'Initial Interview',NOW()+INTERVAL 2 DAY,'Google Meet',4,'SCHEDULED','Online interview.'),
(12,'Initial Interview',NOW()+INTERVAL 3 DAY,'Makati HQ',2,'SCHEDULED','Warehouse supervisor screening interview.');

INSERT INTO endorsements(application_id,client_id,endorsed_by,endorsement_note,endorsed_at,status) VALUES
(4,3,2,'Strong match for warehouse role.',NOW()-INTERVAL 5 DAY,'SENT'),
(5,1,2,'Retail candidate ready for client review.',NOW()-INTERVAL 4 DAY,'SENT'),
(7,5,4,'Qualified production operator.',NOW()-INTERVAL 2 DAY,'SENT');


INSERT INTO offers(application_id,offer_no,offered_salary,employment_type,start_date,status,sent_at,responded_at,created_by) VALUES
(6,'OFR-20260920-0001',19000,'CONTRACT','2026-10-10','SENT',NOW()-INTERVAL 2 DAY,NULL,3),
(7,'OFR-20260919-0002',22000,'FULL_TIME','2026-10-05','ACCEPTED',NOW()-INTERVAL 3 DAY,NOW()-INTERVAL 2 DAY,4),
(8,'OFR-20260918-0003',32000,'FULL_TIME','2026-10-01','ACCEPTED',NOW()-INTERVAL 4 DAY,NOW()-INTERVAL 3 DAY,2);

INSERT INTO deployments(application_id,deployment_no,branch_id,scheduled_date,actual_date,status,notes,created_by) VALUES
(7,'DEP-20260921-0001',3,'2026-10-05',NULL,'SCHEDULED','Coordinate pre-deployment documents.',4),
(8,'DEP-20260920-0002',1,'2026-09-22','2026-09-22','DEPLOYED','Completed deployment.',2);
