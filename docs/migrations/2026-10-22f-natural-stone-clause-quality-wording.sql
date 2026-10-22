-- External QA report L-05: the buyer-facing T&C (QT/PI/OC) still said
-- "Grade A quality" in the Natural Stone Characteristics clause — the
-- owner asked for "quality is as agreed" instead. A prior wording
-- revision (seed.sql's own "wording corrected to the authoritative
-- text" UPDATE) touched this same clause for a different reason but
-- left that phrase untouched. Updates the clause text on an
-- already-seeded database to match the corrected seed.sql; a fresh
-- install gets the corrected text directly from seed.sql and this is a
-- no-op there (WHERE clause won't match). Apply to both the PHP and
-- Node MySQL databases — same schema/seed content.

SET NAMES utf8mb4;
UPDATE tc_clauses
SET clause_text = 'The quality of granite supplied is as agreed, selected for colour consistency. As with all natural stone, minor variation in shade, grain and veining may occur between pieces — however, Natural Variation inherent to natural stone including colour, veining, and texture is not a defect. Any claim of colour non-conformance must be assessed against the agreed pre-shipment sample approved by the Buyer in writing prior to Loading. In the absence of an approved pre-shipment sample, the Buyer accepts all colour variation inherent to natural stone. Products will conform to the agreed specification.'
WHERE clause_title = 'Natural Stone Characteristics'
  AND clause_text LIKE '%Grade A quality%';
