# 21 — Departments, "Log in as" and who sees what

NexaCrest is organised in **departments** (Sales & Export, Procurement,
Logistics, Accounts & Finance, Company Management, Audit & Compliance,
Administration). Each department has its own ladder of **designations** —
Executive, Manager, Director — and each designation is its own **role**:
the set of things that person may see and do.

Day to day, nobody edits permissions. To give someone access, add them to
a department. To take it away, end that membership.

## Logging in as a department

- Someone in **one** department goes straight in, working as that
  department.
- Someone in **more than one** department sees **Log in as** after the
  password (and 2FA) step, and picks one. Tick **Start here next time** to
  skip the question from then on.
- The menu always shows **Logged in as** with the designation and the
  department, in the department's colour.
- **Switch department** (under it) changes department without logging
  out. The app reloads with that department's menus and data.
- Someone who is in no department yet works with their own role (the one
  on their user page), exactly as before.

Every change a person makes is recorded in the Audit Log with the
department they were working as at the time.

## Admin → Departments

**Admin → Departments** shows every department with its Executive,
Manager and Director, how many people hold each designation today, and
the role behind it. From this page Admin can:

- **Add a department** — name, a short code (e.g. QUALITY) and a colour.
- **Add a designation** — department, title, level, and its role: either
  a new role with the same name (then set its permissions with the role
  link) or an existing role.
- **Edit** a department (name, colour, active, sees the whole company) or
  a designation (title, level, active, approval limit in rupees).
- **Preview the app as this** — see the app exactly as that designation
  sees it. Admin's own extra rights are off during a preview. A yellow
  banner stays at the top; **Exit preview** returns to Admin.

A department or designation switched off can no longer be chosen at
"Log in as"; people in it fall back to their other departments.

## A person's departments (Admin → Users → Edit)

The **Departments** section on the user page lists the person's
memberships, current and past.

- **+ Add membership** — choose the department and designation and the
  date it starts.
- **End** — give the last day. The membership stays on record; from the
  next day it can no longer be chosen, and if the person is working as it
  at that moment, it stops at once.

Founder accounts cannot be changed through the application.

## Personal exceptions

Rarely, one person needs one extra right outside their department (for
example, covering for a colleague). **Admin → Permissions → Grant an extra
permission** takes a reason and, now, an optional **Until** date. From the
day after that date the exception no longer applies; the list shows it as
"ended".

## Who sees what (from phase 2)

The organisation design keeps money apart: Sales sees the selling side,
Procurement the buying side, Logistics only CHA, transport and freight;
Finance, the MD/ED and the auditors see everything. Those limits are set
on each designation's role and are rolled out in the next phase.
