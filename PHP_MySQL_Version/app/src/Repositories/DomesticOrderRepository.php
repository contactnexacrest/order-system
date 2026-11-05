<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): the records of a Domestic Trade
 * (DOM) order — its domestic row, lines, payments, documents and number
 * series — plus the Indian states and the GST rates. Port: domesticOrderRepository.js.
 */
final class DomesticOrderRepository
{
    /** @return list<array<string,mixed>> */
    public static function states(): array
    {
        return Database::connection()->query('SELECT * FROM indian_states ORDER BY sort_order, name')->fetchAll();
    }

    public static function stateName(?string $code): string
    {
        foreach (self::states() as $s) {
            if ($s['code'] === $code) {
                return (string) $s['name'];
            }
        }
        return '';
    }

    public static function stateAbbr(?string $code): string
    {
        foreach (self::states() as $s) {
            if ($s['code'] === $code) {
                return (string) $s['abbr'];
            }
        }
        return '';
    }

    public static function isState(?string $code): bool
    {
        return $code !== null && self::stateName($code) !== '';
    }

    /** @return list<array<string,mixed>> */
    public static function gstRates(bool $activeOnly = false): array
    {
        return Database::connection()->query(
            'SELECT g.*, c.name AS category_name FROM gst_rates g LEFT JOIN product_categories c ON c.id = g.category_id'
            . ($activeOnly ? ' WHERE g.is_active = 1' : '') . ' ORDER BY g.hsn_prefix IS NULL, g.hsn_prefix, c.name, g.id'
        )->fetchAll();
    }

