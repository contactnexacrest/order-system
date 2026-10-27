-- External QA report M-02: "Portal visibility of history/downloads
-- configurable globally and per client, independently." The
-- history half (client_portal_show_order_history +
-- order_history_visibility_override) already existed; this adds the
-- same global + per-client override pair for document downloads. Apply
-- to both the PHP and Node MySQL databases — same schema.


SET NAMES utf8mb4;
-- External QA report L-32: IF NOT EXISTS makes this safe to re-run.
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'document_downloads_visibility_override') = 0, 'ALTER TABLE clients ADD COLUMN document_downloads_visibility_override TINYINT(1) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;


INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin)
SELECT 'client_portal_show_document_downloads', '1', 'boolean', 'clients',
       'On (default) = every client can download their own order''s approved/sent customer-facing documents from the portal. Off = the Documents section is hidden and direct download links are refused, unless an individual client''s own override says otherwise.',
       0, 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'client_portal_show_document_downloads');