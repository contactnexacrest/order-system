# 30 — Import & Distribution (IMP)

**Owner decision D-257 Phase 3 (5 Nov 2026).** The **Import & Distribution** division buys goods abroad, imports them into India, holds them in **stock** and sells them to resellers in India with GST. It is **built but still "planned"**: nobody sees any of it until the MD activates it in **Admin › Divisions**. Export and Domestic Trade work exactly as before.

> **To be confirmed by the CA and the customs agent before the first import:** the basic customs duty, social welfare surcharge (SWS) and IGST of each product (HSN), the margin used for the suggested selling price, and the Zoho Books accounts for imports. Each is a setting or a rate you can change; none is fixed in the program. **To be confirmed with the bank:** the bank that opens import LCs and remits abroad.

## Before activating Import & Distribution (MD)

1. **Admin › Divisions › Import & Distribution.**
   - **Set it up:** *Create its departments* makes Import Purchase, Warehouse and Distribution Sales (add their designations in Admin › Departments). *Move to Import & Distribution* places a staff member in the division.
   - **Modules:** Import & Distribution starts with quotation/OC, supplier purchase, associates, team incentive and **Import, stock and landed cost** on; export shipping, LUT/IGST, export benefits, FIRC and ECGC off. The **Import, stock and landed cost** module is on for Import & Distribution only, so nobody else ever reaches the import pages.
   - **Its own settings** (starting values):

     | Setting | Starting value | Meaning |
     |---|---|---|
     | `imp_default_bcd_pct` | 10 | Basic customs duty % for a line whose HSN has no rate in Admin › Import duty rates |
     | `imp_default_sws_pct` | 10 | Social welfare surcharge, % of the basic duty |
     | `imp_default_igst_pct` | 18 | IGST % on import (input credit) |
     | `imp_margin_pct` | 20 | Margin used for the **suggested** selling price (landed cost ÷ (1 − margin)) |
     | `imp_lc_bank` | (empty — add it) | The AD bank that opens import LCs and remits abroad |
     | `imp_default_cha` | (empty — add it) | The customs agent (CHA) usually used |
     | `pricing_fx_rate` (pricing) | 1 | Local-sale prices are worked out in rupees |
     | `program_enabled` (incentive) | 0 | Import & Distribution's own team-incentive programme starts off |

     Add `order_ref_format` (for example `NC/IMP/{YY}/{DDMM}{NNN}`) for the local-sale order numbers.
