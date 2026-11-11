<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SessionTimeouts;

/**
 * Owner decision D-283 (11 Nov 2026): "Stay logged in" in the 2-minute warning
 * popup (app.js) posts here. The area's own auth middleware has already checked
 * the session and reset its idle clock; this only answers with the new times.
 */
final class SessionController
{
    private static function answer(string $area): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        $current = SessionTimeouts::$current;
        echo json_encode([
            'ok' => true,
            'idle_seconds' => $current['idle'] ?? SessionTimeouts::idleMinutes($area) * 60,
            'absolute_left' => $current['absolute_left'] ?? null,
        ]);
    }

    public function staffKeepAlive(array $params): void
    {
        self::answer('staff');
    }

    public function clientKeepAlive(array $params): void
    {
        self::answer('client');
    }

    public function associateKeepAlive(array $params): void
    {
        self::answer('associate');
    }
}
