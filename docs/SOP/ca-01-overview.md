# CA / Accounting — Overview & Permissions

## What this module is for

A separate module for Chartered Accountant / accounting work, kept
deliberately independent of the 9-stage order pipeline. Nothing here
changes an order's stage or status, and nothing in the order-pipeline
Reports module reads from this module (or vice versa) — the two are
intentionally kept apart so CA/accounting figures can never be confused
with, or accidentally affected by, day-to-day order operations.

Phase 1 (this chapter) covers the module's foundation: who can see it, and
the INR Settlement Register — the first piece of data it tracks. Later
phases (Zoho Books sync, expense import, revenue/expense reconciliation,
financial-year and calendar-year reports) will each get their own chapter
here as they're built, following the same rule as every other module in
this system: **a new module or screen always ships with its own SOP
chapter.**

## Why INR actuals matter

Every order in this system is quoted, invoiced, and settled in a **foreign
currency only** (USD, EUR, etc.) — there is no INR amount anywhere on an
order by default. For Indian accounting, GST, and RBI compliance, the
*actual* INR amount credited to the company's bank account also needs to
be on record — and it needs to be recorded **the same day the payment
clears**, not later.

This matters because of exchange-rate drift: the foreign-currency amount
on the order was fixed at quotation time, but the INR value your bank
actually credits depends on the exchange rate on the day the money lands.
If someone comes back a week later and tries to "figure out" the INR
amount from the order's foreign-currency total using that day's rate,
the number will not match what the bank statement actually shows —
creating exactly the kind of mismatch that makes bank reconciliation
unreliable. Recording the real, bank-confirmed INR amount at the moment
it's confirmed is what keeps the books clean.

## Roles and permissions

The CA / Accounting module introduces its own permission keys, on top of
the roles/permissions system covered in
[Admin & Settings](./15-admin-settings.md). Nothing here changes how an
order is managed — a person can hold CA-module permissions with zero
order-management access, or vice versa.

| Permission | What it allows |
|---|---|
| `ca_module_view` | See the CA / Accounting module at all (the "CA / Accounting" link in the sidebar, and the `/ca` page). |
| `inr_actual_view` | See the actual INR amount recorded against a cleared advance/balance/freight payment. |
| `inr_actual_edit` | Record or correct the INR actual amount for a cleared payment. |
| `inr_actual_delete` | Remove a recorded INR actual amount (a destructive correction — kept separate from edit). |
| `ca_internal_doc_manage` | Turn on, and generate, the internal-only CA Financial Annexure for a specific order (see below) — deliberately **not** granted to the Accounts Executive or CA roles by default, unlike every other CA permission. |

