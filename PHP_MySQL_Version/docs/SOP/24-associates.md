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
| Each order: reference, products (description only), product FOB value, stage in plain words (Quotation · Order confirmed · In production · Shipping · Shipped; Cancelled / Did not go ahead) — the same words the buyer sees in the client portal (D-330) | Suppliers, purchase prices, freight |
| Whether the buyer has paid in full — yes / no, no amounts | CAFIN, the annexure, any PI / CI / BL or other document |
| Their commission per order: rate (personal or standard), amount, and state (waiting for full payment · earned · on hold · paid with date, TDS and bank reference); repeat orders marked "no commission" | The buyer's payment amounts and bank references |
| Their statement (CSV and printable), Form 16A / 15CA / 15CB uploaded by NexaCrest, their agreement, profile, bank details masked (••••1234) | Other associates and their clients, staff notes, team incentives |

## Staff permissions

| Permission | Who has it | What it allows |
|---|---|---|
| Manage associates (`manage_associates`) | MD, ED, Admin | The register, rates, status, portal access, personal codes, linking a client already on file, documents, messages, holds and take-backs, TDS and bank wait (commission settings: MD only, `manage_pricing`), the report |
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
   foreign tax ID, address, **Reports to** (the staff member the associate
   reports to — for visibility only; it changes no commission), agreement dates and the
   signed agreement (PDF, with its signing date).
2. **Commission** (owner decision D-245 P3). Choose **Ladder** (the
   level follows the associate's counting orders) or **Fixed level** (one
   of the levels in Associates › Commission › Commission levels, e.g. 5 %). There
   are no per-associate rates any more. Tick **No commission** for an
   associate who introduces without commission.
3. The associate starts as **Invited**. Upload their KYC (PAN card, ID,
   address proof, GST certificate; abroad: passport / company registration,
   tax residency certificate, Form 10F, no-PE declaration) under
   **Documents**, or the associate uploads it in the portal.
4. Bank details are confirmed by the associate in the portal and checked by one staff member who did not enter them (below; two people if Associates › Commission settings says so). Only then can the
   associate be set **Active** (Change status, with a reason).
5. **Send portal access**: the associate gets an e-mail with a link to set
   their password (valid 72 hours) and their personal code is made.

### Status

