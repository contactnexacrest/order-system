<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderCrateRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPackingRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\OrderStageRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SampleDataRepository;
use App\Repositories\SupplierRepository;

/**
 * Phase E follow-up — "can we have some sample records to play with, and
 * clear them through the UI whenever, any number of times, without ever
 * touching real data?" (user request, 2026-09-19).
 *
 * Deliberately reuses the exact same repository/service calls a real user's
 * request would make (OrderRepository::create(), StageGateService, the same
 * DocumentGenerationService::generate() every real QT/PI/OC goes through,
 * ...) rather than inventing a parallel fake-data insertion path — the
 * point of a playground is that it behaves exactly like the real system,
 * because it IS the real system, just flagged is_sample_data = 1 and
 * cleanly removable. See SampleDataRepository for the deletion side.
 *
 * Scope (Task #17 follow-up, 2026-09-21 — extends the original two-order
 * bounded scope, which this file's own docblock flagged as "easy to extend
 * ... if you want a third order further along later"):
 *   - Order A: brand new, left at Stage 1 (no documents) — the
 *     "start from scratch" walkthrough.
 *   - Order B: FOB, "Standard — New Buyer" preset, pushed to Stage 5
 *     (QT/PI/OC generated, buyer PO + advance recorded and cleared) — the
 *     "financials worth looking at on the dashboard" walkthrough.
 *   - Order C: CIF, "Established Buyer — Post-BL" preset (the tier requiring
 *     MD approval and a BL-triggered balance — SOP Tier B), pushed all the
 *     way through Stage 9 to a fully closed order — the "see every document
 *     type and the complete 9-stage lifecycle, including the CFR/CIF-only
 *     Freight Payment stage" walkthrough. Still not an attempt to cover
 *     every possible scenario (a dispute, an amendment, a quantity-shortfall
 *     buyer-approval upload are all real but separate scenarios) — those
 *     are each exercised by their own feature's own live testing, and
 *     bolting all of them onto the fixed "load sample data" button would
 *     make it slower and harder to reason about for the thing it's actually
 *     for: a new user's first walkthrough of the system.
 */
final class SampleDataService
{
    public static function isLoaded(): bool
    {
        return SampleDataRepository::isLoaded();
    }

    /** @return array<int, array<string,mixed>> */
    public static function summary(): array
    {
        return SampleDataRepository::summary();
    }

    /**
     * @return array{clients:int,orders:int}
     * @throws \RuntimeException if sample data is already loaded — clear it first.
     */
    public static function load(int $userId): array
    {
        if (SampleDataRepository::isLoaded()) {
            throw new \RuntimeException('Sample data is already loaded. Clear it first if you want a fresh copy.');
        }

        $incoterms = LookupRepository::incoterms();
        $fobIncoterm = $incoterms[0] ?? null;
        $cifIncoterm = null;
        foreach ($incoterms as $term) {
            if (strtoupper((string) $term['code']) === 'CIF') {
                $cifIncoterm = $term;
                break;
            }
        }
        $cifIncoterm = $cifIncoterm ?? end($incoterms) ?: null;

        $currency = LookupRepository::currencies()[0] ?? null;
        $loadingPort = LookupRepository::ports('loading')[0] ?? null;
        $presets = LookupRepository::paymentPresets();
        $standardPreset = null;
        $establishedPreset = null;
        foreach ($presets as $preset) {
            if ($preset['preset_name'] === 'Established Buyer — Post-BL') {
                $establishedPreset = $preset;
            } elseif ($standardPreset === null) {
                $standardPreset = $preset;
            }
        }
        $establishedPreset = $establishedPreset ?? $standardPreset;

        if (!$fobIncoterm || !$cifIncoterm || !$currency || !$standardPreset || !$establishedPreset) {
            throw new \RuntimeException('No incoterm/currency/payment preset configured yet — set those up first (Company Settings), then load sample data.');
        }

        $clientAId = self::createSampleClient(
            '[SAMPLE] Aurora Décor Imports',
            '124 Harbor Lane, Sample District, Test Country',
            $userId
        );
        $orderA1Id = self::createSampleOrder($clientAId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            ['Hand-carved decorative planter, Model A', '30 x 30 x 45 cm', 'Polished'],
            ['Hand-carved decorative planter, Model B', '25 x 25 x 40 cm', 'Matte'],
        ]);
        // Left exactly here — Stage 1, no documents yet — as the
        // "start from scratch" sample order.

