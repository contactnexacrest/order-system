<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AdminOverrideRepository;
use App\Repositories\AmendmentRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DisputeRepository;
use App\Repositories\DocumentCrossVerificationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\EmailLogRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\HsCodeRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderCommentRepository;
use App\Repositories\OrderCrateRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPackingRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\OrderStageRepository;
use App\Repositories\OrderSupplierPoDocumentRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\PiIntakeRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UserRepository;
use App\Config\Env;
use App\Services\AuthService;
use App\Services\EmailService;
use App\Services\FileUploadService;
use App\Services\PermissionService;
use App\Services\ReferenceNumberService;
use App\Services\StageGateService;
use App\Services\TestModeService;

final class OrderController
{
    /**
     * Card-grid list (2026-09-23 redesign) — the filter chips are plain
     * query-string links (?status=...), no JS, matching how every other
     * filtered list in this app (e.g. DisputeController::index()) already
     * works. Counts for the chip labels always come from the full
     * unfiltered set so a chip never has to be clicked to know its count.
     */
    public function index(array $params): void
    {
        $all = OrderRepository::all();
        $statusFilter = trim((string) ($_GET['status'] ?? ''));

        $counts = [
            'all' => count($all),
            'active' => 0,
            'overdue' => 0,
            'complete' => 0,
            'lost' => 0,
        ];
        foreach ($all as $o) {
            if (!empty($o['is_overdue'])) {
                $counts['overdue']++;
            }
            if (isset($counts[$o['status']])) {
                $counts[$o['status']]++;
            }
        }

        $orders = match ($statusFilter) {
            'overdue' => array_values(array_filter($all, static fn($o) => !empty($o['is_overdue']))),
            'active', 'complete', 'lost' => array_values(array_filter($all, static fn($o) => $o['status'] === $statusFilter)),
            default => $all,
        };

        View::render('orders/index', [
            'orders' => $orders,
            'statusFilter' => $statusFilter ?: 'all',
            'counts' => $counts,
        ], 'layout/base');
    }

    public function archivedIndex(array $params): void
    {
        View::render('orders/archived', ['orders' => OrderRepository::allArchived()], 'layout/base');
    }

