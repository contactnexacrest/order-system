# 27 — Pricing: supplier prices, the price build-up and commission levels

**Who this is for:** the Managing Director (who sets the rules), the Executive Director
(who can look), Procurement (who records supplier prices) and Sales (who quotes).
Owner decision D-245 (4 Nov 2026), phase P1. **Confidential — never shown to clients
or associates beyond their own two prices.**

## How a price is built (FOB, per unit, in USD)

| Step | Rule | Example |
|---|---|---|
| Cost | supplier price "used for pricing" (₹) ÷ pricing exchange rate + other costs to FOB | ₹80,000 ÷ 82.00 = USD 975.61 + 2.5% = **USD 1,000.00** |
| Minimum price | cost ÷ (1 − margin %) — *margin on the price, not markup* | ÷ 0.80 = **USD 1,250.00** — nobody goes below this |
| Team incentive reserve | % of (minimum price − cost), or a USD amount; 0 when the incentive program is off | 15% of 250 = USD 37.50 |
| Staff floor | minimum price + reserve | **USD 1,287.50** |
| Associate floor | staff floor ÷ (1 − commission %), or + a fixed USD amount | at 5%: **USD 1,355.26** |
| Quote price | floor × (1 + buffer %) | staff **USD 1,326.13**; associate at 5% **USD 1,395.92** |

Every % or amount has a **global value** (Admin → **Pricing**). A product can override
any of them on its own page; an empty field means "use the global value".

## Who sees and changes what

| Permission | Default roles | What it allows |
|---|---|---|
| Record supplier / market prices | Procurement Executive, Manager, Director; MD | Product → **Market prices**: add a supplier price (dated, valid until, notes), correct one, choose the one **used for pricing**, copy a supplier quote from Procurement. No selling prices, margins or floors. |
| See the full price build-up | MD, ED | The build-up on the product page, every associate level's floor and quote price, the extra columns on the product list, the pricing settings. |
| Manage pricing | MD only | Change the pricing settings, a product's own settings, the commission levels and ladder, and each associate's commission level. |

Sales staff (anyone who may see selling prices) see only **Quote price** and **Your
lowest price** on the product page and list. Supplier prices and cost components on the
product page are shown only to people who may see purchase prices.

## Admin → Pricing (MD)

1. **Pricing settings:** minimum margin (% of price), other costs to FOB (%), pricing
   exchange rate (₹ per USD — keep it a little below the market rate), negotiating buffer
   (%), team incentive reserve (% of profit or USD per unit), and after how many days a
   supplier price is "stale". Every change is logged (who, when, before → after). New
   quotes use the new values; orders with an issued Proforma Invoice keep their prices.
2. **Commission levels:** add, edit, deactivate or delete (2%, 5%, 7%, 10%, or a fixed USD
   amount per unit). A level used by an associate or the ladder can only be deactivated.
3. **Ladder:** steps by counting-order number (1–5, 6–10, 11–20, 21 onward), each pointing
   to a level. Steps start at 1, run on without gaps, and the last runs "onward".
4. **Associate → Edit → Commission level:** **Ladder** or **Fixed level** (choose the level).

## Procurement → Market prices

Open a product and click **Market prices** (or go to `/products/<id>/market-prices`).
Record each new supplier quote as a **new row** so the history stays. Tick **Use this
price for pricing**, or click **Use for pricing** on an existing row. A price older than
the stale setting is marked **stale**; one past its "valid until" date is **expired** —
update it before Sales quotes.

## Prices on order lines (phase P2)