        $clientBId = self::createSampleClient(
            '[SAMPLE] Meridian Home Collections',
            '77 Riverside Court, Sample District, Test Country',
            $userId
        );
        $orderB1Id = self::createSampleOrder($clientBId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            ['Outdoor stone planter, large', '60 x 60 x 70 cm', 'Natural finish'],
            ['Outdoor stone planter, medium', '40 x 40 x 50 cm', 'Natural finish'],
            ['Garden bench, stone composite', '150 x 45 x 45 cm', 'Sandblasted'],
        ]);
        self::advanceSampleOrderToStage5($orderB1Id, $userId);

        $clientCId = self::createSampleClient(
            '[SAMPLE] Silverleaf Global Trading',
            '9 Customs Quay, Sample Port District, Test Country',
            $userId
        );
        $orderC1Id = self::createSampleOrder($clientCId, $cifIncoterm, $currency, $loadingPort, $establishedPreset, $userId, [
            ['Natural stone kerb stone, large', '100 x 30 x 15 cm', 'Flamed'],
            ['Natural stone kerb stone, small', '60 x 30 x 15 cm', 'Flamed'],
        ], 'Rotterdam, Netherlands');
        self::advanceSampleOrderToStage9($orderC1Id, $userId);

        return ['clients' => 3, 'orders' => 3];
    }

    public static function clear(): array
    {
        return SampleDataRepository::clearAll();
    }

    private static function createSampleClient(string $companyName, string $billingAddress, int $userId): int
    {
        $clientUniqueNumber = ReferenceNumberService::generateClientUniqueNumber();
        $clientId = ClientRepository::create([
            'company_legal_name'     => $companyName,
            'billing_address'        => $billingAddress,
            'consignee_name'         => 'SAME',
            'consignee_address'      => 'SAME',
            'contact_person'         => 'Sample Contact',
            'email'                  => null,
            'phone'                  => null,
            'country_of_destination' => 'Test Country',
            'coo_type'               => 'TBC',
            'notify_party'           => null,
        ], $userId, $clientUniqueNumber);
        ClientRepository::markSample($clientId);
        return $clientId;
    }

    /**
     * @param array<int, array{0:string,1:string,2:string}> $productLines [description, dimensions, finish]
     * @param string|null $portOfDischargeText free-text discharge port — only Chennai (loading) is seeded by
     *        default, so a CFR/CIF sample order (which needs a discharge port to look believable) supplies its
     *        own text fallback rather than depending on a discharge port row existing.
     */
    private static function createSampleOrder(
        int $clientId,
        array $incoterm,
        array $currency,
        ?array $loadingPort,
        array $preset,
        int $userId,
        array $productLines,
        ?string $portOfDischargeText = null
    ): int {
        $client = ClientRepository::find($clientId);
        $sequenceNo = OrderRepository::nextSequenceForClient($clientId);
        $orderRefFormat = CompanySettingsRepository::get('order_ref_format') ?? 'SC/OC/{YYYY}/{NNN}';
        $orderReference = strtr($orderRefFormat, [
            '{YYYY}' => date('Y'),
            '{NNN}'  => str_pad((string) $sequenceNo, 3, '0', STR_PAD_LEFT),
        ]) . '-' . $clientId;

        $orderId = OrderRepository::create([
            'order_reference'       => $orderReference,
            'client_id'             => $clientId,
            'sequence_no'           => $sequenceNo,
            'buyer_inquiry_ref'     => $client['client_unique_number'],
            'payment_preset_id'     => (int) $preset['id'],
            'incoterm_id'           => (int) $incoterm['id'],
            'port_of_loading_id'    => $loadingPort ? (int) $loadingPort['id'] : null,
            'port_of_discharge_text' => $portOfDischargeText,
            'currency_id'           => (int) $currency['id'],
            'coo_type'              => $client['coo_type'] ?? 'TBC',
            'estimated_total_cbm'         => null,
            'estimated_gross_weight_kg'   => null,
            'estimated_net_weight_kg'     => null,
            'indicative_freight_low'      => null,
            'indicative_freight_high'     => null,
            'indicative_insurance_amount' => null,
            'buyers_po_ref'         => 'NIL',
            'quotation_date'        => date('Y-m-d'),
            'quotation_valid_until' => date('Y-m-d', strtotime('+' . ((int) (CompanySettingsRepository::get('quotation_validity_days') ?? 30)) . ' days')),
        ], $userId);
        OrderRepository::markSample($orderId);

        OrderStageRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $lineNo = 1;
        foreach ($productLines as [$description, $dimensions, $finish]) {
            OrderProductRepository::add(
                $orderId,
                $lineNo++,
                $description,
                $dimensions,
                $finish,
                '10',
                false,
                'pcs',
                '250.00',
                '6802.93'
            );
        }

        return $orderId;
    }

    /**
     * Replays exactly the calls OrderController/DocumentController make for
     * a real order: generate QT (passes Stage 1), record the buyer's PO
     * (passes Stage 2), generate PI, record + clear the advance payment
     * (passes Stage 3), generate OC, confirm buyer acknowledgement (passes
     * Stage 4) — leaving the order sitting at Stage 5 (Supplier PO) with a
     * believable paper trail and payment history to look at.
     */
    private static function advanceSampleOrderToStage5(int $orderId, int $userId): void
    {
        DocumentGenerationService::generate($orderId, 'QT', $userId);
        StageGateService::passAndUnlockNext($orderId, 1, $userId);

        OrderRepository::setBuyersPoRef($orderId, 'SAMPLE-BUYER-PO-0001');
        StageGateService::passAndUnlockNext($orderId, 2, $userId);

        DocumentGenerationService::generate($orderId, 'PI', $userId);

        $fobTotal = OrderProductRepository::totalFobValue($orderId);
        $order = OrderRepository::find($orderId);
        $advanceAmount = round($fobTotal * ((float) $order['advance_pct']) / 100, 2);
        $balanceAmount = round($fobTotal * ((float) $order['balance_pct']) / 100, 2);
        $balanceDueDate = $order['balance_trigger_option'] === 'A_BEFORE_SHIPMENT'
            ? null
            : date('Y-m-d', strtotime('+' . (int) $order['balance_days'] . ' days'));

        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, $advanceAmount, date('Y-m-d'));
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, date('Y-m-d'), $userId);
        OrderPaymentStatusRepository::setBalanceAmount($orderId, $balanceAmount, $balanceDueDate);
        StageGateService::passAndUnlockNext($orderId, 3, $userId);

        DocumentGenerationService::generate($orderId, 'OC', $userId);
        StageGateService::passAndUnlockNext($orderId, 4, $userId);
    }

    /**
     * Order C's walkthrough (Task #17) — everything advanceSampleOrderToStage5()
     * does, then continues Stage 5 through Stage 9 by replaying the exact
     * same OrderController actions a real CIF order on the "Established
     * Buyer — Post-BL" preset would go through: Supplier PO, the CFR/CIF-only
     * Freight Payment stage, Packing + BL Instruction, Commercial Invoice +
     * balance, then Document Despatch & Closure. Leaves the order
     * order.status = 'complete' with every one of the nine document types
     * generated at least once.
     */
    private static function advanceSampleOrderToStage9(int $orderId, int $userId): void
    {
        self::advanceSampleOrderToStage5($orderId, $userId);

        // --- Stage 5: Supplier Purchase Order ---
        $supplierId = self::createSampleSupplier();
        $supplierPoReference = ReferenceNumberService::generateDocumentRef(
            (int) DocumentGenerationService::documentTypeIdFor('SUPPO')
        );
        OrderSupplierPoRepository::create($orderId, $supplierId, $supplierPoReference, [
            'material_stone_type'   => 'Natural Granite, Kadapa Black',
            'grade'                 => 'Grade A',
            'surface_finish'        => 'Flamed',
            'dimensions'            => 'Per order — see Annexure',
            'dimensional_tolerance' => '+/- 2mm',
            'quantity'              => '20',
            'unit'                  => 'pcs',
            'colour_reference'      => 'Sample swatch on file',
            'unit_price_inr'        => '15000.00',
            'basic_value_inr'       => '300000.00',
            'gst_rate_pct'          => '18.00',
            'gst_amount_inr'        => '54000.00',
            'total_payable_inr'     => '354000.00',
            'advance_pct'           => '40.00',
            'advance_amount_inr'    => '141600.00',
            'balance_amount_inr'    => '212400.00',
            'delivery_location'     => 'NexaCrest Factory, Chennai',
            'required_delivery_date' => date('Y-m-d', strtotime('+21 days')),
            'packing_requirement'   => 'Export wooden crates, fumigated',
        ]);
        DocumentGenerationService::generate($orderId, 'SUPPO', $userId);
        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        OrderSupplierPoRepository::markSigned((int) $supplierPo['id']);
        StageGateService::passAndUnlockNext($orderId, 5, $userId);
        StageGateService::maybeAutoSkipFreightStage($orderId, $userId);

        // --- Stage 6: Freight Payment (CFR/CIF only — this sample order is
        // CIF, so this stage actually runs rather than being auto-skipped) ---
        OrderFreightRepository::upsert($orderId, [
            'confirmed_freight_rate'    => '1450.00',
            'insurance_amount'          => '185.00',
            'freight_forwarder_name'    => 'Sample Forwarder Logistics',
            'freight_forwarder_contact' => 'ops@sampleforwarder.test',
            'gst_treatment'             => 'NIL',
        ]);
        DocumentGenerationService::generate($orderId, 'FDN', $userId);
        OrderPaymentStatusRepository::recordFreightReceived($orderId, 1635.00, date('Y-m-d'));
        OrderPaymentStatusRepository::markFreightCleared($orderId, date('Y-m-d'), $userId);
        StageGateService::passAndUnlockNext($orderId, 6, $userId);

        // --- Stage 7: Packing & BL Instruction ---
        $summary = OrderProductRepository::orderedQuantitySummary($orderId);
        OrderPackingRepository::upsert($orderId, [
            'actual_quantity_packed' => (string) $summary['total'],
            'crate_count'            => '2',
            'total_net_weight_kg'    => '3200.00',
            'total_gross_weight_kg'  => '3450.00',
            'total_cbm'              => '18.500',
            'packing_date'           => date('Y-m-d'),
            'shortfall_pct'          => '0.00',
        ]);
        $products = OrderProductRepository::forOrder($orderId);
        $crates = [];
        foreach ($products as $i => $product) {
            $crates[] = [
                'crate_no'            => 'C-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'marks_numbers'       => 'NEXACREST/SAMPLE/' . ($i + 1),
                'product_description' => $product['description'],
                'dimensions_lwh_cm'   => '120 x 100 x 90',
                'pcs'                 => $product['quantity'],
                'net_weight_kg'       => '1600.00',
                'gross_weight_kg'     => '1725.00',
                'cbm'                 => '9.250',
                'hs_code'             => $product['hs_code'] ?? '6802.93',
            ];
        }
        OrderCrateRepository::replaceForOrder($orderId, $crates);
        DocumentGenerationService::generate($orderId, 'PL', $userId);

        OrderShippingRepository::upsert($orderId, [
            'shipping_line'  => 'Sample Shipping Line',
            'vessel_name'    => 'MV Sample Voyager',
            'voyage_number'  => 'SV-2026-014',
            'etd'            => date('Y-m-d', strtotime('+3 days')),
            'eta'            => date('Y-m-d', strtotime('+27 days')),
            'container_type' => '1x40HC',
            'container_no'   => 'SAMU1234567',
            'seal_no'        => 'SEAL000123',
        ]);
        DocumentGenerationService::generate($orderId, 'BLI', $userId);
        OrderShippingRepository::recordBl($orderId, 'SAMPLE-BL-0001', date('Y-m-d'));
        StageGateService::passAndUnlockNext($orderId, 7, $userId);

        // --- Stage 8: Commercial Invoice & Balance ---
        DocumentGenerationService::generate($orderId, 'CI', $userId);
        $paymentStatus = OrderPaymentStatusRepository::find($orderId);
        $balanceAmount = $paymentStatus && $paymentStatus['balance_amount']
            ? (float) $paymentStatus['balance_amount']
            : (OrderProductRepository::totalFobValue($orderId) * 0.6);
        OrderPaymentStatusRepository::recordBalanceReceived($orderId, $balanceAmount, date('Y-m-d'));
        OrderPaymentStatusRepository::markBalanceCleared($orderId, date('Y-m-d'), $userId);
        StageGateService::passAndUnlockNext($orderId, 8, $userId);

        // --- Stage 9: Document Despatch & Closure ---
        DocumentGenerationService::generate($orderId, 'COOPREP', $userId);
        OrderShippingRepository::recordBlOriginalsReceived($orderId, 3);
        OrderShippingRepository::recordBlEndorsed($orderId, $userId);
        OrderShippingRepository::recordScannedBlSent($orderId);
        OrderShippingRepository::recordCourierSent($orderId, 'SAMPLE-COURIER-TRACK-0001');
        StageGateService::passAndUnlockNext($orderId, 9, $userId);
        OrderRepository::markComplete($orderId);
    }

    /** Task #17 — a fresh, clearly-flagged supplier for the Stage-5+ sample order (see SECTION T / markSample()). */
    private static function createSampleSupplier(): int
    {
        $supplierId = SupplierRepository::create([
            'supplier_legal_name' => '[SAMPLE] Deccan Stone Quarries Pvt. Ltd.',
            'address'             => 'Quarry Road, Sample Industrial Area, Test State',
            'gstin'               => '29SAMPLE0000A1Z5',
            'pan'                 => 'SAMPL0000A',
            'contact_person'      => 'Sample Supplier Contact',
            'phone'               => '+91-00000-00000',
            'supplier_type'       => 'Quarry',
        ]);
        SupplierRepository::markSample($supplierId);
        return $supplierId;
    }
}
