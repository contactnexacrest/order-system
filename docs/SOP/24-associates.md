# Associates

Owner decision 1 Nov 2026 ("AS all as recommended", register D-217).

## What this is

Associates are outside people, in India or abroad, who introduce buyers to
NexaCrest under a signed Associate Agreement. They are **not staff**: they
have their own **Associate Portal** (`/associate`), with its own login, and
never see the staff app. In the portal an associate sees only their own
clients and those clients' orders.

| The associate sees | The associate never sees |
|---|---|
| Their clients: registered, protected until, first order, the 10-day window | Unit prices, discounts, costs, profit, margin |
| Each order: reference, products (description only), product FOB value, stage in plain words (Enquiry & quotation · Order confirmed · In production · Shipping · Shipped) | Suppliers, purchase prices, freight |
| Whether the buyer has paid in full — yes / no, no amounts | CAFIN, the annexure, any PI / CI / BL or other document |
| Their commission per order: rate (personal or standard), amount, and state (waiting for full payment · earned · on hold · paid with date, TDS and bank reference); repeat orders marked "no commission" | The buyer's payment amounts and bank references |
| Their statement (CSV and printable), Form 16A / 15CA / 15CB uploaded by NexaCrest, their agreement, profile, bank details masked (••••1234) | Other associates and their clients, staff notes, team incentives |

## Staff permissions

| Permission | Who has it | What it allows |
|---|---|---|
| Manage associates (`manage_associates`) | MD, ED, Admin | The register, rates, status, portal access, personal codes, linking a client already on file, documents, messages, holds and claw-backs, standard rates, the report |
| View associates (`view_associates`) | Export Executive, Sales/Export Manager, Sales Director (and MD, ED, Admin) | Who introduced which client — no rates, amounts, bank details or agreements |
| Associate bank & tax details (`manage_associate_bank`) | Finance Manager, Finance Director, MD, Admin | Bank details: enter, first check, verify, reject, see the full number (every view is in the audit log) |
| Pay incentives (`pay_incentives`) | Accounts | Pay due commission on Incentive Payouts (chapter 20) |

Only the **Managing Director** (or a Super Admin) can remove a client's
associate or move the client to another associate, always with a written
reason that the associate(s) are shown.

The associate's line appears at the top of the client page and on the order
page ("Associate client — introduced by …, protected until …") for everyone
with View or Manage associates.

## The register (Associates)

**Associates** (left menu, Operations) lists every associate with status
chips (All · Invited · Active · Suspended · Agreement ended) and a search box.
A Kanban user sees the same as a board with one column per status; both have
the same links, filters and actions, and switch to each other.

### Adding an associate

1. **Add an associate**: name, India / abroad, country, currency, the e-mail
   (their portal login — one associate per e-mail), phone, PAN / GSTIN or
   foreign tax ID, address, relationship manager, agreement dates and the
   signed agreement (PDF, with its signing date).
2. **Commission.** Enter a rate only for an agreed personal rate. A rung
   left **empty** (or 0) carries on the **last personal rate set below it**;
   rungs below the first personal rate use the **standard rate** (owner
   decision D-221). Example: 1–5 = 1.25 % and 6–10 = 1.5 % gives 1.5 % from
   the 11th client on; only 6–10 = 1.5 % gives the standard 0.7 % for
   clients 1–5 and 1.5 % from the 6th; nothing filled in gives the standard
   ladder. Tick **No commission** for an associate who introduces without
   commission.
3. The associate starts as **Invited**. Upload their KYC (PAN card, ID,
   address proof, GST certificate; abroad: passport / company registration,
   tax residency certificate, Form 10F, no-PE declaration) under
   **Documents**, or the associate uploads it in the portal.
4. Bank details are checked by two people (below). Only then can the
   associate be set **Active** (Change status, with a reason).
5. **Send portal access**: the associate gets an e-mail with a link to set
   their password (valid 72 hours) and their personal code is made.

