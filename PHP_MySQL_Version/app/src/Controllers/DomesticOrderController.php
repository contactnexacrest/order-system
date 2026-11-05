<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\ClientRepository;
use App\Repositories\DomesticOrderRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\DivisionService;
use App\Services\DomesticDocumentService;
use App\Services\DomesticOrderService;
use App\Services\MoneyVisibility;
use App\Services\OrderAccessService;
use App\Services\PermissionService;
use App\Services\ZohoBooksService;

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): the Domestic Trade (DOM) order
 * page and its steps. A DOM order opens at /orders/{id} like any order (the
 * order page hands over to show() here); its actions post to /domestic/orders/{id}/….
 * Every action checks the same order access as the order page (assignment,
 * division) and that the order is a DOM order. Port: domesticOrderController.js.
 */
final class DomesticOrderController
{
    /** D-257 Phase 3: the division a new local sale belongs to — Import & Distribution when the person works in it, else Domestic Trade. */
    private static function newOrderCode(): string
    {
        $imp = DomesticOrderService::divisionId('IMP');
        return $imp !== null && DivisionService::currentId() === $imp ? 'IMP' : DomesticOrderService::CODE;
    }

    public function create(array $params): void
    {
        $code = self::newOrderCode();
        View::render('domestic/create', [
            'code' => $code,
            'active' => DomesticOrderService::isActive($code),
            'clients' => DomesticOrderService::isActive($code) ? ClientRepository::all() : [],
            'states' => DomesticOrderRepository::states(),
            'defaultTerms' => DomesticOrderService::setting('dom_payment_terms_days'),
            'old' => Flash::pullOld(),
        ], 'layout/base');
    }

