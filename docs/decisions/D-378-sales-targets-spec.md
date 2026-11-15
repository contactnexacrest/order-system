# D-378 Sales targets — build specification (owner approved mockups + plan, 15 Nov 2026)

Owner-approved plan and 6 mockups (15 Nov 2026). This file is the build spec for both stacks.
PHP first (`PHP_MySQL_Version/app`), then the Node twin (`NODE_MySQL_Version/app`) must behave the same.
No PII anywhere: role names only in seeds, tests, comments. MySQL 5.7 (no CTE, no window functions,
ONLY_FULL_GROUP_BY safe). No change to prices, Price check, incentive, commission, payouts, PDFs/DOCX,
Zoho, order gates, client portal, associate portal, CAFIN. The feature only READS order data
(its own new tables are the only writes, plus the 3 small new columns below).

## 1. Database (migration `docs/migrations/2026-11-15a-sales-targets.sql`, PLAIN ALTER per D-371)

New tables (CREATE TABLE IF NOT EXISTS is allowed for tables; columns use plain ALTER):

- `sales_target_settings` (setting_key VARCHAR(60) PK, setting_value TEXT NULL, updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP). Keys and seed defaults:
  `period` 'quarter' (month|quarter|half|year), `multiple` '3' (1–10, one decimal), `buildup_months` '3' (0–6),
  `review_months` '12' (3–24), `alert_pct` '60' (1–100), `alert_behind_recipients` NULL,
  `alert_review_recipients` NULL, `alert_no_owner_recipients` NULL (NULL = defaults; '' = nobody ticked →
  fallback MD; otherwise comma-separated user ids).
- `sales_target_company` (id PK, effective_from DATE NOT NULL (1st of month), costs_monthly DECIMAL(14,2) NULL,
  profit_monthly DECIMAL(14,2) NULL, reason VARCHAR(500) NOT NULL, created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP). The row with the greatest effective_from <= period start
  applies; none, or costs and profit both empty/0 → company target OFF.
- `sales_targets` (id PK, scope ENUM('designation','user','associate') NOT NULL, ref_id BIGINT UNSIGNED NOT NULL,
  containers DECIMAL(6,1) NULL, new_buyers INT UNSIGNED NULL, quotations INT UNSIGNED NULL,
  multiple DECIMAL(4,1) NULL, buildup_enabled TINYINT(1) NULL, reason VARCHAR(500) NULL,
  reviewed_at DATETIME NULL, updated_by BIGINT UNSIGNED NULL, updated_at TIMESTAMP …,
  UNIQUE KEY uq_sales_target (scope, ref_id)). Values are per target period. Associate targets: containers only.
- `sales_target_credits` (order_id BIGINT UNSIGNED PK, client_id BIGINT UNSIGNED NOT NULL,
  confirmed_on DATE NOT NULL, how ENUM('advance','lc','po_pi') NOT NULL, containers_confirmed INT NOT NULL,
  shipped_on DATE NULL, containers_shipped INT NULL, reversed_on DATE NULL, reversed_why VARCHAR(20) NULL,
  is_new_buyer TINYINT(1) NOT NULL DEFAULT 0, created_at, updated_at). A snapshot written by
  `SalesTargetService::sync()`; never written from order screens.

New columns (plain ALTER): `users.joined_on DATE NULL`, `orders.lost_category VARCHAR(30) NULL`,
`orders.sales_credit_user_id BIGINT UNSIGNED NULL`.

Permission (seed + migration, WHERE NOT EXISTS style): `manage_sales_targets` — "Manage sales targets" —
"Set the company target, the target multiple, designation and personal targets, move an order's target credit;
see the Targets page (D-378)." category 'reports'; roles: Managing Director, Founder & Managing Director,
Executive Director, Founder & Executive Director.

Add the same to both stacks' `docs/schema.sql` and `docs/seed.sql`, a row in `docs/checks/check_all_migrations.sql`
(tables + 3 columns), and a row in the migrations README table.

