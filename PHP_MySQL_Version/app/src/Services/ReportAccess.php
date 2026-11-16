<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\ReportAccessRepository;

/**
 * Owner decision D-379 (15 Nov 2026): report access per role.
 *
 * One registry of reports (REPORTS, the same keys in both stacks). Each role
 * has a level per report — off / own / department / all — kept in
 * role_report_access (no row = off); a person can get a higher level for one
 * report (user_report_extras, with a reason). The effective level of a
 * person is:
 *
 *   - an effective Super Admin: all, for every report (not while previewing a designation);
 *   - else the highest level over their own role, the role they are acting as and every
 *     role of a current department membership (as SopAccessService::roleIdsFor), raised by
 *     their extra when that is higher;
 *   - capped by the report's base permission(s): a level is only worth something with the
 *     permission(s) of the old gate, and money permissions are never bypassed
 *     (missingPermissions()). Without them the level is off.
 *
 * Levels limit rows through scopeSql():
 *   own        orders where the viewer is an assignee (order_assignees), a department owner
 *              (order_department_owners) or the salesperson credited (orders.sales_credit_user_id);
 *   department own, plus orders where any assignee or department owner is a current member of a
 *              department the viewer currently belongs to;
 *   all        every order (division filter, archived rule and test-data flag as before).
 * Reports without order rows use peopleScope() (Staff productivity, Audit log, Incentives) or
 * departmentCodes() (Cockpit).
 *
 * Node twin: NODE_MySQL_Version/app/src/services/reportAccess.js (same registry, keys, defaults,
 * wording and rules).
 */
final class ReportAccess
{
    public const OFF = 'off';
    public const OWN = 'own';
    public const DEPARTMENT = 'department';
    public const ALL = 'all';

    /** In rank order. */
    public const LEVELS = [self::OFF, self::OWN, self::DEPARTMENT, self::ALL];

    public const LEVEL_LABELS = [self::OFF => 'Off', self::OWN => 'Own', self::DEPARTMENT => 'Department', self::ALL => 'All'];

    /** D-379 cosmetic follow-up (16 Nov 2026): the pill beside a report on the Reports page (none for All). */
    public const TAG_LABELS = [self::OWN => 'Your orders', self::DEPARTMENT => 'Your department'];

    /** The 7 headings of the Reports page and of the Reports tab, in order. */
    public const SECTIONS = [
        'sales' => 'Sales & orders',
        'payments' => 'Payments & receivables',
        'disputes' => 'Disputes & amendments',
        'freight' => 'Freight, products & suppliers',
        'people' => 'People',
        'finance' => 'Finance & CA',
        'management' => 'Management',
    ];

    public const BANNER_OWN = 'Showing your own business — orders you are assigned to or own, your clients and your own figures.';
    public const BANNER_DEPARTMENT = "Showing your department's orders";
    public const REFUSED = 'This report is not open to you. Ask whoever manages report access (Users & roles › Roles and permissions › Reports) if you need it.';

