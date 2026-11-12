<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\TermsClauseRepository;

/**
 * Owner decision D-262 (6 Nov 2026, double-confirmed): an order is self-contained.
 *
 * When an order's PI is first approved, freeze() copies onto the order everything a
 * later master edit could otherwise change:
 *   - the T&C clauses (title, text, set, visibility) per document type and group,
 *     plus the tolerance and validity days their placeholders print;
 *   - the company details (bank, LUT, RCMC, IEC, address, director …);
 *   - the category rows behind the category profile (packing words, quality documents, clause set);
 *   - the payment preset's "requires MD approval" flag (its name is already on the order);
 *   - the USD rate for USD-per-unit commission/incentive on a non-USD order (D-245);
 *   - each line's Zoho item.
 *
 * within() runs a block (document generation, finalize, supersede) with the frozen
 * company values and clauses in place of the live ones. Before the freeze an order
 * reads the live values, exactly as before — so a new order prints exactly as today.
 *
 * Port: src/services/orderSnapshotService.js.
 */
final class OrderSnapshotService
{
    /** Company settings an order document prints (DocumentDataAssembler::companyBlock) plus the clause placeholders. */
    public const COMPANY_KEYS = [
        'legal_name', 'registered_office', 'corporate_office', 'gstin', 'iec_pan',
        'md_name', 'md_title', 'phone', 'email',
        'bank_name', 'bank_branch', 'bank_account_no', 'swift_bic', 'ifsc', 'bank_address',
        'lut_number', 'lut_valid_fy',
        'rcmc_number', 'rcmc_valid_until',
        'rbi_purpose_code_advance', 'rbi_purpose_code_balance', 'rbi_purpose_code_freight',
        'non_usd_price_buffer_pct',
        'generated_document_disclaimer_text', 'show_generated_document_disclaimer',
        'quantity_shortfall_tolerance_pct', 'quotation_validity_days', 'pi_validity_days',
    ];

    /** The company details only (what the MD's refresh replaces): every key above except the clause placeholders. */
    public const REFRESHABLE_KEYS = [
        'legal_name', 'registered_office', 'corporate_office', 'gstin', 'iec_pan',
        'md_name', 'md_title', 'phone', 'email',
        'bank_name', 'bank_branch', 'bank_account_no', 'swift_bic', 'ifsc', 'bank_address',
        'lut_number', 'lut_valid_fy',
        'rcmc_number', 'rcmc_valid_until',
        'rbi_purpose_code_advance', 'rbi_purpose_code_balance', 'rbi_purpose_code_freight',
        'generated_document_disclaimer_text', 'show_generated_document_disclaimer',
    ];

