-- 2026-11-25c — Owner decision D-417 follow-up (25 Nov 2026, "go with your recommendation"): staff may add the
-- recording or screenshots of an associate's live video KYC call (MP4, MOV, WEBM, 3GP, JPG, PNG, PDF; up to 25 MB).
-- The file is kept with the associate's KYC documents (kind 'video_kyc'); only staff with "Manage associates" or
-- "Manage associate bank" can open it; it is never shown to the associate.
-- Plain INSERT … WHERE NOT EXISTS (owner D-371): safe to run twice.
-- Note: the server's PHP upload limit (upload_max_filesize / post_max_size) must be at least 25 MB for a 25 MB file.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'associate_video_kyc', 'mp4,mov,webm,3gp,jpg,jpeg,png,pdf', 26214400, 'Live video KYC recording or screenshots of an associate (D-417) — staff only (manage_associates / manage_associate_bank); never shown to the associate.'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'associate_video_kyc');
