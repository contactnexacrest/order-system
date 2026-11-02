-- Owner point 11 + 12 (2 Nov 2026): the Supplier and Logistics Payments Guide
-- (Word + PDF) is listed in the Reference Library, where the other guideline
-- documents live. The files ship in git under
-- storage/assets/reference_library/ (supplier_and_logistics_payments_guide.pdf
-- and .docx) like the existing reference documents.
-- The storage path is taken from the existing "Quarry SOP" row, so no manual
-- path edit is needed; if that row is missing the rows keep the
-- __STORAGE_BASE_PATH__ placeholder (fix as in seed.sql's note).
-- MySQL-safe, safe to run twice (inserts only when the title is not there yet).
SET NAMES utf8mb4;

INSERT INTO reference_library_documents (title, file_path, file_original_name, file_mime_type)
SELECT 'Supplier & Logistics Payments Guide (PDF)',
       '__STORAGE_BASE_PATH__/assets/reference_library/supplier_and_logistics_payments_guide.pdf',
       'Supplier_and_Logistics_Payments_Guide.pdf', 'application/pdf'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM reference_library_documents WHERE title = 'Supplier & Logistics Payments Guide (PDF)');

INSERT INTO reference_library_documents (title, file_path, file_original_name, file_mime_type)
SELECT 'Supplier & Logistics Payments Guide (Word)',
       '__STORAGE_BASE_PATH__/assets/reference_library/supplier_and_logistics_payments_guide.docx',
       'Supplier_and_Logistics_Payments_Guide.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM reference_library_documents WHERE title = 'Supplier & Logistics Payments Guide (Word)');

SET @base := (SELECT SUBSTRING_INDEX(file_path, '/assets/reference_library/', 1)
              FROM reference_library_documents
              WHERE file_path LIKE '%/assets/reference_library/quarry_sop_block_selection_and_reservation.pdf'
                AND file_path NOT LIKE '\_\_STORAGE\_BASE\_PATH\_\_%' LIMIT 1);
UPDATE reference_library_documents
   SET file_path = REPLACE(file_path, '__STORAGE_BASE_PATH__', @base)
 WHERE @base IS NOT NULL
   AND title IN ('Supplier & Logistics Payments Guide (PDF)', 'Supplier & Logistics Payments Guide (Word)')
   AND file_path LIKE '\_\_STORAGE\_BASE\_PATH\_\_%';
