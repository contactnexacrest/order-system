<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\PermissionRepository;

/**
 * Owner decision D-281 (11 Nov 2026, point 3): who is e-mailed about a staff event.
 *
 * Order events: each event type belongs to a department (buyer → Sales & Export,
 * supplier → Procurement, payments → Accounts & Finance, shipping / BL /
 * inspection → Logistics, eBRC / ECGC / benefits → Export Compliance). The
 * e-mail goes only to that department's people on that order — its department
 * owner and the order's assignees who are members of it. With nobody from the
 * department on the order: the department's manager (director when there is no
 * manager), with an "assign someone" note; with no manager either: the MD.
 * The Managing Director, Executive Director and Order Coordinator are not
 * e-mailed for these routine events (they keep the in-app notice).
 *
 * Company-level compliance alerts (LUT, RCMC, agreements, TDS, merchant-trade
 * RBI limits, ECGC policy, monthly eBRC): the responsible person and the backup
 * named in Admin › E-mail settings; fallback the department's manager, then the MD.
 */
final class StaffRouting
{
    /** notifications.type => department code. Types not listed belong to Sales & Export. */
    public const DEPARTMENT_FOR_TYPE = [
        'buyer_oc_acknowledged' => 'SALES', 'buyer_po_uploaded_by_client' => 'SALES', 'order_comment_from_client' => 'SALES',
        'dispute_raised' => 'SALES', 'order_feedback_submitted' => 'SALES', 'reorder_request_submitted' => 'SALES',
        'pi_intake_submitted' => 'SALES', 'dispute_overdue' => 'SALES', 'quotation_expired' => 'SALES', 'pi_expired' => 'SALES',
        'complaint_raised' => 'SALES',
        'supplier_po_ack_uploaded_by_supplier' => 'PROC',
        'client_payment_reported' => 'FIN', 'fdn_overdue' => 'FIN', 'balance_overdue' => 'FIN',
        'lc_expiry_soon' => 'FIN', 'lc_last_shipment_soon' => 'FIN', 'lc_presentation_soon' => 'FIN',
        'inspection_failed' => 'LOG', 'pre_shipment_inspection_reminder' => 'LOG',
        'ecgc_declaration_due' => 'EXPORT_COMPLIANCE', 'ecgc_declaration_overdue' => 'EXPORT_COMPLIANCE',
        'benefit_claim_deadline' => 'EXPORT_COMPLIANCE',
    ];

    /** Roles never e-mailed for routine events (in-app only). */
    public const ROUTINE_IN_APP_ONLY_ROLES = ['Managing Director', 'Executive Director', 'Order Coordinator'];

    /** Company-level compliance items: key => [label, department that falls back]. */
    public const COMPANY_ITEMS = [
        'lut' => ['LUT (Letter of Undertaking) renewal', 'EXPORT_COMPLIANCE'],
        'rcmc' => ['RCMC certificate renewal', 'EXPORT_COMPLIANCE'],
        'agreements' => ['Agreements and registrations ending (IEC, client / associate / supplier agreements)', 'EXPORT_COMPLIANCE'],
        'tds' => ['TDS on associate commission — deposit by the 7th, Form 26Q each quarter', 'FIN'],
        'merchant_trade_rbi' => ['Merchant trade — RBI time limits', 'FIN'],
        'ecgc_policy' => ['ECGC policy renewal', 'FIN'],
        'ebrc_monthly' => ['Missing eBRC / FIRC — monthly list', 'FIN'],
    ];

    public static function departmentFor(string $type): string
    {
        return self::DEPARTMENT_FOR_TYPE[$type] ?? 'SALES';
    }

    public static function departmentName(string $code): string
    {
        $stmt = Database::connection()->prepare('SELECT name FROM departments WHERE code = :c');
        $stmt->execute(['c' => $code]);
        $name = $stmt->fetchColumn();
        return $name === false ? $code : (string) $name;
    }

