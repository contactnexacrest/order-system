# Contract changes for the lawyer (owner decision D-245, 4 November 2026)

**For:** NexaCrest International Private Limited's lawyer.
**From:** the Managing Director.
**Why:** the commission and pricing rules have been decided and built into the order system. The agreements need to say the same thing, so that what the system pays and what the contract promises never differ.

This note lists the changes only. It contains no costs, margins or pricing formulas.

---

## 1. Introducer agreements: drop them

We will not use the "Business Introducer Agreement" (India) or the "Business Introducer Agreement" (International). Everyone who brings us buyers from outside the company will sign the **Associate agreement** instead. Please withdraw both introducer drafts.

## 2. Associate agreement: changes

1. **What commission is paid on.** Replace "Net Realised Amount" with the **final invoiced FOB value of the goods**, line by line.
   - Freight, insurance, services and other charges are not included.
   - Bank charges never reduce it.
   - Only a discount or a claim settlement that NexaCrest has **agreed** reduces it.
2. **Price range (new clause).**
   - NexaCrest gives the associate a written price list per product, with a **quote price** and a **lowest price**.
   - The associate may negotiate only between those two prices. Their commission is the same anywhere in that range.
   - A price below the lowest price needs the **Managing Director's written approval**, and **no commission is paid on that line**.
   - **Changed by D-255 (5 Nov 2026):** there is no longer a full price list to acknowledge. The associate asks for a price per product and specification in the portal ("price request"); each answer has a number, a quote price, a lowest price and a valid-until date (7 days). Please word the clause around these price requests instead of an acknowledged list.
3. **Full payment only.**
   - Commission becomes due only when the buyer has paid the full order value.
   - Remove any pro-rata payment for part payments.
   - If the buyer defaults or does not pay in full, no commission is due on that order.
   - If NexaCrest agrees a discount or settles a claim, commission is paid on the reduced invoiced FOB.
4. **When commission is paid.** The agreement should refer to the timing NexaCrest sets in writing (its system settings), currently:
   - due a set number of days after full payment (now 30 days);
   - held while a buyer claim on the order, raised on or before the due date, is unresolved;   - paid in the next payout run (since 11 Nov 2026, D-292: monthly on the **last day of the month**; a payout day on a Sunday or holiday moves to the working day before).

   Please draft this so NexaCrest can change the timing by written notice for orders that start after the notice.
5. **No take-back after payment.** Once commission is paid, it is not recovered for a later claim, except for the associate's **fraud or breach**. NexaCrest must record the reason.
6. **Commission levels and the optional ladder.** The rate is one of NexaCrest's commission levels (now 2%, 5%, 7% or 10%, or a fixed USD amount per unit). For each associate, NexaCrest chooses either:   - a **fixed level**; or
   - (since 11 Nov 2026, D-291) **an own ladder** for that associate — a copy of the standard ladder that the MD changes, with a reason, for new orders only; or
   - a **ladder**, where the level rises with the number of **counting orders**. A counting order is one fully paid, with invoiced FOB of **₹5 lakh or more** within the price range. A smaller order earns the current level but does not count.

   The level is fixed on each order when the Proforma Invoice is issued. Rates and levels can be changed by written rate notice, for orders that start after the notice.
7. **GST.**
   - Rates exclude GST.
   - A GST-registered associate issues a valid tax invoice, and NexaCrest pays the GST on top.
   - TDS is deducted as the law requires.
8. **Buyer registration.** A buyer the associate registers with NexaCrest stays theirs for **6 months** from confirmation. If no first order arrives in that time, the registration lapses.
9. **Price list and rate notices.** Add a clause that:
   - price lists and rate notices are given in writing, through the associate portal or by e-mail;
   - the associate's acknowledgement is recorded;
   - the latest acknowledged notice applies.

## 2a. Non-circumvention and price confidentiality (new clause, D-255, 5 Nov 2026)

Please add to the associate agreement:

1. **No going around NexaCrest.** During the agreement and for **24 months** after it ends, the associate shall not, directly or through anyone else, buy from, sell for, or deal with any supplier, quarry, processor or transporter they came to know through NexaCrest, for any buyer they introduced or learned of through NexaCrest, without NexaCrest's written consent.
2. **Prices are confidential.** Price requests and their answers are for the associate only. They may tell a buyer a price, but must not pass on, copy, screenshot, publish or forward the price pages, the price breakdown or any list of prices.
3. **Fair use of price requests.** Price requests are for real enquiries. NexaCrest may limit the number per day, and may suspend price requests for an associate who asks for many prices without orders.
4. **Remedies.** On a breach: no commission on the affected business, termination for cause, and damages (please advise whether liquidated damages are enforceable here, and a reasonable amount).

## 3. Sales Manager employment agreement: Schedule A

Replace the 10% override on team members' commission, and the personal commission, with:

> The Manager shares in the **team incentive**, as set by the Managing Director for each order (the Manager's role share, which the MD may change on any order). No other commission, bonus or override applies unless written and signed by the Managing Director.

The salary, probation and other employment terms are unchanged.

## 4. To check with the CA

**GST for associates outside India:** confirm how GST applies (likely reverse charge on an import of services) before the first payout to an associate based abroad.

## 5. New T&C clauses for goods other than natural stone (D-257 Phase 1, 5 Nov 2026)

When an order is for a category other than Natural Stone, the QT, PI, OC, CI and supplier PO now print that category's clause set instead of the stone clauses. The starting wording, written by us and **not yet reviewed**, is in `docs/seed.sql` (table `tc_clauses`, clause sets for general and engineering goods):

- Product Characteristics
- Specification & Drawings
- Quantity Tolerance (Goods)
- Quality Documents
- on the supplier PO: Conformity to Specification, and Traceability & Test Certificates

Please review them before the first non-stone sale. The stone clauses are unchanged. The wording can be changed in Settings › Admin overrides without a code change.

## 6. Associate agreement: new commission rules (D-410, 24 Nov 2026)

The owner has changed how associates earn (owner-decisions register D-410). The Associate Agreement must be updated to match before new agreements are signed, and existing associates told in writing:

1. **Which orders earn.** No longer only the client's orders in the first 10 days from the first PO (clause 4.1 today). Now every order of an introduced client earns while the agreement is active, when the value of the goods (FOB) is at least ₹5,00,000. The client's first order always earns, whatever its size.
2. **Ladder steps.** An order of ₹5,00,000 or more counts as one step once the buyer's advance has cleared (LC orders: once the LC is confirmed; no advance and no LC: once paid in full). Orders of the same client within 15 days of the first order of the group are one step. NexaCrest may change the ₹ minimum and the 15 days (7–45) for orders whose Proforma Invoice is issued after the change. A cancelled order loses its step; earlier orders keep their %.
3. **Payment.** Commission is due only after the buyer has paid in full, on the payout date. A refund to the buyer after the commission was paid does not take the commission back (as before); only the ladder step is removed.
4. **Price.** The client's price includes a set % of commission chosen by NexaCrest; the associate's commission % is not changed by it. Please confirm no clause promises the associate a say in the client's price.
5. **Transition.** Orders whose Proforma Invoice was issued before the change keep the old 10-day rule; no commission is paid retroactively under the new rule.

Please also confirm the wording the associate sees: "Not earned yet — buyer still paying", "Will be paid to you on <date>", "Paid to you on <date> · bank ref <reference>".
