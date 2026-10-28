# Stage 5 — Supplier Purchase Order (Material Procurement)

## What this stage is for

Placing the actual procurement order with the supplier who will produce or
supply the material — a completely separate document and workflow from
anything the buyer sees, since the buyer never sees the Supplier PO or
knows who the supplier is.

## The system does this automatically

- Nothing progresses on its own — every action here is staff-entered, since
  this is an internal procurement step with no client involvement at all.
- **The FOB auto-skip**: the moment Stage 5's gate passes, the system checks
  the order's Incoterm. If it's **FOB**, Stage 6 (Freight Payment) is
  automatically marked **skipped** and Stage 7 unlocks immediately in its
  place — since on an FOB order the buyer arranges and pays for freight
  themselves, there's nothing for NexaCrest to collect at that stage. On a
  **CFR/CIF** order, Stage 6 unlocks normally instead. See
  [Chapter 6](./06-stage6-freight.md).

## What staff do

Stage 5 unlocks the moment Stage 4's gate passes. Suppliers are picked from
the **Suppliers** directory (Admin → Suppliers — see
[Chapter 15](./15-admin-settings.md#admin--settings)); there is no longer an
"add supplier" form on the order page. If the supplier isn't listed yet,
someone with the `manage_suppliers` permission (Admin/MD/ED by default) adds
it there first — the order page links straight to it. Then pick the supplier
and fill in the Supplier PO's material specification and commercial terms:

![Stage 5 section — add supplier and fill in Supplier PO terms](./images/s5_before_supplier.png)

**Supplier Type**, **Surface Finish**, **Unit**, **GST Rate**, and
**Dimensional Tolerance** are all admin-editable dropdowns (same
`manage_dropdown_options` screen as Container Type/COO Type — see
[Chapter 15](./15-admin-settings.md#admin--settings)), not free text.
Supplier Type offers **Quarry Owner**, **Processor (Job-Work Basis)**
(a unit that processes material supplied by/on behalf of someone else, not
one that owns the quarry or the factory itself), **Trader**, and
**Factory Owner** — four genuinely distinct roles, not overlapping labels.
A supplier can hold **more than one** type in the directory (e.g. Quarry
Owner *and* Factory Owner). When the supplier picked on **Save Supplier PO
Terms** has several, a **Supplier Type for this order** dropdown appears and
staff choose the one that applies — it is what prints on the Supplier PO. A
supplier with a single type has it recorded automatically.

**Delivery Location** is a dropdown of the *selected supplier's own*
factory addresses, repopulating the moment a different supplier is
picked — a supplier with none yet shows only **Other (not listed) —
specify**, which reveals a free-text box. A supplier's factory addresses
are managed **only** on its own page in the Suppliers directory (by users
with the "Manage suppliers" permission) — a label (optional) plus the
address. The order page no longer adds or removes factory addresses (owner
request 2026-10-27), so nobody preparing a Supplier PO can delete an
existing address or type in a random one. Each address is immediately available in every
Supplier PO Terms form's Delivery Location dropdown for that supplier,
on this order and any other. Whatever Delivery Location text was
actually selected or typed is saved directly onto that Supplier PO's own
record — editing or removing a factory address later never changes a
Supplier PO that already used it. 90-item backlog "standalone order"
follow-on: the same holds for the supplier's own legal name, address,
city/state, GSTIN, PAN, phone, email, WhatsApp, contact person (with their
phone/WhatsApp), and the supplier type chosen for this order — picking a
supplier on **Save Supplier PO Terms** copies those details onto that
Supplier PO's own record at that moment, so the order is self-contained:
editing, deactivating or deleting the supplier in the directory later
never changes a Supplier PO already saved against them.

**Basic Value, GST Amount, Total Payable, Advance Amount, and Balance
Amount auto-calculate** as Quantity, Unit Price, GST Rate, and Advance %
are filled in (Basic Value = Unit Price × Quantity; GST Amount = Basic
Value × GST Rate; Total Payable = Basic Value + GST Amount; Advance/Balance
split Total Payable by Advance %). This is a convenience, not a lock — every
auto-filled figure stays a plain, directly-editable field, so a genuine
exception (a negotiated lump-sum total that doesn't follow the formula, for
instance) can still be typed in by hand after the fact.

**A 0% GST rate is allowed** (owner decision 2026-10-28). Type `0` in GST
Rate: it is saved as 0 and the Supplier PO prints "0.00% — 0.00" for GST,
not "X% — To Be Confirmed" (which is what a blank GST Rate prints).

**A 0% advance is allowed** (owner decision 2026-10-27). Type `0` in
Advance %: the form fills Advance Amount as 0.00 and the whole Total
Payable as the Balance, and 0 is saved as 0 — not as "not filled in". The
Supplier PO then prints **"Advance (0.00%) \* 0.00"** and the full balance,
instead of "Advance (X%) \* To Be Confirmed". Step 7e completes by itself
(see below).

**Save Supplier PO Terms** creates the internal procurement record (and
generates the Supplier PO reference) — it does **not** generate the document
or pass any gate by itself. From **Documents**, **Generate Supplier PO**
produces the actual PDF, pre-filled with everything just entered, with a
blank acceptance section for the supplier to sign. Once terms are saved, the
section shows what's on file and offers the one action that matters here:

![Stage 5 section — terms saved, awaiting supplier signature](./images/s5_terms_saved.png)

Exactly like Stage 2's Buyer PO, the last two sub-steps are done in this
order:

1. **7c · Attach Supplier PO Acknowledgment** — upload the supplier's
   actual signed copy, kept on record with version history. Like the Buyer
   PO's attachment, this **does not by itself confirm the signature or
   pass the gate**. The supplier doesn't always send one back, so 7c also
   offers two other ways forward:
   - **Generate Supplier Link** — a no-login link (like the Buyer Action
     Link) where the supplier uploads their signed PO / acknowledgment
     themselves. It's emailed to the supplier when the PO has their email,
     and shown on the card with **Copy Link** to send any other way. It
     works for 60 days; regenerating it cancels the old one. The page (laid
     out like the Quotation details form) shows the supplier only their PO
     reference, a **Download Purchase Order** button once the Supplier PO
     is approved/sent, and what has been received — nothing about the
     buyer. The order's team is notified when they upload, and
     the file lands in the order's `07-supplier-po/received/` folder.
   - **Skip — supplier did not acknowledge** — record why (reason of at
     least 10 characters, audit-logged). This completes 7c and opens 7d
     without an acknowledgment on file.
2. **7d · Confirm Supplier Signed & Returned PO** — the button staff click
   once the supplier's countersigned copy is back and attached. **This is
   the action that passes Stage 5's gate** (and triggers the FOB auto-skip
   check above). While a copy is required (see the wet-signature gate
   below) this sub-step shows "Attach the supplier's signed acknowledgment
   in Step 7c first" instead of the button until one is on file (or the
   skip is recorded).

> **Document-generated gate:** "Confirm Supplier Signed & Returned PO" is
> also refused if the Supplier PO document itself was never generated —
> the supplier can't have signed and returned a document they were never
> actually sent. Unlike the wet-signature gate below, this one has no
> toggle: saving terms alone was never enough for the gate to pass, and
> now neither is confirming a signature with no SUPPO document on record
> either.

> **Wet-signature gate:** by default (**Admin & Settings → the
> `wet_signature_required_supplier_po` toggle**, on unless an Admin has
> turned it off), clicking "Confirm Supplier Signed & Returned PO" is
> **not** enough on its own either — it's refused until at least one file
> is also on record under "Attach Supplier PO Acknowledgment" (7c), or 7c's
> "supplier did not acknowledge" skip is recorded with its reason. Same
> loophole this closes as on the Buyer PO side: a stage confirmed "signed"
> with zero physical evidence ever attached. Turn the toggle off only for
> a workflow that genuinely doesn't need the physical copy.

Below the signing actions, the supplier is paid as two sub-steps of their
own (owner decisions 2026-10-27): **7e · Supplier Advance Paid** (the
last card of Step 7) and **10a · Supplier Balance Paid** (the first card
of Step 10, before packing). Both are recorded by staff with the **Manage
supplier payments** permission (Admin, MD, ED and Accounts Executive by
default); everyone else sees the amounts but no buttons.

Each leg is **bill first, then payment**:

1. **+ Add the supplier's advance bill** (7e) or **balance bill** (10a).
   First pick the **Bill type** (owner decision 2026-10-28, Option B):
   - **Proforma / advance request** — the supplier asks for money now. It
     is *not* a cost. 7e starts on Proforma. Enter the full value exactly
     as printed on the proforma, and in **Amount asked now (₹)** the part
     it asks for now (e.g. the 40% advance — the planned advance is shown
     as a hint). Leave it blank when the proforma asks for the whole bill;
     it can't be more than the bill total.
   - **Tax invoice / final bill** — the real bill; this is the cost. 10a
     starts on Tax invoice. When an earlier proforma from this supplier is
     on the order, **Advance already paid on a proforma from this vendor**
     links it (picked automatically when there is only one): what was paid
     on the proforma counts towards the invoice, and the proforma stops
     counting as a cost — so the same goods are never paid or counted
     twice.

   Then the bill number, bill date, taxable value, **GST rate (%)** picked
   from the GST Rate list (0, 5, 12, 18, 28 — 18% preselected), the bill
   file and an optional note. **GST (₹)** and **Bill total (₹)** fill in by
   themselves and can't be typed; the server works the GST out again on
   save and refuses a rate that isn't on the list.
2. **₹ Mark Paid** (the green button in the bill's row) opens the payment
   form: amount paid (prefilled with what is still due — a proforma's
   asked amount, or an invoice's total less anything already paid on it or
   on its proforma), paid on, mode (NEFT / RTGS, IMPS, UPI, Cheque) and
   UTR / reference. The supplier's bank details show above it, or a
   warning with a link when none are on file. More than is still due is
   refused. A bill can be paid in parts: after the first payment the
   button reads **₹ Record another payment**, and the row lists every
   payment with its UTR and **Due ₹…** until nothing is left. A payment
   counts towards the step it is recorded from (7e advance, 10a balance).

**Example** — supplier PO ₹5,31,000, 40% advance: in 7e add Proforma
PI-045 for ₹5,31,000 asking ₹2,12,400 now, and pay ₹2,12,400. In 10a add
Tax invoice TI-112 for ₹5,31,000 linked to PI-045 — it shows ₹2,12,400
already paid and **Due ₹3,18,600**; pay that. The supplier is paid
₹5,31,000 once, and only the tax invoice is counted as cost.

- **A tax invoice added in 7e that is not fully paid** (the supplier sent
  the final bill up front) is shown in 10a as well — "Added in Step 7e —
  shown here until fully paid" — so its balance is paid from 10a.
- A bill with a payment on it can't be removed; **Remove** shows only on a
  bill with no payment yet.

The top of each card shows the Supplier PO reference, the PO total, the
amount planned for that leg and — once paid — how much and when.

- **When the Supplier PO terms plan an advance, 10a waits for 7e** (owner
  report 2026-10-27): the balance bills can be added, but their Mark Paid
  stays hidden behind "Pay the supplier advance first (Step 7e)" until the
  advance is paid, so the advance can't be forgotten.
- **No advance planned (0%, or none entered):** 7e completes by itself and
  shows **"✓ No advance on this order"** — the full amount is paid as the
  balance in 10a. An **"+ Add the supplier's advance bill anyway"** toggle
  stays available if the supplier still asks for something up front.
- A payment recorded before supplier bills were kept stays as it is
  ("Paid before supplier bills were recorded here").

The paid date feeds the Reports hub's **Today's Payments** view (see
Chapter 14), and every bill and payment flows into the order's
financials (Step 12, [CA chapter 8](./ca-08-order-financials.md)).

Once confirmed:

![Stage 5 section — supplier signature confirmed](./images/s5_signed_confirmed.png)

## What the client does (or doesn't)

**Nothing at all, and they're never shown anything about it.** The Supplier
PO, the supplier's identity, and the procurement terms are entirely internal
to NexaCrest — nothing here reaches the client portal, and no client-facing
document at any stage references this transaction. This is the one stage
in the whole pipeline with zero client involvement in either direction.

## What happens next

Confirming the supplier's signature passes Stage 5's gate — and, since this
running example order is on **FOB** terms, that same action immediately
auto-skips Stage 6:

![Stage 6 — auto-skipped for this FOB order](./images/s5_stage6_skipped.png)

![Stage tracker — Stage 5 passed, Stage 6 skipped, Stage 7 now in progress](./images/s5_stage_tracker_after.png)

On a CFR/CIF order, Stage 6 (Freight Payment) unlocks normally instead of
being skipped — see [Chapter 6](./06-stage6-freight.md) for that path.
Either way, [Chapter 7](./07-stage7-packing-bl.md) (Packing & BL
Instruction) is where both paths meet again.

## Production & Estimated Shipment — and where its history lives

The order-detail page carries a standing "Production & Estimated
Shipment" panel (just above Stage 6) where staff post free-text progress
updates — e.g. "Cutting and finishing in progress — 30% complete" and
"Week of 15 October 2026." It isn't gated to Stage 5 specifically; anyone
with `manage_orders` can post an update any time Stage 4 or later is
unlocked, independent of which stage is currently active.

Once the order is fully settled (locked), changing it is a locked-order
edit like any other (QA Round 5): only someone with **"Override locked
data"** sees the form, it asks for a reason (minimum 10 characters), and
the change is logged as `ORDER_EDITED_WHILE_LOCKED`.

Every update to either field is audit-logged — who posted it, when, and
the exact old/new text — the moment it's saved, under action types
`PRODUCTION_STATUS_UPDATED` and `EST_SHIPMENT_DATE_UPDATED`. Re-saving the
same text twice does not create a duplicate entry. The panel itself links
straight to the order's own Audit Log page ("view update history") so this
is never a dead end — click through any time to see the full trail of who
said what, and when, without digging through the general Audit Log and
filtering by order number.

**Changing the estimated shipment date automatically tells the client** —
it posts a message into that order's own [Order Updates
conversation](./12-client-portal.md#inside-an-order) ("The estimated
shipment date for your order has been updated to: ...") the same way a
staff reply there does, so it shows up in their client-portal thread and,
if an email address is on file, in their inbox too. There's no separate
notification mechanism for this one field — it reuses the same channel
every other order-update message already goes through.

**A reminder fires automatically as shipment approaches**, too — every day
the estimated shipment date is within the next 7 days (and not yet
passed) for a still-active order that has a **Pre-Shipment Inspection**
compliance task that isn't marked Skipped, everyone who manages orders
gets a reminder to confirm production completion and prepare for the
3rd-party inspection. See the [Compliance
Checklist](./09-stage9-despatch-closure.md#compliance-checklist) in
Stage 9 for that task, and
[Order Financials](./ca-08-order-financials.md#4-travel-expenses--factory-visits)
for recording the travel cost of these factory visits.