**Change status** needs a reason, which the associate is shown.
- **Suspended**: every commission waiting to be paid is put **on hold**,
  and new commission is earned on hold; nothing is paid until the MD
  releases each one (**Release** on the associate's page). The associate
  can still log in and see their clients, orders and commission, but the
  portal is **read-only** (QA Round 8, Q-R8-2): every page shows "Your
  account is suspended — you can see your clients, orders and commission,
  but cannot invite clients or change your details." Creating or
  cancelling a link, choosing the code, saving the profile, uploading a
  document and changing bank details are refused with "Your account is
  suspended — you cannot invite clients or change your details. Please
  contact your NexaCrest relationship manager." **Messages** to NexaCrest
  and the **invoice upload** for a payout stay open, so they can still ask
  a question and be paid what was already earned.
- **Agreement ended**: the portal closes. Commission already earned is
  still paid; no new clients.

### Commission settings (Admin)

**Associates › Commission › Commission settings and ladder** (`/associates/ladder`). The MD
(permission `manage_pricing`) sets, and every change is in the audit log:
- **Days after full payment** before commission is due (default 30).
- **Payment run** (D-292, 11 Nov 2026): monthly on the 1st–28th or the
  **Last day of the month**, or weekly on a weekday (default: the last day
  of the month). **If the payment day is a Sunday or holiday**: pay on the
  working day before (default) or after — the **Holiday Calendar** is used.
  The portal and e-mails always show the actual date (e.g. "31 Dec 2026").
  Commission due on or before a run date is paid in that run. Changing the
  run, the day or the Sunday/holiday rule needs a **reason** (Audit Log).
- **Hold while a buyer claim is open** (default on): a claim raised on or
  before the due date holds the payout until it is settled.
- **Counting order threshold** (default ₹5,00,000 of invoiced FOB).
- **Buyer registration valid** for N months with no order (default 6).
- **GST on commission** % (default 18 %), added for GST-registered
  associates once their tax invoice is on file.
- **Commission period shown to associates** (D-263, 6 Nov 2026): 3, 6, 9,
  12, 15, 18 or 24 months (default 12). The associate's portal shows the
  commission of that many months — the totals ("Earned in the last 12
  months", "Paid in the last 12 months") and the commission list, the CSV and
  the printable statement. Commission still waiting to be paid or on hold is
  always shown, however old. Staff screens always show everything.
- **Zoho GST tax for commission** (D-256, 5 Nov 2026): the Zoho Books tax
  id put on the associate's commission bill when GST is paid on top (find
  it in Zoho Books → Settings → Taxes; e.g. `460000000067890`). Empty is
  allowed, but then a commission with GST is not sent to Zoho until it is
  chosen — see [CA-03](./ca-03-zoho-sync.md).

Every setting on this page has a line beneath it with a worked example
(D-256), e.g. "Example: 30 → buyer pays in full on 4 Nov, commission due on 4 Dec."

The MD also sets (11 Nov 2026) **Personal code changes allowed per year**
(default 3) and **Suggest "Quoting" access after this many paid orders**
(default 3).

Staff with Manage associates set the TDS % (section 194H, default 2 %) and
**Bank details (associates and suppliers)** (D-293): **Bank details check** —
One person (default; not the one who entered them) or Two people; the wait
before a **first** bank account is used (default 0 days) and before a
**changed** account is used (default 15 days), each 0–90. The other fields
are shown to them read-only. The levels themselves and the standard ladder
are in **Associates › Commission › Commission levels**.

### Commission rule per associate (owner decisions 11 Nov 2026, D-291 / D-292)

Every associate starts on the **Standard ladder**. On the associate's page,
section **Commission**, the MD (Manage pricing) can:
- **+ Add own ladder for this associate** — opens a copy of the standard
  ladder to change (steps from order 1, no gaps, last step "onward"). It
  overrides the standard ladder for this associate. **Edit own ladder** /
  **Remove own ladder — use the standard ladder** later.
- **Set a fixed %** — the same % (a commission level) on every order.
A **reason** is needed (kept in the Audit Log). A change applies to **new
orders only**: an order keeps the % fixed on it when its Proforma Invoice
was issued.

**Who sees what.** Staff with **See associate commission rules** (Admin, MD,
ED, Finance Director, Finance Manager) see the rule (who set it, when, why),
"Next order earns 5% — Level 2", "Orders counted so far 3 of 5 — 2 more
counting orders to reach 7%", the step boxes and the counting rule ("An
order counts once the buyer has paid in full and its invoiced FOB value is
₹5,00,000 or more"). Other staff see **"Set by the MD."** The associate sees
**only their current %** — "5% of the FOB value of each order from the
clients you introduced" — with **How it works** (1. fixed when we send the
Proforma Invoice; 2. worked out on the FOB value on the Commercial Invoice;
3. yours when the buyer has paid in full; 4. paid N days later in our
monthly payment on the last day of the month), the % on each order, and
these statuses: "Not earned yet — buyer still paying" / "Earned — will be
paid on <date>" / "Paid on <date> (ref)" / "On hold — a buyer claim on this
order is open". Never a level name, the ladder, counts or the next step.

**The level is fixed on the order when its Proforma Invoice is issued**
(the order shows "Level 2 — 5%" and "fixed at PI on …"). Until then the
order shows the level the associate would get today. Staff see the level
name; the associate sees only the rate ("5%"), and only when their access
level shows the commission % (D-263).
If the MD later moves the client to another associate, the level fixed at
the PI stays with the associate it was fixed for: the new associate earns on
the order at their own level, the same level the order's price check uses.
A fixed USD amount per unit is converted at the pricing exchange rate when
the buyer's order is in another currency.

## How a buyer becomes an associate's client

The buyer always registers **themselves**, with their own company's
details, on the quotation form — the associate never types the buyer's
details. Two ways, in any mix:

- **Buyer registration link** (one buyer): in the portal, **My clients → Buyer registration link — for one buyer**
  (company name and country). The link `…/invite/<token>` is valid for 30
  days and for **one registration**: the first registration sent through it
  takes it, and anyone opening or sending it again is told "This invitation
  link has expired or was already used. Ask the person who sent it for a
  new one, or send your request without it." (QA Round 8, R8-N04). Accept
  links the associate only for the registration recorded on the link. If
  the buyer registered under a different company name from the one the
  link was made for, staff see "The associate made this link for …, but the
  buyer registered as …" on Quotation Intake. The associate can cancel an
  unused link.
- **Personal code**: every associate has one code, e.g. `NXA-ASSOCIATE-7K`,
  for all their buyers; the link `…/quotation-details?code=NXA-ASSOCIATE-7K`
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
   **protection** starts (default 6 months; it lapses if the buyer places no order in that time) and the associate is told, with a
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

