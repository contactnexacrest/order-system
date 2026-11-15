# 33. Sales targets (MD / ED)

Owner decision **D-378** (15 Nov 2026). For the Managing Director, the Executive Director and anyone given
the permission **Manage sales targets** (Users & roles › Roles and permissions). Salespeople see only their
own target on their Dashboard; associates never see targets.

Targets measure performance only. **They pay nothing by themselves** — incentive and commission keep their
own rules. Prices, the Price check, documents, Zoho and the order steps are not touched.

## What the targets are

| | In | Counted when |
|---|---|---|
| **Company target** (MD/ED) | rupees of margin | the goods **ship** (Commercial Invoice date) |
| **Personal target** (each salesperson) | **containers** (every container counts 1, 20ft or 40ft) + an optional "new buyers won" goal | the buyer **confirms**: advance received, LC received, or — for no-advance terms — buyer PO received with the PI approved |
| **Associate target** (MD/ED only, internal) | containers | confirmation, as above |

Quotations and approved PIs without money are the **pipeline**: shown, not counted.

## Setting it up (Admin › Pricing › Sales targets settings)

1. **Monthly company costs (₹)** — salaries, rent, electricity, software, CA … and **monthly profit wanted (₹)**
   (0 = break-even). Both empty = the company target is off. Choose **Effective from**; earlier periods keep
   their old figures.
2. **Target period** — Quarter (recommended for granite: shipments are uneven month to month), or Month,
   Half-year, Year. Monthly figures are multiplied by the months in the period.
3. **Target multiple (global)** — default **3**, the *rule of thirds*: the margin a salesperson brings in should
   be about 3 × their monthly cost (⅓ pays them, ⅓ the company's costs, ⅓ profit). 1 to 10.
4. **New-joiner build-up** (default 3 months): month 1 = an activity goal only (quotations), month 2 = 50 %,
   then 100 %. Needs the person's **Joining date** (Edit user).
5. **Review reminder** (default every 12 months) and **alert level** (default 60 %).
6. **Default target per designation** — containers, new buyers and the build-up quotations goal.
7. Type a **reason** and Save. Every change is logged.

## Setting a fair personal target (Sales targets page)

- Each person follows their designation's default unless you set an **own target** or an **own multiple**
  (marked "own"), with a reason.
- **Suggest a fair target** (calculator): type the person's monthly cost — **this is never saved or sent** —
  the app multiplies by their multiple and the months in the period and divides by the average margin per
  container of the last 3 months. **Use this** copies only the container figure into that person's form.
- **Salary raised → review the target** the same day. "Last reviewed" shows on each row; you are reminded
  when it is older than the review period.
- The check line under the table compares the sum of all targets with the containers the company needs.

## Who gets the credit

The order's **Sales & Export owner** (order page › Owners). An order confirmed with no owner shows as
**Unassigned** and an alert goes out; choose the owner, or move the credit on the Sales targets page with a
reason (logged). Repeat orders and new buyers both count; a buyer's first confirmed order also counts as a
**new buyer**.

## Corrections

- An order **cancelled or lost after it was counted** is taken off in the period it happens (a "−" row with the
  order number); closed periods are never rewritten.
- **Shipped fewer or more containers** than confirmed: the difference is added when it ships.
- A buyer claim or refund after shipment lowers the **company margin**, not the containers.

## Alerts (Admin › E-mail settings › E)

| Alert | Default recipients |
|---|---|
| Company behind target (from the middle of the period, counting the pipeline) | MD, ED |
| Targets due for review | MD, ED |
| Confirmed order with no Sales owner | MD, Sales/Export Manager |

Tick as many people as you like; everyone ticked gets the e-mail. Nobody valid ticked → the MD.

## Statistics (coaching only)

Reports › **Sales Performance** › *Salesperson*: quotes sent, won, lost before / after PI, win rate, average
days from quotation to PI and the most common lost reason, per person. Leave it empty for the report as
before. These figures **never change a target**. **Mark Lost** has an optional reason category (Price,
Quality, Delivery time, Competitor, Buyer postponed, Other) so the reasons can be counted.
