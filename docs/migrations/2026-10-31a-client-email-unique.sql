-- QA Round 6, R6-N16 (owner decision D-129, 2026-10-28: one email per
-- client — the client portal logs in by it). The app already refuses an
-- email another client uses, but two saves at the same moment could both
-- pass that check. This adds a unique index on clients.email so the
-- database itself refuses the second.
--
-- BEFORE RUNNING: run docs/checks/2026-10-28-duplicate-client-emails.sql.
-- If it returns any row, give those clients their own email first
-- (Clients → Edit). This file never changes or deletes a client's email:
-- while a duplicate remains it adds no index and prints which emails are
-- shared — fix them and run it again.
--
-- An empty email ('') is stored as "no email" (NULL), so clients without
-- an email never clash. Safe to run more than once. MySQL-safe
-- (information_schema + PREPARE; no ADD ... IF NOT EXISTS).
SET NAMES utf8mb4;

UPDATE clients SET email = NULL WHERE email IS NOT NULL AND TRIM(email) = '';

SET @nx_duplicates := (SELECT COUNT(*) FROM (
  SELECT LOWER(TRIM(email)) FROM clients WHERE email IS NOT NULL GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) > 1
) AS shared);
SET @nx_has_index := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND INDEX_NAME = 'uq_clients_email');

SET @nx_sql := IF(@nx_has_index > 0,
  'SELECT ''uq_clients_email already in place — nothing to do.'' AS result',
  IF(@nx_duplicates > 0,
    'SELECT ''NOT APPLIED: some emails are shared by more than one client. Run docs/checks/2026-10-28-duplicate-client-emails.sql, give each client its own email, then run this file again.'' AS result',
    'ALTER TABLE clients ADD UNIQUE KEY uq_clients_email (email)'));
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;
