-- Owner decision C-16 (confirmed twice, 1 Nov 2026): CFR wording. Under CFR the buyer arranges the insurance.
-- The "Shipment Booking (CFR/CIF)" clause asks for freight only on CFR orders, freight and the insurance premium on CIF.
-- Only a clause still carrying the old seeded text is changed (an admin's own edit is left alone).
-- Documents already generated keep their text. Safe to run more than once.
SET NAMES utf8mb4;
UPDATE tc_clauses
   SET clause_text = 'Shipment Booking: For CFR/CIF orders, shipment booking will be confirmed only after full freight payment (and, for CIF orders, the insurance premium) is received and cleared in NexaCrest''s bank account against the Debit Note. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is verified.'
 WHERE clause_title = 'Shipment Booking (CFR/CIF)'
   AND clause_text = 'Shipment Booking: For CFR/CIF orders, shipment booking will be confirmed only after full freight and insurance payment is received and cleared in NexaCrest''s bank account against the Debit Note. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is verified.';