### Status

**Change status** needs a reason, which the associate is shown.
- **Suspended**: every commission waiting to be paid is put **on hold**,
  and new commission is earned on hold; nothing is paid until the MD
  releases each one (**Release** on the associate's page).
- **Agreement ended**: the portal closes. Commission already earned is
  still paid; no new clients.

### Standard rates (Admin)

**Associates → Standard rates**: the standard ladder by the associate's Nth
client (default 0.70 / 1.00 / 1.50 / 1.75 / 2.00 % for clients 1–5 / 6–10 /
11–20 / 21–40 / 41+), the TDS % for India (section 194H, default 2 %, the CA
confirms), the protection period (default 12 months) and the waiting days
before a new bank account is used (default 7).

**The rate is fixed on the order when the order is created** (the order shows
the rate and whether it is personal or standard). Changing a personal or
standard rate applies to orders created afterwards.

## How a buyer becomes an associate's client

The buyer always registers **themselves**, with their own company's
details, on the quotation form — the associate never types the buyer's
details. Two ways, in any mix:

- **One-off link**: in the portal, **My clients → A link for one buyer**
  (company name and country). The link `…/invite/<token>` is valid for 30
  days and for one registration. The associate can cancel it.
- **Personal code**: every associate has one code, e.g. `NXA-RAJESH-7K`,
  for all their buyers; the link `…/quotation-details?code=NXA-RAJESH-7K`
  carries it. The last letter is
  a check letter: a mistyped code is refused, never given to someone else.
  0/O and 1/I count as the same, case does not matter (the buyer never
  types it: it comes with the link). A code belongs to one
  associate for ever; when a new code is set, the old one no longer brings
  new clients (clients already registered stay theirs).

Then:

1. The buyer fills in the form. The associate's link or code is picked up
   automatically and carried as a **hidden field**: the buyer sees no
   checkbox, no associate name and no code box (owner decision 1 Nov 2026).
   A buyer who opens the plain form without a link or code is not linked to
   any associate.
2. The buyer is e-mailed a **6-digit code** (valid 30 minutes) and enters it
   on the next page to confirm the request.
3. The associate sees the invitation move: Link created → Opened →
   Registered → Accepted — your client / Not accepted / Expired.
4. On **Quotation Intake** (list or board) staff see "Associate: <name> —
   buyer’s e-mail verified" and any warnings: the buyer used the associate's
   own e-mail or phone, a free-mail address, an e-mail domain not matching
   the website, the same company as another associate's client, or the
   e-mail not yet verified.
5. **Accept** creates the client and links it to the associate: the
   **protection** starts (12 months) and the associate is told, with a
   printable **registration certificate** in the portal.
   - Not verified yet: Accept is refused until the buyer has entered the code.
   - **First completed registration wins**: if another associate's
     registration of the same company was completed (code entered) earlier
     and is still pending, decide that one first.
6. **Reject** tells the associate, with the reason.

**A direct approach is caught.** A company is recognised by its legal name,
VAT / tax number, e-mail domain (not free-mail) or phone. If an associate's
client sends a new enquiry without the code, the associate is notified and
staff see "Same company as …, already the client of associate …".

Reminders are e-mailed 30 and 7 days before the protection of a client that
has not ordered yet ends, and before an agreement ends (daily job, chapter
15 — the same cron as the daily digest).

A client already on file can be linked by Manage associates (**Link a client
already on file**, with how they introduced it). After that, only the MD can
change it.

## Commission

- Earned on the client's first orders — everything ordered in the **10 days
  from the client's first PO received** (chapter 20) — at the rate fixed on
  each order, on the **product FOB value** (services excluded).
- Earned only when the **buyer has paid the order in full** — never on the
  advance alone. It is then paid in the **next incentive month** against the
  associate's **invoice** (uploaded per payout in the portal).
- **Short pay**: pro rata on the share of the order value received (e.g.
  90 % received → 90 % of the commission); the order's Commission cost line
  says "— pro rata 90% of the order value received".
- **Cancelled** or not-proceeding orders: no commission.
- **No commission** associates: none, the portal says so.
- **Refund after payment — claw-back**: on the associate's page, under
  Payouts, **Claw back** an amount (at most what was paid) with what was
  refunded. It becomes a negative line in the current incentive month and
  is deducted from the associate's next payment (the line is marked "Deducted
  from payout no. …").
- Amounts are worked out in the **order currency** and paid in rupees at
  the rate actually received.

## Paying (Accounts)

On **Incentive Payouts** (chapter 20), an associate's line can be paid only
into a **verified bank account past its waiting days**; otherwise it is
refused ("This associate has no verified bank account usable on …") and
**Mark all paid** skips it and says how many were skipped. Record the amount
paid, the TDS (India: 194H; abroad: Form 15CA/CB — upload them under
Documents), "bank transfer" and the **UTR**. The associate is e-mailed the
amount, TDS and bank reference.

**Zoho Books** (owner decision AS-12): when an order's commission is fully
paid, the next sync sends it as the **associate's own bill** (the associate
a vendor; the bill numbered with their invoice, account Commission Expense,
the gross commission) and a **vendor payment** from the bank with the UTR,
applied to it. Wording (rule D-212):
- Bill: "Associate commission — <associate> — for order <ref> (<buyer>),
  0.7% of product FOB, client no. 1 · associate invoice <no.> · TDS 56.00
  deducted"
