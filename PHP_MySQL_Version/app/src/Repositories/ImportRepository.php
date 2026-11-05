<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-257 Phase 3 (5 Nov 2026): the records of the Import &
 * Distribution (IMP) division — imports (shipments), their lines and
 * supplier payments, customs duty rates, and the stock with its movements.
 * Port: importRepository.js.
 */
final class ImportRepository
{
    // ------------------------------------------------------------ duty rates

    /** @return list<array<string,mixed>> */
    public static function dutyRates(bool $activeOnly = false): array
    {
        return Database::connection()->query(
            'SELECT * FROM import_duty_rates' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY hsn_prefix, id'
        )->fetchAll();
    }

    public static function addDutyRate(string $hsnPrefix, string $bcd, string $sws, string $igst, ?string $note, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO import_duty_rates (hsn_prefix, bcd_pct, sws_pct, igst_pct, note, updated_by) VALUES (:h, :b, :s, :i, :n, :u)')
            ->execute(['h' => $hsnPrefix, 'b' => $bcd, 's' => $sws, 'i' => $igst, 'n' => $note, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function setDutyRateActive(int $id, bool $active, int $userId): bool
    {
        $stmt = Database::connection()->prepare('UPDATE import_duty_rates SET is_active = :a, updated_by = :u WHERE id = :id');
        $stmt->execute(['a' => $active ? 1 : 0, 'u' => $userId, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------ shipments

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM import_shipments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param list<int>|null $divisionIds null = every division
     * @return list<array<string,mixed>> newest first, with line count and the PO value in the supplier's currency
     */
    public static function list(?array $divisionIds, ?string $stage = null): array
    {
        $where = [];
        $params = [];
        if ($divisionIds !== null) {
            $where[] = 's.division_id IN (' . ($divisionIds === [] ? '0' : implode(',', array_map('intval', $divisionIds))) . ')';
        }
        if ($stage !== null) {
            $where[] = 's.stage = :stage';
            $params['stage'] = $stage;
        }
        $stmt = Database::connection()->prepare(
            'SELECT s.id, s.reference, s.stage, s.supplier_name, s.supplier_country, s.currency_code, s.po_date, s.bl_no, s.boe_no,
                    s.received_on, s.landed_total,
                    (SELECT COUNT(*) FROM import_shipment_lines l WHERE l.shipment_id = s.id AND l.is_active = 1) AS line_count,
                    (SELECT COALESCE(SUM(ROUND(l.quantity * l.unit_price_fc, 2)), 0) FROM import_shipment_lines l WHERE l.shipment_id = s.id AND l.is_active = 1) AS po_value_fc
               FROM import_shipments s'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.po_date DESC, s.id DESC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $row */
    public static function insert(array $row, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO import_shipments (division_id, reference, supplier_name, supplier_country, supplier_address, currency_code, incoterm, po_date, payment_terms, created_by, updated_by)
             VALUES (:d, :r, :sn, :sc, :sa, :cur, :inc, :pd, :pt, :u, :u2)'
        )->execute([
            'd' => $row['division_id'], 'r' => $row['reference'], 'sn' => $row['supplier_name'], 'sc' => $row['supplier_country'],
            'sa' => $row['supplier_address'], 'cur' => $row['currency_code'], 'inc' => $row['incoterm'], 'pd' => $row['po_date'],
            'pt' => $row['payment_terms'], 'u' => $userId, 'u2' => $userId,
        ]);
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
        Database::connection()->prepare('UPDATE import_shipments SET ' . implode(', ', $sets) . ', updated_by = :u WHERE id = :id')->execute($params);
    }

    /** Moves the stage only from the stage the caller saw (two people clicking at once move it once). */
    public static function moveStage(int $id, string $from, string $to, int $userId): bool
    {
        $stmt = Database::connection()->prepare('UPDATE import_shipments SET stage = :to, updated_by = :u WHERE id = :id AND stage = :from');
        $stmt->execute(['to' => $to, 'u' => $userId, 'id' => $id, 'from' => $from]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    public static function lines(int $shipmentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT l.*, p.product_code, p.name AS product_name FROM import_shipment_lines l JOIN products p ON p.id = l.product_id
              WHERE l.shipment_id = :s AND l.is_active = 1 ORDER BY l.line_no, l.id'
        );
        $stmt->execute(['s' => $shipmentId]);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $line */
    public static function addLine(int $shipmentId, array $line, int $userId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(line_no), 0) + 1 FROM import_shipment_lines WHERE shipment_id = :s');
        $stmt->execute(['s' => $shipmentId]);
        $lineNo = (int) $stmt->fetchColumn();
        $pdo->prepare(
            'INSERT INTO import_shipment_lines (shipment_id, line_no, product_id, description, hsn_code, quantity, unit, unit_price_fc, bcd_pct, sws_pct, igst_pct, created_by)
             VALUES (:s, :n, :p, :d, :h, :q, :un, :pr, :b, :sw, :i, :u)'
        )->execute([
            's' => $shipmentId, 'n' => $lineNo, 'p' => $line['product_id'], 'd' => $line['description'], 'h' => $line['hsn_code'],
            'q' => $line['quantity'], 'un' => $line['unit'], 'pr' => $line['unit_price_fc'], 'b' => $line['bcd_pct'],
            'sw' => $line['sws_pct'], 'i' => $line['igst_pct'], 'u' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function removeLine(int $shipmentId, int $lineId): bool
    {
        $stmt = Database::connection()->prepare('UPDATE import_shipment_lines SET is_active = 0 WHERE id = :id AND shipment_id = :s AND is_active = 1');
        $stmt->execute(['id' => $lineId, 's' => $shipmentId]);
        return $stmt->rowCount() === 1;
    }

    /** @param array<string,mixed> $figures value_inr, assessable_inr, bcd_inr, sws_inr, igst_inr, landed_inr, landed_unit_inr */
    public static function setLineFigures(int $lineId, array $figures): void
    {
        Database::connection()->prepare(
            'UPDATE import_shipment_lines SET value_inr = :v, assessable_inr = :a, bcd_inr = :b, sws_inr = :s, igst_inr = :i, landed_inr = :l, landed_unit_inr = :lu WHERE id = :id'
        )->execute([
            'v' => $figures['value_inr'], 'a' => $figures['assessable_inr'], 'b' => $figures['bcd_inr'], 's' => $figures['sws_inr'],
            'i' => $figures['igst_inr'], 'l' => $figures['landed_inr'], 'lu' => $figures['landed_unit_inr'], 'id' => $lineId,
        ]);
    }

    // ------------------------------------------------------------ supplier payments

    /** @return list<array<string,mixed>> */
    public static function payments(int $shipmentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, u.name AS recorded_by_name FROM import_payments p LEFT JOIN users u ON u.id = p.recorded_by
              WHERE p.shipment_id = :s ORDER BY p.paid_on, p.id'
        );
        $stmt->execute(['s' => $shipmentId]);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $row */
    public static function addPayment(int $shipmentId, array $row, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO import_payments (shipment_id, paid_on, kind, amount_fc, fx_rate, amount_inr, bank_reference, description, note, recorded_by)
             VALUES (:s, :d, :k, :a, :fx, :inr, :b, :desc, :n, :u)'
        )->execute([
            's' => $shipmentId, 'd' => $row['paid_on'], 'k' => $row['kind'], 'a' => $row['amount_fc'], 'fx' => $row['fx_rate'],
            'inr' => $row['amount_inr'], 'b' => $row['bank_reference'], 'desc' => $row['description'], 'n' => $row['note'], 'u' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function bankReferenceUsed(string $bankReference): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM import_payments WHERE bank_reference = :b LIMIT 1');
        $stmt->execute(['b' => $bankReference]);
        return $stmt->fetchColumn() !== false;
    }

    // ------------------------------------------------------------ stock

    /** @return array<string,mixed>|null the balance row, locked until the transaction ends */
    public static function lockBalance(int $divisionId, int $productId): ?array
    {
        $pdo = Database::connection();
        // Make sure the row exists so that FOR UPDATE has something to lock (an unseen product starts at zero).
        $pdo->prepare('INSERT IGNORE INTO stock_balances (division_id, product_id, qty_on_hand, value_inr) VALUES (:d, :p, 0, 0)')
            ->execute(['d' => $divisionId, 'p' => $productId]);
        $stmt = $pdo->prepare('SELECT * FROM stock_balances WHERE division_id = :d AND product_id = :p FOR UPDATE');
        $stmt->execute(['d' => $divisionId, 'p' => $productId]);
        return $stmt->fetch() ?: null;
    }

    public static function setBalance(int $divisionId, int $productId, string $qty, string $value): void
    {
        Database::connection()->prepare('UPDATE stock_balances SET qty_on_hand = :q, value_inr = :v WHERE division_id = :d AND product_id = :p')
            ->execute(['q' => $qty, 'v' => $value, 'd' => $divisionId, 'p' => $productId]);
    }

    /** @param array<string,mixed> $m */
    public static function addMovement(array $m, int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO stock_movements (division_id, product_id, moved_on, kind, quantity, value_inr, shipment_id, order_id, note, created_by)
             VALUES (:d, :p, :on, :k, :q, :v, :s, :o, :n, :u)'
        )->execute([
            'd' => $m['division_id'], 'p' => $m['product_id'], 'on' => $m['moved_on'], 'k' => $m['kind'], 'q' => $m['quantity'],
            'v' => $m['value_inr'], 's' => $m['shipment_id'] ?? null, 'o' => $m['order_id'] ?? null, 'n' => $m['note'] ?? null, 'u' => $userId,
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function balance(int $divisionId, int $productId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM stock_balances WHERE division_id = :d AND product_id = :p');
        $stmt->execute(['d' => $divisionId, 'p' => $productId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param list<int>|null $divisionIds
     * @return list<array<string,mixed>> stock on hand per product (zero rows left out)
     */
    public static function stockOnHand(?array $divisionIds): array
    {
        $where = 'b.qty_on_hand <> 0';
        if ($divisionIds !== null) {
            $where .= ' AND b.division_id IN (' . ($divisionIds === [] ? '0' : implode(',', array_map('intval', $divisionIds))) . ')';
        }
        return Database::connection()->query(
            "SELECT b.division_id, d.code AS division_code, b.product_id, p.product_code, p.name AS product_name, b.qty_on_hand, b.value_inr
               FROM stock_balances b JOIN products p ON p.id = b.product_id JOIN divisions d ON d.id = b.division_id
              WHERE {$where} ORDER BY p.name, b.division_id"
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> the latest movements of a division, newest first */
    public static function movements(?array $divisionIds, int $limit = 100): array
    {
        $where = $divisionIds === null ? '1=1' : 'm.division_id IN (' . ($divisionIds === [] ? '0' : implode(',', array_map('intval', $divisionIds))) . ')';
        return Database::connection()->query(
            "SELECT m.*, p.product_code, p.name AS product_name, s.reference AS shipment_reference, o.order_reference
               FROM stock_movements m JOIN products p ON p.id = m.product_id
               LEFT JOIN import_shipments s ON s.id = m.shipment_id LEFT JOIN orders o ON o.id = m.order_id
              WHERE {$where} ORDER BY m.moved_on DESC, m.id DESC LIMIT " . max(1, min(500, $limit))
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> the products a line may use (active) */
    public static function products(): array
    {
        return Database::connection()->query('SELECT id, product_code, name, hs_code FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function product(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, product_code, name, hs_code, is_active FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
