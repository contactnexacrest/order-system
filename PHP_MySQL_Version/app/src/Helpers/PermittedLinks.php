<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Services\AuthService;
use App\Services\PermissionService;

/**
 * QA Round 5, R5-04: the SOP chapters and the Getting Started tour link to
 * pages by name (Settings, Audit Log, CA, Disputes, ...) — a user without
 * the page's permission clicked through to a 403. filterHtml() turns such
 * a link into plain text for that user; the text itself stays.
 *
 * Each rule is the permission its route requires in public_html/index.php
 * (any one of the listed keys); PermittedLinksTest checks they match.
 * More specific paths come first.
 */
final class PermittedLinks
{
    public const RULES = [
        '#^/orders/\d+/(?:dossier|edit|annexure|amendments|additional-documents/removed)(?:[/?\#]|$)#' => ['manage_orders'],
        '#^/orders/\d+/zoho-check(?:[/?\#]|$)#'                     => ['ca_module_manage'],
        // Owner decision D-245 P2: the MD's approval screen for a line price below the floor.
        '#^/orders/\d+/products/\d+/price-approval(?:[/?\#]|$)#'      => ['manage_pricing'],
        '#^/orders/\d+/comment-attachments/\d+/download(?:[/?\#]|$)#' => ['download_pdf'],
        '#^/orders/\d+/(?:email/compose|documents/\d+/send)(?:[/?\#]|$)#' => ['generate_documents'],
        '#^/orders/\d+/disputes(?:[/?\#]|$)#'                       => ['manage_disputes', 'respond_to_disputes'],
        '#^/orders/\d+/complaints(?:[/?\#]|$)#'                     => ['manage_complaints', 'respond_to_complaints'],
        '#^/orders/\d+/audit-log(?:[/?\#]|$)#'                      => ['view_audit_log'],
        '#^/orders/archived(?:[/?\#]|$)#'                           => ['view_archived_orders'],
        '#^/orders/create(?:[/?\#]|$)#'                             => ['manage_orders'],
        '#^/clients/(?:create|\d+/(?:duplicate|edit|agreement/download|additional-documents/removed))(?:[/?\#]|$)#' => ['manage_orders'],
        '#^/documents/\d+/download(?:[/?\#]|$)#'                    => ['download_pdf', 'view_cafin'],
        '#^/sop/ca-#'                                               => ['ca_module_view'],
        '#^/settings(?:[/?\#]|$)#'                                  => ['manage_company_settings'],
        '#^/admin/backups(?:[/?\#]|$)#'                             => ['manage_company_settings'], // D-260
        '#^/admin/email-settings(?:[/?\#]|$)#'                      => ['manage_company_settings'], // D-280
        '#^/admin/setup-checklist(?:[/?\#]|$)#'                     => ['manage_company_settings'], // D-312
        // UI-007: the Setup checklist and Lists › Dropdown options link to product and pricing pages.
        '#^/products(?:[?\#]|$)#'                                  => ['view_product_catalog'],
        '#^/products/categories/\d+(?:[?\#]|$)#'                  => ['manage_product_catalog', 'manage_pricing', 'view_price_buildup'],
        '#^/pricing/commission-levels(?:[?\#]|$)#'                 => ['view_price_buildup', 'manage_pricing'],
        // SUI-002: the product page's "Pricing ready?" list and Market prices link to these.
        '#^/products/\d+/configure(?:[?\#]|$)#'                   => ['manage_product_catalog'],
        '#^/products/\d+/specifications(?:[?\#]|$)#'              => ['view_price_buildup', 'manage_product_specifications', 'manage_pricing'],
        // Owner decision D-378: the Targets settings (D-379: the Targets page itself opens by report access — REPORT_ROUTES).
        '#^/sales-targets/#'                                        => ['manage_sales_targets'],
        '#^/audit-log(?:[/?\#]|$)#'                                 => ['view_audit_log'],
        '#^/ca/cafin-export(?:[/?\#]|$)#'                           => ['view_cafin'],
        '#^/ca/(?:zoho-sync|fy-locks)(?:[/?\#]|$)#'                 => ['ca_module_manage'],
        '#^/ca(?:[/?\#]|$)#'                                        => ['ca_module_view'],
        '#^/disputes(?:[/?\#]|$)#'                                  => ['manage_disputes', 'respond_to_disputes'],
        '#^/complaints(?:[/?\#]|$)#'                                => ['manage_complaints', 'respond_to_complaints'],
        '#^/incentives/settings(?:[/?\#]|$)#'                       => ['manage_incentives'],
        '#^/incentives/payouts(?:[/?\#]|$)#'                        => ['pay_incentives'],
        // Owner decision 1 Nov 2026 (AS-11): associates.
        '#^/reports/associates(?:[/?\#]|$)#'                       => ['manage_associates'],
        // D-235 (2 Nov 2026): the associate TDS register is for Finance / incentive payers / the CA.
        '#^/associates/tds(?:[/?\#]|$)#'                           => ['manage_incentives', 'pay_incentives', 'ca_module_view'],
        // Owner decision D-255 (5 Nov 2026): associate price requests and an associate's price access.
        '#^/associates/price-requests(?:[/?\#]|$)#'                => ['manage_pricing', 'view_price_buildup'],
        '#^/associates/\d+/(?:price-access|commission-rule)(?:[/?\#]|$)#' => ['manage_pricing'], // D-291: the commission rule page is the MD's (manage_pricing)
        '#^/associates/\d+/bank/\d+/number(?:[/?\#]|$)#'           => ['manage_associate_bank'],
        '#^/associates/\d+/documents/\d+(?:[/?\#]|$)#'             => ['manage_associates', 'manage_associate_bank'],
        '#^/associates/access-levels(?:[/?\#]|$)#'                 => ['view_associates', 'manage_associates'], // D-261
        '#^/associates/(?:new|ladder|registrations|\d+/(?:edit|view-as|agreement))(?:[/?\#]|$)#' => ['manage_associates'],
        '#^/associates(?:[/?\#]|$)#'                               => ['view_associates', 'manage_associates', 'manage_associate_bank'],
        // Owner decision D-245 P5: incentive & commission reports.
        '#^/reports/incentives(?:[/?\#]|$)#'                        => ['manage_incentives', 'pay_incentives', 'view_price_buildup'],
        '#^/reports/staff(?:[/?\#]|$)#'                             => ['view_staff_reports'],
        '#^/reports/order-profitability(?:[/?\#]|$)#'               => ['manage_order_financials'],
        // Owner decision 1 Nov 2026 (A-7 / A-9): each needs only the money it shows.
        '#^/reports/freight-cost(?:[/?\#]|$)#'                      => ['view_logistics_costs'],
        '#^/reports/suppliers(?:[/?\#]|$)#'                         => ['view_purchase_prices'],
        // D-234 (2 Nov 2026): Missing eBRC / FIRC report.
        '#^/reports/missing-ebrc(?:[/?\#]|$)#'                     => ['ca_module_view', 'manage_export_benefit_claims', 'manage_order_financials'],
        // D-379: /reports itself opens by report access (REPORT_ROUTES); every page under it still needs view_reports unless listed above.
        '#^/reports/#'                                              => ['view_reports'],
        '#^/clients(?:[/?\#]|$)#'                                   => ['manage_orders', 'view_clients'],
        '#^/orders(?:[/?\#]|$)#'                                    => ['manage_orders', 'view_orders'],
        '#^/(?:client-intake|pi-intake-review|reorder-requests)(?:[/?\#]|$)#' => ['manage_orders'],
        '#^/admin/field-protection(?:[/?\#]|$)#'                    => ['manage_field_protection'],
        '#^/company-assets(?:[/?\#]|$)#'                            => ['manage_assets'],
        '#^/signatories(?:[/?\#]|$)#'                               => ['manage_signatories'],
        '#^/port-routes/lookup(?:[/?\#]|$)#'                        => ['manage_orders'],
        '#^/(?:ports|port-routes)(?:[/?\#]|$)#'                     => ['manage_ports'],
        '#^/users(?:[/?\#]|$)#'                                     => ['manage_users'],
        // QA UI-12 (D-257): division set-up pages, and the import / merchant trade modules.
        '#^/admin/(?:divisions|gst-rates|import-duty-rates)(?:[/?\#]|$)#' => ['manage_divisions'],
        // D-257 Zoho: the "App vs Zoho" check of an import, deal or Domestic Trade order (reads Zoho Books).
        '#^/(?:import|merchant|domestic/orders)/[^/]+/zoho-check(?:[/?\#]|$)#' => ['ca_module_manage'],
        '#^/import/create(?:[/?\#]|$)#'                            => ['manage_purchasing'],
        '#^/merchant/create(?:[/?\#]|$)#'                          => ['manage_purchasing'],
        '#^/(?:import|merchant)(?:[/?\#]|$)#'                      => ['view_purchase_prices'],
    ];

