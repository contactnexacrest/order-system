# Zoho notes

- `zoho-change-log.csv` — every change made in Zoho (Books, Mail, CRM, API
  Console …), by anyone, test or real, one row each (standing rule,
  2026-10-29).
- **API call limits (owner decision 2 Nov 2026, D-239).** Zoho limits API
  calls per organisation (about 100 a minute and a daily number by plan),
  shared by every Zoho app and integration of the company. This app keeps to
  its own share — Admin → Settings → Zoho Books: `zoho_api_daily_limit`
  (800) and `zoho_api_minute_limit` (30) — counts every call it makes, stops
  a sync at once when Zoho answers "too many requests", and shares one
  access token between all its processes. Usage is on the app's Zoho Books
  Sync page. Changing these two settings is a change in **our app**, not in
  Zoho, so it needs no row in the change log; the app's own Zoho calls on
  the live site are recorded in its Zoho Sync Log.
