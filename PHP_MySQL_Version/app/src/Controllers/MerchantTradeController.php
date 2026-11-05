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
        $this->act($params, static fn (int $id): ?string => MerchantTradeService::saveHeader($id, $_POST, (int) self::user()['id']), 'Deal saved.', 'mtt-deal');
    }

    public function addLine(array $params): void
    {
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
            MerchantTradeService::markPoGenerated($id, $userId);
        } elseif ($kind === 'invoice') {
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

    public function pushZoho(array $params): void
    {
        if (($d = self::deal($params)) === null) {
            return;
        }
        $error = null;
        try {
            ZohoBooksService::pushMerchantDeal((int) $d['id']);
        } catch (\Throwable $e) {
            $error = 'Zoho Books: ' . $e->getMessage();
        }
        self::done((int) $d['id'], $error, 'Sale invoice and supplier bill sent to Zoho Books.', 'mtt-payments');
    }

    // ------------------------------------------------------------ reports

    public function reports(array $params): void
    {
        View::render('merchant/reports', ['rows' => MerchantTradeService::reportRows(), 'settings' => MerchantTradeService::rbiSettings()], 'layout/base');
    }

    /** Formatting shared by the views: cents of a currency => "1,234.56". */
    public static function fc(int $cents): string
    {
        return ImportService::fc($cents);
    }
}