    /**
     * The registry. Per report:
     *  label, section, href (the button on the Reports page; null = drawn on the page itself),
     *  all  = permissions all needed (the old route gate), any = at least one of these,
     *  money = MoneyVisibility classes all needed, orders = rows are orders (scopeSql),
     *  all_level_needs = extra permissions only the All level needs (Sales targets),
     *  page_min = the lowest level that opens a page (Sales targets: Own is the Dashboard box only),
     *  description = the line under the button.
     */
    public const REPORTS = [
        'order_search' => ['label' => 'Find an order', 'section' => 'sales', 'href' => null, 'all' => ['view_reports'], 'any' => [], 'money' => [], 'orders' => true,
            'description' => "Search by order reference or client name and open that order's Full Report."],
        'aggregate' => ['label' => 'Aggregate report', 'section' => 'sales', 'href' => '/reports/aggregate', 'all' => ['view_reports'], 'any' => [], 'money' => ['selling'], 'orders' => true,
            'description' => 'Filter orders by date range, stage, Incoterm or country; save the filters as a report.'],
        'per_client' => ['label' => 'Per-client report', 'section' => 'sales', 'href' => null, 'all' => ['view_reports'], 'any' => [], 'money' => ['selling', 'buyer_payments'], 'orders' => true,
            'description' => 'One client: orders, documents, payments and products.'],
        'performance' => ['label' => 'Sales performance', 'section' => 'sales', 'href' => '/reports/performance', 'all' => ['view_reports'], 'any' => [], 'money' => ['selling'], 'orders' => true,
            'description' => 'Quotations and PIs sent, won and lost, FOB value won, compared with the period before.'],
        'trends' => ['label' => 'Trends', 'section' => 'sales', 'href' => '/reports/trends', 'all' => ['view_reports'], 'any' => [], 'money' => ['selling'], 'orders' => true,
            'description' => 'Orders created, quotations and PIs sent, lost and FOB value, month over month.'],
        'conversion' => ['label' => 'Conversion rate', 'section' => 'sales', 'href' => '/reports/conversion', 'all' => ['view_reports'], 'any' => [], 'money' => [], 'orders' => true,
            'description' => 'The quotation and PI funnel as percentages.'],
        'product_sales' => ['label' => 'Product sales', 'section' => 'sales', 'href' => '/reports/products', 'all' => ['view_reports'], 'any' => [], 'money' => ['selling'], 'orders' => true,
            'description' => 'Sales by product and HS code.'],
        'payments' => ['label' => 'Payments', 'section' => 'payments', 'href' => '/reports/payments', 'all' => ['view_reports'], 'any' => [], 'money' => ['buyer_payments'], 'orders' => true,
            'description' => 'Collected and outstanding by currency — advance, balance and freight.'],
        'todays_payments' => ['label' => "Today's payments", 'section' => 'payments', 'href' => '/reports/todays-payments', 'all' => ['view_reports'], 'any' => [], 'money' => ['buyer_payments'], 'orders' => true,
            'description' => 'What cleared or was paid on one day, incoming and outgoing.'],
        'ageing' => ['label' => 'Debtors / receivables ageing', 'section' => 'payments', 'href' => '/reports/ageing', 'all' => ['view_reports'], 'any' => [], 'money' => ['buyer_payments'], 'orders' => true,
            'description' => 'Every outstanding leg by how overdue it is.'],
        'missing_ebrc' => ['label' => 'Missing eBRC / FIRC', 'section' => 'payments', 'href' => '/reports/missing-ebrc', 'all' => [], 'any' => ['ca_module_view', 'manage_export_benefit_claims', 'manage_order_financials'], 'money' => [], 'orders' => true,
            'description' => 'Buyer payments received with no FIRC / eBRC reference yet.'],
        'disputes' => ['label' => 'Disputes', 'section' => 'disputes', 'href' => '/reports/disputes', 'all' => ['view_reports'], 'any' => [], 'money' => [], 'orders' => true,
            'description' => 'Disputes by status and age.'],
        'amendments' => ['label' => 'Amendments', 'section' => 'disputes', 'href' => '/reports/amendments', 'all' => ['view_reports'], 'any' => [], 'money' => ['selling'], 'orders' => true,
            'description' => 'Amendments by status and who asked for them.'],
        'freight_cost' => ['label' => 'Freight cost', 'section' => 'freight', 'href' => '/reports/freight-cost', 'all' => ['view_logistics_costs'], 'any' => [], 'money' => [], 'orders' => true,
            'description' => 'Freight cost by forwarder.'],
        'supplier_performance' => ['label' => 'Supplier performance', 'section' => 'freight', 'href' => '/reports/suppliers', 'all' => ['view_purchase_prices'], 'any' => [], 'money' => [], 'orders' => true,
            'description' => 'Supplier PO signing and delivery on time.'],
        'staff_productivity' => ['label' => 'Staff productivity', 'section' => 'people', 'href' => '/reports/staff', 'all' => ['view_staff_reports'], 'any' => [], 'money' => [], 'orders' => false,
            'description' => 'Documents generated and audit-log activity per person.'],
        'incentives' => ['label' => 'Incentives & commission', 'section' => 'people', 'href' => '/reports/incentives', 'all' => [], 'any' => ['manage_incentives', 'pay_incentives', 'view_price_buildup'], 'money' => [], 'orders' => false,
            'description' => 'Staff shares and associate commission per order — register, to pay, owed at month end and one person\'s statement.'],
        'sales_targets' => ['label' => 'Sales targets', 'section' => 'people', 'href' => '/sales-targets', 'all' => [], 'any' => [], 'money' => [], 'orders' => false,
            'all_level_needs' => ['manage_sales_targets'], 'page_min' => self::DEPARTMENT,
            'description' => 'Targets and progress per person, in containers.'],
        'profitability' => ['label' => 'Order profitability', 'section' => 'finance', 'href' => '/reports/order-profitability', 'all' => ['manage_order_financials'], 'any' => [], 'money' => [], 'orders' => true,
            'description' => 'Revenue against every direct cost, per order, for any period.'],
        'cafin' => ['label' => 'CAFIN export', 'section' => 'finance', 'href' => '/ca/cafin-export', 'all' => ['view_cafin'], 'any' => [], 'money' => [], 'orders' => true,
            'description' => 'The CA Financial Annexure of a financial year as CSV. Internal only — never for the client.'],
        'ca_reports' => ['label' => 'CA reports', 'section' => 'finance', 'href' => '/ca', 'all' => ['ca_module_view'], 'any' => [], 'money' => [], 'orders' => true,
            'description' => 'The CA / Accounting module: settlement register, revenue report, export benefits and the company-level CA pages.'],
        'cockpit' => ['label' => 'Management cockpit', 'section' => 'management', 'href' => '/cockpit', 'all' => ['view_cockpit'], 'any' => [], 'money' => [], 'orders' => false,
            'description' => 'Open work per department, escalations and supplier delays.'],
        'queues' => ['label' => 'Operations queues', 'section' => 'management', 'href' => '/reports/queues', 'all' => ['view_reports'], 'any' => [], 'money' => [], 'orders' => true,
            'description' => 'How many orders wait in each queue now, and the funnel for a date range.'],
        'audit_log' => ['label' => 'Audit log', 'section' => 'management', 'href' => '/audit-log', 'all' => ['view_audit_log'], 'any' => [], 'money' => [], 'orders' => false,
            'description' => 'Who did what, when — read-only.'],
    ];

    /** The four role names the "MD" column of the defaults covers. */
    public const MD_ROLE_NAMES = ['Managing Director', 'Founder & Managing Director', 'Executive Director', 'Founder & Executive Director'];

