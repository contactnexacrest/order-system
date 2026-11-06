# Incentives

## What this is

NexaCrest rewards the team for orders that are won, delivered and paid for
(owner decision 2026-10-30; reworked by D-245 P4, 4 Nov 2026).

**How much.** An order's team incentive is a share of its profit after the
associate's commission (if the client came through an associate). That
profit never counts below ₹0.

- The **global rule** (Admin → Pricing settings) is a % of that profit
  (15% to start) or a fixed amount per unit; a product may have its own.
- On one order, the MD may set **a % of this order's profit** or **a fixed
  amount** instead, with a written reason.
- With the incentive program **off**, there is no global incentive; the MD
  may still set one by hand on a single order.
- The app warns when the margin left after the incentive falls below the
  minimum margin, and refuses an incentive larger than the profit.

**When it is fixed.** Nothing locks by itself.

| State | Meaning |
|---|---|
| **Open** | Estimated; the MD can change it any time. |
| **Ready to lock** | The buyer has fully paid every payment (advance or LC, balance, freight on CFR/CIF) and no buyer claim is open. |
| **Locked** | The MD locked it, one order at a time or "Lock all ready" (Team Incentives → Ready to lock). It is added to the order's costs. The MD may unlock it, with a reason, until anything is paid. |
| **Paid** | Accounts paid it. It never changes again. |

The dashboard shows the MD how many orders are ready to lock, and the MD is
reminded a few days (setting, 3 to start) before each payout run.

**When it is paid.** Staff are paid in the **payout run after the order was
locked** — the same run as associate commission (Associates → Commission
settings: monthly on a day, or weekly). The old 21st-to-20th cycle, the
reward table, the minimum profit and the new-client bonus are gone.

Every change — amount, who gets it, shares, lock, unlock — is kept in the
order's incentive **history** with who, when, before → after and the reason.

## Roles

The roles are an **editable list** (Team Incentives → settings; only the MD
changes it). The usual shares of the active roles must add up to 100%. To
start with:

| Role | Usual share | Usually |
|---|---|---|
| Sales | 30% | wins the order and agrees the price |
| Sales Manager | 10% | oversees and helps close |
| Export Documentation | 15% | PI, OC, CI and the buyer's paperwork |
| Export Compliance | 5% | screening, licences, export benefit claims |
| Marketing | 15% | brings the enquiry in |
| Logistics | 15% | factory dispatch, CHA and freight |
| Accounts | 10% | collection, FIRC and the books |

A role can be added, renamed, deactivated, or deleted if it was never used.
The Sales Manager is an employee and is paid only through this incentive.

Each person has their usual role(s). On each order the MD can:

- **untick** a role (or a role with nobody on it simply drops out): its
  share spreads over the ticked roles in proportion;
- **type the shares** for this order (they must add up to 100%), for example
  100% to one person who handled everything;
- change **who did each role**. One person may hold several roles and gets
  each role's amount.

People sharing a role on an order share that role's amount equally.

## Who is eligible, and caps (owner decision 6 Nov 2026, D-259)

- **Eligible for team incentive.** Every person is eligible unless switched
  off. Each **designation** (Admin › Departments & designations, column
  "Incentive eligible") says whether its people share in the team incentive;
  a person's own switch (**Edit User → Team incentive**, also on **Add user**)
  overrides the designation for that person only. Only the **MD** changes
  either; everyone else sees them greyed out. Admin › Departments lists the
  people whose own switch differs from their designation.
- **Not eligible** means the person gets **no share** of any order's team
  incentive. Their share **stays with the company** — it is **not** shared
  out to the others.
- **Caps** (Team Incentives → settings → **Caps**, MD only): per role, the
  most one person can receive **per order** and **per month** (the month the
  order is locked into — the staff payout run). Empty means no cap. The
  monthly cap counts what the person already has **locked** in that role for
  the month. Anything above a cap **stays with the company**; nobody else's
  amount goes up. No salary is entered anywhere.
