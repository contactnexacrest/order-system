-- Owner decision D-215 (confirmed twice, 1 Nov 2026): the Export Service
-- Invoice — the order's service lines on their own invoice, so the
-- Commercial Invoice stays goods-only for customs. A new document type
-- only; safe to run twice.

SET NAMES utf8mb4;

INSERT INTO document_types (code, name, category, ref_format, never_shown_to_buyer, revision_enabled, min_reviewers_default, is_active)
SELECT 'ESI', 'Export Service Invoice', 'customer_facing', 'SC/ESI/{YY}/{DDMM}{NNN}', 0, 1, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM document_types WHERE code = 'ESI');

-- DOCX for the Export Service Invoice, like the other customer-facing documents.
INSERT INTO docx_generation_settings (document_type_id, is_enabled)
SELECT t.id, 1 FROM document_types t
WHERE t.code = 'ESI' AND NOT EXISTS (SELECT 1 FROM docx_generation_settings s WHERE s.document_type_id = t.id);
