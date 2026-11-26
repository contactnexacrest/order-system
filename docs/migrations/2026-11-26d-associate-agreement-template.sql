-- 2026-11-26d — Owner decisions D-437 and D-438 (26 Nov 2026).
--  - D-437: a Price Answer is valid for 30 days, the same as the Quotation (was 7 days, D-255).
--    Only changes the value if it is still the old default 7; a value set by hand in Admin › Pricing is kept.
--  - D-438: the Associate Agreement (Commission Structure) template is added to the Reference Library.
--    Upload the file storage/assets/reference_library/associate_agreement_template.docx with the code first.
-- No ALTER, no PREPARE (owner D-371): plain UPDATE and INSERT … WHERE NOT EXISTS; a second run changes nothing.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
UPDATE pricing_settings SET setting_value = '30'
 WHERE setting_key = 'price_request_valid_days' AND setting_value = '7';
INSERT INTO reference_library_documents (title, file_path, file_original_name, file_mime_type)
SELECT 'Associate Agreement — Commission Structure (Template)',
       '__STORAGE_BASE_PATH__/assets/reference_library/associate_agreement_template.docx',
       'Associate Agreement - Commission Structure (Template).docx',
       'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
WHERE NOT EXISTS (SELECT 1 FROM reference_library_documents x
                   WHERE x.title = 'Associate Agreement — Commission Structure (Template)');
