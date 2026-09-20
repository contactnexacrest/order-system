<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class LoginAttemptRepository
{
    public static function record(?int $userId, string $emailAttempted, string $ip, bool $success): void
    {
        Database::connection()->prepare(
            'INSERT INTO login_attempts (user_id, email_attempted, ip_address, success)
             VALUES (:user_id, :email, :ip, :success)'
        )->execute([
            'user_id' => $userId,
            'email'   => $emailAttempted,
            'ip'      => $ip,
            'success' => $success ? 1 : 0,
        ]);
    }

    public static function recentFailedCount(int $userId, int $withinMinutes = 15): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS c FROM login_attempts
             WHERE user_id = :user_id AND success = 0
               AND attempted_at >= (NOW() - INTERVAL :minutes MINUTE)'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':minutes', $withinMinutes, \PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetch()['c'];
    }
}
