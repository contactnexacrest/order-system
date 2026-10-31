# 22 — Procurement: requirements, quotes, approval, receipt

Procurement buys what an order needs. Sales says **what** is needed and
**by when** — never at what price; Procurement finds the supplier, and
the buying prices stay with Procurement, Accounts and management.

## 1. Sales raises a purchase requirement

On the order page, open **Purchase Requirements — for Procurement**
(next to Step 7). Choose a product line (its description, quantity and
unit are copied) or describe what is needed, enter the **needed-by**
date, and add a specification or a note if useful. **+ Raise purchase
requirement** sends it to Procurement as PR-n.

The card then shows each requirement's status and, once awarded, the
supplier's name — never the price.

## 2. Procurement's queue

**Operations → Procurement** lists every open requirement, earliest need
first; a late one is marked. Open a requirement to work on it.

## 3. Quotes and comparison

Record each supplier's quote: unit price before GST, GST %, lead time in
days, how long it is valid, and the supplier's own reference. The table
compares them **cheapest first, with GST**, with the total for the whole
quantity, and marks the lowest.

## 4. Award and approval

**Award** a quote. The award's value (unit price × quantity, with GST) is
checked against the **approval limit** of the designation the person is
working as:

- within the limit — the purchase is approved at once;
- above it — it waits for someone with **Approve purchases** whose own
  limit covers it. Nobody approves their own award (Admin, MD and ED
  excepted, as for other approvals).

Starting limits (change them in **Admin → Departments → Edit**):

| Designation | Approves up to |
|---|---|
| Procurement Executive | ₹0 — every award goes to a manager |
| Procurement Manager | ₹5,00,000 |
| Procurement Director, MD/ED | no limit |

## 5. Receipt and quality check

When the goods arrive, record the quantity received, the date and the
**quality check**: Passed, Passed with remarks, or Failed. Say what the
check found unless it passed.

## 6. Supplier performance

**Procurement → Supplier performance** shows, for each supplier: quotes
given, awards won, receipts, how many came by the needed-by date, and how
many failed the quality check.

## 7. Cancelling

A requirement not yet received can be cancelled with a reason; it stays
on record.

The Supplier PO for the order (Step 7a onwards) is also Procurement's;
see [Supplier Purchase Order](./05-stage5-supplier-po.md).
