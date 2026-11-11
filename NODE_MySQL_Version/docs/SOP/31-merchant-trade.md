# 31 — Merchant Trade (MTT)

**Owner decision D-257 Phase 4 (5 Nov 2026).** The **Merchant Trade** division buys goods from a supplier abroad and sells them to a buyer abroad: the goods go straight from one to the other and **never enter India** (RBI "merchanting trade"). It is **built but still "planned"**: nobody sees any of it until the MD activates it in **Settings › Divisions**. Export, Domestic Trade and Import & Distribution work exactly as before.

> **To be confirmed with the AD bank and the CA before the first deal:** the time allowed for the whole deal, the time allowed for an outlay, the advance limit without an LC or bank guarantee, the list of documents the bank wants, the ₹ per USD used for the advance check, and the Zoho Books accounts. Each is a setting you can change; none is fixed in the program. The AD bank's approval for merchanting must be recorded before activating.

## Before activating Merchant Trade (MD)

1. **Settings › Divisions › Merchant Trade.**
   - **Set it up:** *Create its departments* makes Merchant Trade Desk and Merchant Trade Documents (add their designations in Users & roles › Departments). *Move to Merchant Trade* places a staff member in the division.
   - **Modules:** the **Merchant trade** module is on for Merchant Trade only, so nobody else reaches `/merchant`. Export shipping, LUT/IGST and export benefits stay off.
   - **Its own settings** (starting values, **to be confirmed with the AD bank and the CA**):

     | Setting | Starting value | Meaning |
     |---|---|---|
     | `mtt_max_months_total` | 9 | The whole deal must finish within this many months of its first payment or receipt |
     | `mtt_max_outlay_months` | 4 | Money paid to the supplier must be covered by the buyer's money within this many months |
     | `mtt_advance_limit_usd` | 500000 | An advance to the supplier above this (USD) needs an LC or bank guarantee |
     | `mtt_usd_inr_rate` | 84.0000 | ₹ per USD used to compare a non-USD advance with the limit, and the USD margin when neither leg is in USD |
     | `mtt_bank_documents` | 7 documents, separated by `\|` | The AD bank's document checklist for each deal |
     | `mtt_ad_bank_approval` | (empty — add it) | The AD bank and its approval or letter reference for merchanting |
     | `program_enabled` (incentive) | 0 | Merchant Trade's own team-incentive programme starts off |
2. Check **NexaCrest's IEC** in Settings.
3. Work through **Before activating**, tick the box and **Activate**.

## A deal, step by step

Menu **Procurement & suppliers › Merchant trade** (people who see purchase prices). *New deal*: the deal date; the **supplier** (abroad), its country and the **purchase currency**; the **buyer** (abroad), its country and the **sale currency**; the country the goods come from (not India — that would be an export); the ports. The deal number (MTT/26-27/0001) and the PO number (MTT/PO/26-27/0001) are given at once. The list can be shown as a list or, for Kanban users, as a board by stage — both have the same filter, links, figures and RBI status.

