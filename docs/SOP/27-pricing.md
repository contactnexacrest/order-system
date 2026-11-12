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
| Staff floor (shown to staff as **Staff lowest**, D-305) | minimum price + reserve | **USD 1,287.50** |
| Associate floor (shown as **Associate lowest**) | staff floor ÷ (1 − commission %), or + a fixed USD amount | at 5%: **USD 1,355.26** |
| Quote price | floor × (1 + buffer %) | staff **USD 1,326.13**; associate at 5% **USD 1,395.92** |

Every % or amount has a **global value** (Products › Pricing › **Pricing settings**). A product can override
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

## Products › Pricing › Pricing settings (MD)

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

**Goods lines must be picked from Products** (Products › Pricing › Pricing settings, MD only; **on** by default).
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

**A per-CBM price that was only worked out (D-338).** A line priced per CBM (50 mm and above)
also goes to the MD — even at or above the lowest price — when the supplier never quoted a
50 mm+ price and the per-CBM price was only CALCULATED from a thinner one (e.g. the 20 mm
price × 40). The Price check shows "CBM price worked out — needs MD approval" with the reason
"CBM price worked out from the supplier's 20 mm price — no 50 mm+ price quoted"; the MD sees
the same reason on the approval page and in Approvals › Prices; the Quotation and Proforma
Invoice wait for the approval. Once a 50 mm+ price is typed for that supplier (Market prices ›
+ Add / update a price), new lines need no approval.

**A line "As per annexure" (D-338).** Such a line has no piece size. If its unit needs the
size to compare the price (e.g. a product priced per m² on a CBM line), the line is refused
with "Line 3 says "As per annexure", so its size is not known — type the price per CBM for
this line." — the same message on the Quotation and Proforma Invoice and on the MD's page.

**Product page › Suppliers (D-338).** A new supplier price there is always one typed price
(**FOB Value (₹)**). The old "add up the cost parts" way (FOB Source **Computed**: factory +
transport + packing + loading + CHA) is no longer offered for a new price. A price saved that
way earlier still shows as "Computed (adds up the cost parts)" and keeps working; its **Edit**
keeps the cost parts and offers "Direct — change to one typed price".

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
LINE_PRICE_APPROVAL_VOIDED. Users & roles › Self-approval rules ("price discount") decide whether the
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

## Prices in other units (D-339, 12 Nov 2026)

The product page has a read-only card **Prices in other units**: for each
thickness the product offers, the price per CBM, per SQM and per SQFT, worked
out from the product's prices (1 SQM = thickness × 1 m²; 1 SQM = 10.7639 SQFT).
Nothing is saved. You see the sets your permissions allow: the **supplier
price** (people who may see purchase prices), the **staff quote and floor**
(people who see selling prices) and **every associate level** (MD and ED). A
thickness with its own typed supplier price shows "typed price wins (D-335)".
Per piece needs a size, so it is shown on the order line, not here.

On an order line the quote is filled in the line's unit and a typed price is
never replaced; the floor is shown in the line's unit (SOP 01, "Number of
pieces and the detailed product table").

**Associates** see, on their price request (the result of **Get price**), their
own quote and lowest price per CBM, per SQM and per SQFT for the thickness they
asked for — their level only.

## Settings to check on go-live day (owner decision, 5 Nov 2026)

The installed values below are the owner's chosen starting values. Since 11 Nov 2026 the exchange rate is fetched every day (D-301); only other costs to FOB may need entering by hand on the day the site goes live (Products › Pricing › Pricing settings):

