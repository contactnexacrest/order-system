# NexaCrest E2E UI smoke suite

Playwright critical-path smoke suite, run against the PHP and Node stacks'
real dev servers (not the disposable PHPUnit/Jest test databases) — this
is the one test category neither `PHP_MySQL_Version/app/tests/` nor
`NODE_MySQL_Version/app/tests/` cover: an actual browser driving the real
rendered pages, not a direct controller/HTTP call.

## What it covers

One real order, created and walked end-to-end through all 9 pipeline
stages exactly as staff would in the browser — login, create an order,
pass each of the 9 stage gates (generating the real documents and
recording the real payments/confirmations each gate needs), through to
`status = 'complete'` at Stage 9.

This is a smoke suite, not exhaustive coverage — it proves the critical
path works end-to-end on a real rendered page in a real browser, which
the existing Unit/Integration suites (controller-level or HTTP-level, no
browser involved) don't. It is not a substitute for those — see
`docs/QA/TEST_PLAN.md` in either stack for the full test strategy.

## Prerequisites

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

The critical-path spec generates real documents, which on the Node stack
means Puppeteer actually launches a browser — `NODE_MySQL_Version/app/.env`'s
`PUPPETEER_EXECUTABLE_PATH` has to point at a real Chromium/Chrome binary on
this machine (`which chromium`), not just whatever the `.env` template
shipped with; a stale/missing path fails every document-generation step with
"Document generation failed" and the real reason only in the server's own
log, not the browser.

Then start each stack's dev server (see that stack's own README's
"First-time setup" / local-testing section) and point the suite at it:

```bash
# PHP
cd PHP_MySQL_Version/public_html && php -S 127.0.0.1:8080 _dev_router.php &

# Node
cd NODE_MySQL_Version/app && npm start &
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
