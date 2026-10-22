-- External QA report L-22 (High, Both stacks): the scanned Bill of Lading
-- upload (OrderController::recordBlIssued()/attachBlCopy() — PHP; the
-- same two actions in ordersController.js — Node) has always called
-- FileUploadService::handleUpload('bl_copy', 'bl_copy', ...) with context
-- key 'bl_copy', but that row was never added to file_upload_contexts —
-- neither in seed.sql nor in any prior migration. Every attempt to attach
-- a scanned BL copy fails with "Unknown upload context: bl_copy" (the
-- order itself still gets recorded; only the file attach step fails).
-- A fresh install now gets this row directly from seed.sql; this
-- migration brings an already-seeded database up to date. Apply to both
-- the PHP and Node MySQL databases — same schema/seed content.

SET NAMES utf8mb4;
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'bl_copy', 'pdf,jpg,jpeg,png', 10485760,
  'External QA report L-22: scanned FINAL Bill of Lading, attached at the Stage 7->8 BL-issued gate or any time after — distinct from draft_bl, which is the pre-approval draft.'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'bl_copy');
