# 26 — Agreements register

**Who this is for:** the Managing Director, Executive Director and Admin (who keep the
register), and the Sales, Procurement, Logistics and Finance Directors (who can look
things up in it).

The Agreements register holds every agreement NexaCrest has signed with a **client**,
**supplier**, **logistics partner**, **associate** or **staff member**: its type, the
signed copy, when it was signed, when it starts and when it expires. Nobody has to
remember an expiry date: a reminder goes out **30 days** and **7 days** before an
agreement expires, and **on the expiry day** (owner decision, 2 Nov 2026).

Menu: **Operations → Agreements** (or open a client, supplier, logistics partner,
associate or user — each has an **Agreements** card at the bottom).

## Who can do what

| | Sees the register | Adds, edits, renews, ends | Sees staff agreements (staff NDAs) | Gets the reminders |
|---|---|---|---|---|
| MD, ED, Admin (permission **Manage agreements**) | ✔ | ✔ | ✔ | ✔ |
| Sales / Procurement / Logistics / Finance Director (**View agreements**) | ✔ | — | — | — |
| Everyone else | — | — | — | — |

A staff member's agreements (for example a staff NDA) are seen only by holders of
**Manage agreements** — not even the Directors who can view the rest of the register.

## The register

The list (or the Kanban board, if you use the Kanban template — both show the same)
has one row per agreement:

- **With** — the client, supplier, logistics partner, associate or staff member, linked
  to their page.
- **Agreement** — its type (Sales & Supply Agreement, Commercial NDA, Staff NDA,
  Supply Agreement (Quarry/Factory), Logistics Services Agreement, Associate Agreement,
  Other) and its title.
- **Signed / Expires** — the dates; "in N day(s)" while it is still running.
- **Status**:
  - **Expiring soon** — expires within 30 days.
  - **Active** — expires later than that.
  - **No expiry** — no expiry date was entered.
  - **Expired** — the expiry date has passed.
  - **Terminated** — recorded as ended early (with the date and reason).
  - **Renewed (replaced)** — a newer agreement replaced it.

Filters: the status chips at the top, **With** (party type), **Type**, **Expiring within**
(7 / 30 / 60 / 90 days) and a search box (name, type, title or note).

The dashboard also shows an **Agreements expiring soon** box to everyone who can see
the register, whenever something expires within 30 days.

### Agreements kept on other pages

Two agreements already had their own place in the system and stay there:

- the **client commercial agreement** (its footer prints on the client's documents) —
  upload, renew and force-expire it on the **client's page**, as before;
- the **associate agreement** (Commission Structure and Associate Agreement) — on the
  **associate's page**, as before.

The register lists both, marked "Managed on the client page" / "Managed on the associate
page", so it is complete, and the reminders cover them too (the client agreement had no
reminder before). Nothing about how they work has changed: the client footer still prints
exactly as it did.

## Add an agreement

1. **Operations → Agreements → Add an agreement**, or **Add agreement** on the party's
   own page (the party is then already chosen).
2. **Agreement with** — client, supplier, logistics partner, associate or staff member,
   then choose who.
3. **Type of agreement** — from the list (Admin can add types under **Admin → Dropdown
   Option Lists**, list "agreement_type").
4. **Title** (optional) — e.g. "Granite supply 2026–2028".
5. **Signed on** — required; cannot be in the future.
6. **Starts on** — only if it differs from the signing date.
7. **Expires on** — leave empty if the agreement never expires. It must be after the start
   (or signing) date.
8. **Signed copy** — required: PDF, Word or a scan (JPG/PNG), up to 15 MB.
9. **Notes** (optional) — e.g. "Renewal needs 60 days' notice".
10. **Save agreement**.

## Renew an agreement

On the register, **Renew** on the agreement. The form opens with the same party and type,
and the start date set to the old expiry date. Attach the newly signed copy and save. The
old agreement shows **Renewed (replaced)** and gets no more reminders.

## An agreement ended early

Open it with **Edit**, then at the bottom **The agreement has ended early**: enter the
date and a reason (5 to 500 characters) and **Record as ended**. It shows **Terminated**
and no more reminders go out.

## Reminders

Every day the alert job checks every agreement with an expiry date. Each person with
**Manage agreements** gets an in-app notification (and its e-mail) when an agreement is
30 days from expiry, again at 7 days, and on the expiry day — each one only once, however
often the job runs. Terminated and renewed agreements get no reminders.

## What is recorded

Adding, renewing, editing, ending and downloading an agreement each write an audit-log
row (AGREEMENT_ADDED, AGREEMENT_RENEWED, AGREEMENT_UPDATED, AGREEMENT_TERMINATED,
AGREEMENT_DOWNLOADED).
