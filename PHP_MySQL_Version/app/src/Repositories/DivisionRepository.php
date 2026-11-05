<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Services\DivisionService;

/**
 * Owner decision D-257 (5 Nov 2026): Admin > Divisions — modules, the
 * division's own settings, the pre-flight checklist and activation.
 * Port: divisionRepository.js.
 */
final class DivisionRepository
{
    /** @return array<string,bool> module key => on, every module listed (a missing row is on) */
    public static function modules(int $divisionId): array
    {
        $out = [];
        foreach (array_keys(DivisionService::MODULES) as $key) {
            $out[$key] = DivisionService::moduleEnabledFor($divisionId, $key);
        }
        return $out;
    }

    /** @param list<string> $enabledKeys */
    public static function saveModules(int $divisionId, array $enabledKeys, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO division_modules (division_id, module_key, is_enabled, updated_by) VALUES (:d, :k, :e, :u)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_by = VALUES(updated_by)'
        );
        foreach (array_keys(DivisionService::MODULES) as $key) {
            $stmt->execute(['d' => $divisionId, 'k' => $key, 'e' => in_array($key, $enabledKeys, true) ? 1 : 0, 'u' => $userId]);
        }
        DivisionService::resetCache();
    }

    /** @return list<array<string,mixed>> */
    public static function settings(int $divisionId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM division_settings WHERE division_id = :d ORDER BY scope, setting_key');
        $stmt->execute(['d' => $divisionId]);
        return $stmt->fetchAll();
    }

    public static function setSetting(int $divisionId, string $scope, string $key, string $value, int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO division_settings (division_id, scope, setting_key, setting_value, updated_by) VALUES (:d, :s, :k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        )->execute(['d' => $divisionId, 's' => $scope, 'k' => $key, 'v' => $value, 'u' => $userId]);
        DivisionService::resetCache();
    }

    public static function deleteSetting(int $divisionId, string $scope, string $key): void
    {
        Database::connection()->prepare('DELETE FROM division_settings WHERE division_id = :d AND scope = :s AND setting_key = :k')
            ->execute(['d' => $divisionId, 's' => $scope, 'k' => $key]);
        DivisionService::resetCache();
    }

    /** @return array<string,int> orders, staff, departments, clients per division id */
    public static function counts(int $divisionId): array
    {
        $pdo = Database::connection();
        $one = static function (string $sql) use ($pdo, $divisionId): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['d' => $divisionId]);
            return (int) $stmt->fetchColumn();
        };
        return [
            'orders' => $one('SELECT COUNT(*) FROM orders WHERE division_id = :d'),
            'staff' => $one('SELECT COUNT(*) FROM users WHERE division_id = :d AND is_active = 1'),
            'departments' => $one('SELECT COUNT(*) FROM departments WHERE division_id = :d AND is_active = 1'),
            'clients' => $one('SELECT COUNT(*) FROM client_divisions WHERE division_id = :d'),
        ];
    }

    /**
     * The pre-flight checklist before a planned division is activated: each
     * item [label, done, how to fix].
     *
     * @return list<array{label:string, ok:bool, help:string}>
     */
    public static function preflight(int $divisionId): array
    {
        $c = self::counts($divisionId);
        $own = [];
        foreach (self::settings($divisionId) as $row) {
            $own[$row['scope'] . ':' . $row['setting_key']] = true;
        }
        $modulesOn = array_filter(self::modules($divisionId));
        $items = [
            ['label' => 'At least one department works in this division', 'ok' => $c['departments'] > 0, 'help' => 'Admin › Departments: set the Division of its departments.'],
            ['label' => 'At least one active staff member works in this division', 'ok' => $c['staff'] > 0, 'help' => 'Admin › Users: set the Division of its staff.'],
            ['label' => 'Order number format set', 'ok' => isset($own['company:order_ref_format']), 'help' => 'Add the division setting "order_ref_format" below (e.g. NC/DOM/{YY}/{DDMM}{NNN}).'],
            ['label' => 'Modules chosen', 'ok' => $modulesOn !== [], 'help' => 'Tick the modules this division uses.'],
            ['label' => 'Settings reviewed (company, pricing, incentive)', 'ok' => count($own) >= 2, 'help' => 'Add the division\'s own values where they differ from the company (at least the number format and one more).'],
        ];
        // D-257 Phase 2: what Domestic Trade needs before its first GST sale.
        $division = DivisionService::find($divisionId);
        if (($division['code'] ?? '') === \App\Services\DomesticOrderService::CODE) {
            $gstin = (string) (CompanySettingsRepository::companyValue('gstin') ?? '');
            $items[] = ['label' => 'NexaCrest\'s GSTIN is valid (it gives the GST state)', 'ok' => \App\Services\GstCalculator::isValidGstin($gstin), 'help' => 'Settings: enter the company GSTIN.'];
            $items[] = ['label' => 'GST rates set (confirm them with the CA)', 'ok' => \App\Repositories\DomesticOrderRepository::gstRates(true) !== [], 'help' => 'Admin › GST rates: add or confirm the rate per HSN.'];
            $items[] = ['label' => 'E-way bill threshold and e-invoicing set (confirm them with the CA)', 'ok' => isset($own['company:dom_eway_threshold_inr'], $own['company:dom_e_invoice_applicable']), 'help' => 'Division settings dom_eway_threshold_inr (₹50,000 as a start) and dom_e_invoice_applicable (0 or 1).'];
            $items[] = ['label' => 'Export-only modules switched off (export shipping, LUT, benefits)', 'ok' => !DivisionService::moduleEnabledFor($divisionId, 'export_shipping') && !DivisionService::moduleEnabledFor($divisionId, 'lut_igst') && !DivisionService::moduleEnabledFor($divisionId, 'export_benefits'), 'help' => 'Untick them under Modules.'];
        }
        return $items;
    }

    /** D-257 Phase 2: the starting departments of a planned division, created on request from Admin › Divisions. */
    public const STARTER_DEPARTMENTS = [
        'DOM' => [['DOM_SALES', 'Domestic Sales', '#2D7D56'], ['DOM_PURCHASE', 'Domestic Purchase', '#1D6FA8'], ['DOM_DISPATCH', 'Domestic Dispatch', '#8A5A00']],
    ];

    /** Creates the division's starting departments that do not exist yet; returns how many were made. */
    public static function createStarterDepartments(int $divisionId): int
    {
        $division = DivisionService::find($divisionId);
        $made = 0;
        foreach (self::STARTER_DEPARTMENTS[$division['code'] ?? ''] ?? [] as [$code, $name, $color]) {
            if (DepartmentRepository::departmentCodeTaken($code) || DepartmentRepository::departmentNameTaken($name)) {
                continue;
            }
            $id = DepartmentRepository::createDepartment($code, $name, $color);
            self::setDepartmentDivision($id, $divisionId);
            $made++;
        }
        return $made;
    }

    public static function setStatus(int $divisionId, string $status, int $userId): void
    {
        Database::connection()->prepare(
            "UPDATE divisions SET status = :s, activated_at = IF(:s2 = 'active', NOW(), activated_at), activated_by = IF(:s3 = 'active', :u, activated_by) WHERE id = :id"
        )->execute(['s' => $status, 's2' => $status, 's3' => $status, 'u' => $userId, 'id' => $divisionId]);
        DivisionService::resetCache();
    }

    public static function setUserDivision(int $userId, ?int $divisionId): void
    {
        Database::connection()->prepare('UPDATE users SET division_id = :d WHERE id = :id')->execute(['d' => $divisionId, 'id' => $userId]);
        DivisionService::resetCache();
    }

    public static function setDepartmentDivision(int $departmentId, ?int $divisionId): void
    {
        Database::connection()->prepare('UPDATE departments SET division_id = :d WHERE id = :id')->execute(['d' => $divisionId, 'id' => $departmentId]);
    }

    /** @return list<array<string,mixed>> active staff who work in another division (company-level people are left out) */
    public static function staffChoices(int $divisionId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, name, email FROM users WHERE is_active = 1 AND division_id IS NOT NULL AND division_id <> :d ORDER BY name');
        $stmt->execute(['d' => $divisionId]);
        return $stmt->fetchAll();
    }
}
