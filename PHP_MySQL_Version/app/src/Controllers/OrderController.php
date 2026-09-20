<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AdminOverrideRepository;
use App\Repositories\AmendmentRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DisputeRepository;
use App\Repositories\DocumentCrossVerificationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderCrateRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPackingRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\OrderStageRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\FileUploadService;
use App\Services\ReferenceNumberService;
use App\Services\StageGateService;

final class OrderController
{
    public function index(array $params): void
    {
        View::render('orders/index', ['orders' => OrderRepository::all()], 'layout/base');
    }

    public function create(array $params): void
    {
        $preselectedClientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : null;
        View::render('orders/create', [
            'clients'         => ClientRepository::all(),
            'incoterms'       => LookupRepository::incoterms(),
            'currencies'      => LookupRepository::currencies(),
            'loadingPorts'    => LookupRepository::ports('loading'),
            'dischargePorts'  => LookupRepository::ports('discharge'),
            'paymentPresets'  => LookupRepository::paymentPresets(),
            'cooTypes'        => LookupRepository::dropdownOptions('coo_type'),
            'containerTypes'  => LookupRepository::dropdownOptions('container_type'),
            'preselectedClientId' => $preselectedClientId,
        ], 'layout/base');
    }

    public function store(array $params): void
    {
        $user = AuthService::currentUser();

        $clientId = (int) ($_POST['client_id'] ?? 0);
        $client = $clientId ? ClientRepository::find($clientId) : null;
        if (!$client) {
            Flash::set('error', 'Please select a valid client.');
            header('Location: /orders/create');
            return;
        }

        $incotermId = (int) ($_POST['incoterm_id'] ?? 0);
        $currencyId = (int) ($_POST['currency_id'] ?? 0);
        $paymentPresetId = (int) ($_POST['payment_preset_id'] ?? 0);
        if (!$incotermId || !$currencyId || !$paymentPresetId) {
            Flash::set('error', 'Incoterm, currency, and a payment preset are all required.');
            header('Location: /orders/create?client_id=' . $clientId);
            return;
        }

        $descriptions = $_POST['product_description'] ?? [];
        $hasAtLeastOneProduct = false;
        foreach ($descriptions as $description) {
            if (trim((string) $description) !== '') {
                $hasAtLeastOneProduct = true;
                break;
            }
        }
        if (!$hasAtLeastOneProduct) {
            Flash::set('error', 'At least one product line (with a description) is required.');
            header('Location: /orders/create?client_id=' . $clientId);
            return;
        }

        $portOfDischargeId = !empty($_POST['port_of_discharge_id']) ? (int) $_POST['port_of_discharge_id'] : null;
        $portOfDischargeText = trim((string) ($_POST['port_of_discharge_text'] ?? ''));

        $sequenceNo = OrderRepository::nextSequenceForClient($clientId);
        $orderRefFormat = CompanySettingsRepository::get('order_ref_format') ?? 'SC/OC/{YYYY}/{NNN}';
        $orderReference = strtr($orderRefFormat, [
            '{YYYY}' => date('Y'),
            '{NNN}'  => str_pad((string) $sequenceNo, 3, '0', STR_PAD_LEFT),
        ]) . '-' . $clientId; // client suffix keeps this globally unique even though the format string isn't scoped per-client

        $orderId = OrderRepository::create([
            'order_reference'       => $orderReference,
            'client_id'             => $clientId,
            'sequence_no'           => $sequenceNo,
            'buyer_inquiry_ref'     => $client['client_unique_number'],
            'payment_preset_id'     => $paymentPresetId,
            'incoterm_id'           => $incotermId,
            'port_of_loading_id'    => !empty($_POST['port_of_loading_id']) ? (int) $_POST['port_of_loading_id'] : null,
            'port_of_discharge_id'  => $portOfDischargeId,
            'port_of_discharge_text' => $portOfDischargeId ? null : ($portOfDischargeText ?: null),
            'currency_id'           => $currencyId,
            'coo_type'              => trim((string) ($_POST['coo_type'] ?? '')) ?: $client['coo_type'] ?? 'TBC',
            'include_annexure_a'    => !empty($_POST['include_annexure_a']),
            'special_requirements'  => trim((string) ($_POST['special_requirements'] ?? '')) ?: null,
            'container_type'        => trim((string) ($_POST['container_type'] ?? '')) ?: null,
            'estimated_total_cbm'   => trim((string) ($_POST['estimated_total_cbm'] ?? '')),
            'estimated_gross_weight_kg' => trim((string) ($_POST['estimated_gross_weight_kg'] ?? '')),
            'estimated_net_weight_kg'   => trim((string) ($_POST['estimated_net_weight_kg'] ?? '')),
            'estimated_package_count'   => trim((string) ($_POST['estimated_package_count'] ?? '')) ?: null,
            'estimated_package_type'    => trim((string) ($_POST['estimated_package_type'] ?? '')) ?: null,
            'est_lead_time_text'    => trim((string) ($_POST['est_lead_time_text'] ?? '')) ?: null,
            'indicative_freight_low'  => trim((string) ($_POST['indicative_freight_low'] ?? '')),
            'indicative_freight_high' => trim((string) ($_POST['indicative_freight_high'] ?? '')),
            'indicative_insurance_amount' => trim((string) ($_POST['indicative_insurance_amount'] ?? '')),
            'buyers_po_ref'         => 'NIL',
            'quotation_date'        => date('Y-m-d'),
            'quotation_valid_until' => date('Y-m-d', strtotime('+' . ((int) (CompanySettingsRepository::get('quotation_validity_days') ?? 30)) . ' days')),
        ], (int) $user['id']);

        OrderStageRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $lineNo = 1;
        foreach ($descriptions as $i => $description) {
            $description = trim((string) $description);
            if ($description === '') {
                continue; // blank row — skip rather than insert an empty product
            }
            $quantityIsTbc = !empty($_POST['product_quantity_tbc'][$i]);
            OrderProductRepository::add(
                $orderId,
                $lineNo++,
                $description,
                trim((string) ($_POST['product_dimensions'][$i] ?? '')) ?: null,
                trim((string) ($_POST['product_finish'][$i] ?? '')) ?: null,
                trim((string) ($_POST['product_quantity'][$i] ?? '')) ?: null,
                $quantityIsTbc,
                trim((string) ($_POST['product_unit'][$i] ?? '')) ?: null,
                trim((string) ($_POST['product_unit_price'][$i] ?? '')) ?: null,
                trim((string) ($_POST['product_hs_code'][$i] ?? '')) ?: '6802.93'
            );
        }

        Flash::set('success', "Order {$orderReference} created for {$client['company_legal_name']}.");
        header("Location: /orders/{$orderId}");
    }