    /**
     * Spec D-379 §4 — the seeded default level per role (docs/seed.sql, migration 2026-11-15b).
     * Key 'MD' = every name in MD_ROLE_NAMES. A role not listed, or a report not listed for a role: off.
     */
    public const DEFAULTS = [
        'MD' => ['order_search' => 'all', 'aggregate' => 'all', 'per_client' => 'all', 'performance' => 'all', 'trends' => 'all', 'conversion' => 'all', 'product_sales' => 'all', 'payments' => 'all', 'todays_payments' => 'all', 'ageing' => 'all', 'missing_ebrc' => 'all', 'disputes' => 'all', 'amendments' => 'all', 'freight_cost' => 'all', 'supplier_performance' => 'all', 'staff_productivity' => 'all', 'incentives' => 'all', 'sales_targets' => 'all', 'profitability' => 'all', 'cafin' => 'all', 'ca_reports' => 'all', 'cockpit' => 'all', 'queues' => 'all', 'audit_log' => 'all'],
        'Admin' => ['order_search' => 'all', 'cockpit' => 'all', 'queues' => 'all', 'audit_log' => 'all'],
        'System Administrator' => ['audit_log' => 'all'],
        'Sales Director' => ['order_search' => 'all', 'aggregate' => 'all', 'per_client' => 'all', 'performance' => 'all', 'trends' => 'all', 'conversion' => 'all', 'product_sales' => 'all', 'payments' => 'all', 'todays_payments' => 'all', 'ageing' => 'all', 'disputes' => 'all', 'amendments' => 'all', 'staff_productivity' => 'all', 'sales_targets' => 'department', 'cockpit' => 'all', 'queues' => 'all'],
        'Sales/Export Manager' => ['order_search' => 'department', 'aggregate' => 'department', 'per_client' => 'department', 'performance' => 'department', 'trends' => 'department', 'conversion' => 'department', 'product_sales' => 'department', 'payments' => 'department', 'todays_payments' => 'department', 'ageing' => 'department', 'disputes' => 'department', 'amendments' => 'department', 'staff_productivity' => 'department', 'sales_targets' => 'own', 'queues' => 'department'],
        'Export Executive' => ['order_search' => 'own', 'aggregate' => 'own', 'per_client' => 'own', 'performance' => 'own', 'trends' => 'own', 'conversion' => 'own', 'product_sales' => 'own', 'payments' => 'own', 'todays_payments' => 'own', 'ageing' => 'own', 'disputes' => 'own', 'amendments' => 'own', 'sales_targets' => 'own'],
        'Finance Director' => ['order_search' => 'all', 'aggregate' => 'all', 'per_client' => 'all', 'performance' => 'all', 'trends' => 'all', 'conversion' => 'all', 'product_sales' => 'all', 'payments' => 'all', 'todays_payments' => 'all', 'ageing' => 'all', 'missing_ebrc' => 'all', 'disputes' => 'all', 'amendments' => 'all', 'freight_cost' => 'all', 'supplier_performance' => 'all', 'staff_productivity' => 'all', 'incentives' => 'all', 'profitability' => 'all', 'cafin' => 'all', 'ca_reports' => 'all', 'cockpit' => 'all', 'queues' => 'all'],
        'Finance Manager' => ['order_search' => 'all', 'aggregate' => 'all', 'per_client' => 'all', 'performance' => 'all', 'trends' => 'all', 'conversion' => 'all', 'product_sales' => 'all', 'payments' => 'all', 'todays_payments' => 'all', 'ageing' => 'all', 'missing_ebrc' => 'all', 'disputes' => 'all', 'amendments' => 'all', 'freight_cost' => 'all', 'supplier_performance' => 'all', 'incentives' => 'all', 'profitability' => 'all', 'cafin' => 'all', 'ca_reports' => 'all'],
        'Accounts Executive' => ['order_search' => 'all', 'aggregate' => 'all', 'per_client' => 'all', 'payments' => 'all', 'todays_payments' => 'all', 'ageing' => 'all', 'missing_ebrc' => 'all', 'freight_cost' => 'all', 'profitability' => 'all', 'ca_reports' => 'all'],
        'CA / Chartered Accountant' => ['order_search' => 'all', 'aggregate' => 'all', 'per_client' => 'all', 'payments' => 'all', 'todays_payments' => 'all', 'ageing' => 'all', 'missing_ebrc' => 'all', 'freight_cost' => 'all', 'profitability' => 'all', 'cafin' => 'all', 'ca_reports' => 'all'],
        'Procurement Director' => ['order_search' => 'department', 'supplier_performance' => 'all', 'staff_productivity' => 'department', 'cockpit' => 'all', 'queues' => 'all'],
        'Procurement Manager' => ['order_search' => 'department', 'supplier_performance' => 'department'],
        'Procurement Executive' => ['order_search' => 'own', 'supplier_performance' => 'own'],
        'Logistics Director' => ['order_search' => 'department', 'disputes' => 'department', 'freight_cost' => 'all', 'staff_productivity' => 'department', 'cockpit' => 'all', 'queues' => 'all'],
        'Logistics Manager' => ['order_search' => 'department', 'disputes' => 'department', 'freight_cost' => 'department'],
        'Logistics Executive' => ['order_search' => 'own', 'disputes' => 'own', 'freight_cost' => 'own'],
        'Export Compliance Director' => ['missing_ebrc' => 'all', 'staff_productivity' => 'department', 'cockpit' => 'all', 'queues' => 'all'],
        'Export Compliance Manager' => ['missing_ebrc' => 'all'],
        'Export Compliance Executive' => ['missing_ebrc' => 'all'],
        'Order Coordinator' => ['order_search' => 'all', 'disputes' => 'all', 'cockpit' => 'all', 'queues' => 'all'],
        'Operations Manager' => ['order_search' => 'all', 'disputes' => 'all', 'cockpit' => 'all', 'queues' => 'all'],
        'Viewer / Auditor' => ['order_search' => 'all', 'aggregate' => 'all', 'per_client' => 'all', 'performance' => 'all', 'trends' => 'all', 'conversion' => 'all', 'product_sales' => 'all', 'payments' => 'all', 'todays_payments' => 'all', 'ageing' => 'all', 'missing_ebrc' => 'all', 'disputes' => 'all', 'amendments' => 'all', 'freight_cost' => 'all', 'supplier_performance' => 'all', 'profitability' => 'all', 'ca_reports' => 'all', 'audit_log' => 'all'],
        'Investor (view only)' => ['performance' => 'all', 'trends' => 'all', 'conversion' => 'all', 'cockpit' => 'all', 'queues' => 'all'],
    ];

