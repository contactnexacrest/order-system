# NexaCrest E2E UI smoke suite

Playwright critical-path smoke suite, run against the PHP and Node stacks'
real dev servers (not the disposable PHPUnit/Jest test databases) — this
is the one test category neither `PHP_MySQL_Version/app/tests/` nor
`NODE_MySQL_Version/app/tests/` cover: an actual browser driving the real
rendered pages, not a direct controller/HTTP call.

## What it covers

- `00-login.spec.js` — basic login smoke test.
- `01-critical-path.spec.js` — one real order, walked end-to-end through
  all 9 pipeline stages exactly as staff would in the browser, through
  to `status = 'complete'`. Along the way it adds and pays the supplier's
  and logistics partner's bills, and downloads the Proforma Invoice and
  Commercial Invoice it generates (PDF and Word) to check their payment
  wording: the ADVANCE PAYABLE NOW box, the balance on the order's own
  terms, the freight & insurance Debit Note line, the bank-charges
  sentence, the cleared-funds sentence and Calendar Days.
- `01-role-visibility.spec.js` — every staff role (Admin, Managing
  Director, Executive Director, Export Executive, Accounts Executive,
  Logistics Executive, Viewer/Auditor, CA) logs in and the nav renders
  exactly what that role is permitted, with denied routes refused
  directly (not just hidden).
- `02-role-workflows.spec.js` — the same 9-stage order, but each stage's
  real work done by whichever role's actual permission grant covers it
  (not assumed from the TEST_PLAN's summaries), plus Viewer/Auditor
  read-only confirmation, CA's view-only boundary, a real client
  portal login/workflow, and the CAFIN generated automatically at Mark
  Fully Settled.
- `03-disputes-amendments.spec.js` — a real client-raised dispute
  replied to and resolved by staff (the Export Executive is assigned to
  the order first — QA Round 5, R5-24: staff work only orders they can
  open), and an amendment through its real maker-checker approval rule.
- `04-intake-reorder.spec.js` — one client's full journey: cold public
  intake accepted into a client, a PI-stage confirmation applied, and a
  reorder request approved into a new order.
- `05-email-admin.spec.js` — the Level-1/Level-2 deferred email send
  approval queue, and a representative Admin/Settings CRUD screen.
- `06-responsive.spec.js` — real phone/tablet viewport coverage against
  the app's actual responsive CSS breakpoints (hamburger nav,
  single-column grids, horizontal-overflow checks). Chromium only — see
  that file's own header comment for why cross-browser-engine testing
  isn't reachable in this sandbox.
- `07-ca-module.spec.js` — Accounts Executive's real CA-module writes
  (export benefit claims, FY lock/unlock) vs. the CA role's view-only
  boundary.
- `08-email-functionality.spec.js` — does the app actually attempt to
  send the right email, to the right recipient, with the right
  attachment (plain-text notifications, the client-portal invite, and a
  full document-send-to-dispatch cycle). See that file's own header
  comment for what this can and can't prove without a real SMTP server.
- `09-form-input-kept.spec.js` — the sitewide form safety net in each
  stack's `app.js`: typed values put back into the form after the server
  rejects it (a duplicate email-template key), and the pre-submit checks
  the browser's own validation misses (a required field holding only
  spaces, a numeric field typed with a comma, a "Re-enter …" field that
  does not match), plus the QA Round 5 R5-27 / R5-30 checks (an empty
  dropdown option, a reference document cleared of its text, an order
  message with neither text nor a file).

This is real end-to-end coverage of the features above, not exhaustive
coverage of the whole app — it proves these specific flows work on a
real rendered page in a real browser, which the existing Unit/
Integration suites (controller-level or HTTP-level, no browser
involved) don't. It is not a substitute for those — see
`docs/QA/TEST_PLAN.md` in either stack for the full test strategy.

## Prerequisites

Settings the suite reads from the environment (QA Round 5, R5-13):

- `BASE_URL` — which stack to drive (`http://127.0.0.1:8080` PHP, `:3000` Node).
- `PW_CHROMIUM_PATH` — a specific Chromium binary; leave unset to use
  Playwright's own installed browser (`npx playwright install chromium`).
- `E2E_DB_USER` / `E2E_DB_PASSWORD` / `E2E_DB_NAME` — the dev database the
  suite's DB helpers write to (defaults: `nexacrest_app` / `DevPass123!` /
  `nexacrest_php_dev` or `nexacrest_node_dev` by stack).

The two email specs (05, 08) switch the "Email sending" setting to what
each test needs and restore it afterwards, so the suite passes on a fresh
seed (which ships with email sending off).

