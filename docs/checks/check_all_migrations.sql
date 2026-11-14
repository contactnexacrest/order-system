-- NexaCrest: which migrations does this database still need?
-- READ-ONLY: it only looks; it changes nothing and shows no business data.
-- How: phpMyAdmin > click your database > SQL tab > paste all of this > Go.
-- Then send a screenshot of the result table.
-- One single SELECT (no variables, no PREPARE) covering every migration 2026-11-11a … 2026-11-13g.
SELECT q.migration AS `Migration`, q.what AS `What it does`, q.status AS `Status`
FROM (
  SELECT CONCAT('2026-11-', e.m) AS migration,
         CASE e.m WHEN '11a' THEN 'Quote in any unit, piece size' WHEN '11b' THEN 'E-mail settings and sessions' WHEN '11c' THEN 'Associates: KYC, commission, bank' WHEN '11d' THEN 'Thickness prices, FX rates, suppliers' WHEN '11e' THEN 'Approvals and setup checklist' WHEN '11f' THEN 'Plain-language e-mail templates' WHEN '11g' THEN 'Order comment e-mail status' WHEN '11h' THEN 'T/T clause uses the bank name setting' WHEN '12a' THEN 'Plain labels on settings' WHEN '12b' THEN 'Supplier base / calculated prices' WHEN '12c' THEN 'Annexure B, "as per annexure"' WHEN '12d' THEN 'Buyer details refresh approval' WHEN '12e' THEN 'Follow-ups (D-338)' WHEN '12f' THEN 'Buyer details self-approval: MD + ED' WHEN '12g' THEN 'Detailed product table' WHEN '12h' THEN 'Price lock, products amendment' WHEN '12i' THEN 'Monument option and defaults' WHEN '12j' THEN 'One surface-finish list' WHEN '13a' THEN 'Suppliers, products, configured base price' WHEN '13b' THEN 'Products: base price fixed, legacy prices linked' END AS what,
         CASE WHEN SUM(e.ok) = COUNT(*) THEN 'already done'
              WHEN SUM(e.ok) = 0 THEN 'NEEDED'
              ELSE CONCAT('PARTLY done (', SUM(e.ok), ' of ', COUNT(*), ') - run it again') END AS status
  FROM (
    SELECT x.m,
           CASE WHEN x.c IS NULL
                THEN (SELECT COUNT(*) FROM information_schema.tables  WHERE table_schema = DATABASE() AND table_name = x.t)
                WHEN x.c = '#marker' THEN (SELECT COUNT(*) > 0 FROM audit_log WHERE action_type = 'MIGRATION_2026_11_13B')
                ELSE (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = x.t AND column_name = x.c)
           END AS ok
    FROM (
      SELECT '11a' m, 'catalog_products' t, 'weight_t_per_cbm' c
      UNION ALL SELECT '11a' m, 'order_products' t, 'copied_from_id' c
      UNION ALL SELECT '11a' m, 'order_products' t, 'piece_length_cm' c
      UNION ALL SELECT '11a' m, 'order_products' t, 'piece_thickness_cm' c
      UNION ALL SELECT '11a' m, 'order_products' t, 'piece_width_cm' c
      UNION ALL SELECT '11a' m, 'product_categories' t, 'weight_t_per_cbm' c
      UNION ALL SELECT '11b' m, 'compliance_alert_owners' t, NULL c
      UNION ALL SELECT '11b' m, 'email_settings' t, NULL c
      UNION ALL SELECT '11c' m, 'associate_documents' t, 'id_doc_type' c
      UNION ALL SELECT '11c' m, 'associate_ladder_steps' t, NULL c
      UNION ALL SELECT '11c' m, 'associates' t, 'address_proof_due_on' c
      UNION ALL SELECT '11c' m, 'associates' t, 'commission_rule_changed_at' c
      UNION ALL SELECT '11c' m, 'vendor_bank_changes' t, NULL c
      UNION ALL SELECT '11d' m, 'catalog_product_suppliers' t, 'cha_transport_per_container' c
      UNION ALL SELECT '11d' m, 'catalog_product_suppliers' t, 'includes_packing_loading' c
      UNION ALL SELECT '11d' m, 'catalog_product_suppliers' t, 'supplier_id' c
      UNION ALL SELECT '11d' m, 'catalog_products' t, 'cha_transport_per_container' c
      UNION ALL SELECT '11d' m, 'catalog_products' t, 'container_cbm' c
      UNION ALL SELECT '11d' m, 'catalog_products' t, 'container_max_t' c
      UNION ALL SELECT '11d' m, 'catalog_products' t, 'thick_k_factor' c
      UNION ALL SELECT '11d' m, 'catalog_supplier_price_history' t, NULL c
      UNION ALL SELECT '11d' m, 'catalog_supplier_thickness_prices' t, NULL c
      UNION ALL SELECT '11d' m, 'fx_rates' t, NULL c
      UNION ALL SELECT '11d' m, 'product_categories' t, 'container_cbm' c
      UNION ALL SELECT '11d' m, 'product_categories' t, 'container_max_t' c
      UNION ALL SELECT '11d' m, 'product_categories' t, 'thick_k_factor' c
      UNION ALL SELECT '11d' m, 'suppliers' t, 'details_pending' c
      UNION ALL SELECT '11e' m, 'setup_checklist_ticks' t, NULL c
      UNION ALL SELECT '11e' m, 'short_payment_requests' t, NULL c
      UNION ALL SELECT '11g' m, 'order_comments' t, 'email_status' c
      UNION ALL SELECT '12b' m, 'catalog_product_suppliers' t, 'auto_fill' c
      UNION ALL SELECT '12b' m, 'catalog_product_suppliers' t, 'base_thickness_mm' c
      UNION ALL SELECT '12b' m, 'catalog_supplier_thickness_prices' t, 'calc_from_mm' c
      UNION ALL SELECT '12b' m, 'catalog_supplier_thickness_prices' t, 'how' c
      UNION ALL SELECT '12b' m, 'catalog_supplier_thickness_prices' t, 'quoted_on' c
      UNION ALL SELECT '12c' m, 'order_annexure_products' t, 'which' c
      UNION ALL SELECT '12c' m, 'order_annexure_terms' t, 'which' c
      UNION ALL SELECT '12c' m, 'order_products' t, 'as_per_annexure' c
      UNION ALL SELECT '12c' m, 'orders' t, 'annexure_b_mode' c
      UNION ALL SELECT '12c' m, 'orders' t, 'ci_annexure' c
      UNION ALL SELECT '12c' m, 'orders' t, 'include_annexure_b' c
      UNION ALL SELECT '12d' m, 'order_buyer_detail_requests' t, NULL c
      UNION ALL SELECT '12e' m, 'order_reorder_request_products' t, 'as_per_annexure' c
      UNION ALL SELECT '12g' m, 'order_products' t, 'pieces_count' c
      UNION ALL SELECT '12g' m, 'order_products' t, 'size_note' c
      UNION ALL SELECT '12g' m, 'orders' t, 'line_table_layout' c
      UNION ALL SELECT '12h' m, 'amendment_product_lines' t, NULL c
      UNION ALL SELECT '12h' m, 'amendments' t, 'activated_at' c
      UNION ALL SELECT '12h' m, 'amendments' t, 'amendment_type' c
      UNION ALL SELECT '12h' m, 'amendments' t, 'products_totals_json' c
      UNION ALL SELECT '12h' m, 'amendments' t, 'supplier_po_revision_needed' c
      UNION ALL SELECT '12h' m, 'order_products' t, 'qt_fixed_unit_price' c
      UNION ALL SELECT '12h' m, 'orders' t, 'buyer_po_amended_at' c
      UNION ALL SELECT '12h' m, 'orders' t, 'buyer_po_amended_by' c
      UNION ALL SELECT '12h' m, 'orders' t, 'buyer_po_amended_by_amendment_id' c
      UNION ALL SELECT '12h' m, 'orders' t, 'prices_fixed_at' c
      UNION ALL SELECT '12h' m, 'orders' t, 'prices_fixed_by_document_id' c
      UNION ALL SELECT '12h' m, 'orders' t, 'pricing_fx_rate_at_qt' c
      UNION ALL SELECT '12j' m, 'spec_definitions' t, 'list_key' c
      UNION ALL SELECT '12j' m, 'spec_options' t, 'dropdown_option_id' c
      UNION ALL SELECT '13a' m, 'catalog_product_suppliers' t, 'offer_active' c
      UNION ALL SELECT '13a' m, 'catalog_products' t, 'configured_at' c
      UNION ALL SELECT '13a' m, 'catalog_products' t, 'configured_base_follows_supplier' c
      UNION ALL SELECT '13a' m, 'catalog_products' t, 'configured_base_price_inr' c
      UNION ALL SELECT '13a' m, 'catalog_products' t, 'configured_base_thickness_mm' c
      UNION ALL SELECT '13a' m, 'catalog_products' t, 'configured_base_unit' c
      UNION ALL SELECT '13a' m, 'catalog_products' t, 'configured_by' c
      UNION ALL SELECT '13a' m, 'catalog_products' t, 'pricing_follow_global' c
      UNION ALL SELECT '13a' m, 'product_categories' t, 'buffer_pct' c
      UNION ALL SELECT '13a' m, 'product_categories' t, 'margin_pct' c
      UNION ALL SELECT '13a' m, 'product_categories' t, 'other_cost_pct' c
      UNION ALL SELECT '13b' m, 'catalog_products' t, 'legacy_no_supplier' c
      UNION ALL SELECT '13b' m, 'order_supplier_po' t, 'price_override_reason' c
      UNION ALL SELECT '13b' m, 'order_supplier_po' t, 'quoted_unit_price_inr' c
      UNION ALL SELECT '13b' m, 'order_supplier_po' t, 'supplier_offer_id' c
      UNION ALL SELECT '13b' m, 'audit_log' t, '#marker' c
    ) x
  ) e
  GROUP BY e.m
  UNION ALL
  SELECT '2026-11-11f', 'Plain-language e-mail templates', CASE WHEN EXISTS (SELECT 1 FROM email_templates WHERE template_key = 'bl_copy_sent' AND body LIKE '%your enquiry reference:%') THEN 'already done' ELSE 'NEEDED' END
UNION ALL SELECT '2026-11-11h', 'T/T clause uses the bank name setting', CASE WHEN EXISTS (SELECT 1 FROM tc_clauses WHERE clause_title = 'T/T (Telegraphic Transfer)' AND clause_text LIKE '%designated bank account at State Bank of India as stated%') THEN 'NEEDED' ELSE 'already done' END
UNION ALL SELECT '2026-11-12a', 'Plain labels on settings', CASE WHEN EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'fdn_overdue_days_c' AND description LIKE 'Days after the date on the freight debit note%') THEN 'already done' ELSE 'NEEDED' END
UNION ALL SELECT '2026-11-12f', 'Buyer details self-approval: MD + ED', CASE WHEN EXISTS (SELECT 1 FROM self_approval_rules s JOIN roles r ON r.id = s.role_id WHERE s.rule_key = 'buyer_details_change' AND r.name = 'Executive Director') THEN 'already done' WHEN EXISTS (SELECT 1 FROM audit_log WHERE action_type = 'SELF_APPROVAL_RULE_CHANGED' AND field_name = 'buyer_details_change') THEN 'not needed (rule set by hand in Admin)' ELSE 'NEEDED' END
UNION ALL SELECT '2026-11-12i', 'Monument option and defaults', CASE WHEN EXISTS (SELECT 1 FROM spec_options WHERE name = 'Monument (cut to size)') THEN 'already done' ELSE 'NEEDED' END
UNION ALL SELECT '2026-11-13c', 'Permission descriptions (text only)', CASE (SELECT COUNT(*) FROM permissions WHERE (permission_key = 'record_market_prices' AND description LIKE 'Record and edit supplier prices%') OR (permission_key = 'edit_order_post_confirmation' AND description LIKE '%Products & prices amendment.') OR (permission_key = 'manage_compliance_task_types' AND description LIKE '%uses manage_compliance_checklist%')) WHEN 3 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END
UNION ALL SELECT '2026-11-13d', 'Bank reference on order expenses and costs (ZOH-005)', CASE (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'order_expenses' AND COLUMN_NAME IN ('bank_reference', 'zoho_pushed_hash')) OR (TABLE_NAME = 'order_cost_entries' AND COLUMN_NAME = 'bank_reference'))) WHEN 3 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END
UNION ALL SELECT '2026-11-13e', 'Large-quantity levels per unit (item 10a)', CASE (SELECT COUNT(*) FROM company_settings WHERE setting_key IN ('quantity_warning_level_sqm', 'quantity_warning_level_sqft', 'quantity_warning_level_cbm', 'quantity_warning_level_ton', 'quantity_warning_level_pcs', 'quantity_warning_level_rmt')) WHEN 6 THEN 'already done' WHEN 0 THEN 'NEEDED' ELSE 'PARTLY done - run it again' END
UNION ALL SELECT '2026-11-13f', 'Remember an office computer = 7 days (D-369)', CASE WHEN (SELECT setting_value FROM company_settings WHERE setting_key = 'login_remember_browser_days') = '30' THEN 'NEEDED' ELSE 'already done' END
UNION ALL SELECT '2026-11-13g', 'Prices frozen at an approved PI (D-370)', CASE WHEN (SELECT COUNT(*) FROM orders o WHERE o.prices_fixed_at IS NULL AND EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE d.order_id = o.id AND dt.code IN ('PI', 'OC', 'BUYERPO', 'CI') AND d.status IN ('approved', 'sent', 'superseded'))) = 0 THEN 'already done' ELSE 'NEEDED' END
) q
ORDER BY q.migration;