    /**
     * D-379 cosmetic follow-up (16 Nov 2026): what each permission is called in the Reports tab's
     * "Needs" column — the name of the permission in the permissions table (Roles and permissions).
     */
    private const PERMISSION_NAMES = [
        'view_reports' => 'View reports',
        'view_staff_reports' => 'View staff productivity reports',
        'manage_order_financials' => 'Manage order financials',
        'view_cafin' => 'View & download CAFIN',
        'ca_module_view' => 'View CA / Accounting module',
        'view_cockpit' => 'Management cockpit',
        'view_audit_log' => 'View audit log',
        'manage_sales_targets' => 'Manage sales targets',
    ];

    /** Money permissions in plain words ("see buyer payments"). */
    private const MONEY_WORDS = [
        'view_selling_prices' => 'see selling prices',
        'view_buyer_payments' => 'see buyer payments',
        'view_purchase_prices' => 'see purchase prices',
        'view_logistics_costs' => 'see logistics costs',
    ];

    /** Short words for an "any of" group ("CA module, export benefit claims or order financials"). */
    private const ANY_WORDS = [
        'ca_module_view' => 'CA module',
        'manage_export_benefit_claims' => 'export benefit claims',
        'manage_order_financials' => 'order financials',
        'manage_incentives' => 'manage team incentives',
        'pay_incentives' => 'pay incentives',
        'view_price_buildup' => 'see the full price build-up',
    ];

    /** @var array<int, array<string,string>> role id => report key => level (one request) */
    private static array $roleLevels = [];
    /** @var array<int, array<string, array<string,mixed>>> user id => report key => extra row */
    private static array $extras = [];
    /** @var array<int, list<int>> user id => department ids */
    private static array $departments = [];
    /** @var array<string, list<int>> "1,2" => member user ids */
    private static array $members = [];
    /** The role "See Reports as this role" shows (null = the signed-in person). */
    private static ?int $previewRoleId = null;
    /** @var array<int, array<string,bool>> role id => permission key => true (preview only) */
    private static array $rolePermissions = [];
    private static int $paramSeq = 0;
    /** @var array<string,string> "user|role|key" => effective level (one request; resetCache() clears it) */
    private static array $levelCache = [];
    /** @var array<string, list<int>> "user|role" => the roles that count (one request) */
    private static array $roleIdsCache = [];
    /** @var array<int,bool> user id => effective Super Admin (one request) */
    private static array $superCache = [];

    public static function resetCache(): void
    {
        self::$levelCache = [];
        self::$superCache = [];
        self::$roleIdsCache = [];
        self::$roleLevels = [];
        self::$extras = [];
        self::$departments = [];
        self::$members = [];
        self::$rolePermissions = [];
    }

    // ================================================================ registry helpers

    public static function isReport(string $key): bool
    {
        return isset(self::REPORTS[$key]);
    }

    public static function isLevel(string $level): bool
    {
        return in_array($level, self::LEVELS, true);
    }

    public static function rank(string $level): int
    {
        $i = array_search($level, self::LEVELS, true);
        return $i === false ? 0 : (int) $i;
    }

    public static function higher(string $a, string $b): string
    {
        return self::rank($a) >= self::rank($b) ? $a : $b;
    }

    /** The lowest level that opens the report's page (Sales targets: Department; Own is its Dashboard box). */
    public static function pageMin(string $key): string
    {
        return (string) (self::REPORTS[$key]['page_min'] ?? self::OWN);
    }

    /** @return array<string, list<string>> section key => report keys, in registry order */
    public static function bySection(): array
    {
        $out = array_fill_keys(array_keys(self::SECTIONS), []);
        foreach (self::REPORTS as $key => $r) {
            $out[$r['section']][] = $key;
        }
        return $out;
    }

    /** @return array<string,string> report key => default level for this role name (off for anything not listed) */
    public static function defaultsForRoleName(string $roleName): array
    {
        $group = in_array($roleName, self::MD_ROLE_NAMES, true) ? 'MD' : $roleName;
        $set = self::DEFAULTS[$group] ?? [];
        $out = [];
        foreach (array_keys(self::REPORTS) as $key) {
            $out[$key] = (string) ($set[$key] ?? self::OFF);
        }
        return $out;
    }

