<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Repositories\AmendmentRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DisputeDocumentRepository;
use App\Repositories\DisputeRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderAnnexureRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderCrateRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPackingRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\OrderStageRepository;
use App\Repositories\OrderSupplierPoDocumentRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\PiIntakeRepository;
use App\Repositories\SampleDataRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UserRepository;

/**
 * Phase E follow-up — "can we have some sample records to play with, and
 * clear them through the UI whenever, any number of times, without ever
 * touching real data?" (user request, 2026-09-19).
 *
 * Deliberately reuses the exact same repository/service calls a real user's
 * request would make (OrderRepository::create(), StageGateService, the same
 * DocumentGenerationService::generate() every real QT/PI/OC goes through,
 * AmendmentService, ReviewWorkflowService, ClientPortalService, ...) rather
 * than inventing a parallel fake-data insertion path — the point of a
 * playground is that it behaves exactly like the real system, because it IS
 * the real system, just flagged is_sample_data = 1 and cleanly removable.
 * See SampleDataRepository for the deletion side.
 *
 * Scope ("cover all" follow-up, 2026-09-23 — extends the Task #17 scope to
 * every scenario a fixed 3-order walkthrough couldn't reach):
 *   - Client A (Aurora Décor Imports) — TWO orders, demonstrating multiple
 *     orders under one client (same buyer_inquiry_ref, since that's derived
 *     from the client's own unique number): Order A1 left at Stage 1 with no
 *     documents ("start from scratch"), Order A2 pushed to Stage 2 passed /
 *     awaiting PI ("a second, independently-progressing order").
 *   - Client B (Meridian Home Collections) — the single most heavily
 *     instrumented order: buyer PO reference AND an actual signed-copy
 *     upload, a PI-stage intake submission deliberately left pending staff
 *     review, client-portal auto-provisioning the moment advance clears, an
 *     Order-Confirmation review/reject/regenerate/approve cycle, a full
 *     payment-terms amendment lifecycle (request -> MD-approve -> generate
 *     -> signed copy uploaded -> active), a Supplier PO with its
 *     acknowledgment copy uploaded, the FOB auto-skip of the Freight
 *     Payment stage, and a quantity-shortfall-with-buyer-approval packing
 *     scenario — left at Stage 8, deliberately not closed.
 *   - Client C (Silverleaf Global Trading) — unchanged full 9-stage CIF
 *     closure (still the only order that pays through the CFR/CIF-only
 *     Freight Payment stage, contrasting with Order B's FOB auto-skip), now
 *     followed by a post-closure dispute: raised, evidence uploaded, and
 *     resolved.
 *   - Client D (Copperfield Trading Co.) — a new client whose only order is
 *     quoted and then marked lost.
 *
 * Further extended (2026-09-23 follow-up — Annexure A / product-line variety
 * had no sample coverage at all: every order above has include_annexure_a =
 * 0) with a fifth client and five more orders, judged individually on
 * whether the scenario actually needs Annexure A (a technical-specification
 * sheet — free text plus optional images) or is purely a product-line/
 * quantity variation that doesn't:
 *   - Client E (Regal Memorials & Monuments Inc.) — four orders:
 *     - Order E1: the SAME product at two different sizes as separate line
 *       items, WITH Annexure A giving each size its own written
 *       specification (no images — a size difference doesn't need a photo).
 *     - Order E2: two different products (a monument blank + a headstone),
 *       WITH Annexure A giving each its own specification AND a reference
 *       image.
 *     - Order E3: a single "complete monument set" product line, WITH
 *       Annexure A describing its components (base/die/cap/vase) and a
 *       reference image.
 *     - Order E4: two line items that are the same kind of product at two
 *       different set sizes (a 10-piece set vs a 12-piece set) — NO
 *       Annexure A, since this is a quantity/packaging distinction, not a
 *       technical drawing one.
 *   - Client F (Granite Quarry Direct Traders) — Order F1: a single raw,
 *     unprocessed block line item (HS code 2516.11, not the finished-goods
 *     default 6802.93) — NO Annexure A, since a raw block has no finish or
 *     technical spec to document.
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

        $reviewerUserId = self::pickReviewerUserId($userId);
        $supplierId = self::createSampleSupplier();

        // --- Client A: two orders under one client ---
        $clientAId = self::createSampleClient(
            '[SAMPLE] Aurora Décor Imports',
            '124 Harbor Lane, Sample District, Test Country',
            $userId
        );
        $orderA1Id = self::createSampleOrder($clientAId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            ['Hand-carved decorative planter, Model A', '30 x 30 x 45 cm', 'Polished'],
            ['Hand-carved decorative planter, Model B', '25 x 25 x 40 cm', 'Matte'],
        ]);
        // Left exactly here — Stage 1, no documents yet — the
        // "start from scratch" sample order.

        $orderA2Id = self::createSampleOrder($clientAId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            ['Hand-carved decorative planter, Model C', '35 x 35 x 50 cm', 'Polished'],
        ]);
        self::advanceSampleOrderToStage2Pending($orderA2Id, $userId);

        // --- Client B: FOB, "Standard — New Buyer" preset — the single
        // order carrying every scenario a fixed 3-order set couldn't reach ---
        $clientBId = self::createSampleClient(
            '[SAMPLE] Meridian Home Collections',
            '77 Riverside Court, Sample District, Test Country',
            $userId,
            'meridian.buyer@sample-client.test'
        );
        $orderB1Id = self::createSampleOrder($clientBId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            ['Outdoor stone planter, large', '60 x 60 x 70 cm', 'Natural finish'],
            ['Outdoor stone planter, medium', '40 x 40 x 50 cm', 'Natural finish'],
            ['Garden bench, stone composite', '150 x 45 x 45 cm', 'Sandblasted'],
        ]);
        self::advanceSampleOrderBJourney($orderB1Id, $clientBId, $supplierId, $userId, $reviewerUserId);

        // --- Client C: CIF, "Established Buyer — Post-BL" preset — the
        // only order that runs through the CFR/CIF Freight Payment stage,
        // pushed all the way to closure, then a post-closure dispute ---
        $clientCId = self::createSampleClient(
            '[SAMPLE] Silverleaf Global Trading',
            '9 Customs Quay, Sample Port District, Test Country',
            $userId
        );
        $orderC1Id = self::createSampleOrder($clientCId, $cifIncoterm, $currency, $loadingPort, $establishedPreset, $userId, [
            ['Natural stone kerb stone, large', '100 x 30 x 15 cm', 'Flamed'],
            ['Natural stone kerb stone, small', '60 x 30 x 15 cm', 'Flamed'],
        ], 'Rotterdam, Netherlands');
        self::advanceSampleOrderToStage9($orderC1Id, $supplierId, $userId);
        self::addSampleDispute($orderC1Id, $userId);

        // --- Client D: a lost order ---
        $clientDId = self::createSampleClient(
            '[SAMPLE] Copperfield Trading Co.',
            '15 Deadline Drive, Sample District, Test Country',
            $userId
        );
        $orderD1Id = self::createSampleOrder($clientDId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            ['Polished marble tabletop, round', '90 cm dia x 3 cm', 'High polish'],
        ]);
        self::advanceAndLoseSampleOrder($orderD1Id, $userId);

        // --- Client E: Annexure A / product-line variety (2026-09-23) ---
        $clientEId = self::createSampleClient(
            '[SAMPLE] Regal Memorials & Monuments Inc.',
            '48 Cemetery Row, Sample Memorial District, Test Country',
            $userId
        );

        // Order E1: same product, two sizes, Annexure A with a written spec per size (no images needed).
        $orderE1Id = self::createSampleOrderCustomLines($clientEId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            ['description' => 'Granite Monument Slab', 'dimensions' => '120 x 80 x 3 cm', 'finish' => 'Polished', 'quantity' => '25', 'unit' => 'pcs', 'unit_price' => '180.00'],
            ['description' => 'Granite Monument Slab', 'dimensions' => '60 x 60 x 2 cm', 'finish' => 'Polished', 'quantity' => '40', 'unit' => 'pcs', 'unit_price' => '95.00'],
        ], true);
        OrderAnnexureRepository::createProduct($orderE1Id, [
            'name' => 'Granite Monument Slab — 120 x 80 x 3 cm',
            'description' => 'Large-format slab for base/kerb use.',
            'dimensions' => '120 x 80 x 3 cm',
            'finish' => 'Polished (mirror finish, top & face)',
            'components' => null,
            'technical_notes' => 'Thickness tolerance +/- 2mm; edges arrised.',
        ]);
        OrderAnnexureRepository::createProduct($orderE1Id, [
            'name' => 'Granite Monument Slab — 60 x 60 x 2 cm',
            'description' => 'Smaller-format slab — same material and finish as the 120 x 80 cm size.',
            'dimensions' => '60 x 60 x 2 cm',
            'finish' => 'Polished (mirror finish, top & face)',
            'components' => null,
            'technical_notes' => 'Thickness tolerance +/- 2mm; edges arrised.',
        ]);
        self::advanceSampleOrderPastQuotation($orderE1Id, $userId, true);

        // Order E2: monument blank + headstone, Annexure A with a spec AND a reference image for each.
        $orderE2Id = self::createSampleOrderCustomLines($clientEId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            ['description' => 'Monument Blank', 'dimensions' => '90 x 45 x 8 cm', 'finish' => 'Polished front & top, rock-pitched sides', 'quantity' => '12', 'unit' => 'pcs', 'unit_price' => '310.00'],
            ['description' => 'Headstone — Traditional Upright', 'dimensions' => '60 x 30 x 8 cm', 'finish' => 'Polished, all sides', 'quantity' => '12', 'unit' => 'pcs', 'unit_price' => '260.00'],
        ], true);
        $orderE2 = OrderRepository::find($orderE2Id);
        $blankAnnexureId = OrderAnnexureRepository::createProduct($orderE2Id, [
            'name' => 'Monument Blank',
            'description' => 'Base blank supplied for on-site engraving by the buyer.',
            'dimensions' => '90 x 45 x 8 cm',
            'finish' => 'Polished front & top, rock-pitched sides',
            'components' => null,
            'technical_notes' => 'Top edge chamfered 10mm; back face left rough for mounting.',
        ]);
        self::attachSampleAnnexureImage(
            $orderE2Id,
            $blankAnnexureId,
            'clients/' . self::pathSafe((string) $orderE2['client_unique_number']) . '/' . self::pathSafe((string) $orderE2['order_reference']) . '/annexure',
            'Monument Blank - reference photo.png',
            $userId
        );
        $headstoneAnnexureId = OrderAnnexureRepository::createProduct($orderE2Id, [
            'name' => 'Headstone — Traditional Upright',
            'description' => 'Standard upright headstone, same order as the monument blank above.',
            'dimensions' => '60 x 30 x 8 cm',
            'finish' => 'Polished, all sides',
            'components' => null,
            'technical_notes' => 'Serpentine-top profile; polished on all visible faces.',
        ]);
        self::attachSampleAnnexureImage(
            $orderE2Id,
            $headstoneAnnexureId,
            'clients/' . self::pathSafe((string) $orderE2['client_unique_number']) . '/' . self::pathSafe((string) $orderE2['order_reference']) . '/annexure',
            'Headstone - reference photo.png',
            $userId
        );
        self::advanceSampleOrderPastQuotation($orderE2Id, $userId, true);

        // Order E3: a complete monument set, Annexure A describing its components + a reference image.
        $orderE3Id = self::createSampleOrderCustomLines($clientEId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            ['description' => 'Complete Monument Set (Base + Die + Cap + Vase)', 'dimensions' => 'Base 90x45x15cm; Die 60x30x8cm; Cap 66x33x10cm', 'finish' => 'Polished front & top, rock-pitched sides', 'quantity' => '6', 'unit' => 'set', 'unit_price' => '780.00'],
        ], true);
        $orderE3 = OrderRepository::find($orderE3Id);
        $monumentSetAnnexureId = OrderAnnexureRepository::createProduct($orderE3Id, [
            'name' => 'Complete Monument Set',
            'description' => 'Full monument assembly, matched from a single block for colour consistency.',
            'dimensions' => 'Base 90 x 45 x 15 cm; Die 60 x 30 x 8 cm; Cap 66 x 33 x 10 cm',
            'finish' => 'Polished front & top, rock-pitched sides',
            'components' => '1x Base, 1x Die (headstone), 1x Cap, 1x Vase',
            'technical_notes' => 'Assembled on-site by the buyer; all pieces cut from the same block for colour match.',
        ]);
        self::attachSampleAnnexureImage(
            $orderE3Id,
            $monumentSetAnnexureId,
            'clients/' . self::pathSafe((string) $orderE3['client_unique_number']) . '/' . self::pathSafe((string) $orderE3['order_reference']) . '/annexure',
            'Complete Monument Set - reference photo.png',
            $userId
        );
        self::advanceSampleOrderPastQuotation($orderE3Id, $userId, true);

        // Order E4: same kind of product, two set sizes (10-piece vs 12-piece) — no Annexure A, a quantity distinction, not a technical one.
        $orderE4Id = self::createSampleOrderCustomLines($clientEId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            ['description' => 'Granite Flower Vase — 10-Piece Set', 'dimensions' => '20 x 20 x 30 cm each', 'finish' => 'Polished', 'quantity' => '10', 'unit' => 'pcs', 'unit_price' => '45.00'],
            ['description' => 'Granite Flower Vase — 12-Piece Set', 'dimensions' => '18 x 18 x 28 cm each', 'finish' => 'Polished', 'quantity' => '12', 'unit' => 'pcs', 'unit_price' => '38.00'],
        ]);
        self::advanceSampleOrderPastQuotation($orderE4Id, $userId, false);

        // --- Client F: a raw, unprocessed block — no Annexure A, no finish to specify ---
        $clientFId = self::createSampleClient(
            '[SAMPLE] Granite Quarry Direct Traders',
            '2 Quarry Access Road, Sample Industrial Zone, Test Country',
            $userId
        );
        $orderF1Id = self::createSampleOrderCustomLines($clientFId, $fobIncoterm, $currency, $loadingPort, $standardPreset, $userId, [
            // 2516.11, not the finished-goods default 6802.93 — a raw/crude-trimmed
            // block is a materially different tariff classification (seed.sql's own
            // note: "Different product = verify HS Code before issuing").
            ['description' => 'Raw Granite Block — Absolute Black (unprocessed)', 'dimensions' => 'approx. 300 x 150 x 150 cm (irregular, as-quarried)', 'finish' => 'Natural / Unfinished (Raw Block)', 'quantity' => '4', 'unit' => 'blocks', 'unit_price' => '95000.00', 'hs_code' => '2516.11'],
        ]);
        self::advanceSampleOrderPastQuotation($orderF1Id, $userId, false);

        return ['clients' => 6, 'orders' => 10];
    }

    public static function clear(): array
    {
        return SampleDataRepository::clearAll();
    }

    private static function createSampleClient(string $companyName, string $billingAddress, int $userId, ?string $email = null): int
    {
        $clientUniqueNumber = ReferenceNumberService::generateClientUniqueNumber();
        $clientId = ClientRepository::create([
            'company_legal_name'     => $companyName,
            'billing_address'        => $billingAddress,
            'consignee_name'         => 'SAME',
            'consignee_address'      => 'SAME',
            'contact_person'         => 'Sample Contact',
            'email'                  => $email,
            'phone'                  => null,
            'country_of_destination' => 'Test Country',
            'coo_type'               => 'TBC',
            'notify_party'           => null,
        ], $userId, $clientUniqueNumber);
        ClientRepository::markSample($clientId);
        return $clientId;
    }

    /**
     * Everything a sample order needs before its product lines exist: the
     * order shell itself (reference, client/incoterm/currency/preset
     * linkage, stage/payment-status initialization). Shared by both
     * createSampleOrder() (fixed-quantity 3-tuple lines) and
     * createSampleOrderCustomLines() (full per-line control — quantity,
     * unit, price, HS code — for the product/Annexure-A variety scenarios).
     *
     * @param string|null $portOfDischargeText free-text discharge port — only Chennai (loading) is seeded by
     *        default, so a CFR/CIF sample order (which needs a discharge port to look believable) supplies its
     *        own text fallback rather than depending on a discharge port row existing.
     */
    private static function createSampleOrderShell(
        int $clientId,
        array $incoterm,
        array $currency,
        ?array $loadingPort,
        array $preset,
        int $userId,
        ?string $portOfDischargeText = null,
        bool $includeAnnexureA = false
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
            'include_annexure_a'    => $includeAnnexureA,
        ], $userId);
        OrderRepository::markSample($orderId);

        OrderStageRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        return $orderId;
    }

    /** @param array<int, array{0:string,1:string,2:string}> $productLines [description, dimensions, finish] — fixed quantity '10' pcs @ 250.00, HS 6802.93. */
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
        $orderId = self::createSampleOrderShell($clientId, $incoterm, $currency, $loadingPort, $preset, $userId, $portOfDischargeText);

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
     * Full per-line control (quantity, unit, price, HS code) for the
     * product/Annexure-A variety scenarios — same product at different
     * sizes, piece-count sets, raw blocks with a non-default HS code, etc.
     *
     * @param array<int, array{description:string,dimensions:string,finish:string,quantity:string,unit:string,unit_price:string,hs_code?:string}> $productLines
     */
    private static function createSampleOrderCustomLines(
        int $clientId,
        array $incoterm,
        array $currency,
        ?array $loadingPort,
        array $preset,
        int $userId,
        array $productLines,
        bool $includeAnnexureA = false
    ): int {
        $orderId = self::createSampleOrderShell($clientId, $incoterm, $currency, $loadingPort, $preset, $userId, null, $includeAnnexureA);

        $lineNo = 1;
        foreach ($productLines as $line) {
            OrderProductRepository::add(
                $orderId,
                $lineNo++,
                $line['description'],
                $line['dimensions'],
                $line['finish'],
                $line['quantity'],
                false,
                $line['unit'],
                $line['unit_price'],
                $line['hs_code'] ?? '6802.93'
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

    /** Order A2 — a second, independently-progressing order for the same client, stopped partway (Stage 2 passed, awaiting PI). */
    private static function advanceSampleOrderToStage2Pending(int $orderId, int $userId): void
    {
        DocumentGenerationService::generate($orderId, 'QT', $userId);
        StageGateService::passAndUnlockNext($orderId, 1, $userId);
        OrderRepository::setBuyersPoRef($orderId, 'SAMPLE-BUYER-PO-0003');
        StageGateService::passAndUnlockNext($orderId, 2, $userId);
    }

    /**
     * The product/Annexure-A variety orders (Client E/F, 2026-09-23) don't
     * need to demonstrate stage depth (that's what Orders A2/B/C/D already
     * cover) — just generate the Quotation (and Annexure A, for the orders
     * whose product lines actually need one — its own product entries must
     * already exist by the time this is called) and pass Stage 1.
     */
    private static function advanceSampleOrderPastQuotation(int $orderId, int $userId, bool $generateAnnexure): void
    {
        DocumentGenerationService::generate($orderId, 'QT', $userId);
        if ($generateAnnexure) {
            DocumentGenerationService::generate($orderId, 'ANNEXA', $userId);
        }
        StageGateService::passAndUnlockNext($orderId, 1, $userId);
    }

    /**
     * Order B's walkthrough ("cover all", 2026-09-23) — everything a real
     * staff user would do for a FOB order that runs into a document
     * rejection, a payment-terms renegotiation, and a packing-quantity
     * shortfall, while also being the order whose client gets portal access
     * and whose PI-stage form is left sitting in the review queue. Leaves
     * the order at Stage 8 (Commercial Invoice) — active, not closed, so
     * Order C remains the only fully-closed sample order.
     */
    private static function advanceSampleOrderBJourney(int $orderId, int $clientId, int $supplierId, int $userId, int $reviewerUserId): void
    {
        // --- Stage 1: Quotation ---
        DocumentGenerationService::generate($orderId, 'QT', $userId);
        StageGateService::passAndUnlockNext($orderId, 1, $userId);

        // A PI-stage intake link sent once the Quotation is out — the
        // client has submitted it, but nobody's actioned it yet, so it sits
        // in the PI Intake Review queue exactly like a real unresolved one.
        $piToken = PiIntakeRepository::createLink($orderId, $userId);
        $piSubmission = PiIntakeRepository::findValidByToken($piToken);
        PiIntakeRepository::submit((int) $piSubmission['id'], [
            'company_legal_name'             => 'Meridian Home Collections LLC',
            'billing_address'                => '77 Riverside Court, Sample District, Test Country',
            'consignee_name'                 => 'SAME',
            'consignee_address'              => 'SAME',
            'vat_eori_tax_no'                => 'GB-SAMPLE-987654321',
            'contact_person'                 => 'Jordan Blake',
            'email'                          => 'meridian.buyer@sample-client.test',
            'phone'                          => '+1-555-0100-2002',
            'notify_party'                   => 'NIL',
            'port_of_discharge_text'         => 'Long Beach, USA',
            'country_of_destination'         => 'United States',
            'incoterm_confirmed'             => 'FOB',
            'container_type_text'            => null,
            'payment_terms_confirmation'     => 'CONFIRMED — 30% advance T/T + 70% balance against scanned BL copy within 30 days.',
            'quotation_acceptance_reference' => 'We accept the Quotation as issued — no changes.',
            'coo_type'                       => 'Non-preferential',
            'buyer_po_ref'                   => null,
            'changes_from_quotation'         => 'No changes.',
            'special_document_requirements'  => null,
        ], '203.0.113.42');

        // --- Stage 2: Buyer PO — reference recorded AND the buyer's actual
        // signed copy attached (Stage 2 gate evidence beyond just a typed ref) ---
        OrderRepository::setBuyersPoRef($orderId, 'SAMPLE-BUYER-PO-0002');
        $order = OrderRepository::find($orderId);
        $buyerPoFileId = self::attachSamplePlaceholderFile(
            null,
            $orderId,
            'clients/' . self::pathSafe((string) $order['client_unique_number']) . '/' . self::pathSafe((string) $order['order_reference']) . '/buyer_po',
            'Buyer PO SAMPLE-BUYER-PO-0002 (signed).pdf',
            'Buyer',
            'Buyer PO copy',
            $userId
        );
        OrderBuyerPoDocumentRepository::attach($orderId, $buyerPoFileId);
        StageGateService::passAndUnlockNext($orderId, 2, $userId);

        // --- Stage 3: PI / Production — advance recorded and cleared ---
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

        // Advance cleared -> the real trigger point for auto-provisioning
        // client portal access (ClientPortalService::provisionIfNeeded()'s
        // only precondition is an email on file, which this client has).
        ClientPortalService::provisionIfNeeded($clientId, $orderId);

        // --- Stage 4: Order Confirmation — a review/reject/regenerate/
        // approve cycle (Section 9) before it can pass ---
        $ocResult = DocumentGenerationService::generate($orderId, 'OC', $userId);
        ReviewWorkflowService::assignReviewers((int) $ocResult['document_id'], [$reviewerUserId], $userId);
        $reviews = DocumentReviewRepository::forDocument((int) $ocResult['document_id']);
        ReviewWorkflowService::reject(
            (int) $reviews[0]['id'],
            $reviewerUserId,
            'Buyer name on the OC does not match the signed Buyer PO exactly — please correct and resubmit.'
        );
        $ocResult2 = DocumentGenerationService::generate($orderId, 'OC', $userId); // new revision, regenerated from draft
        ReviewWorkflowService::assignReviewers((int) $ocResult2['document_id'], [$reviewerUserId], $userId);
        $reviews2 = DocumentReviewRepository::forDocument((int) $ocResult2['document_id']);
        ReviewWorkflowService::approve((int) $reviews2[0]['id'], $reviewerUserId, 'Corrected — matches the Buyer PO. Approved.');
        StageGateService::passAndUnlockNext($orderId, 4, $userId);

        // --- Payment Terms Amendment (Section 8) — full lifecycle:
        // request -> MD-approve -> generate agreement -> signed copy
        // uploaded -> active (payment terms updated on the order) ---
        $amendmentId = AmendmentService::createRequest(
            $orderId,
            'Buyer requested additional time on the balance payment due to an import financing delay at their bank.',
            'importer',
            null,
            null,
            '70% balance T/T against scanned BL copy within 45 days of BL date (extended from 30 days).',
            'B_AGAINST_BL',
            45,
            null,
            date('Y-m-d'),
            $userId
        );
        AmendmentService::approveByMd($amendmentId, $userId);
        AmendmentService::generateDocument($amendmentId, $userId);
        $amendment = AmendmentRepository::find($amendmentId);
        $order = OrderRepository::find($orderId);
        $signedCopyFileId = self::attachSamplePlaceholderFile(
            null,
            $orderId,
            'clients/' . self::pathSafe((string) $order['client_unique_number']) . '/' . self::pathSafe((string) $order['order_reference']) . '/amendments/' . self::pathSafe((string) $amendment['amendment_reference']) . '/signed',
            'Countersigned Amendment ' . $amendment['amendment_reference'] . '.pdf',
            'Buyer',
            'Countersigned Payment Terms Amendment Agreement',
            $userId
        );
        AmendmentService::attachSignedCopyAndActivate($amendmentId, $signedCopyFileId, $userId);

        // --- Stage 5: Supplier Purchase Order — with the supplier's signed
        // acknowledgment copy attached (Stage 5 gate evidence beyond the flag alone) ---
        $supplierPoReference = ReferenceNumberService::generateDocumentRef(
            (int) DocumentGenerationService::documentTypeIdFor('SUPPO')
        );
        OrderSupplierPoRepository::create($orderId, $supplierId, $supplierPoReference, [
            'material_stone_type'   => 'Natural Granite, Absolute Black',
            'grade'                 => 'Grade A',
            'surface_finish'        => 'Natural/Sandblasted',
            'dimensions'            => 'Per order — see Annexure',
            'dimensional_tolerance' => '+/- 2mm',
            'quantity'              => '30',
            'unit'                  => 'pcs',
            'colour_reference'      => 'Sample swatch on file',
            'unit_price_inr'        => '15000.00',
            'basic_value_inr'       => '450000.00',
            'gst_rate_pct'          => '18.00',
            'gst_amount_inr'        => '81000.00',
            'total_payable_inr'     => '531000.00',
            'advance_pct'           => '40.00',
            'advance_amount_inr'    => '212400.00',
            'balance_amount_inr'    => '318600.00',
            'delivery_location'     => 'NexaCrest Factory, Chennai',
            'required_delivery_date' => date('Y-m-d', strtotime('+21 days')),
            'packing_requirement'   => 'Export wooden crates, fumigated',
        ]);
        DocumentGenerationService::generate($orderId, 'SUPPO', $userId);
        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        $supplierAckFileId = self::attachSamplePlaceholderFile(
            null,
            $orderId,
            'clients/' . self::pathSafe((string) $order['client_unique_number']) . '/' . self::pathSafe((string) $order['order_reference']) . '/supplier_po',
            'Supplier PO ' . $supplierPoReference . ' (acknowledged).pdf',
            'Supplier',
            'Supplier PO acknowledgment',
            $userId
        );
        OrderSupplierPoDocumentRepository::attach((int) $supplierPo['id'], $supplierAckFileId);
        OrderSupplierPoRepository::markSigned((int) $supplierPo['id']);
        StageGateService::passAndUnlockNext($orderId, 5, $userId);

        // --- FOB -> Freight Payment (Stage 6) auto-skipped — the direct
        // contrast with Order C's CIF order, which actually pays it ---
        StageGateService::maybeAutoSkipFreightStage($orderId, $userId);

        // --- Stage 7: Packing & BL Instruction — a quantity shortfall
        // beyond tolerance, resolved with the buyer's written approval on
        // file (mirrors OrderController::savePacking()'s own gate) ---
        $summary = OrderProductRepository::orderedQuantitySummary($orderId);
        $actualQty = $summary['total'] - 5.0; // 5 of 30 pcs short — well beyond the default 5% tolerance
        $shortfallPct = round((($summary['total'] - $actualQty) / $summary['total']) * 100, 2);
        $buyerApprovalFileId = self::attachSamplePlaceholderFile(
            null,
            $orderId,
            'clients/' . self::pathSafe((string) $order['client_unique_number']) . '/' . self::pathSafe((string) $order['order_reference']) . '/packing',
            'Buyer approval - quantity shortfall.pdf',
            'Buyer',
            'Quantity shortfall approval',
            $userId
        );
        OrderPackingRepository::upsert($orderId, [
            'actual_quantity_packed' => (string) $actualQty,
            'crate_count'            => '2',
            'total_net_weight_kg'    => '2100.00',
            'total_gross_weight_kg'  => '2280.00',
            'total_cbm'              => '12.400',
            'packing_date'           => date('Y-m-d'),
            'shortfall_pct'          => (string) $shortfallPct,
        ]);
        OrderPackingRepository::attachBuyerApproval($orderId, $buyerApprovalFileId);

        $products = OrderProductRepository::forOrder($orderId);
        $crates = [];
        foreach ($products as $i => $product) {
            $crates[] = [
                'crate_no'            => 'C-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'marks_numbers'       => 'NEXACREST/SAMPLE/' . ($i + 1),
                'product_description' => $product['description'],
                'dimensions_lwh_cm'   => '110 x 90 x 80',
                'pcs'                 => $product['quantity'],
                'net_weight_kg'       => '700.00',
                'gross_weight_kg'     => '760.00',
                'cbm'                 => '4.100',
                'hs_code'             => $product['hs_code'] ?? '6802.93',
            ];
        }
        OrderCrateRepository::replaceForOrder($orderId, $crates);
        DocumentGenerationService::generate($orderId, 'PL', $userId);

        OrderShippingRepository::upsert($orderId, [
            'shipping_line'  => 'Sample Shipping Line',
            'vessel_name'    => 'MV Sample Pioneer',
            'voyage_number'  => 'SP-2026-009',
            'etd'            => date('Y-m-d', strtotime('+3 days')),
            'eta'            => date('Y-m-d', strtotime('+24 days')),
            'container_type' => '1x20FT',
            'container_no'   => 'SAMU7654321',
            'seal_no'        => 'SEAL000456',
        ]);
        DocumentGenerationService::generate($orderId, 'BLI', $userId);
        OrderShippingRepository::recordBl($orderId, 'SAMPLE-BL-0002', date('Y-m-d'));
        StageGateService::passAndUnlockNext($orderId, 7, $userId);

        // --- Stage 8: Commercial Invoice — generated, balance not yet
        // cleared. Deliberately left here, active and not closed, so
        // Order C remains the only fully-closed sample order. ---
        DocumentGenerationService::generate($orderId, 'CI', $userId);
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
    private static function advanceSampleOrderToStage9(int $orderId, int $supplierId, int $userId): void
    {
        self::advanceSampleOrderToStage5($orderId, $userId);

        // --- Stage 5: Supplier Purchase Order ---
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

    /** A dispute raised after closure, with evidence attached, then resolved (Spec Section 16). */
    private static function addSampleDispute(int $orderId, int $userId): void
    {
        $description = 'Buyer reports a shortage of 2 pieces found upon container destuffing at the destination port, against the Packing List quantity.';
        $noticeDate = date('Y-m-d');
        $responseDays = (int) (CompanySettingsRepository::get('dispute_response_days_n') ?? '10');
        $responseDueDate = WorkingDaysCalculator::addWorkingDays($noticeDate, $responseDays);

        $disputeId = DisputeRepository::create($orderId, $noticeDate, 'Buyer', $description, $userId, $responseDueDate);
        AuditLogRepository::log($userId, 'DISPUTE_RAISED', 'disputes', $disputeId, null, null, $description);

        $order = OrderRepository::find($orderId);
        $evidenceFileId = self::attachSamplePlaceholderFile(
            null,
            $orderId,
            'clients/' . self::pathSafe((string) $order['client_unique_number']) . '/' . self::pathSafe((string) $order['order_reference']) . '/disputes/' . $disputeId,
            'Buyer shortage claim - photos and destuffing report.pdf',
            'Buyer',
            'Dispute-related document',
            $userId
        );
        DisputeDocumentRepository::attach($disputeId, $evidenceFileId);

        $resolutionNotes = 'Verified against the Packing List and crate photographs; supplier confirmed a short-shipment of 2 pieces and issued a credit note applied to the buyer\'s next order. Buyer confirmed satisfaction with the resolution.';
        DisputeRepository::resolve($disputeId, $resolutionNotes);
        AuditLogRepository::log($userId, 'DISPUTE_STATUS_CHANGED', 'disputes', $disputeId, 'status', 'Open', 'Resolved', $resolutionNotes);
    }

    /** A quoted order the buyer went cold on — marked lost (replays OrderController::markLost()'s own checks). */
    private static function advanceAndLoseSampleOrder(int $orderId, int $userId): void
    {
        DocumentGenerationService::generate($orderId, 'QT', $userId);
        StageGateService::passAndUnlockNext($orderId, 1, $userId);

        $reason = 'Buyer stopped responding after 45 days despite repeated follow-ups; sourced from a domestic supplier instead per their email.';
        OrderRepository::markLost($orderId, $reason, $userId);
        AuditLogRepository::log($userId, 'ORDER_MARKED_LOST', 'orders', $orderId, 'status', 'active', 'lost', $reason);
    }

    /** Task #17 — a fresh, clearly-flagged supplier shared by every Stage-5+ sample order (see SECTION T / markSample()). */
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

    /** Any active user other than the one running the load, so review-assignment scenarios have a distinct reviewer. Falls back to the same user if none exists. */
    private static function pickReviewerUserId(int $fallbackUserId): int
    {
        foreach (UserRepository::listActive() as $user) {
            if ((int) $user['id'] !== $fallbackUserId) {
                return (int) $user['id'];
            }
        }
        return $fallbackUserId;
    }

    /**
     * FileUploadService::handleUpload() can't be driven without a real
     * $_FILES upload, so sample "received" files replay its own two steps
     * directly: write a placeholder straight to the same storage path
     * convention, then FileStoreRepository::insertReceived() — never a
     * shared file across rows, since SampleDataRepository::clearAll()
     * unlink()s each file_store row's own path individually.
     */
    private static function attachSamplePlaceholderFile(
        ?int $clientId,
        int $orderId,
        string $subPath,
        string $originalFilename,
        ?string $receivedFrom,
        string $documentTypeLabel,
        int $uploadedBy
    ): int {
        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
        $targetDir = "{$storageBase}/{$subPath}";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $uuidFilename = bin2hex(random_bytes(16)) . '.pdf';
        $targetPath = "{$targetDir}/{$uuidFilename}";
        $placeholderContent = "%PDF-1.4\n% Sample placeholder document generated by the Sample Data Playground.\n% {$documentTypeLabel}\n";
        file_put_contents($targetPath, $placeholderContent);

        return FileStoreRepository::insertReceived(
            $clientId,
            $orderId,
            $targetPath,
            $uuidFilename,
            $originalFilename,
            strlen($placeholderContent),
            'application/pdf',
            $uploadedBy,
            $receivedFrom,
            $documentTypeLabel
        );
    }

    /**
     * Annexure product images get base64-embedded straight into the
     * generated Annexure A PDF (see DocumentDataAssembler::annexureProductsBlock())
     * — unlike attachSamplePlaceholderFile()'s stand-in PDF text, this needs
     * genuinely valid, decodable image bytes so it actually renders rather
     * than showing as a broken image.
     */
    private static function attachSampleAnnexureImage(int $orderId, int $annexureProductId, string $subPath, string $originalFilename, int $uploadedBy): void
    {
        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
        $targetDir = "{$storageBase}/{$subPath}";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $uuidFilename = bin2hex(random_bytes(16)) . '.png';
        $targetPath = "{$targetDir}/{$uuidFilename}";
        // A minimal but genuinely valid 1x1 PNG.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        file_put_contents($targetPath, $pngBytes);

        $fileId = FileStoreRepository::insertReceived(
            null,
            $orderId,
            $targetPath,
            $uuidFilename,
            $originalFilename,
            strlen($pngBytes),
            'image/png',
            $uploadedBy,
            null,
            'Annexure product image'
        );
        OrderAnnexureRepository::addImage($annexureProductId, $fileId);
    }

    private static function pathSafe(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
    }
}
