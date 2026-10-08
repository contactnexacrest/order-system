<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Full CRUD for payment_presets (docs/schema.sql Section — payment
 * presets), previously seed-data only with no admin screen. Every
 * order's advance/balance split and balance-trigger wording come from
 * whichever preset it's assigned, via OrderRepository::find()'s
 * COALESCE(override, preset) columns — so editing a preset here changes
 * every order on it, and a NEW preset (e.g. 20% advance) is picked up
 * automatically by DocumentDataAssembler without any further code
 * change (it computes purely off advance_pct/balance_pct, never a
 * hardcoded split).
 *
 * is_protected mirrors the tc_clauses/company_settings pattern (Section
 * L): both seeded presets ship protected, since they drive where money
 * is actually sent/received. A protected preset must be unlocked via
 * the existing Field Protection flow (/admin/field-protection) before
 * it can be edited or deactivated — enforced here AND by the DB
 * trigger (trg_payment_presets_bu) as a backstop.
 */
final class PaymentPresetRepository
{
    /** @return array<int, array<string,mixed>> newest-default-first; includes inactive when $includeInactive */
    public static function all(bool $includeInactive = false): array
    {
        $sql = 'SELECT pp.*, cur.code AS currency_code FROM payment_presets pp JOIN currencies cur ON cur.id = pp.currency_id';
        if (!$includeInactive) {
            $sql .= ' WHERE pp.is_active = 1';
        }
        $sql .= ' ORDER BY pp.is_default DESC, pp.preset_name';
        return Database::connection()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT pp.*, cur.code AS currency_code FROM payment_presets pp JOIN currencies cur ON cur.id = pp.currency_id WHERE pp.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return bool true if any order currently uses this preset (informational — never blocks edit/deactivate on its own) */
    public static function isInUse(int $id): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM orders WHERE payment_preset_id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function create(array $data, int $createdBy): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO payment_presets
                (preset_name, is_default, advance_pct, advance_trigger_text, balance_pct,
                 balance_trigger_option, balance_days, balance_trigger_wording, currency_id,
                 requires_md_approval, is_active, created_by)
             VALUES
                (:preset_name, :is_default, :advance_pct, :advance_trigger_text, :balance_pct,
                 :balance_trigger_option, :balance_days, :balance_trigger_wording, :currency_id,
                 :requires_md_approval, 1, :created_by)'
        );
        $stmt->execute(self::bindData($data) + ['created_by' => $createdBy]);
        $id = (int) $pdo->lastInsertId();

        if (!empty($data['is_default'])) {
            self::clearOtherDefaults($id);
        }
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        Database::connection()->prepare(
            'UPDATE payment_presets SET
                preset_name = :preset_name, is_default = :is_default, advance_pct = :advance_pct,
                advance_trigger_text = :advance_trigger_text, balance_pct = :balance_pct,
                balance_trigger_option = :balance_trigger_option, balance_days = :balance_days,
                balance_trigger_wording = :balance_trigger_wording, currency_id = :currency_id,
                requires_md_approval = :requires_md_approval
             WHERE id = :id'
        )->execute(self::bindData($data) + ['id' => $id]);

        if (!empty($data['is_default'])) {
            self::clearOtherDefaults($id);
        }
    }

    /** Exactly one preset may be is_default=1 (the order form's pre-selected choice) — enforced here in the app layer, not a DB constraint. */
    private static function clearOtherDefaults(int $exceptId): void
    {
        Database::connection()->prepare('UPDATE payment_presets SET is_default = 0 WHERE id != :id')
            ->execute(['id' => $exceptId]);
    }

    /** @throws \RuntimeException if the preset is_protected — caller must unlock via Field Protection first */
    public static function toggleActive(int $id): void
    {
        $preset = self::find($id);
        if ($preset === null) {
            return;
        }
        if ((int) $preset['is_protected'] === 1) {
            throw new \RuntimeException('This preset is protected and cannot be deactivated until unlocked via Field Protection.');
        }
        Database::connection()->prepare('UPDATE payment_presets SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /** @return array<string,mixed> */
    private static function bindData(array $data): array
    {
        return [
            'preset_name'             => $data['preset_name'],
            'is_default'              => !empty($data['is_default']) ? 1 : 0,
            'advance_pct'             => $data['advance_pct'],
            'advance_trigger_text'    => $data['advance_trigger_text'],
            'balance_pct'             => $data['balance_pct'],
            'balance_trigger_option'  => $data['balance_trigger_option'],
            'balance_days'            => $data['balance_days'],
            'balance_trigger_wording' => $data['balance_trigger_wording'] ?? null,
            'currency_id'             => $data['currency_id'],
            'requires_md_approval'    => !empty($data['requires_md_approval']) ? 1 : 0,
        ];
    }
}
