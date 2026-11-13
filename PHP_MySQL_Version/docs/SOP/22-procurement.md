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

**Procurement & suppliers › Procurement** lists every open requirement, earliest need
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
  limit covers it. Within your own limit the award is approved at once —
  the limit is the control; above it, nobody approves their own award
  unless their role is ticked for "Approve a purchase award they made" in
  Users & roles › Self-approval rules (default MD and ED; logged as
  PURCHASE_AWARD_SELF_APPROVED). Approval limits themselves are changed only
  by the MD or ED ([Chapter 21](./21-departments.md)).

The limit applies whatever the department state: a person who holds a
Procurement role without a department membership, or who has not chosen a
department this session, is held to that designation's limit. Where no
limit is set below Director level, the limit counts as ₹0, so the award
waits for an approver (nobody self-approves on an unknown limit).

Each step applies once. A second click, or a colleague acting on the same
purchase at the same moment, changes nothing: raising the same requirement
again shows "The same requirement is already open as PR-N — nothing new
raised.", and a second award, approval or receipt says someone else has
just done it.

Starting limits (change them in **Users & roles › Departments → Edit**):

| Designation | Approves up to |
|---|---|
| Procurement Executive | ₹0 — every award goes to a manager |
| Procurement Manager | ₹5,00,000 |
| Procurement Director, MD/ED | no limit |
| Procurement Manager with no limit set | ₹0 (waits for an approver) |

## 5. Receipt and quality check

When the goods arrive, record the quantity received, the date and the
**quality check**: Passed, Passed with remarks, or Failed. Say what the
check found unless it passed.

## 6. Supplier performance

**Procurement & suppliers › Supplier performance** shows, for each supplier: quotes
given, awards won, receipts, how many came by the needed-by date, and how
many failed the quality check. The link shows only to people with **Manage
purchasing**, the permission the page needs.

## 6a. Suppliers: the products they offer and their prices (D-360, 13 Nov 2026)

**Procurement & suppliers › Suppliers** lists every supplier with, under it, the products it offers: the base price
it quoted (₹ per m² or per CBM at the base thickness, or per unit), **Quoted on · valid until**, an **Offer active**
switch and **Edit price**. You need *Manage suppliers*; to see prices, *View purchase prices*; to switch offers, add
products and edit prices, the right to record supplier prices (Procurement, MD).

- **Show:** All · Active · Inactive · **No product prices yet (N)** — the last lists suppliers with no product price
  yet (they carry a yellow "No product prices yet" badge), so you can work through them.
- **Offer active.** Click the switch to stop using a supplier's price for a product ("not offering now") and click
  again to use it again. A switched-off price is not used for the product's selling price, for matching or for the
  lowest / highest supplier price. A supplier marked **Inactive** has its products greyed: "Inactive: none of their
  prices are used anywhere."
- **Edit price** opens the simple Edit price form (see [Pricing](./27-pricing.md)); Cancel brings you back here.
- **+ Add product** (on the supplier's row, or **+ Add product** under **Prices given for products** on the supplier's
  page):
  1. **Product** — pick it from the list (products that have no supplier yet come first and are marked "no supplier
     yet"), or click **create a new product** and enter its **Name** (must be new), **Category** and, if you know it,
     **HS code** (empty = the category's code).
  2. **Base thickness**, **Price ₹** and **per** (m² for a slab thickness, CBM for 50 mm and above; for a product
     without thicknesses, per its unit or per CBM). The other thicknesses are worked out by the category's price rules.
  3. **Quoted on** (not in the future) and **Valid until** if the supplier gave one. **Save**.
  You return to where you started with a green message; the offer is switched on. A product created here starts
  **Inactive** and not configured — it is not offered on quotations until it is configured on Products.
  If the supplier already has a price for that product you are taken to its **Edit price** instead — a supplier offers
  a product once.
- On the supplier's page the **Prices given for products** table shows Base thickness, Price, dates, **Offer active**,
  **Edit price** and **Open product**. (The old "Used for pricing" column is gone from this table; which supplier's
  price the product is priced from is still set on the product's Market prices.)

## 7. Cancelling

A requirement not yet received can be cancelled with a reason; it stays
on record.

The Supplier PO for the order (Step 7a onwards) is also Procurement's;
see [Supplier Purchase Order](./05-stage5-supplier-po.md).
