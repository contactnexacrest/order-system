# Disputes

## What this module is for

Formally recording and tracking a disagreement with a buyer — quality
complaints, delivery issues, anything that needs a documented response
within a committed timeframe — separate from the routine order-progress
comments in [Client Portal](./12-client-portal.md).

Like Amendments, this isn't part of the 9-stage pipeline: a dispute can be
raised at any point on any order and never blocks or unlocks a stage gate.

## Who can do what

Two separate permissions gate this module — they used to be folded into
the general `manage_orders` permission, which meant anyone who could
touch an order at all could also manage its disputes and flip the
client-facing dispute button. That's now split:

- **Manage disputes** (`manage_disputes`) — view the dispute log, raise a
  dispute, change its status, attach evidence, and enable/disable the
  client's "Raise a Dispute" button. Privileged by default: only Admin,
  Managing Director, Executive Director, and Super Admin hold it out of
  the box. Grant it to another role from **Roles & Permissions** if that
  business needs it more broadly.
- **Respond to disputes** (`respond_to_disputes`) — post a reply in a
  dispute's own reply thread (see below). Granted to Export Executive by
  default, since day-to-day sales staff are usually the ones who actually
  answer a dispute, without needing the broader ability to enable the
  button or change a dispute's status.

Holding either permission is enough to open a given order's Disputes
screen at all; what's shown on it (the Log Dispute form, Status/Attach
Document controls) depends on which of the two permissions you actually
have.

## The system does this automatically

- **The response-due date is calculated automatically** the moment a
  dispute is logged — using **working days**, not calendar days, against
  the company's configured response window (10 working days by default).
  Weekends and holidays are correctly excluded rather than naively counted,
  so the promised response date matches what the Dispute Resolution clause
  on the order's own documents actually commits to.

## What staff do

From **Disputes** on the order page:

**Log Dispute** — notice date, who it's from (free text, e.g. "Buyer, via
email"), a mandatory description, and optionally assign it to a specific
staff member.

For each logged dispute, staff can then update its **Status** (with
resolution notes once resolved) and **Attach Document** — any evidence or
correspondence relevant to the dispute, with an optional "received from"
note:

![Staff-side dispute log with response-due date and status controls](./images/dispute_staff_log.png)

Once resolved:

![A resolved dispute, showing the resolution notes and resolved timestamp](./images/dispute_resolved.png)

A dedicated **global Disputes log** (separate from any one order) lists
every dispute across all orders, filterable by status — useful for seeing
what's still open company-wide rather than checking order by order.

### Replying to a dispute

Each logged dispute has its own **Replies** thread, directly underneath
its status/evidence controls. Anyone holding **Respond to disputes**
(Export Executive by default — see "Who can do what" above) can write a
reply; it's timestamped and shows who posted it. This is deliberately a
separate thread from the order-wide progress chat described in
[Client Portal](./12-client-portal.md) — a dispute reply is scoped to
that one dispute, doesn't email the client automatically, and stays
visible for as long as the dispute record exists, so the full back-
and-forth on a specific complaint is easy to find later without wading
through unrelated order updates.

Because the reply thread lives on the dispute, and the dispute itself
is reached from the order's own Disputes screen, a dispute's entire
history — notice, status changes, evidence, and every reply — is always
part of that order's record, not a separate system you have to
remember to check.

### Letting the client raise their own disputes

By default, the buyer has no way to raise a dispute themselves — there's no
button in their portal. Staff choose, **per order**, whether to expose one:
a checkbox on the order page, **"Show 'Raise a Dispute' button to the client
in their portal for this order,"** turns it on or off. This is deliberately
not a blanket setting — some orders/relationships may warrant it, others
not.

## What the client does (or doesn't)

**If the checkbox above is off** (the default): nothing — there's no
dispute-related screen or button anywhere in their portal for that order.

**If it's on**, the buyer sees a simple form:

![Client portal — Raise a Dispute, only shown when enabled for this order](./images/dispute_client_form.png)

They can only submit a description — no status, no resolution, no document
upload from their side. Once submitted, the same working-days response
clock starts, and it's staff who take it from there exactly as if they'd
logged it themselves.

## What happens next

Nothing is automatically unlocked or triggered elsewhere in the system.
Resolving a dispute is purely a record update — the order's own pipeline
progress is entirely unaffected by a dispute being open, in progress, or
resolved.
