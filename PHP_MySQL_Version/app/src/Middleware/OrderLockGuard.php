<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\PermissionService;

/**
 * External QA report L-28: "Closed (complete + locked) order: production
 * status can still be edited — the new 'lock closed orders' does not
 * cover every edit route." OrderEditGuard (see its own docblock) already
 * blocks a locked order's core details/product lines, but it was only
 * ever added to those 5 call sites by hand — updateProductionStatus()
 * (and every other order-mutating route below) had no is_locked check at
 * all. Rather than keep adding one-off checks route by route (exactly how
 * this gap happened), this is a single central middleware registered on
 * every POST /orders/{id}/* route in public_html/index.php that actually
 * mutates the order's own business data, with the same "Override locked
 * data" permission (edit_locked_data) as the escape hatch everywhere else
 * in this codebase already uses for a locked order.
 *
 * Deliberately NOT registered on: override-status-lock (the override
 * mechanism itself), close/mark-lost (their own stage/status checks
 * already fail safely and more specifically once already locked),
 * archive/unarchive (housekeeping/visibility, not business data),
 * duplicate (reads the locked order to seed a NEW one — never writes to
 * it), comments (a note/chat entry, not a data field), disputes (raising
 * a dispute about an already-closed order is a real, expected workflow —
 * that's what Disputes is for), and the four document-generate/delete/
 * send routes (DocumentGenerationService::generate() already enforces
 * this exact permission internally — see OrderEditGuard's docblock).
 */
final class OrderLockGuard
{
    public static function check(): callable
    {
        return function (array $params): bool {
            $orderId = (int) ($params['id'] ?? 0);
            if ($orderId === 0) {
                return true;
            }

            $order = OrderRepository::find($orderId);
            if (!$order || !$order['is_locked']) {
                return true;
            }

            $user = AuthService::currentUser();
            $roleId = $user && $user['role_id'] !== null ? (int) $user['role_id'] : null;
            if ($user && PermissionService::can((int) $user['id'], $roleId, 'edit_locked_data')) {
                return true;
            }

            \App\Helpers\Flash::set('error', 'This order is locked (closed or marked lost) — this action now needs the "Override locked data" permission.');
            header("Location: /orders/{$orderId}");
            return false;
        };
    }
}
