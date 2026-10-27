-- External QA report N-13 (High, Both): 2026-10-22e-buyer-action-link-
-- token.sql originally created buyer_action_token_plain as VARCHAR(64)
-- (the raw token's own length), before External QA report L-24 required
-- it to hold AES-256-GCM ciphertext instead (base64(iv . tag .
-- ciphertext) for a 64-char raw token needs ~124 chars) — that file's own
-- ADD COLUMN line was widened to VARCHAR(255) in place to match. Any
-- database that had already applied 2026-10-22e before that edit landed
-- never receives the widen: ADD COLUMN IF NOT EXISTS is a no-op once the
-- column already exists, whatever its type. Left at VARCHAR(64), the
-- encrypted token for a Buyer Action Link is silently too wide to store,
-- and generating one fails with "One of the fields you entered is too
-- long… Nothing was saved."
--
-- A plain MODIFY COLUMN is naturally idempotent (re-running the identical
-- definition is a no-op, not an error), unlike ADD COLUMN/ADD CONSTRAINT
-- — so this needs none of the information_schema existence-check dance.
-- Matches docs/schema.sql, which already shows VARCHAR(255) for a fresh
-- install.

SET NAMES utf8mb4;

ALTER TABLE orders
  MODIFY COLUMN buyer_action_token_plain VARCHAR(255) NULL;