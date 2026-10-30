# Incentives

## What this is

NexaCrest rewards the team for orders that are won, delivered and paid for.
Management decides each order's incentive. It is fixed once the buyer has
**fully paid** for the order, and it is paid together with your salary
(owner decision 2026-10-30).

Two kinds of pay are kept apart:

- **Salary (fixed pay)** covers the 1st to the last day of the month and is
  paid on the last day of the month.
- **Incentive (Incentive pay)** follows its own cycle, from the **21st of
  last month to the 20th of this month**. Orders the buyer fully pays in
  that cycle make up this month's incentive, paid together with this
  month's salary. Management can change the cut-off day (the 20th); the
  page shows the current cycle.

"Fully paid" means every payment the buyer owes on the order has reached
the bank: the advance (or the payment under a Letter of Credit), the balance
and, on CFR/CIF orders, the freight. The date the last of them cleared
decides the incentive month.

## Roles

An order's incentive is shared by role:

| Role | Usually |
|---|---|
| Sales | wins the order and agrees the price |
| Export Executive | PI, OC, CI and the buyer's paperwork |
| Marketing | brings the enquiry in |
| Logistics | factory dispatch, CHA and freight |
| Accounts | collection, FIRC and the books |

Each person has their usual role(s); management can change who did which
role on a particular order. When two people share a role on an order, they
share that role's amount equally.

## My Incentives (everyone with an incentive role)

**My Incentives** (left menu) lists your own incentive on each order once
its buyer has fully paid: the order, the client, your role, the amount and
its status:

- **"November 2026 incentive"**: fixed, to be paid with the November
  salary;
- **"November 2026 incentive — paid"**: paid.

At the top: what you have been paid this year, and what is coming with the
next salary. You see only your own amounts. Nothing else about the order's
incentive is shown, and nothing appears before the buyer has fully paid.

## Incentive Payouts (Accounts)

**Incentive Payouts** (left menu, "Pay incentives" permission) lists, for one
incentive month, who is to be paid how much for which order, including the
associates (outside people who introduced a client; see below).

1. Choose the **incentive month**. The list shows its cycle, e.g. "orders
   fully paid 21 Oct to 20 Nov · paid with the November salary".
2. Pay staff incentives together with the month's salary, through payroll
   (salary TDS). Pay an associate against their invoice; the CA advises the
   TDS.
3. Record each payment: the date, the amount actually paid and any TDS,
   then **Mark paid**. The amount paid cannot be more than the payout, and
   the date cannot be in the future or before the 1st of the month before
   the payout's incentive month (QA Round 6, R6-N10). When nothing due was selected, nothing is recorded and a warning says so (R6-N11). **Mark all paid** records every due line of the
   month at the amounts shown. **Download list (CSV)** gives the list for
   payroll.

Accounts cannot change any amount. When everyone on an order is paid, the
order's incentive goes to Zoho Books as **Commission Expense** with the next
sync (chapter [CA-03](./ca-03-zoho-sync.md)).

## In the order's figures

The team incentive and an associate's commission are costs of the order.
Once the buyer has fully paid, each appears in the order's costs (Step 12a,
as a "Commission" line set by Team Incentive, which cannot be removed
there), in its profit (Step 12c), in the reports and in the CA Financial
Annexure. Only management sees how an order's incentive was decided.

## Associates

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
one is refused without the other. The list then shows a **Signed copy**
link under the date. Choosing a new file on Edit replaces the copy on file
(the earlier one stays in storage). Only people with "Manage team
incentives" can open the copy; the general file link refuses everyone
else, and every upload and download is recorded in the audit log.
