-- External QA report L-06 (High, Both): the Commercial Invoice's own
-- "BALANCE DUE NOW" clause (Section 7) hardcoded the Tier 2
-- (against-scanned-BL-copy) balance trigger wording, and a separate
-- order-independent `ci_balance_days_post_bl` company setting for its
-- day count, regardless of which payment preset the order was actually
-- on — a Tier 1 ("before shipment") order's CI wrongly claimed its
-- balance was due against a scanned BL copy. A prior pass in this
-- codebase had instead documented this as an intentional,
-- tier-invariant design decision, citing "NexaCrest_Change_Log.txt
-- Addendum Section 11 item 4" — that section exists, but its item 4 is
-- about the Debit Note's remittance-notification timeframe, not the CI
-- balance clause; there is no such decision in that document. The CI
-- now reuses the same order-driven balance_terms_text QT/PI/BuyerPO
-- already use (DocumentDataAssembler.php/.js), making
-- `ci_balance_days_post_bl` dead. Removed from seed.sql for fresh
-- installs; this drops the now-unused setting from an existing
-- install so it no longer appears, inert, in Company Settings.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — same schema). Safe to
-- re-run: a DELETE scoped to one setting_key finds nothing left to
-- remove on a second run, same idempotency guarantee an UPDATE's own
-- WHERE clause gives.

SET NAMES utf8mb4;

DELETE FROM company_settings WHERE setting_key = 'ci_balance_days_post_bl';
