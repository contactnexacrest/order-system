<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\MerchantTradeRepository;
use App\Services\AuthService;
use App\Services\DivisionService;
use App\Services\ImportService;
use App\Services\MerchantDocumentService;
use App\Services\MerchantTradeService;
use App\Services\PermissionService;
use App\Services\UiTemplateService;
use App\Services\ZohoBooksService;

/**
 * Owner decision D-257 Phase 4 (5 Nov 2026): the Merchant Trade (MTT)
 * pages — the deals list (Standard list and Kanban board, the same filters,
 * links and actions), one deal with its steps, money on both legs and RBI
 * checks, and the reports (open deals with their RBI status, margin per
 * deal). /merchant/… belongs to the module "Merchant trade", which is on for
 * MTT only, so nobody else reaches it; each deal is also checked against the
 * person's division. Port: merchantTradeController.js.
 */
final class MerchantTradeController
{
    private static function user(): array
    {
        return AuthService::currentUser();
    }

    private static function can(string $permission): bool
    {
        $u = self::user();
        return PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, $permission);
    }

    /** The deal behind /merchant/{id}/…, or null after answering 404. */
    /**
     * QA batch 2 (ACC-4): what of the selling side this person may see — the sale prices and margin
     * (view_selling_prices), the buyer's payments (view_buyer_payments) and the buyer's name (not Procurement).
     * @return array{selling:bool, buyer_payments:bool, client:bool}
     */
    private static function money(): array
    {
        return [
            'selling' => \App\Services\MoneyVisibility::can(\App\Services\MoneyVisibility::SELLING),
            'buyer_payments' => \App\Services\MoneyVisibility::can(\App\Services\MoneyVisibility::BUYER_PAYMENTS),
            'client' => \App\Services\MoneyVisibility::seesClient(),
        ];
    }

    private static function deal(array $params): ?array
    {
        $d = MerchantTradeRepository::find((int) ($params['id'] ?? 0));
        if ($d === null || !DivisionService::allows((int) $d['division_id'])) {
            http_response_code(404);
            echo 'Deal not found.';
            return null;
        }
        return $d;
    }

    private static function done(int $id, ?string $error, string $ok, string $anchor = ''): void
    {
        Flash::set($error === null ? 'success' : 'error', $error ?? $ok);
        header("Location: /merchant/{$id}" . ($anchor !== '' ? '#' . $anchor : ''));
    }

    // ------------------------------------------------------------ list / board

    public function index(array $params): void
    {
        $stage = (string) ($_GET['stage'] ?? '');
        $stage = array_key_exists($stage, MerchantTradeService::STAGES) ? $stage : '';
        $rows = [];
        foreach (MerchantTradeRepository::list(MerchantTradeService::reportDivisionIds(), $stage !== '' ? $stage : null) as $d) {
            $rows[] = $d + ['figures' => MerchantTradeService::figures($d), 'worst' => MerchantTradeService::worstLevel(MerchantTradeService::checksFor($d))];
        }
        $pref = self::user()['ui_template_preference_id'] ?? null;
        $board = UiTemplateService::showsBoard($pref !== null ? (int) $pref : null);
        $columns = [];
        if ($board) {
            foreach (MerchantTradeService::STAGES as $key => $label) {
                if ($stage === '' || $key === $stage) {
                    $columns[$key] = ['label' => $label, 'rows' => array_values(array_filter($rows, static fn (array $r): bool => $r['stage'] === $key))];
                }
            }
        }
        View::render('merchant/index', [
            'rows' => $rows,
            'board' => $board,
            'columns' => $columns,
            'stage' => $stage,
            'kanbanUser' => UiTemplateService::resolve($pref !== null ? (int) $pref : null) === 'kanban',
            'active' => MerchantTradeService::isActive(),
            'canEdit' => self::can('manage_purchasing'),
            'money' => self::money(),
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        View::render('merchant/create', ['active' => MerchantTradeService::isActive(), 'old' => Flash::pullOld()], 'layout/base');
    }

    public function store(array $params): void
    {
        [$id, $error] = MerchantTradeService::create($_POST, (int) self::user()['id']);
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
            header('Location: /merchant/create');
            return;
        }
        Flash::set('success', 'Deal created. Add the goods, then generate the purchase order.');
        header("Location: /merchant/{$id}");
    }

    // ------------------------------------------------------------ one deal

    public function show(array $params): void
    {
        if (($d = self::deal($params)) === null) {
            return;
        }
        $id = (int) $d['id'];
        View::render('merchant/show', [
            'd' => $d,
            'lines' => MerchantTradeRepository::lines($id),
            'figures' => MerchantTradeService::figures($d),
            'payments' => MerchantTradeRepository::payments($id),
            'checks' => MerchantTradeService::checksFor($d),
            'docs' => MerchantTradeService::bankDocuments(),
            'received' => MerchantTradeRepository::documents($id),
            'blockers' => MerchantTradeService::blockers($id),
            'canEdit' => self::can('manage_purchasing'),
            'canPay' => self::can('manage_payments'),
            'canZoho' => self::can('ca_module_manage') && ZohoBooksService::isEnabled(),
            'money' => self::money(),
        ], 'layout/base');
    }

    private function act(array $params, callable $fn, string $ok, string $anchor): void
    {
        if (($d = self::deal($params)) === null) {
            return;
        }
        self::done((int) $d['id'], $fn((int) $d['id']), $ok, $anchor);
    }

    public function saveHeader(array $params): void
    {
        // QA batch 2 (ACC-4): the deal header carries the buyer and the sale terms.
        if (!self::money()['client'] || !self::money()['selling']) {
            $this->act($params, static fn (int $id): ?string => 'The deal carries the buyer and the sale terms, which your department does not see.', '', 'mtt-deal');
            return;
        }
        $this->act($params, static fn (int $id): ?string => MerchantTradeService::saveHeader($id, $_POST, (int) self::user()['id']), 'Deal saved.', 'mtt-deal');
    }

    public function addLine(array $params): void
    {
        // QA batch 2 (ACC-4): a line carries its sell price.
        if (!self::money()['selling']) {
            $this->act($params, static fn (int $id): ?string => 'A line carries its sell price, which your department does not see.', '', 'mtt-lines');
            return;
        }
        $this->act($params, static fn (int $id): ?string => MerchantTradeService::addLine($id, $_POST, (int) self::user()['id']), 'Line added.', 'mtt-lines');
    }

    public function removeLine(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => MerchantTradeService::removeLine($id, (int) $params['lineId'], (int) self::user()['id']), 'Line removed.', 'mtt-lines');
    }

    public function saveBacking(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => MerchantTradeService::saveBacking($id, $_POST, (int) self::user()['id']), 'Advance backing saved.', 'mtt-payments');
    }

    public function saveShipment(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => MerchantTradeService::saveShipment($id, $_POST, (int) self::user()['id']), 'Bill of lading saved.', 'mtt-shipment');
    }

    public function saveDocument(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => MerchantTradeService::saveDocument($id, (string) ($_POST['doc_key'] ?? ''), trim((string) ($_POST['received_on'] ?? '')), (int) self::user()['id']), 'Bank checklist saved.', 'mtt-bank');
    }

    public function recordPayment(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => MerchantTradeService::recordPayment($id, $_POST, (int) self::user()['id']), 'Payment recorded.', 'mtt-payments');
    }

    public function advance(array $params): void
    {
        if (($d = self::deal($params)) === null) {
            return;
        }
        $error = MerchantTradeService::advance((int) $d['id'], (string) ($_POST['from_stage'] ?? ''), (int) self::user()['id']);
        $now = MerchantTradeRepository::find((int) $d['id']);
        self::done((int) $d['id'], $error, 'Moved on to: ' . (MerchantTradeService::STAGES[$now['stage'] ?? ''] ?? '') . '.');
    }

    /** The purchase order or the sale invoice as a PDF (download). */
    public function document(array $params): void
    {
        if (($d = self::deal($params)) === null) {
            return;
        }
        $id = (int) $d['id'];
        $kind = (string) ($_POST['kind'] ?? '');
        $userId = (int) self::user()['id'];
        if ($kind === 'po') {
            if (MerchantTradeRepository::lines($id) === []) {
                self::done($id, 'Add the goods first.', '', 'mtt-lines');
                return;
            }
            if (self::can('manage_purchasing')) { // QA batch 2 (ACC-4): only an editor marks the PO generated
                MerchantTradeService::markPoGenerated($id, $userId);
            }
        } elseif ($kind === 'invoice') {
            // QA batch 2 (ACC-4): the sale invoice carries the selling prices and fixes its number (a write).
            if (!self::money()['selling'] || !self::can('manage_purchasing')) {
                self::done($id, 'The sale invoice carries selling prices your department does not see.', '', 'mtt-shipment');
                return;
            }
            if (($error = MerchantTradeService::fixInvoice($id, $userId)) !== null) {
                self::done($id, $error, '', 'mtt-shipment');
                return;
            }
        } else {
            self::done($id, 'Choose the document.', '');
            return;
        }
        $pdf = MerchantDocumentService::pdf($id, $kind);
        $number = (string) MerchantDocumentService::context($id, $kind)['number'];
        AuditLogRepository::log($userId, 'MERCHANT_DOCUMENT_GENERATED', 'merchant_deals', $id, $kind, null, $number);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . ($kind === 'po' ? 'PO ' : 'INV ') . str_replace('/', '-', $number) . '.pdf"');
        echo $pdf;
    }

    /** D-257 Zoho: the deal's "App vs Zoho" check (reads only). */
    public function zohoCheck(array $params): void
    {
        if (($d = self::deal($params)) === null) {
            return;
        }
        \App\Controllers\CaController::divisionZohoCheck('deal', (int) $d['id'], "/merchant/{$d['id']}", 'Deal ' . $d['reference'], $d['supplier_name'] . ' → ' . $d['buyer_name']);
    }

    public function pushZoho(array $params): void
    {
        if (($d = self::deal($params)) === null) {
            return;
        }
        [$error, $ok] = \App\Services\DivisionZohoSync::sendNow('deal', (int) $d['id'], (int) self::user()['id']);
        self::done((int) $d['id'], $error, $ok, 'mtt-payments');
    }

    // ------------------------------------------------------------ reports

    public function reports(array $params): void
    {
        View::render('merchant/reports', ['rows' => MerchantTradeService::reportRows(), 'settings' => MerchantTradeService::rbiSettings(), 'money' => self::money()], 'layout/base');
    }

    /** Formatting shared by the views: cents of a currency => "1,234.56". */
    public static function fc(int $cents): string
    {
        return ImportService::fc($cents);
    }
}