    /** The order's frozen snapshot, or null while its PI is not yet approved. */
    public static function snapshot(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT frozen_snapshot_json FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $raw = $stmt->fetchColumn();
        return is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: null) : null;
    }

    public static function isFrozen(int $orderId): bool
    {
        return self::snapshot($orderId) !== null;
    }

    /**
     * D-262: the company's own values (no division overlay) as JSON — what a Domestic Trade, Import or
     * Merchant Trade record keeps when its invoice/PO is fixed.
     */
    public static function companyValuesJson(): string
    {
        $out = [];
        foreach (self::REFRESHABLE_KEYS as $key) {
            $out[$key] = CompanySettingsRepository::companyValue($key);
        }
        return (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * D-262: a reader of a record's company values — its own snapshot when it has one, else today's.
     *
     * @return callable(string):string
     */
    public static function companyReader(?string $snapshotJson): callable
    {
        $frozen = json_decode((string) $snapshotJson, true);
        return static function (string $k) use ($frozen): string {
            if (is_array($frozen) && array_key_exists($k, $frozen)) {
                return (string) ($frozen[$k] ?? '');
            }
            return (string) (CompanySettingsRepository::companyValue($k) ?? '');
        };
    }

    /** The company values for snapshots: the live company_settings (company level). */
    public static function liveCompany(): array
    {
        $out = [];
        foreach (self::COMPANY_KEYS as $key) {
            $out[$key] = CompanySettingsRepository::get($key);
        }
        return $out;
    }

    /**
     * Freezes the order once, when its PI is first approved. A second call does nothing.
     * Returns true when this call froze it.
     */
    public static function freeze(int $orderId): bool
    {
        $pdo = Database::connection();
        $order = $pdo->prepare('SELECT id, frozen_at, payment_preset_id, prices_fixed_at, pricing_fx_rate_at_qt FROM orders WHERE id = :id');
        $order->execute(['id' => $orderId]);
        $row = $order->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['frozen_at'] !== null) {
            return false;
        }

        $company = self::liveCompany();
        $snapshot = [
            'version'    => 1,
            'company'    => $company,
            'terms'      => TermsClauseRepository::captureAll(),
            'categories' => self::captureCategories($orderId),
        ];

        $requiresMd = null;
        if ($row['payment_preset_id'] !== null) {
            $p = $pdo->prepare('SELECT requires_md_approval FROM payment_presets WHERE id = :id');
            $p->execute(['id' => (int) $row['payment_preset_id']]);
            $flag = $p->fetchColumn();
            $requiresMd = $flag === false ? null : (int) $flag;
        }
        // D-340 follow-up (owner, 12 Nov 2026): the rate prices were actually built with — the automatic
        // pricing rate when Automatic is on (market × (1 − cushion)), else the manual rate — not the raw setting.
        // D-342 (owner, 12 Nov 2026): once the Quotation is approved the PI keeps the rate fixed then.
        $fx = \App\Services\PriceLock::fixedRate($row) ?? \App\Services\FxRateService::rate();
        $tolerance = $company['quantity_shortfall_tolerance_pct'] ?? null;

        $pdo->prepare(
            'UPDATE orders SET frozen_at = NOW(), frozen_snapshot_json = :j, quantity_tolerance_pct = :t,
                    payment_terms_requires_md_approval = :m, pricing_fx_rate_at_pi = :fx
              WHERE id = :id AND frozen_at IS NULL'
        )->execute([
            'j' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            't' => $tolerance !== null && $tolerance !== '' && is_numeric($tolerance) ? $tolerance : null,
            'm' => $requiresMd,
            'fx' => $fx !== null && $fx !== '' && (float) $fx > 0 ? $fx : null,
            'id' => $orderId,
        ]);
        // Each line's Zoho item as it is now (a catalogue change later never re-sends a past invoice).
        $pdo->prepare(
            'UPDATE order_products op JOIN catalog_products cp ON cp.id = op.catalog_product_id
                SET op.zoho_item_id = cp.zoho_item_id
              WHERE op.order_id = :o AND op.zoho_item_id IS NULL AND cp.zoho_item_id IS NOT NULL'
        )->execute(['o' => $orderId]);
        return true;
    }

    /**
     * The MD's "Refresh company details on this order": the order takes today's
     * company details (bank, LUT, IEC, address …). The clauses, tolerance and
     * validity days stay as frozen. Logged with the reason.
     */
    public static function refreshCompany(int $orderId, int $userId, string $reason): ?string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            return 'Give a reason of at least 10 characters (e.g. "Bank account changed to the new SBI account").';
        }
        $snapshot = self::snapshot($orderId);
        if ($snapshot === null) {
            return 'This order still uses the current company details: they are kept on the order when its PI is approved.';
        }
        $old = $snapshot['company'] ?? [];
        $live = self::liveCompany();
        $new = $old;
        foreach (self::REFRESHABLE_KEYS as $key) {
            $new[$key] = $live[$key];
        }
        $snapshot['company'] = $new;
        $snapshot['company_refreshed_at'] = date('Y-m-d H:i:s');
        Database::connection()->prepare('UPDATE orders SET frozen_snapshot_json = :j WHERE id = :id')
            ->execute(['j' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $orderId]);
        $changed = [];
        foreach (self::REFRESHABLE_KEYS as $key) {
            if ((string) ($old[$key] ?? '') !== (string) ($new[$key] ?? '')) {
                $changed[] = $key;
            }
        }
        AuditLogRepository::log(
            $userId, 'ORDER_COMPANY_DETAILS_REFRESHED', 'orders', $orderId, 'frozen_snapshot_json.company',
            json_encode(array_intersect_key($old, array_flip($changed)), JSON_UNESCAPED_UNICODE),
            json_encode(array_intersect_key($new, array_flip($changed)), JSON_UNESCAPED_UNICODE),
            $reason
        );
        return null;
    }

    /**
     * Runs $work with the order's frozen company values and clauses in place of the
     * live ones (no-op before the freeze). Nested calls are safe.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function within(int $orderId, callable $work): mixed
    {
        $snapshot = self::snapshot($orderId);
        if ($snapshot === null) {
            return $work();
        }
        $previousCompany = CompanySettingsRepository::setOverlay($snapshot['company'] ?? []);
        $previousTerms = TermsClauseRepository::setSnapshot($snapshot['terms'] ?? []);
        try {
            return $work();
        } finally {
            CompanySettingsRepository::setOverlay($previousCompany);
            TermsClauseRepository::setSnapshot($previousTerms);
        }
    }

    /** The category row the order's documents use: frozen when the order is frozen and knew it, else live. */
    public static function category(int $orderId, int $categoryId): ?array
    {
        $frozen = self::snapshot($orderId)['categories'][(string) $categoryId] ?? null;
        return is_array($frozen) ? $frozen : ProductCategoryRepository::find($categoryId);
    }

    /** The tolerance % the order prints and enforces: frozen, else the live setting. */
    public static function tolerancePct(array $order): float
    {
        if (isset($order['quantity_tolerance_pct']) && $order['quantity_tolerance_pct'] !== null) {
            return (float) $order['quantity_tolerance_pct'];
        }
        return (float) (CompanySettingsRepository::get('quantity_shortfall_tolerance_pct') ?? 5);
    }

    /** @return array<string, array<string,mixed>> category id → the settings that drive documents */
    private static function captureCategories(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT COALESCE(op.category_id, (SELECT pc.id FROM product_categories pc WHERE pc.is_system = 1 ORDER BY pc.id LIMIT 1)) AS cid
               FROM order_products op
              WHERE op.order_id = :o AND op.line_type = 'goods'"
        );
        $stmt->execute(['o' => $orderId]);
        $out = [];
        foreach (array_filter(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN))) as $cid) {
            $c = ProductCategoryRepository::find($cid);
            if ($c !== null) {
                $out[(string) $cid] = [
                    'id' => (int) $c['id'],
                    'name' => (string) $c['name'],
                    'is_system' => (int) ($c['is_system'] ?? 0),
                    'tc_clause_set' => $c['tc_clause_set'] ?? null,
                    'packing_type' => $c['packing_type'] ?? null,
                    'quality_documents' => $c['quality_documents'] ?? null,
                ];
            }
        }
        return $out;
    }
}
