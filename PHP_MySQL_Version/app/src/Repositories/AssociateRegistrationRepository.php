<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-253 (4 Nov 2026, A1): the MD's one-use, time-limited
 * associate registration links and the registrations they bring, reviewed
 * like Quotation Intake. Node twin: associateRegistrationRepository.js.
 */
final class AssociateRegistrationRepository
{
    public const STATUSES = ['pending' => 'Waiting for review', 'accepted' => 'Accepted', 'rejected' => 'Rejected'];

    public static function linkDays(): int
    {
        $v = (int) (IncentiveRepository::settings()['associate_registration_link_days'] ?? 7);
        return $v > 0 ? min($v, 90) : 7;
    }

    /** Creates a link; returns the raw token (shown once). */
    public static function createLink(?string $note, int $userId): string
    {
        $raw = bin2hex(random_bytes(24));
        Database::connection()->prepare('INSERT INTO associate_registration_links (token_hash, note, created_by, expires_at) VALUES (:h, :n, :u, DATE_ADD(NOW(), INTERVAL :d DAY))')
            ->execute(['h' => hash('sha256', $raw), 'n' => $note, 'u' => $userId, 'd' => self::linkDays()]);
        return $raw;
    }

    /** The link for a raw token if it can still be used (not expired, not used, not cancelled), else null. */
    public static function usableLink(string $raw): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM associate_registration_links WHERE token_hash = :h AND used_at IS NULL AND cancelled_at IS NULL AND expires_at > NOW()');
        $stmt->execute(['h' => hash('sha256', $raw)]);
        return $stmt->fetch() ?: null;
    }

    /** Takes the link for this registration at once (one use, safe against a double submit). */
    public static function claimLink(int $linkId): bool
    {
        $stmt = Database::connection()->prepare('UPDATE associate_registration_links SET used_at = NOW() WHERE id = :id AND used_at IS NULL AND cancelled_at IS NULL AND expires_at > NOW()');
        $stmt->execute(['id' => $linkId]);
        return $stmt->rowCount() === 1;
    }

    public static function cancelLink(int $linkId): void
    {
        Database::connection()->prepare('UPDATE associate_registration_links SET cancelled_at = NOW() WHERE id = :id AND used_at IS NULL AND cancelled_at IS NULL')->execute(['id' => $linkId]);
    }

    /** @return list<array<string,mixed>> */
    public static function links(): array
    {
        return Database::connection()->query(
            'SELECT l.*, u.name AS created_by_name FROM associate_registration_links l JOIN users u ON u.id = l.created_by ORDER BY l.id DESC LIMIT 100'
        )->fetchAll();
    }

    public static function create(int $linkId, array $d, ?string $ip): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO associate_registrations (link_id, name, email, phone, country, based_in, city, consent_at, ip_address)
                       VALUES (:l, :n, :e, :p, :c, :b, :ci, NOW(), :ip)')
            ->execute(['l' => $linkId, 'n' => $d['name'], 'e' => $d['email'], 'p' => $d['phone'], 'c' => $d['country'], 'b' => $d['based_in'], 'ci' => $d['city'], 'ip' => $ip]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM associate_registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function all(?string $status): array
    {
        $sql = 'SELECT r.*, u.name AS reviewed_by_name FROM associate_registrations r LEFT JOIN users u ON u.id = r.reviewed_by';
        $params = [];
        if ($status !== null && isset(self::STATUSES[$status])) {
            $sql .= ' WHERE r.status = :s';
            $params['s'] = $status;
        }
        $stmt = Database::connection()->prepare($sql . ' ORDER BY r.id DESC LIMIT 200');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Moves a pending registration to accepted / rejected exactly once. */
    public static function decide(int $id, string $status, ?string $reason, int $userId, ?int $associateId): bool
    {
        $stmt = Database::connection()->prepare("UPDATE associate_registrations SET status = :s, reject_reason = :r, reviewed_by = :u, reviewed_at = NOW(), associate_id = :a WHERE id = :id AND status = 'pending'");
        $stmt->execute(['s' => $status, 'r' => $reason, 'u' => $userId, 'a' => $associateId, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public static function pendingCount(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM associate_registrations WHERE status = 'pending'")->fetchColumn();
    }
}