- **Where you see it.** On the order (Step 12d) each person's share, what they
  get and what the company keeps, with the reason (e.g. "capped at ₹15,000 per
  order", "not eligible (Sales Manager designation)") and the total kept. The
  Incentive & Commission Register has two columns, **Kept — not eligible** and
  **Kept — capped**, with totals; a person not eligible appears there with
  ₹0 paid and the amount kept (never in "To pay" or "Owed").
- **Cost and profit.** The order's incentive cost (profit, CAFIN) is what is
  actually payable — the kept part is not a cost.
- **Only orders not locked yet.** Changing a switch, a designation or a cap
  changes open orders' estimates at once. **Locked and paid orders never
  change**; unlocking an order (with a reason) works it out again.

Worked example (mockup): profit ₹4,00,000 × 15% = ₹60,000, shared by role.
Sales (30%, ₹18,000) is capped at ₹15,000 per order → gets ₹15,000, ₹3,000
kept. The Sales Manager (10%, ₹6,000) is not eligible → ₹0, ₹6,000 kept.
Logistics (15%, ₹9,000) already has ₹14,000 locked this month against a
monthly cap of ₹20,000 → gets ₹6,000, ₹3,000 kept. Everyone else in full.
Paid ₹48,000; kept by the company ₹12,000 (not eligible ₹6,000, capped
₹6,000).

## My Incentives (everyone with an incentive role)

**My Incentives** (left menu) lists your own incentive on each order once
the MD has locked it: the order, the client, your role, the amount and
its status:

- **"November 2026 incentive"**: locked, to be paid in the November payout
  run;
- **"November 2026 incentive — paid"**: paid.

At the top: what you have been paid this year, and what is coming in each
payout run. You see only your own amounts. Nothing else about the order's
incentive is shown, and nothing appears before the order is locked.

## Incentive Payouts (Accounts)

**Incentive Payouts** (left menu, "Pay incentives" permission) lists, for one
incentive month, who is to be paid how much for which order, including the
associates (outside people who introduced a client; see below).

1. Choose the **payout run** (month). The list shows e.g. "November 2026
   payout run — orders locked before it".
2. Pay staff incentives together with the month's salary, through payroll
   (salary TDS). Pay an associate against their invoice; the app works out
   the associate's TDS (194H, threshold, catch-up, no-PAN rate, abroad
   papers — [Chapter 24](./24-associates.md), D-235) and fills in the net. An associate's line can be paid only into their **verified** bank
   account, past its waiting days; otherwise it is refused, and **Mark all
   paid** skips it and says how many were skipped. A claw-back (negative
   line) is deducted from the associate's next payment (see
   [Chapter 24](./24-associates.md)).
3. Record each payment: the date, the amount actually paid and any TDS,
   **Paid via** (with the salary / bank transfer / cheque) and the
   **Payment reference** (payroll month, UTR or cheque no.), then **Mark
   paid** (rule D-212, 2026-10-31: both are required; once every payout of
   the order is paid, its Commission cost line — and so the Zoho Books
   expense — ends "· paid with the salary (payroll), ref Payroll Nov-2026"). The amount paid cannot be more than the payout, and
   the date cannot be in the future or before the 1st of the month before
   the payout's incentive month (QA Round 6, R6-N10). When nothing due was selected, nothing is recorded and a warning says so (R6-N11). **Mark all paid** records every due line of the
   month at the amounts shown. **Download list (CSV)** gives the list for
   payroll.

Accounts cannot change any amount. When everyone on an order is paid, the
order's incentive goes to Zoho Books as **Commission Expense** with the next
sync (chapter [CA-03](./ca-03-zoho-sync.md)).

## In the order's figures

The team incentive and an associate's commission are costs of the order.
An associate's commission is worked out on the invoiced FOB of the goods at
the level fixed on the order at the PI (chapter 24, D-245 P3); the team's
basis is the order profit after it. Once the MD locks the order, each
appears in the order's costs (Step 12a, as a "Commission" line set by Team
Incentive, which cannot be removed there), in its profit (Step 12c), in the
reports and in the CA Financial Annexure.

A line the MD approved below its floor (chapter 27) already lowered the
order's profit, so the incentive — a % of the actual profit — is already
lower; the price check's "incentive cut" is shown on the card as an
explanation and never taken off a second time.

