<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\DeadlockRetry;
use App\Helpers\FieldValidator;
use App\Helpers\Flash;
use App\Helpers\OrderLineValidator;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AdminOverrideRepository;
use App\Repositories\AmendmentRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DisputeRepository;
use App\Repositories\DocumentCrossVerificationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\EmailLogRepository;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\HsCodeRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderCommentRepository;
use App\Repositories\OrderComplianceTaskRepository;
use App\Repositories\OrderExportBenefitSchemeRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderFeedbackRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderCrateRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPackingRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderAssigneeRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderBlEndorsementRepository;
use App\Repositories\OrderCancellationRepository;
use App\Repositories\OrderDraftBlReviewRepository;
use App\Repositories\OrderInspectionFeeRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\OrderStageRepository;
use App\Repositories\OrderSupplierPoDocumentRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\PiIntakeRepository;
use App\Repositories\SupplierFactoryAddressRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UserRepository;
use App\Config\Env;
use App\Services\AuthService;
use App\Services\CaFyLockGuard;
use App\Services\DocumentGenerationService;
use App\Services\EmailService;
use App\Services\FileUploadService;
use App\Services\MakerCheckerGuard;
use App\Services\OrderAssignmentService;
use App\Services\OrderCommentService;
use App\Services\OrderDuplicationService;
use App\Services\OrderEditGuard;
use App\Services\OrderProfitabilityService;
use App\Services\MoneyVisibility;
use App\Services\PermissionService;
use App\Services\ReferenceNumberService;
use App\Services\StageGateService;
use App\Services\TestModeService;
use App\Services\WetSignatureGuardService;

final class OrderController
{
    /** External QA report U-02. */
    private const ORDERS_PER_PAGE = 25;

    /**
     * Card-grid list (2026-09-23 redesign) — the filter chips are plain
     * query-string links (?status=...), no JS, matching how every other
     * filtered list in this app (e.g. DisputeController::index()) already
     * works. Counts for the chip labels always come from the full
     * unfiltered set so a chip never has to be clicked to know its count.
     */
    public function index(array $params): void
    {
        $actor = AuthService::currentUser();
        $seesAllOrders = PermissionService::can((int) $actor['id'], $actor['role_id'] !== null ? (int) $actor['role_id'] : null, 'view_all_orders');
        if (\App\Services\UiTemplateService::showsBoard($actor['ui_template_preference_id'] ?? null)) {
            $this->indexKanban((int) $actor['id'], $seesAllOrders);
            return;
        }

        $all = OrderRepository::allVisibleTo((int) $actor['id'], $seesAllOrders);
        // Default to the Active tab, not All — most orders in the system
        // at any time are still in play, and staff opening this screen
        // want to see what's actually moving, not a completed/lost order
        // buried in the same list. "All" is now its own explicit choice
        // (?status=all), distinct from no query param at all, so a
        // deliberate click on "All" is remembered for that pageview
        // exactly like every other tab.
        $statusFilter = trim((string) ($_GET['status'] ?? '')) ?: 'active';
        $counts = self::statusCounts($all);
        $filtered = self::filterByStatus($all, $statusFilter);

        // External QA report U-02: with ~200 orders this page was 17,600px
        // tall on desktop (52,000px on a phone) — one unpaginated
        // card-grid of every matching order at once, only getting worse as
        // data grows. Paginated in-PHP (not pushed down into the SQL
        // itself) since OrderRepository::all() already has to compute
        // every status's own total count for the filter chips above
        // regardless of which page is showing — the one query it already
        // runs stays the single source of truth for both.
        $totalFiltered = count($filtered);
        $totalPages = max(1, (int) ceil($totalFiltered / self::ORDERS_PER_PAGE));
        $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
        $orders = array_slice($filtered, ($page - 1) * self::ORDERS_PER_PAGE, self::ORDERS_PER_PAGE);

        View::render('orders/index', [
            'orders' => $orders,
            'statusFilter' => $statusFilter,
            'counts' => $counts,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalFiltered' => $totalFiltered,
            'seesAllOrders' => $seesAllOrders,
            // Owner hard rule 2026-11-01: a Kanban user who switched to the list can switch back.
            'kanbanUser' => \App\Services\UiTemplateService::resolve($actor['ui_template_preference_id'] ?? null) === 'kanban',
        ], 'layout/base');
    }

    /** @param list<array<string,mixed>> $all @return array<string,int> the count per status filter (Standard list and Kanban board alike) */
    private static function statusCounts(array $all): array
    {
        $counts = ['all' => count($all), 'active' => 0, 'overdue' => 0, 'shipped_docs_sent' => 0, 'complete' => 0, 'lost' => 0, 'cancelled' => 0];
        foreach ($all as $o) {
            if (!empty($o['is_overdue'])) {
                $counts['overdue']++;
            }
            if (isset($counts[$o['status']])) {
                $counts[$o['status']]++;
            }
        }
        return $counts;
    }

    /** @param list<array<string,mixed>> $all @return list<array<string,mixed>> the orders a status filter keeps */
    private static function filterByStatus(array $all, string $statusFilter): array
    {
        return match ($statusFilter) {
            'all' => $all,
            'overdue' => array_values(array_filter($all, static fn($o) => !empty($o['is_overdue']))),
            'active', 'shipped_docs_sent', 'complete', 'lost', 'cancelled' => array_values(array_filter($all, static fn($o) => $o['status'] === $statusFilter)),
            default => array_values(array_filter($all, static fn($o) => $o['status'] === 'active')),
        };
    }

    /**
     * docs/schema.sql Section BQ — the Kanban UI template's Orders list:
     * every active order as a card in its own Stage 1-9 column (the
     * pipeline staff actually work through), plus a "Docs Sent" and a
     * "Complete" column for the two closure phases. Lost/Cancelled orders
     * aren't given full board columns of their own — they're dead ends,
     * not pipeline stages — so they're rolled up into a compact summary
     * instead, same as every other real Kanban board treats a "won/lost"
     * outcome. Same status filters as the list, opening on Active like it
     * (QA Round 7, R7-N08). Not paged yet: paging the board waits for the
     * owner's answer to Q-R7-2.
     */
    private function indexKanban(int $actorId, bool $seesAllOrders): void
    {
        $all = OrderRepository::allVisibleTo($actorId, $seesAllOrders);
        // Owner hard rule 2026-11-01: the board has the same status filters (with counts) as the list.
        // QA Round 7, R7-N08: no filter = Active, exactly as the Standard list opens; Lost and Cancelled get columns when chosen.
        $statusFilter = trim((string) ($_GET['status'] ?? '')) ?: 'active';
        $visible = self::filterByStatus($all, $statusFilter);

        $columns = [];
        foreach (LookupRepository::stagesMaster() as $st) {
            $columns['stage' . (int) $st['stage_number']] = ['stage' => (int) $st['stage_number'], 'title' => (string) $st['stage_name'], 'orders' => []];
        }
        $columns['shipped_docs_sent'] = ['title' => 'Docs Sent — Awaiting Benefits', 'orders' => []];
        $columns['complete'] = ['title' => 'Complete', 'orders' => []];
        if (in_array($statusFilter, ['all', 'lost', 'cancelled', 'overdue'], true)) {
            $columns['lost'] = ['title' => 'Lost', 'orders' => []];
            $columns['cancelled'] = ['title' => 'Cancelled', 'orders' => []];
        }
        // D-257: Domestic Trade orders (and local sales) work through their own stages — one column each, only when there are such orders.
        $domStages = \App\Services\DomesticOrderService::STAGES;
        unset($domStages['closed']); // a closed one is Complete
        if (array_filter($visible, static fn (array $o): bool => ($o['dom_stage'] ?? null) !== null && $o['status'] === 'active') !== []) {
            $domColumns = [];
            foreach ($domStages as $k => $label) {
                $domColumns['dom_' . $k] = ['title' => 'Domestic · ' . $label, 'orders' => []];
            }
            $at = array_search('shipped_docs_sent', array_keys($columns), true);
            $columns = array_slice($columns, 0, (int) $at, true) + $domColumns + array_slice($columns, (int) $at, null, true);
        }
        foreach ($visible as $o) {
            $key = $o['status'] === 'active' ? 'stage' . ((int) ($o['current_stage_number'] ?? 1) ?: 1) : (string) $o['status'];
            if (($o['dom_stage'] ?? null) !== null && $o['status'] === 'active') {
                $key = 'dom_' . $o['dom_stage'];
            }
            if (isset($columns[$key])) {
                $columns[$key]['orders'][] = $o;
            }
        }
        // Owner decision 1 Nov 2026 (A-5, Q-R7-2): each column pages like the list — the latest 25 cards,
        // then "Show more" adds 25 at a time (?more_<column>=50, 75, …). The count shows the whole column.
        foreach ($columns as $key => $col) {
            $shown = max(self::ORDERS_PER_PAGE, (int) ($_GET['more_' . $key] ?? 0));
            $shown = (int) (ceil($shown / self::ORDERS_PER_PAGE) * self::ORDERS_PER_PAGE);
            $columns[$key]['total'] = count($col['orders']);
            $columns[$key]['orders'] = array_slice($col['orders'], 0, $shown);
            $columns[$key]['moreUrl'] = $columns[$key]['total'] > $shown
                ? '/orders?' . http_build_query(array_filter(['status' => $statusFilter] + array_filter($_GET, static fn ($k): bool => is_string($k) && str_starts_with($k, 'more_'), ARRAY_FILTER_USE_KEY)) + ['more_' . $key => $shown + self::ORDERS_PER_PAGE]) . '#col-' . $key
                : null;
        }

        View::render('orders/index_kanban', [
            'columns' => $columns,
            'counts' => self::statusCounts($all),
            'statusFilter' => $statusFilter,
            'totalActive' => count(array_filter($all, static fn($o) => $o['status'] === 'active')),
            'seesAllOrders' => $seesAllOrders,
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

    /**
     * Order-Edit feature — "repeat order" for a client who's ordered
     * before, even long after the original closed. Never touches the
     * source order (no gate needed there — nothing about it changes) and
     * the new order starts fresh at Stage 1, exactly like one created by
     * hand through /orders/create.
     */
    public function duplicateOrder(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            Flash::set('error', 'Order not found.');
            header('Location: /orders');
            return;
        }
        $actor = AuthService::currentUser();
        $newOrderId = OrderDuplicationService::duplicate($orderId, (int) $actor['id']);
        Flash::set('success', "Duplicated {$order['order_reference']} into a new order — review and adjust it below before proceeding.");
        header("Location: /orders/{$newOrderId}/edit");
    }

    public function create(array $params): void
    {
        // Owner decision D-257 Phase 2: someone working in Domestic Trade creates a domestic order.
        $__dom = \App\Services\DomesticOrderService::divisionId();
        $__imp = \App\Services\DomesticOrderService::divisionId('IMP'); // D-257 Phase 3: Import & Distribution sells through the same form
        $__cur = \App\Services\DivisionService::currentId();
        if ($__cur !== null && ($__cur === $__dom || $__cur === $__imp)) {
            header('Location: /domestic/orders/create');
            return;
        }
        $preselectedClientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : null;
        $incoterms = LookupRepository::incoterms();

        // Batch 3 #4 — if we're showing this page again after a validation failure on
        // submit, re-populate the whole form (including product rows) instead of making
        // the user retype everything.
        $old = Flash::pullOld();

        // 90-item backlog #41/#46: the first time staff open this form for
        // a client just converted from a Quotation-intake submission (no
        // validation-retry `old` data yet), prefill the commercial terms
        // the buyer actually asked for — otherwise Incoterm/container type
        // silently default away from what was requested (e.g. Incoterm
        // defaulting to FOB when the buyer's intake said CIF).
        //
        // 90-item backlog "feedback-loop fix": the guard above only checked
        // "no validation-retry data yet", so this kept firing on EVERY
        // later New Order form for the same client too — a second, third,
        // Nth order all silently re-prefilled from that client's very
        // first intake, feeding the same stale commercial terms back in
        // indefinitely even once the buyer's actual preference had moved
        // on. Added `$clientHasNoOrdersYet` so this only ever applies to
        // the client's genuinely first order, immediately following
        // conversion — once they have any order on record, staff type
        // this order's own terms instead of inheriting an aging intake.
        $clientHasNoOrdersYet = $preselectedClientId && empty(OrderRepository::forClient($preselectedClientId));
        if ($old === [] && $preselectedClientId && $clientHasNoOrdersYet) {
            $intake = ClientIntakeRepository::latestConvertedForClient($preselectedClientId);
            if ($intake) {
                if ($intake['incoterm_preference']) {
                    foreach ($incoterms as $i) {
                        if (strcasecmp(trim((string) $i['code']), trim((string) $intake['incoterm_preference'])) === 0) {
                            $old['incoterm_id'] = $i['id'];
                            break;
                        }
                    }
                }
                if ($intake['container_type_text']) {
                    $old['container_type'] = $intake['container_type_text'];
                }
                if ($intake['port_of_discharge_text']) {
                    $old['port_of_discharge_text'] = $intake['port_of_discharge_text'];
                }
            }
        }

        // 90-item backlog "default weight/volume values": Total CBM/Gross
        // Weight/Net Weight used to start every New Order form blank, same
        // as a brand-new Package Type/Package Count did before those
        // gained a configurable default — staff typed the same typical
        // figure on nearly every order anyway. Only fills a gap; never
        // overwrites a value already in $old (a validation retry, or the
        // intake-prefill block above, both win as-is).
        // Owner report (2026-10-26): start from the COO type the client gave
        // us (Quotation/PI details or their client record).
        if ($preselectedClientId && !isset($old['coo_type'])) {
            $__clientCoo = (string) (ClientRepository::find($preselectedClientId)['coo_type'] ?? '');
            if ($__clientCoo !== '' && $__clientCoo !== 'To Be Confirmed') {
                $old['coo_type'] = $__clientCoo;
            }
        }
        $old['estimated_total_cbm'] ??= CompanySettingsRepository::get('default_estimated_total_cbm');
        $old['estimated_gross_weight_kg'] ??= CompanySettingsRepository::get('default_estimated_gross_weight_kg');
        $old['estimated_net_weight_kg'] ??= CompanySettingsRepository::get('default_estimated_net_weight_kg');

        View::render('orders/create', [
            'clients'         => ClientRepository::all(),
            'incoterms'       => $incoterms,
            'currencies'      => LookupRepository::currencies(),
            'loadingPorts'    => LookupRepository::ports('loading'),
            'dischargePorts'  => LookupRepository::ports('discharge'),
            'paymentPresets'  => LookupRepository::paymentPresets(),
            'cooTypes'        => LookupRepository::dropdownOptions('coo_type'),
            'containerTypes'  => LookupRepository::dropdownOptions('container_type'),
            'units'           => LookupRepository::dropdownOptions('unit'),
            // Owner decision D-245 P2: "Pick from Products" fills the quote price of whoever sells (selling-visible people only).
            'pickerProducts'  => \App\Repositories\ProductRepository::forPicker(),
            'pickerBlocked'   => \App\Repositories\ProductRepository::notQuotable(), // D-361
            'pickerPrices'    => MoneyVisibility::can(MoneyVisibility::SELLING) ? \App\Services\OrderLinePricing::pickerPrices(\App\Repositories\ProductRepository::forPicker()) : [],
            'pickerSpecs'     => \App\Services\OrderLinePricing::pickerSpecs(\App\Repositories\ProductRepository::forPicker()),
            'clientPriceKeys' => MoneyVisibility::can(MoneyVisibility::SELLING) ? \App\Services\OrderLinePricing::clientKeys(ClientRepository::all()) : [],
            // Owner decision D-245 P2: on a USD order goods lines come from Products (browser rule beside the picker).
            'pickRequiredWhen' => self::createPickRequiredWhen(),
            'packageTypes'    => LookupRepository::dropdownOptions('package_type'),
            'surfaceFinishes' => LookupRepository::dropdownOptions('surface_finish'),
            'preselectedClientId' => $preselectedClientId,
            'hsCodes'         => HsCodeRepository::active(),
            'old'             => $old,
        ], 'layout/base');
    }

    public function store(array $params): void
    {
        $user = AuthService::currentUser();

        $clientId = (int) ($_POST['client_id'] ?? 0);
        $client = $clientId ? ClientRepository::find($clientId) : null;
        if (!$client) {
            Flash::set('error', 'Please select a valid client.');
            Flash::setOld($_POST);
            header('Location: /orders/create');
            return;
        }
        // QA-5 ORD-04: the create-order dropdown only ever lists active
        // clients (ClientRepository::all()), but find() here has no
        // is_active filter — a direct/forged POST, or a client deactivated
        // in the moment between page load and submit, otherwise sailed
        // straight through to a new order for a client the business has
        // already cut off.
        if (!$client['is_active']) {
            Flash::set('error', 'This client is not active — reactivate them before creating a new order.');
            Flash::setOld($_POST);
            header('Location: /orders/create');
            return;
        }

        $incotermId = (int) ($_POST['incoterm_id'] ?? 0);
        $currencyId = (int) ($_POST['currency_id'] ?? 0);
        $paymentPresetId = (int) ($_POST['payment_preset_id'] ?? 0);
        if (!$incotermId || !$currencyId || !$paymentPresetId) {
            Flash::set('error', 'Incoterm, currency, and a payment preset are all required.');
            Flash::setOld($_POST);
            header('Location: /orders/create?client_id=' . $clientId);
            return;
        }
        // Owner decision D-257 Phase 2: rupees are for Domestic Trade orders only; a domestic order has its own form.
        $__inr = Database::connection()->prepare("SELECT 1 FROM currencies WHERE id = :id AND code = 'INR'");
        $__inr->execute(['id' => $currencyId]);
        $__domId = \App\Services\DomesticOrderService::divisionId();
        $__impId = \App\Services\DomesticOrderService::divisionId('IMP');
        $__postedDivision = (int) ($_POST['division_id'] ?? 0);
        if ($__inr->fetchColumn() !== false || ($__domId !== null && $__postedDivision === $__domId) || ($__impId !== null && $__postedDivision === $__impId)) {
            Flash::set('error', 'Orders in rupees belong to Domestic Trade: use New domestic order.');
            Flash::setOld($_POST);
            header('Location: /orders/create?client_id=' . $clientId);
            return;
        }
        // QA Round 5, R5-29: a wrong number is refused with a message, never a database error.
        if ($numberError = FieldValidator::orderNumbers($_POST)) {
            Flash::set('error', $numberError);
            Flash::setOld($_POST);
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
            Flash::setOld($_POST);
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
                Flash::setOld($_POST);
                header('Location: /orders/create?client_id=' . $clientId);
                return;
            }
        }

        // QA-5 ORD-05/ORD-06: quantity/unit price used to be passed straight
        // through as raw strings all the way to a DECIMAL column bind — a
        // non-numeric value crashed with an uncaught PDOException deep in
        // OrderProductRepository::add(), by which point the order/stage/
        // payment-status rows were already committed, and a negative
        // quantity was never rejected at all. Checked here, same "before
        // anything is written" placement as the HS-code check above.
        // Owner decision D-339: a new order prints the detailed product table — each row's No. of
        // pieces works out its quantity here (the browser's figure is never trusted).
        $__rowPieces = [];
        foreach ($descriptions as $i => $description) {
            if (trim((string) $description) === '') {
                continue;
            }
            $__rowPieces[$i] = self::piecesInput($_POST, (int) $i, false);
            if ($__rowPieces[$i][2] !== null) {
                Flash::set('error', 'Product line ' . ($i + 1) . ': ' . $__rowPieces[$i][2]);
                Flash::setOld($_POST);
                header('Location: /orders/create?client_id=' . $clientId);
                return;
            }
        }
        foreach ($descriptions as $i => $description) {
            if (trim((string) $description) === '') {
                continue;
            }
            $quantityError = OrderLineValidator::checkLine(
                (string) ($_POST['product_quantity'][$i] ?? ''),
                !empty($_POST['product_quantity_tbc'][$i]),
                (string) ($_POST['product_unit_price'][$i] ?? '')
            );
            if ($quantityError !== null) {
                Flash::set('error', 'Product line ' . ($i + 1) . ": {$quantityError}");
                Flash::setOld($_POST);
                header('Location: /orders/create?client_id=' . $clientId);
                return;
            }
            // Owner decision item 10a: above about 20 containers of its unit, only with "I confirm this quantity".
            $quantityError = \App\Services\QuantityLimits::check(
                (string) ($_POST['product_quantity'][$i] ?? ''),
                (string) ($_POST['product_unit'][$i] ?? ''),
                !empty($_POST['product_quantity_tbc'][$i]),
                (string) ($_POST['product_' . \App\Services\QuantityLimits::TICK][$i] ?? '') === '1'
            );
            if ($quantityError !== null) {
                Flash::set('error', 'Product line ' . ($i + 1) . ": {$quantityError}");
                Flash::setOld($_POST);
                header('Location: /orders/create?client_id=' . $clientId);
                return;
            }
            $priceError = OrderLineValidator::checkUnitPrice((string) ($_POST['product_unit_price'][$i] ?? ''));
            if ($priceError !== null) {
                Flash::set('error', 'Product line ' . ($i + 1) . ": {$priceError}");
                Flash::setOld($_POST);
                header('Location: /orders/create?client_id=' . $clientId);
                return;
            }
            // Owner decision D-245 P2: never below the product's minimum price.
            $__currencyCode = '';
            foreach (LookupRepository::currencies() as $__cur) {
                if ((int) $__cur['id'] === $currencyId) {
                    $__currencyCode = (string) $__cur['code'];
                }
            }
            // Owner decision D-245 P2: on a USD order goods lines come from Products (Admin → Pricing).
            if (($__pick = \App\Services\OrderLinePricing::pickRefusal($__currencyCode, self::catalogProductInput($_POST['product_catalog_id'][$i] ?? ''), 'goods', $_POST['product_catalog_id'][$i] ?? null)) !== null) {
                Flash::set('error', 'Product line ' . ($i + 1) . ": {$__pick}");
                Flash::setOld($_POST);
                header('Location: /orders/create?client_id=' . $clientId);
                return;
            }
            // D-270 (point 25): the row's piece size; a typed thickness chooses the Thickness option.
            [$__size, $__sizeError] = self::lineSizeInput($_POST, $i);
            [$__rowSel, $__syncError] = $__sizeError === null
                ? \App\Services\OrderLinePricing::syncThickness(self::catalogProductInput($_POST['product_catalog_id'][$i] ?? ''), \App\Services\OrderLinePricing::selectionInput($_POST['product_spec_json'][$i] ?? ''), $__size['piece_thickness_cm'])
                : [null, null];
            // D-348: a line picked from Products has its Thickness and Size / format chosen.
            $__specRefusal = $__sizeError === null && $__syncError === null
                ? \App\Services\OrderLinePricing::requiredSpecsRefusal($i + 1, self::catalogProductInput($_POST['product_catalog_id'][$i] ?? ''), 'goods', $__rowSel)
                : null;
            if ($__specRefusal !== null) {
                Flash::set('error', $__specRefusal);
                Flash::setOld($_POST);
                header('Location: /orders/create?client_id=' . $clientId);
                return;
            }
            $__refusal = $__sizeError ?? $__syncError ?? \App\Services\OrderLinePricing::saveRefusal(
                ['client_id' => $clientId, 'currency_code' => $__currencyCode],
                self::catalogProductInput($_POST['product_catalog_id'][$i] ?? ''),
                'goods',
                OrderLineValidator::priceInput($_POST['product_unit_price'][$i] ?? null),
                self::seesPriceBuildup(),
                // D-255: the row's chosen specification options and unit.
                $__rowSel,
                trim((string) ($_POST['product_unit'][$i] ?? '')) ?: null,
                null,
                $__size
            );
            if ($__refusal !== null) {
                Flash::set('error', 'Product line ' . ($i + 1) . ": {$__refusal}");
                Flash::setOld($_POST);
                header('Location: /orders/create?client_id=' . $clientId);
                return;
            }
        }

        $portOfDischargeId = !empty($_POST['port_of_discharge_id']) ? (int) $_POST['port_of_discharge_id'] : null;
        $portOfDischargeText = trim((string) ($_POST['port_of_discharge_text'] ?? ''));


        // QA-5 ORD-05: the order row, its stage/payment-status rows, and
        // every product line used to be inserted as separate, unwrapped
        // statements — any failure partway through (the crash this defect
        // was originally reported for, or any other insert error) left a
        // real, visible "half-created" order behind with no products or
        // stages. The input validation above now rules out the specific
        // known trigger, but this transaction is what actually guarantees
        // the "no half-created order" property in general: any failure
        // anywhere in this block rolls back the order row itself along
        // with everything else.
        // External QA report L-13 (CONC-03): under real concurrent load a
        // few of these transactions still hit an InnoDB deadlock (SQLSTATE
        // 40001) between this one and another running at the same time —
        // see DeadlockRetry's own docblock for why the fix is retrying the
        // whole attempt rather than shortening the transaction. MySQL's
        // deadlock detector already guarantees this is always safe to
        // retry: it only ever aborts a transaction that made no visible
        // change (it rolls back everything the aborted side did).
        // D-257: the order's division — the creator's own, or the one a company-level person chose.
        $chosenDivision = ($_POST['division_id'] ?? '') !== '' ? (int) $_POST['division_id'] : null;
        if (\App\Services\DivisionService::needsChoiceOnCreate() && ($chosenDivision === null || \App\Services\DivisionService::find($chosenDivision) === null)) {
            Flash::set('error', 'Please choose the division this order belongs to.');
            Flash::setOld($_POST);
            header('Location: /orders/create');
            return;
        }
        if (!\App\Services\DivisionService::allowsClient($clientId) && !\App\Services\DivisionService::seesAll()) {
            Flash::set('error', 'Please select a valid client.');
            Flash::setOld($_POST);
            header('Location: /orders/create');
            return;
        }
        \App\Services\DivisionService::enterOrderDivision(\App\Services\DivisionService::forNewRecord($chosenDivision));
        $testModeEnabled = TestModeService::isEnabled();
        ['orderId' => $orderId, 'orderReference' => $orderReference] = DeadlockRetry::run(function () use (
            $testModeEnabled, $clientId, $client, $paymentPresetId, $incotermId,
            $portOfDischargeId, $portOfDischargeText, $currencyId, $descriptions, $user, $__rowPieces
        ): array {
            // Owner decision 4 Nov 2026 (D1): NC/SC/ORD/{YY}/{DDMM}{NNN}, a daily counter.
            $orderReferenceNew = \App\Services\ReferenceNumberService::generateOrderReference();
            $pdo = Database::connection();
            $pdo->beginTransaction();
            try {
            // QA-5 CONC-03: sequence-number reservation and the order INSERT
            // now happen atomically inside createWithNextSequence() — see its
            // docblock in OrderRepository.php. order_reference embeds the
            // sequence number, so it can only be built once the closure runs
            // with the safely-reserved value.
            $result = OrderRepository::createWithNextSequence($clientId, function (int $sequenceNo) use (
                $orderReferenceNew, $clientId, $client, $paymentPresetId, $incotermId,
                $portOfDischargeId, $portOfDischargeText, $currencyId
            ): array {
                return [
                    'order_reference'       => $orderReferenceNew,
                    'buyer_inquiry_ref'     => $client['client_unique_number'],
                    'payment_preset_id'     => $paymentPresetId,
                    'incoterm_id'           => $incotermId,
                    'port_of_loading_id'    => !empty($_POST['port_of_loading_id']) ? (int) $_POST['port_of_loading_id'] : null,
                    'port_of_discharge_id'  => $portOfDischargeId,
                    'port_of_discharge_text' => $portOfDischargeId ? null : ($portOfDischargeText ?: null),
                    'currency_id'           => $currencyId,
                    'coo_type'              => LookupRepository::sanitizeOption('coo_type', trim((string) ($_POST['coo_type'] ?? ''))) ?: $client['coo_type'] ?? 'To Be Confirmed',
                    'include_annexure_a'    => !empty($_POST['include_annexure_a']),
                    'special_requirements'  => trim((string) ($_POST['special_requirements'] ?? '')) ?: null,
                    'container_type'        => trim((string) ($_POST['container_type'] ?? '')) ?: null,
                    'estimated_total_cbm'   => trim((string) ($_POST['estimated_total_cbm'] ?? '')),
                    'estimated_gross_weight_kg' => trim((string) ($_POST['estimated_gross_weight_kg'] ?? '')),
                    'estimated_net_weight_kg'   => trim((string) ($_POST['estimated_net_weight_kg'] ?? '')),
                    'estimated_package_count'   => trim((string) ($_POST['estimated_package_count'] ?? '')) ?: null,
                    'estimated_package_type'    => trim((string) ($_POST['estimated_package_type'] ?? '')) ?: null,
                    'est_lead_time_text'    => trim((string) ($_POST['est_lead_time_text'] ?? '')) ?: null,
                    'lead_time_min_days'    => ($_POST['lead_time_min_days'] ?? '') !== '' ? (int) $_POST['lead_time_min_days'] : null,
                    'lead_time_max_days'    => ($_POST['lead_time_max_days'] ?? '') !== '' ? (int) $_POST['lead_time_max_days'] : null,
                    'sailing_lead_time_min_days' => ($_POST['sailing_lead_time_min_days'] ?? '') !== '' ? (int) $_POST['sailing_lead_time_min_days'] : null,
                    'sailing_lead_time_max_days' => ($_POST['sailing_lead_time_max_days'] ?? '') !== '' ? (int) $_POST['sailing_lead_time_max_days'] : null,
                    'indicative_freight_amount' => trim((string) ($_POST['indicative_freight_amount'] ?? '')),
                    'indicative_insurance_amount' => trim((string) ($_POST['indicative_insurance_amount'] ?? '')),
                    'buyers_po_ref'         => 'NIL',
                    'quotation_date'        => date('Y-m-d'),
                    'quotation_valid_until' => date('Y-m-d', strtotime('+' . ((int) (CompanySettingsRepository::get('quotation_validity_days') ?? 30)) . ' days')),
                ];
            }, (int) $user['id']);
            $orderId = $result['orderId'];
            $orderReference = $result['orderReference'];
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
                // D-270: the piece size (checked above); the Dimensions text is filled from it when left empty.
                [$__size] = self::lineSizeInput($_POST, $i);
                [$__rowSel] = \App\Services\OrderLinePricing::syncThickness(self::catalogProductInput($_POST['product_catalog_id'][$i] ?? ''), \App\Services\OrderLinePricing::selectionInput($_POST['product_spec_json'][$i] ?? ''), $__size['piece_thickness_cm']);
                $__lineId = OrderProductRepository::add(
                    $orderId,
                    $lineNo++,
                    $description,
                    trim((string) ($_POST['product_dimensions'][$i] ?? '')) ?: self::dimensionsFromSize($__size),
                    trim((string) ($_POST['product_finish'][$i] ?? '')) ?: null,
                    trim((string) ($_POST['product_quantity'][$i] ?? '')) ?: null,
                    $quantityIsTbc,
                    trim((string) ($_POST['product_unit'][$i] ?? '')) ?: null,
                    OrderLineValidator::priceInput($_POST['product_unit_price'][$i] ?? null),
                    trim((string) ($_POST['product_hs_code'][$i] ?? ''))
                );
                // Owner decision item 10a: a confirmed large quantity is audited.
                if (\App\Services\QuantityLimits::isLarge((string) ($_POST['product_quantity'][$i] ?? ''), (string) ($_POST['product_unit'][$i] ?? ''), $quantityIsTbc)) {
                    \App\Services\QuantityLimits::audit((int) $user['id'], 'order_products', $__lineId, $description, (string) $_POST['product_quantity'][$i], (string) ($_POST['product_unit'][$i] ?? ''));
                }
                // Owner decision 2 Nov 2026 (D-240): the product it was picked from; who set its price.
                OrderProductRepository::setCatalogProduct($__lineId, self::catalogProductInput($_POST['product_catalog_id'][$i] ?? ''));
                if (trim((string) ($_POST['product_unit_price'][$i] ?? '')) !== '') {
                    OrderProductRepository::setPriceSetBy($__lineId, (int) $user['id']);
                }
                \App\Services\OrderLinePricing::saveSelection($__lineId, $__rowSel, null);
                OrderProductRepository::setPieceSize($__lineId, $__size);
                OrderProductRepository::setPieces($__lineId, $__rowPieces[$i][0] ?? null, $__rowPieces[$i][1] ?? null);
                // Owner decision D-245 P2: a line below its floor goes to the MD for approval.
                \App\Services\OrderLinePricing::afterSave($__lineId, null, (int) $user['id']);
            }

            $pdo->commit();
            return ['orderId' => $orderId, 'orderReference' => $orderReference];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        });
        \App\Services\DivisionService::leaveOrderDivision();

        // External QA report L-23: order creation was never audit-logged.
        AuditLogRepository::log((int) $user['id'], 'ORDER_CREATED', 'orders', $orderId, 'order_reference', null, $orderReference);

        // QA Round 5, R5-03: terms needing MD/ED approval — tell the approvers.
        if (\App\Services\PaymentTermsApprovalService::isPending($orderId)) {
            foreach (\App\Repositories\PermissionRepository::usersWithPermission('approve_payment_terms') as $approverUserId) {
                \App\Repositories\NotificationRepository::create($approverUserId, null, 'payment_terms_approval_needed', $orderId, "Order {$orderReference}: payment terms need your approval before the Quotation can be generated.");
            }
        }

        Flash::set('success', "Order {$orderReference} created for {$client['company_legal_name']}.");
        // Owner decision (2 Nov 2026, Q-6b): a soft warning when the buyer's open orders now exceed the ECGC limit.
        $__ecgc = \App\Services\ComplianceTrackingService::ecgcPosition((int) $client['id']);
        if ($__ecgc['limit'] !== null && $__ecgc['over_limit']) {
            Flash::set('warning', \App\Services\MoneyVisibility::can(\App\Services\MoneyVisibility::SELLING)
                ? 'ECGC: ' . $__ecgc['warning']
                : 'ECGC: this buyer\'s open orders are above (or outside) the ECGC-approved credit limit — check with Finance.');
        }
        header("Location: /orders/{$orderId}");
    }

    /**
     * Order-Edit feature — the core fields set once at order creation had
     * no edit path at all until now. Deliberately excludes payment terms
     * (advance/balance %, balance trigger/days) — those change through the
     * Amendments module specifically, never here, so there's exactly one
     * place that changes payment terms. Freely editable before Order
     * Confirmation (Stage 4); gated by OrderEditGuard from Stage 4 on.
     */
    public function editDetails(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        // QA Round 6, R6-N27: taken from the saved order, before defaults or typed values are shown in the form.
        $editFingerprint = \App\Helpers\EditFingerprint::of($order, OrderRepository::EDIT_DETAIL_FIELDS);
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        // Owner request (2026-10-25): show the configured default instead
        // of an "e.g." placeholder when this order has no estimate saved —
        // the same default the New Order form pre-fills. Only fills a gap;
        // a saved value always wins.
        foreach (['estimated_total_cbm', 'estimated_gross_weight_kg', 'estimated_net_weight_kg'] as $__field) {
            if ($order[$__field] === null || $order[$__field] === '') {
                $order[$__field] = CompanySettingsRepository::get('default_' . $__field);
            }
        }
        // QA Round 5, R5-29: after a refused save, show what was typed, not the saved values.
        foreach (Flash::pullOld() as $__field => $__value) {
            if (array_key_exists($__field, $order) && !is_array($__value)) {
                $order[$__field] = $__value;
            }
        }

        View::render('orders/edit_details', [
            'editFingerprint' => $editFingerprint,
            'order'           => $order,
            'incoterms'       => LookupRepository::incoterms(),
            'currencies'      => LookupRepository::currencies(),
            'loadingPorts'    => LookupRepository::ports('loading'),
            'dischargePorts'  => LookupRepository::ports('discharge'),
            'cooTypes'        => LookupRepository::dropdownOptions('coo_type'),
            'containerTypes'  => LookupRepository::dropdownOptions('container_type'),
            'packageTypes'    => LookupRepository::dropdownOptions('package_type'),
            'isPostConfirmation' => OrderEditGuard::isPostConfirmation($order['current_stage_number'] !== null ? (int) $order['current_stage_number'] : null),
            'canOverride'     => OrderEditGuard::canOverride((int) $user['id'], $roleId),
            'isLocked'        => (bool) $order['is_locked'],
            'canOverrideLock' => OrderEditGuard::canOverrideLock((int) $user['id'], $roleId),
        ], 'layout/base');
    }

    public function updateDetails(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        $incotermId = (int) ($_POST['incoterm_id'] ?? 0);
        $currencyId = (int) ($_POST['currency_id'] ?? 0);
        if (!$incotermId || !$currencyId) {
            Flash::set('error', 'Incoterm and currency are both required.');
            Flash::setOld($_POST);
            header("Location: /orders/{$orderId}/edit");
            return;
        }
        // QA Round 5, R5-29: a wrong number is refused with a message, never a database error.
        if ($numberError = FieldValidator::orderNumbers($_POST)) {
            Flash::set('error', $numberError);
            Flash::setOld($_POST);
            header("Location: /orders/{$orderId}/edit");
            return;
        }

        // QA Round 6, R6-N27: someone else saved this form since it was opened.
        if ($conflict = \App\Helpers\EditFingerprint::conflict($order, OrderRepository::EDIT_DETAIL_FIELDS, 'orders', $orderId, 'order')) {
            Flash::set('error', $conflict);
            header("Location: /orders/{$orderId}/edit");
            return;
        }
        // QA batch 2 (ORD-07): the incoterm decides the freight stage (FOB skips Stage 6), which is settled once
        // Stage 6 is reached or skipped — after that an incoterm change would leave freight wrong either way.
        if ($incotermId !== (int) $order['incoterm_id']) {
            $freightStage = OrderStageRepository::findByOrderAndStageNumber($orderId, 6);
            if ($freightStage !== null && in_array($freightStage['status'], ['in_progress', 'gate_passed', 'skipped'], true)) {
                Flash::set('error', 'The incoterm can no longer change: the freight stage (Stage 6) is already ' . ($freightStage['status'] === 'skipped' ? 'skipped' : 'under way') . ' for the current incoterm. Agree the change with the buyer through an amendment, and ask an administrator to correct the order.');
                Flash::setOld($_POST);
                header("Location: /orders/{$orderId}/edit");
                return;
            }
        }

        // Owner decision D-342: the approved Quotation fixed the prices in its currency — a currency change would change them.
        if ($currencyId !== (int) $order['currency_id'] && ($lockRefusal = \App\Services\PriceLock::refusal($order)) !== null) {
            Flash::set('error', 'The currency can no longer change. ' . $lockRefusal);
            Flash::setOld($_POST);
            header("Location: /orders/{$orderId}/edit");
            return;
        }

        $currentStageNumber = $order['current_stage_number'] !== null ? (int) $order['current_stage_number'] : null;
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!OrderEditGuard::allow($currentStageNumber, (bool) $order['is_locked'], (int) $user['id'], $roleId, $reason, 'orders', $orderId, 'order_details')) {
            header("Location: /orders/{$orderId}/edit");
            return;
        }

        $portOfDischargeId = !empty($_POST['port_of_discharge_id']) ? (int) $_POST['port_of_discharge_id'] : null;
        $portOfDischargeText = trim((string) ($_POST['port_of_discharge_text'] ?? ''));

        OrderRepository::updateDetails($orderId, [
            'incoterm_id'                 => $incotermId,
            'currency_id'                 => $currencyId,
            'port_of_loading_id'          => !empty($_POST['port_of_loading_id']) ? (int) $_POST['port_of_loading_id'] : null,
            'port_of_discharge_id'        => $portOfDischargeId,
            'port_of_discharge_text'      => $portOfDischargeId ? null : ($portOfDischargeText ?: null),
            'coo_type'                    => LookupRepository::sanitizeOption('coo_type', trim((string) ($_POST['coo_type'] ?? ''))) ?: 'To Be Confirmed',
            'container_type'              => trim((string) ($_POST['container_type'] ?? '')) ?: null,
            'buyers_po_ref'               => trim((string) ($_POST['buyers_po_ref'] ?? '')) ?: 'NIL',
            'special_requirements'        => trim((string) ($_POST['special_requirements'] ?? '')) ?: null,
            'est_lead_time_text'          => trim((string) ($_POST['est_lead_time_text'] ?? '')) ?: null,
            'lead_time_min_days'          => ($_POST['lead_time_min_days'] ?? '') !== '' ? (int) $_POST['lead_time_min_days'] : null,
            'lead_time_max_days'          => ($_POST['lead_time_max_days'] ?? '') !== '' ? (int) $_POST['lead_time_max_days'] : null,
            'sailing_lead_time_min_days'  => ($_POST['sailing_lead_time_min_days'] ?? '') !== '' ? (int) $_POST['sailing_lead_time_min_days'] : null,
            'sailing_lead_time_max_days'  => ($_POST['sailing_lead_time_max_days'] ?? '') !== '' ? (int) $_POST['sailing_lead_time_max_days'] : null,
            'estimated_total_cbm'         => trim((string) ($_POST['estimated_total_cbm'] ?? '')),
            'estimated_gross_weight_kg'   => trim((string) ($_POST['estimated_gross_weight_kg'] ?? '')),
            'estimated_net_weight_kg'     => trim((string) ($_POST['estimated_net_weight_kg'] ?? '')),
            'estimated_package_count'     => trim((string) ($_POST['estimated_package_count'] ?? '')) ?: null,
            'estimated_package_type'      => trim((string) ($_POST['estimated_package_type'] ?? '')) ?: null,
            'indicative_freight_amount'   => trim((string) ($_POST['indicative_freight_amount'] ?? '')),
            'indicative_insurance_amount' => trim((string) ($_POST['indicative_insurance_amount'] ?? '')),
        ]);

        AuditLogRepository::log((int) $user['id'], 'ORDER_DETAILS_UPDATED', 'orders', $orderId);
        Flash::set('success', 'Order details updated.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Order-Edit feature — order_products had no add/edit/delete/duplicate
     * path once the order was created; this closes that gap, gated by
     * OrderEditGuard exactly like updateDetails() above once the order
     * has reached Order Confirmation.
     */
    /**
     * Owner decision D-215: the line's type and its code. Goods need an HS
     * code from the master list (as before); a service needs its SAC code
     * (6 digits starting 99) and no HS code; a charge needs neither.
     * @return array{0:string,1:string,2:?string,3:?string} type, HS code, SAC code, error
     */
    private static function lineTypeInput(): array
    {
        $type = (string) ($_POST['line_type'] ?? 'goods');
        if (!array_key_exists($type, OrderProductRepository::LINE_TYPES)) {
            return ['goods', '', null, 'Choose whether the line is goods, a service or a charge.'];
        }
        $hsCode = trim((string) ($_POST['hs_code'] ?? ''));
        $sacCode = trim((string) ($_POST['sac_code'] ?? ''));
        if ($type === 'service') {
            return preg_match('/^99\d{4}$/', $sacCode) === 1
                ? ['service', '', $sacCode, null]
                : ['service', '', null, 'A service line needs its SAC code: 6 digits starting 99 (e.g. 998719).'];
        }
        if ($type === 'charge') {
            return $hsCode !== '' && !HsCodeRepository::isActiveCode($hsCode)
                ? ['charge', '', null, "HS code \"{$hsCode}\" is not on the HS Code master list — leave it empty for a charge, or add it there first."]
                : ['charge', $hsCode, null, null];
        }
        if ($hsCode === '' || !HsCodeRepository::isActiveCode($hsCode)) {
            return ['goods', $hsCode, null, "HS code \"{$hsCode}\" is not on the HS Code master list — add it there first (HS Codes, under Admin)."];
        }
        return ['goods', $hsCode, null, null];
    }

    /**
     * Owner decision D-215 (5a): the line's product category and, for a
     * category other than Natural Stone, its attribute values. A service
     * has no category; Natural Stone (or none chosen) keeps Dimensions and
     * Finish as today.
     *
     * @return array{0: ?int, 1: ?array<string,string>, 2: ?string} category id, values, error
     */
    private static function categoryInput(string $lineType, ?int $currentCategoryId = null): array
    {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        if ($lineType === 'service' || $categoryId === 0) {
            return [null, null, null];
        }
        $category = \App\Repositories\ProductCategoryRepository::find($categoryId);
        if ($category === null || ((int) $category['is_active'] !== 1 && $categoryId !== $currentCategoryId)) {
            return [null, null, 'Choose the product category from the list.'];
        }
        if ((int) $category['is_system'] === 1) {
            return [null, null, null];
        }
        $raw = (array) ($_POST['attr_' . $categoryId] ?? []);
        $values = [];
        foreach ($category['attributes'] as $i => $label) {
            $value = trim((string) ($raw[$i] ?? ''));
            if (mb_strlen($value) > 100) {
                return [null, null, "{$label}: at most 100 characters."];
            }
            $values[$label] = $value;
        }
        return [$categoryId, $values, null];
    }

    /** Owner decision 2 Nov 2026 (D-240): a posted "Pick from Products" id — only an active Products-menu product counts. */
    private static function catalogProductInput(mixed $value): ?int
    {
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }
        // D-361 (plan rule 4): and quotable — configured with an active supplier offer, or a product
        // from before migration 13b with no supplier yet (the same products Pick from Products offers).
        return \App\Repositories\ProductRepository::isQuotable($id) ? $id : null;
    }

    /** Owner decision D-245 P2: the New Order form's browser rule — the picker is required while the currency is USD. */
    private static function createPickRequiredWhen(): string
    {
        if (!\App\Services\PricingService::settings()['require_product_pick']) {
            return '';
        }
        foreach (LookupRepository::currencies() as $c) {
            if (strtoupper((string) $c['code']) === 'USD') {
                return 'currency_id=' . (int) $c['id'];
            }
        }
        return '';
    }

    /** Owner decision D-245 P2: whether the signed-in person sees the full price build-up (MD, ED). */
    private static function seesPriceBuildup(): bool
    {
        $u = AuthService::currentUser();
        return $u !== null && PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, 'view_price_buildup');
    }

