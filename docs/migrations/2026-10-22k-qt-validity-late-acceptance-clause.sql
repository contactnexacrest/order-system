-- External QA report L-09: "QT lacks the agreed clause: advance received
-- after 'Valid Until' is not acceptance / NexaCrest issues a revised QT
-- at same/updated prices. App says only 'subject to revision'." Extends
-- the existing 'Quotation Validity' clause (tc_clauses, linked to QT via
-- tc_clause_documents) with that missing sentence. Idempotent: the
-- WHERE clause only matches rows that still have the OLD, shorter text,
-- so a second run is a no-op. Apply to both the PHP and Node MySQL
-- databases — same schema.

SET NAMES utf8mb4;

UPDATE tc_clauses
SET clause_text = 'This Quotation is valid for {quotation_validity_days} days from the date of issue as shown in the Valid Until field above. After this date, prices and terms are subject to revision. An advance payment received after the Valid Until date does not constitute acceptance of this Quotation — NexaCrest will issue a revised Quotation at the same or updated prices before proceeding.'
WHERE clause_title = 'Quotation Validity'
  AND clause_text = 'This Quotation is valid for {quotation_validity_days} days from the date of issue as shown in the Valid Until field above. After this date, prices and terms are subject to revision.';