Owner decision D-245 P3 (4 Nov 2026) replaces the client numbering
(R8-N05), the per-associate rung rates and pro rata (AS-9).

- **Which orders**: the client's first orders — everything ordered in the
  **10 days from the client's first PO received** (chapter 20). After that
  only the MD can force "associate: yes" on an order, with a written reason
  (10–300 characters); it is recorded in the audit log.
- **Basis**: the **final invoiced FOB of the goods**, line by line
  (services and charges excluded). A line the MD approved **below the
  floor** earns **0**. Only an **agreed discount** or a **settled buyer
  claim** lowers the basis; bank charges never do.
- **Level**: the level fixed on the order at the PI. On the ladder the step
  is set by the associate's **counting orders**: fully paid orders whose
  invoiced FOB on in-range lines is at least the threshold (default
  ₹5 lakh). An order below the threshold earns the current step but does
  not count towards the next.
- **Full payment only**: earned only when the buyer has paid the order in
  full. A shortfall cleared as "not paid by the buyer" means **no
  commission**; there is no pro rata. When a manager clears a short payment
  (chapter 8) they must say what the short is: an agreed discount, a
  settled buyer claim, or not paid by the buyer.
- **Rupees**: worked out in the order currency and converted at the rate
  actually realised on the order's receipts (bank charges added back).
- **When**: due the set number of days after full payment, then paid in the
  next payout run against the associate's **invoice** (uploaded per payout
  in the portal). Before the due date, or while a buyer claim is open, the
  payout cannot be marked paid ("Not due until …" / "On hold: a buyer claim
  is open").
- **GST**: added on top for a GST-registered associate (GSTIN on file) once
  their tax invoice is uploaded. TDS is worked out on the commission before
  GST, as before.
- **Registration lapses**: a buyer registered by an associate with no
  order within the set months (default 6) is unlinked by the daily job and
  the associate is told.
- **Cancelled** or not-proceeding orders: no commission.
- **No commission** associates: none, the portal says so.
- **Take-back after payment**: only for the associate's **fraud** or
  **breach of the agreement**. On the associate's page, under Payouts,
  **Take back** an amount (at most what is left of what was paid), choose
  fraud or breach and write the reason. Two take-backs saved at the same
  moment are checked one after the other (QA Round 8, R8-N12). It becomes a
  negative line deducted from the associate's next payment ("Deducted from
  payout no. …"). A buyer refund alone is not a reason.
- **Statement**: the order page (Incentive card) and the associate's
  portal order page show the commission statement: each goods line, its
  basis, any reduction, the level, the rate realised, the amount, the state
  (estimated / earned / paid), due date and payout run. "Counts toward the
  ladder" shows the counting-order number and the threshold to staff only;
  the associate sees just "yes" or "no" (D-263).
- **Plain words for the associate (D-330, 11 Nov 2026).** The statement's columns and rows read
  **Invoice value of the goods · Price within range? (Yes / Sold below the lowest price (we agreed
  to it) — no commission on this line) · Your commission rate · Agreed discount or settled claim ·
  Commission worked out on · When we pay ("due <date>, paid on <date>") · Counts toward your next
  commission rate** (staff keep "Counts toward the ladder" and the rupee basis). Notices and
  e-mails say the same in short sentences: "We have sent the proforma invoice for order … Your
  commission rate on this order is now final: 5%.", "… stays your client until …", "We have paid
  your commission for order …: INR …, on …, bank reference …. Before tax it was INR …; we deducted
  INR … tax (TDS, Income Tax Act section 194H, 5%).", "We will pay it on <date> into your checked
  bank account". My commission: "We will take this off your next payment" for a deduction, and
  "Before tax ₹… · Tax we deducted (TDS) ₹… (Income Tax Act section 194H, 5%) · Paid to you ₹…".

### Fixed at full payment — never changes (owner decision 7 Nov 2026, D-264)

- **Automatic.** When the buyer has paid the order in full — the last buyer
  receipt cleared **with its rupees credited recorded** (advance, balance,
  freight, milestones, LC) — the app fixes the associate's commission by
  itself: in the order currency and in rupees at the rate of **all** the
  buyer's payments (rupees credited plus bank charges ÷ foreign amount). The
  payout is created at once (due N days after full payment, paid in the
  payout run), the associate gets a notice and an e-mail, and the order's
  profit uses the fixed amount. Nobody has to lock it. If a receipt's rupees
  are recorded later, the daily job fixes it then.
- **It never changes.** A later move in the exchange rate does not raise or
  lower it. If a buyer claim is opened the payout goes **On hold** by itself
  (and back when the claim is resolved). A settled claim or agreed discount
  works it out again **at the same fixed rate**.
- **MD correction.** Order page › Incentive card › **Change commission**
  (MD only, before it is paid): the new amount in the order currency and a
  reason (10–500 characters); rupees at the same fixed rate; audit-logged;
  the associate is told.
- **Team incentive.** Unchanged: the MD locks it on "Ready to lock" (D-245).
  Locking or unlocking the team never touches the fixed commission.
- **What the associate sees.** The order currency (USD) everywhere. An
  associate **in India** also sees, once fixed: "We will pay you ₹X (at
  ₹R per USD, set on <date>; this will not change)". An associate **abroad** never
  sees rupees. The portal explains this in a box at the top of My commission.
