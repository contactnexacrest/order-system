-- Owner requests (2026-10-26, t3). Safe to run more than once.
SET NAMES utf8mb4;

-- The CHA's draft BL (and the scanned final BL) can arrive as any common
-- document, image, email or archive — not just a PDF.
UPDATE file_upload_contexts
   SET allowed_extensions = 'pdf,doc,docx,xls,xlsx,odt,ods,rtf,txt,csv,jpg,jpeg,png,gif,webp,bmp,tif,tiff,heic,eml,msg,zip'
 WHERE context_key IN ('draft_bl', 'bl_copy');