On the order page, **Step 12d · Team Incentive** is for the MD, the ED
(read-only) and people with "Manage team incentives". It shows the profit,
the associate's commission, the global rule, the amount for this order, the
profit left for NexaCrest, who gets it, the state and the history. With the
program off it still shows for the MD (to set an incentive by hand), and for
an order whose client came through an associate.

## Associates

The full associate system — portal, invitations, bank checks, holds and
take-backs, Zoho bills — is chapter [24 Associates](./24-associates.md).

Associates are outside people, in India or abroad, who introduce new
clients under a signed Associate Agreement. Management keeps their register
and links each client to the associate who introduced it. An associate is
paid the agreement's rate on the client's first orders once the buyer has
fully paid; nothing on repeat orders. Their payment appears on the Incentive
Payouts list in the same incentive month.

**The 10 days.** Everything from the client in the 10 days from the day
the client's **first PO was received** (Day 1, recorded at Step 4d) counts
as one order under the agreement: for a first PO received on 4 March, POs
received up to 13 March (Day 10) are part of it, and a PO received on 14
March (Day 11) or later is a repeat order with no commission. Orders
confirmed before the PO date was recorded count from their order date.
On an order's incentive card, "Associate commission" can still be set to
Yes or No by hand when management decides otherwise.

**The signed agreement.** On **Associates**, add or edit the associate and
enter **Agreement signed on** together with **Signed agreement (PDF)**:
one is refused without the other. The associate's page then shows a
**Signed copy** link. Choosing a new file on Edit replaces the copy on file
(the earlier one stays in storage). Only people with "Manage associates" can open the copy; the general file link refuses everyone
else, and every upload and download is recorded in the audit log.

## Reports and profit (owner decision D-245 P5, 4 Nov 2026)

**One profit calculation everywhere.** The order page (Step 12c and the Net
profit box), the Order Profitability report and the CAFIN CSV export all show:

1. **Profit before commission & incentive** — revenue (rupees credited, or
   estimated at the recorded rate) plus export benefits, minus supplier,
   freight/insurance, logistics bills, other costs, order expenses and
   expenses booked in Zoho Books.
2. **Associate commission** and **Team incentive**, each with its state:
   - **Estimated** — the order is not locked yet; the figure is worked out
     from the order as it stands (the same calculation as the Team Incentive
     card), so profit is never shown higher than it will be;
   - **Locked** — the MD has locked the order; the locked figure *replaces*
     the estimate (it is never added on top);
   - **Paid** / **Held** — the payouts are paid / held.
3. **Profit after commission & incentive** — what NexaCrest keeps.

GST on an associate's commission is paid on top against their tax invoice and
comes back as input credit — **not a cost**. TDS deducted from a payout is owed
to the government — **a liability, not a cost**. Both are shown apart.

Who sees what: the commission and incentive amounts (and the profit after
them, which would reveal their sum) are shown only to whoever manages or pays
incentives or sees the price build-up (MD, ED, Accounts). Others who may see
the order's financials see the profit **before** commission & incentive.

**Switch on the reports.** The Order Profitability report and the CAFIN CSV
export have **Include open orders (estimated)** (the default) or **Locked
orders only**. The CAFIN PDF itself is unchanged: it still shows locked
figures only.

**Reports → Incentives & Commission** (MD / ED / Accounts):

| Report | What it shows |
|---|---|
| **Register** | One row per order and payee (each staff member's share, each associate's commission): amount, GST, TDS, state, due date, payout run, paid on. Filters: state, payout month, person, associate, order date range. |
| **To pay** | This month's payout run (including anything locked for an earlier run and still unpaid), next month's run, and not locked yet (estimated) — each with its total. Filter staff / associates. |
| **Owed at month end** | Everything locked on or before the date and not paid by it; for today or later also the orders ready to lock. |
| **Person statement** | Every order of one staff member or one associate, in every state. |

Every report has **Export CSV** with exactly the columns on screen, and every
total is the plain sum of its rows. Staff see only their own amounts on **My
incentives**; associates on their portal (Commission).
