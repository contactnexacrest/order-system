-- Owner points 5 / 8 / 9 (2 Nov 2026): "every document has a draft and an approved copy — do we need
-- both?", "old CAFIN copies — keep or delete?", "give admin configuration to delete draft documents".
--
-- 1. file_store.removed_at / removed_reason — set when Admin → Document copies clean-up deletes a file.
-- 2. Three company_settings rows (category document_cleanup), all OFF by default.
-- 3. file_store.sent_to_client was never set (always 0). The app now marks the exact PDF a client was
--    sent (e-mail, "counted as sent" with e-mail off, client portal download). Back-fill: the current
--    PDF of every document whose status is 'sent'.
--
-- Run AFTER 2026-11-02m-generated-file-links.sql. Safe to run more than once. MySQL-safe: columns are
-- added through information_schema + PREPARE (no ADD COLUMN IF NOT EXISTS).
SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_store' AND COLUMN_NAME = 'removed_at');
SET @s := IF(@c = 0, 'ALTER TABLE file_store ADD COLUMN removed_at TIMESTAMP NULL AFTER is_active', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_store' AND COLUMN_NAME = 'removed_reason');
SET @s := IF(@c = 0, 'ALTER TABLE file_store ADD COLUMN removed_reason VARCHAR(255) NULL AFTER removed_at', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT x.k, x.v, x.t, 'document_cleanup', x.d, 0 FROM (
  SELECT 'doc_cleanup_draft_days' AS k, '0' AS v, 'number' AS t, 'Remove the DRAFT copy of an approved document after this many days (0 = off; 30, 90 or 180).' AS d
  UNION ALL SELECT 'doc_cleanup_superseded_days', '0', 'number', 'Remove the copies of a superseded revision that was never sent to the client after this many days (0 = off; 90, 180 or 365). The SUPERSEDED-stamped copy is kept.'
  UNION ALL SELECT 'doc_cleanup_cafin_mode', 'keep_all', 'string', 'Old CAFIN copies: keep_all, or latest_only (older copies removed 30 days after a newer one was made).'
) x WHERE NOT EXISTS (SELECT 1 FROM company_settings c WHERE c.setting_key = x.k);

UPDATE file_store fs
  JOIN documents d ON d.pdf_file_id = fs.id
   SET fs.sent_to_client = 1,
       fs.sent_at = COALESCE(fs.sent_at,
                             (SELECT MAX(el.sent_at) FROM email_log el WHERE el.document_id = d.id AND el.status = 'sent'),
                             fs.uploaded_at)
 WHERE d.status = 'sent'
   AND fs.sent_to_client = 0;
