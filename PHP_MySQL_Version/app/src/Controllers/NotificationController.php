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

        View::render('notifications/index', [
            'notifications' => $notifications,
        ], 'layout/base');
    }
}