    /**
     * Visibility-only, never a deletion path — see schema.sql Section W. No
     * reason is required (matches the toggle-active precedent for a
     * reversible, non-destructive state flip); the confirm() dialog on the
     * button is what stands in for a deliberate-action check here.
     */
    public function archive(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            Flash::set('error', 'Order not found.');
            header('Location: /orders');
            return;
        }
        $actor = AuthService::currentUser();
        OrderRepository::archive($orderId, (int) $actor['id']);
        AuditLogRepository::log((int) $actor['id'], 'ORDER_ARCHIVED', 'orders', $orderId, 'is_archived', '0', '1');
        Flash::set('success', "{$order['order_reference']} archived — it no longer appears in the main Orders list. Nothing was deleted; view it any time from Archived Orders.");
        header('Location: /orders');
    }

    public function unarchive(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            Flash::set('error', 'Order not found.');
            header('Location: /orders/archived');
            return;
        }
        $actor = AuthService::currentUser();
        OrderRepository::unarchive($orderId);
        AuditLogRepository::log((int) $actor['id'], 'ORDER_UNARCHIVED', 'orders', $orderId, 'is_archived', '1', '0');
        Flash::set('success', "{$order['order_reference']} restored to the main Orders list.");
        header("Location: /orders/{$orderId}");
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
            'hsCodes'         => HsCodeRepository::active(),
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

        // HS code must come from the master list (docs/schema.sql Section AB) —
        // never freehand — so a typo or an invalid code can never reach an
        // order. Checked here, before anything is written, so a bad code
        // never leaves a half-created order behind.
        foreach ($descriptions as $i => $description) {
            if (trim((string) $description) === '') {
                continue;
            }
            $hsCode = trim((string) ($_POST['product_hs_code'][$i] ?? ''));
            if ($hsCode === '' || !HsCodeRepository::isActiveCode($hsCode)) {
                Flash::set('error', "HS code \"{$hsCode}\" is not on the HS Code master list — add it there first (HS Codes, under Admin) before using it on an order.");
                header('Location: /orders/create?client_id=' . $clientId);
                return;
            }
        }

        $portOfDischargeId = !empty($_POST['port_of_discharge_id']) ? (int) $_POST['port_of_discharge_id'] : null;
        $portOfDischargeText = trim((string) ($_POST['port_of_discharge_text'] ?? ''));

        $sequenceNo = OrderRepository::nextSequenceForClient($clientId);
        $orderRefFormat = CompanySettingsRepository::get('order_ref_format') ?? 'SC/OC/{YYYY}/{NNN}';
        $testModeEnabled = TestModeService::isEnabled();
        $orderReference = TestModeService::applyReferencePrefix(
            strtr($orderRefFormat, [
                '{YYYY}' => date('Y'),
                '{NNN}'  => str_pad((string) $sequenceNo, 3, '0', STR_PAD_LEFT),
            ]) . '-' . $clientId, // client suffix keeps this globally unique even though the format string isn't scoped per-client
            $testModeEnabled
        );

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
        if ($testModeEnabled) {
            OrderRepository::markTest($orderId);
        }

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
                trim((string) ($_POST['product_hs_code'][$i] ?? ''))
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
        $actor = AuthService::currentUser();
        $actorRoleId = $actor['role_id'] !== null ? (int) $actor['role_id'] : null;

        // Archiving only removes an order from the default listing — direct
        // access by URL/bookmark still needs its own gate, or
        // view_archived_orders would be meaningless (Super Admin already
        // bypasses every permission).
        if ((int) $order['is_archived'] === 1) {
            $canView = PermissionService::can((int) $actor['id'], $actorRoleId, 'view_archived_orders');
            if (!$canView) {
                http_response_code(403);
                echo 'This order has been archived. You need the "View archived orders" permission to open it.';
                return;
            }
        }

        // CA / Accounting module (Phase 1) — INR-actual settlement widgets
        // on this page are shown/hidden per-permission rather than gated at
        // the route level, since they're a small part of a page most staff
        // already have full access to.
        $canViewInrActual = PermissionService::can((int) $actor['id'], $actorRoleId, 'inr_actual_view');
        $canEditInrActual = PermissionService::can((int) $actor['id'], $actorRoleId, 'inr_actual_edit');
        $canDeleteInrActual = PermissionService::can((int) $actor['id'], $actorRoleId, 'inr_actual_delete');

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

        // Real gap this closes: EmailLogRepository::forOrder() already
        // existed but nothing on this page ever called it, so the "Send to
        // Buyer" link would silently reappear after a Level-2 rejection or
        // a cron dispatch failure with no visible reason, and nothing
        // stopped a second Level-1 request from being submitted while an
        // earlier one still sat in the approval queue. Grouped by
        // document_id so each document's own send history renders next to
        // its own review/approval block below.
        $emailLogByDocument = [];
        foreach (EmailLogRepository::forOrder($orderId) as $log) {
            if ($log['document_id'] !== null) {
                $emailLogByDocument[(int) $log['document_id']][] = $log;
            }
        }

        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);

        View::render('orders/show', [
            'order'    => $order,
            'products' => OrderProductRepository::forOrder($orderId),
            'stages'   => $stages,
            'stageByNumber' => $stageByNumber,
            'payment'  => OrderPaymentStatusRepository::find($orderId),
            'documents' => $documents,
            'reviewsByDocument' => $reviewsByDocument,
            'crossVerificationsByDocument' => $crossVerificationsByDocument,
            'emailLogByDocument' => $emailLogByDocument,
            'activeUsers' => UserRepository::listActive(),
            'fobTotal' => OrderProductRepository::totalFobValue($orderId),
            'suppliers' => SupplierRepository::all(),
            'supplierPo' => $supplierPo,
            'buyerPoDocuments' => OrderBuyerPoDocumentRepository::forOrder($orderId),
            'supplierPoDocuments' => $supplierPo ? OrderSupplierPoDocumentRepository::forSupplierPo((int) $supplierPo['id']) : [],
            'freight'   => OrderFreightRepository::find($orderId),
            'packing'   => OrderPackingRepository::find($orderId),
            'crates'    => OrderCrateRepository::forOrder($orderId),
            'shipping'  => OrderShippingRepository::find($orderId),
            'production' => OrderProductionRepository::find($orderId),
            'supplierTypes' => LookupRepository::dropdownOptions('supplier_type'),
            'amendmentCount' => count(AmendmentRepository::forOrder($orderId)),
            'openDisputeCount' => count(array_filter(DisputeRepository::forOrder($orderId), static fn(array $d): bool => $d['status'] !== 'Resolved')),
            'piIntake' => PiIntakeRepository::latestForOrder($orderId),
            'clientPaymentReports' => ClientPaymentReportRepository::forOrder($orderId),
            'ocAcknowledgment' => OrderOcAcknowledgmentRepository::find($orderId),
            'comments' => OrderCommentRepository::forOrder($orderId),
            'canViewInrActual' => $canViewInrActual,
            'canEditInrActual' => $canEditInrActual,
            'canDeleteInrActual' => $canDeleteInrActual,
        ], 'layout/base');
    }

    /**
     * Staff-initiated: generates (or regenerates) a per-order PI-stage
     * intake link and emails it to the client's on-file address —
     * mirroring ClientPortalService's set-password email pattern. The
     * link is also flashed back once, same as a freshly created user's
     * temp password, so staff always have a way to hand it over even
     * when no SMTP is configured yet (EmailService degrades to a log
     * line in that case, never a thrown error).
     */
    public function generatePiFormLink(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $user = AuthService::currentUser();
        $rawToken = PiIntakeRepository::createLink($orderId, (int) $user['id']);
        $link = rtrim(Env::get('APP_URL', ''), '/') . '/pi-details/' . $rawToken;

        if (!empty($order['client_email'])) {
            $body = "Hello,\n\n"
                . "Thank you for accepting our Quotation for order {$order['order_reference']}.\n\n"
                . "To issue your Proforma Invoice, please confirm your shipping/consignee details at:\n{$link}\n\n"
                . "This link works for 30 days.\n\n"
                . "NexaCrest International Private Limited";
            EmailService::sendPlainText($order['client_email'], 'NexaCrest — confirm your PI-stage details', $body);
        }

        AuditLogRepository::log((int) $user['id'], 'PI_INTAKE_LINK_GENERATED', 'orders', $orderId, null, null, null, 'PI-stage intake link generated' . (!empty($order['client_email']) ? ' and emailed to client' : ' — no client email on file, hand this link over directly'));
        Flash::set('success', "PI-stage form link" . (!empty($order['client_email']) ? " emailed to {$order['client_email']}" : ' generated') . ": {$link}");
        header("Location: /orders/{$orderId}");
    }

    /**
     * Real gap this closes: no way existed to hand over (or archive)
     * everything on file for one order in a single action — every
     * generated document and every received/uploaded file (dispute
     * evidence, buyer PO copy, supplier PO acknowledgment, ...) had to be
     * downloaded one at a time. file_store.order_id is already set for
     * both origins (insertGenerated() and insertReceived()), so
     * FileStoreRepository::forOrder() alone is everything the dossier
     * needs — no separate joins through documents/dispute_documents/etc.
     * Built as a temp file (not in-memory) since ZipArchive needs a real
     * seekable file handle to write to, then streamed and deleted —
     * never left behind in storage/ for a stray/orphaned ZIP to
     * accumulate.
     */
    public function downloadDossier(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $files = FileStoreRepository::forOrder($orderId);
        if (empty($files)) {
            Flash::set('error', 'No files are on record for this order yet.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'nexacrest_dossier_');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            Flash::set('error', 'Could not build the dossier ZIP — check server storage/permissions.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $usedNames = [];
        foreach ($files as $file) {
            if (!is_file($file['server_path'])) {
                continue; // skip a row whose file went missing rather than fail the whole dossier
            }
            $folder = str_starts_with((string) $file['file_origin'], 'GENERATED') ? 'Generated Documents' : 'Received Documents';
            $name = $file['original_filename'];
            $key = $folder . '/' . $name;
            if (isset($usedNames[$key])) {
                // Same filename twice in the same folder (e.g. two QT revisions
                // both named "QT ... Rev.0.pdf" before a numbering scheme
                // changed) — disambiguate with the file_store id rather than
                // silently letting one overwrite the other inside the ZIP.
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $base = pathinfo($name, PATHINFO_FILENAME);
                $name = $ext !== '' ? "{$base} (#{$file['id']}).{$ext}" : "{$name} (#{$file['id']})";
            }
            $usedNames[$key] = true;
            $zip->addFile($file['server_path'], "{$folder}/{$name}");
        }
        $zip->close();

        $safeOrderRef = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) ?? 'order';
        $downloadName = "NexaCrest Dossier - {$safeOrderRef}.zip";

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($zipPath));
        readfile($zipPath);
        unlink($zipPath);
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

    /**
     * Real gap this closes: recordBuyerPo() above only ever captured a
     * reference number typed by staff — the buyer's actual signed PO was
     * never kept on file anywhere, unlike every other counterparty-
     * evidence flow in this app (amendment_signed_copy, dispute_document,
     * buyer_approval). A separate upload endpoint (not folded into the
     * gate-confirmation form) so attaching a copy is never blocked by, or
     * required for, passing the gate — and a second/corrected upload adds
     * a new file_store row rather than replacing one, giving real version
     * history for free (schema.sql SECTION S).
     */
    public function uploadBuyerPoDocument(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        try {
            $fileId = FileUploadService::handleUpload(
                'document',
                'buyer_po_copy',
                'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/buyer_po',
                null,
                $orderId,
                (int) AuthService::currentUser()['id'],
                null,
                'buyer',
                'Buyer PO copy'
            );
            OrderBuyerPoDocumentRepository::attach($orderId, $fileId);
            Flash::set('success', 'Buyer PO copy attached.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
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

        // docs/schema.sql Section AC — fallback lock trigger. The PI-details
        // form (client's own consent) is the primary trigger; if a client
        // never completes that, real money moving is the latest point
        // client data can still be safely editable. A no-op if the
        // PI-details consent already locked this client first.
        $order = OrderRepository::find($orderId);
        if ($order) {
            ClientRepository::lockData((int) $order['client_id'], 'Auto-locked: advance remittance recorded before client PI-details consent');
        }

        Flash::set('success', 'Advance remittance recorded. Mark it cleared once your bank confirms receipt.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Acknowledges a client's self-reported payment (docs/schema.sql
     * Section AD) as seen — purely a bookkeeping marker for staff, never a
     * substitute for actually verifying the bank statement and recording
     * the payment via recordAdvancePayment()/recordBalancePayment()/
     * recordFreightPayment() as before.
     */
    public function markPaymentReportReviewed(array $params): void
    {
        $orderId = (int) $params['id'];
        $reportId = (int) ($params['reportId'] ?? 0);
        $report = ClientPaymentReportRepository::find($reportId);
        if (!$report || (int) $report['order_id'] !== $orderId) {
            Flash::set('error', 'Payment report not found.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $user = AuthService::currentUser();
        ClientPaymentReportRepository::markReviewed($reportId, (int) $user['id']);
        Flash::set('success', 'Payment report marked reviewed.');
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

    /** docs/schema.sql Section AF — per-order, staff-controlled, default off. */
    public function setDisputeButtonVisible(array $params): void
    {
        $orderId = (int) $params['id'];
        $visible = !empty($_POST['dispute_button_visible_to_client']);
        OrderRepository::setDisputeButtonVisible($orderId, $visible);
        $user = AuthService::currentUser();
        AuditLogRepository::log((int) $user['id'], 'DISPUTE_BUTTON_VISIBILITY_CHANGED', 'orders', $orderId, 'dispute_button_visible_to_client', null, $visible ? '1' : '0');
        Flash::set('success', $visible ? 'The client can now raise a dispute on this order from their portal.' : 'The "Raise a Dispute" button is now hidden from the client for this order.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Stage 4->5 gate: staff records a buyer's Order Confirmation
     * acknowledgment that arrived by reply-to-the-email rather than through
     * the client portal button (docs/schema.sql Section AE). Replaces the
     * old staff-only "Confirm Buyer Acknowledged Order" button, which never
     * required any evidence the buyer had actually agreed to anything — a
     * mandatory note (the reply itself, quoted, is normal practice here) is
     * this feature's substitute for that missing evidence.
     */
    public function recordOcAcknowledgment(array $params): void
    {
        $orderId = (int) $params['id'];
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        if (!$ack || $ack['acknowledged_at'] !== null) {
            Flash::set('error', 'No pending Order Confirmation acknowledgment for this order — send the OC to the buyer first.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $note = trim((string) ($_POST['acknowledged_note'] ?? ''));
        if ($error = ReasonValidator::check($note)) {
            Flash::set('error', 'Describe the evidence (e.g. quote the buyer\'s email reply): ' . $error);
            header("Location: /orders/{$orderId}");
            return;
        }

        $user = AuthService::currentUser();
        OrderOcAcknowledgmentRepository::markAcknowledged($orderId, 'staff_recorded_email', $note, (int) $user['id']);
        StageGateService::passAndUnlockNext($orderId, 4, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'OC_ACKNOWLEDGED_VIA_EMAIL', 'orders', $orderId, null, null, null, $note);
        Flash::set('success', 'Buyer acknowledgement recorded. Stage 5 (Supplier PO) unlocked.');
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
        $supplierId = SupplierRepository::create([
            'supplier_legal_name' => $name,
            'address'             => trim((string) ($_POST['address'] ?? '')) ?: null,
            'gstin'               => trim((string) ($_POST['gstin'] ?? '')) ?: null,
            'pan'                 => trim((string) ($_POST['pan'] ?? '')) ?: null,
            'contact_person'      => trim((string) ($_POST['contact_person'] ?? '')) ?: null,
            'phone'               => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'supplier_type'       => trim((string) ($_POST['supplier_type'] ?? '')) ?: null,
        ]);
        if (TestModeService::isEnabled()) {
            SupplierRepository::markTest($supplierId);
        }
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

    /**
     * Real gap this closes: confirmSupplierSigned() above only ever
     * flipped order_supplier_po.status on a button click — the supplier's
     * actual signed acknowledgment was never kept on file anywhere. A
     * separate upload endpoint, keyed to the specific order_supplier_po
     * row (not the order) since an order can have more than one Supplier
     * PO version and the acknowledgment belongs to the version it was
     * signed against; a second/corrected upload adds a new file_store row
     * rather than replacing one (schema.sql SECTION S).
     */
    public function uploadSupplierPoDocument(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        if (!$order || !$supplierPo) {
            Flash::set('error', 'Save the Supplier PO terms before attaching an acknowledgment copy.');
            header("Location: /orders/{$orderId}");
            return;
        }

        try {
            $fileId = FileUploadService::handleUpload(
                'document',
                'supplier_po_ack',
                'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/supplier_po',
                null,
                $orderId,
                (int) AuthService::currentUser()['id'],
                null,
                'supplier',
                'Supplier PO acknowledgment'
            );
            OrderSupplierPoDocumentRepository::attach((int) $supplierPo['id'], $fileId);
            Flash::set('success', 'Supplier PO acknowledgment attached.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
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

    // ----------------------------------------------------------------
    // CA / Accounting module (Phase 1) — INR actual settlement amounts.
    // Routes are gated on inr_actual_edit/inr_actual_delete (see
    // public_html/index.php); every write is audit-logged since this is
    // exactly the kind of field an auditor/CA will want a trail on.
    // ----------------------------------------------------------------

    public function recordAdvanceInrActual(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $amount = (float) ($_POST['advance_inr_actual'] ?? 0);
        if ($amount <= 0) {
            Flash::set('error', 'Enter the actual INR amount credited to the bank.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, $amount, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'CA_INR_ACTUAL_RECORDED', 'order_payment_status', $orderId, 'advance_inr_actual', null, (string) $amount);
        Flash::set('success', 'Advance INR actual amount recorded.');
        header("Location: /orders/{$orderId}");
    }

    public function deleteAdvanceInrActual(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        OrderPaymentStatusRepository::clearAdvanceInrActual($orderId);
        AuditLogRepository::log((int) $user['id'], 'CA_INR_ACTUAL_DELETED', 'order_payment_status', $orderId, 'advance_inr_actual');
        Flash::set('success', 'Advance INR actual amount removed.');
        header("Location: /orders/{$orderId}");
    }

    public function recordBalanceInrActual(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $amount = (float) ($_POST['balance_inr_actual'] ?? 0);
        if ($amount <= 0) {
            Flash::set('error', 'Enter the actual INR amount credited to the bank.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderPaymentStatusRepository::setBalanceInrActual($orderId, $amount, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'CA_INR_ACTUAL_RECORDED', 'order_payment_status', $orderId, 'balance_inr_actual', null, (string) $amount);
        Flash::set('success', 'Balance INR actual amount recorded.');
        header("Location: /orders/{$orderId}");
    }

    public function deleteBalanceInrActual(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        OrderPaymentStatusRepository::clearBalanceInrActual($orderId);
        AuditLogRepository::log((int) $user['id'], 'CA_INR_ACTUAL_DELETED', 'order_payment_status', $orderId, 'balance_inr_actual');
        Flash::set('success', 'Balance INR actual amount removed.');
        header("Location: /orders/{$orderId}");
    }

    public function recordFreightInrActual(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $amount = (float) ($_POST['freight_inr_actual'] ?? 0);
        if ($amount <= 0) {
            Flash::set('error', 'Enter the actual INR amount credited to the bank.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderPaymentStatusRepository::setFreightInrActual($orderId, $amount, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'CA_INR_ACTUAL_RECORDED', 'order_payment_status', $orderId, 'freight_inr_actual', null, (string) $amount);
        Flash::set('success', 'Freight INR actual amount recorded.');
        header("Location: /orders/{$orderId}");
    }

    public function deleteFreightInrActual(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        OrderPaymentStatusRepository::clearFreightInrActual($orderId);
        AuditLogRepository::log((int) $user['id'], 'CA_INR_ACTUAL_DELETED', 'order_payment_status', $orderId, 'freight_inr_actual');
        Flash::set('success', 'Freight INR actual amount removed.');
        header("Location: /orders/{$orderId}");
    }

    // ----------------------------------------------------------------
    // CA / Accounting module (Phase 2) — assumed exchange rate (one per
    // order, for the register's forex gain/loss column) and per-leg
    // FIRC/eBRC references. Same inr_actual_edit gating as Phase 1.
    // ----------------------------------------------------------------

    public function recordAssumedExchangeRate(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $rate = (float) ($_POST['assumed_exchange_rate'] ?? 0);
        if ($rate <= 0) {
            Flash::set('error', 'Enter the assumed INR exchange rate for this order.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderPaymentStatusRepository::setAssumedExchangeRate($orderId, $rate, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', $orderId, 'assumed_exchange_rate', null, (string) $rate);
        Flash::set('success', 'Assumed exchange rate recorded.');
        header("Location: /orders/{$orderId}");
    }

    public function recordAdvanceFirc(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $reference = trim((string) ($_POST['advance_firc_reference'] ?? ''));
        if ($reference === '') {
            Flash::set('error', 'Enter the FIRC/eBRC reference.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $receivedAt = trim((string) ($_POST['advance_firc_received_at'] ?? '')) ?: date('Y-m-d');
        OrderPaymentStatusRepository::setAdvanceFirc($orderId, $reference, $receivedAt);
        AuditLogRepository::log((int) $user['id'], 'CA_FIRC_RECORDED', 'order_payment_status', $orderId, 'advance_firc_reference', null, $reference);
        Flash::set('success', 'Advance FIRC/eBRC reference recorded.');
        header("Location: /orders/{$orderId}");
    }

    public function recordBalanceFirc(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $reference = trim((string) ($_POST['balance_firc_reference'] ?? ''));
        if ($reference === '') {
            Flash::set('error', 'Enter the FIRC/eBRC reference.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $receivedAt = trim((string) ($_POST['balance_firc_received_at'] ?? '')) ?: date('Y-m-d');
        OrderPaymentStatusRepository::setBalanceFirc($orderId, $reference, $receivedAt);
        AuditLogRepository::log((int) $user['id'], 'CA_FIRC_RECORDED', 'order_payment_status', $orderId, 'balance_firc_reference', null, $reference);
        Flash::set('success', 'Balance FIRC/eBRC reference recorded.');
        header("Location: /orders/{$orderId}");
    }

    public function recordFreightFirc(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $reference = trim((string) ($_POST['freight_firc_reference'] ?? ''));
        if ($reference === '') {
            Flash::set('error', 'Enter the FIRC/eBRC reference.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $receivedAt = trim((string) ($_POST['freight_firc_received_at'] ?? '')) ?: date('Y-m-d');
        OrderPaymentStatusRepository::setFreightFirc($orderId, $reference, $receivedAt);
        AuditLogRepository::log((int) $user['id'], 'CA_FIRC_RECORDED', 'order_payment_status', $orderId, 'freight_firc_reference', null, $reference);
        Flash::set('success', 'Freight FIRC/eBRC reference recorded.');
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
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
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
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
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
