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

## Not yet (later phases)

Order lines do not yet fill in the quote price or refuse a price below the floor (P2);
commission is not yet paid on the levels and ladder (P3); the team incentive is not yet
worked out from the reserve (P4). Until P2 the D-240 reference selling price and its
discount approval keep working as before.
