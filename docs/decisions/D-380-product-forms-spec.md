# D-380 … D-389 Product forms, specifications (HS), BL wording, document fixes — build specification

Owner approved 18 Nov 2026, mockups 1–9 one by one (documents: confirmed twice), whole plan confirmed twice
("confirmed"). Build on BOTH stacks (PHP `PHP_MySQL_Version/app`, Node `NODE_MySQL_Version/app`), behaviour
identical, and in BOTH UI templates (Standard and Kanban) wherever a screen exists. MySQL 5.7-safe SQL, no CTE /
window functions, ONLY_FULL_GROUP_BY safe. Migrations: PLAIN ALTER (owner D-371) + CREATE TABLE IF NOT EXISTS +
INSERT … WHERE NOT EXISTS + plain UPDATE; one row per change in `docs/checks/check_all_migrations.sql`; row in
`docs/migrations/README.md`; same structure in both stacks' `docs/schema.sql` and `docs/seed.sql` (fresh install ==
migrated DB). No PII (role names / placeholders only). Every new input: placeholder, browser validation via the
sitewide `app.js` rules, "Choose…" first on required selects, labels with "*" (D-366: compact fields count as
labelled). Reasons via the existing ReasonValidator. Every change audited. No change to Zoho, CAFIN, profit,
client portal, associate portal. Lines already saved and documents already issued keep what they have.

Decision numbers: D-380 BL wording · D-381 CHA per supplier · D-382 forms of stone & units · D-383 specification
list (HS) & finishes per form · D-384 product "Sold as" · D-385 order line Sold as + locked HS · D-386 documents
"Product Form" · D-387 Supplier PO / Products Amendment "Product Form" · D-388 quantity TBC prints "To Be
Confirmed" · D-389 reorder specification options.

## Item 1 — D-380 Bill of Lading wording (DOCUMENT CHANGE, owner confirmed twice)

A. QT / PI / CI section 7 item 4 (PDF twig/njk AND Word builders, all copies — today the Word QT/PI text differs
from the PDF; after, all identical):
`4. Bill of Lading — full set of original Bills of Lading as issued by the shipping line, couriered to the Buyer
within 3 Working Days of the Balance Payment being confirmed as Payment Cleared (or Telex Release / surrendered BL
on request)`
B. T&C clause 95 text (seed + migration UPDATE of the live master clause only, never per-order frozen copies):
replace the opening "Bill of Lading: Original negotiable BL (3 originals) will be released to buyer only after"
with "Bill of Lading: The full set of original negotiable Bills of Lading, as issued by the shipping line, will be
released to the buyer only after" — rest of the clause unchanged.
C. BL Instruction (PDF + Word): banner "ORIGINAL NEGOTIABLE BILL OF LADING — FULL SET"; "No. of Original Copies" =
"Full set as issued by the shipping line (the number is stated on the BL)"; release banner "CHA to hand the full
set of originals to NexaCrest only — never to the buyer or anyone else." Keep "DO NOT ISSUE SEA WAYBILL OR EXPRESS
BL" and non-negotiable copies 3.
D. Reference-library checklist texts (seed.sql around lines 1130–1365 and the live rows via migration UPDATE with
REPLACE()): every "3 Originals ONLY"/"3 originals"/"all 3 originals" → full-set wording:
"BL TYPE: Original Negotiable Bill of Lading — full set of originals. Telex Release / surrendered BL only on the
buyer's request and with MD approval, after the balance is cleared." and "NexaCrest retains the full set of
originals until the balance T/T is cleared" (no fixed "60%"; say "the balance"). Also "HS Code: 6802.93" →
"68029300" in those checklist lines (3 places). Keep "NEVER request Sea Waybill or Express BL".

## Item 2 — D-381 CHA + transport per supplier

- `suppliers.cha_transport_per_container DECIMAL(14,2) NULL`, `suppliers.cha_port VARCHAR(100) NULL`.
- Order of use (ThicknessPrices::chaPerContainer and its Node twin): the price row's own figure → the row's
  supplier's figure (`catalog_product_suppliers.supplier_id` → `suppliers`) → the product default → none.
  Return also WHERE it came from: 'row' | 'supplier' | 'product'.
