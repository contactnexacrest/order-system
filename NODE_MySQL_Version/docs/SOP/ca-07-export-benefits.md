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
