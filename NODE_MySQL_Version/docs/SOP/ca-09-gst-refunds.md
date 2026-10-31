# CA-09 — GST refunds under LUT

Owner decision 2026-10-31 (option A, confirmed).

## Why this page exists

NexaCrest exports under **LUT** (Letter of Undertaking) and sells nothing in India.

- **The GST is a temporary outflow, not a cost.** The GST paid on purchases (supplier bills, CHA, transport) is not used against any GST on sales, so it comes back as a **refund**.
- **The refund is claimed for a whole period** (a month or a quarter) on the GST portal with form **RFD-01**. It is not claimed order by order: the refund for the period is worked out with the government's formula.
- **It is paid into the bank** account registered on the GST portal. Usually most of it comes first as a provisional amount, and the balance follows after the officer's check. The bank statement shows the payment with its **RFD-05** / UTR reference.

## What the order shows

**The order's cost keeps its GST.** The money left the bank when the bill was paid, and the refund is not per order. So the profit, the margin and the team incentive are worked out with the GST included in the cost.

**One information line.** Step 12c's Profitability Summary shows:

> *For information — GST paid on this order's purchases: ₹57,600.00 (supplier ₹54,000.00 · logistics ₹3,600.00). Refundable under LUT: claimed for the whole period in CA → GST refunds (LUT), not per order, so it is not added to this order's profit.*

The figure is the GST on the order's **tax invoices**. A proforma is left out, because its tax invoice replaces it.

## CA → GST refunds (LUT)

Anyone with the CA module can view the page. Recording on it needs the same permission as recording export benefits: Accounts Executive, Admin, MD or ED.

### 1. Record a claim when it is filed

Enter:
- **Period from / to**: the months the claim covers.
- **RFD-01 claim no. (ARN)**: as on the GST portal. Each claim number can be recorded only once.
- **Filed on**: the date it was filed.
- **Amount claimed**: in rupees.

The claims table also shows **GST on bills in the period**, which is the GST on every tax invoice dated in those months, as a check against the amount claimed.

### 2. Record the money when the bank credits it

Open the claim's **Record money credited to the bank** and enter:
- **Amount credited**: the amount the bank actually received.
- **Credited on**: the date on the bank statement. It cannot be before the claim was filed, or in the future.
- **Bank reference (RFD-05 / UTR)**: required (rule D-212).
- **Part**: provisional, or final / balance.
- **Amount refused, if any**: anything the officer did not allow.

A claim cannot receive more than it claimed: credited plus refused is never more than what is still open. When nothing is left open, the claim shows **Closed**.

### 3. What goes to Zoho Books

**The sync turns each credit into one journal.** At the next sync (hourly, or **Sync Now** on CA → Zoho Books sync), each credit becomes one journal:

- **Debit:** the bank account (the deposit account the other payments use).
- **Credit:** **GST Refund Receivable**.
- **Description:** built by the app from the facts (rule D-212), for example:
  *GST refund under LUT, Oct–Dec 2026, claim AA2701260012345 · provisional · ₹185,400.00 credited 12 Feb 2027 · bank ref RFD05-AA270226004411*
- **Reference:** `GSTREF-<claim no.>-<credit no.>`.

**Each credit is sent once.** Credits dated before the Zoho go-live date are not sent; the CA books them as the opening position.

**The CA's part in Zoho Books:**
- When a claim is **filed**, move the claimed input credit from the input GST accounts into **GST Refund Receivable**. The app's journals then take the refund out of that account.
- An amount **refused** is a real cost. Book it in Zoho Books by hand, from GST Refund Receivable to an expense.

### 4. Correcting a mistake

**Before it reaches Zoho Books,** a credit can be removed with **Remove** under it, then recorded again with the right figures.

**Once it is in Zoho Books,** the app refuses to change it; correct it in Zoho Books.

Every claim, credit and removal is written to the audit log.

## Company figures

The **Revenue Report** (CA → Revenue Reports) shows **GST Refunds Credited (LUT)** for the period, as company-level income.
