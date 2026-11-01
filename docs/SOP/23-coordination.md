# 23 — My tasks, order owners and the management cockpit

Nobody has to remember to pass work on. When one department finishes a
step, the next step appears in the next department's **My tasks** list.
When the step is done there, it disappears.

## My tasks

Sidebar: **My Tasks**. It lists what the department you are working as
has to do next, earliest due first:

| Department | Task | Appears when |
|---|---|---|
| Sales & Export | Raise the purchase requirement | the advance is cleared and nothing has been asked of Procurement |
| Procurement | Get supplier quotes and award | a purchase requirement is raised |
| Procurement | Approve the award | an award is above the awarder's approval limit |
| Procurement | Chase the supplier | an approved purchase reaches its needed-by date |
| Logistics | Book freight and CHA | goods are received (quality check not failed) and no freight terms exist |
| Logistics | Send the documents to the buyer | the balance is cleared |
| Accounts & Finance | Clear the buyer's payment | a remittance (advance, freight, balance) is recorded but not cleared |
| Accounts & Finance | Pay the supplier / logistics bill | a bill is entered and not yet paid |

Each task is **due** a set number of days after the step before it; an
overdue task is red. After a further number of days it is **escalated**
and shows in the management cockpit. **Only orders I own** narrows the
list to orders where you are the department's owner. Tasks never show
amounts.

Management, the Order Desk and Audit have no tasks of their own, so they
see every department's list.

## The daily digest

Every morning (08:15 IST) each person gets **one e-mail** listing what waits
on them: the tasks they own or that sit unowned with their department
(escalated and overdue first) and the documents waiting for their review.
Nobody with nothing waiting gets one, and nobody gets two in a day. Review
requests still arrive at once as before; the digest only repeats them
(owner decision 1 Nov 2026).

A digest counts as sent only when the e-mail really left. While e-mail
sending is off (Admin → Settings) the job reports **"Daily digest: e-mail
sending is off — nothing sent."** and nobody's day is used up, so the
digest goes out once sending is switched on. A digest that fails to send
is tried again on the next run (QA Round 8).

## Every order has an assignee

The person who creates an order is its first assignee. An order always
keeps at least one: to hand it over, add the new person first, then remove
the old one (owner decision 1 Nov 2026).

## Order owners

The order page's side panel shows **Owners** — one person per department
(Sales, Procurement, Logistics, Finance). A department's manager or
director chooses their own department's owner from the people in that
department; MD/ED, Admin and the Order Coordinator can choose for any
department. The owner is told by a notification and can open the order.

## Management cockpit

Insights → **Management Cockpit** (MD/ED, Admin, the department
Directors, the Order Coordinator): open, overdue and escalated tasks per
department, orders without an owner, orders by status, everything
escalated, and suppliers that are late. Counts and status only — no
amounts.

## Task due days (Admin)

Admin → **Task Due Days** sets, for each kind of task, the days until it
is due and the further days until it is escalated (0 to 90).

## The Order Coordinator (optional)

The **Order Desk** department is switched off. When order volume grows,
switch it on in Admin → Departments and add the coordinator: they see
every order's status, tasks and due dates and can set owners, but see no
prices and no money.