    /**
     * UI-006: report pages whose route needs only view_reports but which answer
     * 403 without the money they print (ReportController::refusedForMoney) —
     * every money class listed is needed (MoneyVisibility). Kept apart from
     * RULES, which mirror the route permissions exactly.
     */
    public const MONEY_REPORTS = [
        '#^/reports/client/\d+(?:[/?\#]|$)#'                                         => ['selling', 'buyer_payments'],
        '#^/reports/(?:aggregate|amendments|trends|products|performance)(?:[/?\#]|$)#' => ['selling'],
        '#^/reports/(?:payments|todays-payments|ageing)(?:[/?\#]|$)#'                 => ['buyer_payments'],
    ];

    /**
     * Owner decision D-379: report pages also need the person's report access (ReportAccess) — the report
     * key and the lowest level (null = the level that opens its page). Kept apart from RULES, which mirror
     * the route permissions exactly; these mirror the ReportAccessCheck middleware of the same routes
     * (PermittedLinksTest checks they match). More specific paths come first.
     */
    public const REPORT_ROUTES = [
        '#^/reports/client/\d+(?:[/?\#]|$)#'                       => ['per_client', null],
        '#^/reports/(?:aggregate|saved/\d+/run)(?:[/?\#]|$)#'      => ['aggregate', null],
        '#^/reports/queues(?:[/?\#]|$)#'                           => ['queues', null],
        '#^/reports/payments(?:[/?\#]|$)#'                         => ['payments', null],
        '#^/reports/todays-payments(?:[/?\#]|$)#'                  => ['todays_payments', null],
        '#^/reports/disputes(?:[/?\#]|$)#'                         => ['disputes', null],
        '#^/reports/amendments(?:[/?\#]|$)#'                       => ['amendments', null],
        '#^/reports/trends(?:[/?\#]|$)#'                           => ['trends', null],
        '#^/reports/staff(?:[/?\#]|$)#'                            => ['staff_productivity', null],
        '#^/reports/ageing(?:[/?\#]|$)#'                           => ['ageing', null],
        '#^/reports/freight-cost(?:[/?\#]|$)#'                     => ['freight_cost', null],
        '#^/reports/products(?:[/?\#]|$)#'                         => ['product_sales', null],
        '#^/reports/suppliers(?:[/?\#]|$)#'                        => ['supplier_performance', null],
        '#^/reports/order-profitability(?:[/?\#]|$)#'              => ['profitability', null],
        '#^/reports/incentives(?:[/?\#]|$)#'                       => ['incentives', null],
        '#^/reports/conversion(?:[/?\#]|$)#'                       => ['conversion', null],
        '#^/reports/performance(?:[/?\#]|$)#'                      => ['performance', null],
        '#^/reports/missing-ebrc(?:[/?\#]|$)#'                     => ['missing_ebrc', null],
        '#^/reports(?:[?\#]|$)#'                                   => ['any', null],
        '#^/cockpit(?:[/?\#]|$)#'                                  => ['cockpit', null],
        '#^/audit-log(?:[/?\#]|$)#'                                => ['audit_log', null],
        '#^/ca/cafin-export(?:[/?\#]|$)#'                          => ['cafin', null],
        '#^/ca/(?:expenses|tds-summary|bank-statement|reconciliation|gst-refunds)(?:[?\#]|$)#' => ['ca_reports', 'all'],
        '#^/ca/(?:reports|export-benefits)(?:[?\#]|$)#'            => ['ca_reports', null],
        '#^/ca(?:[?\#]|$)#'                                        => ['ca_reports', null],
        '#^/sales-targets/settings(?:[?\#]|$)#'                    => ['sales_targets', 'all'],
        '#^/sales-targets(?:[?\#]|$)#'                             => ['sales_targets', 'department'],
    ];

