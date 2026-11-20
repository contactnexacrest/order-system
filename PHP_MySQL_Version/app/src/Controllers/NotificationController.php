<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Repositories\NotificationRepository;
use App\Services\AuthService;

final class NotificationController
{
    public function index(array $params): void
    {
        $user = AuthService::currentUser();
        $notifications = NotificationRepository::forUser((int) $user['id'], 50);
        NotificationRepository::markAllRead((int) $user['id']);
        // Owner decision D-394: a prospect reminder offers "Keep for N more days" while its client is still an active prospect.
        $canManageOrders = \App\Services\PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'manage_orders');
        foreach ($notifications as $i => $n) {
            $notifications[$i]['prospect_client'] = null;
            if ($canManageOrders && $n['type'] === \App\Services\ProspectService::NOTIFICATION_TYPE && $n['related_order_id']) {
                $order = \App\Repositories\OrderRepository::find((int) $n['related_order_id']);
                $client = $order ? \App\Repositories\ClientRepository::find((int) $order['client_id']) : null;
                if ($client && (int) ($client['is_prospect'] ?? 0) === 1 && (int) $client['is_active'] === 1) {
                    $notifications[$i]['prospect_client'] = $client;
                }
            }
        }

        View::render('notifications/index', [
            'notifications' => $notifications,
        ], 'layout/base');
    }
}
