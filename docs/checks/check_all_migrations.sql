-- NexaCrest: which migrations does this database still need?
-- READ-ONLY: it only looks; it changes nothing and shows no business data.
-- How: phpMyAdmin > click your database > SQL tab > paste all of this > Go.
-- Then send a screenshot of the result table.
--
-- Fresh install of 14 Nov 2026 (owner decision D-372): the live database was installed from
-- schema.sql + seed.sql, which already hold every earlier change, so every older migration file
-- (2026-11-11a ... 2026-11-13g) was removed from docs/migrations (git history keeps them).
-- From now on every new file in docs/migrations gets ONE row below, in the same commit
-- (tests MigrationCheckScriptCoverageTest / migrationCheckScriptCoverage.test.js enforce it).
-- How to add a row: copy the example under the first SELECT, take out the "-- ", and put in the
-- file's id (e.g. 2026-12-01a), what it does, and a check that is 1 once the file has run
-- (a new column, a new table, or a new setting row). Plain SELECTs only (no variables, no PREPARE).
SELECT q.migration AS `Migration`, q.what AS `What it does`, q.status AS `Status`
FROM (
  SELECT '2026-11-14a' AS migration,
         'Order lines remember the rate their price was set at (D-374)' AS what,
         CASE (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'order_products'
                  AND column_name IN ('priced_fx_rate', 'priced_at'))
              WHEN 2 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END AS status
  UNION ALL
  SELECT '2026-11-15a',
         'Sales targets: 4 new tables, users.joined_on, orders.lost_category / sales_credit_user_id (D-378)',
         CASE (SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name IN ('sales_target_settings', 'sales_target_company', 'sales_targets', 'sales_target_credits'))
            + (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND ((table_name = 'users' AND column_name = 'joined_on')
                    OR (table_name = 'orders' AND column_name IN ('lost_category', 'sales_credit_user_id'))))
              WHEN 7 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END
  UNION ALL
  SELECT '2026-11-15b',
         'Report access per role: 2 new tables and the permission Manage report access (D-379)',
         CASE (SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name IN ('role_report_access', 'user_report_extras'))
            + (SELECT COUNT(*) FROM permissions WHERE permission_key = 'manage_report_access')
              WHEN 3 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END
  UNION ALL
  SELECT '2026-11-18a',
         'Forms of stone, Specifications (HS), Sold as: 4 new tables, finishes Roughly trimmed / Sawn, order_products.hs_spec_id / hs_override_reason and default HS 68029300, permission to change a line''s HS code (D-382 to D-385, D-389)',
         CASE (SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name IN ('product_forms', 'hs_specifications', 'product_hs_specifications', 'surface_finish_forms'))
            + (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'order_products'
                  AND column_name IN ('hs_spec_id', 'hs_override_reason'))
            + (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'order_products'
                  AND column_name = 'hs_code' AND column_default LIKE '%68029300%')
            + (SELECT COUNT(*) FROM permissions WHERE permission_key = 'override_line_hs_code')
            + (SELECT COUNT(*) FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value IN ('Roughly trimmed', 'Sawn'))
              WHEN 10 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END
  UNION ALL
  SELECT '2026-11-18b',
         'CHA + transport per supplier: suppliers.cha_transport_per_container / cha_port; a block price typed per tonne: catalog_product_suppliers.entered_unit / entered_price (D-381, D-382)',
         CASE (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND ((table_name = 'suppliers' AND column_name IN ('cha_transport_per_container', 'cha_port'))
                    OR (table_name = 'catalog_product_suppliers' AND column_name IN ('entered_unit', 'entered_price'))))
              WHEN 4 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END
  UNION ALL
  SELECT '2026-11-18c',
         'Bill of Lading wording: T&C clause 95 and the reference-library checklists (full set of originals, HS 68029300); Supplier PO order_supplier_po.product_form (D-380, D-387)',
         CASE (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'order_supplier_po' AND column_name = 'product_form')
            + (SELECT COUNT(*) FROM tc_clauses
                WHERE clause_title = 'Bill of Lading Release' AND INSTR(clause_text, 'Original negotiable BL (3 originals)') = 0)
            + IF((SELECT COUNT(*) FROM internal_reference_docs
                   WHERE INSTR(content, '3 Originals ONLY') > 0 OR INSTR(content, 'all 3 originals') > 0 OR INSTR(content, '6802.93 for all') > 0) = 0, 1, 0)
              WHEN 3 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END
  UNION ALL
  SELECT '2026-11-20a',
         'Quick-quote prospects: 5 new client columns (is_prospect, prospect_expires_on ...), 3 settings in Settings > Quick-quote prospects, the Sales task "Quotation expiring" (D-394)',
         CASE (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'clients'
                  AND column_name IN ('is_prospect', 'prospect_expires_on', 'prospect_reminded_on', 'prospect_expired_on', 'prospect_expired_reason'))
            + (SELECT COUNT(*) FROM company_settings WHERE setting_key IN ('prospect_expiry_days', 'prospect_reminder_days', 'prospect_auto_close'))
            + (SELECT COUNT(*) FROM department_task_types WHERE task_key = 'prospect_quote_expiring')
              WHEN 9 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END
  UNION ALL
  SELECT '2026-11-20b',
         'The role "Merchant Trade Executive": Sales/Export Manager permissions + manage_purchasing, view_purchase_prices; report access and SOP chapters (D-399)',
         CASE (SELECT COUNT(*) FROM roles WHERE name = 'Merchant Trade Executive')
            + (SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                WHERE r.name = 'Merchant Trade Executive' AND p.permission_key IN ('manage_purchasing', 'view_purchase_prices'))
            + (SELECT COUNT(*) FROM sop_role_chapters c JOIN roles r ON r.id = c.role_id WHERE r.name = 'Merchant Trade Executive' AND c.slug = '31-merchant-trade')
              WHEN 4 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END
  -- UNION ALL
  -- SELECT '2026-12-01a', 'Example: new column orders.example_col',
  --        IF((SELECT COUNT(*) FROM information_schema.columns
  --            WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'example_col') = 1,
  --           'already done', 'NEEDED')
) q;
