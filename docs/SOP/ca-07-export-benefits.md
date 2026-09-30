# CA / Accounting — Government Export Benefits

## What this chapter covers

This builds on [CA / Accounting — Overview & Permissions](./ca-01-overview.md)
and [Expenses](./ca-04-expenses.md) (read those first if you haven't).
Phase 8 adds a place to record government export incentive claims — RODTEP
and any other scheme — which is money the government owes **to** the
company, the opposite direction from an expense.

## Why this is separate from Expenses

Expenses (Chapter 4) are imported one-way from Zoho Books — the company
paying someone else. RODTEP, Duty Drawback, RoSCTL and similar schemes are
the reverse: a claim filed against a shipping bill through ICEGATE/DGFT,
which the government pays out (often at a different amount than claimed,
after their own scrutiny). Zoho Books has no equivalent record for this,
so it's entered directly here instead of through a sync.

## Recording a claim

A claim can be recorded from either of two places, both writing to the
same table: the CA module's own screen below, or directly from the
**Order Financials** box on the order's own page (see
[Order Financials](./ca-08-order-financials.md)) if you already have that
order open and hold `manage_order_financials` — useful when you're
already looking at the order and don't want to go hunting for its
reference number. This chapter covers the CA-module screen.

**CA / Accounting → Government Export Benefits → Record a Claim.** Choose
the scheme (RODTEP is the default; Admin can add more schemes under
Settings → Dropdown Options → `export_benefit_scheme`), optionally the
order it relates to (type the order reference — a claim doesn't have to
be tied to one order), the shipping bill/scroll number if you have it,
the amount claimed, the currency, and the claim date.

## Marking a claim received

Once the government pays out, find the claim in the list and enter the
**received amount** and **received date**. This is intentionally a
separate field from the claimed amount, since DGFT/Customs frequently
pays a different (usually lower) figure after their own review — the
system keeps both on record rather than assuming they always match.

## Where a linked claim shows up

A claim tied to an order (via the order-reference field when recording
it) shows up in two places: the order reference is a clickable link right
here in the Claims table, and the claim also appears on that order's own
detail page under **Order Financials — Government Benefits, Costs &
Profitability** — alongside any expense (see
[Expenses](./ca-04-expenses.md#linking-an-expense-to-the-order-it-belongs-to)),
Order Cost Entry, and the order's own Profitability Sheet — see
[Order Financials](./ca-08-order-financials.md). That box is gated on the
stricter `manage_order_financials` permission (Admin/MD/ED and Super
Admin by default) — **not** `ca_module_view` — so a CA-role or Accounts
Executive user who can see and record claims from this screen may not see
that same claim on the order's own page unless also granted
`manage_order_financials`.

## Totals

The page shows claimed, received, and outstanding totals across every
claim, so it's a quick way to see how much export-incentive money is
still pending.

## Financial year lock

Recording a claim or marking one received is a financial-year-locked
action, same as every other CA write path (see
[Financial Year Lock](./ca-06-fy-lock.md)) — the date used is the claim's
own claimed-at or received-at date, never today's date, so a backdated
entry into an already-locked year is refused unless the
`ca_fy_lock_override` permission is used (logged to the audit trail).

## What this doesn't do yet

- No automatic pull from ICEGATE/DGFT — every claim is entered manually.
- No push to Zoho Books — this data stays local to this system, since
  Zoho Books has no natural home for it either.
