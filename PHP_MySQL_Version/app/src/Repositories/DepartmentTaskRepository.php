<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-214 (2026-10-31), phase 4: each department's open work,
 * built from the order's own state — so when one step finishes, the next
 * step is already in the next department's queue (no hand-off to forget).
 * Each kind of task has standard days (due) and escalation days (to
 * management), set in Admin. No amounts: a task says what to do, not how
 * much. Port: departmentTaskRepository.js.
 */
final class DepartmentTaskRepository
{
    /** Every open task: task_key, order, subject, the date it became due to start ("since") and the page to do it on. */
    private const OPEN_WORK = "
        SELECT o.id AS order_id, 'raise_purchase_requirement' AS task_key, 'Advance cleared — no purchase requirement yet' AS subject,
               DATE(ps.advance_cleared_at) AS since, CONCAT('/orders/', o.id, '#purchase-requirements') AS link
          FROM orders o JOIN order_payment_status ps ON ps.order_id = o.id
         WHERE o.status = 'active' AND ps.advance_cleared_at IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM purchase_requirements pr WHERE pr.order_id = o.id AND pr.status <> 'cancelled')
           AND NOT EXISTS (SELECT 1 FROM order_supplier_po sp WHERE sp.order_id = o.id)
        UNION ALL
        SELECT pr.order_id, 'quote_and_award', CONCAT('PR-', pr.id, ' · ', pr.description), DATE(pr.raised_at), CONCAT('/procurement/', pr.id)
          FROM purchase_requirements pr JOIN orders o ON o.id = pr.order_id
         WHERE o.status = 'active' AND pr.status IN ('open', 'quoted')
        UNION ALL
        SELECT pr.order_id, 'approve_purchase', CONCAT('PR-', pr.id, ' · ', pr.description), DATE(pr.awarded_at), CONCAT('/procurement/', pr.id)
          FROM purchase_requirements pr JOIN orders o ON o.id = pr.order_id
         WHERE o.status = 'active' AND pr.status = 'awarded'
        UNION ALL
        SELECT pr.order_id, 'chase_supplier', CONCAT('PR-', pr.id, ' · ', pr.description, ' — needed by ', DATE_FORMAT(pr.needed_by, '%Y-%m-%d')), pr.needed_by, CONCAT('/procurement/', pr.id)
          FROM purchase_requirements pr JOIN orders o ON o.id = pr.order_id
         WHERE o.status = 'active' AND pr.status = 'approved'
        UNION ALL
        SELECT pr.order_id, 'book_freight', CONCAT('PR-', pr.id, ' · ', pr.description, ' received'), pr.received_on, CONCAT('/orders/', pr.order_id)
          FROM purchase_requirements pr JOIN orders o ON o.id = pr.order_id
         WHERE o.status = 'active' AND pr.status = 'received' AND pr.qc_result <> 'failed'
           AND NOT EXISTS (SELECT 1 FROM order_freight f WHERE f.order_id = pr.order_id)
        UNION ALL
        SELECT o.id, 'clear_buyer_payment', 'Advance remittance received', DATE(ps.advance_remittance_received_at), CONCAT('/orders/', o.id)
          FROM orders o JOIN order_payment_status ps ON ps.order_id = o.id
         WHERE o.status = 'active' AND ps.advance_remittance_received_at IS NOT NULL AND ps.advance_cleared_at IS NULL
        UNION ALL
        SELECT o.id, 'clear_buyer_payment', 'Freight remittance received', DATE(ps.freight_remittance_received_at), CONCAT('/orders/', o.id)
          FROM orders o JOIN order_payment_status ps ON ps.order_id = o.id
         WHERE o.status = 'active' AND ps.freight_remittance_received_at IS NOT NULL AND ps.freight_cleared_at IS NULL
        UNION ALL
        SELECT o.id, 'clear_buyer_payment', 'Balance remittance received', DATE(ps.balance_remittance_received_at), CONCAT('/orders/', o.id)
          FROM orders o JOIN order_payment_status ps ON ps.order_id = o.id
         WHERE o.status = 'active' AND ps.balance_remittance_received_at IS NOT NULL AND ps.balance_cleared_at IS NULL
        UNION ALL
        SELECT b.order_id, 'pay_supplier_bill', CONCAT(IF(b.vendor_kind = 'supplier', 'Supplier', 'Logistics partner'), ' bill ', b.bill_no), DATE(b.created_at), CONCAT('/orders/', b.order_id)
          FROM order_vendor_bills b JOIN orders o ON o.id = b.order_id
         WHERE o.status = 'active' AND b.paid_at IS NULL
           AND NOT EXISTS (SELECT 1 FROM order_vendor_bills r WHERE r.replaces_bill_id = b.id)
        UNION ALL
        SELECT o.id, 'send_documents', 'Balance cleared — documents to the buyer', DATE(ps.balance_cleared_at), CONCAT('/orders/', o.id)
          FROM orders o JOIN order_payment_status ps ON ps.order_id = o.id
         WHERE o.status = 'active' AND ps.balance_cleared_at IS NOT NULL";

    /**
     * @param list<string>|null $departmentCodes only these departments' tasks (null = every department)
     * @return list<array<string,mixed>> each with due_on, overdue, escalated, the department's owner of the order
     */
    public static function open(?array $departmentCodes = null): array
    {
        $params = [];
        $where = '';
        if ($departmentCodes !== null) {
            if ($departmentCodes === []) {
                return [];
            }
            $in = [];
            foreach (array_values($departmentCodes) as $i => $code) {
                $in[] = ":d{$i}";
                $params["d{$i}"] = $code;
            }
            $where = 'WHERE tt.department_code IN (' . implode(', ', $in) . ')';
        }
        $stmt = Database::connection()->prepare(
            "SELECT w.*, o.order_reference, tt.department_code, tt.title, d.id AS department_id, d.name AS department_name, d.color AS department_color,
                    DATE_ADD(w.since, INTERVAL tt.standard_days DAY) AS due_on,
                    (CURDATE() > DATE_ADD(w.since, INTERVAL tt.standard_days DAY)) AS overdue,
                    (CURDATE() > DATE_ADD(w.since, INTERVAL tt.standard_days + tt.escalate_after_days DAY)) AS escalated,
                    odo.user_id AS owner_id, u.name AS owner_name
               FROM (" . self::OPEN_WORK . ") w
               JOIN department_task_types tt ON tt.task_key = w.task_key
               JOIN orders o ON o.id = w.order_id
               LEFT JOIN departments d ON d.code = tt.department_code
               LEFT JOIN order_department_owners odo ON odo.order_id = w.order_id AND odo.department_id = d.id
               LEFT JOIN users u ON u.id = odo.user_id
               {$where}
              ORDER BY due_on, w.order_id, tt.sort_order"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Whose work a person sees, from the membership they work as: a department
     * with its own kinds of task (Sales, Procurement, Logistics, Finance) works
     * its own queue; one without (Management, the Order Desk, Audit) — or no
     * membership — sees every department's.
     * @param array<string,mixed>|null $membership
     * @return list<string>|null department codes, or null for every department
     */
    public static function scopeFor(?array $membership): ?array
    {
        if ($membership === null) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT d.code FROM departments d WHERE d.id = :id AND EXISTS (SELECT 1 FROM department_task_types tt WHERE tt.department_code = d.code)');
        $stmt->execute(['id' => (int) $membership['department_id']]);
        $code = $stmt->fetchColumn();
        return $code === false ? null : [(string) $code];
    }

    /** @return list<array<string,mixed>> */
    public static function types(): array
    {
        return Database::connection()->query(
            'SELECT tt.*, d.name AS department_name FROM department_task_types tt LEFT JOIN departments d ON d.code = tt.department_code ORDER BY tt.sort_order'
        )->fetchAll();
    }

    public static function updateType(string $taskKey, int $standardDays, int $escalateAfterDays): void
    {
        Database::connection()->prepare('UPDATE department_task_types SET standard_days = :s, escalate_after_days = :e WHERE task_key = :k')
            ->execute(['s' => $standardDays, 'e' => $escalateAfterDays, 'k' => $taskKey]);
    }

    // ---- one owner per department per order ----

    /** @return list<array<string,mixed>> the order's owner per department (departments without one included, owner null) */
    public static function ownersForOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT d.id AS department_id, d.code, d.name, d.color, odo.user_id, u.name AS owner_name
               FROM departments d
               LEFT JOIN order_department_owners odo ON odo.department_id = d.id AND odo.order_id = :o
               LEFT JOIN users u ON u.id = odo.user_id
              WHERE d.is_active = 1 AND d.code IN ('SALES', 'PROC', 'LOG', 'FIN')
              ORDER BY d.sort_order"
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> people who can own an order for this department today (an active member of it) */
    public static function candidates(int $departmentId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT u.id, u.name, dd.title AS designation_title
               FROM user_department_memberships m
               JOIN department_designations dd ON dd.id = m.designation_id
               JOIN users u ON u.id = m.user_id
              WHERE dd.department_id = :d AND u.is_active = 1 AND dd.is_active = 1
                AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
              ORDER BY u.name, dd.title"
        );
        $stmt->execute(['d' => $departmentId]);
        return $stmt->fetchAll();
    }

    public static function setOwner(int $orderId, int $departmentId, int $userId, int $assignedBy): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO order_department_owners (order_id, department_id, user_id, assigned_by) VALUES (:o, :d, :u, :b)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), assigned_by = VALUES(assigned_by), assigned_at = CURRENT_TIMESTAMP'
        )->execute(['o' => $orderId, 'd' => $departmentId, 'u' => $userId, 'b' => $assignedBy]);
        // The owner must be able to open the order.
        $pdo->prepare('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (:o, :u, :b)')
            ->execute(['o' => $orderId, 'u' => $userId, 'b' => $assignedBy]);
    }

    /** @return array<string,int> active orders by status, for the cockpit */
    public static function ordersByStatus(): array
    {
        $rows = Database::connection()->query("SELECT status, COUNT(*) AS n FROM orders WHERE status NOT IN ('complete', 'lost', 'cancelled') GROUP BY status ORDER BY status")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
