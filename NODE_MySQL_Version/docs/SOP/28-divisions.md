# 28 — Divisions

**Owner decision D-257 (5 Nov 2026).** One company, several divisions. Today there is one: **Export** (code EXP). Domestic Trade (DOM), Merchant Trade (MTT) and Import & Distribution (IMP) are listed as **planned** and cannot be used until the owner activates them.

## What a division is

- **Every order belongs to one division for life.** It is set when the order is created and never changes. A duplicate stays in the source order's division.
- **Every staff member works in one division** and sees only its orders, clients, reports, counts, tasks, files and CSV exports.
- **Company-level people** (Owner/Admin, MD, ED, Finance, Audit, Administration, the CA, and the Investor (view only) role) hold the permission **See all divisions**. They see every division, or one they pick.
- **One client record** can buy from several divisions; there are no duplicates. A client from before divisions belongs to Export.
- **The client portal is unchanged:** a client always sees all their own orders.

## While only one division is active (today)

Nothing looks different. There is **no division switcher, no "All divisions", no Division filter or column on reports and no Division field** on users or departments. Everyone is placed in Export automatically. **Admin › Divisions** (MD and Admin) is the only place divisions appear.

## Admin › Divisions

- The list shows each division, its status and its numbers of orders, staff and departments. Planned divisions are greyed out.
- Open a division to see its **name, code and order number format** (read-only), its **modules** and its **own settings**.
- **Modules** switch whole parts of the system on or off for a division: quotation/PI/OC, supplier purchase and RFQ, export shipping, LUT/IGST and GST refunds, export benefits, FIRC/eBRC/FEMA, ECGC, associates and pricing, team incentive and CAFIN. A module that is off hides its menus and refuses its addresses for that division. **Export has every module on.**
- **The division's own settings** replace a company, pricing or incentive setting only while someone works in that division. Export has none, so it uses today's values.
- **Numbering:** Export keeps today's formats and counters exactly. Another division counts its own numbers, and its numbers start with its code (for example `DOM/NC/SC/ORD/26/0511001`) unless its own `order_ref_format` (or `ref_format:<document code>`) places `{DIV}` itself.

## Setting up and activating a new division (later)

1. In **Admin › Departments**, set the Division of the departments that will work in it.
2. In **Admin › Users**, set the Division of its staff. Company-level people stay "Company level".
3. In **Admin › Divisions › (the division)**: tick its modules, then add its own settings. At least `order_ref_format` and every value that differs from Export (currency, GST, pricing, incentive).
4. Work through **Before activating**: departments, staff, number format, modules and settings must all be ticked.
5. Tick the confirmation box and press **Activate**. From then on, company-level people see the **division switcher** (with "All divisions"), the reports show a **Division filter**, and users and departments show a **Division field**.

A division with no orders can be set back to planned. **Export always stays active.**

## Facts that depend on the CA or the bank

Values such as GST rates by HSN, e-invoice and e-way bill thresholds, and merchanting limits start from **standard Indian rules**. Each is an editable division setting and is **to be confirmed by the CA / bank** before the division goes live.

## Creating an order when several divisions are active

- **Staff:** the order is in their own division.
- **Company-level person who picked a division in the switcher:** the order is in that division.
- **Company-level person on "All divisions":** the new-order form asks **Division \*** (required).