**Goods lines must be picked from Products** (Admin → Pricing, MD only; **on** by default).
While it is on, a **goods** line on a **USD** order must be picked from Products: a goods
line typed by hand is refused, with the message beside the picker in the browser ("Pick
this goods line from Products — on a USD order goods lines cannot be typed by hand (service
and charge lines can).") and again on saving (New Order form, add and edit line). Service
and charge lines can still be typed, and orders in other currencies are not affected.
Switched off, goods lines may be typed by hand again — and are then not price-checked.
Every change is in the audit log (PRICING_SETTING_CHANGED).

**Pick from Products** on an order line (New Order form, and the order page's add and edit
line forms) fills the description, HS code, selling unit and — on a **USD** order, for
people who may see selling prices — the **quote price of whoever sells the order**:

- the **staff quote** when the order's client has no associate (or the order is not one the
  associate earns on: a repeat order, or "associate: no" on the order);
- the **associate's quote at their commission level** (their fixed level, or the ladder step
  for their next order) when the client is an associate's.

The hint under the picker says "Quote price USD 1,326.13 filled in; your lowest price is
USD 1,287.50." The price stays editable — anyone may raise it.

**Below the lowest price.** As soon as the price is under the seller's floor, a red note
appears beside it: "Below your lowest price (1287.50) — saving sends this line to the MD for
approval; the Proforma Invoice waits for it." The line can still be saved; it is then sent
to the MD (in-app notification), and the order's **Price check** (inside the Products card)
lists it with **Ask MD to approve line N** (or "Sent to the MD for approval").

**Never below the minimum price.** A price below the product's minimum price is refused when
the line is saved: "This price is below what NexaCrest can accept for … — raise it." (MD and
ED also see the minimum price in the message.) Nobody can approve it, not even the MD; to
sell lower, change the product's margin (Products → pricing; logged).

**The MD's approval** (permission "Manage pricing"): **Review and approve line N** opens a
page with the asked price, the floor, the staff floor, the minimum price, the margin at the
asked price and the team incentive on the line before → after (the amount below the staff
floor comes out of the line's team incentive first; on an associate's order the associate
earns **no commission on that line**). The MD writes a reason (at least 10 characters) and
clicks **Approve** or **Refuse** (whoever asked is told). The approval holds while the line
keeps that price — **any later price change voids it** and the line goes back to the MD.
Audit: LINE_PRICE_APPROVAL_REQUESTED, LINE_PRICE_APPROVED, LINE_PRICE_APPROVAL_REFUSED,
LINE_PRICE_APPROVAL_VOIDED. Admin → Self-approval rules ("price discount") decide whether the
MD may approve a price they set themselves.

**The Quotation and the Proforma Invoice wait** while any line is below its lowest price,
below the minimum price, or picked from a product with **no price set** (no supplier price
marked "used for pricing") — the last needs the MD's approval too ("No price set — needs MD
approval"). Step 1 (Quotation) and Step 5c (PI) show the reason in red, e.g. "Line 1 is below
the lowest price for this order — raise it, or ask the MD to approve it, before the
Quotation (order page → Products)." Saving such a line is still allowed — it goes to the MD;
only the documents are blocked.

**Fixed when the PI is issued.** Generating the PI stores each checked line's figures (cost,
minimum, floor, quote, level, settings used) on the line. After that, changes to the pricing
settings or the supplier price do not move that order's check ("fixed at PI"); a price
changed later (an amendment) is checked against the fixed figures. Picking a different
product on the line clears them.

**Who sees what on the order page.** Sales staff see "Quote … · lowest …" and a badge per
line (✓ OK / ✗ Below your lowest price / Approved by the MD); MD and ED also see the minimum
price. Departments without selling prices see none of it. Nothing of it is on a document or
in the client portal.

**Not checked:** goods lines typed by hand (only possible while the setting above is off),
service and charge lines, lines without a price yet (To Be Confirmed), and orders in a
currency other than USD — the badge says so.

The D-240 reference selling price, its note settings and its order-level discount approval
are gone; a product keeps only its **selling unit** (Products → edit). Zoho items now go with
rate 0.

## Not yet (later phases)

Commission is not yet paid on the levels and ladder (P3) — the ladder step used for an
associate's quote in P2 counts their earlier orders whose PI was issued; P3 replaces that with
counting orders. The team incentive is not yet worked out from the reserve (P4).