2. **Admin › Import duty rates** (link on the division page): basic duty, SWS and IGST per HSN (the longest matching start of a line's HSN wins). Starts with HSN 6802 at 20 % / 10 % / 18 %.
3. Check **NexaCrest's IEC** and **GSTIN** in Settings (the importer of record; IGST credit and local sales).
4. The local sale uses the **GST rates**, e-way bill threshold and e-invoicing rule of Domestic Trade (Admin › GST rates and the Domestic Trade settings): the GST law is the same.
5. Work through **Before activating**, tick the box and **Activate**.

## An import, step by step

Menu **Operations › Imports & stock** (people who see purchase prices). *New import*: the foreign supplier, its country and address, the **currency** of its invoice, the Incoterm, the PO date and the payment terms. The import PO number is given at once (IMP/PO/26-27/0001). The list can be shown as a list or, for Kanban users, as a board by stage — both have the same filter, links and actions.

| Stage | What is done | To move on |
|---|---|---|
| **Ordered** | Lines: the **product** (stock is kept per product), description and HSN (blank = from the product), quantity, unit, unit price in the supplier's currency, duty rates (blank = from Admin › Import duty rates). *Download import PO (PDF)*. The **LC** where NexaCrest is the applicant (number, bank, amount, expiry, margin money) — a record of it. Payments abroad. The **bill of lading**. | Lines, import PO generated, BL recorded |
| **Shipped** | **Customs clearance:** bill of entry number and date, Indian port code (e.g. INNSA1), the CHA, the **customs exchange rate** on the bill of entry, the clearance date, and the charges in ₹: freight, insurance, CHA, port and terminal, other. | Clearance recorded |
| **Cleared** | Moving on to Cleared **works out the duty and the landed cost per item and freezes them** (see below). Record when the **IGST was taken as input credit**. *Send to Zoho Books* (Finance, when Zoho is on). | — |
| **In stock** | Moving on to In stock **puts the goods into stock** at their landed cost. | The supplier fully paid and the IGST credit recorded |
| **Closed** | Done. | — |

### Duty and landed cost
- **Goods in ₹** = quantity × unit price (rounded to the cent) × the customs exchange rate.
- **Assessable value** = goods + freight + insurance (freight and insurance are spread over the lines by value).
- **Basic customs duty** = assessable value × BCD %. **SWS** = basic duty × SWS %. **IGST** = (assessable value + duty + SWS) × IGST %.
- **Landed cost** = assessable value + basic duty + SWS + CHA, port and other charges (spread by value). **IGST is not a cost**: it is taken back as input credit.
- Landed cost per unit and the **suggested selling price** (landed cost per unit ÷ (1 − margin), before GST) are shown on each line and in the reports. Every amount is worked in whole paise and rounded half away from zero; a spread always adds up exactly (the last line takes what rounding leaves).

### Payments to the supplier (rule D-212)
Each payment abroad is *advance*, *balance* or *LC retirement*, with the amount in the supplier's currency, the bank's rate and the **SWIFT / UTR reference (required)**. The app writes the description, for example: *"Import supplier advance of USD 1,000.00 (₹84,920.00 at 84.92) to Xiamen Stone Industries (China) against import PO IMP/PO/26-27/0001, SWIFT/UTR ref SBININBB123"*. A reference already recorded, or more than the PO still owes, is refused.

## Stock
- Stock is kept **per division and product**, valued at **weighted average landed cost**.
- It **never goes below zero**: taking goods out locks the product's stock first, so two sales at the same moment cannot take the same stock twice; the second is refused with what is left.
- Every movement in (from an import) and out (to a local sale) is logged.

## The local sale (from stock)
Staff working in Import & Distribution open **Orders › New order** and get the local-sale form: it is the Domestic Trade order (SOP 29) in the Import & Distribution division, with these differences:
- Each line is a **product from stock** (the form shows what is on hand).
- There is **no supplier PO**: at the Purchase stage the order checks that the stock covers every line.
- Moving on to **Dispatch** fixes the tax invoice number — **IMP/26-27/0001** (its own series; quotation IMP/QT/…, order confirmation IMP/OC/…, challan IMP/DC/…) — and **takes the goods out of stock**.
- Receipts read *"Local sale receipt of ₹… from … against GST tax invoice IMP/26-27/0001 (order …), bank ref …"*.

## Reports
**Imports & stock › Reports**: stock on hand (quantity, value, average cost, suggested price), landed cost per item, customs duty and IGST input credit per bill of entry (credit taken or pending), and the latest stock movements. Only the person's divisions are shown.

## Zoho Books
- A cleared import goes to Books as up to three bills: the **supplier's bill** in its currency at the customs rate (`gst_treatment` overseas; goods to *Imported Goods Stock*), the **customs duty bill** from Customs (basic duty and SWS to *Imported Goods Stock*, IGST to *IGST Paid on Import*), and — when there are clearance charges — the **clearance charges bill** from the CHA (`CLR-<bill of entry>`: freight, insurance, CHA, port and terminal, other, to *Imported Goods Stock*, as part of the landed cost). The IGST credit is a journal: debit *Input IGST*, credit *IGST Paid on Import*. **The account names are to be confirmed by the CA.** (Books' own *Inventory Asset* is a stock account that needs an item on every line, so the landed cost goes to an *Imported Goods Stock* asset account.)
- **Payments to the supplier abroad** (advance, balance, LC retirement) go as Books vendor payments in the supplier's currency at the bank's rate, applied to the supplier's bill; reference = SWIFT/UTR, description = the app's D-212 text. They wait until the bill is in Books.
- **The customs duty payment:** record it on the import (*Duty paid on* and the **challan / bank reference**, e.g. the ICEGATE CIN; Finance). It goes as a vendor payment to Customs for basic duty + SWS + IGST, applied to the customs duty bill, with a description that names the bill of entry, each amount and the challan. Once it is in Books it cannot be changed here.
- **The IGST credit journal** goes once the credit date is recorded.
- **When it goes:** at the scheduled Zoho sync and *Sync now*, or at once with *Send to Zoho Books* on the import (Finance) — whatever is waiting. Each record has its row in the Zoho Sync Log (linked to the import); the "Waiting for Zoho" count includes them; records dated before the go-live date are not sent. **Check against Zoho Books** on the import (reads only) compares the three bills, every payment and the journal.
- The Division reporting tag is added only when two or more divisions are active and the tag is set up (`zoho_division_tag_option_imp`).
- **Live-tested** in the Zoho TEST organisation on 5 Nov 2026 (Zoho change log Z-647 onwards): the three bills, both payments abroad, the duty payment and the IGST journal all went once and the check matched all seven.

## Not built yet (to decide with the CA / customs agent when needed)
- Filing the bill of entry or paying duty from the app (done on ICEGATE by the CHA; the numbers are entered here).
- Duty exemptions and concessions (FTA rates, EPCG, advance authorisation), anti-dumping duty, customs landing charges.
- Partial receipts of one import, warehouses by location, stock counts and write-offs, sales returns into stock.
