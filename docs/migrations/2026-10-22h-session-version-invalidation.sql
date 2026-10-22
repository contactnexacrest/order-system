-- External QA report L-26 (Medium, Both stacks): "Security: changing
-- your own password does not end your other logged-in sessions (a
-- stolen session stays valid)." Adds users.session_version, incremented
-- on every password change (self-service, forgot-password, or admin
-- force-reset) and stored in the session at login time; the
-- SessionAuth middleware in both stacks compares the two on every
-- request and logs out any session whose stored value no longer
-- matches the current one. Apply to both the PHP and Node MySQL
-- databases — same schema.

SET NAMES utf8mb4;
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_changed_at;
