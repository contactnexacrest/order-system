-- 2026-11-18c — Owner decisions D-380 and D-387 (18 Nov 2026, document changes confirmed twice).
-- D-380: the Bill of Lading wording. (1) The live master T&C clause 95 "Bill of Lading Release" starts
-- "Bill of Lading: The full set of original negotiable Bills of Lading, as issued by the shipping line, will
-- be released to the buyer only after" instead of "Bill of Lading: Original negotiable BL (3 originals) will
-- be released to buyer only after"; the rest of the clause is unchanged. Only the master row in tc_clauses
-- changes — documents already issued keep the text they were issued with. (2) The reference-library
-- checklists (internal_reference_docs): every fixed "3 originals" becomes the full-set wording, "60% balance"
-- becomes "the balance", and "HS Code: 6802.93" becomes "68029300" (3 places). Each UPDATE uses REPLACE()
-- on the exact old text, so a second run finds nothing to replace and changes nothing; a checklist a user
-- already reworded by hand is left as it is.
-- D-387: new empty column order_supplier_po.product_form (Supplier PO Section 3 "Product Form").
-- PLAIN ALTER (owner D-371, 13 Nov 2026): phpMyAdmin on the shared host refuses the information_schema +
-- PREPARE guard. The column ALTER comes last; a second run only stops there with "#1060 Duplicate column
-- name", which is harmless.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
-- D-380 (B): T&C clause 95, the live master clause only.
UPDATE tc_clauses
   SET clause_text = REPLACE(clause_text, 'Bill of Lading: Original negotiable BL (3 originals) will be released to buyer only after', 'Bill of Lading: The full set of original negotiable Bills of Lading, as issued by the shipping line, will be released to the buyer only after')
 WHERE clause_title = 'Bill of Lading Release' AND INSTR(clause_text, 'Bill of Lading: Original negotiable BL (3 originals) will be released to buyer only after') > 0;