    /** @return array{0: string, 1: ?string}|null [report key or 'any', lowest level] the path's report gate, or null */
    public static function reportRequiredFor(string $path): ?array
    {
        foreach (self::REPORT_ROUTES as $pattern => $gate) {
            if (preg_match($pattern, $path)) {
                return $gate;
            }
        }
        return null;
    }

    /** @return list<string>|null the money classes (all of them) the report page needs, or null */
    public static function moneyRequiredFor(string $path): ?array
    {
        foreach (self::MONEY_REPORTS as $pattern => $classes) {
            if (preg_match($pattern, $path)) {
                return $classes;
            }
        }
        return null;
    }

    /** QA Round 6, R6-N02: pages of one order also need that order to be visible to the user (D-118). */
    private const ORDER_PAGE = '#^/(?:orders|reports/order)/(\d+)(?:[/?\#]|$)#';

    /** @return string[]|null the permissions (any one) the path needs, or null when it needs none of these */
    public static function requiredFor(string $path): ?array
    {
        foreach (self::RULES as $pattern => $permissions) {
            if (preg_match($pattern, $path)) {
                return $permissions;
            }
        }
        return null;
    }

    public static function currentUserMayOpen(string $path): bool
    {
        // D-257: a module switched off for the request's division(s).
        if (\App\Services\DivisionService::disabledModuleForPath((string) parse_url($path, PHP_URL_PATH)) !== null) {
            return false;
        }
        if (preg_match(self::ORDER_PAGE, $path, $m) && !\App\Services\OrderAccessService::currentUserMaySeeOrderId((int) $m[1])) {
            return false;
        }
        $report = self::reportRequiredFor($path);
        if ($report !== null) {
            [$key, $min] = $report;
            $open = $key === 'any' ? \App\Services\ReportAccess::anyOpen() : \App\Services\ReportAccess::can($key, $min ?? \App\Services\ReportAccess::pageMin($key));
            if (!$open) {
                return false;
            }
        }
        foreach (self::moneyRequiredFor($path) ?? [] as $class) {
            if (!\App\Services\MoneyVisibility::can($class)) {
                return false;
            }
        }
        $required = self::requiredFor($path);
        if ($required === null) {
            return true;
        }
        foreach ($required as $key) {
            if (self::currentUserHas($key)) {
                return true;
            }
        }
        return false;
    }

    /** Whether the signed-in user holds this one permission (for views without their own $can… flags). */
    public static function currentUserHas(string $key): bool
    {
        $user = AuthService::currentUser();
        return $user !== null && PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, $key);
    }

    /** Replaces every internal link the current user may not open with its plain text. */
    public static function filterHtml(string $html): string
    {
        return preg_replace_callback(
            '#<a\s[^>]*href="(/[^"]*)"[^>]*>(.*?)</a>#s',
            static fn (array $m): string => self::currentUserMayOpen(html_entity_decode($m[1])) ? $m[0] : '<span class="link-not-permitted">' . $m[2] . '</span>',
            $html
        ) ?? $html;
    }
}
