-- 2026-11-11h — T/T definition names the bank from Settings (owner decision D-331,
-- 11 Nov 2026, confirmed twice by the owner).
--
-- What changes:
--   * The "T/T (Telegraphic Transfer)" definition clause printed on QT, PI, OC, CI and PL
--     read "... designated bank account at State Bank of India as stated in this document."
--     as fixed text. It now reads "... at {bank_name} as stated ...", and the document
--     builders (PHP and Node) fill {bank_name} from Settings › Company › Bank name, taken
--     from the company details saved with each document. With the bank name the live site
--     has today, every document prints exactly as before; if the bank name changes in
--     Settings, new documents follow it. Documents already issued never change.
--
-- Safe to run twice: the WHERE clause only matches the old fixed text. A clause the MD has
-- already reworded in Admin overrides is left alone.
-- Run after 2026-11-11g, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-11h-tt-clause-bank-name.sql
SET NAMES utf8mb4;

UPDATE tc_clauses
   SET clause_text = REPLACE(clause_text,
                             'designated bank account at State Bank of India as stated',
                             'designated bank account at {bank_name} as stated')
 WHERE clause_title = 'T/T (Telegraphic Transfer)'
   AND clause_text LIKE '%designated bank account at State Bank of India as stated%';

-- Check: one row, showing {bank_name}.
SELECT id, clause_text FROM tc_clauses WHERE clause_title = 'T/T (Telegraphic Transfer)';