    public function store(array $params): void
    {
        $user = AuthService::currentUser();
        [$orderId, $error] = DomesticOrderService::create($_POST, (int) $user['id'], self::newOrderCode());
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
            header('Location: /domestic/orders/create');
            return;
        }
        Flash::set('success', 'Domestic order created. Add its lines, then generate the quotation.');
        header("Location: /orders/{$orderId}");
    }

    /** Called by OrderController::show() for a DOM order (access already checked there). @param array<string,mixed> $order */
    public static function show(array $order): void
    {
        $orderId = (int) $order['id'];
        $dom = DomesticOrderRepository::find($orderId);
        if ($dom === null) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $can = static fn (string $p): bool => PermissionService::can((int) $user['id'], $roleId, $p);
        $totals = DomesticOrderService::totals($orderId);
        // D-257 Phase 3: an Import & Distribution sale comes out of stock.
        $isImp = DomesticOrderService::codeOfDivision((int) $order['division_id']) === 'IMP';
        View::render('domestic/show', [
            'isImp' => $isImp,
            'stockProducts' => $isImp ? \App\Repositories\ImportRepository::stockOnHand([(int) $order['division_id']]) : [],
            'stockIssues' => $isImp ? \App\Services\ImportService::stockShortfalls((int) $order['division_id'], DomesticOrderRepository::lines($orderId)) : [],
            'order' => $order,
            'dom' => $dom,
            'lines' => $totals['lines'],
            'totals' => $totals,
            'payments' => DomesticOrderRepository::payments($orderId),
            'paid' => DomesticOrderService::paidTotal($orderId),
            'documents' => DomesticOrderRepository::documents($orderId),
            'blockers' => DomesticOrderService::blockers($orderId),
            'needsEway' => DomesticOrderService::needsEwayBill($dom, $totals),
            'states' => DomesticOrderRepository::states(),
            'seesPrices' => MoneyVisibility::can(MoneyVisibility::SELLING),
            'seesPayments' => MoneyVisibility::can(MoneyVisibility::BUYER_PAYMENTS),
            'canEdit' => $can('manage_orders') && (int) ($order['is_locked'] ?? 0) !== 1,
            'canPay' => $can('manage_payments'),
            'canDocs' => $can('generate_documents'),
            'canZoho' => $can('ca_module_manage') && ZohoBooksService::isEnabled(),
        ], 'layout/base');
    }

    /** The DOM order behind a /domestic/orders/{id}/… action, or null after answering 404/403. */
    private static function order(array $params): ?array
    {
        $order = OrderRepository::find((int) $params['id']);
        if ($order === null || !DomesticOrderService::isDomesticOrder($order)) {
            http_response_code(404);
            echo 'Order not found.';
            return null;
        }
        $user = AuthService::currentUser();
        $denied = OrderAccessService::denialReason($order, (int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null);
        if ($denied !== null) {
            http_response_code(403);
            echo $denied;
            return null;
        }
        if ((int) ($order['is_locked'] ?? 0) === 1) {
            Flash::set('error', 'This order is closed and locked.');
            header("Location: /orders/{$order['id']}");
            return null;
        }
        DivisionService::enterOrderDivision((int) $order['division_id']);
        return $order;
    }

    private static function done(int $orderId, ?string $error, string $ok, string $anchor = ''): void
    {
        Flash::set($error === null ? 'success' : 'error', $error ?? $ok);
        header("Location: /orders/{$orderId}" . ($anchor !== '' ? '#' . $anchor : ''));
    }

    private static function userId(): int
    {
        return (int) AuthService::currentUser()['id'];
    }

    public function saveParty(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        self::done((int) $order['id'], DomesticOrderService::saveParty((int) $order['id'], $_POST, self::userId()), 'Buyer and place of supply saved.', 'dom-party');
    }

    public function addLine(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        self::done((int) $order['id'], DomesticOrderService::addLine((int) $order['id'], $_POST, self::userId()), 'Line added.', 'dom-lines');
    }

    public function removeLine(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        self::done((int) $order['id'], DomesticOrderService::removeLine((int) $order['id'], (int) $params['lineId'], self::userId()), 'Line removed.', 'dom-lines');
    }

    public function savePurchase(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        self::done((int) $order['id'], DomesticOrderService::savePurchase((int) $order['id'], $_POST, self::userId()), 'Supplier PO saved.', 'dom-purchase');
    }

    public function saveDispatch(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        self::done((int) $order['id'], DomesticOrderService::saveDispatch((int) $order['id'], $_POST, self::userId()), 'Dispatch saved.', 'dom-dispatch');
    }

    public function invoiceSent(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        self::done((int) $order['id'], DomesticOrderService::markInvoiceSent((int) $order['id'], trim((string) ($_POST['invoice_sent_on'] ?? '')), self::userId()), 'Tax invoice marked as sent.', 'dom-invoice');
    }

    public function recordPayment(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        self::done((int) $order['id'], DomesticOrderService::recordPayment((int) $order['id'], $_POST, self::userId()), 'Payment recorded.', 'dom-payments');
    }

    public function advance(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        $error = DomesticOrderService::advance((int) $order['id'], (string) ($_POST['from_stage'] ?? ''), self::userId());
        $dom = DomesticOrderRepository::find((int) $order['id']);
        self::done((int) $order['id'], $error, 'Moved on to: ' . (DomesticOrderService::STAGES[$dom['stage'] ?? ''] ?? '') . '.');
    }

    public function generate(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        $code = strtoupper(trim((string) ($_POST['doc_code'] ?? '')));
        [, $error] = DomesticDocumentService::generate((int) $order['id'], $code, self::userId());
        self::done((int) $order['id'], $error, (DomesticOrderService::DOCUMENTS[$code] ?? 'Document') . ' generated.', 'dom-documents');
    }

    /** D-257 Zoho: the order's "App vs Zoho" check (reads only). */
    public function zohoCheck(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        \App\Controllers\CaController::divisionZohoCheck('order', (int) $order['id'], "/orders/{$order['id']}", 'Order ' . $order['order_reference'], (string) $order['company_legal_name']);
    }

    public function pushZoho(array $params): void
    {
        if (($order = self::order($params)) === null) {
            return;
        }
        [$error, $ok] = \App\Services\DivisionZohoSync::sendNow('order', (int) $order['id'], self::userId());
        self::done((int) $order['id'], $error, $ok, 'dom-invoice');
    }

    /** A client's GST registration (Domestic Trade). Shown on the client page only once Domestic Trade is active. */
    public function saveClientGst(array $params): void
    {
        $clientId = (int) $params['id'];
        $client = ClientRepository::find($clientId);
        if ($client === null || !DivisionService::allowsClient($clientId)) {
            http_response_code(404);
            echo 'Client not found.';
            return;
        }
        $gstin = strtoupper(trim((string) ($_POST['gstin'] ?? '')));
        $state = trim((string) ($_POST['gst_state_code'] ?? ''));
        $pos = trim((string) ($_POST['place_of_supply_state'] ?? ''));
        $error = match (true) {
            !DomesticOrderService::isActive() => 'Domestic Trade is not active.',
            $gstin !== '' && !\App\Services\GstCalculator::isValidGstin($gstin) => 'The GSTIN is not valid (15 characters: state code, PAN, entity number, Z and a check character).',
            $state !== '' && !DomesticOrderRepository::isState($state) => 'Choose the state from the list.',
            $pos !== '' && !DomesticOrderRepository::isState($pos) => 'Choose the place of supply from the list.',
            $gstin !== '' && $state !== '' && substr($gstin, 0, 2) !== $state => 'The GSTIN starts with state code ' . substr($gstin, 0, 2) . ', which does not match the state chosen.',
            default => null,
        };
        if ($error === null) {
            if ($gstin !== '' && $state === '') {
                $state = substr($gstin, 0, 2);
            }
            DomesticOrderRepository::setClientGst($clientId, $gstin ?: null, $state ?: null, $pos ?: null);
            \App\Repositories\AuditLogRepository::log(self::userId(), 'CLIENT_GST_SAVED', 'clients', $clientId, 'gstin', $client['gstin'] ?? null, $gstin ?: null);
        }
        Flash::set($error === null ? 'success' : 'error', $error ?? 'GST details saved.');
        header("Location: /clients/{$clientId}#client-gst");
    }
}
