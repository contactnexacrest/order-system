<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-257 Phase 4 (5 Nov 2026): the records of the Merchant
 * Trade (MTT) division — deals, their lines, the money on both legs, the AD
 * bank's document checklist and the RBI reminders already sent.
 * Port: merchantTradeRepository.js.
 */
final class MerchantTradeRepository
{
    private static function idList(array $ids): string
    {
        return $ids === [] ? '0' : implode(',', array_map('intval', $ids));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM merchant_deals WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param list<int>|null $divisionIds null = every division
     * @return list<array<string,mixed>> newest first, with the line count and both leg values in cents of their currency
     */
    public static function list(?array $divisionIds, ?string $stage = null): array
    {
        $where = [];
        $params = [];
        if ($divisionIds !== null) {
            $where[] = 'd.division_id IN (' . self::idList($divisionIds) . ')';
        }
        if ($stage !== null) {
            $where[] = 'd.stage = :stage';
            $params['stage'] = $stage;
        }
        $stmt = Database::connection()->prepare(
            'SELECT d.*,
                    (SELECT COUNT(*) FROM merchant_deal_lines l WHERE l.deal_id = d.id AND l.is_active = 1) AS line_count
               FROM merchant_deals d'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY d.deal_date DESC, d.id DESC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $row */
    public static function insert(array $row, int $userId): int
    {
        $pdo = Database::connection();
        $cols = array_keys($row);
        $pdo->prepare(
            'INSERT INTO merchant_deals (' . implode(', ', $cols) . ', created_by, updated_by) VALUES (:' . implode(', :', $cols) . ', :created_by, :updated_by)'
        )->execute($row + ['created_by' => $userId, 'updated_by' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields column => value (columns fixed by the caller, never user input) */
    public static function update(int $id, array $fields, ?int $userId): void
    {
        $sets = [];
        $params = ['id' => $id, 'u' => $userId];
        foreach ($fields as $col => $value) {
            $sets[] = "{$col} = :f_{$col}";
            $params["f_{$col}"] = $value;
        }
        Database::connection()->prepare('UPDATE merchant_deals SET ' . implode(', ', $sets) . ', updated_by = :u WHERE id = :id')->execute($params);
    }

    /** Moves the stage only from the stage the caller saw (two people clicking at once move it once). */
    public static function moveStage(int $id, string $from, string $to, int $userId): bool
    {
        $stmt = Database::connection()->prepare('UPDATE merchant_deals SET stage = :to, updated_by = :u WHERE id = :id AND stage = :from');
        $stmt->execute(['to' => $to, 'u' => $userId, 'id' => $id, 'from' => $from]);
        return $stmt->rowCount() === 1;
    }

    /** Locks the deal row until the transaction ends (two payments at once are checked one after the other). */
    public static function lock(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM merchant_deals WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function lines(int $dealId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM merchant_deal_lines WHERE deal_id = :d AND is_active = 1 ORDER BY line_no, id');
        $stmt->execute(['d' => $dealId]);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $line */
    public static function addLine(int $dealId, array $line, int $userId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(line_no), 0) + 1 FROM merchant_deal_lines WHERE deal_id = :d');
        $stmt->execute(['d' => $dealId]);
        $lineNo = (int) $stmt->fetchColumn();
        $pdo->prepare(
            'INSERT INTO merchant_deal_lines (deal_id, line_no, description, hsn_code, quantity, unit, buy_price_fc, sell_price_fc, created_by)
             VALUES (:d, :n, :ds, :h, :q, :u, :b, :s, :by)'
        )->execute([
            'd' => $dealId, 'n' => $lineNo, 'ds' => $line['description'], 'h' => $line['hsn_code'], 'q' => $line['quantity'],
            'u' => $line['unit'], 'b' => $line['buy_price_fc'], 's' => $line['sell_price_fc'], 'by' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function removeLine(int $dealId, int $lineId): bool
    {
        $stmt = Database::connection()->prepare('UPDATE merchant_deal_lines SET is_active = 0 WHERE id = :id AND deal_id = :d AND is_active = 1');
        $stmt->execute(['id' => $lineId, 'd' => $dealId]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> oldest first */
    public static function payments(int $dealId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, u.name AS recorded_by_name FROM merchant_payments p LEFT JOIN users u ON u.id = p.recorded_by
              WHERE p.deal_id = :d ORDER BY p.moved_on, p.id'
        );
        $stmt->execute(['d' => $dealId]);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $row */
    public static function addPayment(int $dealId, array $row, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO merchant_payments (deal_id, leg, moved_on, amount_fc, fx_rate, amount_inr, bank_reference, description, note, recorded_by)
             VALUES (:d, :l, :m, :a, :fx, :inr, :b, :desc, :n, :u)'
        )->execute([
            'd' => $dealId, 'l' => $row['leg'], 'm' => $row['moved_on'], 'a' => $row['amount_fc'], 'fx' => $row['fx_rate'],
            'inr' => $row['amount_inr'], 'b' => $row['bank_reference'], 'desc' => $row['description'], 'n' => $row['note'], 'u' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function bankReferenceUsed(string $bankReference): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM merchant_payments WHERE bank_reference = :b LIMIT 1');
        $stmt->execute(['b' => $bankReference]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,string> doc_key => received_on */
    public static function documents(int $dealId): array
    {
        $stmt = Database::connection()->prepare('SELECT doc_key, received_on FROM merchant_deal_documents WHERE deal_id = :d');
        $stmt->execute(['d' => $dealId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['doc_key']] = (string) $r['received_on'];
        }
        return $out;
    }

    public static function setDocument(int $dealId, string $docKey, ?string $receivedOn, int $userId): void
    {
        $pdo = Database::connection();
        if ($receivedOn === null) {
            $pdo->prepare('DELETE FROM merchant_deal_documents WHERE deal_id = :d AND doc_key = :k')->execute(['d' => $dealId, 'k' => $docKey]);
            return;
        }
        $pdo->prepare(
            'INSERT INTO merchant_deal_documents (deal_id, doc_key, received_on, recorded_by) VALUES (:d, :k, :r, :u)
             ON DUPLICATE KEY UPDATE received_on = VALUES(received_on), recorded_by = VALUES(recorded_by)'
        )->execute(['d' => $dealId, 'k' => $docKey, 'r' => $receivedOn, 'u' => $userId]);
    }

    /** Records that a reminder was sent; false when it already was (never sent twice). */
    public static function markAlertSent(int $dealId, string $checkKey, string $level): bool
    {
        $stmt = Database::connection()->prepare('INSERT IGNORE INTO merchant_alerts_sent (deal_id, check_key, level) VALUES (:d, :c, :l)');
        $stmt->execute(['d' => $dealId, 'c' => $checkKey, 'l' => $level]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> deals not closed yet, in every division */
    public static function openDeals(): array
    {
        return Database::connection()->query("SELECT * FROM merchant_deals WHERE stage <> 'closed' ORDER BY id")->fetchAll();
    }

    /** @return list<int> active users who may manage purchasing and work in this division or at company level */
    public static function alertRecipients(int $divisionId): array
    {
        $ids = [];
        foreach (PermissionRepository::usersWithPermission('manage_purchasing') as $userId) {
            $stmt = Database::connection()->prepare('SELECT division_id FROM users WHERE id = :id AND is_active = 1');
            $stmt->execute(['id' => (int) $userId]);
            $row = $stmt->fetch();
            if ($row !== false && ($row['division_id'] === null || (int) $row['division_id'] === $divisionId)) {
                $ids[] = (int) $userId;
            }
        }
        return array_values(array_unique($ids));
    }
}
