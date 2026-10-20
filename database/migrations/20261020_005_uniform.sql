CREATE TABLE IF NOT EXISTS uniform_requests (
 ticket_id BIGINT UNSIGNED PRIMARY KEY,
 position VARCHAR(160) NOT NULL,
 size VARCHAR(20) NOT NULL,
 company VARCHAR(10) NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 id_lace TINYINT(1) NOT NULL,
 CHECK(quantity BETWEEN 1 AND 10), CHECK(company IN ('SW','OFJI')),CHECK(id_lace IN (0,1)),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id),FOREIGN KEY(branch_id) REFERENCES branches(id),
 KEY idx_uniform_queue(company,branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO ticket_types(code,name,routing_profile) VALUES('UNIFORM_REQUEST','Uniform Request','HR_ONLY');