    public function show(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $stages = OrderStageRepository::forOrder($orderId);
        $stageByNumber = [];
        foreach ($stages as $s) {
            $stageByNumber[(int) $s['stage_number']] = $s;
        }

        $documents = DocumentRepository::forOrder($orderId);
        $reviewsByDocument = [];
        $crossVerificationsByDocument = [];
        foreach ($documents as $d) {
            $reviewsByDocument[(int) $d['id']] = DocumentReviewRepository::forDocument((int) $d['id']);
            $crossVerificationsByDocument[(int) $d['id']] = DocumentCrossVerificationRepository::forDocument((int) $d['id']);
        }

        View::render('orders/show', [
            'order'    => $order,
            'products' => OrderProductRepository::forOrder($orderId),
            'stages'   => $stages,
            'stageByNumber' => $stageByNumber,
            'payment'  => OrderPaymentStatusRepository::find($orderId),
            'documents' => $documents,
            'reviewsByDocument' => $reviewsByDocument,
            'crossVerificationsByDocument' => $crossVerificationsByDocument,
            'activeUsers' => UserRepository::listActive(),
            'fobTotal' => OrderProductRepository::totalFobValue($orderId),
            'suppliers' => SupplierRepository::all(),
            'supplierPo' => OrderSupplierPoRepository::findLatestForOrder($orderId),
            'freight'   => OrderFreightRepository::find($orderId),
            'packing'   => OrderPackingRepository::find($orderId),
            'crates'    => OrderCrateRepository::forOrder($orderId),
            'shipping'  => OrderShippingRepository::find($orderId),
            'production' => OrderProductionRepository::find($orderId),
            'supplierTypes' => LookupRepository::dropdownOptions('supplier_type'),
            'amendmentCount' => count(AmendmentRepository::forOrder($orderId)),
            'openDisputeCount' => count(array_filter(DisputeRepository::forOrder($orderId), static fn(array $d): bool => $d['status'] !== 'Resolved')),
        ], 'layout/base');
    }