    /** Owner decision D-245 P2: below the floor (or no price) — staff send the line to the MD. */
    public function requestLinePriceApproval(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $error = \App\Services\OrderLinePricing::requestApproval($orderId, (int) $params['productId'], (int) $user['id']);
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Sent to the MD for approval — the Proforma Invoice waits for it.');
        header("Location: /orders/{$orderId}#price-check");
    }

    /** Owner decision D-245 P2: the MD's approval screen for one line. */
    public function linePriceApproval(array $params): void
    {
        $orderId = (int) $params['id'];
        $view = \App\Services\OrderLinePricing::approvalView($orderId, (int) $params['productId']);
        if ($view === null || !\App\Services\OrderAccessService::currentUserMayOpenOrderId($orderId)) {
            http_response_code(404);
            echo 'Order line not found.';
            return;
        }
        View::render('orders/price_approval', $view + ['approver' => null], 'layout/base');
    }

    /** Owner decision D-245 P2: the MD approves a line price (never below the minimum price). */
    public function approveLinePrice(array $params): void
    {
        $orderId = (int) $params['id'];
        $lineId = (int) $params['productId'];
        $user = AuthService::currentUser();
        $refuse = ($_POST['decision'] ?? 'approve') === 'refuse';
        $error = $refuse
            ? \App\Services\OrderLinePricing::refuse($orderId, $lineId, (int) $user['id'], (string) ($_POST['reason'] ?? ''))
            : \App\Services\OrderLinePricing::approve($orderId, $lineId, (int) $user['id'], (string) ($_POST['reason'] ?? ''));
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}/products/{$lineId}/price-approval");
            return;
        }
        Flash::set('success', $refuse ? 'Price refused — whoever asked has been told; the line stays below the floor until its price is raised.' : 'Price approved — the line no longer keeps the Proforma Invoice back.');
        header("Location: /orders/{$orderId}#price-check");
    }

    /** The flash after saving a line, saying when it now waits for the MD. */
    /**
     * Owner decision D-270 (point 25): a line's piece size from the form — the order page's
     * piece_*_cm fields, or row $row of the New Order form's product_piece_*_cm[].
     *
     * @return array{0: array{piece_length_cm: ?string, piece_width_cm: ?string, piece_thickness_cm: ?string}, 1: ?string}
     */
    private static function lineSizeInput(array $post, ?int $row = null): array
    {
        $values = [];
        foreach (array_keys(\App\Services\LineUnits::SIZE_FIELDS) as $f) {
            $values[$f] = $row === null ? ($post[$f] ?? '') : ($post['product_' . $f][$row] ?? '');
        }
        [$size, $error] = \App\Services\LineUnits::readSize($values);
        if ($error !== null) {
            return [['piece_length_cm' => null, 'piece_width_cm' => null, 'piece_thickness_cm' => null], $error];
        }
        return [$size, null];
    }

    /**
     * Owner decision D-339: a line's "No. of pieces" and "Size note" — the order page's
     * pieces_count / size_note, or row $row of the New Order form's product_pieces_count[] /
     * product_size_note[]. With the size its unit is worked out from (CBM / SQM / SQFT with
     * Length and Width, and Thickness for CBM) the pieces are required and the quantity is
     * worked out here from them, whatever the browser sent (PCS: the quantity is the pieces).
     * $post's quantity (and "Qty To Be Confirmed") are set to it. A line "As per annexure"
     * keeps D-336 (no pieces). Only on an order with the detailed product table.
     *
     * @return array{0: ?int, 1: ?string, 2: ?string} [pieces, size note, error]
     */
    private static function piecesInput(array &$post, ?int $row, bool $perAnnexure): array
    {
        $get = static fn (string $f): mixed => $row === null ? ($post[$f] ?? '') : ($post['product_' . $f][$row] ?? '');
        [$pieces, $error] = \App\Services\LineTable::readPieces($get('pieces_count'));
        [$note, $noteError] = \App\Services\LineTable::readSizeNote($get('size_note'));
        if (($error ?? $noteError) !== null) {
            return [null, null, $error ?? $noteError];
        }
        if ($perAnnexure) {
            return [null, $note, null];
        }
        [$size, $sizeError] = self::lineSizeInput($post, $row);
        if ($sizeError !== null) {
            return [$pieces, $note, null]; // the size's own message follows
        }
        $unit = trim((string) $get('unit'));
        $tbcKey = $row === null ? 'quantity_is_tbc' : 'product_quantity_tbc';
        [$quantity, $qtyError] = \App\Services\LineTable::serverQuantity(
            $unit !== '' ? $unit : null,
            \App\Services\LineUnits::size($size['piece_length_cm']),
            \App\Services\LineUnits::size($size['piece_width_cm']),
            \App\Services\LineUnits::size($size['piece_thickness_cm']),
            $pieces,
            false,
            $row === null ? !empty($post[$tbcKey]) : !empty($post[$tbcKey][$row])
        );
        if ($qtyError !== null) {
            return [$pieces, $note, $qtyError];
        }
        if ($quantity !== null) {
            if ($row === null) {
                $post['quantity'] = $quantity;
                unset($post['quantity_is_tbc']);
            } else {
                $post['product_quantity'][$row] = $quantity;
                unset($post['product_quantity_tbc'][$row]);
            }
        }
        return [$pieces, $note, null];
    }

    /** D-339: the order prints the detailed product table (new orders); 'classic' orders keep the line form as before. */
    private static function detailedLines(array $order): bool
    {
        return ($order['line_table_layout'] ?? \App\Services\LineTable::LAYOUT_CLASSIC) === \App\Services\LineTable::LAYOUT_DETAILED;
    }

    /**
     * Owner decision D-336: the line form's "As per annexure" tick box. Never together
     * with "Qty To Be Confirmed"; the quantity stays required (it is the line's
     * quantity). When ticked, the piece size and the Dimensions text are cleared in
     * $post (the documents print "Dimensions: As per Annexure A/B"). A form without
     * the tick box (no as_per_annexure_field) keeps the line's current setting.
     *
     * @return array{0: bool, 1: ?string} [ticked, error]
     */
    private static function annexureLineInput(array &$post, mixed $current): array
    {
        $perAnnexure = array_key_exists('as_per_annexure_field', $post) || array_key_exists('as_per_annexure', $post)
            ? !empty($post['as_per_annexure'])
            : !empty($current);
        if (!$perAnnexure) {
            return [false, null];
        }
        if (!empty($post['quantity_is_tbc'])) {
            return [true, 'tick either "Qty To Be Confirmed" or "As per annexure", not both.'];
        }
        if (trim((string) ($post['quantity'] ?? '')) === '') {
            return [true, 'a line "As per annexure" still needs its quantity — enter the Qty.'];
        }
        foreach (array_keys(\App\Services\LineUnits::SIZE_FIELDS) as $f) {
            $post[$f] = '';
        }
        $post['dimensions'] = '';
        return [true, null];
    }

    /**
     * The refusal shown when a line cannot be saved: "Product line: …", except the D-338 (1c)
     * "Line N says "As per annexure" …" wording, which already names the line and is shown
     * exactly as it is (the same text as on the Quotation / PI gate).
     */
    private static function lineRefusalText(string $refusal): string
    {
        // D-348: "Line N: choose the Thickness …" already names its line.
        return preg_match('/^Line \d+(?: says "As per annexure"|: choose the )/u', $refusal) === 1 ? $refusal : 'Product line: ' . $refusal;
    }

    /** D-270: "180 × 80 × 13 cm" from the piece size, or null when none was given. */
    /**
     * Owner decision D-342 (12 Nov 2026, confirmed twice): once the order's first Quotation is
     * approved its lines change only through a "Products & prices" amendment — refused here for
     * everyone (no override, not the Super Admin). True when refused.
     */
    private static function refuseLockedLines(array $order): bool
    {
        $refusal = \App\Services\PriceLock::refusal($order);
        if ($refusal === null) {
            return false;
        }
        Flash::set('error', $refusal);
        header('Location: /orders/' . (int) $order['id'] . '#step-products');
        return true;
    }

    private static function dimensionsFromSize(array $size): ?string
    {
        $text = \App\Services\LineUnits::dimensionsText(\App\Services\LineUnits::size($size['piece_length_cm'] ?? null),
            \App\Services\LineUnits::size($size['piece_width_cm'] ?? null), \App\Services\LineUnits::size($size['piece_thickness_cm'] ?? null));
        return $text !== '' ? $text : null;
    }

    private static function lineSavedMessage(int $lineId, string $saved): string
    {
        $line = OrderProductRepository::find($lineId);
        if ($line === null || empty($line['price_approval_requested_at'])) {
            return $saved;
        }
        $check = \App\Services\OrderLinePricing::checkLine($line, \App\Services\OrderLinePricing::context(OrderRepository::find((int) $line['order_id']) ?? []));
        if ($check['status'] === \App\Services\OrderLinePricing::CBM_CALCULATED) {
            // D-338 (2b): the price is fine, but its CBM price was only worked out from a thinner base.
            return $saved . ' Line ' . (int) $line['line_no'] . ' needs MD approval: ' . $check['cbm_reason'] . ' — it was sent to the MD for approval; the Quotation and Proforma Invoice wait for it.';
        }
        return $saved . ' Line ' . (int) $line['line_no'] . ' is below the lowest price for this order (or its product has no price) — it was sent to the MD for approval; the Proforma Invoice waits for it.';
    }

    public function addProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        if (self::refuseLockedLines($order)) {
            return;
        }
        // Owner decision D-255 (5 Nov 2026): a line may come from an associate's price request — it then
        // takes the request's product, options, price and unit (the price can still be changed).
        $__priceRequest = null;
        if (trim((string) ($_POST['price_request_number'] ?? '')) !== '') {
            [$__priceRequest, $__prError] = \App\Services\PriceRequestService::usableForOrder((string) $_POST['price_request_number'], $order);
            if ($__prError !== null) {
                Flash::set('error', 'Product line: ' . $__prError);
                header("Location: /orders/{$orderId}#step-products");
                return;
            }
            $__prProduct = \App\Repositories\ProductRepository::find((int) $__priceRequest['product_id']);
            $_POST['catalog_product_id'] = (string) $__priceRequest['product_id'];
            $_POST['spec_json'] = (string) $__priceRequest['selection'];
            $_POST['line_type'] = 'goods';
            if (trim((string) ($_POST['unit_price'] ?? '')) === '') {
                $_POST['unit_price'] = (string) $__priceRequest['quote'];
            }
            if (!empty($__priceRequest['unit'])) {
                $_POST['unit'] = (string) $__priceRequest['unit'];
            }
            if (trim((string) ($_POST['description'] ?? '')) === '' && $__prProduct !== null) {
                $_POST['description'] = $__prProduct['name'] . ($__priceRequest['selection_text'] !== 'Standard specification' ? ' — ' . $__priceRequest['selection_text'] : '');
            }
            if (trim((string) ($_POST['hs_code'] ?? '')) === '' && $__prProduct !== null) {
                $_POST['hs_code'] = (string) $__prProduct['hs_code'];
            }
        }
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($description === '') {
            Flash::set('error', 'Description is required.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // D-257 0B: a category other than Natural Stone fills its default HS code and unit, and allows only its units.
        if (($__unitError = \App\Repositories\ProductCategoryRepository::applyLineDefaults($_POST)) !== null) {
            Flash::set('error', 'Product line: ' . $__unitError);
            header("Location: /orders/{$orderId}#step-products");
            return;
        }
        [$lineType, $hsCode, $sacCode, $lineTypeError] = self::lineTypeInput();
        [$categoryId, $attributeValues, $categoryError] = self::categoryInput($lineType);
        if (($lineTypeError ?? $categoryError) !== null) {
            Flash::set('error', $lineTypeError ?? $categoryError);
            header("Location: /orders/{$orderId}");
            return;
        }

        // Owner decision D-336: "As per annexure" — never with "Qty To Be Confirmed"; the size is in the annexure.
        [$__perAnnexure, $__annexureError] = self::annexureLineInput($_POST, null);
        if ($__annexureError !== null) {
            Flash::set('error', 'Product line: ' . $__annexureError);
            header("Location: /orders/{$orderId}#step-products");
            return;
        }
        // Owner decision D-339: No. of pieces → the quantity is worked out on the server.
        [$__pieces, $__sizeNote, $__piecesError] = self::detailedLines($order) ? self::piecesInput($_POST, null, $__perAnnexure) : [null, null, null];
        if ($__piecesError !== null) {
            Flash::set('error', 'Product line: ' . $__piecesError);
            header("Location: /orders/{$orderId}#step-products");
            return;
        }

        // QA Round 6, R6-N14/N15: quantity, price and line value within what can be stored.
        if ($lineError = OrderLineValidator::checkLine((string) ($_POST['quantity'] ?? ''), !empty($_POST['quantity_is_tbc']), (string) ($_POST['unit_price'] ?? ''))) {
            Flash::set('error', 'Product line: ' . $lineError);
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision item 10a: above about 20 containers of its unit, only with "I confirm this quantity".
        if ($lineError = \App\Services\QuantityLimits::check((string) ($_POST['quantity'] ?? ''), (string) ($_POST['unit'] ?? ''), !empty($_POST['quantity_is_tbc']), !empty($_POST[\App\Services\QuantityLimits::TICK]))) {
            Flash::set('error', 'Product line: ' . $lineError);
            header("Location: /orders/{$orderId}");
            return;
        }

        // Owner decision D-245 P2: on a USD order goods lines come from Products; never below the product's minimum price.
        // D-255: with the chosen specification options. D-270 (point 25): in any unit, converted with the piece size.
        [$__size, $__sizeError] = self::lineSizeInput($_POST);
        [$__specSel, $__syncError] = $__sizeError === null
            ? \App\Services\OrderLinePricing::syncThickness(self::catalogProductInput($_POST['catalog_product_id'] ?? ''), \App\Services\OrderLinePricing::selectionInput($_POST['spec_json'] ?? ''), $__size['piece_thickness_cm'])
            : [null, null];
        $__refusal = $__sizeError ?? $__syncError ?? \App\Services\OrderLinePricing::pickRefusal($order['currency_code'] ?? null, self::catalogProductInput($_POST['catalog_product_id'] ?? ''), $lineType, $_POST['catalog_product_id'] ?? null)
            // D-348: Thickness and Size / format chosen (a line from a price request keeps the request's options).
            ?? ($__priceRequest !== null ? null : \App\Services\OrderLinePricing::requiredSpecsRefusal(OrderProductRepository::nextLineNo($orderId), self::catalogProductInput($_POST['catalog_product_id'] ?? ''), $lineType, $__specSel))
            ?? \App\Services\OrderLinePricing::saveRefusal($order, self::catalogProductInput($_POST['catalog_product_id'] ?? ''), $lineType, OrderLineValidator::priceInput($_POST['unit_price'] ?? null), self::seesPriceBuildup(),
                $__specSel, trim((string) ($_POST['unit'] ?? '')) ?: null, $__priceRequest, $__size,
                // D-338 (1c): a line "As per annexure" has no size — its own wording.
                $__perAnnexure ? OrderProductRepository::nextLineNo($orderId) : null);
        if ($__refusal !== null) {
            Flash::set('error', self::lineRefusalText($__refusal));
            header("Location: /orders/{$orderId}#step-products");
            return;
        }

        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $currentStageNumber = $order['current_stage_number'] !== null ? (int) $order['current_stage_number'] : null;
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!OrderEditGuard::allow($currentStageNumber, (bool) $order['is_locked'], (int) $user['id'], $roleId, $reason, 'order_products', $orderId, 'product_added')) {
            header("Location: /orders/{$orderId}");
            return;
        }

        $newLineId = OrderProductRepository::add(
            $orderId,
            OrderProductRepository::nextLineNo($orderId),
            $description,
            trim((string) ($_POST['dimensions'] ?? '')) ?: self::dimensionsFromSize($__size),
            trim((string) ($_POST['finish'] ?? '')) ?: null,
            trim((string) ($_POST['quantity'] ?? '')) ?: null,
            !empty($_POST['quantity_is_tbc']),
            trim((string) ($_POST['unit'] ?? '')) ?: null,
            OrderLineValidator::priceInput($_POST['unit_price'] ?? null),
            $hsCode
        );
        OrderProductRepository::setLineType($newLineId, $lineType, $sacCode);
        // Owner decision item 10a: a confirmed large quantity is audited.
        if (\App\Services\QuantityLimits::isLarge((string) ($_POST['quantity'] ?? ''), (string) ($_POST['unit'] ?? ''), !empty($_POST['quantity_is_tbc']))) {
            \App\Services\QuantityLimits::audit((int) $user['id'], 'order_products', $newLineId, $description, (string) $_POST['quantity'], (string) ($_POST['unit'] ?? ''));
        }
        \App\Repositories\ProductCategoryRepository::setLineCategory($newLineId, $categoryId, $attributeValues);
        // Owner decision 2 Nov 2026 (D-240): the product it was picked from; who set its price.
        OrderProductRepository::setCatalogProduct($newLineId, self::catalogProductInput($_POST['catalog_product_id'] ?? ''));
        if (trim((string) ($_POST['unit_price'] ?? '')) !== '') {
            OrderProductRepository::setPriceSetBy($newLineId, (int) $user['id']);
        }
        // D-255: the options chosen, and the price request the line came from (now "became an order").
        \App\Services\OrderLinePricing::saveSelection($newLineId, $__specSel, $__priceRequest !== null ? (int) $__priceRequest['id'] : null);
        OrderProductRepository::setPieceSize($newLineId, $__size);
        OrderProductRepository::setAsPerAnnexure($newLineId, $__perAnnexure);
        if (self::detailedLines($order)) {
            OrderProductRepository::setPieces($newLineId, $__pieces, $__sizeNote);
        }
        if ($__priceRequest !== null) {
            \App\Services\PriceRequestService::markOrdered((int) $__priceRequest['id'], $orderId);
        }
        // Owner decision D-245 P2: a line below its floor goes to the MD for approval.
        \App\Services\OrderLinePricing::afterSave($newLineId, null, (int) $user['id']);
        Flash::set('success', self::lineSavedMessage($newLineId, 'Product line added.'));
        header("Location: /orders/{$orderId}");
    }

    public function updateProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $productId = (int) $params['productId'];
        $order = OrderRepository::find($orderId);
        $product = OrderProductRepository::find($productId);
        if (!$order || !$product || (int) $product['order_id'] !== $orderId) {
            http_response_code(404);
            echo 'Product line not found.';
            return;
        }
        if (self::refuseLockedLines($order)) {
            return;
        }
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($description === '') {
            Flash::set('error', 'Description is required.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // D-257 0B: a category other than Natural Stone fills its default HS code and unit, and allows only its units.
        if (($__unitError = \App\Repositories\ProductCategoryRepository::applyLineDefaults($_POST)) !== null) {
            Flash::set('error', 'Product line: ' . $__unitError);
            header("Location: /orders/{$orderId}#step-products");
            return;
        }
        [$lineType, $hsCode, $sacCode, $lineTypeError] = self::lineTypeInput();
        [$categoryId, $attributeValues, $categoryError] = self::categoryInput($lineType, isset($product['category_id']) ? (int) $product['category_id'] : null);
        if (($lineTypeError ?? $categoryError) !== null) {
            Flash::set('error', $lineTypeError ?? $categoryError);
            header("Location: /orders/{$orderId}");
            return;
        }

        // Owner decision D-336: "As per annexure" — never with "Qty To Be Confirmed"; the size is in the annexure.
        [$__perAnnexure, $__annexureError] = self::annexureLineInput($_POST, $product['as_per_annexure'] ?? null);
        if ($__annexureError !== null) {
            Flash::set('error', 'Product line: ' . $__annexureError);
            header("Location: /orders/{$orderId}#step-products");
            return;
        }
        // Owner decision D-339: No. of pieces → the quantity is worked out on the server
        // (a form without the field keeps the line's pieces and size note).
        $__piecesForm = self::detailedLines($order) && array_key_exists('pieces_count', $_POST);
        [$__pieces, $__sizeNote, $__piecesError] = $__piecesForm ? self::piecesInput($_POST, null, $__perAnnexure) : [null, null, null];
        if ($__piecesError !== null) {
            Flash::set('error', 'Product line: ' . $__piecesError);
            header("Location: /orders/{$orderId}#step-products");
            return;
        }

        // QA Round 6, R6-N14/N15: quantity, price and line value within what can be stored.
        if ($lineError = OrderLineValidator::checkLine((string) ($_POST['quantity'] ?? ''), !empty($_POST['quantity_is_tbc']), (string) ($_POST['unit_price'] ?? ''))) {
            Flash::set('error', 'Product line: ' . $lineError);
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision item 10a: above about 20 containers of its unit, only with "I confirm this quantity".
        if ($lineError = \App\Services\QuantityLimits::check((string) ($_POST['quantity'] ?? ''), (string) ($_POST['unit'] ?? ''), !empty($_POST['quantity_is_tbc']), !empty($_POST[\App\Services\QuantityLimits::TICK]))) {
            Flash::set('error', 'Product line: ' . $lineError);
            header("Location: /orders/{$orderId}");
            return;
        }

        // Owner decision D-245 P2: never below the product's minimum price.
        $__catalogId = array_key_exists('catalog_product_id', $_POST) ? self::catalogProductInput($_POST['catalog_product_id']) : (isset($product['catalog_product_id']) ? (int) $product['catalog_product_id'] : null);
        $__newPriceIn = OrderLineValidator::priceInput($_POST['unit_price'] ?? null);
        $__priceUnchanged = $__newPriceIn !== null && $product['unit_price'] !== null && abs((float) $product['unit_price'] - (float) $__newPriceIn) < 0.005
            && (int) ($product['catalog_product_id'] ?? 0) === (int) ($__catalogId ?? 0);
        // D-255: the chosen options (a form without the field keeps the line's options).
        $__specSel = array_key_exists('spec_json', $_POST) ? \App\Services\OrderLinePricing::selectionInput($_POST['spec_json']) : \App\Services\OrderLinePricing::selectionInput((string) ($product['spec_selection'] ?? ''));
        // D-270 (point 25): the piece size (a form without the fields keeps the line's size); a typed thickness chooses the option.
        if (array_key_exists('piece_length_cm', $_POST) || array_key_exists('piece_width_cm', $_POST) || array_key_exists('piece_thickness_cm', $_POST)) {
            [$__size, $__sizeError] = self::lineSizeInput($_POST);
        } else {
            [$__size, $__sizeError] = [['piece_length_cm' => $product['piece_length_cm'] ?? null, 'piece_width_cm' => $product['piece_width_cm'] ?? null, 'piece_thickness_cm' => $product['piece_thickness_cm'] ?? null], null];
        }
        $__syncError = null;
        if ($__sizeError === null) {
            [$__specSel, $__syncError] = \App\Services\OrderLinePricing::syncThickness($__catalogId, $__specSel, $__size['piece_thickness_cm']);
        }
        $__specUnchanged = json_encode($__specSel) === json_encode(\App\Services\OrderLinePricing::selectionInput((string) ($product['spec_selection'] ?? '')));
        $__sizeUnchanged = \App\Services\OrderLinePricing::lineSize($__size) === \App\Services\OrderLinePricing::lineSize($product)
            && strtoupper(trim((string) ($_POST['unit'] ?? ''))) === strtoupper(trim((string) ($product['unit'] ?? '')));
        $__refusal = $__sizeError ?? $__syncError ?? \App\Services\OrderLinePricing::pickRefusal($order['currency_code'] ?? null, $__catalogId, $lineType, $_POST['catalog_product_id'] ?? null)
            // D-348: Thickness and Size / format chosen — a saved line whose product and options are unchanged keeps them.
            ?? ($__specUnchanged && (int) ($product['catalog_product_id'] ?? 0) === (int) ($__catalogId ?? 0) ? null
                : \App\Services\OrderLinePricing::requiredSpecsRefusal((int) $product['line_no'], $__catalogId, $lineType, $__specSel))
            ?? ($__priceUnchanged && $__specUnchanged && $__sizeUnchanged ? null : \App\Services\OrderLinePricing::saveRefusal($order, $__catalogId, $lineType, $__newPriceIn, self::seesPriceBuildup(),
                $__specSel, trim((string) ($_POST['unit'] ?? '')) ?: null, null, $__size,
                // D-338 (1c): a line "As per annexure" has no size — its own wording.
                $__perAnnexure ? (int) $product['line_no'] : null));
        if ($__refusal !== null) {
            Flash::set('error', self::lineRefusalText($__refusal));
            header("Location: /orders/{$orderId}#step-products");
            return;
        }

        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $currentStageNumber = $order['current_stage_number'] !== null ? (int) $order['current_stage_number'] : null;
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!OrderEditGuard::allow($currentStageNumber, (bool) $order['is_locked'], (int) $user['id'], $roleId, $reason, 'order_products', $productId, 'product_line')) {
            header("Location: /orders/{$orderId}");
            return;
        }

        OrderProductRepository::update(
            $productId,
            $description,
            trim((string) ($_POST['dimensions'] ?? '')) ?: self::dimensionsFromSize($__size),
            trim((string) ($_POST['finish'] ?? '')) ?: null,
            trim((string) ($_POST['quantity'] ?? '')) ?: null,
            !empty($_POST['quantity_is_tbc']),
            trim((string) ($_POST['unit'] ?? '')) ?: null,
            OrderLineValidator::priceInput($_POST['unit_price'] ?? null),
            $hsCode
        );
        OrderProductRepository::setLineType($productId, $lineType, $sacCode);
        // Owner decision item 10a: a confirmed large quantity is audited when the quantity or unit changed.
        $__qtyIn = trim((string) ($_POST['quantity'] ?? ''));
        $__unitIn = trim((string) ($_POST['unit'] ?? ''));
        if (\App\Services\QuantityLimits::isLarge($__qtyIn, $__unitIn, !empty($_POST['quantity_is_tbc']))
            && (abs((float) ($product['quantity'] ?? 0) - (float) $__qtyIn) > 0.0005 || strcasecmp((string) ($product['unit'] ?? ''), $__unitIn) !== 0 || !empty($product['quantity_is_tbc']))) {
            \App\Services\QuantityLimits::audit((int) $user['id'], 'order_products', $productId, $description, $__qtyIn, $__unitIn);
        }
        \App\Repositories\ProductCategoryRepository::setLineCategory($productId, $categoryId, $attributeValues);
        // Owner decision 2 Nov 2026 (D-240): the product it was picked from; who set its price.
        if (array_key_exists('catalog_product_id', $_POST)) {
            OrderProductRepository::setCatalogProduct($productId, self::catalogProductInput($_POST['catalog_product_id']));
        }
        if (!$__specUnchanged || (int) ($product['catalog_product_id'] ?? 0) !== (int) ($__catalogId ?? 0)) {
            // A changed product or options: the line no longer follows its price request.
            \App\Services\OrderLinePricing::saveSelection($productId, $__specSel, null);
        }
        // D-357: a picked line's finish is its chosen Surface finish option (the "More › finish" box is hidden for it).
        \App\Services\SurfaceFinishList::applyToLine($productId);
        OrderProductRepository::setPieceSize($productId, $__size);
        OrderProductRepository::setAsPerAnnexure($productId, $__perAnnexure);
        if ($__piecesForm) {
            OrderProductRepository::setPieces($productId, $__pieces, $__sizeNote);
        }
        $__newPrice = trim((string) ($_POST['unit_price'] ?? ''));
        if ($__newPrice !== '' && ($product['unit_price'] === null || abs((float) $product['unit_price'] - (float) $__newPrice) > 0.0001)) {
            OrderProductRepository::setPriceSetBy($productId, (int) $user['id']);
        }
        // Owner decision D-245 P2: a changed price voids its approval; below the floor goes to the MD.
        \App\Services\OrderLinePricing::afterSave($productId, $product, (int) $user['id']);
        Flash::set('success', self::lineSavedMessage($productId, 'Product line updated.'));
        header("Location: /orders/{$orderId}");
    }

    public function deleteProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $productId = (int) $params['productId'];
        $order = OrderRepository::find($orderId);
        $product = OrderProductRepository::find($productId);
        if (!$order || !$product || (int) $product['order_id'] !== $orderId) {
            http_response_code(404);
            echo 'Product line not found.';
            return;
        }
        if (self::refuseLockedLines($order)) {
            return;
        }

        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $currentStageNumber = $order['current_stage_number'] !== null ? (int) $order['current_stage_number'] : null;
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!OrderEditGuard::allow($currentStageNumber, (bool) $order['is_locked'], (int) $user['id'], $roleId, $reason, 'order_products', $productId, 'product_removed')) {
            header("Location: /orders/{$orderId}");
            return;
        }

        OrderProductRepository::softDelete($productId);
        Flash::set('success', 'Product line removed.');
        header("Location: /orders/{$orderId}");
    }

    /** Clones a product line — the common case is "same product, just the name or dimension changed". */
    public function duplicateProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $productId = (int) $params['productId'];
        $order = OrderRepository::find($orderId);
        $product = OrderProductRepository::find($productId);
        if (!$order || !$product || (int) $product['order_id'] !== $orderId) {
            http_response_code(404);
            echo 'Product line not found.';
            return;
        }
        if (self::refuseLockedLines($order)) {
            return;
        }

        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $currentStageNumber = $order['current_stage_number'] !== null ? (int) $order['current_stage_number'] : null;
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!OrderEditGuard::allow($currentStageNumber, (bool) $order['is_locked'], (int) $user['id'], $roleId, $reason, 'order_products', $productId, 'product_duplicated')) {
            header("Location: /orders/{$orderId}");
            return;
        }

        // Owner decision D-271/D-272 (points 13 and 24): an exact copy right below; its price is checked at once.
        $__copyId = OrderProductRepository::duplicate($productId);
        \App\Services\OrderLinePricing::afterSave($__copyId, null, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ORDER_LINE_DUPLICATED', 'order_products', $__copyId, 'copied_from_id', null, (string) $productId);
        $__copyNo = (int) $product['line_no'] + 1;
        Flash::set('success', self::lineSavedMessage($__copyId, "Line {$__copyNo} is an exact copy of line {$product['line_no']} — change what differs (e.g. the size). The MD's price approval is not copied: the copy's price was checked again."));
        header("Location: /orders/{$orderId}?copied={$__copyId}#line-{$__copyId}");
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

        // Archiving only removes an order from the default listing, and a
        // guessed/bookmarked URL must not bypass the order-assignment
        // scoping the Orders list and Kanban board apply — both checked in
        // OrderAccessService, shared with the file download route.
        // Visibility only: never decides who may act on a step.
        $accessDenied = \App\Services\OrderAccessService::denialReason($order, (int) $actor['id'], $actorRoleId);
        if ($accessDenied !== null) {
            http_response_code(403);
            echo $accessDenied;
            return;
        }
        // Owner decision D-257 Phase 2: a Domestic Trade order has its own page and stages.
        if (\App\Services\DomesticOrderService::isDomesticOrder($order) && \App\Repositories\DomesticOrderRepository::find((int) $order['id']) !== null) {
            \App\Services\DivisionService::enterOrderDivision((int) $order['division_id']);
            DomesticOrderController::show($order);
            return;
        }
        $seesAllOrders = PermissionService::can((int) $actor['id'], $actorRoleId, 'view_all_orders');
        $canAssignOwner = PermissionService::can((int) $actor['id'], $actorRoleId, 'assign_order_owner');

        // CA / Accounting module (Phase 1) — INR-actual settlement widgets
        // on this page are shown/hidden per-permission rather than gated at
        // the route level, since they're a small part of a page most staff
        // already have full access to.
        $canViewInrActual = PermissionService::can((int) $actor['id'], $actorRoleId, 'inr_actual_view');
        $canEditInrActual = PermissionService::can((int) $actor['id'], $actorRoleId, 'inr_actual_edit');
        $canDeleteInrActual = PermissionService::can((int) $actor['id'], $actorRoleId, 'inr_actual_delete');

        // Point 2 follow-up — a RODTEP/export-benefit claim or an expense
        // (ECGC insurance, third-party inspection, ...) can be linked to
        // the specific order it relates to; this surfaces both here so
        // the order page shows every financial detail tied to it at a
        // glance, not just in the separate CA module list screens.
        $canViewCaLinks = PermissionService::can((int) $actor['id'], $actorRoleId, 'ca_module_view');
        $canManageCaInternalDoc = PermissionService::can((int) $actor['id'], $actorRoleId, 'ca_internal_doc_manage');
        // Owner decision 2026-10-29: the per-order "App vs Zoho" check, for whoever runs the Zoho sync.
        $canCheckZoho = PermissionService::can((int) $actor['id'], $actorRoleId, 'ca_module_manage');

        // Reorder-to-supplier linking + Order Profitability Sheet (docs/schema.sql
        // Section AP) — gated on the new, deliberately stricter
        // manage_order_financials permission rather than ca_module_view,
        // per the user's own ask that this specific section stay
        // Super-Admin-plus-explicitly-granted-roles only.
        $canManageOrderFinancials = PermissionService::can((int) $actor['id'], $actorRoleId, 'manage_order_financials');

        $stages = OrderStageRepository::forOrder($orderId);
        $stageByNumber = [];
        foreach ($stages as $s) {
            $stageByNumber[(int) $s['stage_number']] = $s;
        }

        // Owner decision (2026-10-28): CAFIN is only listed for people
        // holding view_cafin — everyone else never sees that one exists.
        $canViewCafin = PermissionService::can((int) $actor['id'], $actorRoleId, 'view_cafin');
        $documents = DocumentRepository::forOrder($orderId);
        if (!$canViewCafin) {
            $documents = array_values(array_filter($documents, static fn (array $d): bool => $d['document_type_code'] !== 'CAFIN'));
        }
        // Owner request (1 Nov 2026): the "All Documents" panel also lists every file uploaded for the
        // order (client, staff, associate) — only those this person may download (same gates as
        // FileStoreController::download, so no link answers 403).
        // Owner request (2 Nov 2026): the document lists show only what this person may open —
        // a document whose money they cannot see (e.g. the Supplier PO for Sales, the BL
        // Instruction for Procurement) is left out entirely, not listed without a link.
        $visibleDocuments = array_values(array_filter($documents,
            static fn (array $d): bool => \App\Services\MoneyVisibility::canSeeDocument((string) $d['document_type_code'])));
        $canManageIncentivesForFiles = PermissionService::can((int) $actor['id'], $actorRoleId, 'manage_incentives');
        $uploadedFiles = array_values(array_filter(FileStoreRepository::uploadedForOrder($orderId),
            static fn (array $f): bool => \App\Services\MoneyVisibility::canSeeFile((int) $f['id'])
                && ($canManageIncentivesForFiles || !\App\Repositories\AssociateRepository::isAgreementFile((int) $f['id']))));
        // QA Round 8, R8-N06: the reviewer list offers a document's own generator only when the
        // self-approval rule 'document_review' allows them (Admin → Self-approval rules, D-221).
        $__selfReviewOk = [];
        foreach ($documents as $__i => $__d) {
            $__gen = (int) ($__d['generated_by'] ?? 0);
            if ($__gen > 0 && !isset($__selfReviewOk[$__gen])) {
                $__selfReviewOk[$__gen] = \App\Services\SelfApprovalRules::allowed('document_review', $__gen);
            }
            $documents[$__i]['generator_may_self_review'] = $__gen > 0 && $__selfReviewOk[$__gen];
        }
        $reviewsByDocument = [];
        $crossVerificationsByDocument = [];
        foreach ($documents as $d) {
            $reviewsByDocument[(int) $d['id']] = DocumentReviewRepository::forDocument((int) $d['id']);
            $crossVerificationsByDocument[(int) $d['id']] = DocumentCrossVerificationRepository::forDocument((int) $d['id']);
        }

        // External QA report M-08: "Regenerating an earlier-stage document
        // after later stages passed: mandatory confirmation ... App only
        // warns." Precomputed once per already-generated type (clicking
        // its Generate button would be a regeneration) so the page's own
        // JS can show a specific, named confirm() dialog before the POST
        // even goes out, instead of only warning after the fact.
        $regenerationRisk = [];
        foreach (array_unique(array_column($documents, 'document_type_code')) as $existingCode) {
            $downstream = DocumentGenerationService::downstreamDocumentsAtRisk($orderId, $existingCode);
            if (!empty($downstream)) {
                $regenerationRisk[$existingCode] = $downstream;
            }
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

        // docs/schema.sql Section BT — export process step 8a: whether this
        // order even has a 3rd-party inspection to track a fee for reuses
        // the existing Compliance Checklist's own "Pre-Shipment Inspection"
        // row rather than a second, duplicate flag — not yet marked
        // 'skipped' (not applicable) means it's still relevant.
        $complianceTasksForOrder = OrderComplianceTaskRepository::forOrder($orderId);
        $pendingInspectionTask = false;
        foreach ($complianceTasksForOrder as $task) {
            if (\App\Services\ShipmentGateService::isInspectionTask($task) && $task['status'] !== 'skipped') {
                $pendingInspectionTask = true;
                break;
            }
        }
        $exportBenefitSchemesForOrder = OrderExportBenefitSchemeRepository::forOrder($orderId);

        $duplicatedFromOrder = !empty($order['duplicated_from_order_id'])
            ? OrderRepository::find((int) $order['duplicated_from_order_id'])
            : null;
        $duplicatedIntoOrders = OrderRepository::findOrdersDuplicatedFrom($orderId);

        View::render('orders/show', [
            'order'    => $order,
            'canAssignOwner' => $canAssignOwner,
            'seesAllOrders' => $seesAllOrders,
            'assignees' => OrderAssigneeRepository::forOrder($orderId),
            'leadTimeDisplayUnit' => CompanySettingsRepository::get('lead_time_display_unit') ?: 'days',
            'products' => OrderProductRepository::forOrder($orderId),
            'hsCodes'  => HsCodeRepository::active(),
            'stages'   => $stages,
            'stageByNumber' => $stageByNumber,
            'payment'  => OrderPaymentStatusRepository::find($orderId),
            'documents' => $documents,
            'uploadedFiles' => $uploadedFiles,
            'visibleDocuments' => $visibleDocuments,
            'regenerationRisk' => $regenerationRisk,
            'reviewsByDocument' => $reviewsByDocument,
            'crossVerificationsByDocument' => $crossVerificationsByDocument,
            'emailLogByDocument' => $emailLogByDocument,
            'activeUsers' => UserRepository::listActive(),
            'fobTotal' => OrderProductRepository::totalFobValue($orderId),
            'suppliers' => SupplierRepository::all(),
            // D-361 (decision 2, mockups p8 / decision_d2): every supplier's offer for the order's product lines, for the Supplier PO terms form.
            'spoOffers' => \App\Services\SupplierOffers::forOrderPo($orderId),
            'logisticsPartners' => \App\Repositories\LogisticsPartnerRepository::all(),
            'supplierPo' => $supplierPo,
            // Owner request (2026-10-27): who to pay in 7e / 10a.
            'supplierBank' => $supplierPo && !empty($supplierPo['supplier_id']) ? \App\Repositories\VendorDetailsRepository::bankDetails(\App\Repositories\VendorDetailsRepository::find('supplier', (int) $supplierPo['supplier_id'])) : null,
            // Owner decisions (2026-10-27): bill first, then pay — the supplier's
            // bills (7e / 10a) and the logistics partners' bills (Group C), and
            // the order's actual expenses with receipts (12a).
            'vendorBills' => $vendorBills = \App\Repositories\OrderVendorBillRepository::forOrder($orderId),
            'logisticsBanks' => array_map(
                static fn (int $partnerId): array => \App\Repositories\VendorDetailsRepository::bankDetails(\App\Repositories\VendorDetailsRepository::find('logistics_partner', $partnerId)),
                array_combine(
                    $__partnerIds = array_values(array_unique(array_map('intval', array_filter(array_column($vendorBills, 'logistics_partner_id'))))),
                    $__partnerIds
                ) ?: []
            ),
            'logisticsPaymentBlocked' => \App\Controllers\VendorBillController::logisticsPaymentBlockedReason($order),
            'orderExpenses' => $canManageOrderFinancials ? \App\Repositories\OrderExpenseRepository::forOrder($orderId) : [],
            'expenseSummary' => $canManageOrderFinancials ? \App\Repositories\OrderExpenseRepository::summary($orderId) : null,
            'buyerPoDocuments' => OrderBuyerPoDocumentRepository::forOrder($orderId),
            'supplierPoDocuments' => $supplierPo ? OrderSupplierPoDocumentRepository::forSupplierPo((int) $supplierPo['id']) : [],
            'freight'   => OrderFreightRepository::find($orderId),
            'packing'   => OrderPackingRepository::find($orderId),
            'crates'    => OrderCrateRepository::forOrder($orderId),
            'shipping'  => OrderShippingRepository::find($orderId),
            'draftBlReviews' => OrderDraftBlReviewRepository::forOrder($orderId),
            'production' => OrderProductionRepository::find($orderId),
            'units' => LookupRepository::dropdownOptions('unit'),
            'surfaceFinishes' => LookupRepository::dropdownOptions('surface_finish'),
            // Owner decision D-245 P2: "Pick from Products" fills the quote price; each line's price check and the MD's approval.
            'pickerProducts' => \App\Repositories\ProductRepository::forPicker(),
            'pickerBlocked' => \App\Repositories\ProductRepository::notQuotable(), // D-361
            'pickerPrices' => MoneyVisibility::can(MoneyVisibility::SELLING) ? \App\Services\OrderLinePricing::pickerPrices(\App\Repositories\ProductRepository::forPicker()) : [],
            'pickerSpecs' => \App\Services\OrderLinePricing::pickerSpecs(\App\Repositories\ProductRepository::forPicker()),
            'linePricing' => \App\Services\OrderLinePricing::forOrder($orderId),
            'pickRequiredWhen' => \App\Services\OrderLinePricing::productPickRequired($order['currency_code'] ?? null) ? 'line_type=goods' : '',
            'canManagePricing' => PermissionService::can((int) $actor['id'], $actorRoleId, 'manage_pricing'),
            'seesPriceBuildup' => PermissionService::can((int) $actor['id'], $actorRoleId, 'view_price_buildup'),
            'gstRates' => LookupRepository::dropdownOptions('gst_rate'),
            'dimensionalTolerances' => LookupRepository::dropdownOptions('dimensional_tolerance'),
            'supplierFactoryAddressesBySupplier' => SupplierFactoryAddressRepository::allGroupedBySupplier(),
            'amendmentCount' => count(AmendmentRepository::forOrder($orderId)),
            'complianceTasks' => $complianceTasksForOrder,
            'exportBenefitEligibility' => $exportBenefitSchemesForOrder,
            'benefitSettlement' => \App\Services\BenefitSettlementService::state($orderId),
            'pendingInspectionTask' => $pendingInspectionTask,
            // Gap list G-1 / G-3 (owner, 1 Nov 2026): what the Bill of Lading waits for, and who may release it.
            'shipmentGateBlockers' => \App\Services\ShipmentGateService::blockers($orderId),
            'shipmentGateOverrides' => \App\Services\ShipmentGateService::overrides($orderId),
            'canOverrideShipmentGates' => PermissionService::can((int) $actor['id'], $actor['role_id'] !== null ? (int) $actor['role_id'] : null, 'override_shipment_gates'),
            'inspection' => \App\Services\ShipmentGateService::inspection($orderId),
            'inspectionApplies' => \App\Services\ShipmentGateService::inspectionApplies($orderId),
            'preShipmentBalance' => \App\Services\ShipmentGateService::isPreShipmentBalance($order),
            'balanceOpenEarly' => \App\Services\ShipmentGateService::balanceOpenEarly($orderId),
            'orderInspectionFee' => $canManageOrderFinancials ? OrderInspectionFeeRepository::find($orderId) : null,
            'openDisputeCount' => count(array_filter(DisputeRepository::forOrder($orderId), static fn(array $d): bool => $d['status'] !== 'Resolved')),
            'openComplaintCount' => count(array_filter(\App\Repositories\OrderComplaintRepository::forOrder($orderId), static fn(array $c): bool => $c['status'] !== 'resolved')),
            'piIntake' => PiIntakeRepository::latestForOrder($orderId),
            'clientPaymentReports' => ClientPaymentReportRepository::forOrder($orderId),
            'ocAcknowledgment' => OrderOcAcknowledgmentRepository::find($orderId),
            'comments' => OrderCommentRepository::forOrder($orderId),
            'canViewInrActual' => $canViewInrActual,
            'canEditInrActual' => $canEditInrActual,
            'canDeleteInrActual' => $canDeleteInrActual,
            'canViewCaLinks' => $canViewCaLinks,
            'canCheckZoho' => $canCheckZoho,
            // Owner decision 2 Nov 2026 (D-233): "Waiting for Zoho: N" for whoever may see Zoho status.
            'zohoWaiting' => ($canCheckZoho || $canViewCaLinks) ? \App\Services\ZohoPendingService::counts($orderId) : null,
            'linkedExportBenefits' => $canViewCaLinks ? CaExportBenefitRepository::forOrder($orderId) : [],
            // Owner request (2026-10-26): Step 12c lists this order's claims for manage_order_financials holders.
            'orderBenefitClaims' => $canManageOrderFinancials ? CaExportBenefitRepository::forOrder($orderId) : [],
            'linkedCaExpenses' => $canViewCaLinks ? CaExpenseRepository::forOrderCounted($orderId) : [],
            'canManageCaInternalDoc' => $canManageCaInternalDoc,
            'canViewCafin' => $canViewCafin,
            'paymentTermsApproval' => \App\Services\PaymentTermsApprovalService::state($orderId),
            'canApprovePaymentTerms' => PermissionService::can((int) $actor['id'], $actorRoleId, 'approve_payment_terms'),
            // D-269: who can approve, named from the permission, not "MD/ED".
            'paymentTermsApprovers' => \App\Services\PaymentTermsApprovalService::whoCanApprove(),
            'caInternalDoc' => $canViewCafin ? DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'CAFIN') : null,
            'canManageOrderFinancials' => $canManageOrderFinancials,
            'orderCostEntries' => $canManageOrderFinancials ? OrderCostEntryRepository::forOrder($orderId) : [],
            'costEntryCategories' => OrderCostEntryRepository::CATEGORIES,
            // Always fetched (not gated on canManageOrderFinancials): Step
            // 5's LC leg (payment_method = 'LC') needs this for whoever can
            // manage/clear payments, a different permission tier than Order
            // Financials — display of the financials-specific fields below
            // stays gated by $canManageOrderFinancials in the view itself.
            'orderLcDetails' => OrderLcDetailRepository::find($orderId),
            // Gap list G-4 (owner, 1 Nov 2026): the LC's amendments.
            'orderLcAmendments' => \App\Repositories\OrderLcAmendmentRepository::forOrder($orderId),
            'orderTravelExpenses' => $canManageOrderFinancials ? \App\Repositories\OrderTravelExpenseRepository::find($orderId) : null,
            'profitability' => $canManageOrderFinancials ? OrderProfitabilityService::computeForOrder($orderId) : null,
            // Owner decision 2026-10-31 (GST under LUT): shown for information only — the cost keeps its GST.
            'orderPurchaseGst' => $canManageOrderFinancials ? \App\Repositories\GstRefundRepository::gstOnOrderBills($orderId) : null,
            'exportBenefitSchemes' => LookupRepository::dropdownOptions('export_benefit_scheme'),
            'duplicatedFromOrder' => $duplicatedFromOrder,
            'duplicatedIntoOrders' => $duplicatedIntoOrders,
            'additionalDocuments' => FileStoreRepository::additionalDocumentsForOrder($orderId),
            'blEndorsement' => OrderBlEndorsementRepository::find($orderId),
            'blEndorsementDoc' => DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'BLE'),
            'containerTypes' => LookupRepository::dropdownOptions('container_type'),
            // docs/schema.sql Section BV — rating/comments aren't financial
            // data, so this isn't gated behind manage_order_financials.
            'orderFeedback' => \App\Repositories\OrderFeedbackRepository::find($orderId),
            'testimonialSuggestion' => \App\Services\TestimonialSuggestionService::forOrder($orderId),
        ], 'layout/base');
    }

    /**
     * Order ownership / assignment visibility — adds a staff member to an
     * order's assignee list. Route-gated to assign_order_owner (Admin/MD/ED
     * only); this method doesn't re-check it. Visibility-only: never
     * touches stage-gate or action-permission checks elsewhere on this
     * order.
     */
    public function addAssignee(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            Flash::set('error', 'Order not found.');
            header('Location: /orders');
            return;
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $user = $userId > 0 ? UserRepository::findById($userId) : null;
        if (!$user || !$user['is_active']) {
            Flash::set('error', 'Select a valid, active user to assign this order to.');
            header("Location: /orders/{$orderId}");
            return;
        }

        try {
            OrderAssignmentService::addAssignee($orderId, $userId, (int) AuthService::currentUser()['id']);
            Flash::set('success', "{$user['name']} added as an assignee on this order.");
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
        }
        header("Location: /orders/{$orderId}");
    }

    /**
     * Order ownership / assignment visibility — removes a staff member
     * from an order's assignee list. Route-gated to assign_order_owner
     * (Admin/MD/ED only); this method doesn't re-check it.
     */
    public function removeAssignee(array $params): void
    {
        $orderId = (int) $params['id'];
        $userId = (int) $params['userId'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            Flash::set('error', 'Order not found.');
            header('Location: /orders');
            return;
        }

        try {
            OrderAssignmentService::removeAssignee($orderId, $userId, (int) AuthService::currentUser()['id']);
            Flash::set('success', 'Assignee removed from this order.');
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
        }
        header("Location: /orders/{$orderId}");
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
    /**
     * Owner request (3 Nov 2026): after any link is generated the page returns to that
     * card, opened, with the new link focused. ?open= forces a fresh page load (a bare
     * #hash on the same URL would not reload); the hash opens and scrolls to the card.
     */
    public static function linkReturnUrl(int $orderId, string $anchor): string
    {
        return "/orders/{$orderId}?open=" . rawurlencode($anchor) . '#' . $anchor;
    }

    /** The card a link form came from (its return_to), or the given default. */
    private static function linkReturnAnchor(string $default): string
    {
        $returnTo = (string) ($_POST['return_to'] ?? '');
        return preg_match('/^step-[a-z0-9-]+$/', $returnTo) ? $returnTo : $default;
    }

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

        // QA batch 3 MAIL-4: fall back to the client record's e-mail; MAIL-2: report what really happened.
        $clientEmail = \App\Services\EmailDispatchService::clientEmailFor($order);
        $sent = false;
        if ($clientEmail !== null) {
            // 90-item backlog #37: admin-editable (Admin > Settings >
            // Email Templates) — this exact original wording is the
            // fallback.
            $rendered = EmailTemplateRepository::renderOrDefault(
                'pi_form_link',
                ['{order_reference}' => $order['order_reference'], '{pi_form_link}' => $link],
                'NexaCrest — please confirm your details for the proforma invoice',
                "Hello,\n\n"
                    . "Thank you for accepting our Quotation for order {order_reference}.\n\n"
                    . "Before we can send your proforma invoice, please confirm your shipping details and who receives the goods:\n{pi_form_link}\n\n"
                    . "This link works for 30 days.\n\n"
                    . "NexaCrest International Private Limited"
            );
            $sent = EmailService::sendPlainText($clientEmail, $rendered['subject'], $rendered['body'], false, 'buyer_links', 'buyer');
        }

        AuditLogRepository::log((int) $user['id'], 'PI_INTAKE_LINK_GENERATED', 'orders', $orderId, null, null, null, 'PI-stage intake link generated' . ($sent ? ' and emailed to client' : ($clientEmail !== null ? ' — the e-mail was not sent, hand this link over directly' : ' — no client email on file, hand this link over directly')));
        Flash::set('success', \App\Services\EmailDispatchService::linkMailOutcome('PI-stage form link', $clientEmail, $sent, $link), $link);
        header('Location: ' . self::linkReturnUrl($orderId, 'step-5a-pi-form-link'));
    }

    /**
     * Owner decision (2026-10-26): Step 5c (Generate PI) stays locked until
     * the client's PI-stage form is applied (5b) — so when a client won't
     * use the form, staff record that here (reason required, audit-logged),
     * which completes 5a/5b and unlocks 5c.
     */
    public function skipPiForm(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!OrderRepository::find($orderId)) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $reason = trim((string) ($_POST['skip_reason'] ?? ''));
        if (($error = ReasonValidator::check($reason)) !== null) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}#step-5b-pi-intake-apply");
            return;
        }
        $userId = (int) AuthService::currentUserId();
        OrderRepository::markPiFormSkipped($orderId, $userId, $reason);
        AuditLogRepository::log($userId, 'PI_FORM_SKIPPED', 'orders', $orderId, 'pi_form_skipped_at', null, date('Y-m-d H:i:s'), $reason);
        Flash::set('success', "PI-stage form skipped — Step 5c (Generate Proforma Invoice) is now open.");
        header("Location: /orders/{$orderId}");
    }

    /**
     * 90-item backlog #75: generates (or regenerates) the public, no-login
     * Buyer Action link — lets the buyer acknowledge their Order
     * Confirmation and upload their signed Buyer PO copy without logging
     * into the client portal. Same staff-initiated, flash-back-the-link
     * pattern as generatePiFormLink() above; see docs/schema.sql Section
     * BG and BuyerActionController's docblock.
     */
    public function generateBuyerActionLink(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $user = AuthService::currentUser();
        $rawToken = OrderRepository::generateBuyerActionToken($orderId);
        $returnAnchor = self::linkReturnAnchor('step-4b-buyer-po-link');
        // Owner request (3 Nov 2026): the OC confirmation link is made only by the Step 6b button.
        if ($returnAnchor === 'step-6b-buyer-action-link') {
            OrderRepository::markOcLinkGenerated($orderId);
        }
        $link = rtrim(Env::get('APP_URL', ''), '/') . '/buyer-action/' . $rawToken;

        // QA batch 3 MAIL-4: fall back to the client record's e-mail; MAIL-2: report what really happened.
        $clientEmail = \App\Services\EmailDispatchService::clientEmailFor($order);
        $sent = false;
        if ($clientEmail !== null) {
            // 90-item backlog #37: admin-editable (Admin > Settings >
            // Email Templates) — this exact original wording is the
            // fallback.
            $rendered = EmailTemplateRepository::renderOrDefault(
                'buyer_action_link',
                ['{order_reference}' => $order['order_reference'], '{buyer_action_link}' => $link],
                'NexaCrest — sign your purchase order and confirm your order',
                "Hello,\n\n"
                    . "For order {order_reference}, please use this link to:\n1. download your purchase order,\n2. upload the signed copy with your PO number,\n3. confirm your order when you receive our order confirmation.\n\n{buyer_action_link}\n\n"
                    . "This link works for 90 days.\n\n"
                    . "NexaCrest International Private Limited"
            );
            $sent = EmailService::sendPlainText($clientEmail, $rendered['subject'], $rendered['body'], false, 'buyer_links', 'buyer');
        }

        AuditLogRepository::log((int) $user['id'], 'BUYER_ACTION_LINK_GENERATED', 'orders', $orderId, null, null, null, 'Buyer Action link generated' . ($sent ? ' and emailed to client' : ($clientEmail !== null ? ' — the e-mail was not sent, hand this link over directly' : ' — no client email on file, hand this link over directly')));
        Flash::set('success', \App\Services\EmailDispatchService::linkMailOutcome('Buyer Action link', $clientEmail, $sent, $link), $link);
        // Owner request (2026-10-26): sent from Step 4b or 6b — return to that card (3 Nov: opened, link focused).
        header('Location: ' . self::linkReturnUrl($orderId, $returnAnchor));
    }

    /**
     * Owner request (2026-10-27): the Payment Action Link — the client sends
     * their payment details (advance / freight / balance) there. Generated
     * from 5d, 9c or 11c like the Buyer Action Link from 4b; the same link
     * also goes out automatically inside the PI, Debit Note and CI emails.
     */
    public function generatePaymentActionLink(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $user = AuthService::currentUser();
        $link = rtrim(Env::get('APP_URL', ''), '/') . '/payment-action/' . OrderRepository::generatePaymentActionToken($orderId);
        $clientEmail = \App\Services\EmailDispatchService::clientEmailFor($order);
        $sent = false;
        if ($clientEmail !== null) {
            $rendered = EmailTemplateRepository::renderOrDefault(
                'payment_action_link',
                ['{order_reference}' => $order['order_reference'], '{payment_action_link}' => $link],
                'NexaCrest — send us your payment details for order {order_reference}',
                "Hello,\n\n"
                    . "For order {order_reference}: once you have made the payment, please send us a screenshot of the payment and its details (bank reference number, amount and date) on this secure link. You do not need to sign in:\n{payment_action_link}\n\n"
                    . "Our team will check them against our bank statement and confirm. The same link works for every payment on this order.\n\n"
                    . "NexaCrest International Private Limited"
            );
            $sent = EmailService::sendPlainText($clientEmail, $rendered['subject'], $rendered['body'], false, 'buyer_links', 'buyer');
        }

        AuditLogRepository::log((int) $user['id'], 'PAYMENT_ACTION_LINK_GENERATED', 'orders', $orderId, null, null, null, 'Payment Action link generated' . ($sent ? ' and emailed to client' : ($clientEmail !== null ? ' — the e-mail was not sent, hand this link over directly' : ' — no client email on file, hand this link over directly')));
        Flash::set('success', \App\Services\EmailDispatchService::linkMailOutcome('Payment Action link', $clientEmail, $sent, $link), $link);
        header('Location: ' . self::linkReturnUrl($orderId, self::linkReturnAnchor('step-5d-payment-record')));
    }

    /**
     * Real gap this closes: no way existed to hand over (or archive)
     * everything on file for one order in a single action — every
     * generated document and every received/uploaded file (dispute
     * evidence, buyer PO copy, supplier PO acknowledgment, ...) had to be
     * downloaded one at a time. file_store.order_id is already set for
     * both origins (insertGenerated() and insertReceived()), so
     * FileStoreRepository::forOrderExcludingInternalCaDocs() (forOrder()
     * minus the internal-only CA Financial Annexure, see that method's
     * docblock — this route is gated only on manage_orders, far broader
     * than the ca_module_view that document's data needs everywhere
     * else) is everything the dossier needs — no separate joins through
     * documents/dispute_documents/etc.
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

        // Owner decision D-214, phase 2: only the files whose money this person may see.
        $files = array_values(array_filter(FileStoreRepository::forOrderExcludingInternalCaDocs($orderId),
            static fn (array $f): bool => \App\Services\MoneyVisibility::canSeeFile((int) $f['id'])));
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

    /**
     * Owner decision 2026-10-30: corrects the date the buyer's PO was
     * received after Step 4d (it is Day 1 of an associate's 10 days), with a
     * reason, audit-logged. An incentive already fixed keeps its figures
     * until it is worked out again on the order's incentive card.
     */
    public function correctBuyerPoReceivedDate(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        $user = AuthService::currentUser();
        if ($order === null || (string) ($order['buyers_po_ref'] ?? '') === '' || $order['buyers_po_ref'] === 'NIL') {
            Flash::set('error', "Confirm the buyer's PO received (Step 4d) first.");
            header("Location: /orders/{$orderId}");
            return;
        }
        $receivedOn = trim((string) ($_POST['buyer_po_received_on'] ?? ''));
        $orderDate = substr((string) $order['created_at'], 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $receivedOn, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $receivedOn > date('Y-m-d') || $receivedOn < $orderDate) {
            Flash::set('error', "Enter the date the buyer's PO was received: not in the future and not before the order was created ({$orderDate}).");
            header("Location: /orders/{$orderId}");
            return;
        }
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (($reasonError = ReasonValidator::check($reason)) !== null) {
            Flash::set('error', $reasonError);
            header("Location: /orders/{$orderId}");
            return;
        }
        $old = $order['buyer_po_received_on'] !== null ? (string) $order['buyer_po_received_on'] : null;
        if ($old === $receivedOn) {
            Flash::set('success', 'No change: that is already the date on file.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderRepository::setBuyerPoReceivedOn($orderId, $receivedOn);
        AuditLogRepository::log((int) $user['id'], 'BUYER_PO_RECEIVED_DATE_CORRECTED', 'orders', $orderId, 'buyer_po_received_on', $old, $receivedOn, $reason);
        $locked = !empty(\App\Repositories\IncentiveRepository::forOrder($orderId)['locked_at']);
        Flash::set('success', 'Date the buyer\'s PO was received corrected to ' . \App\Helpers\Dates::short($receivedOn) . '.'
            . ($locked ? ' This order\'s incentive is already locked; unlock it (with a reason) on its incentive card for the new date to count.' : ''));
        header("Location: /orders/{$orderId}");
    }

    /** Stage 1->2 manual gate: buyer's signed PO received. */
    public function recordBuyerPo(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!StageGateService::isUnlocked($orderId, 2)) {
            Flash::set('error', 'Stage 2 has not been unlocked for this order yet — complete Stage 1 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision (2026-10-25): this stage completes only once its
        // document is approved (email off) / sent (email on).
        if ($blockedBy = DocumentGenerationService::stageDocumentBlockedBy($orderId, 2)) {
            Flash::set('error', DocumentGenerationService::stageDocumentBlockedMessage($blockedBy));
            header("Location: /orders/{$orderId}");
            return;
        }
        $ref = trim((string) ($_POST['buyers_po_ref'] ?? ''));
        if ($ref === '') {
            Flash::set('error', "Buyer's PO / reference number is required to confirm this gate.");
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision 2026-10-30: the day the PO arrived is the associate
        // agreement's Day 1, so it is recorded here (not the click date).
        $receivedOn = trim((string) ($_POST['buyer_po_received_on'] ?? ''));
        $orderDate = substr((string) (OrderRepository::find($orderId)['created_at'] ?? ''), 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $receivedOn, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $receivedOn > date('Y-m-d') || $receivedOn < $orderDate) {
            Flash::set('error', "Enter the date the buyer's PO was received: not in the future and not before the order was created ({$orderDate}).");
            header("Location: /orders/{$orderId}");
            return;
        }
        if (WetSignatureGuardService::buyerPoBlocked($orderId)) {
            Flash::set('error', 'Wet-signature required: upload the buyer\'s signed PO copy ("Attach Buyer PO copy") before confirming this gate.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderRepository::setBuyersPoRef($orderId, $ref, $receivedOn);
        StageGateService::passAndUnlockNext($orderId, 2, (int) $user['id']);
        Flash::set('success', "Buyer PO recorded ({$ref}, received " . \App\Helpers\Dates::short($receivedOn) . '). Stage 3 (PI / Production) unlocked.');
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
                \App\Helpers\OrderStorage::forReceived($order, 'buyer_po'),
                null,
                $orderId,
                (int) AuthService::currentUser()['id'],
                null,
                'buyer',
                'Buyer PO copy'
            );
            $documentId = OrderBuyerPoDocumentRepository::attach($orderId, $fileId);
            // External QA report N-08: this action never reached the
            // audit log, so it never notified anyone either.
            AuditLogRepository::log((int) AuthService::currentUser()['id'], 'ORDER_BUYER_PO_DOCUMENT_UPLOADED', 'order_buyer_po_documents', $documentId, null, null, null);
            Flash::set('success', 'Buyer PO copy attached.');
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
        }
        header("Location: /orders/{$orderId}");
    }

    /**
     * Batch 3 #13b — a free-form extra document attached to this order
     * that doesn't fit any fixed document type and isn't part of the
     * order progress chat (that's for back-and-forth discussion; this is
     * for a standalone file, e.g. a buyer-supplied certificate template).
     * Reuses file_store/FileUploadService rather than a new table — see
     * schema.sql Section AZ.
     */
    public function uploadAdditionalDocument(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            Flash::set('error', 'A title is required for the document.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;

        try {
            $fileId = FileUploadService::handleUpload(
                'document',
                'order_additional_document',
                \App\Helpers\OrderStorage::folder($order, 'additional_documents'),
                null,
                $orderId,
                (int) AuthService::currentUser()['id'],
                null,
                trim((string) ($_POST['received_from'] ?? '')) ?: null,
                $title
            );
            FileStoreRepository::markAsAdditionalDocument($fileId, $notes);
            AuditLogRepository::log((int) AuthService::currentUser()['id'], 'ORDER_ADDITIONAL_DOCUMENT_ADDED', 'file_store', $fileId, 'document_type_label', null, $title);
            Flash::set('success', "\"{$title}\" attached to this order.");
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
        }
        header("Location: /orders/{$orderId}");
    }

    /**
     * Soft delete only — the row is deactivated, the file on disk is
     * never touched.
     *
     * External QA report L-18: "deleteAdditionalDocument (order + client)
     * still takes no reason; no 'show removed' view." Now requires a
     * reason (ReasonValidator::MIN_LENGTH, same standing instruction as
     * every other reason field in this app), logged on the audit entry —
     * see showRemovedAdditionalDocuments() for the "show removed" half.
     */
    public function deleteAdditionalDocument(array $params): void
    {
        $orderId = (int) $params['id'];
        $fileId = (int) $params['fileId'];
        $file = FileStoreRepository::find($fileId);
        if (!$file || (int) $file['order_id'] !== $orderId || !$file['is_additional_document']) {
            Flash::set('error', 'Document not found.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}");
            return;
        }

        FileStoreRepository::deactivate($fileId);
        AuditLogRepository::log((int) AuthService::currentUser()['id'], 'ORDER_ADDITIONAL_DOCUMENT_REMOVED', 'file_store', $fileId, 'document_type_label', $file['document_type_label'], null, $reason);
        Flash::set('success', "\"{$file['document_type_label']}\" removed. The file itself is never deleted from disk.");
        header("Location: /orders/{$orderId}");
    }

    /** External QA report L-18: "no screen to see removed files" — a read-only list of this order's deactivated additional documents, newest first. */
    public function showRemovedAdditionalDocuments(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        View::render('orders/removed_additional_documents', [
            'order' => $order,
            'removedDocuments' => FileStoreRepository::removedAdditionalDocumentsForOrder($orderId),
        ]);
    }

    /** The bank charges typed with "Bank charges deducted" (order currency), or null to use the difference. */
    private static function postedBankCharges(): ?float
    {
        $raw = trim((string) ($_POST['bank_charges'] ?? ''));
        return $raw !== '' && is_numeric($raw) ? (float) $raw : null;
    }

    /** HNT-003: the recording below runs while this order's payment lock is held (PartPaymentService::claimOrderPayments). */
    public function recordAdvancePayment(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!\App\Services\PartPaymentService::claimOrderPayments($orderId)) {
            \App\Helpers\Flash::set('error', \App\Services\PartPaymentService::BUSY_MESSAGE);
            header("Location: /orders/{$orderId}");
            return;
        }
        try {
            $this->recordAdvancePaymentLocked($params);
        } finally {
            \App\Services\PartPaymentService::releaseOrderPayments($orderId);
        }
    }

    private function recordAdvancePaymentLocked(array $params): void
    {
        $orderId = (int) $params['id'];
        $amount = (float) ($_POST['advance_amount'] ?? 0);
        $receivedAt = trim((string) ($_POST['advance_received_at'] ?? '')) ?: date('Y-m-d');
        if ((OrderPaymentStatusRepository::find($orderId) ?? [])['advance_cleared_at'] ?? null) {
            Flash::set('error', \App\Services\PartPaymentService::clearedMessage('advance'));
            header("Location: /orders/{$orderId}");
            return;
        }
        if ($amount <= 0) {
            Flash::set('error', 'Enter the advance amount actually received.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision D-244 (4 Nov 2026): the INR the bank credited is entered and the rate is worked
        // out from it; the bank's rate on the advice, when given, checks it (₹1 tolerance).
        $inr = \App\Services\RemittanceInr::fromPost($_POST, 'advance_', $amount, (string) ((OrderRepository::find($orderId) ?? [])['currency_code'] ?? ''));
        if (isset($inr['error'])) {
            Flash::set('error', $inr['error']);
            header("Location: /orders/{$orderId}");
            return;
        }
        $rate = (float) $inr['rate'];
        $bankRef = trim((string) ($_POST['advance_bank_reference'] ?? ''));
        if ($error = \App\Helpers\FieldValidator::moneyAmount($amount, 'The advance amount') ?? \App\Helpers\FieldValidator::exchangeRate($rate) ?? \App\Helpers\FieldValidator::bankReference($bankRef)) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner report (2026-10-27, blocker): a mistyped advance flowed
        // straight into the Order Confirmation. An advance can never exceed
        // the whole order, and an amount that differs from the advance due
        // must be confirmed on purpose.
        $orderForAdvance = OrderRepository::find($orderId);
        $fobTotal = OrderProductRepository::totalFobValue($orderId);
        $advanceDue = round($fobTotal * (float) ($orderForAdvance['advance_pct'] ?? 0) / 100, 2);
        $currency = (string) ($orderForAdvance['currency_code'] ?? '');
        $advanceSoFar = \App\Services\PartPaymentService::receivedTotal($orderId, 'advance');
        if ($fobTotal > 0 && $advanceSoFar + $amount > $fobTotal + 0.005) {
            Flash::set('error', sprintf('%s %s is more than the whole order value (%s %s). Check the amount the bank received.', $currency, number_format($amount, 2), $currency, number_format($fobTotal, 2)));
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner 2 Nov 2026 ("18 yes"): an amount that differs from what is still due says why —
        // bank charges deducted, the buyer paid less, or the buyer paid more.
        if (\App\Services\PartPaymentService::receiptExists($orderId, 'advance', null, $amount, $receivedAt, $rate, $inr['inr'], $bankRef)) {
            Flash::set('warning', 'Advance remittance already recorded with these details — nothing changed.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $diffEval = \App\Services\RemittanceDifference::evaluatePosted($_POST, 'Advance', $currency, $advanceDue, $advanceSoFar, $amount, self::postedBankCharges());
        if (isset($diffEval['error'])) {
            Flash::set('error', $diffEval['error']);
            header("Location: /orders/{$orderId}");
            return;
        }
        // QA Round 6, R6-N22: the amount, its rate and their audit rows are
        // saved together or not at all.
        $recorded = \App\Config\Database::transaction(static function () use ($orderId, $amount, $receivedAt, $rate, $bankRef, $diffEval, $inr): bool {
            // QA Round 6, R6-N17: a second click with the same details records and logs nothing more.
            // Gap list G-5 (owner, 1 Nov 2026): each remittance is its own receipt; the leg keeps the total.
            if (\App\Services\PartPaymentService::receiptExists($orderId, 'advance', null, $amount, $receivedAt, $rate, $inr['inr'], $bankRef)) {
                return false;
            }
            $receiptId = \App\Services\PartPaymentService::addLegReceipt($orderId, 'advance', $amount, $receivedAt, $rate, $bankRef, (int) AuthService::currentUserId(), $diffEval['reason'], (float) $diffEval['charges'], $inr);
            \App\Services\RemittanceDifference::afterReceipt($orderId, 'advance', null, $receiptId, $diffEval, $amount, $rate, $receivedAt, $bankRef, (int) AuthService::currentUserId());
            AuditLogRepository::log(AuthService::currentUserId(), 'CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', $orderId, 'advance_exchange_rate', null, (string) $rate);
            // External QA report L-23: advance payment recording was never audit-logged.
            AuditLogRepository::log(AuthService::currentUserId(), 'ADVANCE_PAYMENT_RECORDED', 'order_payment_status', $orderId, 'advance_amount', null, (string) $amount);
            return true;
        });
        if (!$recorded) {
            Flash::set('warning', 'Advance remittance already recorded with these details — nothing changed.');
            header("Location: /orders/{$orderId}");
            return;
        }

        // docs/schema.sql Section AC — fallback lock trigger. The PI-details
        // form (client's own consent) is the primary trigger; if a client
        // never completes that, real money moving is the latest point
        // client data can still be safely editable. A no-op if the
        // PI-details consent already locked this client first.
        $order = OrderRepository::find($orderId);
        if ($order) {
            ClientRepository::lockData((int) $order['client_id'], 'Auto-locked: advance remittance recorded before client PI-details consent');
        }

        Flash::set('success', trim($this->remittanceStatement($orderId, $amount, $rate, $inr) . ' ' . \App\Services\RemittanceDifference::outcome('advance', 'Advance', $currency, $diffEval, $amount, $rate)) . ' ' . 'Advance remittance recorded. Mark it cleared once your bank confirms receipt.');
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
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log((int) $user['id'], 'CLIENT_PAYMENT_REPORT_REVIEWED', 'client_payment_reports', $reportId, null, null, null);
        Flash::set('success', 'Payment report marked reviewed.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 2->3 gate: advance payment marked cleared in NexaCrest's bank account. */
    public function clearAdvancePayment(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!StageGateService::isUnlocked($orderId, 3)) {
            Flash::set('error', 'Stage 3 has not been unlocked for this order yet — complete Stage 2 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision (2026-10-25): this stage completes only once its
        // document is approved (email off) / sent (email on).
        if ($blockedBy = DocumentGenerationService::stageDocumentBlockedBy($orderId, 3)) {
            Flash::set('error', DocumentGenerationService::stageDocumentBlockedMessage($blockedBy));
            header("Location: /orders/{$orderId}");
            return;
        }
        // QA-5 (GATE-05): the stage-unlock check above only proves Stage 2
        // was passed — it says nothing about whether an advance amount was
        // ever actually recorded via recordAdvancePayment(). Without this,
        // clearing a never-recorded (NULL) advance would still unlock
        // Stage 4 and provision client portal access with zero money
        // having been received.
        $existingPayment = OrderPaymentStatusRepository::find($orderId);
        if (!$existingPayment || $existingPayment['advance_amount'] === null) {
            Flash::set('error', 'Record the advance remittance before marking it cleared.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Gap list G-5 (owner, 1 Nov 2026): not cleared while money is still owed, unless a manager approves it with a reason.
        $partCheck = \App\Services\PartPaymentService::clearCheck($orderId, 'advance', null, $user, (string) ($_POST['short_reason'] ?? ''), (string) ($_POST['short_kind'] ?? ''));
        if (isset($partCheck['error'])) {
            Flash::set('error', $partCheck['error']);
            header("Location: /orders/{$orderId}");
            return;
        }
        $clearedAt = trim((string) ($_POST['advance_cleared_at'] ?? '')) ?: date('Y-m-d');

        $fobTotal = OrderProductRepository::totalFobValue($orderId);
        $order = OrderRepository::find($orderId);
        // D-215 (5a, owner confirmed twice): the balance leg leaves out the order's payment milestones.
        $balanceAmount = OrderPaymentStatusRepository::balanceDue($fobTotal, (float) $order['advance_pct'], $existingPayment, \App\Repositories\PaymentMilestoneRepository::balanceDeduction($orderId, $fobTotal, true)); // QA batch 4, ORD-09
        // Gap list G-2 (owner, 1 Nov 2026): before shipment, the balance is due on shipment readiness (no fixed date);
        // against the BL, it gets its due date only when the scanned BL is sent to the buyer (recordScannedBlSent()).
        $balanceDueDate = null;

        OrderPaymentStatusRepository::markAdvanceCleared($orderId, $clearedAt, (int) $user['id']);
        $shortNote = $partCheck['short'] !== null ? ' ' . \App\Services\PartPaymentService::recordShortfall($orderId, 'advance', null, $partCheck['short'], $user) : '';
        \App\Services\AssociatePayoutService::afterPaymentChange($orderId, (int) $user['id']); // D-264: fixed by itself once the buyer has paid in full
        OrderPaymentStatusRepository::setBalanceAmount($orderId, $balanceAmount, $balanceDueDate);
        // External QA report L-23: advance payment clearing was never audit-logged.
        AuditLogRepository::log((int) $user['id'], 'ADVANCE_PAYMENT_CLEARED', 'order_payment_status', $orderId, 'advance_cleared_at', null, $clearedAt);
        StageGateService::passAndUnlockNext($orderId, 3, (int) $user['id']);

        // Client portal access is provisioned here, and only here — see
        // ClientPortalService's docblock. No-op if this client already has
        // a login from an earlier order.
        $provisionStatus = \App\Services\ClientPortalService::provisionIfNeeded((int) $order['client_id'], $orderId);

        $baseMessage = 'Advance payment cleared. Stage 4 unlocked — you can now generate the Order Confirmation.' . $shortNote;
        if ($provisionStatus === 'provisioned') {
            Flash::set('success', $baseMessage . \App\Services\ClientPortalService::inviteOutcome(), \App\Services\ClientPortalService::$lastInvite['link'] ?? null); // D-280
        } elseif ($provisionStatus === 'no_email_on_file') {
            Flash::set('warning', $baseMessage . ' WARNING: this client has no email on file, so portal login could NOT be provisioned — add an email to their record and provision access manually, otherwise they will never be able to log in.');
        } else {
            Flash::set('success', $baseMessage . ' The client already has portal access from an earlier order.');
        }
        header("Location: /orders/{$orderId}");
    }

    /**
     * Step 5 LC leg (order.payment_method = 'LC'): records the LC that
     * replaces the cash advance entirely — issuing bank, LC number, LC
     * amount, charges billed to the client, and (optionally) the final
     * LC document. This leg's equivalent of recordAdvancePayment() —
     * purely an informational entry, no stage gate of its own;
     * confirmLcUsable() below is the real gate.
     */
    public function recordLcDetails(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();
        // QA batch 2 (ORD-10): the LC route exists only on an LC order — never a side door past the cash advance.
        if (($order['payment_method'] ?? '') !== 'LC') {
            Flash::set('error', 'This order is not paid by Letter of Credit.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $lcAmount = (float) ($_POST['lc_amount'] ?? 0);
        if ($lcAmount <= 0) {
            Flash::set('error', 'Enter the LC amount.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $lcChargesRaw = trim((string) ($_POST['lc_charges_amount'] ?? ''));
        if ($lcChargesRaw !== '' && (!is_numeric($lcChargesRaw) || (float) $lcChargesRaw < 0)) {
            Flash::set('error', 'Enter a valid LC charges amount (or leave it blank).');
            header("Location: /orders/{$orderId}");
            return;
        }
        $lcNumber = trim((string) ($_POST['lc_number'] ?? '')) ?: null;
        $lcBankName = trim((string) ($_POST['lc_bank_name'] ?? '')) ?: null;
        $lcCharges = $lcChargesRaw !== '' ? (float) $lcChargesRaw : null;

        $existing = OrderLcDetailRepository::find($orderId);
        $lcFileId = $existing['lc_file_id'] ?? null;
        if (!empty($_FILES['lc_file']['name'])) {
            try {
                $lcFileId = FileUploadService::handleUpload(
                    'lc_file',
                    'order_lc_copy',
                    \App\Helpers\OrderStorage::folder($order, 'lc'),
                    null,
                    $orderId,
                    (int) $user['id'],
                    null,
                    null,
                    'Letter of Credit'
                );
            } catch (\Throwable $e) {
                Flash::set('error', 'LC details were not saved — the LC file upload failed: ' . Flash::describeException($e, 'upload failed — check the server error log.'));
                header("Location: /orders/{$orderId}");
                return;
            }
        }

        OrderLcDetailRepository::recordLegDetails($orderId, $lcNumber, $lcBankName, $lcAmount, $lcCharges, $lcFileId, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'LC_DETAILS_RECORDED', 'order_lc_details', $orderId, 'lc_amount', null, (string) $lcAmount);

        // Same real-money-moving trigger as recordAdvancePayment() — see
        // docs/schema.sql Section AC.
        ClientRepository::lockData((int) $order['client_id'], 'Auto-locked: LC details recorded before client PI-details consent');

        Flash::set('success', 'LC details recorded. Confirm it usable once the advising bank confirms.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Gap list G-4 (owner, 1 Nov 2026): the Letter of Credit's dates — issue,
     * expiry, last shipment and the deadline to present the documents. The
     * bank can refuse to pay if one is missed, so each is reminded 7 days
     * before (cron/check_alerts.php).
     */
    public function saveLcDates(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $back = "/orders/{$orderId}#step-5-lc-bank-loan";
        if (($order['payment_method'] ?? '') !== 'LC') {
            Flash::set('error', 'This order is not paid by Letter of Credit.');
            header("Location: {$back}");
            return;
        }
        $dates = [];
        foreach (['lc_issue_date', 'lc_expiry_date', 'lc_last_shipment_date', 'lc_presentation_deadline'] as $f) {
            $v = trim((string) ($_POST[$f] ?? ''));
            if ($v !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
                Flash::set('error', 'Enter each LC date as a date, or leave it empty.');
                header("Location: {$back}");
                return;
            }
            $dates[$f] = $v !== '' ? $v : null;
        }
        $error = match (true) {
            $dates['lc_issue_date'] !== null && $dates['lc_issue_date'] > date('Y-m-d') => 'The LC issue date cannot be in the future.',
            $dates['lc_expiry_date'] !== null && $dates['lc_last_shipment_date'] !== null && $dates['lc_last_shipment_date'] > $dates['lc_expiry_date'] => 'The last shipment date cannot be after the LC expiry date.',
            $dates['lc_expiry_date'] !== null && $dates['lc_presentation_deadline'] !== null && $dates['lc_presentation_deadline'] > $dates['lc_expiry_date'] => 'The deadline to present documents cannot be after the LC expiry date.',
            $dates['lc_issue_date'] !== null && array_filter([$dates['lc_expiry_date'], $dates['lc_last_shipment_date'], $dates['lc_presentation_deadline']], static fn ($d): bool => $d !== null && $d <= $dates['lc_issue_date']) !== []
                => 'The expiry, last shipment and presentation dates must be after the LC issue date (' . \App\Helpers\Dates::short($dates['lc_issue_date']) . ').',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: {$back}");
            return;
        }
        $before = OrderLcDetailRepository::find($orderId);
        $userId = (int) AuthService::currentUserId();
        OrderLcDetailRepository::saveDates($orderId, $dates['lc_issue_date'], $dates['lc_expiry_date'], $dates['lc_last_shipment_date'], $dates['lc_presentation_deadline'], $userId);
        foreach ($dates as $f => $v) {
            if (($before[$f] ?? null) !== $v) {
                AuditLogRepository::log($userId, 'LC_DATE_SAVED', 'order_lc_details', $orderId, $f, $before[$f] ?? null, $v);
            }
        }
        Flash::set('success', 'LC dates saved. Reminders go out 7 days before the last shipment date, the deadline to present documents and the expiry.');
        header("Location: {$back}");
    }

    /** Gap list G-4 (owner, 1 Nov 2026): an amendment to the order's Letter of Credit, with an optional copy. */
    public function addLcAmendment(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $back = "/orders/{$orderId}#step-5-lc-bank-loan";
        $date = trim((string) ($_POST['amended_on'] ?? ''));
        $reference = trim((string) ($_POST['reference'] ?? ''));
        $change = trim((string) ($_POST['change_text'] ?? ''));
        $error = match (true) {
            ($order['payment_method'] ?? '') !== 'LC' => 'This order is not paid by Letter of Credit.',
            !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $date > date('Y-m-d') => 'Enter the date of the amendment (today or earlier).',
            $reference === '' || mb_strlen($reference) > 100 => 'Enter the amendment number or reference (up to 100 characters).',
            mb_strlen($change) < 5 || mb_strlen($change) > 500 => 'Say what the amendment changed (5 to 500 characters).',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: {$back}");
            return;
        }
        $userId = (int) AuthService::currentUserId();
        $fileId = null;
        if (!empty($_FILES['amendment_file']['name'])) {
            try {
                $fileId = FileUploadService::handleUpload('amendment_file', 'order_lc_amendment', \App\Helpers\OrderStorage::folder($order, 'lc'), null, $orderId, $userId, null, null, 'LC amendment ' . $reference);
            } catch (\Throwable $e) {
                Flash::set('error', 'The amendment was not saved — the copy could not be uploaded: ' . Flash::describeException($e, 'upload failed — check the server error log.'));
                header("Location: {$back}");
                return;
            }
        }
        $id = \App\Repositories\OrderLcAmendmentRepository::add($orderId, $date, $reference, $change, $fileId, $userId);
        AuditLogRepository::log($userId, 'LC_AMENDMENT_RECORDED', 'order_lc_amendments', $id, 'reference', null, $reference, $change);
        Flash::set('success', "LC amendment {$reference} recorded. If it changed a date, update the LC dates too.");
        header("Location: {$back}");
    }

    /**
     * Step 5 LC leg's equivalent of clearAdvancePayment() — the real
     * Stage 2->3 gate when this order's payment_method is LC. Mirrors
     * clearAdvancePayment() exactly (same stage check, same balance
     * computation, same client-portal provisioning), just reading the
     * LC leg's own confirmation instead of the cash advance's.
     */
    public function confirmLcUsable(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        // QA batch 2 (ORD-10): the LC route exists only on an LC order — never a side door past the cash advance.
        $lcOrder = OrderRepository::find($orderId);
        if ($lcOrder === null || ($lcOrder['payment_method'] ?? '') !== 'LC') {
            Flash::set('error', 'This order is not paid by Letter of Credit.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if (!StageGateService::isUnlocked($orderId, 3)) {
            Flash::set('error', 'Stage 3 has not been unlocked for this order yet — complete Stage 2 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision (2026-10-25): this stage completes only once its
        // document is approved (email off) / sent (email on).
        if ($blockedBy = DocumentGenerationService::stageDocumentBlockedBy($orderId, 3)) {
            Flash::set('error', DocumentGenerationService::stageDocumentBlockedMessage($blockedBy));
            header("Location: /orders/{$orderId}");
            return;
        }
        $existingLc = OrderLcDetailRepository::find($orderId);
        if (!$existingLc || $existingLc['lc_amount'] === null) {
            Flash::set('error', 'Record the LC details before confirming it usable.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $fobTotal = OrderProductRepository::totalFobValue($orderId);
        $order = OrderRepository::find($orderId);
        $balanceAmount = round($fobTotal * ((float) $order['balance_pct']) / 100, 2);
        // Gap list G-2 (owner, 1 Nov 2026): an LC balance gets its due date when the scanned BL is sent to the buyer.
        $balanceDueDate = null;

        OrderLcDetailRepository::confirmUsable($orderId, (int) $user['id']);
        OrderPaymentStatusRepository::setBalanceAmount($orderId, $balanceAmount, $balanceDueDate);
        AuditLogRepository::log((int) $user['id'], 'LC_CONFIRMED_USABLE', 'order_lc_details', $orderId, 'lc_confirmed_at', null, date('Y-m-d H:i:s'));
        StageGateService::passAndUnlockNext($orderId, 3, (int) $user['id']);

        $provisionStatus = \App\Services\ClientPortalService::provisionIfNeeded((int) $order['client_id'], $orderId);

        $baseMessage = 'LC confirmed usable. Stage 4 unlocked — you can now generate the Order Confirmation.';
        if ($provisionStatus === 'provisioned') {
            Flash::set('success', $baseMessage . \App\Services\ClientPortalService::inviteOutcome(), \App\Services\ClientPortalService::$lastInvite['link'] ?? null); // D-280
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
        $user = AuthService::currentUser();
        $before = OrderRepository::find($orderId);
        // QA Round 5, R5-01: on a locked (fully settled) order this is a
        // locked-order edit like any other — "Override locked data", a
        // reason, and an ORDER_EDITED_WHILE_LOCKED audit row.
        if (!empty($before['is_locked'])) {
            $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
            if (!OrderEditGuard::allow(null, true, (int) $user['id'], $roleId, trim((string) ($_POST['reason'] ?? '')), 'orders', $orderId, 'production_status')) {
                header("Location: /orders/{$orderId}#step-production");
                return;
            }
        }
        $text = trim((string) ($_POST['production_status_text'] ?? ''));
        if ($text !== '' && $text !== ($before['production_status_text'] ?? '')) {
            OrderRepository::setProductionStatus($orderId, $text);
            AuditLogRepository::log((int) $user['id'], 'PRODUCTION_STATUS_UPDATED', 'orders', $orderId, 'production_status_text', $before['production_status_text'] ?? null, $text);
        }
        $shipmentTextInput = trim((string) ($_POST['est_shipment_date_text'] ?? ''));
        $shipmentDateInput = trim((string) ($_POST['est_shipment_date'] ?? ''));
        $resolvedDate = $shipmentDateInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $shipmentDateInput)
            ? $shipmentDateInput
            : ($before['est_shipment_date'] ?? null);
        $resolvedText = $shipmentTextInput !== '' ? $shipmentTextInput : ($before['est_shipment_date_text'] ?? '');
        $textChanged = $resolvedText !== '' && $resolvedText !== ($before['est_shipment_date_text'] ?? '');
        $dateChanged = $resolvedDate !== ($before['est_shipment_date'] ?? null);
        if ($textChanged || $dateChanged) {
            OrderRepository::setEstShipmentDate($orderId, $resolvedText, $resolvedDate);
            AuditLogRepository::log((int) $user['id'], 'EST_SHIPMENT_DATE_UPDATED', 'orders', $orderId, 'est_shipment_date_text', $before['est_shipment_date_text'] ?? null, $resolvedText);

            // Export process step 8: "client gets notified - he can able to
            // see this in his portal" — reuses the existing order-comment-
            // to-client channel (OrderCommentService::postAsStaff()), which
            // already shows in the client's portal thread AND emails them
            // when an address is on file, rather than building a second,
            // parallel notification mechanism for this one field.
            $dateDisplay = $resolvedDate ? date('d M Y', strtotime($resolvedDate)) : $resolvedText;
            try {
                OrderCommentService::postAsStaff($orderId, (int) $user['id'], "The estimated shipment date for your order has been updated to: {$dateDisplay}.", []);
            } catch (\Throwable $e) {
                // Never block the actual production/shipment-date update over a notification failure.
            }
        }
        Flash::set('success', 'Production status updated.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * D-262: the MD replaces this order's frozen company details (bank, LUT, IEC, address …) with
     * today's, for a real change such as a new bank account. A reason is required and logged.
     */
    public function refreshCompanyDetails(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!\App\Services\IncentiveService::isMd($user)) {
            http_response_code(403);
            echo 'Only the Managing Director can refresh the company details on an order.';
            return;
        }
        $error = \App\Services\OrderSnapshotService::refreshCompany($orderId, (int) $user['id'], (string) ($_POST['reason'] ?? ''));
        if ($error !== null) {
            Flash::set('error', $error);
        } else {
            Flash::set('success', 'This order now uses today\'s company details (bank, LUT, IEC, address). Documents already issued are unchanged; the next ones print the new details.');
        }
        header("Location: /orders/{$orderId}#company-details-snapshot");
    }

    /**
     * QA Round 5, R5-03 (owner decision 2026-10-28, Option A): MD/ED approve
     * the payment terms of an order on a "Requires MD approval" preset —
     * the Quotation can't be generated or sent until they do.
     */
    public function approvePaymentTerms(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        // QA Round 6, R6-N13: nothing to approve on an order that is cancelled or lost.
        if (in_array($order['status'], ['cancelled', 'lost'], true)) {
            Flash::set('error', 'This order is ' . $order['status'] . ' — its payment terms cannot be approved.');
            header("Location: /orders/{$orderId}#step-1-quotation-prepare");
            return;
        }
        // Owner decision 1 Nov 2026 (A-1, Q-R6-1; replaces D-117): a second person approves the
        // terms of an order; only the MD or ED may approve their own, and that is noted in the audit log.
        $ownOrder = (int) ($order['created_by'] ?? 0) === (int) $user['id'];
        if ($ownOrder && !MakerCheckerGuard::paymentTermsSelfApprovalAllowed((int) $user['id'])) {
            Flash::set('error', 'You created this order, so its payment terms must be approved by someone else.');
            header("Location: /orders/{$orderId}#step-1-quotation-prepare");
            return;
        }
        if (!\App\Services\PaymentTermsApprovalService::isPending($orderId)
            || !\App\Services\PaymentTermsApprovalService::approve($orderId, (int) $user['id'])) {
            Flash::set('warning', 'These payment terms do not need approval, or are already approved.');
        } else {
            if ($ownOrder) {
                AuditLogRepository::log((int) $user['id'], 'PAYMENT_TERMS_SELF_APPROVED', 'orders', $orderId, 'payment_terms', null, 'approved by the order\'s own creator',
                    'Self-approval by MD/ED (owner decision 1 Nov 2026, A-1)');
            }
            Flash::set('success', 'Payment terms approved — the Quotation can now be generated.');
        }
        header("Location: /orders/{$orderId}#step-1-quotation-prepare");
    }

    /** docs/schema.sql Section AF — per-order, staff-controlled, default off. */
    public function setDisputeButtonVisible(array $params): void
    {
        $orderId = (int) $params['id'];
        $visible = !empty($_POST['dispute_button_visible_to_client']);
        OrderRepository::setDisputeButtonVisible($orderId, $visible);
        $user = AuthService::currentUser();
        AuditLogRepository::log((int) $user['id'], 'DISPUTE_BUTTON_VISIBILITY_CHANGED', 'orders', $orderId, 'dispute_button_visible_to_client', null, $visible ? '1' : '0');
        Flash::set('success', $visible ? 'The client can now raise a dispute on this order from their portal.' : 'The "Ask to cancel this order" (dispute) button is now hidden from the client for this order.');
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
    /**
     * Gap list G-6 (owner, 1 Nov 2026): with e-mail sending off, staff send
     * the Order Confirmation themselves — the buyer's 48 hours start when
     * staff mark it as sent, not when it is approved.
     */
    public function markOcSentToBuyer(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!StageGateService::isUnlocked($orderId, 4)) {
            Flash::set('error', 'Stage 4 has not been unlocked for this order yet — complete Stage 3 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if (\App\Services\EmailDispatchService::mailSendingEnabled()) {
            Flash::set('error', 'Document e-mails to the buyer are on: the buyer\'s 48 hours start when the Order Confirmation is e-mailed.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if ($blockedBy = DocumentGenerationService::stageDocumentBlockedBy($orderId, 4)) {
            Flash::set('error', DocumentGenerationService::stageDocumentBlockedMessage($blockedBy));
            header("Location: /orders/{$orderId}");
            return;
        }
        $oc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'OC');
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        if ($oc === null) {
            Flash::set('error', 'Generate the Order Confirmation for this order first.');
        } elseif ($ack !== null && $ack['acknowledged_at'] !== null) {
            Flash::set('error', 'This order\'s Order Confirmation has already been acknowledged.');
        } elseif ($ack !== null && (int) $ack['document_id'] === (int) $oc['id']) {
            Flash::set('error', 'This Order Confirmation is already marked as sent to the buyer.');
        } else {
            \App\Services\EmailDispatchService::startOcAcknowledgmentClock(DocumentRepository::find((int) $oc['id']) ?? $oc);
            $ack = OrderOcAcknowledgmentRepository::find($orderId);
            AuditLogRepository::log((int) $user['id'], 'OC_MARKED_SENT_TO_BUYER', 'orders', $orderId, 'oc_sent_at', null, (string) $ack['sent_at']);
            Flash::set('success', 'Order Confirmation marked as sent to the buyer. If the buyer does not respond by ' . \App\Helpers\Dates::human((string) $ack['due_at']) . ', it auto-confirms and Step 7 (Supplier PO) unlocks.');
        }
        header("Location: /orders/{$orderId}");
    }

    public function recordOcAcknowledgment(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!StageGateService::isUnlocked($orderId, 4)) {
            Flash::set('error', 'Stage 4 has not been unlocked for this order yet — complete Stage 3 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision (2026-10-25): this stage completes only once its
        // document is approved (email off) / sent (email on).
        if ($blockedBy = DocumentGenerationService::stageDocumentBlockedBy($orderId, 4)) {
            Flash::set('error', DocumentGenerationService::stageDocumentBlockedMessage($blockedBy));
            header("Location: /orders/{$orderId}");
            return;
        }

        // docs/schema.sql Section AU: off by default (today's behaviour) —
        // manage_orders alone, already required to reach this route, is
        // enough. When an admin turns this on, a second, narrower
        // permission is also required, so the override can be restricted
        // to specific roles without touching code.
        if (CompanySettingsRepository::get('oc_ack_override_restricted') === '1') {
            $user = AuthService::currentUser();
            $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
            if (!PermissionService::can((int) $user['id'], $roleId, 'override_buyer_acknowledgment')) {
                Flash::set('error', 'Recording a buyer acknowledgment manually has been restricted — you do not hold the required permission.');
                header("Location: /orders/{$orderId}");
                return;
            }
        }

        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        if ($ack && $ack['acknowledged_at'] !== null) {
            Flash::set('error', 'This order\'s Order Confirmation has already been acknowledged.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if (!$ack) {
            // No OC send has ever completed for this order (e.g. mail
            // sending is misconfigured, or a send is still stuck awaiting
            // Level-2 approval) — there is nothing yet for recordSent() to
            // have created. The OC must at least have been generated so
            // there's a real document to point this record at.
            $ocDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'OC');
            if (!$ocDoc) {
                Flash::set('error', 'Generate the Order Confirmation for this order first.');
                header("Location: /orders/{$orderId}");
                return;
            }
            OrderOcAcknowledgmentRepository::ensureRowExists($orderId, (int) $ocDoc['id']);
        }

        $note = trim((string) ($_POST['acknowledged_note'] ?? ''));
        if ($error = ReasonValidator::check($note)) {
            Flash::set('error', 'Describe the evidence (e.g. quote the buyer\'s email reply): ' . $error);
            header("Location: /orders/{$orderId}");
            return;
        }

        $user = AuthService::currentUser();
        if (!OrderOcAcknowledgmentRepository::markAcknowledged($orderId, 'staff_recorded_email', $note, (int) $user['id'])) {
            // QA-5 EML-06: lost the race to another acknowledgment path
            // (client portal, or the 48h auto-confirm cron) between this
            // action's own pre-check above and this write.
            Flash::set('error', 'This order was already acknowledged by another path a moment ago.');
            header("Location: /orders/{$orderId}");
            return;
        }
        StageGateService::passAndUnlockNext($orderId, 4, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'OC_ACKNOWLEDGED_VIA_EMAIL', 'orders', $orderId, null, null, null, $note);
        Flash::set('success', 'Buyer acknowledgement recorded. Stage 5 (Supplier PO) unlocked.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Step 16 (Order Feedback) for a non-technical client who tells their
     * NexaCrest contact how the order went over the phone or in person
     * rather than using the portal — there was no way for staff to
     * record that before this. Same gate and the same
     * OrderFeedbackRepository::upsert() the client's own
     * ClientPortalController::submitFeedback() uses; logged under its own
     * action type so the audit trail still shows it was staff-entered,
     * not a genuine client-portal submission.
     */
    public function recordFeedback(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        if (!in_array($order['status'], ['shipped_docs_sent', 'complete'], true)) {
            Flash::set('error', "Feedback can be recorded once this order's documents have been sent to the client.");
            header("Location: /orders/{$orderId}");
            return;
        }

        $rating = (int) ($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            Flash::set('error', 'Choose a rating between 1 and 5 stars.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $comments = trim((string) ($_POST['comments'] ?? '')) ?: null;

        OrderFeedbackRepository::upsert($orderId, (int) $order['client_id'], $rating, $comments);

        $user = AuthService::currentUser();
        AuditLogRepository::log((int) $user['id'], 'ORDER_FEEDBACK_RECORDED_BY_STAFF', 'orders', $orderId, null, null, (string) $rating, 'Feedback recorded by staff on the client\'s behalf (not submitted via the client portal).');

        Flash::set('success', 'Feedback recorded for this order.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 5: fill the Supplier PO's material/commercial terms — creates order_supplier_po ahead of generating the SUPPO document. */
    public function saveSupplierPo(array $params): void
    {
        $orderId = (int) $params['id'];
        // QA-4 P0.5: confirmSupplierSigned() (below) already gates on Stage 5
        // being unlocked, but this method — which actually creates the
        // order_supplier_po row and mints its reference number ahead of
        // generating the SUPPO document — never did, so holding manage_orders
        // alone was enough to save/generate a Supplier PO for an order still
        // at Stage 1, before a Buyer PO, PI or OC even existed.
        if (!StageGateService::isUnlocked($orderId, 5)) {
            Flash::set('error', 'Stage 5 has not been unlocked for this order yet — complete Stage 4 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $supplier = $supplierId ? SupplierRepository::find($supplierId) : null;
        if (!$supplier || !$supplier['is_active']) {
            Flash::set('error', 'Select an active supplier from the Suppliers directory first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // A supplier can hold several types; the Supplier PO records the ONE
        // that applies to this order (picked automatically when it has one).
        $supplierType = trim((string) ($_POST['supplier_type'] ?? ''));
        if (count($supplier['types']) === 1) {
            $supplierType = $supplier['types'][0];
        } elseif ($supplier['types'] !== [] && !in_array($supplierType, $supplier['types'], true)) {
            Flash::set('error', "Choose which of {$supplier['supplier_legal_name']}'s supplier types applies to this order.");
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

        // D-361 (decision 2): the supplier offer chosen in the table and its quoted price; an agreed
        // price different from the quote needs a reason, kept with the PO and in the Audit Log.
        $__offerId = (int) ($_POST['supplier_offer_id'] ?? 0);
        $__offer = $__offerId > 0 ? \App\Repositories\ProductSupplierRepository::find($__offerId) : null;
        if ($__offer !== null && (int) ($__offer['supplier_id'] ?? 0) !== $supplierId) {
            $__offer = null; // a different supplier was chosen after the table row: no quote to compare with
        }
        $__quoted = $__offer !== null && is_numeric(str_replace(',', '', (string) ($_POST['quoted_unit_price_inr'] ?? ''))) ? round((float) str_replace(',', '', (string) $_POST['quoted_unit_price_inr']), 2) : null;
        $__agreedRaw = str_replace(',', '', trim((string) ($_POST['unit_price_inr'] ?? '')));
        $__overrideReason = trim((string) ($_POST['price_override_reason'] ?? ''));
        $__overridden = $__quoted !== null && is_numeric($__agreedRaw) && abs((float) $__agreedRaw - $__quoted) > 0.004;
        if ($__overridden && ($__reasonError = \App\Helpers\ReasonValidator::check($__overrideReason)) !== null) {
            Flash::set('error', 'Unit price ₹' . number_format((float) $__agreedRaw, 2) . ' is not the quoted ₹' . number_format($__quoted, 2) . ' — give the reason: ' . $__reasonError);
            header("Location: /orders/{$orderId}#supplier-po-form");
            return;
        }
        $__spoId = OrderSupplierPoRepository::create($orderId, $supplierId, $supplierPoReference, [
            'supplier_type'         => $supplierType !== '' ? $supplierType : null,
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
            // Owner decision (2026-10-28): 0% GST is saved as 0, not left blank —
            // PHP treats "0" as empty, which printed "X% — To Be Confirmed".
            'gst_rate_pct'          => trim((string) ($_POST['gst_rate_pct'] ?? '')) !== '' ? trim((string) $_POST['gst_rate_pct']) : null,
            'gst_amount_inr'        => trim((string) ($_POST['gst_amount_inr'] ?? '')) !== '' ? trim((string) $_POST['gst_amount_inr']) : null,
            'total_payable_inr'     => trim((string) ($_POST['total_payable_inr'] ?? '')) ?: null,
            // Owner decision (2026-10-27): 0% advance is saved as 0, not left blank.
            'advance_pct'           => trim((string) ($_POST['advance_pct'] ?? '')) !== '' ? trim((string) $_POST['advance_pct']) : null,
            'advance_amount_inr'    => trim((string) ($_POST['advance_amount_inr'] ?? '')) !== '' ? trim((string) $_POST['advance_amount_inr']) : null,
            'balance_amount_inr'    => trim((string) ($_POST['balance_amount_inr'] ?? '')) ?: null,
            'delivery_location'     => trim((string) ($_POST['delivery_location'] ?? '')) ?: null,
            'required_delivery_date' => trim((string) ($_POST['required_delivery_date'] ?? '')) ?: null,
            'packing_requirement'   => trim((string) ($_POST['packing_requirement'] ?? '')) ?: null,
        ]);
        // External QA report L-23: saving Supplier PO terms was never audit-logged.
        AuditLogRepository::log(AuthService::currentUserId(), 'SUPPLIER_PO_SAVED', 'orders', $orderId, 'supplier_po_reference', null, $supplierPoReference);
        if ($__offer !== null) {
            OrderSupplierPoRepository::setOfferChoice($__spoId, $__offerId, $__quoted, $__overridden ? mb_substr($__overrideReason, 0, 500) : null);
            if ($__overridden) {
                AuditLogRepository::log(AuthService::currentUserId(), 'SUPPLIER_PO_PRICE_OVERRIDE', 'order_supplier_po', $__spoId, 'unit_price_inr',
                    '₹' . number_format($__quoted, 2) . ' quoted by ' . $supplier['supplier_legal_name'], '₹' . number_format((float) $__agreedRaw, 2) . ' agreed', $__overrideReason);
            }
        }

        Flash::set('success', "Supplier PO terms saved ({$supplierPoReference}). Generate the Supplier PO document below.");
        header("Location: /orders/{$orderId}");
    }

    /** Stage 5->6 gate: supplier signs, stamps and returns the Supplier PO. Auto-skips Stage 6 for FOB orders. */
    public function confirmSupplierSigned(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!StageGateService::isUnlocked($orderId, 5)) {
            Flash::set('error', 'Stage 5 has not been unlocked for this order yet — complete Stage 4 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        if (!$supplierPo) {
            Flash::set('error', 'Save the Supplier PO terms and generate the document before confirming signature.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // 90-item backlog #52: the supplier can't have signed and returned a
        // document that was never actually generated — require the SUPPO
        // document to exist (same "generated" bar as recordOcAcknowledgment's
        // OC check below), not just the internal order_supplier_po terms row.
        if (!DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'SUPPO')) {
            Flash::set('error', 'Generate the Supplier PO document for this order before confirming signature.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision (2026-10-25): ...and approved (email off) / sent
        // (email on) — after the more specific "generate it first" checks.
        if ($blockedBy = DocumentGenerationService::stageDocumentBlockedBy($orderId, 5)) {
            Flash::set('error', DocumentGenerationService::stageDocumentBlockedMessage($blockedBy));
            header("Location: /orders/{$orderId}");
            return;
        }
        if (WetSignatureGuardService::supplierPoBlocked((int) $supplierPo['id'])) {
            Flash::set('error', 'Wet-signature required: upload the supplier\'s signed acknowledgment copy before confirming this gate.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderSupplierPoRepository::markSigned((int) $supplierPo['id']);
        // External QA report L-23: supplier PO signature confirmation was never audit-logged.
        AuditLogRepository::log((int) $user['id'], 'SUPPLIER_PO_SIGNED', 'order_supplier_po', (int) $supplierPo['id'], 'supplier_po_reference', null, $supplierPo['supplier_po_reference'] ?? null);
        StageGateService::passAndUnlockNext($orderId, 5, (int) $user['id']);
        StageGateService::maybeAutoSkipFreightStage($orderId, (int) $user['id']);
        Flash::set('success', 'Supplier PO signature confirmed.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * External QA report R-11 (SPO-PAY-01): validates the paid date is a
     * real calendar date, not more than today, and resolves the amount
     * actually paid (required, must be positive) — before this, neither
     * was checked at all: 2026-02-30 crashed with an HTTP 500, a date in
     * 2099 was silently accepted, and no amount was ever captured.
     *
     * @return array{0:string,1:float}|null [date, amount] on success, or
     *   null after already setting an error flash + redirecting.
     */
    private function validateSupplierPoPayment(int $orderId): ?array
    {
        $date = trim((string) ($_POST['paid_date'] ?? ''));
        if ($date === '') {
            $date = date('Y-m-d');
        } elseif (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            Flash::set('error', 'Enter a valid paid date.');
            header("Location: /orders/{$orderId}");
            return null;
        } elseif ($date > date('Y-m-d')) {
            Flash::set('error', 'The paid date cannot be in the future.');
            header("Location: /orders/{$orderId}");
            return null;
        }

        $amountRaw = trim((string) ($_POST['amount_paid_inr'] ?? ''));
        if ($amountRaw === '' || !is_numeric($amountRaw) || (float) $amountRaw <= 0) {
            Flash::set('error', 'Enter the amount actually paid.');
            header("Location: /orders/{$orderId}");
            return null;
        }

        return [$date, (float) $amountRaw];
    }

    /**
     * Order-page polish round: this action only ever checked that a
     * Supplier PO row existed — never that Stage 5 was actually
     * unlocked, the same isUnlocked(5) gate confirmSupplierSigned() just
     * above already requires. A direct POST on an order still at Stage 1
     * could mark the supplier's advance as paid before Stage 4 even
     * passed.
     */
    public function markSupplierAdvancePaid(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        // Supplier payments stay valid after Stage 5 passes — the balance is
        // paid later, before packing (Step 10a).
        if (!StageGateService::isReachedOrPassed($orderId, 5)) {
            Flash::set('error', 'Stage 5 has not been unlocked for this order yet — complete Stage 4 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        if (!$supplierPo) {
            Flash::set('error', 'No Supplier PO found for this order.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $validated = $this->validateSupplierPoPayment($orderId);
        if ($validated === null) {
            return;
        }
        [$date, $amount] = $validated;
        OrderSupplierPoRepository::markAdvancePaid((int) $supplierPo['id'], $date, (int) $user['id'], $amount);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log((int) $user['id'], 'SUPPLIER_ADVANCE_PAID', 'order_supplier_po', (int) $supplierPo['id'], 'advance_paid_date', null, $date);
        Flash::set('success', 'Supplier advance payment recorded.');
        header("Location: /orders/{$orderId}");
    }

    /** Order-page polish round: same missing isUnlocked(5) gate as markSupplierAdvancePaid() above — see its docblock. */
    public function markSupplierBalancePaid(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        // Supplier payments stay valid after Stage 5 passes — the balance is
        // paid later, before packing (Step 10a).
        if (!StageGateService::isReachedOrPassed($orderId, 5)) {
            Flash::set('error', 'Stage 5 has not been unlocked for this order yet — complete Stage 4 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        if (!$supplierPo) {
            Flash::set('error', 'No Supplier PO found for this order.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner report (2026-10-27): the supplier advance was never recorded
        // — the balance waits for it whenever the terms plan an advance.
        if ((float) ($supplierPo['advance_amount_inr'] ?? 0) > 0 && empty($supplierPo['advance_paid_at'])) {
            Flash::set('error', 'Record the supplier advance (Step 7e) first — the Supplier PO terms plan an advance of ₹' . number_format((float) $supplierPo['advance_amount_inr'], 2) . '.');
            header("Location: /orders/{$orderId}#step-7e-supplier-advance-paid");
            return;
        }
        $validated = $this->validateSupplierPoPayment($orderId);
        if ($validated === null) {
            return;
        }
        [$date, $amount] = $validated;
        OrderSupplierPoRepository::markBalancePaid((int) $supplierPo['id'], $date, (int) $user['id'], $amount);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log((int) $user['id'], 'SUPPLIER_BALANCE_PAID', 'order_supplier_po', (int) $supplierPo['id'], 'balance_paid_date', null, $date);
        Flash::set('success', 'Supplier balance payment recorded.');
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
                \App\Helpers\OrderStorage::forReceived($order, 'supplier_po'),
                null,
                $orderId,
                (int) AuthService::currentUser()['id'],
                null,
                'supplier',
                'Supplier PO acknowledgment'
            );
            $documentId = OrderSupplierPoDocumentRepository::attach((int) $supplierPo['id'], $fileId);
            // External QA report N-08: this action never reached the
            // audit log, so it never notified anyone either.
            AuditLogRepository::log((int) AuthService::currentUser()['id'], 'SUPPLIER_PO_DOCUMENT_UPLOADED', 'order_supplier_po_documents', $documentId, null, null, null);
            Flash::set('success', 'Supplier PO acknowledgment attached.');
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
        }
        header("Location: /orders/{$orderId}");
    }

    /**
     * Owner request (2026-10-26, t4): Step 7c — generate (or regenerate) the
     * no-login Supplier Action Link so the supplier can upload their signed
     * PO / acknowledgment themselves; emailed to the supplier when the PO
     * has their email. Staff can still upload it themselves.
     */
    public function generateSupplierActionLink(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        if (!$order || !$supplierPo) {
            Flash::set('error', 'Save the Supplier PO terms before generating a Supplier Action Link.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $rawToken = OrderSupplierPoRepository::generateActionToken((int) $supplierPo['id']);
        $link = rtrim(Env::get('APP_URL', ''), '/') . '/supplier-action/' . $rawToken;
        $email = trim((string) ($supplierPo['supplier_email'] ?? ''));
        $sent = false;
        if ($email !== '') {
            $rendered = EmailTemplateRepository::renderOrDefault(
                'supplier_action_link',
                ['{supplier_po_reference}' => (string) $supplierPo['supplier_po_reference'], '{supplier_action_link}' => $link],
                'NexaCrest — please upload your signed PO {supplier_po_reference}',
                "Hello,\n\n"
                    . "Please sign and stamp purchase order {supplier_po_reference} and upload it here. You can also upload a written note that you accept it:\n{supplier_action_link}\n\n"
                    . "This link works for 60 days.\n\n"
                    . "NexaCrest International Private Limited"
            );
            $sent = EmailService::sendPlainText($email, $rendered['subject'], $rendered['body'], false, 'supplier_links', 'supplier');
        }
        AuditLogRepository::log((int) AuthService::currentUserId(), 'SUPPLIER_ACTION_LINK_GENERATED', 'order_supplier_po', (int) $supplierPo['id'], null, null, null, 'Supplier Action link generated' . ($sent ? ' and emailed to the supplier' : ($email !== '' ? ' — the e-mail was not sent, hand this link over directly' : ' — no supplier email on the PO, hand this link over directly')));
        // QA batch 3 MAIL-2: "emailed" only when the e-mail really went out.
        Flash::set('success', \App\Services\EmailDispatchService::linkMailOutcome('Supplier Action link', $email !== '' ? $email : null, $sent, $link, 'supplier_links', 'supplier'), $link);
        header('Location: ' . self::linkReturnUrl($orderId, 'step-7c-supplier-po-attach'));
    }

    /** Owner request (2026-10-26, t4): Step 7c — the supplier did not acknowledge the PO; reason required, audit-logged. */
    public function skipSupplierPoAck(array $params): void
    {
        $orderId = (int) $params['id'];
        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        if (!$supplierPo) {
            Flash::set('error', 'Save the Supplier PO terms first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $reason = trim((string) ($_POST['skip_reason'] ?? ''));
        if (($error = ReasonValidator::check($reason)) !== null) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}#step-7c-supplier-po-attach");
            return;
        }
        $userId = (int) AuthService::currentUserId();
        OrderSupplierPoRepository::markAckSkipped((int) $supplierPo['id'], $userId, $reason);
        AuditLogRepository::log($userId, 'SUPPLIER_PO_ACK_SKIPPED', 'order_supplier_po', (int) $supplierPo['id'], 'ack_skipped_at', null, date('Y-m-d H:i:s'), $reason);
        Flash::set('success', 'Recorded that the supplier did not acknowledge — Step 7d (confirm the PO) is now open.');
        header("Location: /orders/{$orderId}#step-7d-supplier-po-signed");
    }

    /**
     * Stage 6 (CFR/CIF only): record NexaCrest's agreed freight/insurance
     * terms before generating the FDN. Order-page polish round: this
     * action had no stage check at all — on an order still at Stage 1,
     * a direct POST with just manage_shipping could save freight terms
     * before Stage 5 ever passed. Mirrors the same isUnlocked(6) guard
     * recordFreightPayment()/clearFreightPayment() already use below.
     */
    public function saveFreightTerms(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!StageGateService::isUnlocked($orderId, 6)) {
            Flash::set('error', 'Stage 6 has not been unlocked for this order yet — complete Stage 5 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner request (2026-10-27): the forwarder is picked from the
        // Logistics Partners directory (like the supplier in 7a from the
        // Suppliers directory); its name and contact are copied onto this order.
        // QA Round 5, R5-29: a wrong number is refused with a message, never a database error.
        $numberError = FieldValidator::decimal(trim((string) ($_POST['confirmed_freight_rate'] ?? '')), 'Confirmed freight rate')
            ?? FieldValidator::decimal(trim((string) ($_POST['insurance_amount'] ?? '')), 'Insurance amount');
        if ($numberError !== null) {
            Flash::set('error', $numberError);
            header("Location: /orders/{$orderId}#step-9a-freight-terms");
            return;
        }
        $partner = \App\Repositories\LogisticsPartnerRepository::find((int) ($_POST['freight_forwarder_partner_id'] ?? 0));
        if (!$partner || !$partner['is_active']) {
            Flash::set('error', 'Choose the freight forwarder from the Logistics Partners directory.');
            header("Location: /orders/{$orderId}#step-9a-freight-terms");
            return;
        }
        $contact = implode(' · ', array_filter([
            $partner['contact_person_name'] ?? null,
            $partner['contact_person_phone'] ?: ($partner['phone'] ?? null),
            $partner['email'] ?? null,
        ]));
        OrderFreightRepository::upsert($orderId, [
            'confirmed_freight_rate'    => trim((string) ($_POST['confirmed_freight_rate'] ?? '')) ?: null,
            // Q-R5-2: only a CIF seller insures — nothing is stored for FOB/CFR.
            'insurance_amount'          => strtoupper((string) (OrderRepository::find($orderId)['incoterm_code'] ?? '')) === 'CIF'
                ? (trim((string) ($_POST['insurance_amount'] ?? '')) ?: null)
                : null,
            'freight_forwarder_name'    => $partner['partner_name'],
            'freight_forwarder_contact' => $contact !== '' ? $contact : null,
            'freight_forwarder_partner_id' => (int) $partner['id'],
        ]);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ORDER_FREIGHT_TERMS_SAVED', 'order_freight', $orderId, null, null, null);
        Flash::set('success', 'Freight & insurance terms saved. Generate the Debit Note below.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * External QA report L-35 (OOS-01): on an order still at Stage 1,
     * this would accept a freight payment with no stage check at all —
     * freight is a Stage 6 ("Freight Payment") activity, so Stage 6 must
     * actually be unlocked first (Stage 5 — Supplier PO — passed).
     */
    /** HNT-003: the recording below runs while this order's payment lock is held (PartPaymentService::claimOrderPayments). */
    public function recordFreightPayment(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!\App\Services\PartPaymentService::claimOrderPayments($orderId)) {
            \App\Helpers\Flash::set('error', \App\Services\PartPaymentService::BUSY_MESSAGE);
            header("Location: /orders/{$orderId}");
            return;
        }
        try {
            $this->recordFreightPaymentLocked($params);
        } finally {
            \App\Services\PartPaymentService::releaseOrderPayments($orderId);
        }
    }

    private function recordFreightPaymentLocked(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!StageGateService::isUnlocked($orderId, 6)) {
            Flash::set('error', 'Stage 6 has not been unlocked for this order yet — complete Stage 5 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $amount = (float) ($_POST['freight_amount'] ?? 0);
        $receivedAt = trim((string) ($_POST['freight_received_at'] ?? '')) ?: date('Y-m-d');
        if ((OrderPaymentStatusRepository::find($orderId) ?? [])['freight_cleared_at'] ?? null) {
            Flash::set('error', \App\Services\PartPaymentService::clearedMessage('freight'));
            header("Location: /orders/{$orderId}");
            return;
        }
        if ($amount <= 0) {
            Flash::set('error', 'Enter the freight & insurance amount actually received.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision D-244 (4 Nov 2026): the INR the bank credited is entered and the rate is worked
        // out from it; the bank's rate on the advice, when given, checks it (₹1 tolerance).
        $inr = \App\Services\RemittanceInr::fromPost($_POST, 'freight_', $amount, (string) ((OrderRepository::find($orderId) ?? [])['currency_code'] ?? ''));
        if (isset($inr['error'])) {
            Flash::set('error', $inr['error']);
            header("Location: /orders/{$orderId}");
            return;
        }
        $rate = (float) $inr['rate'];
        $bankRef = trim((string) ($_POST['freight_bank_reference'] ?? ''));
        if ($error = \App\Helpers\FieldValidator::moneyAmount($amount, 'The freight amount') ?? \App\Helpers\FieldValidator::exchangeRate($rate) ?? \App\Helpers\FieldValidator::bankReference($bankRef)) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner 2 Nov 2026 ("18 yes"): an amount that differs from what is still due says why.
        if (\App\Services\PartPaymentService::receiptExists($orderId, 'freight', null, $amount, $receivedAt, $rate, $inr['inr'], $bankRef)) {
            Flash::set('warning', 'Freight remittance already recorded with these details — nothing changed.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $currency = (string) ((OrderRepository::find($orderId) ?? [])['currency_code'] ?? '');
        $diffEval = \App\Services\RemittanceDifference::evaluatePosted($_POST, 'Freight', $currency, (float) (\App\Services\PartPaymentService::expected($orderId, 'freight') ?? 0.0),
            \App\Services\PartPaymentService::receivedTotal($orderId, 'freight'), $amount, self::postedBankCharges());
        if (isset($diffEval['error'])) {
            Flash::set('error', $diffEval['error']);
            header("Location: /orders/{$orderId}");
            return;
        }
        // QA Round 6, R6-N22: saved together or not at all.
        $recorded = \App\Config\Database::transaction(static function () use ($orderId, $amount, $receivedAt, $rate, $bankRef, $diffEval, $inr): bool {
            // QA Round 6, R6-N17: a second click with the same details records and logs nothing more.
            // Gap list G-5 (owner, 1 Nov 2026): each remittance is its own receipt; the leg keeps the total.
            if (\App\Services\PartPaymentService::receiptExists($orderId, 'freight', null, $amount, $receivedAt, $rate, $inr['inr'], $bankRef)) {
                return false;
            }
            $receiptId = \App\Services\PartPaymentService::addLegReceipt($orderId, 'freight', $amount, $receivedAt, $rate, $bankRef, (int) AuthService::currentUserId(), $diffEval['reason'], (float) $diffEval['charges'], $inr);
            \App\Services\RemittanceDifference::afterReceipt($orderId, 'freight', null, $receiptId, $diffEval, $amount, $rate, $receivedAt, $bankRef, (int) AuthService::currentUserId());
            AuditLogRepository::log(AuthService::currentUserId(), 'CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', $orderId, 'freight_exchange_rate', null, (string) $rate);
            // External QA report N-08: this action never reached the audit
            // log, so it never notified anyone either.
            AuditLogRepository::log(AuthService::currentUserId(), 'FREIGHT_PAYMENT_RECORDED', 'order_payment_status', $orderId, 'freight_amount', null, (string) $amount);
            return true;
        });
        if (!$recorded) {
            Flash::set('warning', 'Freight remittance already recorded with these details — nothing changed.');
            header("Location: /orders/{$orderId}");
            return;
        }
        Flash::set('success', trim($this->remittanceStatement($orderId, $amount, $rate, $inr) . ' ' . \App\Services\RemittanceDifference::outcome('freight', 'Freight', $currency, $diffEval, $amount, $rate)) . ' ' . 'Freight remittance recorded. Mark it cleared once your bank confirms receipt.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 6->7 gate: freight & insurance payment cleared in NexaCrest's bank account. */
    public function clearFreightPayment(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!StageGateService::isUnlocked($orderId, 6)) {
            Flash::set('error', 'Stage 6 has not been unlocked for this order yet — complete Stage 5 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision (2026-10-25): this stage completes only once its
        // document is approved (email off) / sent (email on).
        if ($blockedBy = DocumentGenerationService::stageDocumentBlockedBy($orderId, 6)) {
            Flash::set('error', DocumentGenerationService::stageDocumentBlockedMessage($blockedBy));
            header("Location: /orders/{$orderId}");
            return;
        }
        // Gap list G-5 (owner, 1 Nov 2026): not cleared while money is still owed, unless a manager approves it with a reason.
        $partCheck = \App\Services\PartPaymentService::clearCheck($orderId, 'freight', null, $user, (string) ($_POST['short_reason'] ?? ''), (string) ($_POST['short_kind'] ?? ''));
        if (isset($partCheck['error'])) {
            Flash::set('error', $partCheck['error']);
            header("Location: /orders/{$orderId}");
            return;
        }
        $clearedAt = trim((string) ($_POST['freight_cleared_at'] ?? '')) ?: date('Y-m-d');
        OrderPaymentStatusRepository::markFreightCleared($orderId, $clearedAt, (int) $user['id']);
        $shortNote = $partCheck['short'] !== null ? ' ' . \App\Services\PartPaymentService::recordShortfall($orderId, 'freight', null, $partCheck['short'], $user) : '';
        \App\Services\AssociatePayoutService::afterPaymentChange($orderId, (int) $user['id']); // D-264: fixed by itself once the buyer has paid in full
        StageGateService::passAndUnlockNext($orderId, 6, (int) $user['id']);
        Flash::set('success', 'Freight payment cleared. Stage 7 (Packing & BL Instruction) unlocked.' . $shortNote);
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
        // External QA report L-35 (OOS-01): had no stage check at all —
        // packing is a Stage 7 activity (see this method's own docblock),
        // so Stage 7 must actually be unlocked first (Stage 6 — freight
        // payment cleared — passed).
        if (!StageGateService::isUnlocked($orderId, 7)) {
            Flash::set('error', 'Stage 7 has not been unlocked for this order yet — complete Stage 6 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $actualQtyRaw = trim((string) ($_POST['actual_quantity_packed'] ?? ''));
        $actualQty = $actualQtyRaw !== '' ? (float) $actualQtyRaw : null;

        $summary = OrderProductRepository::orderedQuantitySummary($orderId);
        // D-262: the tolerance frozen with the order once its PI is approved (what the buyer agreed to), else today's setting.
        $tolerance = \App\Services\OrderSnapshotService::tolerancePct(OrderRepository::find($orderId) ?? []);
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
            'actual_quantity_unit'   => trim((string) ($_POST['actual_quantity_unit'] ?? '')) ?: ($summary['comparable'] ? $summary['unit'] : null),
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
                    \App\Helpers\OrderStorage::forReceived($order, 'packing_bl'),
                    null,
                    $orderId,
                    (int) AuthService::currentUser()['id'],
                    null,
                    'Buyer',
                    'Quantity shortfall approval'
                );
                OrderPackingRepository::attachBuyerApproval($orderId, $fileId);
            } catch (\Throwable $e) {
                Flash::set('error', 'Packing figures saved, but the approval upload failed: ' . Flash::describeException($e, 'upload failed — check the server error log.'));
                header("Location: /orders/{$orderId}");
                return;
            }
        }

        $crateNos = $_POST['crate_no'] ?? [];
        $orderProducts = OrderProductRepository::forOrder($orderId);
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
                // Taken from the crate's product, never from the form (t4).
                'hs_code'             => OrderCrateRepository::hsCodeFor($orderProducts, trim((string) ($_POST['crate_product_description'][$i] ?? '')) ?: null),
            ];
        }
        OrderCrateRepository::replaceForOrder($orderId, $crates);

        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ORDER_PACKING_SAVED', 'order_packing', $orderId, null, null, null);
        Flash::set('success', 'Packing figures and crate breakdown saved. Generate the Packing List below.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Stage 7: record shipping/vessel details, ahead of generating the BL
     * Instruction Sheet.
     *
     * External QA report L-35 (OOS-01): had no stage check at all — an
     * order still at Stage 1 could have vessel/container details stored
     * here. Stage 7 must actually be unlocked first (Stage 6 — freight
     * payment cleared — passed).
     */
    public function saveShipping(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!StageGateService::isUnlocked($orderId, 7)) {
            Flash::set('error', 'Stage 7 has not been unlocked for this order yet — complete Stage 6 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // QA Round 5, R5-28: the server applies the form's own "1 or more" rule.
        if ($countError = FieldValidator::wholeNumber(trim((string) ($_POST['container_count'] ?? '')), 'Number of containers', 1)) {
            Flash::set('error', $countError);
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderShippingRepository::upsert($orderId, [
            'shipping_line'  => trim((string) ($_POST['shipping_line'] ?? '')) ?: null,
            'vessel_name'    => trim((string) ($_POST['vessel_name'] ?? '')) ?: null,
            'voyage_number'  => trim((string) ($_POST['voyage_number'] ?? '')) ?: null,
            'etd'            => trim((string) ($_POST['etd'] ?? '')) ?: null,
            'eta'            => trim((string) ($_POST['eta'] ?? '')) ?: null,
            'container_type'  => trim((string) ($_POST['ship_container_type'] ?? '')) ?: null,
            'container_count' => trim((string) ($_POST['container_count'] ?? '')) ?: null,
            'container_no'    => trim((string) ($_POST['container_no'] ?? '')) ?: null,
            'seal_no'         => trim((string) ($_POST['seal_no'] ?? '')) ?: null,
        ]);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ORDER_SHIPPING_DETAILS_SAVED', 'order_shipping', $orderId, null, null, null);
        Flash::set('success', 'Shipping details saved. Generate the BL Instruction Sheet below.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Export process steps 9h-9j: the BL Instruction Sheet + packing
     * list have been sent to the CHA — this logs the draft BL the CHA
     * sent back, as the next review round for this order. Refuses a new
     * upload while the current round is still 'pending' — the existing
     * draft must be approved or sent back for revision first, so at
     * most one round is ever awaiting a decision at a time.
     */
    public function uploadDraftBl(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();

        $latest = OrderDraftBlReviewRepository::latestForOrder($orderId);
        if ($latest && $latest['status'] === 'pending') {
            Flash::set('error', "Round {$latest['round_number']}'s draft is still awaiting your review — approve it or request a revision before logging another.");
            header("Location: /orders/{$orderId}");
            return;
        }

        if (empty($_FILES['draft_bl']['name'])) {
            Flash::set('error', 'Attach the draft BL the CHA sent before logging this round.');
            header("Location: /orders/{$orderId}");
            return;
        }

        try {
            $fileId = FileUploadService::handleUpload(
                'draft_bl',
                'draft_bl',
                \App\Helpers\OrderStorage::forReceived($order, 'packing_bl'),
                null,
                $orderId,
                (int) $user['id'],
                null,
                'CHA',
                'Draft Bill of Lading'
            );
        } catch (\Throwable $e) {
            Flash::set('error', 'Draft BL upload failed: ' . Flash::describeException($e, 'upload failed — check the server error log.'));
            header("Location: /orders/{$orderId}");
            return;
        }

        $roundId = OrderDraftBlReviewRepository::createRound($orderId, $fileId, (int) $user['id']);
        OrderShippingRepository::attachDraftBl($orderId, $fileId);
        AuditLogRepository::log((int) $user['id'], 'DRAFT_BL_UPLOADED', 'order_draft_bl_reviews', $roundId, null, null, (string) $orderId);
        Flash::set('success', 'Draft BL logged for review.');
        header("Location: /orders/{$orderId}");
    }

    /** Export process step 9j: staff's decision on a CHA draft BL round — approve it, or send it back with notes for a revision. */
    public function reviewDraftBl(array $params): void
    {
        $orderId = (int) $params['id'];
        $roundId = (int) $params['roundId'];
        $round = OrderDraftBlReviewRepository::find($roundId);
        if (!$round || (int) $round['order_id'] !== $orderId) {
            http_response_code(404);
            echo 'Draft BL round not found for this order.';
            return;
        }
        if ($round['status'] !== 'pending') {
            Flash::set('error', 'This round has already been reviewed.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $decision = trim((string) ($_POST['decision'] ?? ''));
        if (!in_array($decision, ['approved', 'revision_requested'], true)) {
            Flash::set('error', 'Select a decision.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        if ($decision === 'revision_requested' && $notes === null) {
            Flash::set('error', 'Explain what needs to change before sending this draft back for revision.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $user = AuthService::currentUser();
        OrderDraftBlReviewRepository::review($roundId, $decision, $notes, (int) $user['id']);
        if ($decision === 'approved') {
            OrderShippingRepository::approveDraftBl($orderId, (int) $user['id']);
        }
        AuditLogRepository::log((int) $user['id'], 'DRAFT_BL_REVIEWED', 'order_draft_bl_reviews', $roundId, 'status', 'pending', $decision);
        Flash::set('success', $decision === 'approved' ? "Round {$round['round_number']} draft BL approved." : "Revision requested on round {$round['round_number']} — upload the CHA's corrected draft once received.");
        header("Location: /orders/{$orderId}");
    }

    /** Stage 7->8 gate: BL issued (vessel departs) — also the date CI must match. */
    public function recordBlIssued(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!StageGateService::isUnlocked($orderId, 7)) {
            Flash::set('error', 'Stage 7 has not been unlocked for this order yet — complete Stage 6 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision (2026-10-25): this stage completes only once its
        // document is approved (email off) / sent (email on).
        if ($blockedBy = DocumentGenerationService::stageDocumentBlockedBy($orderId, 7)) {
            Flash::set('error', DocumentGenerationService::stageDocumentBlockedMessage($blockedBy));
            header("Location: /orders/{$orderId}");
            return;
        }
        $blNumber = trim((string) ($_POST['bl_number'] ?? ''));
        $blDate = trim((string) ($_POST['bl_date'] ?? '')) ?: date('Y-m-d');
        if ($blNumber === '') {
            Flash::set('error', 'BL number is required to confirm this gate.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Gap list G-1 / G-3 (owner, 1 Nov 2026): a "before shipment" balance and a pre-shipment
        // inspection come first; a manager can release the step with a written reason.
        $gateRelease = null;
        if ($blockers = \App\Services\ShipmentGateService::blockers($orderId)) {
            $reason = trim((string) ($_POST['gate_override_reason'] ?? ''));
            $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
            if ($reason === '') {
                Flash::set('error', implode(' ', $blockers) . (PermissionService::can((int) $user['id'], $roleId, 'override_shipment_gates') ? ' Or release the step with a written reason.' : ' A manager can release the step with a written reason.'));
                header("Location: /orders/{$orderId}");
                return;
            }
            if (!PermissionService::can((int) $user['id'], $roleId, 'override_shipment_gates')) {
                Flash::set('error', 'Only a manager with "Override shipment gates" can release the Bill of Lading step.');
                header("Location: /orders/{$orderId}");
                return;
            }
            if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
                Flash::set('error', 'Give the reason for releasing the Bill of Lading step (10 to 500 characters).');
                header("Location: /orders/{$orderId}");
                return;
            }
            $gateRelease = [array_keys($blockers), $reason];
        }
        if ($gateRelease !== null) {
            \App\Services\ShipmentGateService::recordOverride($orderId, $gateRelease[0], $gateRelease[1], (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'SHIPMENT_GATE_OVERRIDDEN', 'orders', $orderId, 'gates', null, implode(',', $gateRelease[0]), $gateRelease[1]);
        }
        OrderShippingRepository::recordBl($orderId, $blNumber, $blDate);
        // External QA report L-23: BL issuance was never audit-logged.
        AuditLogRepository::log((int) $user['id'], 'BL_ISSUED', 'orders', $orderId, 'bl_number', null, $blNumber);

        if (!empty($_FILES['bl_copy']['name'])) {
            $order = OrderRepository::find($orderId);
            try {
                $fileId = FileUploadService::handleUpload(
                    'bl_copy',
                    'bl_copy',
                    \App\Helpers\OrderStorage::forReceived($order, 'packing_bl'),
                    null,
                    $orderId,
                    (int) $user['id'],
                    null,
                    'Shipping Line',
                    'Scanned Bill of Lading'
                );
                OrderShippingRepository::attachBlCopy($orderId, $fileId);
            } catch (\Throwable $e) {
                Flash::set('error', "Bill of Lading recorded ({$blNumber}), but the copy upload failed: " . Flash::describeException($e, 'upload failed — check the server error log.'));
                header("Location: /orders/{$orderId}");
                return;
            }
        }

        StageGateService::passAndUnlockNext($orderId, 7, (int) $user['id']);
        Flash::set('success', "Bill of Lading recorded ({$blNumber}). Stage 8 (Commercial Invoice) unlocked.");
        header("Location: /orders/{$orderId}");
    }

    /**
     * Gap list G-3 (owner, 1 Nov 2026): the third-party pre-shipment
     * inspection's result — Passed or Failed, with its date, agency and
     * report. A Passed inspection resolves the checklist line and lets the
     * Bill of Lading be recorded; a Failed one tells the managers.
     */
    public function recordInspectionResult(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $order = OrderRepository::find($orderId);
        if ($order === null || $order['status'] !== 'active') {
            Flash::set('error', 'The inspection can be recorded only on an active order.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if (!\App\Services\ShipmentGateService::inspectionApplies($orderId)) {
            Flash::set('error', 'This order has no pre-shipment inspection on its compliance checklist (it is marked not applicable).');
            header("Location: /orders/{$orderId}#step-8a-inspection");
            return;
        }
        $result = (string) ($_POST['inspection_result'] ?? '');
        $date = trim((string) ($_POST['inspected_on'] ?? ''));
        $agency = trim((string) ($_POST['agency_name'] ?? ''));
        $note = trim((string) ($_POST['inspection_note'] ?? ''));
        $error = match (true) {
            !in_array($result, ['passed', 'failed'], true) => 'Choose whether the inspection passed or failed.',
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || $date > date('Y-m-d') => 'Enter the date of the inspection (today or earlier).',
            mb_strlen($agency) < 2 || mb_strlen($agency) > 150 => 'Enter the inspection agency (2 to 150 characters).',
            $result === 'failed' && mb_strlen($note) < 10 => 'Say what failed (at least 10 characters).',
            $note !== '' && mb_strlen($note) < 10 => 'Write the note in at least 10 characters, or leave it empty.',
            mb_strlen($note) > 500 => 'The note is too long (up to 500 characters).',
            empty($_FILES['inspection_report']['name']) => 'Attach the inspection report (PDF or image).',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}#step-8a-inspection");
            return;
        }
        try {
            $fileId = FileUploadService::handleUpload('inspection_report', 'inspection_report', \App\Helpers\OrderStorage::forReceived($order, 'packing_bl'), null, $orderId, (int) $user['id'], null, $agency, 'Pre-shipment inspection report');
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
            header("Location: /orders/{$orderId}#step-8a-inspection");
            return;
        }
        \App\Services\ShipmentGateService::saveInspection($order, $result, $date, $agency, $fileId, $note !== '' ? $note : null, (int) $user['id']);
        if ($result === 'failed') {
            Flash::set('warning', 'Inspection recorded as FAILED. The managers were notified; the Bill of Lading waits for a Passed inspection.');
        } else {
            Flash::set('success', 'Inspection recorded as Passed.');
        }
        header("Location: /orders/{$orderId}#step-8a-inspection");
    }

    /** Lets staff attach the scanned BL copy later, when it wasn't available yet at bl-issued time. */
    public function attachBlCopy(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $order = OrderRepository::find($orderId);
        if (!$order) {
            Flash::set('error', 'Order not found.');
            header("Location: /orders/{$orderId}");
            return;
        }
        try {
            $fileId = FileUploadService::handleUpload(
                'bl_copy',
                'bl_copy',
                \App\Helpers\OrderStorage::forReceived($order, 'packing_bl'),
                null,
                $orderId,
                (int) $user['id'],
                null,
                'Shipping Line',
                'Scanned Bill of Lading'
            );
            OrderShippingRepository::attachBlCopy($orderId, $fileId);
            // External QA report N-08: this action never reached the
            // audit log, so it never notified anyone either.
            AuditLogRepository::log((int) $user['id'], 'ORDER_BL_COPY_ATTACHED', 'order_shipping', $orderId, null, null, null);
            Flash::set('success', 'Scanned BL copy attached.');
        } catch (\Throwable $e) {
            Flash::set('error', 'Could not attach the scanned BL copy: ' . Flash::describeException($e, 'upload failed — check the server error log.'));
        }
        header("Location: /orders/{$orderId}");
    }

    /**
     * Owner decision 2026-10-29: records the shipping bill — its number,
     * date and the customs exchange rate printed on it. The CI and Debit
     * Note go to Zoho Books at that rate, so the GST return matches the
     * shipping bill. Can be corrected later; an invoice already in Zoho
     * Books keeps the rate it was sent at.
     */
    public function recordShippingBill(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            Flash::set('error', 'Order not found.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $number = trim((string) ($_POST['shipping_bill_no'] ?? ''));
        $date = trim((string) ($_POST['shipping_bill_date'] ?? ''));
        $rate = trim((string) ($_POST['customs_exchange_rate'] ?? ''));
        $error = null;
        if ($number === '' || mb_strlen($number) > 30) {
            $error = 'Enter the shipping bill number (up to 30 characters).';
        } elseif (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $date > date('Y-m-d')) {
            $error = 'Enter the shipping bill date (not in the future).';
        } elseif (!is_numeric($rate) || round((float) $rate, 4) <= 0 || (float) $rate > 100000) { // QA Round 6, R6-N23: at least 0.0001 once stored
            $error = 'Enter the customs exchange rate printed on the shipping bill (rupees for 1 ' . ($order['currency_code'] ?? 'unit') . ').';
        }
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}#shipping-bill");
            return;
        }
        $before = OrderShippingRepository::find($orderId);
        OrderShippingRepository::recordShippingBill($orderId, $number, $date, (float) $rate);
        AuditLogRepository::log(AuthService::currentUserId(), 'ORDER_SHIPPING_BILL_RECORDED', 'order_shipping', $orderId, 'customs_exchange_rate', $before['customs_exchange_rate'] ?? null, $rate, "Shipping bill {$number} dated {$date}");
        Flash::set('success', "Shipping bill {$number} recorded (customs rate {$rate}).");
        header("Location: /orders/{$orderId}#shipping-bill");
    }

    public function recordScannedBlSent(array $params): void
    {
        $orderId = (int) $params['id'];
        OrderShippingRepository::recordScannedBlSent($orderId);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ORDER_SCANNED_BL_SENT', 'order_shipping', $orderId, null, null, null);
        // Gap list G-2 (owner, 1 Nov 2026): a balance due against the BL (or an LC) is due the order's balance days from today.
        $dueDate = \App\Services\BalanceDueService::startFromScannedBl($orderId, date('Y-m-d'));
        Flash::set('success', 'Scanned BL copy marked as sent to buyer.' . ($dueDate !== null ? ' Balance payment due on ' . \App\Helpers\Dates::short($dueDate) . '.' : ''));
        header("Location: /orders/{$orderId}");
    }

    /** HNT-003: the recording below runs while this order's payment lock is held (PartPaymentService::claimOrderPayments). */
    public function recordBalancePayment(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!\App\Services\PartPaymentService::claimOrderPayments($orderId)) {
            \App\Helpers\Flash::set('error', \App\Services\PartPaymentService::BUSY_MESSAGE);
            header("Location: /orders/{$orderId}");
            return;
        }
        try {
            $this->recordBalancePaymentLocked($params);
        } finally {
            \App\Services\PartPaymentService::releaseOrderPayments($orderId);
        }
    }

    private function recordBalancePaymentLocked(array $params): void
    {
        $orderId = (int) $params['id'];
        // Gap list G-1 (owner, 1 Nov 2026): a "before shipment" balance is recorded from the
        // freight stage on, against the PI — no Commercial Invoice needed yet.
        $early = \App\Services\ShipmentGateService::balanceOpenEarly($orderId);
        // 90-item backlog #53: this action had no stage gate at all (unlike
        // clearBalancePayment just below, which does check Stage 8) and no
        // check that the Commercial Invoice was ever actually generated —
        // the buyer can't be remitting against an invoice they were never
        // sent. Same "generated" bar as confirmSupplierSigned's SUPPO check
        // and recordOcAcknowledgment's OC check above.
        if (!$early && !StageGateService::isUnlocked($orderId, 8)) {
            Flash::set('error', 'Stage 8 has not been unlocked for this order yet.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if (!$early && !DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'CI')) {
            Flash::set('error', 'Generate the Commercial Invoice for this order before recording the balance remittance.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $amount = (float) ($_POST['balance_amount'] ?? 0);
        $receivedAt = trim((string) ($_POST['balance_received_at'] ?? '')) ?: date('Y-m-d');
        if ((OrderPaymentStatusRepository::find($orderId) ?? [])['balance_cleared_at'] ?? null) {
            Flash::set('error', \App\Services\PartPaymentService::clearedMessage('balance'));
            header("Location: /orders/{$orderId}");
            return;
        }
        if ($amount <= 0) {
            Flash::set('error', 'Enter the balance amount actually received.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision D-244 (4 Nov 2026): the INR the bank credited is entered and the rate is worked
        // out from it; the bank's rate on the advice, when given, checks it (₹1 tolerance).
        $inr = \App\Services\RemittanceInr::fromPost($_POST, 'balance_', $amount, (string) ((OrderRepository::find($orderId) ?? [])['currency_code'] ?? ''));
        if (isset($inr['error'])) {
            Flash::set('error', $inr['error']);
            header("Location: /orders/{$orderId}");
            return;
        }
        $rate = (float) $inr['rate'];
        $bankRef = trim((string) ($_POST['balance_bank_reference'] ?? ''));
        if ($error = \App\Helpers\FieldValidator::moneyAmount($amount, 'The balance amount') ?? \App\Helpers\FieldValidator::exchangeRate($rate) ?? \App\Helpers\FieldValidator::bankReference($bankRef)) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner 2 Nov 2026 ("18 yes"): an amount that differs from what is still due says why.
        if (\App\Services\PartPaymentService::receiptExists($orderId, 'balance', null, $amount, $receivedAt, $rate, $inr['inr'], $bankRef)) {
            Flash::set('warning', 'Balance remittance already recorded with these details — nothing changed.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $currency = (string) ((OrderRepository::find($orderId) ?? [])['currency_code'] ?? '');
        $diffEval = \App\Services\RemittanceDifference::evaluatePosted($_POST, 'Balance', $currency, (float) (\App\Services\PartPaymentService::expected($orderId, 'balance') ?? 0.0),
            \App\Services\PartPaymentService::receivedTotal($orderId, 'balance'), $amount, self::postedBankCharges());
        if (isset($diffEval['error'])) {
            Flash::set('error', $diffEval['error']);
            header("Location: /orders/{$orderId}");
            return;
        }
        // QA Round 6, R6-N22: saved together or not at all.
        $recorded = \App\Config\Database::transaction(static function () use ($orderId, $amount, $receivedAt, $rate, $bankRef, $diffEval, $inr): bool {
            // QA Round 6, R6-N17: a second click with the same details records and logs nothing more.
            // Gap list G-5 (owner, 1 Nov 2026): each remittance is its own receipt; the leg keeps the total.
            if (\App\Services\PartPaymentService::receiptExists($orderId, 'balance', null, $amount, $receivedAt, $rate, $inr['inr'], $bankRef)) {
                return false;
            }
            $receiptId = \App\Services\PartPaymentService::addLegReceipt($orderId, 'balance', $amount, $receivedAt, $rate, $bankRef, (int) AuthService::currentUserId(), $diffEval['reason'], (float) $diffEval['charges'], $inr);
            \App\Services\RemittanceDifference::afterReceipt($orderId, 'balance', null, $receiptId, $diffEval, $amount, $rate, $receivedAt, $bankRef, (int) AuthService::currentUserId());
            AuditLogRepository::log(AuthService::currentUserId(), 'CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', $orderId, 'balance_exchange_rate', null, (string) $rate);
            // External QA report L-23: balance payment recording was never audit-logged.
            AuditLogRepository::log(AuthService::currentUserId(), 'BALANCE_PAYMENT_RECORDED', 'order_payment_status', $orderId, 'balance_amount', null, (string) $amount);
            return true;
        });
        if (!$recorded) {
            Flash::set('warning', 'Balance remittance already recorded with these details — nothing changed.');
            header("Location: /orders/{$orderId}");
            return;
        }
        Flash::set('success', trim($this->remittanceStatement($orderId, $amount, $rate, $inr) . ' ' . \App\Services\RemittanceDifference::outcome('balance', 'Balance', $currency, $diffEval, $amount, $rate)) . ' ' . 'Balance remittance recorded. Mark it cleared once your bank confirms receipt.');
        header("Location: /orders/{$orderId}");
    }

    /** Stage 8->9 gate: 60% balance payment cleared — triggers COO prep / document despatch / closure. */
    public function clearBalancePayment(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $stage8 = StageGateService::isUnlocked($orderId, 8);
        // Gap list G-1 (owner, 1 Nov 2026): a "before shipment" balance is cleared before the
        // Bill of Lading; Stage 8 is passed later, once its Commercial Invoice is approved/sent.
        $early = !$stage8 && \App\Services\ShipmentGateService::balanceOpenEarly($orderId);
        if (!$stage8 && !$early) {
            Flash::set('error', 'Stage 8 has not been unlocked for this order yet — complete Stage 7 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision (2026-10-25): this stage completes only once its
        // document is approved (email off) / sent (email on).
        if ($stage8 && $blockedBy = DocumentGenerationService::stageDocumentBlockedBy($orderId, 8)) {
            Flash::set('error', DocumentGenerationService::stageDocumentBlockedMessage($blockedBy));
            header("Location: /orders/{$orderId}");
            return;
        }
        $alreadyCleared = OrderPaymentStatusRepository::find($orderId)['balance_cleared_at'] ?? null;
        if ($alreadyCleared !== null) {
            if ($early) {
                Flash::set('error', 'The balance is already cleared.');
            } else {
                StageGateService::passAndUnlockNext($orderId, 8, (int) $user['id']);
                AuditLogRepository::log((int) $user['id'], 'STAGE_8_COMPLETED_BALANCE_CLEARED_EARLIER', 'orders', $orderId, 'balance_cleared_at', null, substr((string) $alreadyCleared, 0, 10));
                Flash::set('success', 'Step 11 complete — the balance was cleared before shipment on ' . \App\Helpers\Dates::short(substr((string) $alreadyCleared, 0, 10)) . '. Stage 9 (Document Despatch & Closure) unlocked.');
            }
            header("Location: /orders/{$orderId}");
            return;
        }
        // QA-5 (GATE-04): being unlocked only proves Stage 7 was passed —
        // it says nothing about whether a balance amount was ever actually
        // recorded (normally set automatically by clearAdvancePayment(),
        // but an admin stage override could reach Stage 8 without it).
        // Without this, clearing a never-recorded (NULL) balance would
        // still unlock Stage 9.
        $existingPayment = OrderPaymentStatusRepository::find($orderId);
        if (!$existingPayment || $existingPayment['balance_amount'] === null) {
            Flash::set('error', 'The balance amount has not been recorded for this order yet — it cannot be marked cleared.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // External QA report N-05: clearAdvancePayment() pre-fills
        // balance_amount automatically, so the check above alone lets a
        // user clear the balance without ever actually recording the
        // remittance via recordBalancePayment() — balance_remittance_
        // received_at then stays NULL forever, and every later export-
        // benefit claim is refused with a misleading message (see
        // OrderFinancialsController::addExportBenefit's own gate on
        // that field).
        if ($existingPayment['balance_remittance_received_at'] === null) {
            Flash::set('error', 'Record the balance remittance before marking it cleared.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision D-215 (5a): every milestone between the advance and the balance is cleared first.
        if ($open = \App\Repositories\PaymentMilestoneRepository::notCleared($orderId)) {
            Flash::set('error', 'Clear payment milestone ' . $open[0]['seq'] . ' (' . rtrim(rtrim(number_format((float) $open[0]['pct'], 2, '.', ''), '0'), '.') . '% ' . $open[0]['due_text'] . ') before the balance.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Gap list G-5 (owner, 1 Nov 2026): not cleared while money is still owed, unless a manager approves it with a reason.
        $partCheck = \App\Services\PartPaymentService::clearCheck($orderId, 'balance', null, $user, (string) ($_POST['short_reason'] ?? ''), (string) ($_POST['short_kind'] ?? ''));
        if (isset($partCheck['error'])) {
            Flash::set('error', $partCheck['error']);
            header("Location: /orders/{$orderId}");
            return;
        }
        $clearedAt = trim((string) ($_POST['balance_cleared_at'] ?? '')) ?: date('Y-m-d');
        OrderPaymentStatusRepository::markBalanceCleared($orderId, $clearedAt, (int) $user['id']);
        $shortNote = $partCheck['short'] !== null ? ' ' . \App\Services\PartPaymentService::recordShortfall($orderId, 'balance', null, $partCheck['short'], $user) : '';
        \App\Services\AssociatePayoutService::afterPaymentChange($orderId, (int) $user['id']); // D-264: fixed by itself once the buyer has paid in full
        // External QA report L-23: balance payment clearing was never audit-logged.
        AuditLogRepository::log((int) $user['id'], 'BALANCE_PAYMENT_CLEARED', 'order_payment_status', $orderId, 'balance_cleared_at', null, $clearedAt);
        if ($early) {
            Flash::set('success', 'Balance payment cleared before shipment. The Bill of Lading (Step 10f) can now be recorded; Step 11 completes once the Commercial Invoice is approved.' . $shortNote);
            header("Location: /orders/{$orderId}");
            return;
        }
        StageGateService::passAndUnlockNext($orderId, 8, (int) $user['id']);
        Flash::set('success', 'Balance payment cleared. Stage 9 (Document Despatch & Closure) unlocked.' . $shortNote);
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
        $paymentStatus = OrderPaymentStatusRepository::find($orderId);
        if (!$this->legAmountRecorded($paymentStatus, 'advance')) {
            Flash::set('error', 'Record the advance payment itself before entering its INR actual amount.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($paymentStatus['advance_cleared_at'] ?? null, (int) $user['id'], $roleId, 'order_payment_status', $orderId, 'advance_inr_actual')) {
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
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        // QA Round 6, R6-N04: nothing to remove is not a removal.
        if (((OrderPaymentStatusRepository::find($orderId) ?? [])['advance_inr_actual'] ?? null) === null) {
            Flash::set('error', 'No advance INR actual amount is recorded on this order — nothing was removed.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if (!CaFyLockGuard::allow((OrderPaymentStatusRepository::find($orderId) ?? [])['advance_cleared_at'] ?? null, (int) $user['id'], $roleId, 'order_payment_status', $orderId, 'advance_inr_actual')) {
            header("Location: /orders/{$orderId}");
            return;
        }
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
        $paymentStatus = OrderPaymentStatusRepository::find($orderId);
        if (!$this->legAmountRecorded($paymentStatus, 'balance')) {
            Flash::set('error', 'Record the balance payment itself before entering its INR actual amount.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($paymentStatus['balance_cleared_at'] ?? null, (int) $user['id'], $roleId, 'order_payment_status', $orderId, 'balance_inr_actual')) {
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
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        // QA Round 6, R6-N04: nothing to remove is not a removal.
        if (((OrderPaymentStatusRepository::find($orderId) ?? [])['balance_inr_actual'] ?? null) === null) {
            Flash::set('error', 'No balance INR actual amount is recorded on this order — nothing was removed.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if (!CaFyLockGuard::allow((OrderPaymentStatusRepository::find($orderId) ?? [])['balance_cleared_at'] ?? null, (int) $user['id'], $roleId, 'order_payment_status', $orderId, 'balance_inr_actual')) {
            header("Location: /orders/{$orderId}");
            return;
        }
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
        $paymentStatus = OrderPaymentStatusRepository::find($orderId);
        if (!$this->legAmountRecorded($paymentStatus, 'freight')) {
            Flash::set('error', 'Record the freight payment itself before entering its INR actual amount.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($paymentStatus['freight_cleared_at'] ?? null, (int) $user['id'], $roleId, 'order_payment_status', $orderId, 'freight_inr_actual')) {
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
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        // QA Round 6, R6-N04: nothing to remove is not a removal.
        if (((OrderPaymentStatusRepository::find($orderId) ?? [])['freight_inr_actual'] ?? null) === null) {
            Flash::set('error', 'No freight INR actual amount is recorded on this order — nothing was removed.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if (!CaFyLockGuard::allow((OrderPaymentStatusRepository::find($orderId) ?? [])['freight_cleared_at'] ?? null, (int) $user['id'], $roleId, 'order_payment_status', $orderId, 'freight_inr_actual')) {
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderPaymentStatusRepository::clearFreightInrActual($orderId);
        AuditLogRepository::log((int) $user['id'], 'CA_INR_ACTUAL_DELETED', 'order_payment_status', $orderId, 'freight_inr_actual');
        Flash::set('success', 'Freight INR actual amount removed.');
        header("Location: /orders/{$orderId}");
    }

    // ----------------------------------------------------------------
    // CA / Accounting module (Phase 2, Batch 3 #3) — exchange rate, one
    // per settlement leg (advance/balance/freight), for the register's
    // forex gain/loss column — each leg can genuinely clear on a
    // different date with a different market rate, so a single
    // order-wide rate (the original Phase 2 design) overstated/
    // understated gain/loss for whichever leg didn't match it. Same
    // inr_actual_edit gating as Phase 1.
    // ----------------------------------------------------------------

    /**
     * External QA report L-35 (OOS-01): "the advance FIRC, exchange rate
     * and INR-credited amount (the advance itself was never recorded)" —
     * none of these 3 per-leg CA fields checked that the leg's own
     * payment (advance/balance/freight_amount) had actually been
     * recorded first. Shared by every FIRC/exchange-rate/INR-actual
     * handler below.
     */
    private function legAmountRecorded(?array $paymentStatus, string $leg): bool
    {
        return $paymentStatus !== null && $paymentStatus[$leg . '_amount'] !== null;
    }

    public function recordAdvanceExchangeRate(array $params): void
    {
        $this->recordLegExchangeRate((int) $params['id'], 'advance');
    }

    public function recordBalanceExchangeRate(array $params): void
    {
        $this->recordLegExchangeRate((int) $params['id'], 'balance');
    }

    public function recordFreightExchangeRate(array $params): void
    {
        $this->recordLegExchangeRate((int) $params['id'], 'freight');
    }

    private function recordLegExchangeRate(int $orderId, string $leg): void
    {
        $user = AuthService::currentUser();
        $rate = (float) ($_POST[$leg . '_exchange_rate'] ?? 0);
        if ($rate <= 0) {
            Flash::set('error', 'Enter the exchange rate for this leg.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if ($error = \App\Helpers\FieldValidator::exchangeRate($rate)) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}");
            return;
        }
        $paymentStatus = OrderPaymentStatusRepository::find($orderId);
        if (!$this->legAmountRecorded($paymentStatus, $leg)) {
            Flash::set('error', "Record the {$leg} payment itself before entering its exchange rate.");
            header("Location: /orders/{$orderId}");
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $clearedAt = $paymentStatus[$leg . '_cleared_at'] ?? null;
        if (!CaFyLockGuard::allow($clearedAt, (int) $user['id'], $roleId, 'order_payment_status', $orderId, $leg . '_exchange_rate')) {
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner decision (2026-10-26, t4): the rate is recorded together with
        // the remittance; changing it afterwards is a correction of a typing
        // mistake and needs a reason, kept in the audit log.
        $previous = $paymentStatus[$leg . '_exchange_rate'] ?? null;
        $reason = trim((string) ($_POST['correction_reason'] ?? ''));
        if ($previous !== null) {
            $error = ReasonValidator::check($reason);
            if ($error !== null) {
                Flash::set('error', 'Correcting a recorded exchange rate needs a reason. ' . $error);
                header("Location: /orders/{$orderId}");
                return;
            }
        }
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, $leg, $rate, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], $previous !== null ? 'CA_EXCHANGE_RATE_CORRECTED' : 'CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', $orderId, $leg . '_exchange_rate', $previous !== null ? (string) $previous : null, (string) $rate, $previous !== null ? $reason : null);
        Flash::set('success', ucfirst($leg) . ($previous !== null ? ' exchange rate corrected.' : ' exchange rate recorded.'));
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
        $paymentStatus = OrderPaymentStatusRepository::find($orderId);
        if (!$this->legAmountRecorded($paymentStatus, 'advance')) {
            Flash::set('error', 'Record the advance payment itself before entering its FIRC/eBRC reference.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($paymentStatus['advance_cleared_at'] ?? null, (int) $user['id'], $roleId, 'order_payment_status', $orderId, 'advance_firc_reference')) {
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
        $paymentStatus = OrderPaymentStatusRepository::find($orderId);
        if (!$this->legAmountRecorded($paymentStatus, 'balance')) {
            Flash::set('error', 'Record the balance payment itself before entering its FIRC/eBRC reference.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($paymentStatus['balance_cleared_at'] ?? null, (int) $user['id'], $roleId, 'order_payment_status', $orderId, 'balance_firc_reference')) {
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
        $paymentStatus = OrderPaymentStatusRepository::find($orderId);
        if (!$this->legAmountRecorded($paymentStatus, 'freight')) {
            Flash::set('error', 'Record the freight payment itself before entering its FIRC/eBRC reference.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($paymentStatus['freight_cleared_at'] ?? null, (int) $user['id'], $roleId, 'order_payment_status', $orderId, 'freight_firc_reference')) {
            header("Location: /orders/{$orderId}");
            return;
        }
        $receivedAt = trim((string) ($_POST['freight_firc_received_at'] ?? '')) ?: date('Y-m-d');
        OrderPaymentStatusRepository::setFreightFirc($orderId, $reference, $receivedAt);
        AuditLogRepository::log((int) $user['id'], 'CA_FIRC_RECORDED', 'order_payment_status', $orderId, 'freight_firc_reference', null, $reference);
        Flash::set('success', 'Freight FIRC/eBRC reference recorded.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Order-page polish round: all four BL-originals/endorsement actions
     * below (Stage 9 — "COO received + BL originals endorsed", per
     * StageGateService's own docblock) had no stage check at all — a
     * direct POST with just manage_shipping could record/endorse BL
     * originals on an order still at Stage 1. Same isUnlocked(9) guard
     * closeOrder() already requires lower down this file.
     */
    public function recordBlOriginalsReceived(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!StageGateService::isUnlocked($orderId, 9)) {
            Flash::set('error', 'Stage 9 has not been unlocked for this order yet — complete Stage 8 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // QA Round 8, R8-N13: a whole number 1-10, never silently 3.
        $raw = trim((string) ($_POST['bl_originals_count'] ?? ''));
        if (!preg_match('/^\d{1,2}$/', $raw) || (int) $raw < 1 || (int) $raw > 10) {
            Flash::set('error', 'Enter how many original BL copies were received (1 to 10).');
            header("Location: /orders/{$orderId}");
            return;
        }
        $count = (int) $raw;
        OrderShippingRepository::recordBlOriginalsReceived($orderId, $count);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ORDER_BL_ORIGINALS_RECEIVED', 'order_shipping', $orderId, 'bl_originals_received_count', null, (string) $count);
        Flash::set('success', 'Original BL copies recorded as received from CHA.');
        header("Location: /orders/{$orderId}");
    }

    /** Order-page polish round: same missing isUnlocked(9) gate — see recordBlOriginalsReceived()'s docblock above. */
    public function recordBlEndorsed(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!StageGateService::isUnlocked($orderId, 9)) {
            Flash::set('error', 'Stage 9 has not been unlocked for this order yet — complete Stage 8 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderShippingRepository::recordBlEndorsed($orderId, (int) $user['id']);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log((int) $user['id'], 'ORDER_BL_ENDORSED', 'order_shipping', $orderId, null, null, null);
        Flash::set('success', 'Original BLs marked as endorsed by NexaCrest.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * docs/schema.sql Section BC — the CRUD save behind the BL
     * Endorsement print feature. Pre-fills onto order_shipping's own
     * vessel_name/voyage_number/bl_number when the form is first shown
     * (see the view), but what's actually generated always reads from
     * this independently-editable row, never back from order_shipping.
     */
    /** Order-page polish round: same missing isUnlocked(9) gate — see recordBlOriginalsReceived()'s docblock above. */
    public function saveBlEndorsement(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!StageGateService::isUnlocked($orderId, 9)) {
            Flash::set('error', 'Stage 9 has not been unlocked for this order yet — complete Stage 8 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderBlEndorsementRepository::upsert($orderId, [
            'bl_number'           => trim((string) ($_POST['bl_number'] ?? '')) ?: null,
            'vessel_voyage'       => trim((string) ($_POST['vessel_voyage'] ?? '')) ?: null,
            'port_of_loading'     => trim((string) ($_POST['port_of_loading'] ?? '')) ?: null,
            'port_of_discharge'   => trim((string) ($_POST['port_of_discharge'] ?? '')) ?: null,
            'date_of_endorsement' => trim((string) ($_POST['date_of_endorsement'] ?? '')) ?: null,
        ], (int) $user['id']);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log((int) $user['id'], 'ORDER_BL_ENDORSEMENT_SAVED', 'order_bl_endorsements', $orderId, null, null, null);
        Flash::set('success', 'BL Endorsement details saved.');
        header("Location: /orders/{$orderId}");
    }

    /** Order-page polish round: same missing isUnlocked(9) gate — see recordBlOriginalsReceived()'s docblock above. */
    public function generateBlEndorsement(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!StageGateService::isUnlocked($orderId, 9)) {
            Flash::set('error', 'Stage 9 has not been unlocked for this order yet — complete Stage 8 first.');
            header("Location: /orders/{$orderId}");
            return;
        }
        try {
            \App\Services\DocumentGenerationService::generateBlEndorsement($orderId, (int) $user['id']);
            // External QA report N-08: this action never reached the
            // audit log, so it never notified anyone either.
            AuditLogRepository::log((int) $user['id'], 'ORDER_BL_ENDORSEMENT_GENERATED', 'orders', $orderId, null, null, null);
            Flash::set('success', 'BL Endorsement generated — print it and physically endorse all 3 original BLs.');
        } catch (\RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        header("Location: /orders/{$orderId}");
    }

    /** Stage 9 gate: COO received, BL originals endorsed, complete document set couriered to buyer -> order closed. */
    /**
     * 90-item backlog Phase 6 Item 3 — BLI + COOPREP are now a real,
     * server-side hard gate (see DocumentGenerationService::closureBlockedBy()'s
     * docblock for why this applies unconditionally, dispute or not).
     * The one deliberately narrow escape hatch mirrors every other
     * locked-data override in this controller: edit_locked_data
     * permission (Super Admin bypasses automatically) plus a mandatory
     * reason, both re-checked here rather than trusted from the form.
     */
    /**
     * QA batch 4, ORD-14 (owner decision 5 Nov 2026): what is still missing on the original BLs before the
     * order can be closed (step 11a received from the CHA, then endorsed). Empty when both are recorded.
     *
     * @return list<string>
     */
    public static function blOriginalsMissing(int $orderId): array
    {
        $shipping = OrderShippingRepository::find($orderId) ?? [];
        $missing = [];
        if (empty($shipping['bl_originals_received_at'])) {
            $missing[] = 'record the original BLs received from the CHA (step 11a)';
        }
        if (empty($shipping['bl_endorsed_at'])) {
            $missing[] = 'mark the original BLs endorsed';
        }
        return $missing;
    }

    public function closeOrder(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!StageGateService::isUnlocked($orderId, 9)) {
            Flash::set('error', 'Stage 9 has not been unlocked for this order yet — complete Stage 8 first.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $overrideRequested = !empty($_POST['override_gate']);
        $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));
        $allowOverride = false;
        if ($overrideRequested) {
            if (!PermissionService::can((int) $user['id'], $roleId, 'edit_locked_data')) {
                Flash::set('error', 'You do not have permission to override the closure document requirement.');
                header("Location: /orders/{$orderId}");
                return;
            }
            if ($overrideReason === '' || mb_strlen($overrideReason) < 10) {
                Flash::set('error', 'A reason (at least 10 characters) is required to close an order without both required documents approved.');
                header("Location: /orders/{$orderId}");
                return;
            }
            $allowOverride = true;
        }

        if (!$allowOverride) {
            $blockedBy = DocumentGenerationService::closureBlockedBy($orderId);
            if (!empty($blockedBy)) {
                Flash::set('error', 'Before closing: generate and get approval for ' . implode(' and ', $blockedBy) . '. A user with the "Override locked data" permission can force this through with a reason if absolutely necessary.');
                header("Location: /orders/{$orderId}");
                return;
            }
            // QA batch 4, ORD-14 (owner decision 5 Nov 2026): the original BLs must be received from the CHA and endorsed.
            if (($blMissing = self::blOriginalsMissing($orderId)) !== []) {
                Flash::set('error', 'Before closing: ' . implode(' and ', $blMissing) . '. A user with the "Override locked data" permission can force this through with a reason if absolutely necessary.');
                header("Location: /orders/{$orderId}");
                return;
            }
        }

        $trackingNumber = trim((string) ($_POST['courier_tracking_number'] ?? ''));
        if ($trackingNumber === '') {
            Flash::set('error', 'Courier tracking number is required to close the order.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Owner request (3 Nov 2026): the courier charges go in with the tracking number — booked as an order cost now.
        $courierCharges = \App\Services\CourierCharges::parse($_POST);
        if (is_string($courierCharges)) {
            Flash::set('error', $courierCharges);
            header("Location: /orders/{$orderId}#step-11g-close-order");
            return;
        }
        OrderShippingRepository::recordCourierSent($orderId, $trackingNumber);
        StageGateService::passAndUnlockNext($orderId, 9, (int) $user['id']);
        OrderRepository::markShippedDocsSent($orderId);
        if ($courierCharges !== null) {
            \App\Services\CourierCharges::book(OrderRepository::find($orderId) ?? [], $orderId, $courierCharges, $trackingNumber, (int) $user['id']);
        }
        if ($allowOverride) {
            AuditLogRepository::log((int) $user['id'], 'CLOSE_ORDER_DOCUMENT_GATE_OVERRIDE', 'orders', $orderId, 'status', null, 'shipped_docs_sent', $overrideReason);
        }
        Flash::set('success', "Documents sent to buyer (tracking: {$trackingNumber}) — this order now shows as done to the client. Apply for any government export benefits, then use \"Mark Fully Settled\" below once there's nothing left outstanding.");
        header("Location: /orders/{$orderId}");
    }

    /**
     * docs/schema.sql Section BU — export process step 11e, the real
     * final lock. Only reachable from 'shipped_docs_sent' (documents
     * must already be out — see closeOrder() above). Blocks on any
     * government export benefit claim linked to this order that's
     * still awaiting receipt (step 11d: "once we receive the benefits —
     * record in system — then we mark the order a complete close"),
     * with the same edit_locked_data + reason escape hatch as the
     * closeOrder() document-gate override, for the rare case a benefit
     * genuinely never arrives and the order needs closing anyway.
     */
    public function markFullySettled(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        if ($order['status'] !== 'shipped_docs_sent') {
            Flash::set('error', 'This order must be in "Documents Sent" status (closeOrder, Stage 9) before it can be marked fully settled.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $overrideRequested = !empty($_POST['override_gate']);
        $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));
        $allowOverride = false;
        if ($overrideRequested) {
            if (!PermissionService::can((int) $user['id'], $roleId, 'edit_locked_data')) {
                Flash::set('error', 'You do not have permission to override the outstanding-benefits requirement.');
                header("Location: /orders/{$orderId}");
                return;
            }
            if ($overrideReason === '' || mb_strlen($overrideReason) < 10) {
                Flash::set('error', 'A reason (at least 10 characters) is required to fully settle an order with a benefit claim still outstanding.');
                header("Location: /orders/{$orderId}");
                return;
            }
            $allowOverride = true;
        }

        if (!$allowOverride) {
            // Owner decision (2026-10-27): Step 13g — every eligible scheme
            // received, or skipped with a reason (a skipped scheme's open
            // claim no longer blocks).
            $benefits = \App\Services\BenefitSettlementService::state($orderId);
            if ($benefits['waiting'] !== []) {
                Flash::set('error', 'This order has a government export benefit claim still awaiting receipt (' . implode(', ', $benefits['waiting']) . ') — record it as received in Step 13g, or skip it there with a reason. A user with the "Override locked data" permission can also force this through with a reason if absolutely necessary.');
                header("Location: /orders/{$orderId}#step-13g-benefits");
                return;
            }

            $outstandingComplianceTasks = array_filter(
                OrderComplianceTaskRepository::forOrder($orderId),
                static fn(array $ct): bool => $ct['status'] === 'not_started' || $ct['status'] === 'pending_approval'
            );
            if (!empty($outstandingComplianceTasks)) {
                Flash::set('error', 'This order still has an unresolved Compliance Checklist task — approve or mark it Not Applicable (with a reason) first, or a user with the "Override locked data" permission can force this through with a reason if absolutely necessary.');
                header("Location: /orders/{$orderId}#compliance-checklist");
                return;
            }

            $pendingExportBenefitSchemes = array_filter(
                OrderExportBenefitSchemeRepository::forOrder($orderId),
                static fn(array $s): bool => $s['status'] === 'pending'
            );
            if (!empty($pendingExportBenefitSchemes)) {
                Flash::set('error', 'This order still has a government export-benefit scheme with no eligibility decision — mark it Eligible or Not Eligible first, or a user with the "Override locked data" permission can force this through with a reason if absolutely necessary.');
                header("Location: /orders/{$orderId}#order-financials");
                return;
            }

            if ($benefits['unclaimed'] !== []) {
                Flash::set('error', 'An eligible government export benefit has not been claimed yet (' . implode(', ', $benefits['unclaimed']) . ') — record the claim and the money received in Step 13g, or skip it there with a reason. A user with the "Override locked data" permission can also force this through with a reason if absolutely necessary.');
                header("Location: /orders/{$orderId}#step-13g-benefits");
                return;
            }
        }

        OrderRepository::markComplete($orderId);
        AuditLogRepository::log((int) $user['id'], 'ORDER_FULLY_SETTLED', 'orders', $orderId, 'status', 'shipped_docs_sent', 'complete', $allowOverride ? $overrideReason : null);
        Flash::set('success', 'Order fully settled and closed.');
        // Owner decision (2026-10-28): every settled order gets its final
        // CAFIN automatically — all payments, bills, expenses and benefits
        // are final by now. Never blocks settlement: if it fails, staff
        // are told to generate it from the CAFIN box.
        try {
            $cafin = \App\Services\DocumentGenerationService::generateCaInternalAnnexure($orderId, (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'CA_INTERNAL_DOC_AUTO_GENERATED', 'orders', $orderId, 'document_reference', null, $cafin['document_reference'], 'Generated automatically when the order was fully settled');
            Flash::set('success', "Final internal financial annexure generated automatically: {$cafin['document_reference']}.");
        } catch (\Throwable $e) {
            error_log('[CAFIN AUTO-GENERATION FAILED] order=' . $orderId . ' — ' . $e->getMessage());
            Flash::set('warning', 'The order is settled, but the internal financial annexure (CAFIN) could not be generated automatically — generate it from the CAFIN box at the top of the order.');
        }
        header("Location: /orders/{$orderId}");
    }

    /**
     * Compliance/pre-closure task checklist (docs/schema.sql Section AR) —
     * "the person who has permission to close the order must able to see
     * this otherwise no meaning for this." Gated on close_orders, same as
     * the view itself; re-checked here (not just route-level/UI-level) per
     * the existing defense-in-depth convention (see WetSignatureGuardService).
     */
    public function updateComplianceTask(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $respond = static function (bool $ok, string $message) use ($orderId): void {
            Flash::set($ok ? 'success' : 'error', $message);
            header("Location: /orders/{$orderId}#compliance-checklist");
        };
        if (!PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'manage_compliance_checklist')) { // D-226: split out of close_orders
            $respond(false, 'You do not have permission to update the compliance checklist.');
            return;
        }

        // Owner request (2026-10-26, t4): one Save button per checklist card
        // posts every row as tasks[i][...]; each row lands in its own task.
        if (isset($_POST['tasks']) && is_array($_POST['tasks'])) {
            $this->saveComplianceChecklist($orderId, $user, $_POST['tasks'], (string) ($_POST['phase'] ?? ''));
            return;
        }

        $taskTypeId = (int) ($_POST['task_type_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));
        if ($taskTypeId <= 0 || !array_key_exists($status, OrderComplianceTaskRepository::STATUSES)) {
            $respond(false, 'Select a valid compliance task and status.');
            return;
        }

        $skipReason = trim((string) ($_POST['skip_reason'] ?? ''));
        if ($status === 'skipped' && $skipReason === '') {
            $respond(false, 'A reason is required to mark a compliance task as not applicable/skipped.');
            return;
        }

        OrderComplianceTaskRepository::setStatus($orderId, $taskTypeId, $status, $status === 'skipped' ? $skipReason : null, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'COMPLIANCE_TASK_STATUS_UPDATED', 'orders', $orderId, 'compliance_task:' . $taskTypeId, null, $status, $status === 'skipped' ? $skipReason : null);
        $respond(true, 'Saved — ' . OrderComplianceTaskRepository::STATUSES[$status] . '.');
    }

    /**
     * Saves a whole compliance checklist card at once. All-or-nothing: if
     * any row is invalid (unknown status, or Skipped without a reason)
     * nothing is written. Only rows whose status or reason changed are
     * updated, so untouched rows keep their resolved-by stamp.
     */
    private function saveComplianceChecklist(int $orderId, array $user, array $rows, string $phase): void
    {
        $anchor = $phase === 'shipment' ? 'compliance-checklist-shipment' : 'compliance-checklist';
        $fail = static function (string $message) use ($orderId, $anchor): void {
            Flash::set('error', $message);
            header("Location: /orders/{$orderId}#{$anchor}");
        };
        $current = [];
        foreach (OrderComplianceTaskRepository::forOrder($orderId) as $ct) {
            $current[(int) $ct['task_type_id']] = $ct;
        }

        $changes = [];
        foreach ($rows as $row) {
            $taskTypeId = (int) ($row['task_type_id'] ?? 0);
            $status = trim((string) ($row['status'] ?? ''));
            $reason = trim((string) ($row['skip_reason'] ?? ''));
            if (!isset($current[$taskTypeId]) || !array_key_exists($status, OrderComplianceTaskRepository::STATUSES)) {
                $fail('Select a valid status for every compliance task.');
                return;
            }
            $name = (string) $current[$taskTypeId]['task_type_name'];
            if ($status === 'skipped' && mb_strlen($reason) < ReasonValidator::MIN_LENGTH) {
                $fail('"' . $name . '" is marked Skipped — give a reason of at least ' . ReasonValidator::MIN_LENGTH . ' characters. Nothing was saved.');
                return;
            }
            $reason = $status === 'skipped' ? $reason : null;
            $before = $current[$taskTypeId];
            if ($before['status'] !== $status || ($status === 'skipped' && (string) ($before['skip_reason'] ?? '') !== $reason)) {
                $changes[] = [$taskTypeId, (string) $before['status'], $status, $reason];
            }
        }

        foreach ($changes as [$taskTypeId, $from, $status, $reason]) {
            OrderComplianceTaskRepository::setStatus($orderId, $taskTypeId, $status, $reason, (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'COMPLIANCE_TASK_STATUS_UPDATED', 'orders', $orderId, 'compliance_task:' . $taskTypeId, $from, $status, $reason);
        }
        Flash::set('success', $changes === [] ? 'Nothing changed on the checklist.' : 'Checklist saved — ' . count($changes) . ' task(s) updated.');
        header("Location: /orders/{$orderId}#{$anchor}");
    }

    /**
     * Repeat-order shortcut: skips every currently-outstanding (not yet
     * approved/skipped) compliance task on this order in one submit, with
     * one shared reason — the same deliberate "skipped requires a reason"
     * rule as a single task, just applied to the whole remaining set at
     * once instead of clicking through each one.
     */
    public function markAllComplianceTasksNotApplicable(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        if (!PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'manage_compliance_checklist')) { // D-226
            Flash::set('error', 'You do not have permission to update the compliance checklist.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $skipReason = trim((string) ($_POST['skip_reason'] ?? ''));
        if ($skipReason === '') {
            Flash::set('error', 'A reason is required to mark the remaining compliance tasks Not Applicable.');
            header("Location: /orders/{$orderId}#compliance-checklist");
            return;
        }

        // Each checklist card (Step 7 / Step 10) offers this for its own phase.
        $phase = (string) ($_POST['phase'] ?? '');
        $outstanding = array_filter(
            OrderComplianceTaskRepository::forOrder($orderId),
            static fn(array $ct): bool => ($ct['status'] === 'not_started' || $ct['status'] === 'pending_approval')
                && ($phase === '' || $ct['phase'] === $phase)
        );
        foreach ($outstanding as $ct) {
            OrderComplianceTaskRepository::setStatus($orderId, (int) $ct['task_type_id'], 'skipped', $skipReason, (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'COMPLIANCE_TASK_STATUS_UPDATED', 'orders', $orderId, 'compliance_task:' . $ct['task_type_id'], $ct['status'], 'skipped', $skipReason);
        }
        Flash::set('success', count($outstanding) . ' outstanding compliance task(s) marked Not Applicable.');
        header("Location: /orders/{$orderId}#compliance-checklist");
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
        // 90-item backlog Phase 6 Item 3 — once a real Bill of Lading
        // exists, the goods have actually shipped, so "lost" (buyer
        // walked away, nothing ever shipped) no longer describes this
        // order's reality. Closes a side door around Close Order's own
        // BLI/COOPREP requirement: without this, staff could mark an
        // already-shipped order "lost" and skip that paperwork entirely.
        $shipping = OrderShippingRepository::find($orderId);
        if ($shipping && !empty($shipping['bl_number'])) {
            Flash::set('error', 'This order already has a Bill of Lading on file — the goods have shipped, so it can no longer be marked "lost". Use Close Order instead.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // QA batch 2 (ORD-04): "lost" is the walk-away before any money. Once the buyer has paid (or an LC is
        // confirmed usable), or while a cancellation awaits its decision, the order goes through the
        // cancellation workflow instead (second approver, refund recorded with its bank reference).
        if (OrderCancellationRepository::findPendingForOrder($orderId)) {
            Flash::set('error', 'This order has a cancellation request awaiting a decision — approve or reject it instead of marking the order lost.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $lc = \App\Repositories\OrderLcDetailRepository::find($orderId);
        if (\App\Services\PartPaymentService::orderReceivedTotal($orderId) > 0 || ($lc !== null && !empty($lc['lc_confirmed_at']))) {
            Flash::set('error', 'The buyer has already paid on this order, so it cannot be marked lost. Use Request Cancellation: a second person approves it and the refund is recorded.');
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
     * External QA report M-09: "Formal order cancellation workflow
     * (reason, approval, money/refund handling, audit) distinct from
     * 'mark lost'." markLost() above only covers a pre-money walk-away
     * (status must still be 'active', no BL yet) — this covers
     * cancelling an order that's already further along. A request here
     * never changes the order's own status by itself; only
     * approveCancellation() does, once a privileged second person signs
     * off (close_orders to request, edit_locked_data to decide — same
     * maker-checker separation as every other approval in this app).
     */
    public function requestCancellation(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        if (in_array($order['status'], ['shipped_docs_sent', 'complete', 'lost', 'cancelled'], true)) {
            Flash::set('error', "An order that is already \"{$order['status']}\" cannot have a cancellation requested.");
            header("Location: /orders/{$orderId}");
            return;
        }
        if (OrderCancellationRepository::findPendingForOrder($orderId)) {
            Flash::set('error', 'This order already has a cancellation request awaiting a decision.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}");
            return;
        }

        $cancellationId = OrderCancellationRepository::create($orderId, $reason, (int) $user['id']);
        // External QA report N-07: nobody who can actually approve a
        // cancellation (edit_locked_data, the same tier the approval
        // decision itself is gated on) was ever told one was pending —
        // only the catch-all notification email below fired.
        foreach (\App\Repositories\PermissionRepository::usersWithPermission('edit_locked_data') as $approverUserId) {
            \App\Repositories\NotificationRepository::create($approverUserId, null, 'order_cancellation_requested', $orderId, "Cancellation requested for order {$order['order_reference']} — awaiting approval.");
        }
        AuditLogRepository::log((int) $user['id'], 'ORDER_CANCELLATION_REQUESTED', 'order_cancellations', $cancellationId, null, null, $reason);
        Flash::set('success', 'Cancellation requested — awaiting approval.');
        header("Location: /orders/{$orderId}");
    }

    public function approveCancellation(array $params): void
    {
        $orderId = (int) $params['id'];
        $cancellationId = (int) ($params['cancellationId'] ?? 0);
        $user = AuthService::currentUser();
        $cancellation = OrderCancellationRepository::find($cancellationId);
        if (!$cancellation || (int) $cancellation['order_id'] !== $orderId || $cancellation['status'] !== 'pending') {
            Flash::set('error', 'That cancellation request is no longer pending.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // QA-5 maker-checker: the person who requested the cancellation does
        // not also approve it, unless their role is ticked in Admin →
        // Self-approval rules (QA Round 8, R8-N09; default MD and ED).
        $selfDecision = (int) $cancellation['requested_by'] === (int) $user['id'];
        if ($selfDecision && !MakerCheckerGuard::selfApprovalAllowed('cancellation', (int) $user['id'])) {
            Flash::set('error', 'You requested this cancellation — a different privileged person must approve it.');
            header("Location: /orders/{$orderId}");
            return;
        }

        // QA batch 2 (ORD-01): the request may be weeks old — only an order that is still running
        // (active or disputed) can be cancelled. A shipped, settled or lost order keeps its status.
        $order = OrderRepository::find($orderId);
        if (!$order || !in_array($order['status'], ['active', 'disputed'], true)) {
            Flash::set('error', 'This order is now "' . ($order['status'] ?? 'missing') . '", so the cancellation can no longer be approved. Reject the request instead.');
            header("Location: /orders/{$orderId}");
            return;
        }

        // QA batch 2 (ORD-03, rule D-212): a refund is "completed" only through Record refund paid, with the
        // date it left the bank and its bank reference. Approval records "pending" or "not applicable".
        $refundStatus = (string) ($_POST['refund_status'] ?? 'not_applicable');
        if ($refundStatus === 'completed') {
            Flash::set('error', 'Approve with "Refund pending", then record the refund with the date it was paid and its bank reference.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if (!in_array($refundStatus, ['not_applicable', 'pending'], true)) {
            $refundStatus = 'not_applicable';
        }
        $refundAmountRaw = trim((string) ($_POST['refund_amount'] ?? ''));
        $refundAmount = null;
        if ($refundAmountRaw !== '') {
            $received = \App\Services\PartPaymentService::orderReceivedTotal($orderId);
            if (!is_numeric($refundAmountRaw) || (float) $refundAmountRaw <= 0) {
                Flash::set('error', 'Enter the refund amount as a number above zero, or leave it empty.');
                header("Location: /orders/{$orderId}");
                return;
            }
            if (round((float) $refundAmountRaw, 2) > $received) {
                Flash::set('error', 'The refund (' . number_format((float) $refundAmountRaw, 2) . ') is more than the buyer has paid on this order (' . number_format($received, 2) . ').');
                header("Location: /orders/{$orderId}");
                return;
            }
            $refundAmount = round((float) $refundAmountRaw, 2);
        }
        $decisionNotes = trim((string) ($_POST['decision_notes'] ?? '')) ?: null;

        if (!OrderRepository::cancel($orderId)) {
            Flash::set('error', 'The order changed while you were deciding, so it was not cancelled. Reload and check it.');
            header("Location: /orders/{$orderId}");
            return;
        }
        OrderCancellationRepository::approve($cancellationId, (int) $user['id'], $decisionNotes, $refundAmount, $refundStatus);
        // External QA report N-07: the requester was never told what
        // happened to their own request — only the catch-all notification
        // email below fired.
        \App\Repositories\NotificationRepository::create((int) $cancellation['requested_by'], null, 'order_cancellation_approved', $orderId, "Your cancellation request for order {$order['order_reference']} was approved.");
        AuditLogRepository::log((int) $user['id'], 'ORDER_CANCELLATION_APPROVED', 'orders', $orderId, 'status', $order['status'], 'cancelled', $decisionNotes);
        if ($selfDecision) {
            AuditLogRepository::log((int) $user['id'], 'ORDER_CANCELLATION_SELF_APPROVED', 'order_cancellations', $cancellationId, null, null, null, 'Approved the cancellation they requested themselves (Admin → Self-approval rules).');
        }
        Flash::set('success', 'Cancellation approved — order is now cancelled.');
        header("Location: /orders/{$orderId}");
    }

    public function rejectCancellation(array $params): void
    {
        $orderId = (int) $params['id'];
        $cancellationId = (int) ($params['cancellationId'] ?? 0);
        $user = AuthService::currentUser();
        $cancellation = OrderCancellationRepository::find($cancellationId);
        if (!$cancellation || (int) $cancellation['order_id'] !== $orderId || $cancellation['status'] !== 'pending') {
            Flash::set('error', 'That cancellation request is no longer pending.');
            header("Location: /orders/{$orderId}");
            return;
        }
        // Same rule as approveCancellation() (QA Round 8, R8-N09).
        $selfDecision = (int) $cancellation['requested_by'] === (int) $user['id'];
        if ($selfDecision && !MakerCheckerGuard::selfApprovalAllowed('cancellation', (int) $user['id'])) {
            Flash::set('error', 'You requested this cancellation — a different privileged person must decide on it.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $decisionNotes = trim((string) ($_POST['decision_notes'] ?? ''));
        if ($error = ReasonValidator::check($decisionNotes)) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}");
            return;
        }

        OrderCancellationRepository::reject($cancellationId, (int) $user['id'], $decisionNotes);
        // External QA report N-07: the requester was never told what
        // happened to their own request — only the catch-all notification
        // email below fired.
        $order = OrderRepository::find($orderId);
        \App\Repositories\NotificationRepository::create((int) $cancellation['requested_by'], null, 'order_cancellation_rejected', $orderId, "Your cancellation request for order {$order['order_reference']} was rejected: {$decisionNotes}");
        AuditLogRepository::log((int) $user['id'], 'ORDER_CANCELLATION_REJECTED', 'order_cancellations', $cancellationId, null, 'pending', 'rejected', $decisionNotes);
        if ($selfDecision) {
            AuditLogRepository::log((int) $user['id'], 'ORDER_CANCELLATION_SELF_REJECTED', 'order_cancellations', $cancellationId, null, null, null, 'Rejected the cancellation they requested themselves (Admin → Self-approval rules).');
        }
        Flash::set('success', 'Cancellation request rejected — order is unaffected.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Recording that the refund money has actually moved is a separate,
     * lower-bar step from the approval decision itself (the bank
     * transfer happens outside this system on its own timeline) — gated
     * on close_orders, same tier as requesting the cancellation, not the
     * stricter edit_locked_data the approval decision itself needs.
     */
    public function completeCancellationRefund(array $params): void
    {
        $orderId = (int) $params['id'];
        $cancellationId = (int) ($params['cancellationId'] ?? 0);
        $user = AuthService::currentUser();
        $cancellation = OrderCancellationRepository::find($cancellationId);
        if (!$cancellation || (int) $cancellation['order_id'] !== $orderId || $cancellation['status'] !== 'approved' || $cancellation['refund_status'] !== 'pending') {
            Flash::set('error', 'There is no pending refund to complete for this cancellation.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $refundNotes = trim((string) ($_POST['refund_notes'] ?? '')) ?: null;
        // Owner rule D-212: the refund says when it left the bank and with which reference.
        $refundPaidAt = trim((string) ($_POST['refund_paid_at'] ?? ''));
        $refundReference = trim((string) ($_POST['refund_reference'] ?? ''));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $refundPaidAt, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $refundPaidAt > date('Y-m-d')) {
            Flash::set('error', 'Enter the date the refund was paid (today or earlier).');
            header("Location: /orders/{$orderId}");
            return;
        }
        if ($error = \App\Helpers\FieldValidator::bankReference($refundReference, 'The bank reference of the refund')) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}");
            return;
        }

        OrderCancellationRepository::completeRefund($cancellationId, (int) $user['id'], $refundNotes, $refundPaidAt, $refundReference);
        AuditLogRepository::log((int) $user['id'], 'ORDER_CANCELLATION_REFUND_COMPLETED', 'order_cancellations', $cancellationId, 'refund_status', 'pending', 'completed',
            "Paid {$refundPaidAt}, bank ref {$refundReference}" . ($refundNotes !== null ? " — {$refundNotes}" : ''));
        Flash::set('success', 'Refund marked as completed.');
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

        if (!in_array($newStatus, ['active', 'shipped_docs_sent', 'complete', 'disputed', 'lost', 'cancelled'], true)) {
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

    /** "Received USD 1,800.00 at today's exchange rate of ₹83.2500 (= ₹149,850.00)." — the remittance + rate just recorded. */
    private function remittanceStatement(int $orderId, float $amount, float $rate, ?array $inr = null): string
    {
        // D-244: the rupees the bank credited and the rate worked out from them (or the typed rate, as an estimate).
        return \App\Services\RemittanceInr::statement((string) (OrderRepository::find($orderId)['currency_code'] ?? ''), $amount, $inr, $rate);
    }
}
