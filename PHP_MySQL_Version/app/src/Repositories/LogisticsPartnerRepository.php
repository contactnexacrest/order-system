<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * CHA / Transportation partner directory (docs/schema.sql Section AQ) —
 * standalone master data, not yet linked to a specific order or cost
 * entry. service_type covers a partner providing CHA only, transportation
 * only, or both, since the same company sometimes does either or both.
 */
final class LogisticsPartnerRepository
{
    public const SERVICE_TYPES = [
        'cha'            => 'CHA',
        'transportation' => 'Transportation',
        'both'           => 'CHA + Transportation',
        // Owner request (2026-10-27): Step 9a picks the freight forwarder from this directory.
        'freight_forwarder' => 'Freight Forwarder',
    ];

    /**
     * Owner request (2 Nov 2026): Step 9a's Freight Forwarder drop-down starts on the partner
     * already used on this order — the forwarder already saved on the order; else the one
     * partner the order's logistics bills come from; else, among several, the only one whose
     * service is freight forwarding. Null when none of these picks exactly one (still changeable).
     *
     * @param array<int,array<string,mixed>> $orderBills the order's bills (any kind; only logistics count)
     * @param array<int,array<string,mixed>> $partners   the directory rows the drop-down offers
     */
    public static function suggestedForwarderId(?array $freight, array $orderBills, array $partners): ?int
    {
        $offered = [];
        foreach ($partners as $p) {
            $offered[(int) $p['id']] = $p;
        }
        $saved = (int) ($freight['freight_forwarder_partner_id'] ?? 0);
        if ($saved > 0 && isset($offered[$saved])) {
            return $saved;
        }
        $used = [];
        foreach ($orderBills as $b) {
            $id = (int) ($b['logistics_partner_id'] ?? 0);
            if (($b['vendor_kind'] ?? '') === 'logistics' && $id > 0 && isset($offered[$id])) {
                $used[$id] = true;
            }
        }
        $used = array_keys($used);
        if (count($used) === 1) {
            return $used[0];
        }
        $forwarders = array_values(array_filter($used, static fn (int $id): bool => ($offered[$id]['service_type'] ?? '') === 'freight_forwarder'));
        return count($forwarders) === 1 ? $forwarders[0] : null;
    }

    /** @return array<int, array<string,mixed>> newest first; $serviceType filters to 'cha'/'transportation' matching either that type alone or 'both' */
    public static function all(bool $includeInactive = false, ?string $serviceType = null): array
    {
        $where = [];
        $params = [];
        if (!$includeInactive) {
            $where[] = 'is_active = 1';
        }
        if ($serviceType !== null && $serviceType !== '') {
            $where[] = "service_type IN (:service_type, 'both')";
            $params['service_type'] = $serviceType;
        }
        $sql = 'SELECT * FROM logistics_partners';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY partner_name';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM logistics_partners WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data, int $createdBy): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO logistics_partners
                (partner_name, service_type, address, city, state, phone, whatsapp_number, email,
                 contact_person_name, contact_person_phone, contact_person_whatsapp, gstin, pan, notes, created_by)
             VALUES
                (:partner_name, :service_type, :address, :city, :state, :phone, :whatsapp_number, :email,
                 :contact_person_name, :contact_person_phone, :contact_person_whatsapp, :gstin, :pan, :notes, :created_by)'
        );
        $stmt->execute(self::bindData($data) + ['created_by' => $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        Database::connection()->prepare(
            'UPDATE logistics_partners SET
                partner_name = :partner_name, service_type = :service_type, address = :address,
                city = :city, state = :state, phone = :phone, whatsapp_number = :whatsapp_number, email = :email,
                contact_person_name = :contact_person_name, contact_person_phone = :contact_person_phone,
                contact_person_whatsapp = :contact_person_whatsapp, gstin = :gstin, pan = :pan, notes = :notes
             WHERE id = :id'
        )->execute(self::bindData($data) + ['id' => $id]);
    }

    /** @return array<string,mixed> */
    private static function bindData(array $data): array
    {
        return [
            'partner_name'             => $data['partner_name'],
            'service_type'             => $data['service_type'],
            'address'                  => $data['address'] ?? null,
            'city'                     => $data['city'] ?? null,
            'state'                    => $data['state'] ?? null,
            'phone'                    => $data['phone'] ?? null,
            'whatsapp_number'          => $data['whatsapp_number'] ?? null,
            'email'                    => $data['email'] ?? null,
            'contact_person_name'      => $data['contact_person_name'] ?? null,
            'contact_person_phone'     => $data['contact_person_phone'] ?? null,
            'contact_person_whatsapp'  => $data['contact_person_whatsapp'] ?? null,
            'gstin'                    => $data['gstin'] ?? null,
            'pan'                      => $data['pan'] ?? null,
            'notes'                    => $data['notes'] ?? null,
        ];
    }

    public static function toggleActive(int $id): void
    {
        Database::connection()->prepare('UPDATE logistics_partners SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM logistics_partners WHERE id = :id')->execute(['id' => $id]);
    }
}