| Setting | Value | Where |
|---|---|---|
| Pricing exchange rate | **Automatic**: today's market ₹ per USD (Frankfurter / European Central Bank, fetched at 07:05 IST) minus the **2 %** cushion (0–5 %, one decimal); press **Fetch now** on go-live day; a daily change over 2 % waits for the MD | Products › Pricing › Pricing settings › Exchange Rate |
| Other costs to FOB | 2.5% until worked out from the last 3–5 shipments (packing, inland freight, CHA, inspection, bank); override heavy products such as blocks | Products › Pricing › Pricing settings, and per product |
| Minimum margin | 20% of the price (raise per product where the market allows) | Products › Pricing › Pricing settings |
| Negotiating buffer | 3% | Products › Pricing › Pricing settings |
| Team incentive | 15% of profit (review after 6 months) | Products › Pricing › Pricing settings |
| Supplier price stale after | 60 days | Products › Pricing › Pricing settings |
| Commission due | 30 days after full payment, held while a buyer claim is open | Associates › Commission › Commission settings and ladder |
| Payout day | monthly, on the **last day of the month**; a Sunday or holiday moves to the working day before (D-292) | Associates › Commission › Commission settings and ladder |
| Counting order | invoiced FOB of ₹5,00,000 or more | Associates › Commission › Commission settings and ladder |
| Buyer registration valid | 6 months | Associates › Commission › Commission settings and ladder |
| GST on commission | 18% (associates with a GSTIN and a tax invoice) | Associates › Commission › Commission settings and ladder |
| Associate registration link | 7 days | Associates › Commission › Commission settings and ladder |
| Personal code changes | 3 a year | Associates › Commission › Commission settings and ladder |

Commission levels and the ladder (P1/P3) and the team incentive (P4) are in use; see SOP 20
and SOP 24.

## Specifications that change the price (D-255, 5 Nov 2026)

**Products › Pricing › Specifications** (MD — manage pricing; MD and ED can view) lists, per
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
| Price per CBM from (mm) | 50 | Products › Pricing › Pricing settings |
| Associate price requests per day | 10 | Products › Pricing › Pricing settings (per associate: Associates › Price access) |
| An associate's price stays valid for (days) | 7 | Products › Pricing › Pricing settings (per associate: Associates › Price access) |

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

The **Thickness** choice starts on **"Supplier's base price (…)"** — the thickness the supplier's
base price is for (e.g. 50 mm and above, per CBM) — so the first figure equals the product list and
the build-up (D-335). Pick a thickness to price that thickness instead; the option shown is always
the one priced.

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
there). Settings › Lists › Dropdown lists can rename or hide them.

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
when one of the order's categories uses that set (Settings › Admin overrides shows it as
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

## Thickness prices, CHA + transport and price rules (D-300, 11 Nov 2026)

**Recording a supplier price (Procurement).** Products › a product › Market prices › Add a price.

1. **Supplier** — type a few letters and pick from the list (it is the Suppliers directory). Not there yet?
   Use **+ Add a new supplier (quick)**: name, location, phone, type. It is added as *Details pending*;
   GSTIN, bank and contacts follow later. The **Location** list shows the supplier's factory / quarry addresses.
2. **Thickness, Price and the tick boxes** (D-335) — choose the thickness the supplier quoted and type its
   price, e.g. 20 mm = ₹1,600 per m² (per CBM for 50 mm and above). Under "Also work out from this 20 mm
   price" each other thickness shows what it would be and how: 30 mm → ₹2,160.00 (= 1,600 + 35 %),
   50 mm and above → ₹64,000.00 per CBM (= 20 mm price ₹1,600 per m² × 40). Leave ticked the ones you want
   worked out; untick the others (they stay "no price"). The price you type becomes the **base** of this
   supplier's price.
3. **Price includes packing and loading** — leave ticked unless the supplier's price is without them.
4. **CHA + transport per container (₹)** — e.g. ₹60,000. The screen shows it per CBM (₹7,500 with 8 CBM)
   and per m² for each thickness (₹150 at 20 mm). Empty = the product's default.
5. **Price for** — keep "Every thickness". Choose "A special combination" only for one exact price such as
   "30 mm · Leather".
6. Tick **Use this price for pricing** and **Save price**.

**The one rule (D-335, 12 Nov 2026).**
1. **A price you typed always wins** — no rule ever changes it.
2. **The base price you chose (◉)** is what the product's price, order lines with no thickness and lines
   of 50 mm and above use.
