# Standing rules for this repository (from the owner)

- **Parity:** PHP_MySQL_Version and NODE_MySQL_Version must always behave the same. Every change goes into both.
- **Verify before pushing:** PHPUnit, Jest and the Playwright e2e suite (`e2e/`) against both stacks.
- **Push always:** commit and push finished work without waiting to be asked.
- **Mockups first:** for a larger UI redesign, show mockup images and get confirmation before building.
- **CAFIN must never reach the client**, at any cost: not by download, link, email or portal.
- **Supplier and buyer never cross (non-negotiable).** A supplier, CHA or any other vendor never sees the client's details, the client's order terms or the client's amounts. That means no name, address, references (inquiry, Buyer PO, Quotation, PI), products, prices, payment terms or amendments. The buyer never sees supplier documents (SUPPO, with its INR costs).
  - A link always opens only its own page: `/supplier-action/` for suppliers, `/buyer-action/` and `/payment-action/` for the buyer. Each token lives in its own table, so one can never open the other's page.
  - Documents for suppliers (`DocumentDataAssembler::SUPPLIER_FACING`, currently SUPPO) get a stripped context with no client data at all (`supplierFacingOnly()`). Add any new supplier-facing document type there.
  - The BL Instruction and similar freight documents, where the order value is needed, are a separate, deliberate case.
  - Enforced by `SupplierBuyerSeparationTest` / `supplierBuyerSeparation.test.js` and the e2e critical path.
- **Browser-side validation on every form, existing or new.** Every input form is checked in the browser before it is submitted, with the message shown beside the field (server-side checks stay as well).
  - The check lives once, sitewide, in `public_html/assets/js/app.js` (PHP) and `public/js/app.js` (Node). Every layout loads it. Extend `fieldError()` there for a new rule rather than writing per-page scripts.
  - Use HTML attributes (`required`, `type`, `min`/`max`, `minlength`, `pattern`) for the simple rules. `app.js` shows their messages inline and opens a closed step card that holds the invalid field.
  - A "Re-enter ..." field must be checked against the field it confirms. Name it `<name>_confirm` (or `confirm_password` for `new_password`), or give it `data-match="<name>"`. `FormValidationConventionsTest` / `formValidationConventions.test.js` enforce this.
- **MySQL compatibility:** the host runs MySQL, while dev runs MariaDB. Never use `ADD/DROP/CHANGE COLUMN IF [NOT] EXISTS` or `CREATE INDEX IF NOT EXISTS`. Guard with information_schema + PREPARE instead (see `docs/migrations`). Queries must pass ONLY_FULL_GROUP_BY.
- **SOP:** edit `docs/SOP`, then copy it to `PHP_MySQL_Version/docs/SOP` and `NODE_MySQL_Version/docs/SOP`.
