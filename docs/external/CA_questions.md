# Questions for the CA

**From:** the Managing Director, NexaCrest International Private Limited. **Prepared:** 6 Nov 2026.

NexaCrest exports natural stone (and later other goods) from India. Our order system runs every order from quotation to closure, records every payment, and sends invoices, bills, payments and journals to Zoho Books. We also pay commission to independent associates (in India and abroad) and plan three more divisions: Domestic Trade (selling in India with GST), Import & Distribution (importing into India) and Merchant Trade (buy abroad, sell abroad). The system already holds a starting value for every tax rule below. Please confirm or correct each one, so the system calculates and books exactly what the law requires.

For each question: **Today** is what the system does now; **Where** is the setting we change after your answer.

---

## A. Associate commission (needed before the Export go-live)

**1. GST on commission paid to an associate based abroad.** Is this an import of services on which NexaCrest pays GST under reverse charge? At what rate, and are there any conditions (for example, the associate's place of supply)?
- Today: the system pays no GST to an associate abroad and books none. Payouts to associates abroad are on hold until you confirm.
- Where: Associates › Commission › Commission settings and ladder, and SOP 24.
- Answer: ____________________________________________

**2. GST on commission to an associate in India.** Is 18% correct for a GST-registered associate who issues a tax invoice (GST paid on top, input credit taken)?
- Today: 18% (`associate_gst_pct` = 18.00), added on top only when the associate is GST-registered.
- Where: Associates › Commission › Commission settings and ladder › GST %.
- Answer: ____________________________________________

**3. TDS on commission in India.** Is section 194H at 2% with a ₹20,000 per-year threshold correct? If the associate has no PAN, is 20% correct?
- Today: section 194H, 2% (`associate_tds_pct`), threshold ₹20,000 per financial year (`associate_tds_threshold_inr`), 20% without PAN (`associate_tds_no_pan_pct`). TDS is never more than the payout; any shortfall is carried to the next payout in the same year.
- Where: Settings › Lists › Associate TDS rules.
- Answer: ____________________________________________

**4. TDS on commission to an associate abroad (section 195 and DTAA).** When the associate gives us a Tax Residency Certificate, Form 10F and a no-PE declaration, can we pay without TDS under the treaty? If not, what rate applies?
- Today: no TDS for an associate abroad when TRC, 10F and no-PE are on file and accepted (`associate_tds_foreign` = 0); without them the payout is held.
- Where: Settings › Lists › Associate TDS rules › "Deduct TDS for associates abroad"; the papers are checked on the associate's page.
- Answer: ____________________________________________

**5. Form 15CA / 15CB.** Is a 15CA (and a CA's 15CB) needed for every commission payment abroad, or only above a limit? Will you issue the 15CB?
- Today: 15CB required for every payment abroad (`associate_15cb_required` = 1); the payout cannot be marked paid without it.
- Where: Settings › Lists › Associate TDS rules.
- Answer: ____________________________________________

**6. Zoho tax codes for commission bills.** Which Zoho Books tax should be used for (a) GST on an Indian associate's tax invoice, (b) reverse-charge GST for an associate abroad, (c) TDS?
- Today: empty (`zoho_gst_commission_tax_id`, `zoho_tds_tax_id`); the Zoho sync refuses such a bill with a clear message until they are set.
- Where: Associates › Commission › Commission settings and ladder and TDS settings.
- Answer: ____________________________________________

## B. Domestic Trade (only before switching on Domestic Trade)

**7. GST rate for our goods.** Is 18% correct for HSN 6802 (worked building and monumental stone) sold within India? Please list any other HSN codes and rates we should add.
- Today: one rate, HSN 6802 at 18% (table `gst_rates`); the default for anything else is 18% (`dom_default_gst_rate`).
- Where: Settings › Lists › GST rates.
- Answer: ____________________________________________

**8. E-way bill.** Is ₹50,000 the right consignment value above which an e-way bill is needed, for our goods and states?
- Today: ₹50,000 (`dom_eway_threshold_inr`); exactly ₹50,000 does not need one.
- Where: Settings › Divisions › Domestic Trade.
- Answer: ____________________________________________

**9. E-invoicing.** Does e-invoicing (IRN) apply to us, based on our turnover?
- Today: off (`dom_e_invoice_applicable` = 0).
- Where: Settings › Divisions › Domestic Trade.
- Answer: Yes / No  ____________________________________________

**10. Tax invoice format and numbering.** Is the invoice content correct: CGST + SGST within the state, IGST between states, an HSN summary, amount in words, rounding to the rupee with a round-off line, "Reverse charge: No"? Is the numbering DOM/26-27/0001 (16 characters at most, a new series each financial year) acceptable?
- Today: as described. The invoice number is fixed when the goods are dispatched.
- Where: the Domestic Trade documents (SOP 29).
- Answer: ____________________________________________

**11. Payment terms.** Is 30 days a sensible default for domestic buyers? (Business choice, but tell us if any tax point depends on it.)
- Today: 30 days (`dom_payment_terms_days`).
- Answer: ____________________________________________

## C. Import & Distribution (only before switching on Import & Distribution)

**12. Import duty rates for HSN 6802.** Are basic customs duty 20%, social welfare surcharge 10% (of the duty) and IGST 18% correct?
- Today: HSN 6802 = BCD 20%, SWS 10%, IGST 18% (table `import_duty_rates`); defaults for other HSN codes BCD 10%, SWS 10%, IGST 18%.
- Where: Settings › Lists › Import duty.
- Answer: ____________________________________________

**13. Assessable value.** Is assessable value = goods value × customs exchange rate + freight + insurance correct (no landing charge)?
- Today: as described; spread across lines by value.
- Answer: ____________________________________________

**14. IGST input credit.** Can the IGST paid on import be taken as input credit in the month of clearance? Any conditions?
- Today: credit recorded on the clearance date; IGST is not added to landed cost.
- Answer: ____________________________________________

**15. Landed cost.** Is landed cost = assessable value + basic duty + SWS + CHA, port and other charges (IGST excluded as credit) the right basis for stock value?
- Today: as described; stock is valued at weighted average landed cost.
- Answer: ____________________________________________

## D. Zoho Books account names (only before switching on the matching division)

**16. Which Zoho accounts should these postings use?** Please confirm or give the right account for each.

| Posting | Account the system uses today | Your answer |
|---|---|---|
| Import goods and duty bills | Imported Goods Stock | |
| IGST paid on import (journal) | IGST Paid on Import | |
| Merchant Trade sales | Merchanting Trade Sales | |
| Merchant Trade purchases | Merchanting Trade Purchases | |
| Cost of goods sold when imported stock is sold (not built yet: waiting for this answer) | — | |

## E. Merchant Trade accounting (only before switching on Merchant Trade)

**17. GST on merchanting.** We buy abroad and sell abroad; the goods never enter India. Please confirm no GST applies to the sale or purchase, and how the margin is reported.
- Today: no GST on either leg; the sale invoice says the goods do not enter India.
- Answer: ____________________________________________

---
Thank you. Please return this file with the answers filled in, or reply by e-mail quoting the question numbers.
