-- External QA report L-07 (Medium, Both stacks): "'FDN' still printed:
-- CI 'Freight & Insurance paid separately via FDN'; Debit Note 'Please
-- quote FDN No.'" The document_types row itself (name/ref_format) was
-- already renamed "Freight Debit Note" -> "Debit Note" / "SC/FDN/" ->
-- "SC/DN/" directly in seed.sql (and on this session's own dev
-- databases), per the authoritative NexaCrest_Change_Log.txt
-- ("Tier1/06_DebitNote" — OLD: Freight Debit Note / SC/FDN/, NEW: Debit
-- Note / SC/DN/) — but, matching L-19's own pattern, that seed.sql edit
-- was never captured as its own migration, so a database provisioned
-- before that edit never gets it. The document_type_code itself stays
-- 'FDN' (an internal identifier only, never shown to the buyer) — only
-- the buyer-facing name and reference-number prefix change. The
-- template text that still spelled out "FDN" literally (the CI and the
-- Debit Note document's own PDF templates) was fixed in application
-- code, not here. Apply to both the PHP and Node MySQL databases —
-- same schema.

SET NAMES utf8mb4;
UPDATE document_types SET name = 'Debit Note', ref_format = 'SC/DN/{YYYY}/{DDMM}{NNN}' WHERE code = 'FDN';