Both stacks need their own real dev database, schema+seed applied, and a
known-working login. One-time setup, per stack:

```bash
# schema + seed (skip if already applied)
mysql -u nexacrest_app -p<password> <db_name> < <stack>/docs/schema.sql
mysql -u nexacrest_app -p<password> <db_name> < <stack>/docs/seed.sql

# fix the seeded asset rows' storage path placeholder (Node docs explain why)
mysql -u nexacrest_app -p<password> <db_name> -e "
  UPDATE assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '<absolute STORAGE_BASE_PATH from .env>');
  UPDATE user_signature_assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '<same path>');
  UPDATE reference_library_documents SET file_path = REPLACE(file_path, '__STORAGE_BASE_PATH__', '<same path>');
"

# the seeded admin's password is a placeholder staff are forced to change
# on first login — for a scripted suite, reset it to a known value instead
# of logging in interactively once to clear force_password_change:
mysql -u nexacrest_app -p<password> <db_name> -e "
  UPDATE users SET password_hash = '<a fresh bcrypt hash of your chosen password>',
         force_password_change = 0, is_active = 1
  WHERE email = 'gulmohar.sontakke@nexacrestinternational.com';
"
```

Generate the bcrypt hash with each stack's own hashing function (PHP's
`password_hash()` produces a `$2y$` hash; Node's `bcrypt` package
produces `$2b$` — either verifies fine against its own stack, but don't
assume they're interchangeable without checking):

```bash
php -r "echo password_hash('<password>', PASSWORD_BCRYPT, ['cost'=>12]);"
node -e "console.log(require('bcrypt').hashSync('<password>', 12))"
```

Update `ADMIN_EMAIL`/`ADMIN_PASSWORD` in `tests/support/auth.js` to match
whatever you set.

`tests/support/auth.js`'s `ROLES` map also needs one account per role
other than `admin`/`executiveDirector` (the two real seeded people) —
`seed-ui-test-users.sql` creates all 6 (External QA report TI-10: these
used to be created by hand, with no committed script and nothing said
here, so a fresh clone's e2e run failed at login for 6 of 8 roles with
no clue why):

```bash
mysql -u nexacrest_app -p<password> <db_name> < e2e/seed-ui-test-users.sql
```

The script's `@pw` hash is for the PHP stack (`password_hash()`,
`$2y$`) — for the Node stack, open the file and swap it for the `$2b$`
hash named in its own header comment (`bcrypt`'s output), or regenerate
either with the commands the file documents.

The critical-path spec generates real documents, which on the Node stack
means Puppeteer actually launches a browser — `NODE_MySQL_Version/app/.env`'s
`PUPPETEER_EXECUTABLE_PATH` has to point at a real Chromium/Chrome binary on
this machine (`which chromium`), not just whatever the `.env` template
shipped with; a stale/missing path fails every document-generation step with
"Document generation failed" and the real reason only in the server's own
log, not the browser.

`08-email-functionality.spec.js` reads each stack's own "email attempt"
log rather than a real inbox (neither stack has SMTP configured in a dev
sandbox — see that file's own header comment). PHP's is stable
(`PHP_MySQL_Version/storage/logs/error.log`, independent of how the
server was started) but Node's email service only ever calls
`console.error` with no file sink of its own, so the Node dev server
**must** be started with its output redirected to a file, or that one
spec file fails outright with a clear error naming the missing path
(override it with `NODE_SERVER_LOG` if you redirect somewhere other
than the default below):

```bash
# PHP
cd PHP_MySQL_Version/public_html && php -S 127.0.0.1:8080 _dev_router.php &

# Node — note the redirect, required for 08-email-functionality.spec.js
mkdir -p /tmp/claude-0
cd NODE_MySQL_Version/app && npm start > /tmp/claude-0/node-server.log 2>&1 &
```

## Running

```bash
cd e2e
npm install          # once
npm run test:php     # BASE_URL=http://127.0.0.1:8080
npm run test:node    # BASE_URL=http://127.0.0.1:3000
# or point at anything else:
BASE_URL=http://127.0.0.1:9000 npx playwright test
```

The same spec runs unmodified against either stack — the two apps are
meant to be route-for-route, markup-for-markup identical ports of the
same spec (per both stacks' own `docs/QA/TEST_PLAN.md`), so one suite,
parametrized only by `BASE_URL`, is the right shape here rather than two
near-duplicate suites.

## Why this isn't wired into `composer test` / `npm test`

Those run each stack's own PHPUnit/Jest suite against a disposable,
auto-rebuilt test database with no server process involved. This suite
needs a real running server plus a real seeded dev database with a known
login — deliberately a separate, manually-invoked step, not part of the
fast inner-loop test run.