- Payment: "Associate commission payment — <associate> — for order <ref> ·
  against bill <no.> · TDS 56.00 deducted · bank ref <UTR>"

The TDS deducted stays open on the bill; the CA books it against TDS
payable. A claw-back is not sent to Zoho automatically: the CA books the
recovery (vendor credit) from the associate's statement.

## Bank details (two people, a wait, an e-mail)

- The associate adds or changes bank details in the portal (account number
  twice, IFSC or SWIFT, bank address abroad, a cancelled cheque or bank
  letter). Accounts can also enter them from the associate's signed form.
- A change is a **request**: payments keep going to the verified account.
- **First check** (Finance): how it was checked — call-back on the known
  number, ₹1 test credit. **Verify** must be a **different person** (and
  not the one who entered it). Then the account is used after the waiting
  days (default 7). The associate is e-mailed at each step. **Reject**
  with a reason.
- The number is stored encrypted and shown ••••1234; **Show full number**
  is Finance / MD only and recorded in the audit log.

## The associate's page (staff)

Profile and agreement · Change status · Portal and personal code (send /
resend access, switch off, set a new code) · **View their portal
(read-only)** — staff see exactly what the associate sees; nothing can be
submitted · Clients introduced · Invitation links · Orders and commission ·
Payouts (hold with reason, release, claw back) · Bank details · Documents ·
Messages (the associate writes to their relationship manager; replies are
e-mailed) · History (nobody can edit it).

## Report

**Associates → Report** (`/reports/associates`), per financial year:
performance per associate (clients, orders, FOB value, paid, TDS, waiting),
commission waiting (ageing, with holds) and the **TDS register** (paid on,
PAN, order, paid, TDS, bank reference); each as CSV.

## The associate portal (what the associate does)

1. Open the e-mailed link, set a password (10+ characters, letters and
   digits; enter it twice).
2. Log in at `/associate/login` with e-mail and password, then the 6-digit
   code e-mailed each time. Five wrong passwords lock the login for 15
   minutes.
3. **Home**: totals (earned this year, waiting, paid this year, on hold),
   latest orders and notices.
4. **My clients**: create one-off links, see and copy the personal code
   (the readable part can be chosen once), registered clients with their
   protection and certificate, invitations and their status.
5. **Orders**, **Commission** (statement, invoice upload per payout, tax
   certificates), **Messages**, **Profile** (contact and tax details,
   bank details, documents, agreement download).
