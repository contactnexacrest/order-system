<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\ImportRepository;
use App\Services\AuthService;
use App\Services\DivisionService;
use App\Services\GstCalculator;
use App\Services\ImportDocumentService;
use App\Services\ImportService;
use App\Services\PermissionService;
use App\Services\UiTemplateService;
use App\Services\ZohoBooksService;

/**
 * Owner decision D-257 Phase 3 (5 Nov 2026): the Import & Distribution (IMP)
 * pages — the imports list (Standard list and Kanban board, the same
 * filters, links and actions), one import with its steps, the reports
 * (landed cost, stock on hand, duty and IGST credit) and Admin › Import duty
 * rates. /import/… belongs to the module "Import, stock and landed cost",
 * which is on for IMP only, so nobody else reaches it; each import is also
 * checked against the person's division. Purchase money: view_purchase_prices.
 * Port: importController.js.
 */
final class ImportController
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

    /** The import behind /import/{id}/…, or null after answering 404. */
    private static function shipment(array $params): ?array
    {
        $s = ImportRepository::find((int) ($params['id'] ?? 0));
        if ($s === null || !DivisionService::allows((int) $s['division_id'])) {
            http_response_code(404);
            echo 'Import not found.';
            return null;
        }
        return $s;
    }

    private static function done(int $id, ?string $error, string $ok, string $anchor = ''): void
    {
        Flash::set($error === null ? 'success' : 'error', $error ?? $ok);
        header("Location: /import/{$id}" . ($anchor !== '' ? '#' . $anchor : ''));
    }

    // ------------------------------------------------------------ list / board

    public function index(array $params): void
    {
        $stage = (string) ($_GET['stage'] ?? '');
        $stage = array_key_exists($stage, ImportService::STAGES) ? $stage : '';
        $ids = ImportService::reportDivisionIds();
        $rows = ImportRepository::list($ids, $stage !== '' ? $stage : null);
        $pref = self::user()['ui_template_preference_id'] ?? null;
        $board = UiTemplateService::showsBoard($pref !== null ? (int) $pref : null);
        $columns = [];
        if ($board) {
            foreach (ImportService::STAGES as $key => $label) {
                if ($stage === '' || $key === $stage) {
                    $columns[$key] = ['label' => $label, 'rows' => array_values(array_filter($rows, static fn (array $r): bool => $r['stage'] === $key))];
                }
            }
        }
        View::render('import/index', [
            'rows' => $rows,
            'board' => $board,
            'columns' => $columns,
            'stage' => $stage,
            'kanbanUser' => UiTemplateService::resolve($pref !== null ? (int) $pref : null) === 'kanban',
            'active' => ImportService::isActive(),
            'canEdit' => self::can('manage_purchasing'),
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        View::render('import/create', ['active' => ImportService::isActive(), 'old' => Flash::pullOld()], 'layout/base');
    }

    public function store(array $params): void
    {
        [$id, $error] = ImportService::create($_POST, (int) self::user()['id']);
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
            header('Location: /import/create');
            return;
        }
        Flash::set('success', 'Import created. Add its lines, then generate the import PO.');
        header("Location: /import/{$id}");
    }

    // ------------------------------------------------------------ one import

    public function show(array $params): void
    {
        if (($s = self::shipment($params)) === null) {
            return;
        }
        $id = (int) $s['id'];
        $figures = ImportService::figures($s);
        View::render('import/show', [
            's' => $s,
            'figures' => $figures,
            'payments' => ImportRepository::payments($id),
            'poCents' => ImportService::poValueCents($id),
            'paidCents' => ImportService::paidCents($id),
            'blockers' => ImportService::blockers($id),
            'products' => ImportRepository::products(),
            'canEdit' => self::can('manage_purchasing'),
            'canPay' => self::can('manage_payments'),
            'canZoho' => self::can('ca_module_manage') && ZohoBooksService::isEnabled(),
            'margin' => ImportService::setting('imp_margin_pct'),
        ], 'layout/base');
    }

    private function act(array $params, callable $fn, string $ok, string $anchor): void
    {
        if (($s = self::shipment($params)) === null) {
            return;
        }
        self::done((int) $s['id'], $fn((int) $s['id']), $ok, $anchor);
    }

    public function saveHeader(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => ImportService::saveHeader($id, $_POST, (int) self::user()['id']), 'Import PO saved.', 'imp-po');
    }

    public function addLine(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => ImportService::addLine($id, $_POST, (int) self::user()['id']), 'Line added.', 'imp-lines');
    }

    public function removeLine(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => ImportService::removeLine($id, (int) $params['lineId'], (int) self::user()['id']), 'Line removed.', 'imp-lines');
    }

    public function saveLc(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => ImportService::saveLc($id, $_POST, (int) self::user()['id']), 'LC saved.', 'imp-lc');
    }

    public function saveShipment(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => ImportService::saveShipment($id, $_POST, (int) self::user()['id']), 'Bill of lading saved.', 'imp-shipment');
    }

    public function saveClearance(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => ImportService::saveClearance($id, $_POST, (int) self::user()['id']), 'Customs clearance saved.', 'imp-clearance');
    }

    public function igstCredit(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => ImportService::saveIgstCredit($id, trim((string) ($_POST['igst_credit_on'] ?? '')), (int) self::user()['id']), 'IGST input credit recorded.', 'imp-clearance');
    }

    public function recordPayment(array $params): void
    {
        $this->act($params, static fn (int $id): ?string => ImportService::recordPayment($id, $_POST, (int) self::user()['id']), 'Payment recorded.', 'imp-payments');
    }

    public function advance(array $params): void
    {
        if (($s = self::shipment($params)) === null) {
            return;
        }
        $error = ImportService::advance((int) $s['id'], (string) ($_POST['from_stage'] ?? ''), (int) self::user()['id']);
        $now = ImportRepository::find((int) $s['id']);
        self::done((int) $s['id'], $error, 'Moved on to: ' . (ImportService::STAGES[$now['stage'] ?? ''] ?? '') . '.');
    }

    /** The import PO as a PDF (download). Generating it ticks "import PO generated". */
    public function poDocument(array $params): void
    {
        if (($s = self::shipment($params)) === null) {
            return;
        }
        if (ImportRepository::lines((int) $s['id']) === []) {
            self::done((int) $s['id'], 'Add at least one line first.', '', 'imp-lines');
            return;
        }
        $pdf = ImportDocumentService::pdf((int) $s['id']);
        ImportService::markPoGenerated((int) $s['id'], (int) self::user()['id']);
        AuditLogRepository::log((int) self::user()['id'], 'IMPORT_PO_GENERATED', 'import_shipments', (int) $s['id'], 'reference', null, (string) $s['reference']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="IPO ' . str_replace('/', '-', (string) $s['reference']) . '.pdf"');
        echo $pdf;
    }

    public function pushZoho(array $params): void
    {
        if (($s = self::shipment($params)) === null) {
            return;
        }
        $error = null;
        try {
            ZohoBooksService::pushImport((int) $s['id']);
        } catch (\Throwable $e) {
            $error = 'Zoho Books: ' . $e->getMessage();
        }
        self::done((int) $s['id'], $error, 'Supplier bill and customs duty bill sent to Zoho Books.', 'imp-clearance');
    }

    // ------------------------------------------------------------ reports

    public function reports(array $params): void
    {
        $ids = ImportService::reportDivisionIds();
        $landed = [];
        $duty = [];
        foreach (ImportRepository::list($ids) as $row) {
            if (in_array($row['stage'], ['ordered', 'shipped'], true)) {
                continue;
            }
            $s = ImportRepository::find((int) $row['id']);
            foreach (ImportRepository::lines((int) $s['id']) as $l) {
                $landed[] = $l + ['reference' => $s['reference'], 'boe_no' => $s['boe_no'], 'shipment_id' => (int) $s['id']];
            }
            $duty[] = $s;
        }
        View::render('import/reports', [
            'landed' => $landed,
            'duty' => $duty,
            'stock' => ImportService::stockReport(),
            'movements' => ImportRepository::movements($ids, 50),
            'margin' => ImportService::setting('imp_margin_pct'),
        ], 'layout/base');
    }

    // ------------------------------------------------------------ Admin › Import duty rates

    public function dutyRates(array $params): void
    {
        View::render('import/duty_rates', [
            'rates' => ImportRepository::dutyRates(),
            'defaults' => ['bcd' => ImportService::setting('imp_default_bcd_pct'), 'sws' => ImportService::setting('imp_default_sws_pct'), 'igst' => ImportService::setting('imp_default_igst_pct')],
        ], 'layout/base');
    }

    public function storeDutyRate(array $params): void
    {
        $user = self::user();
        $hsn = preg_replace('/\D/', '', (string) ($_POST['hsn_prefix'] ?? '')) ?? '';
        $pct = static fn (string $k): string => trim((string) ($_POST[$k] ?? ''));
        $bad = static fn (string $v): bool => preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v) !== 1 || GstCalculator::scaled($v, 2) > 15000;
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
        $error = match (true) {
            strlen($hsn) < 2 || strlen($hsn) > 8 => 'An HSN has 2 to 8 digits.',
            $bad($pct('bcd_pct')) || $bad($pct('sws_pct')) || $bad($pct('igst_pct')) => 'Each rate is a percentage, 0 to 150, with up to 2 decimals.',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: /admin/import-duty-rates');
            return;
        }
        $m = static fn (string $v): string => GstCalculator::money(GstCalculator::scaled($v, 2));
        $id = ImportRepository::addDutyRate($hsn, $m($pct('bcd_pct')), $m($pct('sws_pct')), $m($pct('igst_pct')), $note !== '' ? $note : null, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'IMPORT_DUTY_RATE_ADDED', 'import_duty_rates', $id, 'hsn_prefix', null, $hsn);
        Flash::set('success', 'Duty rate added.');
        header('Location: /admin/import-duty-rates');
    }

    public function toggleDutyRate(array $params): void
    {
        $user = self::user();
        $active = ($_POST['active'] ?? '') === '1';
        ImportRepository::setDutyRateActive((int) $params['id'], $active, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'IMPORT_DUTY_RATE_' . ($active ? 'ON' : 'OFF'), 'import_duty_rates', (int) $params['id'], 'is_active', null, $active ? '1' : '0');
        Flash::set('success', $active ? 'Duty rate in use again.' : 'Duty rate no longer used.');
        header('Location: /admin/import-duty-rates');
    }
}