-- D-380 (D): the reference-library checklists.
UPDATE internal_reference_docs SET content = REPLACE(content, '- NexaCrest retains all 3 original BLs until the balance T/T is received and cleared — never release before that.', '- NexaCrest retains the full set of originals until the balance T/T is cleared — never release before that.')
 WHERE INSTR(content, '- NexaCrest retains all 3 original BLs until the balance T/T is received and cleared — never release before that.') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- HS Code: 6802.93 for all granite/monument products', '- HS Code: 68029300 for all granite/monument products')
 WHERE INSTR(content, '- HS Code: 6802.93 for all granite/monument products') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- HS Code on PL matches PI and CI: 6802.93 (or correct code per product)', '- HS Code on PL matches PI and CI: 68029300 (or correct code per product)')
 WHERE INSTR(content, '- HS Code on PL matches PI and CI: 6802.93 (or correct code per product)') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- ⚠ MANDATORY — BL TYPE: Original Negotiable Bill of Lading — 3 Originals ONLY. NEVER request Sea Waybill or Express BL.', '- ⚠ MANDATORY — BL TYPE: Original Negotiable Bill of Lading — full set of originals. Telex Release / surrendered BL only on the buyer''s request and with MD approval, after the balance is cleared. NEVER request Sea Waybill or Express BL.')
 WHERE INSTR(content, '- ⚠ MANDATORY — BL TYPE: Original Negotiable Bill of Lading — 3 Originals ONLY. NEVER request Sea Waybill or Express BL.') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, 'NexaCrest retains all 3 originals until 60% balance T/T is cleared.', 'NexaCrest retains the full set of originals until the balance T/T is cleared.')
 WHERE INSTR(content, 'NexaCrest retains all 3 originals until 60% balance T/T is cleared.') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- 3 original BLs received from CHA — count physically ⚠ If fewer than 3 originals received — contact CHA immediately', '- Full set of original BLs received from CHA — count physically against the number stated on the BL ⚠ If fewer originals received than the BL states — contact CHA immediately')
 WHERE INSTR(content, '- 3 original BLs received from CHA — count physically ⚠ If fewer than 3 originals received — contact CHA immediately') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- All 3 original BLs stored securely at NexaCrest', '- Full set of original BLs stored securely at NexaCrest')
 WHERE INSTR(content, '- All 3 original BLs stored securely at NexaCrest') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- BL ENDORSEMENT — on the BACK of each of the 3 original BLs:', '- BL ENDORSEMENT — on the BACK of each original BL of the full set:')
 WHERE INSTR(content, '- BL ENDORSEMENT — on the BACK of each of the 3 original BLs:') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, 'All 3 originals must be endorsed before couriering. Check all 3 — not just one.', 'Every original of the full set must be endorsed before couriering. Check every original — not just one.')
 WHERE INSTR(content, 'All 3 originals must be endorsed before couriering. Check all 3 — not just one.') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- All 3 original BLs endorsed (stamp + signature + date on back of each)', '- Full set of original BLs endorsed (stamp + signature + date on back of each)')
 WHERE INSTR(content, '- All 3 original BLs endorsed (stamp + signature + date on back of each)') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- 3 original BLs (endorsed)', '- Full set of original BLs (endorsed)')
 WHERE INSTR(content, '- 3 original BLs (endorsed)') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- BILL OF LADING: Always Original Negotiable BL — 3 originals. Consignee:', '- BILL OF LADING: Always Original Negotiable BL — full set of originals. Telex Release / surrendered BL only on the buyer''s request and with MD approval, after the balance is cleared. Consignee:')
 WHERE INSTR(content, '- BILL OF LADING: Always Original Negotiable BL — 3 originals. Consignee:') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, 'CHA hands all 3 originals to NexaCrest only.', 'CHA hands the full set of originals to NexaCrest only.')
 WHERE INSTR(content, 'CHA hands all 3 originals to NexaCrest only.') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- BL ENDORSEMENT: Endorse (rubber stamp + sign + date on back) all 3 originals only AFTER 60% balance is cleared.', '- BL ENDORSEMENT: Endorse (rubber stamp + sign + date on back) every original of the full set only AFTER the balance is cleared.')
 WHERE INSTR(content, '- BL ENDORSEMENT: Endorse (rubber stamp + sign + date on back) all 3 originals only AFTER 60% balance is cleared.') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- HS CODE: 6802.93 for all worked monumental granite products.', '- HS CODE: 68029300 for all worked monumental granite products.')
 WHERE INSTR(content, '- HS CODE: 6802.93 for all worked monumental granite products.') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- ⚠ BL TYPE FINAL CHECK: Original Negotiable BL — 3 originals ONLY. Consignee on BL:', '- ⚠ BL TYPE FINAL CHECK: Original Negotiable Bill of Lading — full set of originals. Telex Release / surrendered BL only on the buyer''s request and with MD approval, after the balance is cleared. Consignee on BL:')
 WHERE INSTR(content, '- ⚠ BL TYPE FINAL CHECK: Original Negotiable BL — 3 originals ONLY. Consignee on BL:') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- BL type confirmed: Original Negotiable BL — 3 originals', '- BL type confirmed: Original Negotiable BL — full set of originals')
 WHERE INSTR(content, '- BL type confirmed: Original Negotiable BL — 3 originals') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, '- Reminder set: collect 3 original BLs from CHA after vessel departure', '- Reminder set: collect the full set of original BLs from CHA after vessel departure')
 WHERE INSTR(content, '- Reminder set: collect 3 original BLs from CHA after vessel departure') > 0;
UPDATE internal_reference_docs SET content = REPLACE(content, 'do not release any of the 3 original Bills of Lading to the buyer', 'do not release any of the original Bills of Lading (the full set) to the buyer')
 WHERE INSTR(content, 'do not release any of the 3 original Bills of Lading to the buyer') > 0;
-- D-387: Supplier PO Section 3 "Product Form" (last: a second run stops here with the harmless #1060).
ALTER TABLE order_supplier_po ADD COLUMN product_form VARCHAR(80) NULL;
