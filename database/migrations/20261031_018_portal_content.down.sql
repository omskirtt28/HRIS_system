-- Select the intended database first. Removes the two portal content tables (uploaded files in storage/portal_downloads stay on disk).
DROP TABLE IF EXISTS portal_downloads;
DROP TABLE IF EXISTS portal_faqs;
-- Verify: SHOW TABLES LIKE 'portal_%'; returns no rows.
