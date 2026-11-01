<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/** Owner decision 1 Nov 2026 (AS-5): an associate's invitations — one-off client links and buyers who came through the personal code. */
final class AssociateInvitationRepository
{
    public const STATUSES = ['created' => 'Link created', 'opened' => 'Opened', 'registered' => 'Registered', 'accepted' => 'Accepted — your client',
        'not_accepted' => 'Not accepted', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
    public const LINK_DAYS = 30;

    public static function createLink(int $associateId, string $company, string $country): array
    {
        $pdo = Database::connection();
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO associate_invitations (associate_id, kind, token, company_name, country, status, expires_at)
                       VALUES (:a, 'client_link', :t, :c, :k, 'created', DATE_ADD(NOW(), INTERVAL " . self::LINK_DAYS . " DAY))")
            ->execute(['a' => $associateId, 't' => $token, 'c' => $company, 'k' => $country]);
        return ['id' => (int) $pdo->lastInsertId(), 'token' => $token];
    }

    /** A buyer who registered through the personal code gets a row of their own, so it shows like any invitation. */
    public static function createForCode(int $associateId, string $company, string $country): int
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO associate_invitations (associate_id, kind, company_name, country, status) VALUES (:a, 'personal_code', :c, :k, 'opened')")
            ->execute(['a' => $associateId, 'c' => $company, 'k' => $country]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM associate_invitations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM associate_invitations WHERE token = :t');
        $stmt->execute(['t' => $token]);
        return $stmt->fetch() ?: null;
    }

    /** A link that can still be used: created / opened, not past its date. */
    public static function isUsable(array $inv): bool
    {
        return in_array($inv['status'], ['created', 'opened'], true) && ($inv['expires_at'] === null || strtotime((string) $inv['expires_at']) > time());
    }

    public static function setStatus(int $id, string $status, array $extra = []): void
    {
        $sets = ['status = :s'];
        $params = ['s' => $status, 'id' => $id];
        foreach ($extra as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[$k] = $v;
        }
        if (in_array($status, ['accepted', 'not_accepted'], true)) {
            $sets[] = 'decided_at = NOW()';
        }
        if ($status === 'opened') {
            $sets[] = 'opened_at = COALESCE(opened_at, NOW())';
        }
        Database::connection()->prepare('UPDATE associate_invitations SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    /** Links past their 30 days become "expired". */
    public static function expireOld(): int
    {
        $stmt = Database::connection()->prepare("UPDATE associate_invitations SET status = 'expired' WHERE status IN ('created', 'opened') AND kind = 'client_link' AND expires_at < NOW()");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public static function forAssociate(int $associateId): array
    {
        self::expireOld();
        $stmt = Database::connection()->prepare('SELECT * FROM associate_invitations WHERE associate_id = :a ORDER BY id DESC');
        $stmt->execute(['a' => $associateId]);
        return $stmt->fetchAll();
    }
}