- A price row with `fob_source = 'computed'` (older, CHA already inside) never gets per-container CHA added
  (fobParts / cost): show note "CHA already inside this older price".
- Edit Supplier form: box "CHA + transport per container, factory → port (₹)" + "Port this figure is for"
  (optional) + reason (required when either changes); shown and saved only for users with view_purchase_prices or
  view_logistics_costs (whichever the app uses for CHA today). Audit SUPPLIER_CHA_CHANGED (old → new).
- Edit price › More: CHA field placeholder/help names the fallback ("Empty = supplier's ₹45,000.00 (Mundra)" or
  the product default). Price check / build-up shows the source as a small tag: "this price" / "supplier" (+port) /
  "product default".

## Items 3–5 — D-382 / D-383 / D-384 Forms, specification list (HS), finishes, "Sold as"

Tables (new):
- `product_forms` (id PK, code VARCHAR(40) UNIQUE, name VARCHAR(80), units VARCHAR(100) NOT NULL — comma list of
  canonical units as LineUnits::canonical (CBM, M2, SQFT, PCS, TON …), default_unit VARCHAR(10) NOT NULL,
  spec_group VARCHAR(20) NOT NULL — one of today's ProductSpecs::FORMS keys (block, slab, tile, kerb, step,
  cobble), so `spec_definitions.applies_to` keeps working unchanged, default_finish VARCHAR(80) NULL,
  sort_order INT, is_active TINYINT(1) DEFAULT 1, updated_by, created_at, updated_at).
  Seed (name · units · default unit · group · default finish):
  Monument slab · PCS,M2,SQFT,CBM · PCS · slab · Natural Polish
  Gangsaw slab · M2,SQFT,CBM · M2 · slab · Natural Polish
  Cut-to-size slab · M2,SQFT,CBM,PCS · M2 · slab · Natural Polish
  Raw block · CBM,TON · CBM · block · Roughly trimmed
  Sawn block · CBM,TON · CBM · block · Sawn
  Tile · M2,SQFT,PCS · M2 · tile · Natural Polish
  Paving stone · M2,SQFT,PCS · M2 · slab · Flamed
  Kerbs, setts, cobbles · PCS,M2,RMT · PCS · kerb · Flamed
- `hs_specifications` (id PK, name VARCHAR(120), form_id, usual_finish VARCHAR(80) NULL, hs_code VARCHAR(8) NOT
  NULL — must exist & be active in hs_codes, 6 or 8 digits, no dots, when_to_use VARCHAR(255) NULL, sort_order,
  is_active, updated_by, timestamps). Seed:
  Raw block · Raw block · Roughly trimmed · 25161100 · "As it comes from the quarry"
  Sawn block · Sawn block · Sawn · 25161200 · "Sawn / cut to size on all sides"
  Gangsaw slab · Gangsaw slab · Natural Polish · 68022310
  Random slab — irregular · Gangsaw slab · Natural Polish · 68022390
  Cut-to-size slab · Cut-to-size slab · Natural Polish · 68022310
  Tile · Tile · Natural Polish · 68022310
  Monument slab · Monument slab · Natural Polish · 68029300
  Monument — complete set / tombstone · Monument slab · Natural Polish · 68029300
  Paving stone · Paving stone · Flamed · 68010000
  Kerbs, setts, cobbles · Kerbs, setts, cobbles · Flamed · 68010000