| Stage | What is done | To move on |
|---|---|---|
| **Contracted** | The goods: description, HS code, quantity, unit, **buy price** (purchase currency) and **sell price** (sale currency). *Download purchase order (PDF)* to the supplier. Advances on either leg. The **bill of lading** — and, only where the AD bank allows it, a **switch BL** (its number, who issued it and the bank's consent). | Goods, PO generated, BL recorded |
| **Shipped** | *Download sale invoice (PDF)* — the invoice number (MTT/I/26-27/0001) is fixed the first time, and needs the BL. The buyer pays; the supplier is paid. | Invoice generated, buyer paid in full, supplier paid in full |
| **Settled** | Tick each document of the **AD bank's checklist** with the date the bank received it. | Every document with the bank |
| **Closed** | Done. The closing date counts for the 9-month check. | — |

## Who sees the selling side (6 Nov 2026, D-258)

The sale prices, sale totals, margin, the buyer's name and the buyer's
payments are shown only to people with the selling-side money rights.
Procurement sees the purchase side only: on the deal page, the list, the
board and the reports. Without those rights a user cannot edit the deal
header, add lines, or generate and number the sale invoice. The PO can
still be downloaded; only someone with *Manage purchasing* marks it as
generated.

## Money on both legs (rule D-212)
Each entry is **paid to the supplier** (buy leg, purchase currency) or **received from the buyer** (sell leg, sale currency), with the amount, the bank's rate and the **SWIFT / UTR reference (required)**. The app writes the description, for example:
- *"Merchant trade supplier advance of EUR 8,000.00 (₹7,29,600.00 at 91.20) to Anatolia Marble (Turkey) against merchant trade deal MTT/26-27/0001, PO MTT/PO/26-27/0001, SWIFT/UTR ref …"* — a supplier payment before any money from the buyer is an **advance**; when it is backed by an LC or bank guarantee the description says so.
- *"Merchant trade sale receipt of USD 19,250.00 (₹16,18,925.00 at 84.10) from Gulf Stone Trading (United Arab Emirates) against merchant trade deal MTT/26-27/0001, invoice MTT/I/26-27/0001, SWIFT/UTR ref …"* — a buyer's payment before our invoice is a *buyer advance received*.

A reference already recorded, or more than is still owed on that leg, is refused. Two entries at the same moment are checked one after the other.

### Margin
**Margin ₹ = ₹ received from the buyer − ₹ paid to the supplier**, at the rates the bank actually gave. It is also shown in **USD** (at the USD leg's realised rate; when neither leg is in USD, at `mtt_usd_inr_rate`) and as a % of the ₹ received. It reads "so far" until both legs are complete. When both legs are in the same currency the contract margin in that currency is shown too.

## RBI checks
Each deal's page shows four checks, in green (on track), amber (due soon — 30 days or less) or red (action needed):
1. **Whole deal within 9 months** of its first payment or receipt (finished = closed).
2. **Outlay within 4 months**: money paid to the supplier that the buyer's money (in ₹, at the realised rates) does not yet cover must be covered within 4 months. A receipt on the same day as a payment covers it.
3. **Advance within the limit**: supplier payments before the buyer's first receipt, in USD, may not go above USD 5,00,000 unless backed by an **LC or bank guarantee** (record it under *Advance backed by*). A payment that would cross the limit without backing is **refused**.
4. **Documents with the AD bank**: how many of the checklist are with the bank, and which are missing.

The **daily alert job** sends a reminder (in the app and by e-mail) to the people who manage purchasing in Merchant Trade and at company level when a check turns amber or red — once per deal, check and colour, never repeated.

## Reports
**Merchant trade › Reports**: open deals with their four RBI checks, and the margin per deal (sale, ₹ received, purchase, ₹ paid, margin in ₹ and USD, %). Only the person's divisions are shown.

## Zoho Books
- A deal with its sale invoice goes to Books as an **invoice** to the buyer (sale currency, at the realised sell rate; `gst_treatment` overseas, no GST; account *Merchanting Trade Sales*) and a **bill** from the supplier (purchase currency, at the realised buy rate; account *Merchanting Trade Purchases*). **The account names are to be confirmed by the CA.**
- The Division reporting tag is added only when two or more divisions are active and the tag is set up (`zoho_division_tag_option_mtt`).
- The buyer and the supplier are Books contacts **in their own currency** (if Books already has the same name in another currency, the app uses "<name> (<currency>)"), so the invoice and the bill are in the deal's currencies. The invoice is **marked sent** (no e-mail goes out).
- **Both payment legs go to Books:** the buy leg as vendor payments on the supplier's bill (purchase currency, at the bank's rate), the sell leg as customer payments on the sale invoice (sale currency, at the bank's rate); reference = SWIFT/UTR, description = the app's D-212 text. A payment waits until its invoice or bill is in Books.
- **The sale invoice number is `MTT/I/26-27/0001`** (16 characters — the most a GST-registered Books organisation accepts for an invoice number).
- **When it goes:** at the scheduled Zoho sync and *Sync now*, or at once with *Send to Zoho Books* on the deal (Finance). Sync Log rows link to the deal; the "Waiting for Zoho" count includes them; records dated before the go-live date are not sent. **Check against Zoho Books** on the deal (reads only) compares the invoice, the bill and every payment.
- **Live-tested** in the Zoho TEST organisation on 5 Nov 2026 (Zoho change log Z-647 onwards): invoice in USD, bill in EUR, both legs applied; the check matched all four.

## Not built yet (to decide with the AD bank / CA when needed)
- The bank's own merchanting forms and filings; reporting a delay to the bank is done outside the app (the red check says when).
- Several shipments under one deal, partial shipments, and LC documents of the buyer's LC.
- Hedging (forward contracts) on either leg.