    // ================================================================ levels

    /** @return array<string,string> every report key => this role's stored level (no row = off) */
    public static function roleLevels(int $roleId): array
    {
        if (!isset(self::$roleLevels[$roleId])) {
            $stored = ReportAccessRepository::levelsForRole($roleId);
            $out = [];
            foreach (array_keys(self::REPORTS) as $key) {
                $out[$key] = isset($stored[$key]) && self::isLevel($stored[$key]) ? $stored[$key] : self::OFF;
            }
            self::$roleLevels[$roleId] = $out;
        }
        return self::$roleLevels[$roleId];
    }

    public static function roleLevel(int $roleId, string $key): string
    {
        return self::roleLevels($roleId)[$key] ?? self::OFF;
    }

    /** @return array<string, array<string,mixed>> report key => extra row (level, reason, created_by, created_by_name, created_at) */
    public static function userExtras(int $userId): array
    {
        if (!isset(self::$extras[$userId])) {
            self::$extras[$userId] = ReportAccessRepository::extrasForUser($userId);
        }
        return self::$extras[$userId];
    }

    /**
     * The roles whose levels count for this person: their own role, the role they act as and
     * every role of a current department membership — only the previewed one during a designation preview.
     *
     * @return list<int>
     */
    public static function roleIdsFor(int $userId, ?int $roleId): array
    {
        $previewing = ActiveDepartmentService::isPreviewing($userId);
        $acting = ActiveDepartmentService::effectiveRoleId($userId, $roleId);
        $cacheKey = $userId . '|' . ($roleId ?? '-') . '|' . ($acting ?? '-') . ($previewing ? '|preview' : '');
        if (!isset(self::$roleIdsCache[$cacheKey])) {
            $ids = $previewing ? [] : SopAccessService::roleIdsFor($userId, $roleId);
            if ($acting !== null) {
                $ids[] = $acting;
            }
            self::$roleIdsCache[$cacheKey] = array_values(array_unique(array_map('intval', $ids)));
        }
        return self::$roleIdsCache[$cacheKey];
    }

    /** The level from the roles and the person's extra, before the base-permission ceiling. */
    public static function rawLevelFor(int $userId, ?int $roleId, string $key): string
    {
        $level = self::OFF;
        foreach (self::roleIdsFor($userId, $roleId) as $rid) {
            $level = self::higher(self::roleLevel($rid, $key), $level);
        }
        if (!ActiveDepartmentService::isPreviewing($userId)) {
            $extra = self::userExtras($userId)[$key]['level'] ?? null;
            if (is_string($extra) && self::isLevel($extra)) {
                $level = self::higher($extra, $level);
            }
        }
        return $level;
    }

    /**
     * The permissions a level of this report needs that $can does not give (empty = none missing).
     * "any" permissions come back as one entry "a or b or c".
     *
     * @param callable(string):bool $can
     * @return list<string>
     */
    public static function missingPermissions(callable $can, string $key, string $level = self::OWN): array
    {
        $r = self::REPORTS[$key] ?? null;
        if ($r === null) {
            return [];
        }
        $missing = [];
        foreach ($r['all'] as $perm) {
            if (!$can($perm)) {
                $missing[] = $perm;
            }
        }
        if ($r['any'] !== []) {
            $ok = false;
            foreach ($r['any'] as $perm) {
                $ok = $ok || $can($perm);
            }
            if (!$ok) {
                $missing[] = implode(' or ', $r['any']);
            }
        }
        foreach ($r['money'] as $class) {
            $perm = MoneyVisibility::PERMISSIONS[$class] ?? null;
            if ($perm !== null && !$can($perm)) {
                $missing[] = $perm;
            }
        }
        if ($level === self::ALL) {
            foreach ($r['all_level_needs'] ?? [] as $perm) {
                if (!$can($perm)) {
                    $missing[] = $perm;
                }
            }
        }
        return $missing;
    }

    /** One permission in plain words: money as "see …", others as '"Name" permission'. */
    private static function permissionWords(string $perm): string
    {
        if (isset(self::MONEY_WORDS[$perm])) {
            return self::MONEY_WORDS[$perm];
        }
        return '"' . (self::PERMISSION_NAMES[$perm] ?? $perm) . '" permission';
    }

    /** "a, b or c" */
    private static function orList(array $words): string
    {
        $last = array_pop($words);
        return $words === [] ? (string) $last : implode(', ', $words) . ' or ' . $last;
    }

