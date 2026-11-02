<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Agreements register (owner decision 2 Nov 2026, point 4): every agreement signed with a client,
 * supplier, logistics partner, associate or staff member, with its signed copy, dates and status.
 *
 * Rows of the `agreements` table are managed here. Two older records are listed alongside them,
 * read-only, so the register is complete without moving data the rest of the app depends on:
 * the client commercial agreement (clients.agreement_* — its footer prints on documents) and the
 * associate agreement (associates.agreement_* — the associate's own reminders read it). Each such
 * row links to the page that manages it. Node twin: agreementRepository.js.
 */
final class AgreementRepository
{
    public const PARTY_TYPES = [
        'client' => 'Client',
        'supplier' => 'Supplier',
        'logistics_partner' => 'Logistics partner',
        'associate' => 'Associate',
        'staff' => 'Staff member',
    ];

    /** Status shown on the list, the board columns and the filter chips, in this order. */
    public const STATUSES = [
        'expiring' => 'Expiring soon',
        'active' => 'Active',
        'no_expiry' => 'No expiry',
        'expired' => 'Expired',
        'terminated' => 'Terminated',
        'renewed' => 'Renewed (replaced)',
    ];

    public const EXPIRING_DAYS = 30;

    /** Reminder thresholds: 30 and 7 days before expiry, and the expiry day. */
    public const ALERT_DAYS = [30, 7, 0];

    /** The status of one row on $today ('Y-m-d'). */
    public static function statusOf(array $row, string $today): string
    {
        if (!empty($row['terminated_on'])) {
            return 'terminated';
        }
        if (!empty($row['renewed'])) {
            return 'renewed';
        }
        $expires = $row['expires_on'] ?? null;
        if ($expires === null || $expires === '') {
            return 'no_expiry';
        }
        if ($expires < $today) {
            return 'expired';
        }
        $limit = (new \DateTimeImmutable($today))->modify('+' . self::EXPIRING_DAYS . ' days')->format('Y-m-d');
        return $expires <= $limit ? 'expiring' : 'active';
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM agreements WHERE id = :id AND is_active = 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data, int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO agreements (party_type, party_id, agreement_type, title, signed_on, starts_on, expires_on, file_id, renews_agreement_id, notes, created_by)
             VALUES (:party_type, :party_id, :agreement_type, :title, :signed_on, :starts_on, :expires_on, :file_id, :renews, :notes, :created_by)'
        );
        $stmt->execute([
            'party_type' => $data['party_type'], 'party_id' => (int) $data['party_id'], 'agreement_type' => $data['agreement_type'],
            'title' => $data['title'] ?? null, 'signed_on' => $data['signed_on'], 'starts_on' => $data['starts_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null, 'file_id' => $data['file_id'] ?? null, 'renews' => $data['renews_agreement_id'] ?? null,
            'notes' => $data['notes'] ?? null, 'created_by' => $userId,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /** @param array<string,mixed> $data party, type, title, dates and notes; the file only when a new copy was attached */
    public static function update(int $id, array $data): void
    {
        $sets = 'agreement_type = :agreement_type, title = :title, signed_on = :signed_on, starts_on = :starts_on, expires_on = :expires_on, notes = :notes';
        $params = [
            'id' => $id, 'agreement_type' => $data['agreement_type'], 'title' => $data['title'] ?? null, 'signed_on' => $data['signed_on'],
            'starts_on' => $data['starts_on'] ?? null, 'expires_on' => $data['expires_on'] ?? null, 'notes' => $data['notes'] ?? null,
        ];
        if (!empty($data['file_id'])) {
            $sets .= ', file_id = :file_id';
            $params['file_id'] = (int) $data['file_id'];
        }
        Database::connection()->prepare("UPDATE agreements SET {$sets} WHERE id = :id")->execute($params);
    }

    public static function terminate(int $id, string $on, string $reason): void
    {
        Database::connection()->prepare('UPDATE agreements SET terminated_on = :on, terminated_reason = :reason WHERE id = :id')
            ->execute(['id' => $id, 'on' => $on, 'reason' => $reason]);
    }

    /** The party's display name, or null when it does not exist. */
    public static function partyName(string $type, int $id): ?string
    {
        $sql = [
            'client' => 'SELECT company_legal_name FROM clients WHERE id = :id',
            'supplier' => 'SELECT supplier_legal_name FROM suppliers WHERE id = :id',
            'logistics_partner' => 'SELECT partner_name FROM logistics_partners WHERE id = :id',
            'associate' => 'SELECT name FROM associates WHERE id = :id',
            'staff' => 'SELECT name FROM users WHERE id = :id',
        ][$type] ?? null;
        if ($sql === null) {
            return null;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }

    /** Where the party is managed. */
    public static function partyUrl(string $type, int $id): string
    {
        return [
            'client' => "/clients/{$id}",
            'supplier' => "/suppliers/{$id}/edit",
            'logistics_partner' => "/logistics-partners/{$id}/edit",
            'associate' => "/associates/{$id}",
            'staff' => "/users/{$id}/edit",
        ][$type] ?? '#';
    }

    /** @return array<string, array<int, string>> party type => id => name, for the "who with" drop-downs */
    public static function partyOptions(): array
    {
        $pdo = Database::connection();
        $pick = static fn (string $sql): array => array_column($pdo->query($sql)->fetchAll(), 'n', 'id');
        return [
            'client' => $pick('SELECT id, company_legal_name AS n FROM clients WHERE is_active = 1 ORDER BY company_legal_name'),
            'supplier' => $pick('SELECT id, supplier_legal_name AS n FROM suppliers WHERE is_active = 1 ORDER BY supplier_legal_name'),
            'logistics_partner' => $pick('SELECT id, partner_name AS n FROM logistics_partners WHERE is_active = 1 ORDER BY partner_name'),
            'associate' => $pick("SELECT id, name AS n FROM associates ORDER BY name"),
            'staff' => $pick('SELECT id, name AS n FROM users WHERE is_active = 1 ORDER BY name'),
        ];
    }

    /**
     * Every agreement on record — the register's own rows plus the client commercial agreements and
     * associate agreements kept on their own pages — each with key, source, party_name, status.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $pdo = Database::connection();
        $rows = [];
        $own = $pdo->query(
            'SELECT a.*, (SELECT COUNT(*) FROM agreements n WHERE n.renews_agreement_id = a.id AND n.is_active = 1) AS renewed,
                    f.original_filename
               FROM agreements a LEFT JOIN file_store f ON f.id = a.file_id
              WHERE a.is_active = 1 ORDER BY a.id'
        )->fetchAll();
        foreach ($own as $a) {
            $a['key'] = 'agreement:' . $a['id'];
            $a['source'] = 'register';
            $a['party_name'] = self::partyName((string) $a['party_type'], (int) $a['party_id']) ?? '(removed)';
            $rows[] = $a;
        }
        // The client commercial agreement — managed on the client page (its footer prints on documents).
        foreach ($pdo->query(
            "SELECT id, company_legal_name, agreement_file_original_name, agreement_uploaded_at, agreement_expiry_date, agreement_force_expired
               FROM clients WHERE agreement_file_path IS NOT NULL AND agreement_file_path <> '' ORDER BY id"
        )->fetchAll() as $c) {
            $rows[] = [
                'key' => 'client:' . $c['id'], 'source' => 'client', 'id' => null,
                'party_type' => 'client', 'party_id' => (int) $c['id'], 'party_name' => (string) $c['company_legal_name'],
                'agreement_type' => 'Client commercial agreement', 'title' => 'Footer agreement on this client\'s documents',
                'signed_on' => $c['agreement_uploaded_at'] !== null ? substr((string) $c['agreement_uploaded_at'], 0, 10) : null,
                'starts_on' => null, 'expires_on' => $c['agreement_expiry_date'],
                'terminated_on' => (int) $c['agreement_force_expired'] === 1 ? ($c['agreement_expiry_date'] ?? $today) : null,
                'terminated_reason' => (int) $c['agreement_force_expired'] === 1 ? 'Force-expired on the client page' : null,
                'renewed' => 0, 'original_filename' => $c['agreement_file_original_name'], 'notes' => null,
            ];
        }
        // The associate agreement — managed on the associate page.
        foreach ($pdo->query(
            "SELECT a.id, a.name, a.status, a.agreement_signed_on, a.agreement_ends_on, f.original_filename
               FROM associates a LEFT JOIN file_store f ON f.id = a.agreement_file_id
              WHERE a.agreement_file_id IS NOT NULL ORDER BY a.id"
        )->fetchAll() as $s) {
            $rows[] = [
                'key' => 'associate:' . $s['id'], 'source' => 'associate', 'id' => null,
                'party_type' => 'associate', 'party_id' => (int) $s['id'], 'party_name' => (string) $s['name'],
                'agreement_type' => 'Associate Agreement', 'title' => 'Commission Structure and Associate Agreement',
                'signed_on' => $s['agreement_signed_on'], 'starts_on' => null, 'expires_on' => $s['agreement_ends_on'],
                'terminated_on' => $s['status'] === 'ended' ? ($s['agreement_ends_on'] ?? $today) : null,
                'terminated_reason' => $s['status'] === 'ended' ? 'Associate ended' : null,
                'renewed' => 0, 'original_filename' => $s['original_filename'], 'notes' => null,
            ];
        }
        foreach ($rows as &$r) {
            $r['status'] = self::statusOf($r, $today);
            $r['days_left'] = ($r['expires_on'] ?? null) ? (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable((string) $r['expires_on']))->format('%r%a') : null;
        }
        unset($r);
        return $rows;
    }

    /**
     * The rows this person may see: staff agreements only with manage_agreements.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function visibleTo(array $rows, bool $canManage): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => $canManage || $r['party_type'] !== 'staff'));
    }

    /** @return list<array<string,mixed>> the agreements of one party */
    public static function forParty(string $type, int $id, ?string $today = null): array
    {
        return array_values(array_filter(self::all($today), static fn (array $r): bool => $r['party_type'] === $type && (int) $r['party_id'] === $id));
    }

    public static function alertAlreadySent(string $key, string $expiresOn, int $threshold): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM agreement_alerts_sent WHERE agreement_key = :k AND expires_on = :e AND threshold_days = :t');
        $stmt->execute(['k' => $key, 'e' => $expiresOn, 't' => $threshold]);
        return $stmt->fetchColumn() !== false;
    }

    public static function markAlertSent(string $key, string $expiresOn, int $threshold): void
    {
        Database::connection()->prepare('INSERT IGNORE INTO agreement_alerts_sent (agreement_key, expires_on, threshold_days) VALUES (:k, :e, :t)')
            ->execute(['k' => $key, 'e' => $expiresOn, 't' => $threshold]);
    }
}
