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
   A fixed USD amount is always in US dollars: on an order in another currency it is
   converted at the pricing exchange rate (e.g. USD 1.00 × 2,000 units = ₹1,67,000 at
   ₹83.50, whatever the buyer's currency). The same holds for a team incentive reserve in
   USD per unit.
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
keeps that price and the same options and unit — **any later change of the price, the
specification options or the unit voids it** and the line goes back to the MD. A change of
options or unit after the Proforma Invoice also drops the figures fixed at the PI, so the
line is checked against the floor of its new options.
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

## Settings to check on go-live day (owner decision, 5 Nov 2026)

The installed values below are the owner's chosen starting values. Only the first two need
entering by hand on the day the site goes live (Admin › Pricing):

| Setting | Value | Where |
|---|---|---|
| Pricing exchange rate | **today's market ₹ per USD minus about 1.5%**; review monthly, or when the rupee moves more than 1% | Admin › Pricing |
| Other costs to FOB | 2.5% until worked out from the last 3–5 shipments (packing, inland freight, CHA, inspection, bank); override heavy products such as blocks | Admin › Pricing, and per product |
| Minimum margin | 20% of the price (raise per product where the market allows) | Admin › Pricing |
| Negotiating buffer | 3% | Admin › Pricing |
| Team incentive | 15% of profit (review after 6 months) | Admin › Pricing |
| Supplier price stale after | 60 days | Admin › Pricing |
| Commission due | 30 days after full payment, held while a buyer claim is open | Associates › Commission settings |
| Payout run | monthly, on the 5th | Associates › Commission settings |
| Counting order | invoiced FOB of ₹5,00,000 or more | Associates › Commission settings |
| Buyer registration valid | 6 months | Associates › Commission settings |
| GST on commission | 18% (associates with a GSTIN and a tax invoice) | Associates › Commission settings |
| Associate registration link | 7 days | Associates › Commission settings |
| Personal code changes | 3 a year | Associates › Commission settings |

Commission levels and the ladder (P1/P3) and the team incentive (P4) are in use; see SOP 20
and SOP 24.

## Specifications that change the price (D-255, 5 Nov 2026)

**Pricing › Specifications** (MD — manage pricing; MD and ED can view) lists, per
category, the specifications and their options. The Natural Stone category starts
with: Grade / quality, Thickness, Surface finish, Size / format, Edge work,
Quantity, Packing, Treatment, Calibration. The first (base) option of each changes
nothing; every other option changes the **cost**:

- a **%** — multiplies the cost (e.g. Standard −8%, 30 mm +35%);
- **USD per m²** — added to the cost (e.g. Leather +USD 4.00/m²);
- **USD per running metre** — in the unit price only for products sold per running
  metre; otherwise shown to the associate as a separate charge;
- **USD per container** — always a separate charge (e.g. A-frame);
- **Quantity** % — applied last (e.g. 2–4 containers −2%).

Margin, team incentive, associate commission and buffer are then added as usual, so
every option keeps the margin. Worked example (₹3,280 per m², ₹82 = USD 1, other
costs 0%, margin 20%, incentive 15% on, buffer 3%, Level 2 = 5%): Standard · 30 mm ·
Leather · 2–4 containers → quote **USD 73.43**, lowest **USD 71.30** per m².

**Thick stone — per CBM.** From **50 mm** (setting "Price per CBM from (mm)"; a
product can set its own) the price is per CBM and starts from the product's
**per-CBM supplier price** (Products › Market prices › "Price per: CBM — thick
stone", with its own "Used for pricing"). Thickness % options no longer apply;
per-m² amounts are converted ×1000 ÷ thickness. Example at 80 mm with ₹53,300 per CBM:
quote **USD 904.56**, lowest **USD 878.21** per CBM (≈ USD 72.36 per m²). No per-CBM
supplier price goes to a manual price; "Other, 50 mm or more" on an order line uses
the line's own thickness (e.g. 13 cm), so it needs no manual price. Since owner
decision D-270 (11 Nov 2026) an order line may be in **any unit** of its category —
PCS, m², sq ft, RMT, Ton or CBM: the line price is converted to the product's
reference unit with the piece size (one piece = L × W × T; 1 m² = 10.7639 sq ft;
Ton by the weight per CBM, stone 2.7 t) for every check — the save, the badge, Price
check, the MD's approval and the Quotation / PI hold. See
[Stage 1 — Quoting in any unit](./01-stage1-enquiry-quotation.md#quoting-in-any-unit--piece-size-owner-decision-d-270-11-nov-2026).

**A product's own specifications** — Products › a product › **Price options
(specifications)**. Needs the role permission **Manage a product's
specifications** (Admin and MD to start). A product follows its category; it can
set its category, forms and "CBM from"; untick an option (not offered), type its
own value (empty = follow the category, so later category changes flow through),
hide a whole specification, add options or whole specifications for itself only,
and reset one specification or the whole product. Badges show *From category*,
*Changed for this product*, *This product only* and *Hidden for this product*. An
option or specification already used on an order line or price request can only be
deactivated. Every change is in the audit log; prices already issued do not change.

**Order lines.** Picking a product on an order line shows its options; the quote and
the floor follow them (fetched from the server — cost never reaches the browser),
per the line's own unit and piece size (D-270). The options go into the line's
existing fields (description, dimensions, finish, unit); documents are unchanged.

| Setting | Default | Where |
|---|---|---|
| Price per CBM from (mm) | 50 | Pricing › Settings |
| Associate price requests per day | 10 | Pricing › Settings (per associate: Associates › Price access) |
| An associate's price stays valid for (days) | 7 | Pricing › Settings (per associate: Associates › Price access) |

## Pricing a new product in 5 steps (D-256, 5 Nov 2026)

The product page shows **Pricing ready?** (MD, ED and Procurement) — a checklist of what
the product still needs before it can be priced, each line with a link to fix it. The
product list shows a red **Pricing: N to fix** badge on any product with something left.

1. **Supplier price per unit** — Products › the product › **Market prices** › Add a price,
   "Price for: Base", tick **Use this price for pricing**.
2. **Supplier price per CBM** (only when the product is sold at 50 mm or more) — the same
   form, "Price for: Thick stone … (per CBM)".
3. **Specifications** — check the product's options (Products › the product › Price options);
   most products simply follow their category.
4. **Prices current** — both prices quoted within the stale setting (60 days) and not past
   their "valid until" date.
5. **Open to associates** — Associates › an associate › Price access: tick the product's
   category and forms.

Until every line is ✓ staff can still quote, but an associate's **Get price** for the missing
part goes to staff for a manual price. Procurement sees only the supplier-price lines; Sales
and associates never see the checklist.

**An option worth 0** now reads **"Included in base price"** everywhere (the option lists,
Try a price, the associate's price breakdown) instead of "+0%" or "+USD 0.00".

## Try a price (D-256)

Beside the checklist, **Try a price** (MD and ED — anyone who may see the build-up) lets you
pick any combination of the product's options and click **Work it out**. Nothing is saved.
It shows the supplier price used, the options, the cost, minimum price, staff floor, staff
quote and every associate level's floor and quote — worked out on the server by the same
engine as an associate's **Get price** and the order page, so the figures always agree.

Example (₹3,200 per m², ₹82 = USD 1, other costs 2.5%, margin 20%, incentive 15% on,
buffer 3%): ₹3,200 ÷ 82 + 2.5% = **USD 40.00**; Standard · 30 mm · Leather · 2–4 containers →
staff quote **USD 69.76**; associate Level 2 (5%) floor **USD 71.30**, quote **USD 73.43**.

## Exact supplier prices for a specific combination (D-256)

Sometimes a supplier quotes one figure for a particular combination (e.g. "30 mm leather,
₹4,900 per m²") rather than a base price plus changes. Record it in **Market prices** ›
Add a price › **Price for: A specific combination (exact price)**, then under "For a
specific combination" choose the option for each specification the price fixes and leave the
rest as **any**. A supplier quote from Procurement can be copied in with **Copy for a
specific combination**.

- The price is used **as it is** for the options it names; the options it does not name
  still apply as usual (e.g. Standard −8%, 2–4 containers −2%).
- When several exact prices match, the one naming **the most options** wins; on a tie, the
  **most recently quoted**. A **stale** or **expired** exact price is ignored (the ordinary
  price is used) until it is renewed — Pricing ready? flags it.
- It is per unit, or per CBM when a named thickness is 50 mm or more; a per-unit exact price
  is never used for a per-CBM request.
- **Stop using** turns one off without deleting it; **Use for pricing** turns it back on.
- The associate sees only "Exact price for 30 mm · Leather — used" in their breakdown, never
  the supplier or the cost.

Example: exact ₹4,900 for "30 mm · Leather"; Premium · 30 mm · Leather · 2–4 containers →
cost ₹4,900 ÷ 82 + 2.5% − 2% = **USD 60.03** (60.025); staff floor **USD 77.28**, staff
quote **USD 79.60**; associate Level 2 floor **USD 81.35**, quote **USD 83.79**.

## Help beside every setting (D-256)

Every pricing setting, option type, the CBM threshold, every commission setting and every
price-request setting has a short line beneath it with a worked example (e.g. "Example: cost
USD 1,000 at 20% → minimum price USD 1,250 (1,000 ÷ 0.80). Nobody can sell below it.").

## Product categories: units, HS code, packing, quality documents (D-257 Phase 0B, 5 Nov 2026)

So the system can sell products other than stone (for example engineering goods), each
product category now has its own **settings**. Open **Products › Categories**, then
**Settings: <category>** (needs *Manage product catalogue*):

| Setting | What it does | Example |
|---|---|---|
| Default unit | A new order line of this category starts with this unit. | PCS |
| Allowed units | Tick the only units its lines may use; none ticked = any unit. A line with another unit is refused, in the browser and on save. | PCS, NOS, SET |
| Default HS code | A goods line of this category starts with this HS code (from the HS Code master list). A code typed on the line always wins. | 730791 |
| Packing type | From the Package Types list. | Wooden Crates |
| Quality documents | One per line, at most 10. Each order with a line of this category gets a **Quality documents** checklist on the order page. | Mill test certificate 3.1, Drawing approval |
| T&C clause set | Which terms the documents of this category print (since Phase 1, see below). | Engineering goods clauses |

**Natural Stone keeps today's behaviour:** any unit, HS 680293, Wooden Crates, the
natural-stone clauses and no extra quality documents. Its Dimensions and Finish stay
locked; its settings can be edited but never change a stone order line or document
(changing a stone document needs before/after samples and your double confirmation).

**Order lines.** When you choose a category on the order line, its default HS code and
unit are filled in (the HS code only if the field is empty). If you leave them empty, the
category's values are used on save. Supplier purchase orders and purchase requirements
take the HS code and unit from the order line, as before.

**Prices per piece, per kg, per set.** A specification option can now change the price by
*USD per piece*, *USD per kg* or *USD per set*. It is added to the cost only when the
product's selling unit matches (PCS/NOS for a piece, KG for kg, SET for a set); otherwise
the price build-up shows it as "charged separately". As for every option, the margin,
incentive and buffer are added on top. Example: supplier ₹1,230 ÷ 82 = USD 15.00 + 2.5 %
other costs = 15.375; "Machined" +USD 4.50 per piece → 19.875; ÷ (1 − 20 %) = minimum
USD 24.84; + 3 % buffer = quote USD 25.59.

**Quality documents card (order page).** Shown only when a line's category asks for
documents. For each document: the status (Pending → Received → Approved), the file and a
note (e.g. heat number). Received or Approved needs the file attached. Only people who can
manage orders or purchasing can update it; it is internal and never shown to the client.
It sits inside the **Products** card, which opens by itself while a document is still
awaited. A deleted line no longer asks for its category's documents.

**New list values.** Units MTR, LTR, HOUR and DAY (PCS, SET, KG and MT were already there);
supplier types Manufacturer, Machine Shop and Forging / Casting Unit (Trader was already
there). Admin › Dropdowns can rename or hide them.

## Documents follow the category (D-257 Phase 1, 5 Nov 2026)

The quotation, proforma invoice, order confirmation, commercial invoice, packing list,
BL instruction, COO preparation sheet and supplier purchase order now take these from the
categories of the order's goods lines, in PDF and in Word:

| On the document | Natural Stone order | Order of another category |
|---|---|---|
| Product lines | Dimensions, Finish and HS Code, as today | The category's own attributes (e.g. Material, Size) and the line's HS code |
| Packages | "12 Wooden Crates", "Crate No.", "CRATE-LEVEL BREAKDOWN", "Net Weight (stone only)" | The category's packing type (e.g. "8 Wooden Boxes"), "Package No.", "PACKAGE-LEVEL BREAKDOWN", "Net Weight (goods only)". Two categories with different packing: "Packages". |
| Documents to be provided | The 5 standard documents | The same 5, then each quality document the category needs, numbered on (e.g. "6. Mill Test Certificate 3.1 — required for Engineering Products") |
| Supplier PO, section 3 | "Material / Stone Type", tolerance ±2 mm / ±0.5 mm | "Material / Product Type", tolerance "As per the approved drawing / specification", plus "Quality Documents Required" |
| Terms & conditions | Today's clauses | The clauses of the category's clause set (below) |

**Clause sets.** Most clauses print on every order. A few belong to a set and print only
when one of the order's categories uses that set (Admin › Overrides shows it as
"only for: …" beside the clause; the wording is edited there as before):

| Set | Clauses (starting wording) |
|---|---|
| Natural stone clauses | Natural Stone Characteristics; Quantity Tolerance; on the supplier PO, Grade A Only and Colour Consistency |
| General goods clauses | Product Characteristics; Quantity Tolerance (Goods); on the supplier PO, Conformity to Specification |
| Engineering goods clauses | Specification & Drawings; Quantity Tolerance (Goods); Quality Documents; on the supplier PO, Conformity to Specification and Traceability & Test Certificates |

The general-goods and engineering wording is a starting text: **have the lawyer review it
before the first non-stone sale.** An order with lines of two categories prints both sets.

**Natural Stone never changes.** The system category Natural Stone always prints exactly
today's documents, whatever its settings say. Tests compare every stone document (PDF and
Word, FOB and CIF) with copies captured before this change.

Not changed in Phase 1: the Certificate of Origin issuer printed on the documents
(CAPEXIL) and the fumigation certificate line stay as today for every category.