## 2. Rules

**Periods.** month; quarter = Jan–Mar, Apr–Jun, Jul–Sep, Oct–Dec; half = Apr–Sep / Oct–Mar; year = Apr–Mar
(Indian FY). Label e.g. "Oct–Dec 2026". Company monthly figures × months in period.

**Confirmation (counts for the salesperson / associate), date = earliest of:**
- advance: earliest `order_payment_receipts.received_on` with leg 'advance' (fallback
  `order_payment_status.advance_remittance_received_at`);
- lc: `order_lc_details.lc_received_at`;
- po_pi (only when the order's `advance_pct` is 0 or NULL-treated-as-0 — no-advance terms): buyer PO received
  (`orders.buyer_po_received_on`) AND PI approved (`orders.frozen_at` not null): date = later of the two.
Excluded: `is_test_data = 1`, `is_sample_data = 1`. Orders already lost/cancelled before confirmation never count.

**Containers.** confirmed = the order's estimated containers from its goods lines
(`OrderLinePricing::totals(lines, forOrder checks)['containers']`), minimum 1. Shipped = `order_shipping.container_count`
when set, else the estimate at that time, minimum 1. Every container counts 1 (20ft or 40ft).

**Shipped.** the order has a CI document (latest, any status except draft/superseded… use the same "printed date"
helper as documents: `DocumentRepository::printedDate`) → shipped_on = that date.

**Corrections (events, each dated):** +confirmed at confirmed_on; −confirmed at reversed_on when the order
becomes cancelled or lost after confirmation (reversed_on = the day sync first sees it, or the order's
cancel/lost date when stored); (shipped − confirmed) at shipped_on when they differ and not reversed.
A period's count = sum of events dated in it. Past periods are never rewritten (events keep their dates).

**New buyer.** the first credit row (by confirmed_on, then order id) for that client_id.

**Credit.** `orders.sales_credit_user_id` if set (MD/ED moved it, reason logged as ORDER_SALES_CREDIT_MOVED),
else the order's Sales & Export department owner (`DepartmentTaskRepository::ownersForOrder`, code 'SALES'),
else "Unassigned". Resolved when read. Associate credit: the order's earning associate
(`AssociateCommission::earningAssociate`).

**Company target (money, MD/ED).** needed = (costs_monthly + profit_monthly) × months. Earned = Σ
`OrderProfitabilityService::computeForOrder(id)['profit_after_incentive_inr']` over orders with shipped_on in the
period. Avg margin per container = Σ profit_after_incentive over orders shipped in the last 3 months ÷ Σ their
containers_shipped (null when none → "not enough history yet"). Containers needed = ceil(needed ÷ avg);
break-even containers = ceil(costs × months ÷ avg). Show the last 2 periods too.

**Personal target per period.** own (scope user) else designation default (scope designation, the person's
default active membership designation as in `IncentiveService::designationEligibility`, but return the id).
Build-up (setting buildup_months N, person's buildup_enabled default 1, needs users.joined_on): for each month of
the period, k = months since joining (joining month = 1): N=0 or N=1 → factor 1; k=1 → 0; 1<k<N → 0.5; k>=N → 1;
months before joining → 0. Containers target = target × average factor. While any factor < 1 in the period, also
show the designation's `quotations` goal (QT e-mails sent on orders credited to the person, in the period).

**Multiple.** global setting `multiple`; per person `sales_targets.multiple`. Only used by the calculator.

**Calculator.** browser-only: inputs have NO name attribute and live outside any form; "Use this" writes the result
into the chosen person's containers field in the edit form. Nothing about salary is ever posted. Avg margin per
container is printed only for users who also hold `view_price_buildup`; otherwise they type it.

**Who sees what.** Targets page + settings + move credit: `manage_sales_targets`. Money figures (₹, margin,
avg margin per container): `manage_sales_targets` AND `view_price_buildup`. Salesperson dashboard box: the signed-in
user's own target only, containers only (their order list may show the order's USD FOB because they already see
selling prices — show it only if `MoneyVisibility::can('selling')`). Associates never see anything.

## 3. Screens (both templates use the same dashboard/cockpit views; Targets page is not a list page)

1. Admin › Pricing settings page: a link card "Sales targets settings" → `/sales-targets/settings` (GET/POST,
   `manage_sales_targets`): company costs, profit, effective from (month), period, multiple, build-up months,
   review months, alert %, reason (required, ReasonValidator); designation defaults table (containers, new buyers,
   quotations) saved in the same form. Audit SALES_TARGET_SETTINGS_CHANGED.
2. `/sales-targets` (GET, `manage_sales_targets`): period picker (current + previous periods); team table
   (person, designation, multiple (own), target, confirmed, shipped, pipeline, new buyers, progress, last reviewed,
   Edit); Unassigned row listing order refs; associates row (MD/ED page only); total + check line vs company
   containers needed; calculator. `/sales-targets/person/{id}` POST: own containers / new buyers / multiple /
   build-up on-off / reason → reviewed_at = NOW(); audit SALES_TARGET_CHANGED. `/sales-targets/associate/{id}` POST
   likewise (containers). `/sales-targets/credit/{orderId}` POST (user id + reason) sets orders.sales_credit_user_id.
   Pipeline = the person's orders with PI approved (frozen_at) or a QT sent, not confirmed, status active.
3. Cockpit (`coordination/cockpit`): new box at the top when the company target is ON and the user has
   `manage_sales_targets`; money lines only with `view_price_buildup`.
4. Dashboard: "Your target — <period>" box when the signed-in user has a target (>0 containers) — target,
   confirmed (shipped · awaiting · corrections · new buyers), still to achieve, pipeline, progress, list of counted
   orders + correction rows.
5. Sales Performance: new optional select `salesperson` (default '' = exactly today's page). '' renders nothing
   new. 'all' or a user id adds a "By salesperson" table below: quotes sent, won (PI), lost before PI, lost after
   PI, win rate, avg days QT→PI, top lost category — orders credited to that person. CSV unchanged.
6. Mark Lost form: optional `lost_category` select (Price, Quality, Delivery time, Competitor, Buyer postponed,
   Other; "Choose… (optional)"); saved only when one of those values; the reason stays required.
7. Edit user: optional `joined_on` date field; saved only when `joined_on_present` posted.
8. E-mail settings section E: 3 new rows with a checkbox list of active staff each (names
   `target_alerts[behind|review|no_owner][]`, plus hidden `target_alerts_present`), saved with the page's existing
   reason; defaults when NULL: behind + review → MD and ED roles; no_owner → MD + 'Sales/Export Manager' role.
   Existing rows unchanged.

## 4. Daily job (inside existing `check_alerts`): `SalesTargetService::sync()` then `runAlerts($today)` using
`ComplianceTrackingService::once()`-style dedupe (`compliance_alerts_sent`):
- behind: once the period is ≥ 50 % elapsed, if company target ON and (containers counted in period + confirmed
  awaiting shipment) ÷ containers needed × 100 < alert_pct → once per period;
- review: each person with a target whose reviewed_at is older than review_months → once per person per month;
- no_owner: each credit row with no credit user → once per order.
E-mail + in-app notice to the recipients (`notifyMdAndAdmin`-style helper in check_alerts).

## 5. Tests (both stacks): `SalesTargetsTest` / `salesTargets.test.js`
Periods; confirmation dates (advance, LC, po_pi); containers min 1; corrections (cancel same period → 0; later
period → −n; shipped less); new buyer; credit override; company target off/break-even/profit; avg margin none →
no history; build-up factors; who sees what (salesperson own only, no money; non-holder 403 on /sales-targets;
associate nothing); calculator inputs have no name attribute; Sales Performance default output identical;
Mark Lost optional category; joined_on save; alert recipients defaults/save; seeded permission.
