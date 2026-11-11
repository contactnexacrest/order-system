# 29 — Domestic Trade (DOM)

**Owner decision D-257 Phase 2 (5 Nov 2026).** The **Domestic Trade** division sells to resellers in India, in rupees, with GST. It is **built but still "planned"**: nobody sees any of it until the MD activates it in **Settings › Divisions**. Export works exactly as before.

> **To be confirmed by the CA before the first domestic sale:** the GST rate of each product (HSN), the e-way bill threshold (₹50,000 as a start), whether e-invoicing applies to NexaCrest, and the invoice wording. Each is a setting you can change; none is fixed in the program.

## Before activating Domestic Trade (MD)

1. **Settings › Divisions › Domestic Trade.**
   - **Set it up:** *Create its departments* makes Domestic Sales, Domestic Purchase and Domestic Dispatch (add their designations in Users & roles › Departments). *Move to Domestic Trade* places a staff member in the division (company-level people stay company level).
   - **Modules:** Domestic Trade starts with quotation/OC, supplier purchase, associates and team incentive on; export shipping, LUT/IGST, export benefits, FIRC and ECGC off.
   - **Its own settings** (already filled with starting values):

     | Setting | Starting value | Meaning |
     |---|---|---|
     | `dom_default_gst_rate` | 18 | GST % for a line whose HSN has no rate in Settings › Lists › GST rates |
     | `dom_eway_threshold_inr` | 50000 | Above this consignment value (with GST) an e-way bill number is needed before the goods move on |
     | `dom_e_invoice_applicable` | 0 | 1 = the tax invoice notes that the IRN and QR code come from the e-invoice portal |
     | `dom_payment_terms_days` | 30 | Default payment terms on a new domestic order |
     | `pricing_fx_rate` (pricing) | 1 | Prices are worked out in rupees |
     | `program_enabled` (incentive) | 0 | Domestic Trade's own team-incentive programme starts off |

     Add `order_ref_format` (for example `NC/DOM/{YY}/{DDMM}{NNN}`); without it the numbers start with `DOM/` and Export's format.
