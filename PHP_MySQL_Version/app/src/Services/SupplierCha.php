<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\ReasonValidator;
use App\Repositories\AuditLogRepository;

/**
 * Owner decision D-381 (18 Nov 2026): CHA + transport per container, factory → port, per
 * supplier. The Edit Supplier form has a box "CHA + transport per container, factory → port (₹)"
 * and "Port this figure is for" (optional), saved with a reason when either changes (audit
 * SUPPLIER_CHA_CHANGED, old → new). A supplier-price row without its own figure uses the
 * supplier's (ThicknessPrices::chaSource: row → supplier → product default → none).
 * Shown and saved only for those who see purchase prices or logistics costs.
 * Node twin: src/services/supplierCha.js.
 */
final class SupplierCha
{
    public const MAX = 9999999999.99;

    /** Whether the signed-in person sees (and may change) a supplier's CHA + transport figure. */
    public static function canSee(): bool
    {
        return MoneyVisibility::can(MoneyVisibility::PURCHASE) || MoneyVisibility::can(MoneyVisibility::LOGISTICS);
    }

    /** "₹45,000.00 (Mundra)", "₹45,000.00", or "none". */
    public static function text(?float $amount, ?string $port): string
    {
        if ($amount === null) {
            return 'none';
        }
        $port = $port !== null && trim($port) !== '' ? trim($port) : null;
        return '₹' . number_format($amount, 2) . ($port !== null ? ' (' . $port . ')' : '');
    }

    /**
     * Reads the box from the posted form and checks it (the same checks the browser does first).
     * Returns [null, null] when nothing changed, [changes, null] to save, or [null, error].
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $supplier the stored supplier row
     * @return array{0: ?array{amount: ?float, port: ?string, reason: string}, 1: ?string}
     */
    public static function read(array $post, array $supplier): array
    {
        if (!array_key_exists('cha_transport_per_container', $post) && !array_key_exists('cha_port', $post)) {
            return [null, null];
        }
        $raw = trim(str_replace(',', '', (string) ($post['cha_transport_per_container'] ?? '')));
        $amount = null;
        if ($raw !== '') {
            if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $raw) !== 1 || (float) $raw > self::MAX) {
                return [null, 'CHA + transport per container: enter ₹ (at most 2 decimals), or leave it empty.'];
            }
            $amount = (float) $raw;
        }
        $port = trim((string) ($post['cha_port'] ?? ''));
        if (mb_strlen($port) > 100) {
            return [null, 'Port this figure is for: at most 100 characters.'];
        }
        $port = $port !== '' ? $port : null;
        if ($port !== null && $amount === null) {
            return [null, 'Port this figure is for: enter the CHA + transport figure too, or leave both empty.'];
        }
        $oldAmount = ($supplier['cha_transport_per_container'] ?? null) !== null ? (float) $supplier['cha_transport_per_container'] : null;
        $oldPort = ($supplier['cha_port'] ?? null) !== null && trim((string) $supplier['cha_port']) !== '' ? trim((string) $supplier['cha_port']) : null;
        $same = ($oldAmount === null ? $amount === null : $amount !== null && abs($oldAmount - $amount) < 0.005) && $oldPort === $port;
        if ($same) {
            return [null, null];
        }
        $reason = trim((string) ($post['cha_reason'] ?? ''));
        if (($error = ReasonValidator::check($reason)) !== null) {
            return [null, 'CHA + transport — reason for the change: ' . $error];
        }
        return [['amount' => $amount, 'port' => $port, 'reason' => $reason], null];
    }

    /** Saves the figure and port and writes the audit row (old → new, with the reason). */
    public static function save(int $supplierId, array $supplier, array $change, int $userId): void
    {
        Database::connection()->prepare('UPDATE suppliers SET cha_transport_per_container = :a, cha_port = :p WHERE id = :id')
            ->execute(['a' => $change['amount'] !== null ? number_format((float) $change['amount'], 2, '.', '') : null, 'p' => $change['port'], 'id' => $supplierId]);
        $old = self::text(($supplier['cha_transport_per_container'] ?? null) !== null ? (float) $supplier['cha_transport_per_container'] : null, $supplier['cha_port'] ?? null);
        $new = self::text($change['amount'], $change['port']);
        AuditLogRepository::log($userId, 'SUPPLIER_CHA_CHANGED', 'suppliers', $supplierId, 'cha_transport_per_container', $old, $new, $change['reason']);
    }
}
