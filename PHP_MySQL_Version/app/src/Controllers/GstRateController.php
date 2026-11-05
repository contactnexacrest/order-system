<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DomesticOrderRepository;
use App\Repositories\ProductCategoryRepository;
use App\Services\AuthService;
use App\Services\DomesticOrderService;
use App\Services\GstCalculator;

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): Admin › GST rates, for Domestic
 * Trade sales — a rate per HSN (the longest matching start of a line's HSN
 * wins) or per product category; anything else takes DOM's default rate.
 * Opened from Admin › Divisions › Domestic Trade (manage_divisions). The
 * starting values are standard rates, to be confirmed by the CA.
 * Port: gstRateController.js.
 */
final class GstRateController
{
    public const RATES = ['0.00', '0.25', '3.00', '5.00', '12.00', '18.00', '28.00', '40.00'];

    public function index(array $params): void
    {
        View::render('gst_rates/index', [
            'rates' => DomesticOrderRepository::gstRates(),
            'categories' => ProductCategoryRepository::all(),
            'defaultRate' => DomesticOrderService::setting('dom_default_gst_rate'),
        ], 'layout/base');
    }

    public function store(array $params): void
    {
        $user = AuthService::currentUser();
        $hsn = preg_replace('/\D/', '', (string) ($_POST['hsn_prefix'] ?? '')) ?? '';
        $category = ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;
        $rate = trim((string) ($_POST['rate_pct'] ?? ''));
        $rate = $rate !== '' ? GstCalculator::money(GstCalculator::scaled($rate, 2)) : '';
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
        $error = match (true) {
            ($hsn === '') === ($category === null) => 'Give either an HSN (2 to 8 digits) or a product category, not both.',
            $hsn !== '' && (strlen($hsn) < 2 || strlen($hsn) > 8) => 'An HSN has 2 to 8 digits.',
            !in_array($rate, self::RATES, true) => 'Choose a GST rate.',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: /admin/gst-rates');
            return;
        }
        $id = DomesticOrderRepository::addGstRate($hsn !== '' ? $hsn : null, $category, $rate, $note !== '' ? $note : null, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'GST_RATE_ADDED', 'gst_rates', $id, 'rate_pct', null, $rate);
        Flash::set('success', 'GST rate added.');
        header('Location: /admin/gst-rates');
    }

    public function toggle(array $params): void
    {
        $user = AuthService::currentUser();
        $active = ($_POST['active'] ?? '') === '1';
        DomesticOrderRepository::setGstRateActive((int) $params['id'], $active, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'GST_RATE_' . ($active ? 'ON' : 'OFF'), 'gst_rates', (int) $params['id'], 'is_active', null, $active ? '1' : '0');
        Flash::set('success', $active ? 'GST rate in use again.' : 'GST rate no longer used.');
        header('Location: /admin/gst-rates');
    }
}
