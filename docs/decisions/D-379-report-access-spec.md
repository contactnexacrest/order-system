# D-379 Report access per role — build specification (owner approved 5 mockups + plan, 15 Nov 2026)

Build in PHP first (`PHP_MySQL_Version/app`), then the Node twin must behave identically. Standard and Kanban
templates (the Reports page and settings tabs have one view each). No PII; MySQL 5.7-safe SQL; plain-ALTER /
WHERE NOT EXISTS migration (D-371) + check-script row; browser validation + placeholders; reasons via
ReasonValidator. **No change** to the order page, documents/PDF/DOCX, Zoho, money rules, CAFIN rule (never to
clients), the client or associate portal.

## 1. Report registry (one list, same keys both stacks) — `ReportAccess::REPORTS`

| key | label | section | existing route(s) | base permission(s) kept as ceiling |
|---|---|---|---|---|
| order_search | Find an order | sales | /reports?q= | view_reports |
| aggregate | Aggregate report (+ saved reports) | sales | /reports/aggregate, /reports/saved/* | view_reports + money selling |
| per_client | Per-client report | sales | /reports/client/{id} | view_reports + selling + buyer_payments |
| performance | Sales performance | sales | /reports/performance | view_reports + selling |
| trends | Trends | sales | /reports/trends | view_reports + selling |
| conversion | Conversion rate | sales | /reports/conversion | view_reports |
| product_sales | Product sales | sales | /reports/products | view_reports + selling |
| payments | Payments | payments | /reports/payments | view_reports + buyer_payments |
| todays_payments | Today's payments | payments | /reports/todays-payments | view_reports + buyer_payments |
| ageing | Debtors / receivables ageing | payments | /reports/ageing | view_reports + buyer_payments |
| missing_ebrc | Missing eBRC / FIRC | payments | /reports/missing-ebrc | any of ca_module_view, manage_export_benefit_claims, manage_order_financials |
| disputes | Disputes | disputes | /reports/disputes | view_reports |
| amendments | Amendments | disputes | /reports/amendments | view_reports + selling |
| freight_cost | Freight cost | freight | /reports/freight-cost | view_logistics_costs |
| supplier_performance | Supplier performance | freight | /reports/suppliers | view_purchase_prices |
| staff_productivity | Staff productivity | people | /reports/staff | view_staff_reports |
| incentives | Incentives & commission | people | /reports/incentives, /incentives/to-pay, /incentives/owed, /incentives/statement | any of manage_incentives, pay_incentives, view_price_buildup |
| sales_targets | Sales targets | people | /sales-targets (+ team view) | Own: none; Department: none (read-only team view); All: manage_sales_targets |
| profitability | Order profitability | finance | /reports/order-profitability | manage_order_financials |
| cafin | CAFIN export | finance | /ca/cafin-export | view_cafin |
| ca_reports | CA reports | finance | /ca, /ca/reports and the other ca_module_view pages | ca_module_view |
| cockpit | Management cockpit | management | /cockpit | view_cockpit |
| queues | Operations queues | management | /reports/queues | view_reports |
| audit_log | Audit log | management | /audit-log | view_audit_log |

Sections (headings, in order): Sales & orders · Payments & receivables · Disputes & amendments · Freight, products
& suppliers · People · Finance & CA · Management.

Unchanged and outside this registry: the per-order Full Report (follows order access), /reports/associates
(manage_associates), /my-incentives (own), /associates/tds, /import/reports, /merchant/reports.

**Effective access** = the role's level for the report, raised by the person's extra (if higher), AND the base
permission(s) (money permissions remain a ceiling — never bypassed). For people with several roles (department
memberships), take the highest level across their roles (same as `SopAccessService::roleIdsFor`).
Effective Super Admin = All for everything (as today).

## 2. Levels and scope

`off` hidden from menu and Reports page; route returns 403 (same refusal as PermissionCheck) incl. CSV.
`own` orders where the viewer is in `order_assignees`, or is a department owner in `order_department_owners`, or is
`orders.sales_credit_user_id`; client lists = clients of those orders; incentives = own only.
`department` orders where any assignee or any department owner is a current member (active
`user_department_memberships`) of any department the viewer currently belongs to; plus own.
`all` every order (division filter + test-data flag as today).
Reports without order rows (Staff productivity: department = people in the viewer's departments; Audit log: own =
the viewer's own actions, department = actions of people in their departments; Cockpit: department = rows of
their departments only; Sales targets: own = Dashboard box only (no menu), department = read-only team view of
department members, all = the full page).
Implement one helper `ReportAccess::scopeSql(string $report, string $orderAlias = 'o'): array{sql,params}` and use it
in every report query (closing today's gaps: performance, trends, conversion, product_sales, ageing, queues,
order_search, missing_ebrc, supplier_performance, staff_productivity, incentives are currently unscoped).

## 3. Data (migration `docs/migrations/2026-11-15b-report-access.sql`)

- `role_report_access` (role_id BIGINT UNSIGNED, report_key VARCHAR(40), level ENUM('off','own','department','all'),
  updated_by, updated_at, PRIMARY KEY (role_id, report_key)).
- `user_report_extras` (user_id, report_key, level ENUM('own','department','all'), reason VARCHAR(500) NOT NULL,
  created_by, created_at, PRIMARY KEY (user_id, report_key)).
- A role with no row for a report = `off`. Seed defaults below (INSERT … SELECT … WHERE NOT EXISTS by role name).
- New permission `manage_report_access` ("Set which reports each role may open and how far" — MD, ED,
  Founder & MD/ED). Admin does not get it by default.

## 4. Defaults (A = all, D = department, O = own, blank = off)

| report | MD, Founder&MD, ED, Founder&ED | Admin | System Administrator | Sales Director | Sales/Export Manager | Export Executive | Finance Director | Finance Manager | Accounts Executive | CA / Chartered Accountant | Procurement Director | Procurement Manager | Procurement Executive | Logistics Director | Logistics Manager | Logistics Executive | Export Compliance Director | Export Compliance Manager | Export Compliance Executive | Order Coordinator | Operations Manager | Viewer / Auditor | Investor (view only) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| order_search | A | A | | A | D | O | A | A | A | A | D | D | O | D | D | O | | | | A | A | A | |
| aggregate | A | | | A | D | O | A | A | A | A | | | | | | | | | | | | A | |
| per_client | A | | | A | D | O | A | A | A | A | | | | | | | | | | | | A | |
| performance | A | | | A | D | O | A | A | | | | | | | | | | | | | | A | A |
| trends | A | | | A | D | O | A | A | | | | | | | | | | | | | | A | A |
| conversion | A | | | A | D | O | A | A | | | | | | | | | | | | | | A | A |
| product_sales | A | | | A | D | O | A | A | | | | | | | | | | | | | | A | |
| payments | A | | | A | D | O | A | A | A | A | | | | | | | | | | | | A | |
| todays_payments | A | | | A | D | O | A | A | A | A | | | | | | | | | | | | A | |
| ageing | A | | | A | D | O | A | A | A | A | | | | | | | | | | | | A | |
| missing_ebrc | A | | | | | | A | A | A | A | | | | | | | A | A | A | | | A | |
| disputes | A | | | A | D | O | A | A | | | | | | D | D | O | | | | A | A | A | |
| amendments | A | | | A | D | O | A | A | | | | | | | | | | | | | | A | |
| freight_cost | A | | | | | | A | A | A | A | | | | A | D | O | | | | | | A | |
| supplier_performance | A | | | | | | A | A | | | A | D | O | | | | | | | | | A | |
| staff_productivity | A | | | A | D | | A | | | | D | | | D | | | D | | | | | | |
| incentives | A | | | | | | A | A | | | | | | | | | | | | | | | |
| sales_targets | A | | | D | O | O | | | | | | | | | | | | | | | | | |
| profitability | A | | | | | | A | A | A | A | | | | | | | | | | | | A | |
| cafin | A | | | | | | A | A | | A | | | | | | | | | | | | | |
| ca_reports | A | | | | | | A | A | A | A | | | | | | | | | | | | A | |
| cockpit | A | A | | A | | | A | | | | A | | | A | | | A | | | A | A | | A |
| queues | A | A | | A | D | | A | | | | A | | | A | | | A | | | A | A | | A |
| audit_log | A | A | A | | | | | | | | | | | | | | | | | | | A | |

Roles not listed: off for everything. (Base permissions still apply: e.g. a level of A on freight_cost without
view_logistics_costs is still refused — the Reports tab shows "needs <permission>: missing for this role".)

## 5. Screens

1. **Users & roles › Roles and permissions › <role> › Reports tab** (`manage_report_access`): grouped table, a
   4-way radio per report (Off/Own/Department/All, compact, no extra label per D-366), money/base-permission column
   ("needs: selling prices" / "missing for this role" warning), reason (required), Save → audit
   REPORT_ACCESS_CHANGED (one row per changed report: old → new). Buttons: "See Reports as this role" (renders the
   Reports page as that role would see it, read-only, banner "Preview as <role>") and "Reset to defaults" (POST,
   reason required).
2. **Reports page** `/reports`: 7 section boxes with only the reports the viewer may open (button text = label);
   banner "Showing your own business — orders you are assigned to or own, your clients and your own figures." when
   every visible order-based report is Own; "Showing your department's orders" when the highest is Department.
   Route gate for /reports: at least one report not off (instead of view_reports).
3. **Edit user › Extra report access for this person** (`manage_report_access`): list (report, role gives, person
   gets, reason, set by/on, Remove), add form (report select "Choose…", level select offering only levels above
   the role's, reason required). Audit USER_REPORT_EXTRA_ADDED / _REMOVED.
4. **Sidebar** (`src/Config/navigation.json`, byte-identical in both stacks): Overview group gets "Sales targets"
   (href /sales-targets) after Reports, shown when the effective sales_targets level is department or all.
   Reports entries ("All reports", "Freight cost", "Supplier report", "Management cockpit", "Audit log") are shown
   by effective access, not just the old permission (Navigation gets a flag/when type for report access, e.g.
   `"when": {"report": "freight_cost"}` — implement in both Navigation services and keep navigationConfig tests
   green).
5. **Sales targets team view**: /sales-targets for department level = read-only table of members of the viewer's
   departments (target, confirmed, shipped, pipeline, new buyers, progress) — no Edit, no money, no calculator,
   no associates, no settings link. All level = today's page (still needs manage_sales_targets to change anything).

## 6. Tests (both stacks) `ReportAccessTest` / `reportAccess.test.js`
Defaults seeded per role (spot-check the table); Own/Department/All row filtering on aggregate, performance,
ageing, order_search and disputes (CSV too); off → 403 by direct URL; base permission still required; extras raise
only (lower level refused by the form); highest level across memberships; Admin sees Management only; Investor
cannot open per_client; Reports page shows only allowed sections + banner; menu shows Sales targets for MD/ED and
Sales Director only; team view has no money/Edit; preview renders as the role; reset to defaults.
Update existing route/permission/inventory tests (RouteMatrix, RouteBehaviour, NavLinksMatchRoutePermissions,
navigationMenuD320, QaUiBatchNov13 / e2e qa-inventory.json, PermittedLinks) for the new rules.
