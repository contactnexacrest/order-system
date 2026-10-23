<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csv;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderSupplierPoRepository;
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
            'canManageOrderFinancials' => PermissionService::can((int) $user['id'], $roleId, 'manage_order_financials'),
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

        if (($_GET['format'] ?? '') === 'csv') {
            ['headers' => $headers, 'rows' => $csvRows] = self::orderAuditCsvRows($data['audit']);
            Csv::stream('order_' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $data['order']['order_reference']) . '_audit_trail.csv', $headers, $csvRows);
        }

        View::render('reports/order', $data, 'layout/base');
    }

    /**
     * Exports the page's "Full Audit Trail" section — the one of this
     * report's 3 sections (Stage Data, Documents, Full Audit Trail) that
     * can genuinely run long and is worth pulling into a spreadsheet;
     * Stage Data/Documents are both small, fixed-size tables already
     * visible at a glance on the page itself.
     *
     * @return array{headers: string[], rows: array<int, array<string, mixed>>}
     */
    public static function orderAuditCsvRows(array $audit): array
    {
        $rows = array_map(static fn (array $a): array => [
            'When' => $a['created_at'],
            'User' => $a['user_name'] ?? 'System',
            'Action' => $a['action_type'],
            'Field' => $a['field_name'] ?? '—',
            'Old -> New' => ($a['old_value'] !== null || $a['new_value'] !== null) ? ($a['old_value'] ?? '—') . ' -> ' . ($a['new_value'] ?? '—') : '—',
            'Reason' => $a['reason'] ?? '—',
        ], $audit);

        return ['headers' => ['When', 'User', 'Action', 'Field', 'Old -> New', 'Reason'], 'rows' => $rows];
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

    /**
     * 90-item backlog item #1 ("today's incoming/supplier payments") —
     * distinct from payments() above, which filters orders by CREATED
     * date and shows their current status regardless of when a leg
     * actually cleared. This instead answers "what cleared/was paid on
     * this specific day", both directions: buyer legs cleared
     * (order_payment_status) and supplier legs paid (order_supplier_po).
     */
    public function todaysPayments(array $params): void
    {
        $date = trim((string) ($_GET['date'] ?? '')) ?: date('Y-m-d');

        // External QA report Q-RPT-1: supplier payment amounts are
        // internal INR cost data, not something a broad view_reports
        // audience (Viewer/Auditor included) should see by default — only
        // computed/shown/exported for whoever holds manage_supplier_payments
        // (Accounts/Admin/MD/ED), same tier that can now also act on these
        // payments (see docs/migrations/2026-10-23d-split-payment-and-
        // supplier-payment-permissions.sql).
        $user = AuthService::currentUser();
        $canManageSupplierPayments = $user && PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'manage_supplier_payments');

        $incoming = OrderPaymentStatusRepository::clearedOnDate($date);
        $supplier = $canManageSupplierPayments ? OrderSupplierPoRepository::paidOnDate($date) : [];

        if (($_GET['format'] ?? '') === 'csv') {
            $rows = [];
            foreach ($incoming as $r) {
                if ($r['advance_cleared_at'] !== null && substr((string) $r['advance_cleared_at'], 0, 10) === $date) {
                    $rows[] = ['Direction' => 'Incoming', 'Leg' => 'Advance', 'Order Ref' => $r['order_reference'], 'Party' => $r['company_legal_name'], 'Currency' => $r['currency_code'], 'Amount' => $r['advance_amount']];
                }
                if ($r['balance_cleared_at'] !== null && substr((string) $r['balance_cleared_at'], 0, 10) === $date) {
                    $rows[] = ['Direction' => 'Incoming', 'Leg' => 'Balance', 'Order Ref' => $r['order_reference'], 'Party' => $r['company_legal_name'], 'Currency' => $r['currency_code'], 'Amount' => $r['balance_amount']];
                }
                if ($r['freight_cleared_at'] !== null && substr((string) $r['freight_cleared_at'], 0, 10) === $date) {
                    $rows[] = ['Direction' => 'Incoming', 'Leg' => 'Freight', 'Order Ref' => $r['order_reference'], 'Party' => $r['company_legal_name'], 'Currency' => $r['currency_code'], 'Amount' => $r['freight_amount']];
                }
            }
            foreach ($supplier as $r) {
                if ($r['advance_paid_at'] === $date) {
                    $rows[] = ['Direction' => 'Outgoing', 'Leg' => 'Advance', 'Order Ref' => $r['order_reference'], 'Party' => $r['supplier_legal_name'], 'Currency' => 'INR', 'Amount' => $r['advance_amount_inr']];
                }
                if ($r['balance_paid_at'] === $date) {
                    $rows[] = ['Direction' => 'Outgoing', 'Leg' => 'Balance', 'Order Ref' => $r['order_reference'], 'Party' => $r['supplier_legal_name'], 'Currency' => 'INR', 'Amount' => $r['balance_amount_inr']];
                }
            }
            Csv::stream('payments_' . $date . '.csv', ['Direction', 'Leg', 'Order Ref', 'Party', 'Currency', 'Amount'], $rows);
        }

        View::render('reports/todays_payments', [
            'date' => $date,
            'incoming' => $incoming,
            'supplier' => $supplier,
            'canManageSupplierPayments' => $canManageSupplierPayments,
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
                'Type' => $r['dispute_type'] === 'complaint_damage' ? 'Complaint / Damage' : 'Dispute',
                'Status' => $r['status'],
                'Response Due' => $r['response_due_date'] ?? '',
                'Resolved At' => $r['resolved_at'] ?? '',
                'Days Open' => $r['days_open'],
            ], $data['rows']);
            Csv::stream('disputes_report.csv', ['Order Ref', 'Client', 'Notice Date', 'From Party', 'Type', 'Status', 'Response Due', 'Resolved At', 'Days Open'], $rows);
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
        $months = ReportRepository::monthlyTrends(12);

        if (($_GET['format'] ?? '') === 'csv') {
            ['headers' => $headers, 'rows' => $rows] = self::trendsCsvRows($months);
            Csv::stream('trends_report.csv', $headers, $rows);
        }

        View::render('reports/trends', [
            'months' => $months,
        ], 'layout/base');
    }

    /**
     * 90-item backlog "CSV export for the 6 non-CA reports": Trends,
     * Staff Productivity, Supplier Performance, Conversion Rate, Sales
     * Performance, and the per-Order report were the 6 reports in the
     * non-CA Reports module with no CSV export at all, unlike every
     * other report here. Extracted as its own public static method (same
     * for the other 5 below) purely so this can be unit-tested without
     * exercising Csv::stream()'s exit() call.
     *
     * @return array{headers: string[], rows: array<int, array<string, mixed>>}
     */
    public static function trendsCsvRows(array $months): array
    {
        $rows = array_map(static function (array $m): array {
            $fobByCurrency = empty($m['fob_by_currency']) ? '—' : implode('; ', array_map(
                static fn ($cc, $amt) => "{$cc} " . number_format((float) $amt, 2),
                array_keys($m['fob_by_currency']),
                array_values($m['fob_by_currency'])
            ));
            return [
                'Month' => $m['month'],
                'Orders Created' => $m['orders_created'],
                'Quotations Sent' => $m['quotations_sent'],
                'PI Sent' => $m['pi_sent'],
                'Lost' => $m['lost'],
                'Total FOB Value' => $fobByCurrency,
            ];
        }, $months);

        return ['headers' => ['Month', 'Orders Created', 'Quotations Sent', 'PI Sent', 'Lost', 'Total FOB Value'], 'rows' => $rows];
    }

    /** Staff productivity report — gated on view_staff_reports, not view_reports, since it shows individual activity. */
    public function staff(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;

        $rows = ReportRepository::staffProductivity($dateFrom, $dateTo);

        if (($_GET['format'] ?? '') === 'csv') {
            ['headers' => $headers, 'rows' => $csvRows] = self::staffCsvRows($rows);
            Csv::stream('staff_productivity_report.csv', $headers, $csvRows);
        }

        View::render('reports/staff', [
            'rows' => $rows,
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        ], 'layout/base');
    }

    /** @return array{headers: string[], rows: array<int, array<string, mixed>>} */
    public static function staffCsvRows(array $rows): array
    {
        $csvRows = array_map(static function (array $r): array {
            $byType = empty($r['by_type']) ? '—' : implode('; ', array_map(
                static fn ($type, $n) => "{$type}: {$n}",
                array_keys($r['by_type']),
                array_values($r['by_type'])
            ));
            return [
                'User' => $r['user_name'],
                'Documents Generated (by type)' => $byType,
                'Documents Total' => $r['documents_total'],
                'Audit Log Actions' => $r['audit_actions'],
            ];
        }, $rows);

        return ['headers' => ['User', 'Documents Generated (by type)', 'Documents Total', 'Audit Log Actions'], 'rows' => $csvRows];
    }

    /** Debtors/receivables ageing report — closes a real gap flagged in the CA/Reports review: outstanding amounts existed, "how overdue" did not. */
    public function ageing(array $params): void
    {
        $data = ReportRepository::ageingReport();

        if (($_GET['format'] ?? '') === 'csv') {
            $rows = array_map(static fn(array $r): array => [
                'Order Ref' => $r['order_reference'],
                'Client' => $r['company_legal_name'],
                'Leg' => $r['leg'],
                'Currency' => $r['currency_code'],
                'Amount' => $r['amount'],
                'Due Date' => $r['due_date'],
                'Days Overdue' => $r['days_overdue'],
                'Bucket' => $r['bucket'],
            ], $data['rows']);
            Csv::stream('debtors_ageing_report.csv', ['Order Ref', 'Client', 'Leg', 'Currency', 'Amount', 'Due Date', 'Days Overdue', 'Bucket'], $rows);
        }

        View::render('reports/ageing', $data, 'layout/base');
    }

    /** Freight cost report — closes a real gap: freight terms/costs were recorded per order but never rolled up. */
    public function freightCost(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;

        $data = ReportRepository::freightCostReport($dateFrom, $dateTo);

        if (($_GET['format'] ?? '') === 'csv') {
            $rows = array_map(static fn(array $r): array => [
                'Order Ref' => $r['order_reference'],
                'Client' => $r['company_legal_name'],
                'Incoterm' => $r['incoterm_code'],
                'Currency' => $r['currency_code'],
                'Forwarder' => $r['freight_forwarder_name'] ?? '',
                'Confirmed Rate' => $r['confirmed_freight_rate'] ?? '',
                'Insurance' => $r['insurance_amount'] ?? '',
                'Invoiced' => $r['freight_amount'] ?? '',
                'Cleared' => $r['payment_cleared_at'] ? 'Yes' : 'No',
                'Created' => $r['created_at'],
            ], $data['rows']);
            Csv::stream('freight_cost_report.csv', ['Order Ref', 'Client', 'Incoterm', 'Currency', 'Forwarder', 'Confirmed Rate', 'Insurance', 'Invoiced', 'Cleared', 'Created'], $rows);
        }

        View::render('reports/freight_cost', [
            'rows' => $data['rows'],
            'byForwarder' => $data['by_forwarder'],
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        ], 'layout/base');
    }

    /** Product/HS-code sales report — closes a real gap: no view anywhere of which products actually drive business. */
    public function products(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;

        $data = ReportRepository::productSalesReport($dateFrom, $dateTo);

        if (($_GET['format'] ?? '') === 'csv') {
            $rows = array_map(static fn(array $r): array => [
                'HS Code' => $r['hs_code'],
                'Description' => $r['description'],
                'Currency' => $r['currency_code'],
                'Order Count' => $r['order_count'],
                'Total Quantity' => $r['total_quantity'],
                'Total FOB Value' => $r['total_fob_value'],
            ], $data['rows']);
            Csv::stream('product_sales_report.csv', ['HS Code', 'Description', 'Currency', 'Order Count', 'Total Quantity', 'Total FOB Value'], $rows);
        }

        View::render('reports/products', [
            'byHsCode' => $data['by_hs_code'],
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        ], 'layout/base');
    }

    /** Supplier performance report — closes a real gap: supplier POs were tracked per order but never rolled up. */
    public function suppliers(array $params): void
    {
        $rows = ReportRepository::supplierPerformanceReport();

        if (($_GET['format'] ?? '') === 'csv') {
            ['headers' => $headers, 'rows' => $csvRows] = self::suppliersCsvRows($rows);
            Csv::stream('supplier_performance_report.csv', $headers, $csvRows);
        }

        View::render('reports/suppliers', [
            'rows' => $rows,
        ], 'layout/base');
    }

    /** @return array{headers: string[], rows: array<int, array<string, mixed>>} */
    public static function suppliersCsvRows(array $rows): array
    {
        $csvRows = array_map(static fn (array $s): array => [
            'Supplier' => $s['supplier_name'],
            'POs' => $s['po_count'],
            'Signed' => $s['signed_count'],
            'Avg Signing Days' => $s['avg_signing_days'] ?? '—',
            'Delivery Tracked' => $s['delivery_tracked_count'],
            'On-Time %' => $s['on_time_pct'] !== null ? $s['on_time_pct'] . '%' : '—',
        ], $rows);

        return ['headers' => ['Supplier', 'POs', 'Signed', 'Avg Signing Days', 'Delivery Tracked', 'On-Time %'], 'rows' => $csvRows];
    }

    /**
     * Order Profitability Sheet, rolled up across orders — gated on
     * manage_order_financials (not the general view_reports), same
     * reasoning as the order-page panel this mirrors: it surfaces
     * per-order margin data.
     */
    public function profitability(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;

        $data = ReportRepository::orderProfitabilityReport($dateFrom, $dateTo);

        if (($_GET['format'] ?? '') === 'csv') {
            $rows = array_map(static fn(array $r): array => [
                'Order Ref' => $r['order_reference'],
                'Client' => $r['company_legal_name'],
                'Created' => $r['created_at'],
                'Revenue (INR)' => $r['revenue_inr'],
                'Estimated' => $r['revenue_is_estimated'] ? 'Yes' : 'No',
                'Total Cost (INR)' => $r['total_cost_inr'],
                'Profit (INR)' => $r['profit_inr'],
                'Margin %' => $r['margin_pct'],
            ], $data['rows']);
            Csv::stream('order_profitability_report.csv', ['Order Ref', 'Client', 'Created', 'Revenue (INR)', 'Estimated', 'Total Cost (INR)', 'Profit (INR)', 'Margin %'], $rows);
        }

        View::render('reports/profitability', [
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        ], 'layout/base');
    }

    /**
     * Conversion-rate report — closes a real gap: the funnel showed raw
     * counts, never the actual conversion percentage.
     *
     * Deliberately NOT keyed as 'data' below — View::capture()'s own
     * parameter is named $data, and extract(..., EXTR_SKIP) silently skips
     * any key that collides with an already-defined local variable, so a
     * 'data' key here would never actually reach the view (caught by
     * browser-verifying this exact page: every figure came back as an
     * "Undefined array key" warning).
     */
    public function conversion(array $params): void
    {
        $dateFrom = trim((string) ($_GET['date_from'] ?? '')) ?: null;
        $dateTo = trim((string) ($_GET['date_to'] ?? '')) ?: null;

        $conversion = ReportRepository::conversionRateReport($dateFrom, $dateTo);

        if (($_GET['format'] ?? '') === 'csv') {
            ['headers' => $headers, 'rows' => $csvRows] = self::conversionCsvRows($conversion);
            Csv::stream('conversion_report.csv', $headers, $csvRows);
        }

        View::render('reports/conversion', [
            'conversion' => $conversion,
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        ], 'layout/base');
    }

    /**
     * A Metric/Value pair table, same shape as queues()'s own
     * funnel_activity CSV, since this report (like that funnel section)
     * is one row per metric rather than one row per record.
     *
     * @return array{headers: string[], rows: array<int, array<string, mixed>>}
     */
    public static function conversionCsvRows(array $conversion): array
    {
        $pct = static fn ($v) => $v !== null ? $v . '%' : '—';
        $rows = [
            ['Metric' => 'Quotations sent', 'Value' => $conversion['quotations_sent']],
            ['Metric' => 'Quotation -> PI conversion', 'Value' => $pct($conversion['quotation_to_pi_pct'])],
            ['Metric' => 'Quotation lost rate', 'Value' => $pct($conversion['quotation_lost_pct'])],
            ['Metric' => 'PI sent', 'Value' => $conversion['pi_sent']],
            ['Metric' => 'PI -> Confirmed Order conversion', 'Value' => $pct($conversion['pi_to_confirmed_pct'])],
            ['Metric' => 'PI lost rate', 'Value' => $pct($conversion['pi_lost_pct'])],
            ['Metric' => 'Overall Quotation -> Confirmed Order', 'Value' => $pct($conversion['overall_quotation_to_confirmed_pct'])],
        ];

        return ['headers' => ['Metric', 'Value'], 'rows' => $rows];
    }

    /**
     * Sales Performance report — quotations/PIs sent, win/loss counts and
     * rate, FOB value won, and every lost order's own reason, for any
     * period, with an automatic comparison against the immediately
     * preceding period of equal length. A `preset` query param (see
     * presetRange()) fills in date_from/date_to for the common cases
     * ("This Month", "Last Quarter", ...) without a separate UI per
     * period — the underlying report is always just a date range.
     */
    public function performance(array $params): void
    {
        $preset = trim((string) ($_GET['preset'] ?? ''));
        $presetRange = $preset !== '' ? self::presetRange($preset) : null;

        $dateFrom = $presetRange[0] ?? (trim((string) ($_GET['date_from'] ?? '')) ?: null);
        $dateTo = $presetRange[1] ?? (trim((string) ($_GET['date_to'] ?? '')) ?: null);

        $report = ReportRepository::salesPerformanceReport($dateFrom, $dateTo);

        if (($_GET['format'] ?? '') === 'csv') {
            ['headers' => $headers, 'rows' => $csvRows] = self::performanceCsvRows($report);
            Csv::stream('sales_performance_report.csv', $headers, $csvRows);
        }

        View::render('reports/performance', [
            'report' => $report,
            'filters' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'preset' => $preset],
        ], 'layout/base');
    }

    /**
     * Exports the page's "Lost Orders — Detail" table — the one genuinely
     * per-record section on this report; Key Metrics/FOB Value Won are
     * both single-row-per-period summaries already visible at a glance.
     *
     * @return array{headers: string[], rows: array<int, array<string, mixed>>}
     */
    public static function performanceCsvRows(array $report): array
    {
        $rows = array_map(static fn (array $lo): array => [
            'Order' => $lo['order_reference'],
            'Client' => $lo['company_legal_name'],
            'Lost On' => (string) $lo['lost_at'],
            'Stage Reached' => $lo['reached_pi'] ? 'PI' : 'Quotation',
            'Reason' => $lo['lost_reason'] ?? '—',
        ], $report['lost_orders']);

        return ['headers' => ['Order', 'Client', 'Lost On', 'Stage Reached', 'Reason'], 'rows' => $rows];
    }

    /** @return array{0:string,1:string}|null [dateFrom, dateTo] for a named preset, calendar-based (not fiscal year) */
    private static function presetRange(string $preset): ?array
    {
        $today = new \DateTimeImmutable('today');
        switch ($preset) {
            case 'this_month':
                return [$today->modify('first day of this month')->format('Y-m-d'), $today->modify('last day of this month')->format('Y-m-d')];
            case 'last_month':
                return [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d')];
            case 'this_quarter':
            case 'last_quarter':
                $quarterStartMonth = (int) (ceil(((int) $today->format('n')) / 3) - 1) * 3 + 1;
                $start = $today->modify('first day of this month')->setDate((int) $today->format('Y'), $quarterStartMonth, 1);
                if ($preset === 'last_quarter') {
                    $start = $start->modify('-3 months');
                }
                $end = $start->modify('+2 months')->modify('last day of this month');
                return [$start->format('Y-m-d'), $end->format('Y-m-d')];
            case 'this_half_year':
            case 'last_half_year':
                $halfStartMonth = ((int) $today->format('n')) <= 6 ? 1 : 7;
                $start = $today->setDate((int) $today->format('Y'), $halfStartMonth, 1);
                if ($preset === 'last_half_year') {
                    $start = $start->modify('-6 months');
                }
                $end = $start->modify('+5 months')->modify('last day of this month');
                return [$start->format('Y-m-d'), $end->format('Y-m-d')];
            case 'this_year':
                return [$today->format('Y') . '-01-01', $today->format('Y') . '-12-31'];
            case 'last_year':
                $lastYear = ((int) $today->format('Y')) - 1;
                return [$lastYear . '-01-01', $lastYear . '-12-31'];
            default:
                return null;
        }
    }
}
