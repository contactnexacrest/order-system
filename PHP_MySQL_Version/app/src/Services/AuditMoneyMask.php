<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

/**
 * Owner points 6 / 15 (2 Nov 2026): "only permitted people see amounts —
 * not a single leak". The audit log stores old/new values, and many rows
 * carry money (a remittance amount, a supplier bill, a commission, a cost).
 * Whoever may read the audit log (view_audit_log, view_reports for an order's
 * Full Audit Trail) still sees that the action happened, by whom and when —
 * but the values of a row are hidden unless they also hold the permission
 * for that kind of money. Used by the audit log page, the order audit page,
 * the per-order report's Full Audit Trail and their CSV exports.
 * Node twin: src/services/auditMoneyMask.js (same rules, same text).
 */
final class AuditMoneyMask
{
    public const HIDDEN = '— hidden (no permission to see these amounts)';

    /** class => permission(s) that show it (any one is enough) */
    private const PERMISSIONS = [
        'selling'        => ['view_selling_prices'],
        'buyer_payments' => ['view_buyer_payments'],
        'purchase'       => ['view_purchase_prices'],
        'logistics'      => ['view_logistics_costs'],
        'incentives'     => ['manage_incentives'],
        // D-172: Accounts sees what to pay.
        'payouts'        => ['manage_incentives', 'pay_incentives'],
        'financials'     => ['manage_order_financials', 'ca_module_view'],
    ];

    /** [regex on action_type, class] — first match wins. */
    private const ACTION_RULES = [
        ['/^INCENTIVE_PAID|^ASSOCIATE_PAYOUT|^ASSOCIATE_CLAWBACK|^ASSOCIATE_INVOICE/', 'payouts'],
        ['/INCENTIVE|COMMISSION|PAYOUT|CLAWBACK|ASSOCIATE_SETTING|LADDER|ASSOCIATE_RATE/', 'incentives'],
        ['/^ORDER_(COST_ENTRY|EXPENSE|TRAVEL_EXPENSE|FINANCIALS|INSPECTION_FEE|FULLY_SETTLED)|^CA_EXPENSE|EXPORT_BENEFIT|GST_REFUND|CAFIN|PROFIT/', 'financials'],
        ['/^(ADVANCE|BALANCE|FREIGHT)_PAYMENT|MILESTONE_PAYMENT|^LC_|^ORDER_LC|^CA_INR_ACTUAL|EXCHANGE_RATE|SHORTFALL|SHORT_PAYMENT|^CA_FIRC|CLIENT_PAYMENT_REPORT|CANCELLATION_REFUND|^CA_BANK_STATEMENT|PAYMENT_REPORTED/', 'buyer_payments'],
        ['/^LOGISTICS_BILL|^ORDER_FREIGHT|FREIGHT_TERMS|FREIGHT_COST/', 'logistics'],
        ['/^SUPPLIER_(PO|ADVANCE|BALANCE|BILL)|^PURCHASE_(AWARD|QUOTE)|APPROVAL_LIMIT|SUPPLIER_PAYMENT/', 'purchase'],
        ['/^AMENDMENT_|PAYMENT_SCHEDULE|QUOTATION_VALUE|ORDER_PRODUCT|UNIT_PRICE/', 'selling'],
    ];

    /** entity_type => class, when the action alone does not say. */
    private const ENTITY_RULES = [
        'incentive_payouts' => 'payouts', 'incentive_settings' => 'incentives', 'order_incentives' => 'incentives', 'user_incentive_roles' => 'incentives',
        'order_cost_entries' => 'financials', 'order_expenses' => 'financials', 'order_travel_expenses' => 'financials', 'ca_expenses' => 'financials',
        'ca_export_benefits' => 'financials', 'gst_refund_claims' => 'financials', 'gst_refund_credits' => 'financials', 'order_inspection_fees' => 'financials',
        'order_payment_status' => 'buyer_payments', 'order_payment_milestones' => 'buyer_payments', 'order_lc_details' => 'buyer_payments',
        'order_lc_amendments' => 'buyer_payments', 'order_payment_shortfalls' => 'buyer_payments', 'client_payment_reports' => 'buyer_payments',
        'ca_bank_statement_lines' => 'buyer_payments',
        'order_freight' => 'logistics',
        'order_supplier_po' => 'purchase', 'purchase_quotes' => 'purchase', 'purchase_requirements' => 'purchase',
        'order_products' => 'selling', 'amendments' => 'selling', 'payment_presets' => 'selling',
    ];