    /**
     * D-379 cosmetic follow-up (16 Nov 2026): the Reports tab's "Needs" column in plain words, one entry
     * per need: text, has = the role holds it (shown ✓ in green), missing = the level set here opens
     * nothing without it (shown ⚠ "missing for this role" in red) — the same rule as missingPermissions().
     *
     * @param callable(string):bool $can
     * @return list<array{text:string, has:bool, missing:bool}>
     */
    public static function needsItems(callable $can, string $key, string $level): array
    {
        $r = self::REPORTS[$key];
        $on = $level !== self::OFF;
        $items = [];
        foreach ($r['all'] as $perm) {
            $has = $can($perm);
            $items[] = ['text' => self::permissionWords($perm), 'has' => $has, 'missing' => $on && !$has];
        }
        if ($r['any'] !== []) {
            $has = false;
            $words = [];
            foreach ($r['any'] as $perm) {
                $has = $has || $can($perm);
                $words[] = self::ANY_WORDS[$perm] ?? (self::PERMISSION_NAMES[$perm] ?? $perm);
            }
            $items[] = ['text' => self::orList($words), 'has' => $has, 'missing' => $on && !$has];
        }
        foreach ($r['money'] as $class) {
            $perm = MoneyVisibility::PERMISSIONS[$class];
            $has = $can($perm);
            $items[] = ['text' => self::permissionWords($perm), 'has' => $has, 'missing' => $on && !$has];
        }
        foreach ($r['all_level_needs'] ?? [] as $perm) {
            $has = $can($perm);
            $items[] = ['text' => 'All needs "' . (self::PERMISSION_NAMES[$perm] ?? $perm) . '"', 'has' => $has, 'missing' => $level === self::ALL && !$has];
        }
        return $items;
    }

    /** The "Needs" column as one line of plain words (no marks), e.g. '"View reports" permission, see selling prices'. */
    public static function needsText(string $key): string
    {
        $none = static fn (string $perm): bool => false;
        return implode(', ', array_map(static fn (array $i): string => $i['text'], self::needsItems($none, $key, self::OFF)));
    }

    /** Applies the base-permission ceiling to a raw level. */
    private static function capped(callable $can, string $key, string $level): string
    {
        if ($level === self::OFF) {
            return self::OFF;
        }
        if (self::missingPermissions($can, $key, self::OWN) !== []) {
            return self::OFF;
        }
        if ($level === self::ALL && self::missingPermissions($can, $key, self::ALL) !== []) {
            return self::DEPARTMENT; // Sales targets: All without manage_sales_targets is the read-only team view
        }
        return $level;
    }

    /** The effective level of a person for one report. */
    public static function effectiveLevelFor(int $userId, ?int $roleId, string $key): string
    {
        if (!self::isReport($key)) {
            return self::OFF;
        }
        $cacheKey = $userId . '|' . ($roleId ?? '-') . '|' . $key;
        if (isset(self::$levelCache[$cacheKey])) {
            return self::$levelCache[$cacheKey];
        }
        if (!ActiveDepartmentService::isPreviewing($userId) && (self::$superCache[$userId] ??= SuperAdminService::isEffective($userId))) {
            return self::$levelCache[$cacheKey] = self::ALL;
        }
        $can = static fn (string $perm): bool => PermissionService::can($userId, $roleId, $perm);
        return self::$levelCache[$cacheKey] = self::capped($can, $key, self::rawLevelFor($userId, $roleId, $key));
    }

    /** The effective level of a role alone (the Reports tab and "See Reports as this role"): its stored level and its own permissions. */
    public static function effectiveLevelForRole(int $roleId, string $key): string
    {
        if (!isset(self::$rolePermissions[$roleId])) {
            self::$rolePermissions[$roleId] = array_fill_keys(ReportAccessRepository::rolePermissionKeys($roleId), true);
        }
        $perms = self::$rolePermissions[$roleId];
        $can = static fn (string $perm): bool => isset($perms[$perm]);
        return self::capped($can, $key, self::roleLevel($roleId, $key));
    }

    /** "See Reports as this role": the Reports page asks for this role's levels until cleared (null). */
    public static function previewAsRole(?int $roleId): void
    {
        self::$previewRoleId = $roleId;
    }

    public static function previewRoleId(): ?int
    {
        return self::$previewRoleId;
    }

