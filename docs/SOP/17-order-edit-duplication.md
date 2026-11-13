# Editing, Duplicating & Reordering

## What this chapter covers

Four related things that don't belong to any single stage of the 9-stage
pipeline, because they can happen at almost any point in an order's life
(or after it's finished entirely):

- **Editing an order's core details** after it was created — Incoterm,
  currency, ports, COO type, container type, special requirements,
  estimated weight/volume, indicative freight/insurance, and the buyer's
  own PO reference. (**The Incoterm is locked once Stage 6 is skipped,
  in progress or passed** — D-258, 6 Nov 2026.)
- **Duplicating a product line** on an order — a quick way to add a near-
  identical line when only the name or dimensions differ.
- **Duplicating a whole order** — a repeat order for the same client,
  started by staff from any existing order.
- **A client's own reorder request** — the buyer-facing equivalent,
  reviewed by staff before it becomes a real order.
- **Buyer details after the client is corrected** — how an order picks
  up a corrected client e-mail, address or consignee (D-337, 12 Nov 2026).

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
in exactly one place, rather than two competing ones, is deliberate. The
same holds, since owner decision D-342 (12 Nov 2026), for the order's
**product lines and prices once its first Quotation is approved**: they
change only through a **Products & prices** amendment (below).

## The pre/post-confirmation line

Until the order reaches **Stage 4 (Order Confirmation)** — that is, until
the advance payment clears or the Letter of Credit is confirmed usable —
it is still being set up: editing its details or product lines needs
nothing beyond the ordinary "Manage orders" permission, same as it always
has.

**Product lines are fixed earlier — at the first approved Quotation**
(owner decision D-342, 12 Nov 2026). From the moment the order's first
Quotation is approved, no one can add, edit, duplicate or delete a line, or
change the order's currency — not with "Edit an order after confirmation",
not with "Override locked data", not the Super Admin. The products table is
read-only with the note "Prices and products are fixed by the approved
Quotation of <date> — change them through an Amendment (Order page ›
Amendments › Products & prices)." If the Proforma Invoice was approved before
any Quotation, the first approved PI fixes them in the same way and the note
names it ("… fixed by the approved Proforma Invoice of <date> …", owner
decision D-370, 13 Nov 2026). See [Amendments](./10-amendments.md).
Packing-list actuals are still recorded as before.

Once the order reaches Stage 4, its terms are meant to be settled. From
that point on, changing a core detail
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

### A stricter line once the order is locked

A **second, stricter** gate sits in front of the one above: once an
order is **locked** (`is_locked`) — closed via
[Stage 9](./09-stage9-despatch-closure.md), or marked lost — editing its
details or product lines needs the **"Override locked data"**
permission specifically, not just "Edit an order after confirmation."
This is the same permission already required to force a document
through on a locked order (see [Documents](./13-document-review-approval.md)),
applied consistently to the order's own core fields and product lines
too. A locked order is always past Order Confirmation as well, but a
post-confirmation order is not necessarily locked — so this check is
evaluated first, and whichever one applies decides what the edit form
shows: the warning banner and reason field read differently depending on
which line was crossed, and the change is logged as
`ORDER_EDITED_WHILE_LOCKED` rather than `ORDER_EDITED_POST_CONFIRMATION`.
A Super Admin always has this, unconditionally, like every other
permission. Before this, a closed, fully-despatched order's own details
could still be edited by anyone holding just "Edit an order after
confirmation" — this closes that gap, matching how document generation
on a locked order has always worked.

## Editing Order Details

**Edit Order Details** — the **Edit order** button in the action bar at the top of the order page (D-321) — opens a form with
every core field pre-filled. Before Stage 4 it saves immediately; from
Stage 4 on, the same form appears with a warning banner and a required
Reason field, and the button relabels to "Override & Save" so it's never
ambiguous that a lock is being bypassed. Once the order is actually
**locked** (see above), the banner, reason prompt, and button wording
change again — "Override Lock & Save" — and require "Override locked
data" specifically rather than "Edit an order after confirmation."

**Two people editing at once** (QA Round 6, R6-N27). If someone else saves
the order's details after you opened the form, your save is refused with
"This order was changed by <name> at <time> after you opened this page.
Reload the page to see the change, then make yours again." — so nobody's
change is silently undone. The client and supplier edit forms work the same
way. Saving order details is recorded in the audit log.

## Product lines: edit, add, remove, duplicate

The order page's Products section is fully editable (again, subject to
the same pre/post-confirmation rule). Since owner decision D-273 (11 Nov
2026) it is one compact table with **Edit**, **Duplicate** and **Delete**
on every line and **+ Add product** at the top; Edit and Add open a side
panel in four steps (Product · Options · Size and quantity · Price) with
"More" for the description, Dimensions text, finish, line type, HS / SAC
code and category. See
[Stage 1 — The Products section](./01-stage1-enquiry-quotation.md#the-products-section-on-the-order-page-owner-decisions-d-271--d-273-11-nov-2026).

Quantity and unit price are checked wherever a line is saved — creating
the order, adding a line, editing a line (QA Round 6, R6-N14/N15): the
unit price has at most 2 decimals (0.0001 is refused, not saved as 0.00)
and at most 999,999,999,999.99; the quantity has at most 3 decimals; the
line value (quantity × unit price) at most 999,999,999,999.99. On the
order's details, a minimum lead time or sailing time above its maximum,
and a net weight above the gross weight, are refused with a message
beside the field (R6-N28).

**Duplicate** is the one built specifically for the common case: a second
size or a renamed variant of a product already on the order. Since owner
decision D-272 (11 Nov 2026) it is an **exact copy placed right below** the
original, marked "copy of line N": every option, the piece size, unit,
quantity, price, HS code, line type, SAC, category, product, material and
price-request link. The MD's price approval is **not** copied — the copy is
checked at once and, if it is below your lowest price, goes to the MD.
Change only what differs (usually the size and the price). Lines can be
added, edited, duplicated and deleted only until the order's first
Quotation is approved (D-342, above); after that use a Products & prices
amendment.

The same **Duplicate** button is also on the **New Order** creation
screen itself, on every product row, from the very first line — it
doesn't only appear after the order is saved. It works the same way
there: clones that row's values into a brand-new row in the same form,
instantly, with nothing saved yet. This is different from "+ Add product
line" right below it, which adds a blank row — use Duplicate when the new
line is mostly the same as one already typed (a second size, a colour
variant) and Add for a genuinely new product.

## Duplicating a whole order

**Duplicate Order**, on the order page, is for a genuine repeat order (its
lines are the same full copies as a line's Duplicate, each checked again) —
available on any order regardless of its status, including one that's
long since closed. It creates a brand-new order carrying over:

- The same client, Incoterm, currency, ports, COO type, container type,
  special requirements, and every estimated weight/volume/freight field.
- Every active product line, copied as-is (with its No. of pieces and
  Size note). The new order prints the detailed product table on its
  documents (D-339), whatever the earlier order printed, and its prices
  are fixed again only when its own first Quotation is approved.
- **Annexure A** (since 12 Nov 2026, D-338): its product entries with the
  same pictures, its Additional Terms, whether it is included and its
  content (Product Specification / Additional Terms / Both). **Annexure B
  (as shipped) is not copied**, and neither is the choice of annexure for
  the Commercial Invoice — they describe what was shipped on the earlier
  order.

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
producing a real new order (Annexure A comes along as described above).
**Reject** requires a reason, and the client can always submit a fresh
reorder request afterward.

**"As per annexure" on a reorder line (D-338).** Each line on the buyer's
reorder form and on the staff review screen has an **As per annexure** tick
box next to "Quantity not decided yet" (buyer) / **Qty To Be Confirmed**
(staff). It means the line's sizes are given in the annexure. Only one of
the two boxes can be ticked — ticking one clears the other, and the server
refuses both: "Line 2: tick either "Qty To Be Confirmed" or "As per
annexure", not both." While it is ticked the **Dimensions** box shows "As
per annexure" and takes nothing, and the quantity is required ("Line 2: a
line "As per annexure" still needs its quantity — enter the Qty."). A line
that was "As per annexure" on the earlier order starts ticked. When the
request is approved the new order's line keeps the tick, so its documents
print "Dimensions: As per Annexure A".

## Buyer details after the client is corrected (D-337)

Every order keeps its own copy of the buyer's details — company name,
billing address, VAT / EORI number, contact person, e-mail, phone,
country of destination, consignee and notify party. That copy is what
the order's documents print. When the client's details are corrected on
the client page, the order follows in one of two ways.

**An order with nothing issued yet** (no documents, or only drafts and
documents still in review) is updated **at once** when you save the
client. The client page then says *"Buyer details also updated on draft
order(s): …"*. The MD and ED get a short notice saying what changed. No
approval is needed.

> Example: you created an order with the client e-mail a@b.com, generated
> a draft quotation, deleted it, and corrected the e-mail on the client
> page to x@y.com. On saving the client, the order now shows x@y.com, and
> the next quotation you generate prints x@y.com.

If an order still shows old details (for example because the client was
corrected before this rule existed), the order page shows *"The client's
details have changed since this order was created"* with each field
(*On this order → Client now*) and the button **Update buyer details from
the client**.

**An order that already has an issued document** (a document that was
approved or sent) is **not** changed automatically. The order page shows
the same notice with the differences and the button **Ask to update buyer
details from the client**:

1. Type the reason (10 to 500 characters), e.g. *"The buyer's purchasing
   e-mail changed; the BL and invoice must go to the new address."*
2. Click the button. The request waits for approval; only one request per
   order can wait at a time.
3. The MD or ED sees it on the order page and in **Approvals › Other**
   ("Buyer details update"). They click **Approve** (a note is optional) or
   **Refuse** (a note of at least 10 characters is required). Nobody
   approves their own request, unless **Users & roles › Self-approval
   rules › buyer_details_change** allows it (by default only the Managing
   Director).
4. You get a notification with the decision.

**If the client is changed again** before the request is decided, the
request **lapses** and nothing is applied: *"The client's details changed
again after this request — ask again so the approver sees the current
details."* Ask again, so the approver approves exactly what will be
copied.

**Documents already issued never change.** They keep the details they
were issued with. Only documents generated after the change show the new
details. Every step (request, approval, refusal, lapse and each changed
field) appears in the order's history (audit log).

## What doesn't change

Nothing in this chapter touches the 9-stage pipeline's own gates, the
Amendments module, or any other module's permissions. A duplicated order
runs through the full pipeline from Stage 1 like any other; it doesn't
inherit or skip anything from where its source order was.
