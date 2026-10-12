-- Maternity leave record (Live birth 105 days / Early termination of pregnancy 60 days). Idempotent.
CREATE TABLE IF NOT EXISTS employee_maternity_leaves (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id BIGINT UNSIGNED NOT NULL,
  leave_kind VARCHAR(30) NOT NULL,
  leave_days SMALLINT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  return_date DATE NOT NULL,
  previous_employment_type_id BIGINT UNSIGNED NULL,
  leave_request_id BIGINT UNSIGNED NULL,
  leave_request_created TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  med_cert_received_on DATE NULL,
  med_cert_document_id BIGINT UNSIGNED NULL,
  med_cert_received_by BIGINT UNSIGNED NULL,
  remarks VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_maternity_employee (employee_id, status),
  KEY idx_maternity_return (status, return_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
