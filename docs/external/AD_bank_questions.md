# Questions for our AD bank

**From:** the Managing Director, NexaCrest International Private Limited. **Prepared:** 6 Nov 2026.

NexaCrest is an Indian exporter. Our order system manages every export order and records each foreign remittance with its bank reference. We plan two new lines of business: **Merchant Trade** (we buy goods abroad and sell them to a buyer abroad; the goods never come to India) and **Import & Distribution** (we import goods into India, sometimes against our own LC). The system already checks the RBI merchanting rules using starting values. Please confirm or correct each rule below, and tell us what your bank needs from us, so the system warns us before any limit is crossed.

For each question: **Today** is what the system does now; **Where** is the setting we change after your answer.

---

## A. Merchant Trade (RBI merchanting trade transactions)

**1. Whole-deal time limit.** Must the whole deal (first payment to the last receipt) be completed within 9 months?
- Today: 9 months (`mtt_max_months_total`). The system shows amber 30 days before and red after the limit, and reminds staff daily.
- Where: Settings › Divisions › Merchant Trade.
- Answer: ____________________________________________

**2. Outlay limit.** Must any money we pay out before receiving from the buyer be covered within 4 months?
- Today: 4 months (`mtt_max_outlay_months`), measured in rupees at the rates your bank gave.
- Answer: ____________________________________________

**3. Advance to the supplier.** Above what amount does an advance to the foreign supplier need an LC or bank guarantee? Is USD 500,000 correct?
- Today: USD 5,00,000 (`mtt_advance_limit_usd`). A payment that would cross it is refused until an LC or bank guarantee is recorded. A non-USD advance is compared using ₹84.00 per USD (`mtt_usd_inr_rate`).
- Answer: ____________________________________________

**4. Documents you need for each deal.** Is this list complete? Please add or remove.

| # | Document the system asks for today |
|---|---|
| 1 | Sale contract or buyer's order |
| 2 | Purchase contract or supplier's proforma |
| 3 | Supplier's invoice |
| 4 | Bill of lading |
| 5 | Our sale invoice |
| 6 | Inward remittance advice (sell leg) |
| 7 | Outward remittance SWIFT copy (buy leg) |

- Where: Settings › Divisions › Merchant Trade › bank documents (`mtt_bank_documents`). A deal cannot be closed until every document is ticked.
- Answer: ____________________________________________

**5. Your approval.** Do we need your bank's approval or registration before our first merchanting deal? Please give the reference we should record.
- Today: empty (`mtt_ad_bank_approval`); Merchant Trade cannot be switched on until it is filled.
- Answer (reference): ____________________________________________

**6. Switch bill of lading.** Do you accept a switch BL for merchanting deals? Any conditions?
- Today: the system records a switch BL flag, the new BL number and who issued it.
- Answer: ____________________________________________

**7. Reporting.** Are there any reports or declarations you need from us per deal or per quarter?
- Answer: ____________________________________________

## B. Import & Distribution

**8. LC for imports.** Will your bank open import LCs for us? Please give the bank and branch name we should record, and any margin money required.
- Today: empty (`imp_lc_bank`); Import & Distribution cannot be switched on until it is filled.
- Where: Settings › Divisions › Import & Distribution.
- Answer: ____________________________________________

**9. Payments to suppliers abroad.** What documents do you need for an advance payment, and for a balance payment against shipping documents?
- Today: each payment needs a SWIFT/UTR reference and gets a full description (supplier, import PO, what it settles).
- Answer: ____________________________________________

## C. Export receipts (current business)

**10. FIRC / eBRC.** For our export receipts, do you issue a FIRC for each remittance, or do we rely on the eBRC on the DGFT portal? How soon after credit?
- Today: the system records a FIRC/eBRC reference against each receipt.
- Answer: ____________________________________________

---
Thank you. Please return this file with the answers filled in, or reply by e-mail quoting the question numbers.