    public static function addGstRate(?string $hsnPrefix, ?int $categoryId, string $rate, ?string $note, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO gst_rates (hsn_prefix, category_id, rate_pct, note, updated_by) VALUES (:h, :c, :r, :n, :u)')
            ->execute(['h' => $hsnPrefix, 'c' => $categoryId, 'r' => $rate, 'n' => $note, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function setGstRateActive(int $id, bool $active, int $userId): void
    {
        Database::connection()->prepare('UPDATE gst_rates SET is_active = :a, updated_by = :u WHERE id = :id')
            ->execute(['a' => $active ? 1 : 0, 'u' => $userId, 'id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM domestic_orders WHERE order_id = :o');
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string,mixed> $row */
    public static function insert(int $orderId, array $row, int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO domestic_orders (order_id, buyer_gstin, buyer_state_code, place_of_supply, seller_state_code, delivery_address, payment_terms_days, buyer_po_ref, updated_by)
             VALUES (:o, :g, :bs, :pos, :ss, :addr, :days, :po, :u)'
        )->execute([
            'o' => $orderId, 'g' => $row['buyer_gstin'], 'bs' => $row['buyer_state_code'], 'pos' => $row['place_of_supply'],
            'ss' => $row['seller_state_code'], 'addr' => $row['delivery_address'], 'days' => $row['payment_terms_days'],
            'po' => $row['buyer_po_ref'], 'u' => $userId,
        ]);
    }

    /** @param array<string,mixed> $fields column => value (columns are fixed by the caller, never user input) */
    public static function update(int $orderId, array $fields, ?int $userId): void
    {
        $sets = [];
        $params = ['o' => $orderId, 'u' => $userId];
        foreach ($fields as $col => $value) {
            $sets[] = "{$col} = :f_{$col}";
            $params["f_{$col}"] = $value;
        }
        Database::connection()->prepare('UPDATE domestic_orders SET ' . implode(', ', $sets) . ', updated_by = :u WHERE order_id = :o')->execute($params);
    }

    /** Moves the stage only from the stage the caller saw (two people clicking at once move it once). */
    public static function moveStage(int $orderId, string $from, string $to, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE domestic_orders SET stage = :to, updated_by = :u, closed_at = IF(:to2 = 'closed', NOW(), closed_at) WHERE order_id = :o AND stage = :from"
        );
        $stmt->execute(['to' => $to, 'to2' => $to, 'u' => $userId, 'o' => $orderId, 'from' => $from]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    public static function lines(int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM domestic_order_lines WHERE order_id = :o AND is_active = 1 ORDER BY line_no, id');
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $line */
    public static function addLine(int $orderId, array $line, int $userId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(line_no), 0) + 1 FROM domestic_order_lines WHERE order_id = :o');
        $stmt->execute(['o' => $orderId]);
        $lineNo = (int) $stmt->fetchColumn();
        $pdo->prepare(
            'INSERT INTO domestic_order_lines (order_id, line_no, product_id, description, hsn_code, quantity, unit, rate_inr, gst_rate_pct, created_by)
             VALUES (:o, :n, :p, :d, :h, :q, :un, :r, :g, :u)'
        )->execute([
            'o' => $orderId, 'n' => $lineNo, 'p' => $line['product_id'], 'd' => $line['description'], 'h' => $line['hsn_code'],
            'q' => $line['quantity'], 'un' => $line['unit'], 'r' => $line['rate_inr'], 'g' => $line['gst_rate_pct'], 'u' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function removeLine(int $orderId, int $lineId): bool
    {
        $stmt = Database::connection()->prepare('UPDATE domestic_order_lines SET is_active = 0 WHERE id = :id AND order_id = :o AND is_active = 1');
        $stmt->execute(['id' => $lineId, 'o' => $orderId]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    public static function payments(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, u.name AS recorded_by_name FROM domestic_payments p LEFT JOIN users u ON u.id = p.recorded_by
              WHERE p.order_id = :o ORDER BY p.received_on, p.id'
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function addPayment(int $orderId, string $receivedOn, string $amount, string $bankReference, string $description, ?string $note, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO domestic_payments (order_id, received_on, amount_inr, bank_reference, description, note, recorded_by)
             VALUES (:o, :d, :a, :b, :desc, :n, :u)'
        )->execute(['o' => $orderId, 'd' => $receivedOn, 'a' => $amount, 'b' => $bankReference, 'desc' => $description, 'n' => $note, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function bankReferenceUsed(string $bankReference): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM domestic_payments WHERE bank_reference = :b LIMIT 1');
        $stmt->execute(['b' => $bankReference]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> newest first */
    public static function documents(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, u.name AS generated_by_name FROM domestic_documents d LEFT JOIN users u ON u.id = d.generated_by
              WHERE d.order_id = :o ORDER BY d.generated_at DESC, d.id DESC'
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null the latest document of this kind */
    public static function latestDocument(int $orderId, string $code): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM domestic_documents WHERE order_id = :o AND doc_code = :c ORDER BY id DESC LIMIT 1');
        $stmt->execute(['o' => $orderId, 'c' => $code]);
        return $stmt->fetch() ?: null;
    }

    public static function addDocument(int $orderId, string $code, string $reference, int $fileId, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO domestic_documents (order_id, doc_code, reference, file_store_id, generated_by) VALUES (:o, :c, :r, :f, :u)')
            ->execute(['o' => $orderId, 'c' => $code, 'r' => $reference, 'f' => $fileId, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** The next number of a series in a financial year, race-safe (one UPDATE takes the number). */
    public static function nextNumber(string $series, string $fy): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO domestic_counters (series, fy, last_no) VALUES (:s, :f, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)')
            ->execute(['s' => $series, 'f' => $fy]);
        return (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    /** A DOM setting (Admin › Divisions › Domestic Trade, company scope), or null when not set. */
    public static function divisionSetting(int $divisionId, string $key): ?string
    {
        $stmt = Database::connection()->prepare("SELECT setting_value FROM division_settings WHERE division_id = :d AND scope = 'company' AND setting_key = :k");
        $stmt->execute(['d' => $divisionId, 'k' => $key]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    /** @return array<string,mixed>|null */
    public static function client(int $clientId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM clients WHERE id = :id');
        $stmt->execute(['id' => $clientId]);
        return $stmt->fetch() ?: null;
    }

    /** Fills a client's GST fields where they are still empty (a client's own values are never overwritten here). */
    public static function fillClientGst(int $clientId, ?string $gstin, ?string $stateCode, ?string $placeOfSupply): void
    {
        Database::connection()->prepare(
            'UPDATE clients SET gstin = COALESCE(gstin, :g), gst_state_code = COALESCE(gst_state_code, :s), place_of_supply_state = COALESCE(place_of_supply_state, :p) WHERE id = :id'
        )->execute(['g' => $gstin, 's' => $stateCode, 'p' => $placeOfSupply, 'id' => $clientId]);
    }

    public static function setClientGst(int $clientId, ?string $gstin, ?string $stateCode, ?string $placeOfSupply): void
    {
        Database::connection()->prepare('UPDATE clients SET gstin = :g, gst_state_code = :s, place_of_supply_state = :p WHERE id = :id')
            ->execute(['g' => $gstin, 's' => $stateCode, 'p' => $placeOfSupply, 'id' => $clientId]);
    }
}