3. **The rules (−20 %, +35 %, × 40 …) are only a calculator** — used when you tick a thickness to work it
   out while adding a price. The result is marked CALCULATED ("from 20 mm") and you can type over it later.
   Nothing is ever recalculated in the background.

**The price card.** Market prices shows one card per supplier: every thickness with its price, unit, how it
was set (**TYPED**, **CALCULATED** from 20 mm …, or *no price*), since when, the FOB cost including CHA and
transport (per m², or per CBM for 50 mm and above) and which one is the **BASE**. Below it: "Product price
(from the base)". *Example (product sold per CBM):* 50 mm and above typed ₹1,80,000 per CBM and made the base,
CHA + transport ₹80,000 per container of 8.2 CBM (₹9,756.10 per CBM): FOB cost ₹1,89,756.10 per CBM. At a rate of
₹95, 8 % margin and 3 % buffer: USD 1,997.43, minimum USD 2,171.12, quote about USD 2,236.

**Later prices: + Add / update a price** on the card. Example: the supplier now quotes 20 mm at ₹4,600 per m².
Choose 20 mm, type 4,600 and the date. The tick boxes show 15 mm → ₹3,680.00 (= 4,600 − 20 %), 18 mm →
₹4,370.00, 30 mm → ₹6,210.00, 40 mm → ₹7,820.00 — ticked, because they have no typed price — and 50 mm
and above → ₹1,84,000.00 per CBM **not ticked**, with "you typed ₹1,80,000.00 on 11 Nov — kept unless you
tick this". Base for orders: keep 50 mm and above, or make this 20 mm price the base. **Save price**. After
saving: 20 mm is TYPED (₹4,600 + CHA 195.12 = ₹4,795.12 per m²), 15/18/30/40 mm are CALCULATED from 20 mm,
the CBM price is unchanged and still the base. A thickness you leave unticked keeps its price (or stays "no
price" — an order line for it goes to the MD, as today). "○ make base" on a row changes the base without a
new price. Every change is in the **History** (who, when, old → new).

Prices recorded before 12 Nov 2026 still work out the missing thicknesses each time (marked "worked out each
time — older price") until someone adds a price with **+ Add / update a price**; from then on only what is on
the card is used. Market prices › **Edit** changes the supplier, dates, packing and loading, CHA + transport and
notes; the thickness prices are changed on the card.

**Price rules (MD).** Products › Price rules (or Categories › <category> › Price rules): the thickness %s,
the "× 40" for 50 mm and above, **+ Add a thickness** (e.g. 18 mm −5 %), what one container holds (8 CBM,
max 27 t) and the stone weight (2.7 t per CBM). The example column changes as you type. Give a reason and
**Save rules**; it is in the Audit Log. New prices use the rules at once; quotes already sent keep theirs.
A product can have its own × K or container (Edit product, MD only) — it shows an **own rule** badge.

**Product page (D-335).** Edit product has two sections:
- **Costs to FOB** — CHA + transport per container (₹; the screen shows "÷ 8.2 CBM per container = ₹9,756.10
  per CBM") and Packing + loading per unit (₹; added only when a supplier price does NOT include packing and
  loading). The old Default factory / transportation / CHA cost fields are gone: the supplier's price is typed
  on Market prices, and CHA + transport is only here (or on one supplier price).
- **Price rules in effect for this product** — every rule with "category" or "own rule". The MD can switch on
  **Use own rules for this product** to change × K and the container for this product only; switched off, the
  category's rules apply. The rules are only a calculator: they never change a typed price or the base.

## Exchange rate (D-301, 11 Nov 2026)

The USD rate is fetched every morning (Frankfurter, European Central Bank; backup open.er-api.com).
**Pricing rate = market rate − 2 %** on purpose: it keeps our prices safe if the rupee gets stronger before
the buyer pays. *Example:* we quote USD 1,000 at a market rate of ₹88.40. The buyer pays 60 days later at
₹86.63; USD 1,000 now brings ₹86,630, not ₹88,400 — because prices were worked out at ₹86.63, the cost is
still covered.

Products › Pricing › Pricing settings › **Exchange Rate (USD → INR)** starts with the box **RATE USED FOR
PRICES RIGHT NOW** (D-340, 12 Nov 2026): the rate in big type and a badge — *Automatic · fetched today*,
*Automatic · rate of <date>*, *Manual fall-back* (nothing fetched yet) or *Manual* — then the market rate,
when it was fetched, the source and the cushion ("2.0% → ₹88.40 × 0.98"), and any warning. **Green** = the
fetched rate is in use and all is well; **orange** = something needs a look (no rate fetched yet, today's fetch
failed, a big move waiting); **blue** = Manual. In Manual the box compares your rate with the last market rate
("your rate is 5.2% above the market" — above the market means prices in USD come out lower than intended).
Beside the fields a badge says which one is in use: the manual rate shows **not used now — fall-back only**
while a fetched rate is in use, **in use now** otherwise; the cushion shows **not used in Manual**. The badges
change as soon as you change the Automatic / Manual drop-down (save to apply). **History (last 30 fetches)**
opens the list of fetches below the fields.

The MD can set Automatic / Manual and the cushion (0–5 %, one decimal), and **Fetch today's rate now**
(in the box). If the fetch fails, yesterday's rate stays (a warning shows). If the rate moves more than 2 % in a
day it waits: the MD reads the figures in the box, gives a reason and chooses **Use this rate** or **Keep the
rate in use**. Quotations and Proforma Invoices already issued never change.

On an order (MD and ED only), the Products card shows above Price check which rate the prices were worked out
at: before the Proforma Invoice "Prices worked out at ₹86.63 per USD (Automatic, 12 Nov 2026) — live: follows
today's rate."; after it "… — fixed on the Proforma Invoice of 12 Nov 2026 at ₹86.63 per USD." (and, if
today's rate is different, "today's rate ₹86.40 does not change them"). Nobody else sees this line, and it is
never on a document.

## One master for HS codes and suppliers (D-302, D-303)

- A product's HS code is picked from the HS Code Master List (pre-filled with the category's default).
  People who manage HS codes see **+ Add a new HS code**. Order lines accept only master-list codes.
- One-time clean-up: Products › HS codes › **Fix HS codes on products** (choose the right code for each product listed) and
  Products › **Link suppliers** (**Match by name** links exact names at once; pick or quick-add the rest).
- A supplier's page lists **Prices given for products**; untick **Details pending** once GSTIN, bank and
  contacts are complete.

## Catalogue labels and who sees which price (D-304, D-305)

- Column headers say the currency and unit, e.g. "Cost USD / CBM", "Supplier price ₹ / CBM" (with
  "≈ USD" for people who edit products). "Staff lowest" and "Associate lowest" are the lowest prices.
- Sales staff see only **Quote price** and **Your lowest**. On an order from a client who came through an
  associate they see **Quote and lowest for this order** and the note "This client came through an
  associate, so this order's prices are higher" — no associate name, level or %.
- Associates see no prices in the catalogue; **Get price** gives their own quote and lowest (as before).

## One page per category (D-306)

Products › Categories › <category>: **General** (name, units, HS code, packing, documents) · **Options and
their price effect** (was the separate Specifications page under Pricing) · **Price rules** · **Products in this
category**. On a product's options page, "edit for all products in <category>" opens the category's
options; **Change for this product only** changes just that product.

## Inactive products: find, reactivate or delete (owner, 12 Nov 2026)

Products › All products shows **Active (n) · Inactive (n) · All (n)** above the list (also on a search); Active is
the default. A deactivated product is hidden from the order-line picker but is never lost: open **Inactive** to find it.
Each inactive product shows **Used on** (e.g. "3 orders · 1 price request", or "not used anywhere"). People who manage
the product catalogue can **Reactivate** it, or **Delete…** it — only when it is inactive and not used anywhere (its
supplier prices, images and price history go with it). A used product cannot be deleted, so its orders stay complete.
On an active product's page the button is **Deactivate**, not Delete. Every deactivation, reactivation and delete is in
the audit log.