    /** Whether a person holds (by own role or a current membership) the MD, ED or Order Coordinator role. */
    public static function isRoutineInAppOnly(int $userId): bool
    {
        $in = implode(',', array_fill(0, count(self::ROUTINE_IN_APP_ONLY_ROLES), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.name IN ({$in})
             UNION ALL
             SELECT 1 FROM user_department_memberships m JOIN department_designations dd ON dd.id = m.designation_id JOIN roles r ON r.id = dd.role_id
              WHERE m.user_id = ? AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE()) AND r.name IN ({$in})
             LIMIT 1"
        );
        $stmt->execute(array_merge([$userId], self::ROUTINE_IN_APP_ONLY_ROLES, [$userId], self::ROUTINE_IN_APP_ONLY_ROLES));
        return $stmt->fetchColumn() !== false;
    }

    /** @param list<int> $ids @return list<int> */
    private static function withoutInAppOnly(array $ids): array
    {
        return array_values(array_filter(array_unique(array_map('intval', $ids)), static fn (int $id): bool => !self::isRoutineInAppOnly($id)));
    }

    /** @return list<int> the department's owner on the order and the order's assignees who are members of it (active users) */
    public static function departmentPeopleOnOrder(int $orderId, string $deptCode): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT odo.user_id FROM order_department_owners odo JOIN departments d ON d.id = odo.department_id JOIN users u ON u.id = odo.user_id
              WHERE odo.order_id = :o1 AND d.code = :c1 AND u.is_active = 1
             UNION
             SELECT oa.user_id FROM order_assignees oa
               JOIN users u ON u.id = oa.user_id
               JOIN user_department_memberships m ON m.user_id = oa.user_id
               JOIN department_designations dd ON dd.id = m.designation_id
               JOIN departments d ON d.id = dd.department_id
              WHERE oa.order_id = :o2 AND d.code = :c2 AND u.is_active = 1
                AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())"
        );
        $stmt->execute(['o1' => $orderId, 'c1' => $deptCode, 'o2' => $orderId, 'c2' => $deptCode]);
        return array_values(array_unique(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));
    }

    /** @return list<int> the department's managers today; its directors when it has no manager */
    public static function managers(string $deptCode): array
    {
        foreach (['manager', 'director'] as $level) {
            $stmt = Database::connection()->prepare(
                "SELECT DISTINCT m.user_id FROM user_department_memberships m
                   JOIN department_designations dd ON dd.id = m.designation_id
                   JOIN departments d ON d.id = dd.department_id
                   JOIN users u ON u.id = m.user_id
                  WHERE d.code = :c AND dd.level = :l AND dd.is_active = 1 AND u.is_active = 1
                    AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
                  ORDER BY m.user_id"
            );
            $stmt->execute(['c' => $deptCode, 'l' => $level]);
            $ids = self::withoutInAppOnly($stmt->fetchAll(\PDO::FETCH_COLUMN));
            if ($ids !== []) {
                return $ids;
            }
        }
        return [];
    }

    /** @return list<int> the active Managing Director(s), by own role or membership */
    public static function managingDirectors(): array
    {
        $rows = Database::connection()->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Managing Director' AND u.is_active = 1
             UNION
             SELECT m.user_id FROM user_department_memberships m JOIN department_designations dd ON dd.id = m.designation_id
               JOIN roles r ON r.id = dd.role_id JOIN users u ON u.id = m.user_id
              WHERE r.name = 'Managing Director' AND u.is_active = 1 AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())"
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_unique(array_map('intval', $rows)));
    }

    /**
     * Who is e-mailed about an order event of this department.
     *
     * @return array{email: list<int>, level: string, note: ?string}
     *   level 'order' (people on the order), 'manager' (nobody on the order) or 'md' (no manager either)
     */
    public static function forOrder(int $orderId, string $deptCode): array
    {
        $onOrder = self::withoutInAppOnly(self::departmentPeopleOnOrder($orderId, $deptCode));
        if ($onOrder !== []) {
            return ['email' => $onOrder, 'level' => 'order', 'note' => null];
        }
        $note = 'Nobody from ' . self::departmentName($deptCode) . ' is assigned to this order — please assign someone.';
        $managers = self::managers($deptCode);
        if ($managers !== []) {
            return ['email' => $managers, 'level' => 'manager', 'note' => $note];
        }
        return ['email' => self::managingDirectors(), 'level' => 'md', 'note' => $note];
    }

    /**
     * Associate events: the associate's relationship manager; without one, the
     * people who manage associates (not MD / ED / Coordinator); else the MD.
     * Bank changes also go to Accounts & Finance's verifiers.
     *
     * @param array<string,mixed>|null $associate
     * @return list<int>
     */
    public static function forAssociate(?array $associate, bool $bankChange = false): array
    {
        $ids = [];
        if ($associate !== null && !empty($associate['relationship_manager_id'])) {
            $ids[] = (int) $associate['relationship_manager_id'];
        } else {
            $ids = self::withoutInAppOnly(PermissionRepository::usersWithPermission('manage_associates'));
        }
        if ($bankChange) {
            $ids = array_merge($ids, PermissionRepository::usersWithPermission('manage_associate_bank'));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return $ids !== [] ? $ids : self::managingDirectors();
    }

    // ---- company-level compliance items ----

    /** @return array<string, array{responsible_user_id:?int, backup_user_id:?int}> */
    public static function companyOwners(): array
    {
        $out = [];
        foreach (array_keys(self::COMPANY_ITEMS) as $k) {
            $out[$k] = ['responsible_user_id' => null, 'backup_user_id' => null];
        }
        try {
            foreach (Database::connection()->query('SELECT item_key, responsible_user_id, backup_user_id FROM compliance_alert_owners')->fetchAll() as $r) {
                if (isset($out[$r['item_key']])) {
                    $out[$r['item_key']] = [
                        'responsible_user_id' => $r['responsible_user_id'] !== null ? (int) $r['responsible_user_id'] : null,
                        'backup_user_id' => $r['backup_user_id'] !== null ? (int) $r['backup_user_id'] : null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // A database from before D-281: everyone falls back to the department manager.
        }
        return $out;
    }

    /** @return list<int> who is e-mailed about a company-level item */
    public static function companyItem(string $itemKey): array
    {
        $owners = self::companyOwners()[$itemKey] ?? ['responsible_user_id' => null, 'backup_user_id' => null];
        $ids = [];
        foreach ([$owners['responsible_user_id'], $owners['backup_user_id']] as $id) {
            if ($id !== null) {
                $u = \App\Repositories\UserRepository::findById($id);
                if ($u !== null && (int) $u['is_active'] === 1) {
                    $ids[] = $id;
                }
            }
        }
        if ($ids !== []) {
            return array_values(array_unique($ids));
        }
        $managers = self::managers(self::COMPANY_ITEMS[$itemKey][1] ?? 'FIN');
        return $managers !== [] ? $managers : self::managingDirectors();
    }

    /**
     * @param array<string, array{responsible?:string, backup?:string}> $input item => user ids from the form
     * @return list<string> what changed
     */
    public static function saveCompanyOwners(array $input, int $userId, string $reason): array
    {
        $current = self::companyOwners();
        $changes = [];
        $stmt = Database::connection()->prepare(
            'INSERT INTO compliance_alert_owners (item_key, responsible_user_id, backup_user_id, updated_by) VALUES (:k, :r, :b, :u)
             ON DUPLICATE KEY UPDATE responsible_user_id = VALUES(responsible_user_id), backup_user_id = VALUES(backup_user_id), updated_by = VALUES(updated_by)'
        );
        foreach (self::COMPANY_ITEMS as $key => [$label]) {
            $r = (int) ($input[$key]['responsible'] ?? 0) ?: null;
            $b = (int) ($input[$key]['backup'] ?? 0) ?: null;
            if ($r !== null && $r === $b) {
                $b = null; // the backup is a different person
            }
            if ($r === $current[$key]['responsible_user_id'] && $b === $current[$key]['backup_user_id']) {
                continue;
            }
            $stmt->execute(['k' => $key, 'r' => $r, 'b' => $b, 'u' => $userId]);
            AuditLogRepository::log($userId, 'COMPLIANCE_ALERT_OWNER_CHANGED', 'compliance_alert_owners', null, $key,
                ($current[$key]['responsible_user_id'] ?? '') . '/' . ($current[$key]['backup_user_id'] ?? ''), ($r ?? '') . '/' . ($b ?? ''), $reason);
            $changes[] = $label;
        }
        return $changes;
    }
}