- **Paid in.** India → always INR (the field is locked). Abroad → USD, EUR or
  GBP (default USD); INR is refused.
- **Bank charges** are never deducted. Abroad, Commission settings choose
  **OUR** (default: NexaCrest pays its own and the intermediary bank's
  charges) or **SHA**. An associate abroad is paid once the amount due in a
  run reaches the **minimum payout abroad** (default USD 100); smaller amounts
  carry to the next run with a note.

## Paying (Accounts)

On **Incentive Payouts** (chapter 20), an associate's line can be paid only
into a **verified bank account past its waiting days**; otherwise it is
refused ("This associate has no verified bank account usable on …") and
**Mark all paid** skips it and says how many were skipped. Record the date,
"bank transfer" and the **UTR**; the app fills in the TDS and the net amount
(below). The associate is e-mailed the gross, the TDS (section and rate) and
the net paid, with the bank reference.

**What the payment cost (D-264).** For an associate **in India** add our bank
charges if any (optional — paid by NexaCrest, not deducted). For an associate
**abroad** enter the **rupees the bank debited** (required, from the bank
advice) and our + intermediary charges. The difference against the fixed
commission (exchange difference + charges) is shown under **Associate payout
costs** on Incentive Payouts and in its CSV, with its description, e.g.
"Associate commission payout — NC-EXP-0142 — Demo Associate — USD 1,250.00
sent, SWIFT ref FT2612050001; exchange difference ₹812.50 and transfer
charges ₹1,850.00". It is a company cost of the month: the order keeps the
fixed commission. It goes to Zoho Books at the next sync as one journal
(*Associate payout costs* debited, the bank credited; a saving the other way
round), once the MD has entered the Zoho account under Associates › Commission › Commission settings and ladder → *Zoho account for associate payout costs*; until then the payouts
page and the Sync Log say it is waiting. **Mark all paid** skips
payouts to associates abroad — record each with its rupees debited.

### TDS on associate commission (owner decision 2 Nov 2026, D-235)

The app works out the TDS on each associate payment, so Accounts never has
to guess. Every value is in **Settings › Lists › Associate TDS rules** (Manage company
settings; every change needs a reason and is audited) and can be changed on
the CA's advice — payments already recorded keep the TDS they were paid with.

