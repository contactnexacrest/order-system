<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csv;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\LookupRepository;
use App\Repositories\ReportDefinitionRepository;
use App\Repositories\ReportRepository;
use App\Services\AuthService;
use App\Services\PermissionService;

/** Spec Section 16 — REPORTS: per-client, per-order, aggregate, CSV export, saved report definitions. */
final class ReportController
{
    public function index(array $params): void
    {
        $user = AuthService::currentUser();
        View::render('reports/index', [
            'clients' => ClientRepository::all(),
            'incoterms' => LookupRepository::incoterms(),
            'stages' => LookupRepository::stagesMaster(),
            'countries' => ReportRepository::distinctCountries(),
            'savedReports' => ReportDefinitionRepository::visibleTo((int) $user['id']),
        ], 'layout/base');
    }

    public function client(array $params): void
    {
        $clientId = (int) $params['clientId'];
        $data = ReportRepository::perClient($clientId);
        if (!$data['client']) {
            http_response_code(404);
            echo 'Client not found.';
            return;
        }

        if (($_GET['format'] ?? '') === 'csv') {
            $rows = array_map(static fn(array $o): array => [
                'Order Ref' => $o['order_reference'],
                'Status' => $o['status'],
                'Stage' => $o['current_stage_name'] ?? 'Quotation',
                'Incoterm' => $o['incoterm_code'],
                'Currency' => $o['currency_code'],
                'Created' => $o['created_at'],
            ], $data['orders']);
            Csv::stream('client_' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $data['client']['client_unique_number']) . '_orders.csv',
                ['Order Ref', 'Status', 'Stage', 'Incoterm', 'Currency', 'Created'], $rows);
        }

        $user = AuthService::currentUser();
        $canViewFullEmail = PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'view_client_email_full');

        View::render('reports/client', $data + ['canViewFullEmail' => $canViewFullEmail], 'layout/base');
    }

    public function order(array $params): void
    {
        $orderId = (int) $params['orderId'];
        $data = ReportRepository::perOrder($orderId);
        if (!$data['order']) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        View::render('reports/order', $data, 'layout/base');
    }

    public function aggregate(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;
        $stageId = ($_GET['stage_id'] ?? '') !== '' ? (int) $_GET['stage_id'] : null;
        $incotermId = ($_GET['incoterm_id'] ?? '') !== '' ? (int) $_GET['incoterm_id'] : null;
        $country = trim((string) ($_GET['country'] ?? '')) ?: null;

        $rows = ReportRepository::aggregate($dateFrom, $dateTo, $stageId, $incotermId, $country);

        if (($_GET['format'] ?? '') === 'csv') {
            $csvRows = array_map(static fn(array $r): array => [
                'Order Ref' => $r['order_reference'],
                'Client' => $r['company_legal_name'],
                'Country' => $r['country_of_destination'],
                'Incoterm' => $r['incoterm_code'],
                'Currency' => $r['currency_code'],
                'Stage' => $r['current_stage_name'] ?? 'Quotation',
                'Status' => $r['status'],
                'Total FOB Value' => $r['total_fob_value'],
                'Created' => $r['created_at'],
            ], $rows);
            Csv::stream('aggregate_report.csv',
                ['Order Ref', 'Client', 'Country', 'Incoterm', 'Currency', 'Stage', 'Status', 'Total FOB Value', 'Created'], $csvRows);
        }

        $user = AuthService::currentUser();
        $canManageDefinitions = PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'manage_report_definitions');

        View::render('reports/aggregate', [
            'rows' => $rows,
            'incoterms' => LookupRepository::incoterms(),
            'stages' => LookupRepository::stagesMaster(),
            'countries' => ReportRepository::distinctCountries(),
            'filters' => compact('dateFrom', 'dateTo', 'stageId', 'incotermId', 'country'),
            'canManageDefinitions' => $canManageDefinitions,
        ], 'layout/base');
    }

    /** Added 2026-09-19 — "Operations Queues" snapshot + date-ranged funnel activity report. */
    public function queues(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: date('Y-m-d', strtotime('-7 days'));
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: date('Y-m-d');

        View::render('reports/queues', [
            'queues' => ReportRepository::operationsQueues(),
            'funnel' => ReportRepository::funnelActivity($dateFrom, $dateTo),
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        ], 'layout/base');
    }

    public function saveDefinition(array $params): void
    {
        $user = AuthService::currentUser();
        $name = trim((string) ($_POST['name'] ?? ''));
        $reportType = (string) ($_POST['report_type'] ?? 'aggregate');
        $visibility = ($_POST['visibility'] ?? '') === 'shared' ? 'shared' : 'private';

        if ($name === '') {
            Flash::set('error', 'A name is required to save a report.');
            header('Location: /reports');
            return;
        }

        $filters = [
            'date_from' => trim((string) ($_POST['date_from'] ?? '')) ?: null,
            'date_to' => trim((string) ($_POST['date_to'] ?? '')) ?: null,
            'stage_id' => ($_POST['stage_id'] ?? '') !== '' ? (int) $_POST['stage_id'] : null,
            'incoterm_id' => ($_POST['incoterm_id'] ?? '') !== '' ? (int) $_POST['incoterm_id'] : null,
            'country' => trim((string) ($_POST['country'] ?? '')) ?: null,
        ];

        ReportDefinitionRepository::create($name, $reportType, (int) $user['id'], $visibility, $filters, []);
        Flash::set('success', "Report \"{$name}\" saved.");
        header('Location: /reports');
    }

    public function runDefinition(array $params): void
    {
        $id = (int) $params['reportId'];
        $def = ReportDefinitionRepository::find($id);
        if (!$def) {
            http_response_code(404);
            echo 'Saved report not found.';
            return;
        }
        ReportDefinitionRepository::markRun($id);
        $f = $def['filters_json'];
        $qs = http_build_query(array_filter([
            'date_from' => $f['date_from'] ?? null,
            'date_to' => $f['date_to'] ?? null,
            'stage_id' => $f['stage_id'] ?? null,
            'incoterm_id' => $f['incoterm_id'] ?? null,
            'country' => $f['country'] ?? null,
        ]));
        header('Location: /reports/aggregate' . ($qs !== '' ? "?{$qs}" : ''));
    }

    public function deleteDefinition(array $params): void
    {
        $id = (int) $params['reportId'];
        $user = AuthService::currentUser();
        $def = ReportDefinitionRepository::find($id);
        if ($def && (int) $def['owner_user_id'] === (int) $user['id']) {
            ReportDefinitionRepository::delete($id);
            Flash::set('success', 'Saved report deleted.');
        } else {
            Flash::set('error', 'You can only delete your own saved reports.');
        }
        header('Location: /reports');
    }
}
