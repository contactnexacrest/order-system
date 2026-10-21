-- Phase 6 Item B: client order-history visibility (global + per-client
-- override). Adds one new clients column and one new company_settings
-- row. Apply to both the PHP and Node MySQL databases — same schema.

ALTER TABLE clients
  ADD COLUMN order_history_visibility_override TINYINT(1) NULL;

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin)
SELECT 'client_portal_show_order_history', '1', 'boolean', 'clients',
       'On (default) = every client sees their full order history, including completed/lost orders, on their portal dashboard. Off = clients see active/disputed orders only, unless an individual client''s own override says otherwise.',
       0, 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'client_portal_show_order_history');