2. **Settings › Lists › GST rates** (link on the division page): one rate per HSN (the longest matching start of a line's HSN wins) or per product category. Starts with HSN 6802 at 18 %.
3. Check **NexaCrest's GSTIN** in Settings: its first two digits are the GST state (29 = Karnataka) used to choose CGST + SGST or IGST.
4. Work through **Before activating**, tick the box and **Activate**. From then on company-level people see the division switcher, "All divisions" and the Division filters (SOP 28).

## A domestic order, step by step

Staff working in Domestic Trade open **Orders › New order** and get the domestic form (company-level people: switch to Domestic Trade first, or open `/domestic/orders/create`).

| Stage | What is done | To move on |
|---|---|---|
| **Enquiry** | Client, buyer's GSTIN and state, **place of supply** (the state the goods go to), delivery address, payment terms, buyer's PO. Add the lines: description, HSN, quantity, unit, rate in ₹, GST % (blank = from the GST rates). | At least one line |
| **Quotation** | *Generate quotation* (DOM/QT/26-27/0001). | Quotation generated |
| **Order confirmation** | *Generate order confirmation* (DOM/OC/…). Payments can be recorded from here on. | Buyer's GSTIN entered (a reseller buys on its registration) and the confirmation generated |
| **Purchase** | Supplier and supplier PO number (the purchase itself can use Procurement). | Supplier PO recorded |
| **Dispatch** | Moving on to Dispatch **fixes the tax invoice number** (DOM/26-27/0001, at most 16 characters, a new series each financial year) and its totals; the lines can no longer change. Record the dispatch date, transporter (and its GSTIN/TRANSIN), vehicle, LR and distance, and the **e-way bill** number and date. *Generate GST tax invoice* and *Generate delivery challan*. | Dispatch recorded, e-way bill above the threshold, invoice and challan generated |
| **Tax invoice** | Mark the invoice as sent to the buyer. *Send to Zoho Books* (Finance, when Zoho is on). | Invoice marked as sent |
| **Payment** | Record each receipt: date, amount, **bank reference (required)**, note. | Fully paid (₹1 tolerance) |
| **Closed** | The order is complete and locked. | — |

### GST on the documents
- **Same state** (NexaCrest's GST state = place of supply): **CGST + SGST**, each half the rate. **Another state:** **IGST**.
- Each line's tax is rounded to the paisa; the invoice total is rounded to the rupee with a *Round off* line.
- The tax invoice shows the HSN summary, the total in words in lakh/crore ("Indian Rupees Two Lakh … Only"), "Tax payable under reverse charge: No", the transport and e-way bill details and the bank account.
- The four domestic documents (quotation, order confirmation, GST tax invoice, delivery challan) are new and used only by Domestic Trade orders. **No export document changes.** They show prices, so only people who may see selling prices can open them.

### Money descriptions (rule D-212)
Each receipt is described by the app, for example: *"Domestic sale receipt of ₹1,00,000.00 from Bengaluru Stone Traders against GST tax invoice DOM/26-27/0001 (order DOM/NC/SC/ORD/26/0511001), bank ref UTR SBIN226000111; note: part payment"*. Before the invoice: *"Domestic sale advance of ₹… against order …"*. A bank reference already recorded is refused.

### Clients
Once Domestic Trade is active, the client page has a **GST registration** section (GSTIN, state, usual place of supply). A new domestic order fills the client's empty GST fields from the order.

## Zoho Books
- The tax invoice goes to Books as a GST invoice: `gst_treatment` business_gst with the buyer's GSTIN (business_none if unregistered), place of supply, HSN and the GST group (GST18) or IGST (IGST18) per line, and a note saying what it is.
- The **Division reporting tag** is added to Books records only when two or more divisions are active and the tag is set up in Settings (`zoho_division_tag_id`, `zoho_division_tag_option_dom` / `_exp`). With one division nothing sent to Books changes.
- **The buyer is its own INR, GST customer in Books** (`gst_treatment` business_gst with the GSTIN, place of contact; business_none without a GSTIN), kept apart from the client's export customer (overseas, in a foreign currency). If Books already has a same-named customer that is not INR / GST, the app uses "<name> (INR)". The app remembers it on the client. The client sync (Settings → Zoho Books) only sends a client's **export** customer.
- The invoice carries the tax invoice's **round-off** as the adjustment, so Books' total equals the printed total, and it is **marked sent** (no e-mail goes out) so receipts can be applied.
- **Each buyer receipt** goes as a Books customer payment in INR: reference = the bank reference, description = the app's own D-212 text, applied to the tax invoice up to what it still owes. A receipt waits until its invoice is in Books.
- **When it goes:** at the scheduled Zoho sync and *Sync now* (Zoho Books Sync page), or at once with *Send to Zoho Books* on the order (Finance). Each record has its row in the Zoho Sync Log; the "Waiting for Zoho" count includes them. Records dated before the go-live date are not sent (the CA books them by hand). A retry never makes a second record (found by invoice number, or by bank reference and amount).
- **Check against Zoho Books** (on the order, reads only) shows the invoice and each receipt beside what Books holds: Matches / Not in Zoho Books yet / Different / Missing.
- **Live-tested** in the Zoho TEST organisation on 5 Nov 2026 (Z-647 onwards in the Zoho change log): an intra-state invoice (GST18) with its receipt on the PHP stack, and an inter-state one (IGST18) with a part receipt on the Node stack; both checks matched.

## Not built yet (to decide with the CA when needed)
- Generating the e-way bill or e-invoice (IRN) from the app — they are made on the government portals and their numbers entered here.
- Credit and debit notes, sales returns, TDS/TCS on domestic receipts, and reverse-charge sales.
- The order list and board list a domestic order with its reference and client; its stage and amounts are on its own page.