- **Associates in India — section 194H, 2 %.** No TDS while the associate's
  commission in the financial year (1 April – 31 March) stays within the
  threshold (₹20,000). The payment that takes the year past it carries TDS
  on itself **and** on the commission paid earlier that year without TDS
  (the *catch-up*); every later payment that year carries TDS on its full
  amount. A new financial year starts again below the threshold.
  *Example:* ₹12,000 paid in October (no TDS — "below threshold — FY
  2026-27 total ₹12,000.00 of ₹20,000.00"); ₹10,000 due in November → TDS
  2 % of (10,000 + 12,000) = ₹440, net paid ₹9,560 ("194H 2% — includes
  catch-up on ₹12,000.00 paid earlier this FY 2026-27 without TDS");
  ₹5,000 in December → TDS ₹100, net ₹4,900.
- **No PAN, or the PAN card not accepted on the Documents tab → 20 %
  (section 206AA).** TDS is rounded to the nearest rupee.
- **Associates abroad — no TDS** (setting "Deduct TDS for associates abroad"
  off), but only once the **Tax Residency Certificate, Form 10F and the
  no-PE declaration** are on file and **accepted** on the associate's
  Documents tab. Each payment also needs its **Form 15CA** (and **15CB**,
  setting on by default) — attach them in the payment row itself. Without
  them the payment is refused with the list of what is missing, and **Mark
  all paid** skips it.
- **The payment row** shows "Gross ₹… · TDS ₹… (reason) · Net to pay ₹…",
  with the net amount and the TDS filled in. To record a different TDS (e.g.
  a lower-deduction certificate u/s 197 or the CA's advice) give the reason
  (10+ characters); it is kept on the payment ("changed by hand: … (worked
  out: …)") and audited (ASSOCIATE_TDS_OVERRIDDEN). Paid + TDS can never be
  more than the commission.
- **The order's cost** is the gross commission (TDS included).
- **Deposit and returns.** Deposit each month's TDS by the **7th of the next
  month** (challan 281) and enter the challan no. and date in **Associates ›
  TDS register** (`/associates/tds`, by financial-year quarter, CSV). File
  **Form 26Q** after each quarter (by 31 Jul, 31 Oct, 31 Jan, 31 May) and
  upload each associate's **Form 16A** on their Documents tab — they see it
  in the portal under "TDS certificates (Form 16A) and remittance forms".
  The daily alert job reminds holders of *Pay incentives* on the 1st–7th
  (TDS not yet deposited) and from the 20th of the 26Q month.
- The register is open to holders of Manage incentives, Pay incentives or
  the CA module.

**Zoho Books** (owner decision AS-12): when an order's commission is fully
paid, the next sync sends it as the **associate's own bill** (the associate
a vendor; the bill numbered with their invoice, account Commission Expense,
the gross commission) and a **vendor payment** from the bank with the UTR,
applied to it. Wording (rule D-212):
- Bill: "Associate commission — <associate> — for order <ref> (<buyer>),
  2% on the invoiced FOB of the goods (basis USD 4500.00) · associate
  invoice <no.> · TDS 160.00 deducted · GST 1440.00 on the associate's tax invoice (input credit)" (the GST part only for a GST-registered associate)
- Payment (D-235): "Associate commission for <associate> — order <ref> —
  gross ₹25,000.00, TDS u/s 194H ₹500.00 (2%), net paid ₹24,500.00 · UTR
  <UTR> · against bill <no.>"

With a **Zoho Books TDS tax id** in Settings › Lists › Associate TDS rules, the bill
carries the TDS through Zoho's TDS feature and the payment is the net. Left
blank (the default), the bill is the gross, the payment the net, and the
TDS stays open on the bill for the CA to book against TDS payable — the
Sync Log says so ("TDS INR … left on the bill for the CA to book"). Turning
on TDS in Zoho Books is a change the CA makes in Zoho (and logs in the Zoho
change log); then its tax id goes into the setting. A take-back is not sent to Zoho automatically: the CA books the
recovery (vendor credit) from the associate's statement.

## Bank details (the associate confirms, one person verifies, a wait)

Owner decisions 11 Nov 2026 (D-293). Flow: **entered → the associate
confirms → staff verify → wait**.

- The associate adds or changes bank details in the portal (account number
  twice, IFSC or SWIFT, bank address abroad, a cancelled cheque or bank
  letter). Accounts can also enter them from the associate's signed form.
- A change is a **request**: payments keep going to the verified account.
- **The associate confirms first** — even when they asked for it themselves.
  They get the "your bank details changed" e-mail and a bar on every portal
  page ("New bank details are waiting for your confirmation … Review now").
  The confirm page shows the current and the new account side by side; they
  re-type the **last 4 digits of the new account**, click **E-mail me a
  code**, enter the **6-digit code** sent to their **registered e-mail**
  (valid 10 minutes) and tick "These are my bank details and I want my
  commission paid to them" → **Confirm new bank details**.
- **"This is not me — cancel it"** cancels the change at once and alerts
  the MD and Finance (in the app and by e-mail) — treat it as possible
  fraud: find out who entered the details.
- **No answer in 7 days**: the change is cancelled by itself and the old
  account stays. Staff see "Waiting for the associate — ends <date>" and a
  **Remind associate** button (the e-mail again).
- **Verify only after the confirmation.** One person (default setting), who
  is **not** the person who entered the details, ticks **at least two** of
  "How did you check it?": matches the bank proof on file / called the
  associate on their registered phone and they confirmed the last 4 digits /
  test payment of ₹1 / USD 1 received in the right name (with its bank
  reference). A note is optional. With the setting **Two people**: a first
  check (with the ticks), then Verify by a different person.
- **MD and ED may verify details they entered themselves**, with a reason
  (Users & roles › Self-approval rules › Bank details). Everyone else sees "Someone
  else must verify details you entered."
- Then the account is used after the waiting days: a **first** account at
  once (0 days), a **changed** account after 15 days (both settings 0–90).
  Until then payments go to the old account. The associate is e-mailed at
  each step. **Reject** with a reason. The e-mails say where payments go meanwhile (QA Round 8,
  R8-N01): "Payments keep going to your verified account (••••1234)." when
  a verified account is in use, otherwise "No payment can be made until
  new bank details are verified." The reason is shown with one full stop,
  e.g. "Your new bank details (HDFC Bank ••••3333) were not accepted:
  Account holder name does not match the PAN. No payment can be made until
  new bank details are verified."
- The number is stored encrypted and shown ••••1234; **Show full number**
  is Finance / MD only and recorded in the audit log.

## The associate's page (staff)

Profile and agreement · Change status · Portal and personal code (send /
resend access, switch off, set a new code) · **View their portal
(read-only)** — staff see exactly what the associate sees; nothing can be
submitted · Clients introduced · Invitation links · Orders and commission ·
Payouts (due date, payout run, GST; hold with reason, release, take back for fraud or breach) · Bank details · Documents ·
Messages (the associate writes to their relationship manager; replies are
e-mailed) · History (nobody can edit it).

## Report

**Associates › Report** (`/reports/associates`), per financial year:
performance per associate (clients, orders, FOB value, paid, TDS, waiting),
commission waiting (ageing, with holds) and the **TDS register** (paid on,
PAN, order, paid, TDS, bank reference); each as CSV. **By quarter, with
challans →** opens `/associates/tds` (D-235).

## The associate portal (what the associate does)

1. Open the e-mailed link, set a password (10+ characters, letters and
   digits; enter it twice).
2. Log in at `/associate/login` with e-mail and password, then the 6-digit
   code e-mailed each time. Five wrong passwords lock the login for 15
   minutes.
3. **Home**: commission totals — **Earned in the last N months**, **Waiting
   to be paid**, **Paid in the last N months**, **On hold** (N is the
   "Commission period shown to associates" setting, default 12) — latest
   orders and notices. "Earned" counts commission by its incentive month;
   "Paid" counts what was paid out (after TDS) by the payment date; "Waiting"
   and "On hold" are everything still unpaid, whatever its month. The portal
   never shows a count or total of orders or of business (owner rule, D-263).
4. **My clients**: create buyer registration links, see and copy the
   personal code and the quotation link (with the code), change the code
   (D-254, below), registered clients with how they came ("came via"),
   their orders (only when the level shows orders), protection and
   certificate, links and codes used.
5. **Catalogue and price requests** (D-255, 5 Nov 2026 — replaces the P3
   "Your price range" page and its acknowledgement): the catalogue lists the
   products in the categories and forms NexaCrest opened to the associate —
   name, description, forms and finishes, with **no prices, supplier names or
   quarry locations**. **Get price** on a product shows only that product's
   options (grade, thickness, finish, size, edge, quantity, packing, treatment,
   calibration). The answer comes at once: request number **PR-YY-DD-NNNN**,
   the **quote price** and the **lowest price they may agree** at their own
   commission level, in the right unit (per CBM from 50 mm, with an ≈ per-m²
   figure for comparison), what each option did to the price, separate
   charges (edge work per running metre, A-frame per container), and the date
   it is **valid until** (7 days, setting). At most **10 automatic requests a
   day** (setting; the MD can give one associate another limit). Ticking
   **The buyer needs something not listed here** (with a note of at least 10
   characters), an unlisted thickness of 50 mm or more, or a product without
   a supplier price sends the request to our team: priced within **24 hours**,
   not counted toward the daily limit; the associate is told in the portal and
   by e-mail when it is priced. Prices are shown on screen only (no download
   or export) and every request is logged. Cost is never shown.

   Staff side: **Associates › Price requests** (MD, ED — view the price
   build-up or manage pricing) lists every request, manual ones waiting first
   with their due time; **Price it** (MD — manage pricing) enters the quote,
   lowest price, unit and a note. Below it, **Requests and orders — last 90
   days** per associate, with a red badge when 20 or more prices were asked
   and no order came. This page is a work queue (like Registrations), so it
   has no Kanban board. **Associates › an associate › Price access** (MD) sets
   the categories and forms they may price, and their own daily limit and
   validity (empty = the global value). On an order for that associate's
   buyer, staff can type the **price request number** in "Add a product line":
   the line takes the request's product, options, price and unit, the order
   check uses the request's prices, and the request shows "Became an order".
6. **Orders** (with the per-order commission statement), **Commission** (the
   last N months plus anything still unpaid; statement, invoice upload per payout, tax
   certificates), **Messages**, **Profile** (contact and tax details,
   bank details, documents, agreement download).

## A buyer never sees an error from a code or link (owner decision 4 Nov 2026, A6)

When a buyer opens the quotation form with an associate code or link that
cannot be used, the form simply opens as for any direct buyer — no message,
no hint. This covers a mistyped or unknown code, an associate's old code that
NexaCrest **blocked** (an old code that was simply replaced keeps working —
D-254), an expired or already-used link, and an associate
who is suspended or whose agreement has ended. The buyer registers as a
direct buyer.

On **Quotation Intake Review** the request then carries a warning line such
as "Associate code NXA-… given but not applied: it is an old code of associate
… that NexaCrest blocked". Decide as usual: accept as a direct client, or — if the associate should
get the client — link the client to the associate afterwards from the
associate's page (Link a client already on file).

## Names used for the links (owner decision 4 Nov 2026, D-250)

To end the "portal link" confusion the screens, e-mails and messages use:

- **Set-password link** — the associate's own one-use link (72 hours) to set
  the password of their portal (staff button: *Send set-password link*; the
  link is also shown with **Copy**, D-282). The **login page** itself is
  always `/associate/login` (shown with Copy on the associate page); there
  the associate can use **Forgot password?** to get a new link by e-mail.
- **Quotation link (with your code)** — `…/quotation-details?code=NXA-…`, for
  any buyer; the buyer registers with the associate's personal code.
- **Buyer registration link** — `…/invite/<token>`, made by the associate for
  one buyer (one registration, 30 days).
- **Associate registration link** — `…/associate-register/<token>`, made by
  NexaCrest for a person who wants to become an associate (D-253).

## Documents, numbers and KYC (owner decisions 4 Nov 2026, D-251 and D-252)

Every associate document has a **document number / reference**, checked by
kind in the browser and on the server: PAN `ABCDE1234F`; **Aadhaar card —
only the last 4 digits are kept, shown as "XXXX XXXX 1234"; a full Aadhaar
number is refused**; an **ID document**: first choose its type — Passport,
National ID card, Residence permit or Driving licence — then its number,
5–30 letters, digits, dashes (-), slashes (/) or spaces (D-290, 11 Nov
2026); GSTIN 15 characters; anything else up to 50 characters.

The **KYC checklist** on the associate's page (staff) and in the portal
(My profile): India — PAN card, **ID proof** (Aadhaar card, passport or
another ID document), **Address proof**, bank proof (cancelled cheque or
statement), signed agreement, and the GST certificate when they have a GSTIN;
abroad — **ID proof** (passport, national ID card, residence permit or
driving licence), **Address proof**, bank proof, signed agreement.
**ID proof and address proof are separate items, each checked on its own**
(D-290). If one document shows both (e.g. a passport with the current
address), it is uploaded twice — once as "ID document", once as "Address
proof"; the app names the files "ID proof — <name> — <date>" and "Address
proof — <name> — <date>". There is no "use the same file" button.
Associates already accepted on 11 Nov 2026 have **60 days** to add their
address proof; after that their payouts wait until it is accepted (the date
shows on their page and in the portal).
Each item is *Not uploaded yet*, *Pending*, *Accepted* or *Rejected* (with the
reason). Staff with **Manage associates** accept or reject each document
(a reason of at least 10 characters is needed to reject; the associate is
told and uploads it again). Staff are notified of every upload. Every review
is in the audit log.

**Commission cannot be marked paid until every KYC item is accepted** — the
payout shows "The associate's KYC is not complete — still to be accepted: …".
Activation of the associate stays as before.

## Associate self-registration (owner decision 4 Nov 2026, D-253)

1. **Associates › Registrations → Create link** (Manage associates). The
   associate registration link is valid for 7 days (setting on the same
   page) and for one registration. Copy it and send it.
2. The person fills in a short form: name, e-mail (their future login),
   phone, based in India or abroad, country, city, and a consent tick. An
   expired, used or cancelled link shows "This link is no longer open" —
   never an error code. Too many tries from one connection are slowed down.
3. Staff are notified; the registration waits under **Registrations**.
   **Accept** creates the associate (status *Invited*, INR for India, USD
   abroad) and e-mails the set-password link (always sent — a MUST e-mail;
   valid 72 hours, works once; the e-mail also gives the permanent login
   page). The screen says whether the e-mail really went out and shows the
   link with **Copy**, so you can also send it by WhatsApp. **Reject** needs
   a reason (10–300 characters), which is e-mailed to the person (the screen
   says if it was not).
4. After the first login the associate completes the profile (address, PAN,
   GSTIN), bank details and KYC documents; the portal home reminds them
   until everything is in and accepted.

## Changing the personal code (owner decision 4 Nov 2026, D-254)

In the portal, **My clients → Change my code**: type the readable part and
press **⟳ Show another ending** to see a proposed code (press again for another one,
like a captcha refresh), then **Use this code**. Up to 3 changes a year
(setting `associate_code_changes_per_year`).

**Old codes keep working**: a buyer who uses any of the associate's previous
codes is still linked to that associate, and links already shared keep
working. "My codes" lists every code with its dates and status. Clients
show "How they came to us" — the code or link they used.

Staff can set a new code on the associate's page; ticking **Block the old
code** (with a reason, e.g. it leaked) stops that old code from bringing new
clients — such a code then counts as "not applied" (A6).


## TDS never more than the payout (QA batch 4, MON-1 — 5 Nov 2026)

When a payout takes the associate's year past the TDS threshold, the
catch-up TDS on the earlier payments can be larger than the payout itself
(for example 20% without a verified PAN). The app now keeps back at most the
whole payout — the payout can be recorded with **net ₹0, settled by TDS** —
and carries the rest of the TDS to the associate's **next payout in the same
financial year**. The payout's TDS note says how much was carried
("capped at the payout; ₹X TDS carried to the next payout"), and the next
payout's TDS includes it ("includes ₹X TDS carried from an earlier payout").
If Accounts changes the TDS by hand (with a reason), nothing is carried.

## Access levels — what each associate sees (owner decision 6 Nov 2026, D-261)

A new associate does not get the whole portal at once. The MD decides how much each associate sees, and opens more as their track record grows.

**The levels**
- **Starter** — the level every associate added from now on starts on. They see their profile, bank and agreement; their clients; those clients' orders and the stage each is at; their commission per order (the amount only, not the %); registration links and their personal code; messages; TDS certificates. No prices, no catalogue and no quoting.
- **Quoting** — Starter plus the catalogue and **Get price** (only for the categories opened on Price access), price requests ("Other"), the order value (FOB), the commission % and the commission statement download.
- **Full** — the whole portal, including order amounts in notices and e-mails. Every associate who was set up before 6 Nov 2026 is on Full, so nothing changed for them.
- **Custom** — the MD ticks features one by one for this associate only.

**Changing an associate's level (MD only)** — open the associate, go to **Portal access**, choose the level (or Custom and the ticks), give the reason (at least 10 characters) and save. The history under the card shows who changed what, when and why. Other staff see the card greyed out.

**Changing what a level shows (MD only)** — **Associates › Access levels** has one row per feature and a tick for Starter, Quoting and Full. A change applies to every associate on that level at once; an associate on Custom keeps their own ticks. A reason is needed, every change is logged, and **Restore recommended defaults** puts the starting ticks back (the browser asks "Put the recommended defaults back for all three levels?" first; Cancel changes nothing). "Show FOB order value" is off for Starter by default.

**When to open more** — the associate page has a **Track record** panel: clients brought in, orders, order value, and orders completed and fully paid. After **3** completed, fully paid orders (a setting) "Ready for the Quoting level?" shows on the associate page and on the dashboard of everyone with **Manage associates** (D-263). Nothing upgrades by itself: change the level, or click **Not yet** and you are asked again after their next completed, fully paid order.

**What the associate sees when something is hidden** — the menu does not show it, and if they open it anyway (for example an old bookmark) they get "This page is not open for your account" — please contact NexaCrest. The order value and the % are taken out on the server, not just hidden on the screen, and amounts (and the %) are blanked in their notices and e-mails where their level does not show them.

**Never shown to an associate, at any level (owner rule, D-263, 6 Nov 2026)** — a count or total of their orders or of their business; the running number of their counting orders and the ₹ threshold behind the ladder; the name of a commission level or its band (only the rate, and only with "Show commission %"); the FOB in a "commission earned" notice unless "Show FOB order value" is on. The number of clients they brought in is shown.

Cost, margin, supplier prices and CAFIN are never shown to an associate at any level.
