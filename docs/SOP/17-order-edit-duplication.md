# Editing, Duplicating & Reordering

## What this chapter covers

Four related things that don't belong to any single stage of the 9-stage
pipeline, because they can happen at almost any point in an order's life
(or after it's finished entirely):

- **Editing an order's core details** after it was created — Incoterm,
  currency, ports, COO type, container type, special requirements,
  estimated weight/volume, indicative freight/insurance, and the buyer's
  own PO reference.
- **Duplicating a product line** on an order — a quick way to add a near-
  identical line when only the name or dimensions differ.
- **Duplicating a whole order** — a repeat order for the same client,
  started by staff from any existing order.
- **A client's own reorder request** — the buyer-facing equivalent,
  reviewed by staff before it becomes a real order.

## Why this exists

Before this, the core fields set on the New Order form could never be
corrected or updated afterward — not even a typo — and product lines
worked the same way: set once at creation, with no way to edit, remove,
or clone one later. Every repeat order also had to be typed in from
scratch, client details and all, even when it was identical to one
already on file. None of that matched how orders actually get corrected
and repeated in practice.

**What stays out of scope on purpose:** payment terms — advance/balance
percentage, balance trigger, balance days — are never touched by any of
the screens in this chapter. That's what
[Amendments](./10-amendments.md) is for, specifically, with its own MD
approval and signed-agreement paper trail. Keeping payment-terms changes
in exactly one place, rather than two competing ones, is deliberate.

## The pre/post-confirmation line

Before **Order Confirmation** (Stage 4) is issued, an order is still
being set up — editing its details or product lines needs nothing beyond
the ordinary "Manage orders" permission, same as it always has.

Once Order Confirmation is issued, the order's terms are meant to be
settled. From that point on, changing a core detail or a product line
needs the separate **"Edit an order after confirmation"** permission
(granted by default to Admin, Managing Director, and Executive Director —
adjustable any time from Roles & Permissions) — and every such change
requires a typed reason and is logged to the audit trail. A Super Admin
always has this, unconditionally, like every other permission. Someone
without it still sees the order and its product lines; they just don't
see the edit form, only a message naming the permission they'd need.

This is the same shape as the CA module's
[financial-year-lock override](./ca-06-fy-lock.md#overriding-the-lock-for-a-single-correction):
a normal action becomes a logged, permission-gated exception once a
meaningful point has passed, rather than being blocked outright — because
"the client asked for a change after confirmation" is a real, if
infrequent, situation.

## Editing Order Details

**Edit Order Details**, linked from the order page, opens a form with
every core field pre-filled. Before Stage 4 it saves immediately; from
Stage 4 on, the same form appears with a warning banner and a required
Reason field, and the button relabels to "Override & Save" so it's never
ambiguous that a lock is being bypassed.

## Product lines: edit, add, remove, duplicate

The order page's Products section is fully editable (again, subject to
the same pre/post-confirmation rule): each line is its own small form —
description, dimensions, finish, quantity, unit, unit price, HS code —
with Save, Duplicate, and Remove actions, plus an "Add Product Line" form
at the bottom.

**Duplicate** is the one built specifically for the common case: a second
size or a renamed variant of a product already on the order. It clones
every field on that line into a new one — nothing needs re-typing except
whatever's actually different. From Stage 4 on, duplicating (like editing
or removing a line, or adding a new one) needs the override permission
and a reason, exactly like the Edit Order Details form above.

## Duplicating a whole order

**Duplicate Order**, on the order page, is for a genuine repeat order —
available on any order regardless of its status, including one that's
long since closed. It creates a brand-new order carrying over:

- The same client, Incoterm, currency, ports, COO type, container type,
  special requirements, and every estimated weight/volume/freight field.
- Every active product line, copied as-is.

It deliberately does **not** copy anything specific to the original
order's own history: no dates, no generated documents, no payment status,
no FIRC/INR-actual data, no disputes or comments, and no stage progress —
the new order starts at Stage 1, exactly like one built by hand through
**New Order**. The buyer's own PO reference is cleared (staff record the
new one), and the action is logged to the audit trail linking the new
order back to its source. Staff land straight on **Edit Order Details**
for the new order to review and adjust anything before proceeding.

## The client's own reorder request

A client viewing any of their own orders in the portal sees a
**Reorder This** link. It opens a form pre-filled with that order's
product lines — the client can edit, remove, or add lines, and leave a
free-text note — before submitting.

This is deliberately **not** the same form new prospective clients fill
out ([Client Portal](./12-client-portal.md)'s intake form asks for
company legal name, billing address, VAT/EORI — none of which makes
sense for a client who's already on file). Submitting a reorder request
never creates a live order by itself; it lands in a staff review queue —
**Reorder Requests**, alongside Quotation Intake Review and PI Intake
Review — exactly like every other client-facing submission in this
system.

From there, staff open the request and see the client's product lines,
now editable one more time: this is where HS code and unit price get set
(never something the client provides — HS code must come from the master
list, same rule as everywhere else an order gets one). **Approve & Create
Order** runs the same duplication logic as staff-side Duplicate Order
above, seeded from these lines rather than the order's own current ones,
producing a real new order. **Reject** requires a reason, and the client
can always submit a fresh reorder request afterward.

## What doesn't change

Nothing in this chapter touches the 9-stage pipeline's own gates, the
Amendments module, or any other module's permissions. A duplicated order
runs through the full pipeline from Stage 1 like any other; it doesn't
inherit or skip anything from where its source order was.