    /** Stage 1->2 manual gate: buyer's signed PO received. */
    public function recordBuyerPo(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $ref = trim((string) ($_POST['buyers_po_ref'] ?? ''));
        if ($ref === '') {
            Flash::set('error', "Buyer's PO / reference number is required to confirm this gate.");
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderRepository::setBuyersPoRef($orderId, $ref);
        StageGateService::passAndUnlockNext($orderId, 2, (int) $user['id']);
        Flash::set('success', "Buyer PO recorded ({$ref}). Stage 3 (PI / Production) unlocked.");
        header("Location: /orders/{$orderId}");
    }

    public function recordAdvancePayment(array $params): void
    {
        $orderId = (int) $params['id'];
        $amount = (float) ($_POST['advance_amount'] ?? 0);
        $receivedAt = trim((string) ($_POST['advance_received_at'] ?? '')) ?: date('Y-m-d');
        if ($amount <= 0) {
            Flash::set('error', 'Enter the advance amount actually received.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, $amount, $receivedAt);
        Flash::set('success', 'Advance remittance recorded. Mark it cleared once your bank confirms receipt.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 2->3 gate: advance payment marked cleared in NexaCrest's bank account. */
    public function clearAdvancePayment(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $clearedAt = trim((string) ($_POST['advance_cleared_at'] ?? '')) ?: date('Y-m-d');

        $fobTotal = OrderProductRepository::totalFobValue($orderId);
        $order = OrderRepository::find($orderId);
        $balanceAmount = round($fobTotal * ((float) $order['balance_pct']) / 100, 2);
        $balanceDueDate = $order['balance_trigger_option'] === 'A_BEFORE_SHIPMENT'
            ? null // due date is event-triggered (shipment readiness), not a fixed date, for this preset
            : date('Y-m-d', strtotime('+' . (int) $order['balance_days'] . ' days'));

        OrderPaymentStatusRepository::markAdvanceCleared($orderId, $clearedAt, (int) $user['id']);
        OrderPaymentStatusRepository::setBalanceAmount($orderId, $balanceAmount, $balanceDueDate);
        StageGateService::passAndUnlockNext($orderId, 3, (int) $user['id']);

        // Client portal access is provisioned here, and only here — see
        // ClientPortalService's docblock. No-op if this client already has
        // a login from an earlier order.
        $provisionStatus = \App\Services\ClientPortalService::provisionIfNeeded((int) $order['client_id'], $orderId);

        $baseMessage = 'Advance payment cleared. Stage 4 unlocked — you can now generate the Order Confirmation.';
        if ($provisionStatus === 'provisioned') {
            Flash::set('success', $baseMessage . ' The client has been emailed their portal login.');
        } elseif ($provisionStatus === 'no_email_on_file') {
            Flash::set('warning', $baseMessage . ' WARNING: this client has no email on file, so portal login could NOT be provisioned — add an email to their record and provision access manually, otherwise they will never be able to log in.');
        } else {
            Flash::set('success', $baseMessage . ' The client already has portal access from an earlier order.');
        }
        header("Location: /orders/{$orderId}");
    }

    public function updateProductionStatus(array $params): void
    {
        $orderId = (int) $params['id'];
        $text = trim((string) ($_POST['production_status_text'] ?? ''));
        if ($text !== '') {
            OrderRepository::setProductionStatus($orderId, $text);
        }
        $shipmentText = trim((string) ($_POST['est_shipment_date_text'] ?? ''));
        if ($shipmentText !== '') {
            OrderRepository::setEstShipmentDate($orderId, $shipmentText);
        }
        Flash::set('success', 'Production status updated.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 4->5 gate: buyer acknowledges the Order Confirmation (never built in Phase B). */
    public function confirmBuyerAcknowledged(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        StageGateService::passAndUnlockNext($orderId, 4, (int) $user['id']);
        Flash::set('success', 'Buyer acknowledgement of the Order Confirmation recorded. Stage 5 (Supplier PO) unlocked.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 5: fill the Supplier PO's material/commercial terms — creates order_supplier_po ahead of generating the SUPPO document. */
    public function saveSupplierPo(array $params): void
    {
        $orderId = (int) $params['id'];
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        if (!$supplierId) {
            Flash::set('error', 'Select or add a supplier first.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $docTypeId = \App\Services\DocumentGenerationService::documentTypeIdFor('SUPPO');
        $supplierPoReference = $docTypeId ? ReferenceNumberService::generateDocumentRef($docTypeId) : null;
        if (!$supplierPoReference) {
            Flash::set('error', 'Could not generate a Supplier PO reference — check document_types.ref_format for SUPPO.');
            header("Location: /orders/{$orderId}");
            return;
        }

        OrderSupplierPoRepository::create($orderId, $supplierId, $supplierPoReference, [
            'material_stone_type'   => trim((string) ($_POST['material_stone_type'] ?? '')) ?: null,
            'grade'                 => trim((string) ($_POST['grade'] ?? '')) ?: 'Grade A',
            'surface_finish'        => trim((string) ($_POST['surface_finish'] ?? '')) ?: null,
            'dimensions'            => trim((string) ($_POST['dimensions'] ?? '')) ?: null,
            'dimensional_tolerance' => trim((string) ($_POST['dimensional_tolerance'] ?? '')) ?: null,
            'quantity'              => trim((string) ($_POST['quantity'] ?? '')) ?: null,
            'unit'                  => trim((string) ($_POST['unit'] ?? '')) ?: null,
            'colour_reference'      => trim((string) ($_POST['colour_reference'] ?? '')) ?: null,
            'special_requirements'  => trim((string) ($_POST['special_requirements'] ?? '')) ?: null,
            'unit_price_inr'        => trim((string) ($_POST['unit_price_inr'] ?? '')) ?: null,
            'basic_value_inr'       => trim((string) ($_POST['basic_value_inr'] ?? '')) ?: null,
            'gst_rate_pct'          => trim((string) ($_POST['gst_rate_pct'] ?? '')) ?: null,
            'gst_amount_inr'        => trim((string) ($_POST['gst_amount_inr'] ?? '')) ?: null,
            'total_payable_inr'     => trim((string) ($_POST['total_payable_inr'] ?? '')) ?: null,
            'advance_pct'           => trim((string) ($_POST['advance_pct'] ?? '')) ?: null,
            'advance_amount_inr'    => trim((string) ($_POST['advance_amount_inr'] ?? '')) ?: null,
            'balance_amount_inr'    => trim((string) ($_POST['balance_amount_inr'] ?? '')) ?: null,
            'delivery_location'     => trim((string) ($_POST['delivery_location'] ?? '')) ?: null,
            'required_delivery_date' => trim((string) ($_POST['required_delivery_date'] ?? '')) ?: null,
            'packing_requirement'   => trim((string) ($_POST['packing_requirement'] ?? '')) ?: null,
        ]);

        Flash::set('success', "Supplier PO terms saved ({$supplierPoReference}). Generate the Supplier PO document below.");
        header("Location: /orders/{$orderId}");
    }

    public function createSupplier(array $params): void
    {
        $orderId = (int) $params['id'];
        $name = trim((string) ($_POST['supplier_legal_name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Supplier legal name is required.');
            header("Location: /orders/{$orderId}");
            return;
        }
        SupplierRepository::create([
            'supplier_legal_name' => $name,
            'address'             => trim((string) ($_POST['address'] ?? '')) ?: null,
            'gstin'               => trim((string) ($_POST['gstin'] ?? '')) ?: null,
            'pan'                 => trim((string) ($_POST['pan'] ?? '')) ?: null,
            'contact_person'      => trim((string) ($_POST['contact_person'] ?? '')) ?: null,
            'phone'               => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'supplier_type'       => trim((string) ($_POST['supplier_type'] ?? '')) ?: null,
        ]);
        Flash::set('success', "Supplier \"{$name}\" added.");
        header("Location: /orders/{$orderId}");
    }

    /** Stage 5->6 gate: supplier signs, stamps and returns the Supplier PO. Auto-skips Stage 6 for FOB orders. */
    public function confirmSupplierSigned(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        if (!$supplierPo) {
            Flash::set('error', 'Save the Supplier PO terms and generate the document before confirming signature.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderSupplierPoRepository::markSigned((int) $supplierPo['id']);
        StageGateService::passAndUnlockNext($orderId, 5, (int) $user['id']);
        StageGateService::maybeAutoSkipFreightStage($orderId, (int) $user['id']);
        Flash::set('success', 'Supplier PO signature confirmed.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 6 (CFR/CIF only): record NexaCrest's agreed freight/insurance terms before generating the FDN. */
    public function saveFreightTerms(array $params): void
    {
        $orderId = (int) $params['id'];
        OrderFreightRepository::upsert($orderId, [
            'confirmed_freight_rate'    => trim((string) ($_POST['confirmed_freight_rate'] ?? '')) ?: null,
            'insurance_amount'          => trim((string) ($_POST['insurance_amount'] ?? '')) ?: null,
            'freight_forwarder_name'    => trim((string) ($_POST['freight_forwarder_name'] ?? '')) ?: null,
            'freight_forwarder_contact' => trim((string) ($_POST['freight_forwarder_contact'] ?? '')) ?: null,
            'gst_treatment'             => trim((string) ($_POST['gst_treatment'] ?? '')) ?: null,
        ]);
        Flash::set('success', 'Freight & insurance terms saved. Generate the Freight Debit Note below.');
        header("Location: /orders/{$orderId}");
    }

    public function recordFreightPayment(array $params): void
    {
        $orderId = (int) $params['id'];
        $amount = (float) ($_POST['freight_amount'] ?? 0);
        $receivedAt = trim((string) ($_POST['freight_received_at'] ?? '')) ?: date('Y-m-d');
        if ($amount <= 0) {
            Flash::set('error', 'Enter the freight & insurance amount actually received.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderPaymentStatusRepository::recordFreightReceived($orderId, $amount, $receivedAt);
        Flash::set('success', 'Freight remittance recorded. Mark it cleared once your bank confirms receipt.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 6->7 gate: freight & insurance payment cleared in NexaCrest's bank account. */
    public function clearFreightPayment(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $clearedAt = trim((string) ($_POST['freight_cleared_at'] ?? '')) ?: date('Y-m-d');
        OrderPaymentStatusRepository::markFreightCleared($orderId, $clearedAt, (int) $user['id']);
        StageGateService::passAndUnlockNext($orderId, 6, (int) $user['id']);
        Flash::set('success', 'Freight payment cleared. Stage 7 (Packing & BL Instruction) unlocked.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Stage 7: record actual packing figures + crate-level breakdown, ahead
     * of generating the Packing List.
     *
     * Quantity-tolerance hard rule (Section 13 / additional_instructions
     * point re: "system-enforced, not just checklist"): when the ordered
     * quantity can be unambiguously compared (single unit, not TBC), the
     * shortfall is computed HERE, server-side, never trusted from the
     * client — and a shortfall exceeding
     * company_settings.quantity_shortfall_tolerance_pct blocks the save
     * entirely until the buyer's written approval is uploaded in the same
     * request (order_packing.buyer_approval_file_id — a column the
     * original delivery reserved but never wired to anything). When the
     * order can't be unambiguously compared (mixed units, or an
     * unconfirmed TBC quantity), this falls back to the pre-existing
     * manual shortfall_pct entry rather than blocking a save the system
     * has no sound basis to validate.
     */
    public function savePacking(array $params): void
    {
        $orderId = (int) $params['id'];
        $actualQtyRaw = trim((string) ($_POST['actual_quantity_packed'] ?? ''));
        $actualQty = $actualQtyRaw !== '' ? (float) $actualQtyRaw : null;

        $summary = OrderProductRepository::orderedQuantitySummary($orderId);
        $tolerance = (float) (CompanySettingsRepository::get('quantity_shortfall_tolerance_pct') ?? 5);
        $shortfallPct = null;

        if ($summary['comparable'] && $actualQty !== null && $summary['total'] > 0) {
            $shortfallPct = max(0.0, round((($summary['total'] - $actualQty) / $summary['total']) * 100, 2));
        }

        $existingPacking = OrderPackingRepository::find($orderId);
        $hasExistingApproval = $existingPacking && $existingPacking['buyer_approval_file_id'];
        $uploadingApprovalNow = !empty($_FILES['buyer_approval']['name']);

        if ($shortfallPct !== null && $shortfallPct > $tolerance && !$hasExistingApproval && !$uploadingApprovalNow) {
            Flash::set('error', sprintf(
                'Actual quantity packed (%s %s) is %.2f%% short of the ordered quantity (%s %s) — this exceeds the %.2f%% tolerance in Company Settings. Nothing was saved. Upload the buyer\'s written approval of this shortfall below to proceed.',
                $actualQtyRaw, $summary['unit'], $shortfallPct, rtrim(rtrim(number_format($summary['total'], 3), '0'), '.'), $summary['unit'], $tolerance
            ));
            header("Location: /orders/{$orderId}");
            return;
        }

        OrderPackingRepository::upsert($orderId, [
            'actual_quantity_packed' => $actualQtyRaw ?: null,
            'crate_count'            => trim((string) ($_POST['crate_count'] ?? '')) ?: null,
            'total_net_weight_kg'    => trim((string) ($_POST['total_net_weight_kg'] ?? '')) ?: null,
            'total_gross_weight_kg'  => trim((string) ($_POST['total_gross_weight_kg'] ?? '')) ?: null,
            'total_cbm'              => trim((string) ($_POST['total_cbm'] ?? '')) ?: null,
            'packing_date'           => trim((string) ($_POST['packing_date'] ?? '')) ?: null,
            // Auto-computed whenever comparable; otherwise the pre-existing
            // manual field is the only source of truth we have.
            'shortfall_pct'          => $shortfallPct !== null ? (string) $shortfallPct : (trim((string) ($_POST['shortfall_pct'] ?? '')) ?: null),
        ]);

        if ($uploadingApprovalNow) {
            $order = OrderRepository::find($orderId);
            try {
                $fileId = FileUploadService::handleUpload(
                    'buyer_approval',
                    'buyer_approval',
                    'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/packing',
                    null,
                    $orderId,
                    (int) AuthService::currentUser()['id'],
                    null,
                    'Buyer',
                    'Quantity shortfall approval'
                );
                OrderPackingRepository::attachBuyerApproval($orderId, $fileId);
            } catch (\Throwable $e) {
                Flash::set('error', 'Packing figures saved, but the approval upload failed: ' . $e->getMessage());
                header("Location: /orders/{$orderId}");
                return;
            }
        }

        $crateNos = $_POST['crate_no'] ?? [];
        $crates = [];
        foreach ($crateNos as $i => $crateNo) {
            $crateNo = trim((string) $crateNo);
            if ($crateNo === '') {
                continue;
            }
            $crates[] = [
                'crate_no'            => $crateNo,
                'marks_numbers'       => trim((string) ($_POST['crate_marks_numbers'][$i] ?? '')) ?: null,
                'product_description' => trim((string) ($_POST['crate_product_description'][$i] ?? '')) ?: null,
                'dimensions_lwh_cm'   => trim((string) ($_POST['crate_dimensions'][$i] ?? '')) ?: null,
                'pcs'                 => trim((string) ($_POST['crate_pcs'][$i] ?? '')) ?: null,
                'net_weight_kg'       => trim((string) ($_POST['crate_net_weight_kg'][$i] ?? '')) ?: null,
                'gross_weight_kg'     => trim((string) ($_POST['crate_gross_weight_kg'][$i] ?? '')) ?: null,
                'cbm'                 => trim((string) ($_POST['crate_cbm'][$i] ?? '')) ?: null,
                'hs_code'             => trim((string) ($_POST['crate_hs_code'][$i] ?? '')) ?: null,
            ];
        }
        OrderCrateRepository::replaceForOrder($orderId, $crates);

        Flash::set('success', 'Packing figures and crate breakdown saved. Generate the Packing List below.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 7: record shipping/vessel details, ahead of generating the BL Instruction Sheet. */
    public function saveShipping(array $params): void
    {
        $orderId = (int) $params['id'];
        OrderShippingRepository::upsert($orderId, [
            'shipping_line'  => trim((string) ($_POST['shipping_line'] ?? '')) ?: null,
            'vessel_name'    => trim((string) ($_POST['vessel_name'] ?? '')) ?: null,
            'voyage_number'  => trim((string) ($_POST['voyage_number'] ?? '')) ?: null,
            'etd'            => trim((string) ($_POST['etd'] ?? '')) ?: null,
            'eta'            => trim((string) ($_POST['eta'] ?? '')) ?: null,
            'container_type' => trim((string) ($_POST['ship_container_type'] ?? '')) ?: null,
            'container_no'   => trim((string) ($_POST['container_no'] ?? '')) ?: null,
            'seal_no'        => trim((string) ($_POST['seal_no'] ?? '')) ?: null,
        ]);
        Flash::set('success', 'Shipping details saved. Generate the BL Instruction Sheet below.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 7->8 gate: BL issued (vessel departs) — also the date CI must match. */
    public function recordBlIssued(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $blNumber = trim((string) ($_POST['bl_number'] ?? ''));
        $blDate = trim((string) ($_POST['bl_date'] ?? '')) ?: date('Y-m-d');
        if ($blNumber === '') {
            Flash::set('error', 'BL number is required to confirm this gate.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderShippingRepository::recordBl($orderId, $blNumber, $blDate);
        StageGateService::passAndUnlockNext($orderId, 7, (int) $user['id']);
        Flash::set('success', "Bill of Lading recorded ({$blNumber}). Stage 8 (Commercial Invoice) unlocked.");
        header("Location: /orders/{$orderId}");
    }

    public function recordScannedBlSent(array $params): void
    {
        $orderId = (int) $params['id'];
        OrderShippingRepository::recordScannedBlSent($orderId);
        Flash::set('success', 'Scanned BL copy marked as sent to buyer.');
        header("Location: /orders/{$orderId}");
    }

    public function recordBalancePayment(array $params): void
    {
        $orderId = (int) $params['id'];
        $amount = (float) ($_POST['balance_amount'] ?? 0);
        $receivedAt = trim((string) ($_POST['balance_received_at'] ?? '')) ?: date('Y-m-d');
        if ($amount <= 0) {
            Flash::set('error', 'Enter the balance amount actually received.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderPaymentStatusRepository::recordBalanceReceived($orderId, $amount, $receivedAt);
        Flash::set('success', 'Balance remittance recorded. Mark it cleared once your bank confirms receipt.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 8->9 gate: 60% balance payment cleared — triggers COO prep / document despatch / closure. */
    public function clearBalancePayment(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $clearedAt = trim((string) ($_POST['balance_cleared_at'] ?? '')) ?: date('Y-m-d');
        OrderPaymentStatusRepository::markBalanceCleared($orderId, $clearedAt, (int) $user['id']);
        StageGateService::passAndUnlockNext($orderId, 8, (int) $user['id']);
        Flash::set('success', 'Balance payment cleared. Stage 9 (Document Despatch & Closure) unlocked.');
        header("Location: /orders/{$orderId}");
    }

    public function recordBlOriginalsReceived(array $params): void
    {
        $orderId = (int) $params['id'];
        $count = (int) ($_POST['bl_originals_count'] ?? 3);
        OrderShippingRepository::recordBlOriginalsReceived($orderId, $count ?: 3);
        Flash::set('success', 'Original BL copies recorded as received from CHA.');
        header("Location: /orders/{$orderId}");
    }

    public function recordBlEndorsed(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        OrderShippingRepository::recordBlEndorsed($orderId, (int) $user['id']);
        Flash::set('success', 'Original BLs marked as endorsed by NexaCrest.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 9 gate: COO received, BL originals endorsed, complete document set couriered to buyer -> order closed. */
    public function closeOrder(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $trackingNumber = trim((string) ($_POST['courier_tracking_number'] ?? ''));
        if ($trackingNumber === '') {
            Flash::set('error', 'Courier tracking number is required to close the order.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderShippingRepository::recordCourierSent($orderId, $trackingNumber);
        StageGateService::passAndUnlockNext($orderId, 9, (int) $user['id']);
        OrderRepository::markComplete($orderId);
        Flash::set('success', "Order closed — complete document set couriered to buyer (tracking: {$trackingNumber}).");
        header("Location: /orders/{$orderId}");
    }

    /**
     * Added 2026-09-19 — reporting had no way to distinguish "still in play"
     * from "buyer walked away" (spec items #7/#10, "quotation/PI lost").
     * Reason is mandatory and audit-logged, same pattern as every other
     * override action in this controller; the order locks the same way
     * closeOrder() locks a completed one. Reversing a wrong "lost" call goes
     * through the existing overrideStatusLock() escape hatch above (now that
     * 'lost' is in its allowed-status whitelist) rather than a second
     * bespoke action.
     */
    public function markLost(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        if ($order['status'] !== 'active') {
            Flash::set('error', "Only an active order can be marked lost (this one is already \"{$order['status']}\").");
            header("Location: /orders/{$orderId}");
            return;
        }
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
            Flash::set('error', 'A reason is required to mark an order lost — nothing was saved.');
            header("Location: /orders/{$orderId}");
            return;
        }

        OrderRepository::markLost($orderId, $reason, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ORDER_MARKED_LOST', 'orders', $orderId, 'status', $order['status'], 'lost', $reason);
        Flash::set('success', 'Order marked as lost.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Spec Section 13 — "Stage/lock/order status flags" is explicitly
     * named as an Admin-editable field, distinct from the normal stage-
     * gate progression (StageGateService) which is the non-override path
     * every order takes. This is the escape hatch for a genuinely wrong
     * status/lock flag — reason mandatory, logged with old/new value.
     */
    public function overrideStatusLock(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $user = AuthService::currentUser();
        $newStatus = (string) ($_POST['status'] ?? '');
        $newIsLocked = isset($_POST['is_locked']);
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if (!in_array($newStatus, ['active', 'complete', 'disputed', 'lost'], true)) {
            Flash::set('error', 'Invalid status value.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $oldIsLocked = (bool) $order['is_locked'];
        if ($newStatus === $order['status'] && $newIsLocked === $oldIsLocked) {
            Flash::set('success', 'No change was made.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if ($reason === '') {
            Flash::set('error', 'A reason is required to override order status/lock — nothing was saved.');
            header("Location: /orders/{$orderId}");
            return;
        }

        AdminOverrideRepository::updateOrderStatusLock($orderId, $newStatus, $newIsLocked);
        if ($newStatus !== $order['status']) {
            AuditLogRepository::log((int) $user['id'], 'FIELD_EDIT', 'orders', $orderId, 'status', $order['status'], $newStatus, $reason);
        }
        if ($newIsLocked !== $oldIsLocked) {
            AuditLogRepository::log((int) $user['id'], 'LOCK_OVERRIDE', 'orders', $orderId, 'is_locked', $oldIsLocked ? '1' : '0', $newIsLocked ? '1' : '0', $reason);
        }
        Flash::set('success', 'Order status/lock overridden.');
        header("Location: /orders/{$orderId}");
    }
}