    /** The signed-in person's effective level (or the previewed role's). */
    public static function level(string $key): string
    {
        if (self::$previewRoleId !== null) {
            return self::effectiveLevelForRole(self::$previewRoleId, $key);
        }
        $user = AuthService::currentUser();
        if ($user === null) {
            return self::OFF;
        }
        return self::effectiveLevelFor((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, $key);
    }

    /** Whether the signed-in person's level for the report reaches $min. */
    public static function can(string $key, string $min = self::OWN): bool
    {
        return self::rank(self::level($key)) >= self::rank($min);
    }

    /** Whether the report's page opens for the signed-in person (Sales targets needs Department). */
    public static function canOpenPage(string $key): bool
    {
        return self::can($key, self::pageMin($key));
    }

    /** @return array<string,string> every report key => the signed-in person's effective level */
    public static function levels(): array
    {
        $out = [];
        foreach (array_keys(self::REPORTS) as $key) {
            $out[$key] = self::level($key);
        }
        return $out;
    }

    /** At least one report page opens (the gate of /reports). */
    public static function anyOpen(): bool
    {
        foreach (array_keys(self::REPORTS) as $key) {
            if (self::canOpenPage($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The facts the menu config asks about (navigation.json "when": {"report": key, "reportMin": level}):
     * "report:<key>" = the page opens, "report:<key>:<level>" = the level reaches it, "report:any".
     *
     * @return array<string,bool>
     */
    public static function navFlags(): array
    {
        $flags = ['report:any' => false];
        foreach (self::levels() as $key => $level) {
            $flags['report:' . $key] = self::rank($level) >= self::rank(self::pageMin($key));
            foreach ([self::OWN, self::DEPARTMENT, self::ALL] as $min) {
                $flags['report:' . $key . ':' . $min] = self::rank($level) >= self::rank($min);
            }
            $flags['report:any'] = $flags['report:any'] || $flags['report:' . $key];
        }
        return $flags;
    }

    /** The Reports page banner: own business when every open order report is Own, the department's when the highest is Department. */
    public static function banner(): ?string
    {
        $highest = null;
        $allOwn = true;
        foreach (self::REPORTS as $key => $r) {
            if (!$r['orders'] || !self::canOpenPage($key)) {
                continue;
            }
            $level = self::level($key);
            $highest = $highest === null ? $level : self::higher($level, $highest);
            $allOwn = $allOwn && $level === self::OWN;
        }
        if ($highest === null) {
            return null;
        }
        if ($allOwn) {
            return self::BANNER_OWN;
        }
        return $highest === self::DEPARTMENT ? self::BANNER_DEPARTMENT : null;
    }

    // ================================================================ scope

    /** @return list<int> the departments the person currently belongs to */
    public static function departmentIds(int $userId): array
    {
        if (!isset(self::$departments[$userId])) {
            self::$departments[$userId] = ReportAccessRepository::currentDepartmentIds($userId);
        }
        return self::$departments[$userId];
    }

    /** @return list<string> the codes of those departments (the Cockpit's department rows) */
    public static function departmentCodes(int $userId): array
    {
        return ReportAccessRepository::departmentCodes(self::departmentIds($userId));
    }

    /** @return list<int> every current member of the person's departments, the person included */
    public static function departmentMemberIds(int $userId): array
    {
        $ids = self::departmentIds($userId);
        $cacheKey = implode(',', $ids);
        if (!isset(self::$members[$cacheKey])) {
            self::$members[$cacheKey] = $ids === [] ? [] : ReportAccessRepository::currentMemberIds($ids);
        }
        $out = self::$members[$cacheKey];
        $out[] = $userId;
        return array_values(array_unique(array_map('intval', $out)));
    }

    /**
     * The condition limiting `orders {$orderAlias}` to the rows this report shows the signed-in person.
     * Nothing when nobody is signed in (cron, scripts). A level of off — a page the gate already
     * refuses — keeps the order-visibility rule used before D-379 (OrderAccessService).
     *
     * @return array{sql: string, params: array<string,int>} sql is " AND …" or ""
     */
    public static function scopeSql(string $report, string $orderAlias = 'o'): array
    {
        $user = AuthService::currentUser();
        if ($user === null) {
            return ['sql' => '', 'params' => []];
        }
        return self::scopeSqlFor((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, $report, $orderAlias);
    }

    /** @return array{sql: string, params: array<string,int>} */
    public static function scopeSqlFor(int $userId, ?int $roleId, string $report, string $orderAlias = 'o'): array
    {
        $a = $orderAlias;
        $level = self::effectiveLevelFor($userId, $roleId, $report);
        if ($level === self::OFF) {
            [$sql, $params] = OrderAccessService::visibilitySqlFor($userId, $roleId, $a);
            return ['sql' => $sql, 'params' => $params];
        }
        // As before D-379: the request's division(s), archived orders only with view_archived_orders.
        $sql = DivisionService::sql("{$a}.division_id");
        if (!PermissionService::can($userId, $roleId, 'view_archived_orders')) {
            $sql .= " AND {$a}.is_archived = 0";
        }
        if ($level === self::ALL) {
            return ['sql' => $sql, 'params' => []];
        }
        $n = ++self::$paramSeq;
        $p = "ra{$n}_";
        $conds = [
            "{$a}.id IN (SELECT {$p}oa.order_id FROM order_assignees {$p}oa WHERE {$p}oa.user_id = :{$p}u1)",
            "{$a}.id IN (SELECT {$p}odo.order_id FROM order_department_owners {$p}odo WHERE {$p}odo.user_id = :{$p}u2)",
            "{$a}.sales_credit_user_id = :{$p}u3",
        ];
        $params = ["{$p}u1" => $userId, "{$p}u2" => $userId, "{$p}u3" => $userId];
        if ($level === self::DEPARTMENT) {
            $members = self::departmentMemberIds($userId);
            if (count($members) > 1) {
                $in = implode(',', array_map('intval', $members)); // integers, safe to inline
                $conds[] = "{$a}.id IN (SELECT {$p}doa.order_id FROM order_assignees {$p}doa WHERE {$p}doa.user_id IN ({$in}))";
                $conds[] = "{$a}.id IN (SELECT {$p}dodo.order_id FROM order_department_owners {$p}dodo WHERE {$p}dodo.user_id IN ({$in}))";
            }
        }
        return ['sql' => $sql . ' AND (' . implode(' OR ', $conds) . ')', 'params' => $params];
    }

    /**
     * @param list<int> $orderIds
     * @return list<int> those of the orders this report shows the signed-in person
     */
    public static function orderIdsInScope(string $report, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === [] || AuthService::currentUser() === null) {
            return $orderIds;
        }
        $scope = self::scopeSql($report, 'o');
        return ReportAccessRepository::orderIdsWhere($orderIds, $scope['sql'], $scope['params']);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>> the rows whose order this report shows the signed-in person
     */
    public static function visibleRows(string $report, array $rows, string $orderIdKey = 'order_id'): array
    {
        $ids = [];
        foreach ($rows as $r) {
            if (isset($r[$orderIdKey])) {
                $ids[] = (int) $r[$orderIdKey];
            }
        }
        $keep = array_flip(self::orderIdsInScope($report, $ids));
        return array_values(array_filter($rows, static fn (array $r): bool => isset($r[$orderIdKey]) && isset($keep[(int) $r[$orderIdKey]])));
    }

    /** @return list<int>|null the clients of the orders in scope; null = every client (All, or nobody signed in) */
    public static function clientIdsInScope(string $report): ?array
    {
        $user = AuthService::currentUser();
        $level = self::level($report);
        if ($user === null || $level === self::ALL || $level === self::OFF) {
            return null;
        }
        $scope = self::scopeSql($report, 'o');
        return ReportAccessRepository::clientIdsWhere($scope['sql'], $scope['params']);
    }

    /** @return list<int>|null the people whose rows a people report shows; null = everybody */
    public static function peopleScope(string $report): ?array
    {
        $user = AuthService::currentUser();
        if ($user === null) {
            return null;
        }
        $level = self::level($report);
        if ($level === self::OWN) {
            return [(int) $user['id']];
        }
        if ($level === self::DEPARTMENT) {
            return self::departmentMemberIds((int) $user['id']);
        }
        return null;
    }

    // ================================================================ changes (all audited)

    /**
     * Saves a role's levels; one REPORT_ACCESS_CHANGED audit row per report whose level changed.
     *
     * @param array<string,string> $levels report key => level (unknown keys and levels are ignored)
     * @return list<string> the report keys changed
     */
    public static function saveRoleLevels(int $roleId, array $levels, int $byUserId, string $reason): array
    {
        $old = self::roleLevels($roleId);
        $changed = [];
        foreach ($levels as $key => $level) {
            $key = (string) $key;
            $level = (string) $level;
            if (!self::isReport($key) || !self::isLevel($level) || $old[$key] === $level) {
                continue;
            }
            ReportAccessRepository::setLevel($roleId, $key, $level, $byUserId);
            AuditLogRepository::log($byUserId, 'REPORT_ACCESS_CHANGED', 'roles', $roleId, $key, $old[$key], $level, $reason);
            $changed[] = $key;
        }
        unset(self::$roleLevels[$roleId]);
        self::$levelCache = [];
        return $changed;
    }

    /** Reset to defaults: the role gets the seeded levels of its name (off for a role not in the defaults). */
    public static function resetRole(int $roleId, string $roleName, int $byUserId, string $reason): array
    {
        return self::saveRoleLevels($roleId, self::defaultsForRoleName($roleName), $byUserId, $reason);
    }

    /**
     * Extra report access for one person: only a level above what their roles give.
     *
     * @return string|null the error, or null when saved
     */
    public static function addUserExtra(int $userId, ?int $roleId, string $key, string $level, string $reason, int $byUserId): ?string
    {
        if (!self::isReport($key)) {
            return 'Choose a report.';
        }
        if (!in_array($level, [self::OWN, self::DEPARTMENT, self::ALL], true)) {
            return 'Choose a level.';
        }
        if ($error = \App\Helpers\ReasonValidator::check($reason)) {
            return $error;
        }
        $roleGives = self::OFF;
        foreach (self::roleIdsForAdmin($userId, $roleId) as $rid) {
            $roleGives = self::higher(self::roleLevel($rid, $key), $roleGives);
        }
        if (self::rank($level) <= self::rank($roleGives)) {
            return 'An extra can only raise the level: their roles already give ' . self::LEVEL_LABELS[$roleGives] . ' for ' . self::REPORTS[$key]['label'] . '.';
        }
        $old = self::userExtras($userId)[$key]['level'] ?? null;
        ReportAccessRepository::saveExtra($userId, $key, $level, trim($reason), $byUserId);
        unset(self::$extras[$userId]);
        self::$levelCache = [];
        AuditLogRepository::log($byUserId, 'USER_REPORT_EXTRA_ADDED', 'users', $userId, $key, $old, $level, trim($reason));
        return null;
    }

    public static function removeUserExtra(int $userId, string $key, int $byUserId): bool
    {
        $old = self::userExtras($userId)[$key]['level'] ?? null;
        if ($old === null) {
            return false;
        }
        ReportAccessRepository::deleteExtra($userId, $key);
        unset(self::$extras[$userId]);
        self::$levelCache = [];
        AuditLogRepository::log($byUserId, 'USER_REPORT_EXTRA_REMOVED', 'users', $userId, $key, (string) $old, null);
        return true;
    }

    /**
     * The roles that count for a person seen from Edit user (not the editor's own session):
     * their own role and every role of a current department membership.
     *
     * @return list<int>
     */
    public static function roleIdsForAdmin(int $userId, ?int $roleId): array
    {
        return SopAccessService::roleIdsFor($userId, $roleId);
    }

    /** What a person's roles give for each report (Edit user › Extra report access). @return array<string,string> */
    public static function roleGivesFor(int $userId, ?int $roleId): array
    {
        $out = [];
        foreach (array_keys(self::REPORTS) as $key) {
            $level = self::OFF;
            foreach (self::roleIdsForAdmin($userId, $roleId) as $rid) {
                $level = self::higher(self::roleLevel($rid, $key), $level);
            }
            $out[$key] = $level;
        }
        return $out;
    }
}
