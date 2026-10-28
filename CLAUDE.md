# Standing rules for this repository (from the owner)

- **Parity:** PHP_MySQL_Version and NODE_MySQL_Version must always behave the same. Every change goes into both.
- **Verify before pushing:** PHPUnit, Jest and the Playwright e2e suite (`e2e/`) against both stacks.
- **Ask before pushing:** never push to git without asking the owner first (this replaces the earlier "push always" rule, 2026-10-27).
  - When asking, first give a short summary: what is being pushed, which files change, and which functionality is affected.
  - With it, give the owner a **review pack** they can actually check before deciding (2026-10-28): (1) what changed, in plain words, one line per fix; (2) before/after screenshots on both stacks for everything visible; (3) "try it yourself" steps — which user/role, which order, what to click, what should happen (and what should now be refused); (4) which roles are affected; (5) test results on both stacks, failures explained; (6) risks and what was deliberately not changed; (7) live-site steps (migrations, in order); (8) files changed, grouped; (9) the decision asked: push / don't push / change first.
- **No PDF / document changes without double confirmation.** Before changing anything that ends up in a generated document (PDF or DOCX templates, the document data assembler, document builders), first show the owner what the document shows now and what it will show after the change, and get their confirmation twice. All current PDFs are correct as they are.
- **Mockups first:** for a larger UI redesign, show mockup images and get confirmation before building.
- **CAFIN must never reach the client**, at any cost: not by download, link, email or portal.
- **Browser-side validation on every form, existing or new.** Every input form is checked in the browser before it is submitted, with the message shown beside the field (server-side checks stay as well).
  - The check lives once, sitewide, in `public_html/assets/js/app.js` (PHP) and `public/js/app.js` (Node). Every layout loads it. Extend `fieldError()` there for a new rule rather than writing per-page scripts.
  - Use HTML attributes (`required`, `type`, `min`/`max`, `minlength`, `pattern`) for the simple rules. `app.js` shows their messages inline and opens a closed step card that holds the invalid field.
  - A "Re-enter ..." field must be checked against the field it confirms. Name it `<name>_confirm` (or `confirm_password` for `new_password`), or give it `data-match="<name>"`. `FormValidationConventionsTest` / `formValidationConventions.test.js` enforce this.
- **MySQL compatibility:** the host runs MySQL, while dev runs MariaDB. Never use `ADD/DROP/CHANGE COLUMN IF [NOT] EXISTS` or `CREATE INDEX IF NOT EXISTS`. Guard with information_schema + PREPARE instead (see `docs/migrations`). Queries must pass ONLY_FULL_GROUP_BY.
- **SOP:** edit `docs/SOP`, then copy it to `PHP_MySQL_Version/docs/SOP` and `NODE_MySQL_Version/docs/SOP`.