A new role, **CA / Chartered Accountant**, is seeded with `ca_module_view`
and `inr_actual_view` only — a view-only role for an external or in-house
Chartered Accountant, with no order-management access at all. The
**Accounts Executive** role is granted `ca_module_view`,
`inr_actual_view`, and `inr_actual_edit` (not delete) — but, deliberately,
**not** `ca_internal_doc_manage`. **Admin, Managing Director,** and
**Executive Director** get every permission automatically, same as
everywhere else in the system, so in practice `ca_internal_doc_manage`
starts out held only by them (and Super Admin) — anyone else needs an
explicit grant. As with any permission, these can be granted individually
to any other user (e.g. a Director who isn't normally in Accounts) via
**Admin → Settings → Roles & Permissions** or a per-user override — see
[Admin & Settings](./15-admin-settings.md).

## Recording an INR actual amount

This happens on the **order's own page**, not in the CA module itself —
right where the advance/balance/freight payment is marked cleared, since
that's the moment the real bank-credited amount is known.

1. Open the order and scroll to **Payment Status**.
2. Once a leg (Advance / Balance / Freight) shows as cleared, a new box
   titled **"[Leg] — INR Actual (CA/Accounting)"** appears underneath it.
   - If nothing has been recorded yet, and you hold the "Add/edit INR
     actual" permission, you'll see a field to enter the amount actually
     credited to the bank, in INR, and a **Record INR Actual** button.
   - If you don't hold that permission, the box just says it hasn't been
     recorded yet and who can record it — you don't need to go looking for
     it elsewhere.
3. Once recorded, the box shows the amount, the date/time it was recorded,
   and who recorded it. Anyone with the "View INR actual" permission (which
   includes a CA-role user) can see this, even though they can't edit it.
4. If a mis-entry needs correcting, a user with the "Delete INR actual"
   permission sees a **Remove** button next to the recorded amount — this
   clears it back to "not yet recorded" so it can be re-entered correctly.
   This is a deliberately separate permission from edit, since removing a
   figure is a correction, not routine data entry.

Every record and delete action here is written to the system's audit log
(**Insights → Audit Log**, if you hold that permission), same as any other
sensitive field in the system.

## Government export benefits & linked expenses (also on the order's own page)

Just below the Payment Status section, anyone with `ca_module_view` also
sees a **Government Export Benefits & Expenses (CA)** box — any RODTEP/
export-benefit claim or expense (ECGC insurance, third-party inspection,
CHA, transport, ...) linked to this specific order, so opening one order
shows every CA financial detail tied to it at a glance. This box is
read-only; recording a claim, marking one received, or linking/unlinking
an expense is still done from the CA module itself — see
[Government Export Benefits](./ca-07-export-benefits.md) and
[Expenses](./ca-04-expenses.md#linking-an-expense-to-the-order-it-belongs-to).

## The internal-only CA Financial Annexure — and why it can never reach a client

Government export benefits and expenses are genuine business figures, but
they must **never** appear on a document a client/buyer ever sees — not
by accident, and not even if someone deliberately tries to make it
happen. Rather than add a checkbox to an existing buyer document (a risk
that a future change, a bug, or a mis-click could quietly undo), this is
built as three separate, structural guarantees:

1. **Off by default, and behind its own permission.** At the bottom of
   the same Government Export Benefits & Expenses (CA) box, someone
   holding `ca_internal_doc_manage` sees a checkbox — *"Enable internal
   financial annexure for this order"* — unchecked by default for every
   order. Only once it's checked does a **Generate Internal Financial
   Annexure (PDF)** button appear. Anyone else (including a CA-role user
   with `ca_module_view`) sees a plain read-only line stating whether
   it's currently enabled, with no way to change it.
2. **A different document type entirely.** The generated PDF (document
   type code `CAFIN`) is not a variant of any buyer-facing document — it
   is its own internal-only document type, using the exact same
   mechanism this system already relies on to keep the Supplier PO, BL
   Instruction Sheet, and Payment Terms Amendment out of the client
   portal: `document_types.category = 'internal'`. The client portal's
   document list only ever queries `category = 'customer_facing'`, so
   CAFIN is excluded by construction — not by a setting that could be
   turned off, and not dependent on the document's approval status.
   Visually it's deliberately plain and different too: a heavy red
   "INTERNAL USE ONLY — NOT FOR CLIENT / BUYER" banner top and bottom, no
   shared header/logo/signature-block styling with any buyer document, so
   it could never be mistaken for one even printed on paper.
3. **Viewing it needs `ca_module_view`, specifically — even on the
   generic download link.** Once generated, anyone with `ca_module_view`
   can open it from the same box (it shows the latest one generated,
   with its reference and date) — the same permission that already lets
   them see this data on-screen, so this adds no new exposure. But the
   system's one generic "download any document" link is normally gated
   only by the much broader "Download PDF" permission most staff hold —
   for this one document type specifically, that link additionally
   refuses anyone who doesn't hold `ca_module_view`, so a staff member
   without any CA access can't fetch it just by knowing or guessing its
   document ID. It's also deliberately left out of the "download
   everything for this order" dossier ZIP staff use for handovers/
   archiving, for the same reason — that ZIP only requires the broad
   "manage orders" permission.

Staff and admins can always open both what a client actually receives
(any of the ordinary generated documents) and this internal one side by
side, to check exactly what each contains — nothing here blocks staff
access, it only makes sure the two document sets can never cross paths on
the client's side.

## The INR Settlement Register

**CA / Accounting** in the sidebar (visible only with `ca_module_view`)
opens the module's first screen: a single table listing every
advance/balance/freight leg that has been marked cleared anywhere in the
order pipeline, one row per leg, with:

- the order reference and client (linking straight back to that order),
- the foreign-currency amount and currency,
- the date it was cleared,
- the INR actual amount, if recorded — or a plain "Not yet recorded" note,
  so anything still outstanding is easy to spot at a glance,
- when it was recorded and by whom.

This is read-only in Phase 1 — it exists so a CA (or anyone else with the
view permission) can see every settlement's INR status in one place
without opening each order individually. Recording or correcting a value
is still done from the order page, as above.
