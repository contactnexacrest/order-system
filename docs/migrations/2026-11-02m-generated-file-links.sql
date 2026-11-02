-- Owner points 6 / 15 (2 Nov 2026): "only permitted people see amounts — not a single leak".
-- A generated document keeps several files: the draft PDF, the final PDF made on approval, a
-- SUPERSEDED copy when a newer revision is approved, the internal DOCX, and every older CAFIN.
-- Only the document's CURRENT pdf/docx was recognised as belonging to it, so the other copies had no
-- document type, so no money class, so anyone with order access could download them (file-store
-- link, dossier ZIP). The app now writes file_store.linked_document_id on every generated file; this
-- back-fills existing rows:
--   1. files that are a document's current pdf/docx;
--   2. older generated copies, matched by order and file name "<CODE> <reference with - for />…".
-- Safe to run more than once (only rows without a link are touched). MySQL-safe (no IF EXISTS DDL).
SET NAMES utf8mb4;

UPDATE file_store fs
  JOIN documents d ON (d.pdf_file_id = fs.id OR d.docx_file_id = fs.id)
   SET fs.linked_document_id = d.id
 WHERE fs.linked_document_id IS NULL
   AND fs.file_origin <> 'RECEIVED';

UPDATE file_store fs
  JOIN (SELECT d.order_id, dt.code, d.document_reference, MAX(d.id) AS document_id
          FROM documents d
          JOIN document_types dt ON dt.id = d.document_type_id
         WHERE d.document_reference IS NOT NULL AND d.order_id IS NOT NULL
         GROUP BY d.order_id, dt.code, d.document_reference) x
    ON x.order_id = fs.order_id
   AND (fs.original_filename LIKE CONCAT(x.code, ' ', REPLACE(x.document_reference, '/', '-'), ' %')
        OR fs.original_filename LIKE CONCAT(x.code, ' ', REPLACE(x.document_reference, '/', '-'), '.%'))
   SET fs.linked_document_id = x.document_id
 WHERE fs.linked_document_id IS NULL
   AND fs.file_origin = 'GENERATED_AUTO';

-- 3. documents with no reference number (e.g. the COO preparation sheet): file name "<CODE> <CODE> …".
UPDATE file_store fs
  JOIN (SELECT d.order_id, dt.code, MAX(d.id) AS document_id
          FROM documents d
          JOIN document_types dt ON dt.id = d.document_type_id
         WHERE d.document_reference IS NULL AND d.order_id IS NOT NULL
         GROUP BY d.order_id, dt.code) x
    ON x.order_id = fs.order_id
   AND fs.original_filename LIKE CONCAT(x.code, ' ', x.code, ' %')
   SET fs.linked_document_id = x.document_id
 WHERE fs.linked_document_id IS NULL
   AND fs.file_origin = 'GENERATED_AUTO';