- `product_hs_specifications` (product_id, hs_spec_id, PRIMARY KEY both) — the product's "Sold as" ticks.
  Migration maps once from `catalog_products.forms` (today's ticks): block → Raw block + Sawn block; slab →
  Gangsaw slab + Cut-to-size slab + Monument slab; tile → Tile; kerb/step/cobble → Kerbs, setts, cobbles.
  Products with no forms → no ticks (= every active specification offered, as "no ticks = all forms" today).
- `surface_finish_forms` (dropdown_option_id, form_id, PK both): which finishes a form offers. No rows for a
  form = all finishes (safety). Seed: "Roughly trimmed" → Raw block only; "Sawn" → Sawn block only; every other
  surface_finish option → every non-block form.
- New finishes in Settings › Lists `surface_finish`: "Roughly trimmed", "Sawn" (with their linked Surface finish
  spec_options at no price change, the D-357 way — see migration 2026-11-12j for how options link).
- `hs_codes` row 68022310 description "Granite blocks or tiles, polished" → "Granite blocks or tiles".
- `order_products.hs_code` column default '6802.93' → '68029300' (ALTER … MODIFY, same type/nullability).

Screens:
- Admin › Pricing › **Forms of stone** (list + add/edit: name, units ticks, starts on, group, default finish,
  order, active; reason required; audit PRODUCT_FORM_CHANGED). Switch off, never delete once used (delete allowed
  only when no specification/line uses it). Permission: the one that manages Pricing › Specifications today.
- Products › **Specifications (HS)** (list + add/edit: name, form (Choose…), usual finish, HS code (from the HS
  Code Master, active only), when to use, order, active; reason; audit HS_SPECIFICATION_CHANGED). Same
  switch-off rule. Same permission as above (or manage_products if that is what Products uses — pick the existing
  one, document it).
- Settings › Lists › surface_finish: per finish, ticks "Offered for forms" (saved with the page's reason).
- Product › Specifications page: "Sold as" ticks of active specifications (name · HS code), reason required,
  audit PRODUCT_SOLD_AS_CHANGED; replaces the old fixed "Forms" ticks — the product's forms used for
  spec applies_to = the spec_groups of its ticked specifications' forms; none ticked → old `forms` value (kept,
  read-only) → else all. New product: at least one tick required (server + browser) once the product is saved
  with specifications; existing products keep working with none.
- Product › Edit: HS code label "HS Code (fallback)" + help line.
- Products list (Standard + Kanban): "Sold as" short list per product.
- Edit price / + Add product for a block form: "Priced per" select (CBM / Tonne). A per-tonne price is stored as
  per-CBM (price × the product's weight per CBM, PricingLevels weight_t_per_cbm) in the existing fields, with the
  typed value and unit kept in new columns `catalog_product_suppliers.entered_unit VARCHAR(10) NULL`,
  `entered_price DECIMAL(14,2) NULL` for display ("₹30,000.00 per tonne = ₹87,000.00 per CBM at 2.9 t/CBM").
  Without a weight per CBM the per-tonne choice is refused with the existing LineUnits weight message.

## Item 6 — D-385 Order line "Sold as" + locked HS code

- `order_products.hs_spec_id BIGINT UNSIGNED NULL`, `order_products.hs_override_reason VARCHAR(500) NULL`.
- Line panel (order page, both templates) and New Order product rows: today's 4 steps unchanged. Step 1 gets
  "Sold as *" (only the product's ticked active specifications; all active when none ticked) and a read-only HS
  code shown from it. Required for goods lines picked from Products on a USD order (where picking from Products is
  required); optional otherwise. Changing it refills HS code, the Unit list (only the form's units; the form's
  default unit preselected for a new line) and the Surface finish option to the form's default finish when the
  current one is not offered for the form; finishes offered = surface_finish_forms.
- Required choices follow the unit when a Sold as is chosen (replaces D-348 REQUIRED_SPEC_NAMES for those lines):
  CBM / TON → nothing extra; M2 / SQFT → Thickness (option or piece thickness cm); PCS → length, width, thickness
  (as today's LineUnits rules). Lines without Sold as keep D-348 exactly.
- Server: hs_code = specification's hs_code; a posted different code is refused unless the user holds the new
  permission `override_line_hs_code` ("Change a line's HS code from its specification, with a reason" — MD,
  Founder & MD, ED, Founder & ED) AND gives a reason (ReasonValidator) AND the code is an active HS master code;
  audit HS_CODE_OVERRIDDEN (line, old → new, reason). Unit not offered by the form → refused with a clear message.
- Order page products table: "Sold as" shown under/next to the product; HS cell tag "from specification" or
  "changed by MD/ED — <reason>". Duplicate line copies hs_spec_id, hs_code, hs_override_reason.
- Kanban order board / any list showing lines: same info where lines are listed.
- Size ranges: no new field — the existing Size note (help text: "e.g. Length 160–210 cm").

## Item 7 — D-386 Documents "Product Form" (DOCUMENT CHANGE, confirmed twice)

QT, PI, OC, CI only (PDF twig/njk + Word, both stacks): in the grey detail line under a product that has a Sold as,
add `Product Form: <form name>` — detailed table (LineTable::detail): immediately before "HS Code:"; simple table
spec rows: as the first item. Nothing else changes (finish, dimensions, HS format, columns, totals). Lines without
Sold as print exactly as today. Packing List, BL Instruction, COO prep, Buyer PO, other documents: unchanged.

## Item 8 — D-387 Supplier PO + Products Amendment (DOCUMENT CHANGE, confirmed twice)

- Supplier PO Section 3: new row "Product Form" right after the material row, value from new column
  `supplier_purchase_orders.product_form VARCHAR(80) NULL` (find the real Supplier PO table name), prefilled on
  the Supplier PO form from the order line's form, editable like the other Section 3 fields; row printed only when
  filled. PDF + Word, both stacks.
- Products Amendment (buyer document): in the Product cell, a second line "Product Form: <form name>" for lines
  with Sold as. PDF + Word, both stacks.

## Item 9 — D-388 Quantity "To Be Confirmed" totals (DOCUMENT CHANGE, confirmed twice)

On every document built from the line tables (QT and the others that can print a TBC line; PDF + Word, both
stacks): a goods line with `quantity_is_tbc` prints its line total as "To Be Confirmed" (never 0.00). The TOTAL
row, "FOB Value", "TOTAL QUOTED VALUE" (and the equivalent totals and the amount in words where a document prints
them): every goods line TBC → "To Be Confirmed"; some known → "<known sum formatted as today> + line 2 To Be
Confirmed" (several: "+ lines 2, 4 To Be Confirmed"). Price per unit prints as today. No change to stored
fob_value, app screens, Zoho, CAFIN, profit.

## Item 10 — D-389 Reorder specification options + Monument first

- Pricing › Specifications (category options) and the product's own options: each option's "Order" editable
  (today a hidden field) or ↑/↓ buttons; saved with audit. Both templates.
- Migration: within every category-level "Size / format" specification, option "Monument (cut to size)" moves
  first (others +1, it to 0), others keep their order.

## Migrations (both stacks identical file names)

`docs/migrations/2026-11-18a-product-forms-and-hs.sql` (items 3–6, 10, finishes, HS default/description),
`docs/migrations/2026-11-18b-cha-per-supplier.sql` (item 2), `docs/migrations/2026-11-18c-documents-bl-and-po.sql`
(item 1 clause 95 + checklist UPDATEs, item 8 Supplier PO column). Header "PLAIN ALTER (owner D-371)"; re-run
safe except the harmless #1060. Check-script rows for every new table/column/seed row.

## Tests (both stacks)

PHPUnit / Jest per item: chain row→supplier→product and computed-no-CHA; forms CRUD + switch-off; specification
CRUD + HS must be active master code; finish-per-form filtering; product Sold-as ticks + mapping; order line:
Sold as required, HS locked, override needs permission+reason, unit refused when not offered, TON line priced
from weight, D-348 unchanged without Sold as; documents: exact BL wording (QT/PI/CI PDF+Word, BLI, clause 95
seed), "Product Form:" in QT/PI/OC/CI detail line and absent on PL/others and on lines without Sold as, Supplier
PO row + amendment line, TBC totals (all TBC, mixed, multiple); option reorder; seeds/migration equivalence;
Kanban parity tests updated for any list change; form label/placeholder/validation convention tests stay green.
e2e (Playwright, both stacks): a person adds a form and a specification, ticks Sold as on a product, adds a raw
block line in tonnes, sees the locked HS code, MD changes it with a reason, generates a Quotation and finds
"Product Form" and "To Be Confirmed", reorders an option.
