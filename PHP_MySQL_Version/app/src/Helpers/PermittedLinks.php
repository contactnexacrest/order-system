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
        '#^/orders/\d+/dossier(?:[/?\#]|$)#'                        => ['manage_orders'],
        '#^/orders/\d+/additional-documents/removed(?:[/?\#]|$)#'   => ['manage_orders'],
        '#^/clients/\d+/additional-documents/removed(?:[/?\#]|$)#'  => ['manage_orders'],
        '#^/documents/\d+/download(?:[/?\#]|$)#'                    => ['download_pdf', 'view_cafin'],
        '#^/sop/ca-#'                                               => ['ca_module_view'],
        '#^/settings(?:[/?\#]|$)#'                                  => ['manage_company_settings'],
        '#^/audit-log(?:[/?\#]|$)#'                                 => ['view_audit_log'],
        '#^/ca(?:[/?\#]|$)#'                                        => ['ca_module_view'],
        '#^/disputes(?:[/?\#]|$)#'                                  => ['manage_disputes'],
        '#^/complaints(?:[/?\#]|$)#'                                => ['manage_complaints'],
        '#^/reports(?:[/?\#]|$)#'                                   => ['view_reports'],
        '#^/clients(?:[/?\#]|$)#'                                   => ['manage_orders', 'view_clients'],
        '#^/orders(?:[/?\#]|$)#'                                    => ['manage_orders', 'view_orders'],
        '#^/(?:client-intake|pi-intake-review|reorder-requests)(?:[/?\#]|$)#' => ['manage_orders'],
        '#^/admin/field-protection(?:[/?\#]|$)#'                    => ['manage_field_protection'],
        '#^/company-assets(?:[/?\#]|$)#'                            => ['manage_assets'],
        '#^/signatories(?:[/?\#]|$)#'                               => ['manage_signatories'],
        '#^/(?:ports|port-routes)(?:[/?\#]|$)#'                     => ['manage_ports'],
        '#^/users(?:[/?\#]|$)#'                                     => ['manage_users'],
    ];

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
