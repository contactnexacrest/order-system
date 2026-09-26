<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderSupplierPoDocumentRepository;

/**
 * The wet-signature-required flag concept (docs/SOP/README.md "Still
 * pending", task tracker item #106). recordBuyerPo() (Stage 2) and
 * confirmSupplierSigned() (Stage 5) used to let staff pass their gate with
 * a button click alone — the upload-signed-copy endpoint next to each one
 * is a separate, optional action, never actually required to advance the
 * stage. That was the loophole: a stage could be confirmed "signed" with
 * zero physical evidence ever attached to the order. This service is the
 * single place both gates check before they're allowed to pass — each
 * flag defaults on (see seed.sql), but stays Admin-toggleable per document
 * type via company_settings for a workflow that genuinely doesn't need
 * the physical copy.
 */
final class WetSignatureGuardService
{
    public static function buyerPoBlocked(int $orderId): bool
    {
        return CompanySettingsRepository::get('wet_signature_required_buyer_po') === '1'
            && empty(OrderBuyerPoDocumentRepository::forOrder($orderId));
    }

    public static function supplierPoBlocked(int $orderSupplierPoId): bool
    {
        return CompanySettingsRepository::get('wet_signature_required_supplier_po') === '1'
            && empty(OrderSupplierPoDocumentRepository::forSupplierPo($orderSupplierPoId));
    }
}
