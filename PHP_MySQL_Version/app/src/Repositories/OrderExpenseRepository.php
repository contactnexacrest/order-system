<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decisions (2026-10-27): the order's actual expenses — travel,
 * hotel, courier, ... — each with its receipt, or a reason when no bill
 * exists. The travel % of FOB (order_travel_expenses) stays the
 * provisional figure until real travel expenses are recorded; a late
 * voucher keeps its real expense_date. docs/schema.sql Section CE.
 */
final class OrderExpenseRepository
{
    public const CATEGORIES = [
        'travel_fuel'      => 'Travel — fuel / tolls',
        'travel_ticket'    => 'Travel — train / flight / bus',
        'local_conveyance' => 'Local conveyance (taxi / auto)',
        'hotel'            => 'Hotel',
        'food'             => 'Food',
        'courier'          => 'Courier',
        'samples'          => 'Samples',
        'other'            => 'Other',
    ];

    /** Categories that are travel — once any is recorded, it replaces the provisional travel %. */
    public const TRAVEL_CATEGORIES = ['travel_fuel', 'travel_ticket', 'local_conveyance', 'hotel', 'food'];

    public const PAID_BY = [
        'company_account' => 'Company account',
        'company_card'    => 'Company card',
        'staff'           => 'Staff (to reimburse)',
    ];

    /** A voucher entered more than this many days after the expense shows as "added later". */
    public const LATE_AFTER_DAYS = 30;

    /** @param array<string,mixed> $data */
    public static function create(int $orderId, array $data, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO order_expenses (order_id, expense_date, category, details, amount_inr, paid_by, staff_name, receipt_file_id, no_receipt_reason, created_by)
             VALUES (:order_id, :expense_date, :category, :details, :amount_inr, :paid_by, :staff_name, :receipt_file_id, :no_receipt_reason, :created_by)'
        )->execute([
            'order_id'          => $orderId,
            'expense_date'      => $data['expense_date'],
            'category'          => $data['category'],
            'details'           => $data['details'],
            'amount_inr'        => $data['amount_inr'],
            'paid_by'           => $data['paid_by'],
            'staff_name'        => $data['staff_name'] ?? null,
            'receipt_file_id'   => $data['receipt_file_id'] ?? null,
            'no_receipt_reason' => $data['no_receipt_reason'] ?? null,
            'created_by'        => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_expenses WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> by expense date, with the recording user's name and an "added later" flag */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT e.*, u.name AS created_by_name,
                    (DATEDIFF(DATE(e.created_at), e.expense_date) > ' . self::LATE_AFTER_DAYS . ') AS added_later
             FROM order_expenses e
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.order_id = :order_id
             ORDER BY e.expense_date, e.id'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM order_expenses WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return array{total: float, travel: float, has_travel: bool, count: int, with_receipt: int, to_reimburse: float} */
    public static function summary(int $orderId): array
    {
        $out = ['total' => 0.0, 'travel' => 0.0, 'has_travel' => false, 'count' => 0, 'with_receipt' => 0, 'to_reimburse' => 0.0];
        foreach (self::forOrder($orderId) as $e) {
            $amount = (float) $e['amount_inr'];
            $out['total'] += $amount;
            $out['count']++;
            if (in_array($e['category'], self::TRAVEL_CATEGORIES, true)) {
                $out['travel'] += $amount;
                $out['has_travel'] = true;
            }
            if (!empty($e['receipt_file_id'])) {
                $out['with_receipt']++;
            }
            if ($e['paid_by'] === 'staff') {
                $out['to_reimburse'] += $amount;
            }
        }
        return $out;
    }
}
