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
        $query = trim((string) ($_GET['q'] ?? ''));
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        View::render('reports/index', [
            'clients' => ClientRepository::all(),
            'incoterms' => LookupRepository::incoterms(),
            'stages' => LookupRepository::stagesMaster(),
            'countries' => ReportRepository::distinctCountries(),
            'savedReports' => ReportDefinitionRepository::visibleTo((int) $user['id']),
            'searchQuery' => $query,
            'searchResults' => $query !== '' ? ReportRepository::searchOrders($query) : [],
            'canViewStaffReports' => PermissionService::can((int) $user['id'], $roleId, 'view_staff_reports'),
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
            'summary' => ReportRepository::aggregateSummary($rows),
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

        $format = (string) ($_GET['format'] ?? '');
        if ($format === 'csv') {
            $section = (string) ($_GET['section'] ?? 'queues');
            if ($section === 'funnel') {
                $funnel = ReportRepository::funnelActivity($dateFrom, $dateTo);
                $labels = [
                    'quotationsSent' => 'Quotations sent (all)',
                    'quotationsLost' => 'Quotations lost (marked lost before reaching PI)',
                    'quotationsWon' => 'Quotations won (reached PI)',
                    'piSent' => 'PI sent (all)',
                    'piLost' => 'PI lost (marked lost after reaching PI, before CI)',
                    'amendments' => 'Amendments',
                ];
                $rows = [];
                foreach ($labels as $key => $label) {
                    $rows[] = ['Metric' => $label, 'Count' => $funnel[$key]];
                }
                Csv::stream("funnel_activity_{$dateFrom}_to_{$dateTo}.csv", ['Metric', 'Count'], $rows);
            }

            $queues = ReportRepository::operationsQueues();
            $bucketLabels = [
                'quotationAwaitingSend' => 'Quotation drafted, not yet sent to buyer',
                'buyerPoAwaited' => "Quotation sent - waiting on buyer's PO",
                'orderAcceptanceAwaitingSend' => 'Our Order-Acceptance (PO) not yet sent to buyer',
                'piStage' => 'Sitting at PI stage',
                'ocAwaitingSend' => 'Order Confirmation drafted, not yet sent',
                'ocAwaitingAck' => "Order Confirmation sent - awaiting buyer's acknowledgement",
                'supplierPoNeeded' => 'Reached Supplier PO stage - nothing drafted for our supplier yet',
                'blAwaitingSend' => 'CI issued - scanned BL not yet sent to buyer',
                'balanceAwaited' => 'Scanned BL sent - balance payment not yet received',
                'hardCopyAwaited' => 'Balance received - hard-copy document set not yet couriered',
            ];
            $rows = [];
            foreach ($bucketLabels as $key => $label) {
                foreach ($queues[$key] as $o) {
                    $rows[] = [
                        'Queue' => $label,
                        'Order Ref' => $o['order_reference'],
                        'Client' => $o['company_legal_name'],
                        'Created' => $o['created_at'],
                    ];
                }
            }
            Csv::stream('operations_queues.csv', ['Queue', 'Order Ref', 'Client', 'Created'], $rows);
        }

        View::render('reports/queues', [
            'queues' => ReportRepository::operationsQueues(),
            'funnel' => ReportRepository::funnelActivity($dateFrom, $dateTo),
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        ], 'layout/base');
    }

    /**
     * Consolidated financial/payments report (added to close a real gap:
     * neither the dashboard's two overdue lists nor the per-client report's
     * own totals ever showed collected-vs-outstanding across the whole
     * business). Filtered the same way as the Aggregate Report (order
     * created_at date range) for predictable, consistent semantics.
     */
    public function payments(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;

        $data = ReportRepository::paymentsReport($dateFrom, $dateTo);

        if (($_GET['format'] ?? '') === 'csv') {
            $rows = array_map(static fn(array $r): array => [
                'Order Ref' => $r['order_reference'],
                'Client' => $r['company_legal_name'],
                'Currency' => $r['currency_code'],
                'Status' => $r['status'],
                'Advance Invoiced' => $r['advance_amount'] ?? '',
                'Advance Cleared' => $r['advance_cleared_at'] ? 'Yes' : ($r['advance_amount'] !== null ? 'No' : ''),
                'Advance Outstanding' => $r['advance_outstanding'] !== null ? number_format($r['advance_outstanding'], 2, '.', '') : '',
                'Balance Invoiced' => $r['balance_amount'] ?? '',
                'Balance Cleared' => $r['balance_cleared_at'] ? 'Yes' : ($r['balance_amount'] !== null ? 'No' : ''),
                'Balance Outstanding' => $r['balance_outstanding'] !== null ? number_format($r['balance_outstanding'], 2, '.', '') : '',
                'Freight Invoiced' => $r['freight_amount'] ?? '',
                'Freight Cleared' => $r['freight_cleared_at'] ? 'Yes' : ($r['freight_amount'] !== null ? 'No' : ''),
                'Freight Outstanding' => $r['freight_outstanding'] !== null ? number_format($r['freight_outstanding'], 2, '.', '') : '',
                'Created' => $r['created_at'],
            ], $data['rows']);
            Csv::stream('payments_report.csv', [
                'Order Ref', 'Client', 'Currency', 'Status',
                'Advance Invoiced', 'Advance Cleared', 'Advance Outstanding',
                'Balance Invoiced', 'Balance Cleared', 'Balance Outstanding',
                'Freight Invoiced', 'Freight Cleared', 'Freight Outstanding',
                'Created',
            ], $rows);
        }

        View::render('reports/payments', [
            'rows' => $data['rows'],
            'byCurrency' => $data['by_currency'],
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

    public function updateDefinition(array $params): void
    {
        $id = (int) $params['reportId'];
        $user = AuthService::currentUser();
        $def = ReportDefinitionRepository::find($id);
        if (!$def) {
            Flash::set('error', 'Saved report not found.');
            header('Location: /reports');
            return;
        }
        if ((int) $def['owner_user_id'] !== (int) $user['id']) {
            Flash::set('error', 'You can only edit your own saved reports.');
            header('Location: /reports');
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'A name is required.');
            header('Location: /reports');
            return;
        }
        $visibility = ($_POST['visibility'] ?? '') === 'shared' ? 'shared' : 'private';

        ReportDefinitionRepository::updateNameVisibility($id, $name, $visibility);
        Flash::set('success', "\"{$name}\" updated.");
        header('Location: /reports');
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

    /** Cross-order dispute report — closes the gap where disputes only ever showed up as generic audit-log rows. */
    public function disputes(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;

        $data = ReportRepository::disputesReport($dateFrom, $dateTo);

        if (($_GET['format'] ?? '') === 'csv') {
            $rows = array_map(static fn(array $r): array => [
                'Order Ref' => $r['order_reference'],
                'Client' => $r['company_legal_name'],
                'Notice Date' => $r['notice_date'],
                'From Party' => $r['from_party'] ?? '',
                'Status' => $r['status'],
                'Response Due' => $r['response_due_date'] ?? '',
                'Resolved At' => $r['resolved_at'] ?? '',
                'Days Open' => $r['days_open'],
            ], $data['rows']);
            Csv::stream('disputes_report.csv', ['Order Ref', 'Client', 'Notice Date', 'From Party', 'Status', 'Response Due', 'Resolved At', 'Days Open'], $rows);
        }

        View::render('reports/disputes', [
            'rows' => $data['rows'],
            'byStatus' => $data['by_status'],
            'openCount' => $data['open_count'],
            'avgResolutionDays' => $data['avg_resolution_days'],
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        ], 'layout/base');
    }

    /** Cross-order amendment report — closes the gap where amendments only ever showed a bare count in the funnel section. */
    public function amendments(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;

        $data = ReportRepository::amendmentsReport($dateFrom, $dateTo);

        if (($_GET['format'] ?? '') === 'csv') {
            $rows = array_map(static fn(array $r): array => [
                'Amendment Ref' => $r['amendment_reference'],
                'Order Ref' => $r['order_reference'],
                'Client' => $r['company_legal_name'],
                'Requested By' => $r['requested_by'],
                'Status' => $r['status'],
                'Reason' => $r['reason'],
                'Currency' => $r['currency_code'],
                'Amended Advance' => $r['amended_advance_amount'] ?? '',
                'Amended Balance' => $r['amended_balance_amount'] ?? '',
                'Effective From' => $r['effective_from'] ?? '',
                'Created' => $r['created_at'],
            ], $data['rows']);
            Csv::stream('amendments_report.csv', ['Amendment Ref', 'Order Ref', 'Client', 'Requested By', 'Status', 'Reason', 'Currency', 'Amended Advance', 'Amended Balance', 'Effective From', 'Created'], $rows);
        }

        View::render('reports/amendments', [
            'rows' => $data['rows'],
            'byStatus' => $data['by_status'],
            'byRequestedBy' => $data['by_requested_by'],
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        ], 'layout/base');
    }

    /** Month-over-month trend view — everything else in this module is either a snapshot or a single flat total. */
    public function trends(array $params): void
    {
        View::render('reports/trends', [
            'months' => ReportRepository::monthlyTrends(12),
        ], 'layout/base');
    }

    /** Staff productivity report — gated on view_staff_reports, not view_reports, since it shows individual activity. */
    public function staff(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;

        View::render('reports/staff', [
            'rows' => ReportRepository::staffProductivity($dateFrom, $dateTo),
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        ], 'layout/base');
    }
}