    /** A money-looking field name, for rows no rule above classifies (FIELD_EDIT and the like). */
    private const MONEY_FIELD = '/price|amount|cost|rate|fob|freight|insurance|commission|incentive|profit|_inr|value|payable|paid|advance|balance|expense|margin/i';

    /** @var array<string,bool> */
    private static array $canCache = [];

    /** The kind of money an audit row carries, or null when it carries none. */
    public static function classFor(array $row): ?string
    {
        $action = (string) ($row['action_type'] ?? '');
        $entity = (string) ($row['entity_type'] ?? '');
        $field = (string) ($row['field_name'] ?? '');

        if ($entity === 'order_vendor_bills' || str_starts_with($action, 'VENDOR_BILL')) {
            return self::vendorBillClass((int) ($row['entity_id'] ?? 0), $action);
        }
        foreach (self::ACTION_RULES as [$regex, $class]) {
            if (preg_match($regex, $action)) {
                return $class;
            }
        }
        if (isset(self::ENTITY_RULES[$entity])) {
            return self::ENTITY_RULES[$entity];
        }
        if ($field !== '' && preg_match(self::MONEY_FIELD, $field)) {
            return match (true) {
                (bool) preg_match('/cost|supplier|factory|purchase/i', $field) => 'purchase',
                (bool) preg_match('/freight|insurance/i', $field) => 'logistics',
                (bool) preg_match('/commission|incentive/i', $field) => 'incentives',
                (bool) preg_match('/profit|expense|margin/i', $field) => 'financials',
                (bool) preg_match('/advance|balance|paid|_inr|rate/i', $field) => 'buyer_payments',
                default => 'selling',
            };
        }
        return null;
    }

    private static function vendorBillClass(int $billId, string $action): string
    {
        if (str_starts_with($action, 'LOGISTICS')) {
            return 'logistics';
        }
        if ($billId > 0) {
            $stmt = Database::connection()->prepare('SELECT vendor_kind FROM order_vendor_bills WHERE id = :id');
            $stmt->execute(['id' => $billId]);
            if ($stmt->fetchColumn() === 'logistics') {
                return 'logistics';
            }
        }
        return 'purchase';
    }

    /** Whether the signed-in person may see this class of money. */
    public static function canSee(string $class): bool
    {
        $user = AuthService::currentUser();
        if ($user === null) {
            return false;
        }
        $key = $user['id'] . ':' . ($user['role_id'] ?? '') . ':' . $class;
        if (array_key_exists($key, self::$canCache)) {
            return self::$canCache[$key];
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        foreach (self::PERMISSIONS[$class] ?? [] as $permission) {
            if (PermissionService::can((int) $user['id'], $roleId, $permission)) {
                return self::$canCache[$key] = true;
            }
        }
        return self::$canCache[$key] = false;
    }

    /** One row, with its values hidden when the signed-in person may not see that money. */
    public static function mask(array $row): array
    {
        $class = self::classFor($row);
        if ($class === null || self::canSee($class)) {
            return $row;
        }
        foreach (['old_value', 'new_value'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                $row[$key] = self::HIDDEN;
            }
        }
        if (!empty($row['reason'])) {
            $row['reason'] = (string) preg_replace('/\d[\d,]*(\.\d+)?/', '•••', (string) $row['reason']);
        }
        $row['values_hidden'] = true;
        return $row;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    public static function maskAll(array $rows): array
    {
        return array_map([self::class, 'mask'], $rows);
    }

    /** Tests sign in as several people in one process. */
    public static function resetCache(): void
    {
        self::$canCache = [];
    }
}
