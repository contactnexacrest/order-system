<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Services\TestModeService;

/**
 * Spec Section 16 — "REPORTS: Per client ... Per order ... Aggregate ...
 * Export to CSV/Excel." Three query shapes, one per report type named in
 * the spec — kept as plain read queries here; ReportService decides which
 * one a saved report_definitions row runs.
 *
 * Test Mode (docs/schema.sql Section V) — reports must never mix test and
 * production data. Every query below is scoped to match the CURRENT mode:
 * with Test Mode on, only test-flagged orders/clients show up; off, only
 * real ones do. isTestModeFlag() is the one place that reads the switch.
 */
final class ReportRepository
{
    private static function isTestModeFlag(): int
    {
        return TestModeService::isEnabled() ? 1 : 0;
    }

    /** @return array<string,mixed> order history, payments, products, totals for one client */
    public static function perClient(int $clientId): array
    {
        $pdo = Database::connection();
        $isTestMode = self::isTestModeFlag();

        $client = $pdo->prepare('SELECT * FROM clients WHERE id = :id');
        $client->execute(['id' => $clientId]);
        $client = $client->fetch() ?: null;
        if ($client && (int) $client['is_test_data'] !== $isTestMode) {
            // A real client while Test Mode is on, or a test client while
            // it's off — never shown, same contract as "client not found".
            return ['client' => null, 'orders' => [], 'documents' => [], 'payments' => [], 'products' => [], 'total_fob_value_by_currency' => [], 'total_cleared_by_currency' => []];
        }

        $orders = $pdo->prepare(
            "SELECT o.id, o.order_reference, o.status, o.created_at,
                    i.code AS incoterm_code, cur.code AS currency_code,
                    sm.stage_name AS current_stage_name
             FROM orders o
             JOIN incoterms i ON i.id = o.incoterm_id
             JOIN currencies cur ON cur.id = o.currency_id
             LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
             WHERE o.client_id = :client_id AND o.is_test_data = :is_test_data
             ORDER BY o.created_at DESC"
        );
        $orders->execute(['client_id' => $clientId, 'is_test_data' => $isTestMode]);
        $orders = $orders->fetchAll();

        $orderIds = array_map(static fn(array $o): int => (int) $o['id'], $orders);
        $documents = [];
        $payments = [];
        $products = [];
        // Keyed by currency_code — see "currency-blind totals" fix below.
        // A single client can have orders in different currencies (currency_id
        // lives on `orders`, not `clients`), so a single scalar total would
        // silently add e.g. USD and EUR amounts together as if they were the
        // same unit. Every row below carries its own order's currency_code
        // (joined in SQL, not assumed), and totals are grouped by it.
        $totalFobByCurrency = [];
        $totalClearedByCurrency = [];

        if ($orderIds) {
            $in = implode(',', array_fill(0, count($orderIds), '?'));

            $stmt = $pdo->prepare(
                "SELECT d.order_id, dt.code AS type_code, d.document_reference, d.revision_number, d.status, d.generated_at
                 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
                 WHERE d.order_id IN ($in) ORDER BY d.order_id, d.generated_at"
            );
            $stmt->execute($orderIds);
            $documents = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                "SELECT ops.order_id, ops.advance_amount, ops.advance_cleared_at, ops.balance_amount, ops.balance_cleared_at,
                        ops.freight_amount, ops.freight_cleared_at, cur.code AS currency_code
                 FROM order_payment_status ops
                 JOIN orders o2 ON o2.id = ops.order_id
                 JOIN currencies cur ON cur.id = o2.currency_id
                 WHERE ops.order_id IN ($in)"
            );
            $stmt->execute($orderIds);
            $payments = $stmt->fetchAll();
            foreach ($payments as $p) {
                $cc = $p['currency_code'];
                if ($p['advance_cleared_at']) {
                    $totalClearedByCurrency[$cc] = ($totalClearedByCurrency[$cc] ?? 0.0) + (float) $p['advance_amount'];
                }
                if ($p['balance_cleared_at']) {
                    $totalClearedByCurrency[$cc] = ($totalClearedByCurrency[$cc] ?? 0.0) + (float) $p['balance_amount'];
                }
            }

            $stmt = $pdo->prepare(
                "SELECT p.order_id, p.description, p.quantity, p.quantity_is_tbc, p.unit, p.unit_price, p.fob_value, cur.code AS currency_code
                 FROM order_products p
                 JOIN orders o2 ON o2.id = p.order_id
                 JOIN currencies cur ON cur.id = o2.currency_id
                 WHERE p.order_id IN ($in) AND p.is_active = 1 ORDER BY p.order_id, p.line_no"
            );
            $stmt->execute($orderIds);
            $products = $stmt->fetchAll();
            foreach ($products as $prod) {
                $cc = $prod['currency_code'];
                $totalFobByCurrency[$cc] = ($totalFobByCurrency[$cc] ?? 0.0) + (float) ($prod['fob_value'] ?? 0);
            }
        }

        ksort($totalFobByCurrency);
        ksort($totalClearedByCurrency);

        return [
            'client' => $client,
            'orders' => $orders,
            'documents' => $documents,
            'payments' => $payments,
            'products' => $products,
            'total_fob_value_by_currency' => $totalFobByCurrency,
            'total_cleared_by_currency' => $totalClearedByCurrency,
        ];
    }

    /** @return array<string,mixed> all stage data, all documents, full audit for one order */
    public static function perOrder(int $orderId): array
    {
        $pdo = Database::connection();
        $isTestMode = self::isTestModeFlag();

        $order = OrderRepository::find($orderId);
        if ($order && (int) $order['is_test_data'] !== $isTestMode) {
            return ['order' => null, 'stages' => [], 'documents' => [], 'audit' => []];
        }

        $stages = $pdo->prepare(
            "SELECT sm.stage_name, sm.sequence, os.status, os.unlocked_at, os.gate_passed_at, os.skip_reason,
                    u.name AS gate_passed_by_name
             FROM order_stages os
             JOIN stages_master sm ON sm.id = os.stage_id
             LEFT JOIN users u ON u.id = os.gate_passed_by
             WHERE os.order_id = :order_id ORDER BY sm.sequence"
        );
        $stages->execute(['order_id' => $orderId]);
        $stages = $stages->fetchAll();

        $documents = DocumentRepository::forOrder($orderId);

        // "Full audit" for an order = audit_log rows against the order
        // itself, plus every document/amendment/dispute that belongs to it
        // — audit_log has no order_id column of its own (by design: one
        // immutable log for every entity type in the system, not a
        // per-order shadow table), so this assembles the order's audit
        // trail by first collecting the entity ids that belong to it.
        $documentIds = array_map(static fn(array $d): int => (int) $d['id'], $documents);
        $amendmentIds = array_column(AmendmentRepository::forOrder($orderId), 'id');
        $disputeIds = array_column(DisputeRepository::forOrder($orderId), 'id');

        $conditions = ['(al.entity_type = \'orders\' AND al.entity_id = :order_id)'];
        $params = ['order_id' => $orderId];
        if ($documentIds) {
            $in = implode(',', array_map('intval', $documentIds));
            $conditions[] = "(al.entity_type = 'documents' AND al.entity_id IN ($in))";
        }
        if ($amendmentIds) {
            $in = implode(',', array_map('intval', $amendmentIds));
            $conditions[] = "(al.entity_type = 'amendments' AND al.entity_id IN ($in))";
        }
        if ($disputeIds) {
            $in = implode(',', array_map('intval', $disputeIds));
            $conditions[] = "(al.entity_type = 'disputes' AND al.entity_id IN ($in))";
        }

        $stmt = $pdo->prepare(
            'SELECT al.*, u.name AS user_name FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
             WHERE ' . implode(' OR ', $conditions) . ' ORDER BY al.created_at ASC'
        );
        $stmt->execute($params);
        $audit = $stmt->fetchAll();

        return [
            'order' => $order,
            'stages' => $stages,
            'documents' => $documents,
            'audit' => $audit,
        ];
    }

    /**
     * Aggregate report — date range / stage / Incoterm / country filters,
     * per the spec's own phrasing. All filters optional and combinable.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function aggregate(
        ?string $dateFrom,
        ?string $dateTo,
        ?int $stageId,
        ?int $incotermId,
        ?string $country
    ): array {
        $where = ['o.is_test_data = :is_test_data'];
        $params = ['is_test_data' => self::isTestModeFlag()];
        if ($dateFrom) {
            $where[] = 'DATE(o.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $where[] = 'DATE(o.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }
        if ($stageId) {
            $where[] = 'o.current_stage_id = :stage_id';
            $params['stage_id'] = $stageId;
        }
        if ($incotermId) {
            $where[] = 'o.incoterm_id = :incoterm_id';
            $params['incoterm_id'] = $incotermId;
        }
        if ($country) {
            $where[] = 'c.country_of_destination = :country';
            $params['country'] = $country;
        }

        $sql = "SELECT o.id, o.order_reference, o.status, o.created_at,
                       c.company_legal_name, c.country_of_destination,
                       i.code AS incoterm_code, cur.code AS currency_code,
                       sm.stage_name AS current_stage_name,
                       (SELECT COALESCE(SUM(fob_value), 0) FROM order_products WHERE order_id = o.id AND is_active = 1) AS total_fob_value
                FROM orders o
                JOIN clients c ON c.id = o.client_id
                JOIN incoterms i ON i.id = o.incoterm_id
                JOIN currencies cur ON cur.id = o.currency_id
                LEFT JOIN stages_master sm ON sm.id = o.current_stage_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY o.created_at DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Summary statistics for the Aggregate Report — computed from the exact
     * same row set aggregate() returns (never a second, separate query), so
     * the summary numbers and the detail table beneath them can never
     * disagree about what's included. Pass it the same $rows the caller
     * already fetched from aggregate() and/or is about to render/export.
     *
     * @param array<int, array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public static function aggregateSummary(array $rows): array
    {
        $fobByCurrency = [];
        $byStage = [];
        $byStatus = [];
        $byCountry = [];

        foreach ($rows as $r) {
            $cc = $r['currency_code'];
            $fobByCurrency[$cc] = ($fobByCurrency[$cc] ?? 0.0) + (float) $r['total_fob_value'];

            $stage = $r['current_stage_name'] ?? 'Quotation';
            $byStage[$stage] = ($byStage[$stage] ?? 0) + 1;

            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;

            $country = $r['country_of_destination'] ?? '—';
            $byCountry[$country] = ($byCountry[$country] ?? 0) + 1;
        }

        ksort($fobByCurrency);
        arsort($byStage);
        arsort($byStatus);
        arsort($byCountry);

        return [
            'total_orders' => count($rows),
            'fob_by_currency' => $fobByCurrency,
            'by_stage' => $byStage,
            'by_status' => $byStatus,
            'by_country' => $byCountry,
        ];
    }

    /** @return string[] distinct country_of_destination values seeded so far, for the aggregate filter dropdown */
    public static function distinctCountries(): array
    {
        $stmt = Database::connection()->query(
            "SELECT DISTINCT country_of_destination FROM clients WHERE country_of_destination IS NOT NULL AND country_of_destination != '' ORDER BY country_of_destination"
        );
        return array_column($stmt->fetchAll(), 'country_of_destination');
    }

    // ================================================================
    // PAYMENTS / FINANCIAL REPORT
    // ================================================================
    //
    // "Consolidated financial visibility" was missing entirely before this:
    // the dashboard only ever showed two overdue lists, and the per-client
    // report only ever showed one client's own payments. This answers
    // "how much have we actually collected vs. how much is still
    // outstanding, across the whole business, by currency" in one place.
    //
    // Correctness discipline: the per-currency summary below is computed in
    // PHP from the SAME $rows the detail table renders — never a second,
    // independent SQL query — so the two can never disagree about what a
    // given order contributed. "Outstanding" only counts an amount that was
    // actually invoiced (order_payment_status.*_amount IS NOT NULL); an
    // order with no freight amount set (e.g. FOB terms, buyer arranges
    // freight) contributes nothing to the freight bucket at all, not a
    // false "0 outstanding".

    /**
     * @return array{rows: array<int,array<string,mixed>>, by_currency: array<string,array<string,float>>}
     */
    public static function paymentsReport(?string $dateFrom, ?string $dateTo): array
    {
        $where = ['o.is_test_data = :is_test_data'];
        $params = ['is_test_data' => self::isTestModeFlag()];
        if ($dateFrom) {
            $where[] = 'DATE(o.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $where[] = 'DATE(o.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = "SELECT o.id, o.order_reference, o.status, o.created_at, c.company_legal_name, cur.code AS currency_code,
                       ops.advance_amount, ops.advance_cleared_at,
                       ops.balance_amount, ops.balance_cleared_at,
                       ops.freight_amount, ops.freight_cleared_at
                FROM orders o
                JOIN clients c ON c.id = o.client_id
                JOIN currencies cur ON cur.id = o.currency_id
                LEFT JOIN order_payment_status ops ON ops.order_id = o.id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY o.created_at DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Per-row outstanding, computed once here and reused everywhere
        // (view table, CSV export) — nobody else re-derives this formula.
        foreach ($rows as &$row) {
            $row['advance_outstanding'] = $row['advance_amount'] !== null
                ? round((float) $row['advance_amount'] - ($row['advance_cleared_at'] ? (float) $row['advance_amount'] : 0.0), 2)
                : null;
            $row['balance_outstanding'] = $row['balance_amount'] !== null
                ? round((float) $row['balance_amount'] - ($row['balance_cleared_at'] ? (float) $row['balance_amount'] : 0.0), 2)
                : null;
            $row['freight_outstanding'] = $row['freight_amount'] !== null
                ? round((float) $row['freight_amount'] - ($row['freight_cleared_at'] ? (float) $row['freight_amount'] : 0.0), 2)
                : null;
        }
        unset($row);

        $byCurrency = [];
        foreach ($rows as $r) {
            $cc = $r['currency_code'];
            if (!isset($byCurrency[$cc])) {
                $byCurrency[$cc] = [
                    'advance_invoiced' => 0.0, 'advance_cleared' => 0.0,
                    'balance_invoiced' => 0.0, 'balance_cleared' => 0.0,
                    'freight_invoiced' => 0.0, 'freight_cleared' => 0.0,
                ];
            }
            if ($r['advance_amount'] !== null) {
                $byCurrency[$cc]['advance_invoiced'] += (float) $r['advance_amount'];
                if ($r['advance_cleared_at']) {
                    $byCurrency[$cc]['advance_cleared'] += (float) $r['advance_amount'];
                }
            }
            if ($r['balance_amount'] !== null) {
                $byCurrency[$cc]['balance_invoiced'] += (float) $r['balance_amount'];
                if ($r['balance_cleared_at']) {
                    $byCurrency[$cc]['balance_cleared'] += (float) $r['balance_amount'];
                }
            }
            if ($r['freight_amount'] !== null) {
                $byCurrency[$cc]['freight_invoiced'] += (float) $r['freight_amount'];
                if ($r['freight_cleared_at']) {
                    $byCurrency[$cc]['freight_cleared'] += (float) $r['freight_amount'];
                }
            }
        }

        foreach ($byCurrency as $cc => &$b) {
            $b['advance_outstanding'] = round($b['advance_invoiced'] - $b['advance_cleared'], 2);
            $b['balance_outstanding'] = round($b['balance_invoiced'] - $b['balance_cleared'], 2);
            $b['freight_outstanding'] = round($b['freight_invoiced'] - $b['freight_cleared'], 2);
            $b['total_outstanding'] = round($b['advance_outstanding'] + $b['balance_outstanding'] + $b['freight_outstanding'], 2);
            $b['advance_invoiced'] = round($b['advance_invoiced'], 2);
            $b['advance_cleared'] = round($b['advance_cleared'], 2);
            $b['balance_invoiced'] = round($b['balance_invoiced'], 2);
            $b['balance_cleared'] = round($b['balance_cleared'], 2);
            $b['freight_invoiced'] = round($b['freight_invoiced'], 2);
            $b['freight_cleared'] = round($b['freight_cleared'], 2);
        }
        unset($b);
        ksort($byCurrency);

        return ['rows' => $rows, 'by_currency' => $byCurrency];
    }

    // ================================================================
    // OPERATIONS QUEUES / FUNNEL REPORT — added 2026-09-19
    // ================================================================
    //
    // Every bucket below reads columns that already existed for the order
    // workflow itself (order_stages via orders.current_stage_id, documents.status,
    // order_payment_status, order_shipping, order_supplier_po) plus the new
    // orders.status = 'lost' value and its lost_reason/lost_at/lost_by columns.
    // Nothing here is a new source of truth — it's new queries against data the
    // app was already recording.
    //
    // Key fact this relies on: orders.current_stage_id always holds the FRONTIER
    // stage — the one whose gate has NOT yet passed (StageGateService moves it
    // forward the instant a gate passes). So "current_stage_id = X" already means
    // "waiting at X", with no need to separately inspect order_stages.status.
    //
    // "Latest document of type X for this order" is always
    // `ORDER BY revision_number DESC LIMIT 1` — matches DocumentRepository's own
    // latestOfType() convention, so a document that was superseded by a later
    // revision is correctly ignored in favor of the current one.

    private const ORDER_ROW_COLS = 'o.id, o.order_reference, c.company_legal_name, o.created_at';
    private const ORDER_ROW_JOIN = 'FROM orders o JOIN clients c ON c.id = o.client_id';

    private static function latestDocStatusExpr(string $code): string
    {
        return "(SELECT d.status FROM documents d
                   JOIN document_types dt ON dt.id = d.document_type_id
                  WHERE dt.code = '{$code}' AND d.order_id = o.id
                  ORDER BY d.revision_number DESC LIMIT 1)";
    }

    private static function notSentClause(string $code): string
    {
        $expr = self::latestDocStatusExpr($code);
        return "({$expr} IS NULL OR {$expr} <> 'sent')";
    }

    private static function sentClause(string $code): string
    {
        return self::latestDocStatusExpr($code) . " = 'sent'";
    }

    /** @return array<int, array<string,mixed>> */
    private static function queueRows(string $whereSql, string $extraJoins = ''): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::ORDER_ROW_COLS . ' ' . self::ORDER_ROW_JOIN . " {$extraJoins} WHERE ({$whereSql}) AND o.is_test_data = :is_test_data ORDER BY o.created_at"
        );
        $stmt->execute(['is_test_data' => self::isTestModeFlag()]);
        return $stmt->fetchAll();
    }

    /** Bucket 1 — Quotation not yet dispatched to the buyer (drafted or not even generated yet). */
    private static function quotationAwaitingDispatch(): array
    {
        return self::queueRows("o.status = 'active' AND " . self::notSentClause('QT'));
    }

    /** Bucket 2 (spec item #15) — QT sent, buyer's own PO not yet recorded (Stage 2, "Buyer PO"). */
    private static function awaitingBuyerPo(): array
    {
        return self::queueRows(
            "o.status = 'active' AND sm.stage_slug = 'buyer_po' AND " . self::sentClause('QT'),
            'JOIN stages_master sm ON sm.id = o.current_stage_id'
        );
    }

    /** Spec item #14 — our own Order-Acceptance PO (document type BUYERPO) not yet sent back to the buyer. */
    private static function orderAcceptanceAwaitingDispatch(): array
    {
        return self::queueRows(
            "o.status = 'active' AND sm.stage_slug NOT IN ('quotation','buyer_po') AND " . self::notSentClause('BUYERPO'),
            'JOIN stages_master sm ON sm.id = o.current_stage_id'
        );
    }

    /** Spec item #2 — sitting at the PI stage right now. */
    private static function atPiStage(): array
    {
        return self::queueRows("o.status = 'active' AND sm.stage_slug = 'pi'", 'JOIN stages_master sm ON sm.id = o.current_stage_id');
    }

    /** Spec item #11 — at the OC stage, OC not yet sent. */
    private static function ocAwaitingDispatch(): array
    {
        return self::queueRows(
            "o.status = 'active' AND sm.stage_slug = 'oc_production' AND " . self::notSentClause('OC'),
            'JOIN stages_master sm ON sm.id = o.current_stage_id'
        );
    }

    /** Spec item #12 — OC sent, buyer's acknowledgement not yet recorded (OrderOcAcknowledgmentRepository — client portal, staff-recorded email reply, or 48h auto-confirm). */
    private static function ocAwaitingAcknowledgement(): array
    {
        return self::queueRows(
            "o.status = 'active' AND sm.stage_slug = 'oc_production' AND " . self::sentClause('OC'),
            'JOIN stages_master sm ON sm.id = o.current_stage_id'
        );
    }

    /** Spec item #16 — reached Supplier PO stage or beyond, nothing drafted yet in order_supplier_po. */
    private static function supplierPoNotYetCreated(): array
    {
        return self::queueRows(
            "o.status = 'active'
             AND sm.stage_slug NOT IN ('quotation','buyer_po','pi','oc_production')
             AND NOT EXISTS (SELECT 1 FROM order_supplier_po sp WHERE sp.order_id = o.id)",
            'JOIN stages_master sm ON sm.id = o.current_stage_id'
        );
    }

    /** Spec item #3 — CI sent, balance unpaid, scanned BL not yet sent to the buyer. */
    private static function ciAwaitingScannedBl(): array
    {
        return self::queueRows(
            "o.status = 'active' AND " . self::sentClause('CI') . ' AND os.scanned_bl_sent_to_buyer_at IS NULL',
            'JOIN order_shipping os ON os.order_id = o.id'
        );
    }

    /** Spec item #4 — CI sent, scanned BL already sent, balance payment not yet received. */
    private static function ciAwaitingBalancePayment(): array
    {
        return self::queueRows(
            "o.status = 'active' AND " . self::sentClause('CI') . '
             AND os.scanned_bl_sent_to_buyer_at IS NOT NULL
             AND ops.balance_remittance_received_at IS NULL',
            'JOIN order_shipping os ON os.order_id = o.id
             JOIN order_payment_status ops ON ops.order_id = o.id'
        );
    }

    /** Spec item #5 — balance payment cleared, hard-copy document set not yet couriered to the buyer. */
    private static function ciAwaitingHardCopyDespatch(): array
    {
        return self::queueRows(
            "o.status = 'active' AND " . self::sentClause('CI') . '
             AND ops.balance_cleared_at IS NOT NULL
             AND os.courier_sent_at IS NULL',
            'JOIN order_shipping os ON os.order_id = o.id
             JOIN order_payment_status ops ON ops.order_id = o.id'
        );
    }

    /** Snapshot dashboard (spec items #1, #2, #3, #4, #5, #11, #12, #14, #15, #16). No date range — "right now". */
    public static function operationsQueues(): array
    {
        return [
            'quotationAwaitingSend' => self::quotationAwaitingDispatch(), // #1
            'buyerPoAwaited' => self::awaitingBuyerPo(), // #15
            'orderAcceptanceAwaitingSend' => self::orderAcceptanceAwaitingDispatch(), // #14
            'piStage' => self::atPiStage(), // #2
            'ocAwaitingSend' => self::ocAwaitingDispatch(), // #11
            'ocAwaitingAck' => self::ocAwaitingAcknowledgement(), // #12
            'supplierPoNeeded' => self::supplierPoNotYetCreated(), // #16
            'blAwaitingSend' => self::ciAwaitingScannedBl(), // #3
            'balanceAwaited' => self::ciAwaitingBalancePayment(), // #4
            'hardCopyAwaited' => self::ciAwaitingHardCopyDespatch(), // #5
        ];
    }

    private static function sentCount(string $code, string $dateCol, ?string $dateFrom, ?string $dateTo): int
    {
        $clauses = ['dt.code = :code', "el.status = 'sent'", 'o.is_test_data = :is_test_data'];
        $params = ['code' => $code, 'is_test_data' => self::isTestModeFlag()];
        if ($dateFrom) {
            $clauses[] = "DATE(el.{$dateCol}) >= :date_from";
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $clauses[] = "DATE(el.{$dateCol}) <= :date_to";
            $params['date_to'] = $dateTo;
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(DISTINCT el.document_id) AS n
               FROM email_log el
               JOIN documents d ON d.id = el.document_id
               JOIN document_types dt ON dt.id = d.document_type_id
               JOIN orders o ON o.id = d.order_id
              WHERE ' . implode(' AND ', $clauses)
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int) ($row['n'] ?? 0);
    }

    private static function lostCount(bool $reachedPi, ?string $dateFrom, ?string $dateTo): int
    {
        $reachedClause = "EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE dt.code = 'PI' AND d.order_id = o.id)";
        $clauses = ["o.status = 'lost'", $reachedPi ? $reachedClause : "NOT {$reachedClause}", 'o.is_test_data = :is_test_data'];
        $params = ['is_test_data' => self::isTestModeFlag()];
        if ($dateFrom) {
            $clauses[] = 'DATE(o.lost_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $clauses[] = 'DATE(o.lost_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS n FROM orders o WHERE ' . implode(' AND ', $clauses)
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int) ($row['n'] ?? 0);
    }

    private static function quotationsWonCount(?string $dateFrom, ?string $dateTo): int
    {
        $clauses = [
            "EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE dt.code = 'PI' AND d.order_id = o.id)",
            'o.is_test_data = :is_test_data',
        ];
        $params = ['is_test_data' => self::isTestModeFlag()];
        if ($dateFrom) {
            $clauses[] = 'o.pi_date >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $clauses[] = 'o.pi_date <= :date_to';
            $params['date_to'] = $dateTo;
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS n FROM orders o WHERE ' . implode(' AND ', $clauses)
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int) ($row['n'] ?? 0);
    }

    private static function amendmentCount(?string $dateFrom, ?string $dateTo): int
    {
        $clauses = ["EXISTS (SELECT 1 FROM orders oo WHERE oo.id = amendments.order_id AND oo.is_test_data = :is_test_data)"];
        $params = ['is_test_data' => self::isTestModeFlag()];
        if ($dateFrom) {
            $clauses[] = 'DATE(created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $clauses[] = 'DATE(created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS n FROM amendments WHERE ' . implode(' AND ', $clauses)
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Date-ranged funnel activity (spec items #6-10, #13). "Reached PI" is the
     * fork point between the quotation funnel and the PI funnel: an order that
     * was marked lost before a PI was ever generated counts as a lost quotation;
     * one lost after a PI exists counts as a lost PI, not a lost quotation.
     * Quotation/PI "sent" counts are anchored on email_log.sent_at (the actual
     * dispatch event), not documents.generated_at (drafting), since a document
     * can sit generated-but-unsent for days.
     */
    public static function funnelActivity(?string $dateFrom, ?string $dateTo): array
    {
        return [
            'quotationsSent' => self::sentCount('QT', 'sent_at', $dateFrom, $dateTo), // #6
            'quotationsLost' => self::lostCount(false, $dateFrom, $dateTo), // #7
            'quotationsWon' => self::quotationsWonCount($dateFrom, $dateTo), // #8
            'piSent' => self::sentCount('PI', 'sent_at', $dateFrom, $dateTo), // #9
            'piLost' => self::lostCount(true, $dateFrom, $dateTo), // #10
            'amendments' => self::amendmentCount($dateFrom, $dateTo), // #13
        ];
    }

    // ================================================================
    // DISPUTE REPORT
    // ================================================================
    // Disputes previously only ever surfaced as generic audit_log rows
    // inside the per-order report — no cross-order view of "how many are
    // open, how old is the oldest one, how long do we typically take to
    // resolve one." days_open is computed once, in SQL, per row; the
    // status/avg-resolution summary below is folded from those SAME rows
    // in PHP — never a second, independent query — so it can't disagree
    // with the detail table.

    /** @return array{rows: array<int,array<string,mixed>>, by_status: array<string,int>, open_count: int, avg_resolution_days: ?float} */
    public static function disputesReport(?string $dateFrom, ?string $dateTo): array
    {
        $where = ['o.is_test_data = :is_test_data'];
        $params = ['is_test_data' => self::isTestModeFlag()];
        if ($dateFrom) {
            $where[] = 'd.notice_date >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $where[] = 'd.notice_date <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = "SELECT d.id, d.order_id, o.order_reference, c.company_legal_name, d.notice_date, d.from_party,
                       d.status, d.response_due_date, d.resolved_at, d.created_at,
                       DATEDIFF(COALESCE(d.resolved_at, NOW()), d.notice_date) AS days_open
                FROM disputes d
                JOIN orders o ON o.id = d.order_id
                JOIN clients c ON c.id = o.client_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY d.notice_date DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $byStatus = [];
        $openCount = 0;
        $resolvedDaysSum = 0;
        $resolvedCount = 0;
        foreach ($rows as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
            if ($r['status'] !== 'Resolved') {
                $openCount++;
            } else {
                $resolvedDaysSum += (int) $r['days_open'];
                $resolvedCount++;
            }
        }
        arsort($byStatus);

        return [
            'rows' => $rows,
            'by_status' => $byStatus,
            'open_count' => $openCount,
            'avg_resolution_days' => $resolvedCount > 0 ? round($resolvedDaysSum / $resolvedCount, 1) : null,
        ];
    }

    // ================================================================
    // AMENDMENT REPORT
    // ================================================================
    // Amendments previously only ever surfaced as a bare count in the
    // funnel section, or as generic audit_log rows in the per-order
    // report. This lists every amendment with its actual terms and a
    // status/requested-by breakdown folded from the same rows.

    /** @return array{rows: array<int,array<string,mixed>>, by_status: array<string,int>, by_requested_by: array<string,int>} */
    public static function amendmentsReport(?string $dateFrom, ?string $dateTo): array
    {
        $where = ['o.is_test_data = :is_test_data'];
        $params = ['is_test_data' => self::isTestModeFlag()];
        if ($dateFrom) {
            $where[] = 'DATE(a.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $where[] = 'DATE(a.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = "SELECT a.id, a.amendment_reference, a.order_id, o.order_reference, c.company_legal_name,
                       a.reason, a.requested_by, a.status, a.effective_from, a.created_at,
                       a.amended_advance_amount, a.amended_balance_amount, cur.code AS currency_code
                FROM amendments a
                JOIN orders o ON o.id = a.order_id
                JOIN clients c ON c.id = o.client_id
                JOIN currencies cur ON cur.id = o.currency_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY a.created_at DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $byStatus = [];
        $byRequestedBy = [];
        foreach ($rows as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
            $byRequestedBy[$r['requested_by']] = ($byRequestedBy[$r['requested_by']] ?? 0) + 1;
        }
        arsort($byStatus);
        arsort($byRequestedBy);

        return ['rows' => $rows, 'by_status' => $byStatus, 'by_requested_by' => $byRequestedBy];
    }

    // ================================================================
    // ORDER/CLIENT SEARCH — Reports hub
    // ================================================================
    // The Reports index previously just told staff to already know the
    // numeric order id ("go directly to /reports/order/<id>"). This is a
    // plain partial-match lookup by order reference or client company
    // name, scoped to the current Test Mode state like every other query
    // in this file, capped at 25 rows (a search box, not a full listing).

    /** @return array<int, array<string,mixed>> */
    public static function searchOrders(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT o.id, o.order_reference, o.status, o.created_at, c.company_legal_name
             FROM orders o JOIN clients c ON c.id = o.client_id
             WHERE o.is_test_data = :is_test_data
               AND (o.order_reference LIKE :q OR c.company_legal_name LIKE :q2)
             ORDER BY o.created_at DESC
             LIMIT 25'
        );
        $like = '%' . $query . '%';
        $stmt->execute(['is_test_data' => self::isTestModeFlag(), 'q' => $like, 'q2' => $like]);
        return $stmt->fetchAll();
    }

    // ================================================================
    // MONTH-OVER-MONTH TRENDS
    // ================================================================
    // Everything else in this file is either a point-in-time snapshot
    // (Queues) or a single flat date-range total (Funnel, Aggregate) —
    // there was no way to see whether the business is growing or slowing
    // month to month. Builds the canonical list of the last N months
    // FIRST (so a month with zero activity still appears as a zero row,
    // never silently dropped), then merges each GROUP BY query's rows
    // into it by month key.

    /** @return array<int, array<string,mixed>> one row per month, oldest first */
    public static function monthlyTrends(int $months = 12): array
    {
        $isTestMode = self::isTestModeFlag();
        $pdo = Database::connection();

        $end = new \DateTimeImmutable('first day of this month');
        $monthKeys = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthKeys[] = $end->modify("-{$i} months")->format('Y-m');
        }

        $result = [];
        foreach ($monthKeys as $mk) {
            $result[$mk] = ['month' => $mk, 'orders_created' => 0, 'quotations_sent' => 0, 'pi_sent' => 0, 'lost' => 0, 'fob_by_currency' => []];
        }
        $fromDate = $monthKeys[0] . '-01';

        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n
             FROM orders WHERE is_test_data = :t AND created_at >= :from GROUP BY ym"
        );
        $stmt->execute(['t' => $isTestMode, 'from' => $fromDate]);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($result[$row['ym']])) {
                $result[$row['ym']]['orders_created'] = (int) $row['n'];
            }
        }

        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS ym, cur.code AS cc, COALESCE(SUM(p.fob_value), 0) AS total
             FROM orders o
             JOIN currencies cur ON cur.id = o.currency_id
             JOIN order_products p ON p.order_id = o.id AND p.is_active = 1
             WHERE o.is_test_data = :t AND o.created_at >= :from GROUP BY ym, cc"
        );
        $stmt->execute(['t' => $isTestMode, 'from' => $fromDate]);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($result[$row['ym']])) {
                $result[$row['ym']]['fob_by_currency'][$row['cc']] = round((float) $row['total'], 2);
            }
        }

        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(el.sent_at, '%Y-%m') AS ym, COUNT(DISTINCT el.document_id) AS n
             FROM email_log el
             JOIN documents doc ON doc.id = el.document_id
             JOIN document_types dt ON dt.id = doc.document_type_id
             JOIN orders o ON o.id = doc.order_id
             WHERE dt.code = 'QT' AND el.status = 'sent' AND o.is_test_data = :t AND el.sent_at >= :from
             GROUP BY ym"
        );
        $stmt->execute(['t' => $isTestMode, 'from' => $fromDate]);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($result[$row['ym']])) {
                $result[$row['ym']]['quotations_sent'] = (int) $row['n'];
            }
        }

        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(el.sent_at, '%Y-%m') AS ym, COUNT(DISTINCT el.document_id) AS n
             FROM email_log el
             JOIN documents doc ON doc.id = el.document_id
             JOIN document_types dt ON dt.id = doc.document_type_id
             JOIN orders o ON o.id = doc.order_id
             WHERE dt.code = 'PI' AND el.status = 'sent' AND o.is_test_data = :t AND el.sent_at >= :from
             GROUP BY ym"
        );
        $stmt->execute(['t' => $isTestMode, 'from' => $fromDate]);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($result[$row['ym']])) {
                $result[$row['ym']]['pi_sent'] = (int) $row['n'];
            }
        }

        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(lost_at, '%Y-%m') AS ym, COUNT(*) AS n
             FROM orders WHERE is_test_data = :t AND status = 'lost' AND lost_at >= :from GROUP BY ym"
        );
        $stmt->execute(['t' => $isTestMode, 'from' => $fromDate]);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($result[$row['ym']])) {
                $result[$row['ym']]['lost'] = (int) $row['n'];
            }
        }

        return array_values($result);
    }

    // ================================================================
    // STAFF PRODUCTIVITY REPORT
    // ================================================================
    // Deliberately scoped to two straightforward, reliably-attributable
    // GROUP BY queries — documents.generated_by (who actually generated
    // each document) and audit_log.user_id (overall activity level) — and
    // NOT a derived "average turnaround per staff member" metric. Orders
    // themselves carry no created_by column, so any "who initiated this
    // order" figure would have to be inferred (e.g. via the first QT
    // document), which risks being subtly wrong. Given the explicit
    // requirement that every number here be trustworthy, a smaller set of
    // unambiguous counts beats a larger set with a shaky derived figure.
    // Gated on the view_staff_reports permission (Admin/MD/ED only by
    // default), not the general view_reports every other report here
    // uses, since this shows individual staff activity rather than
    // business data.

    /** @return array<int, array<string,mixed>> one row per user, sorted by name */
    public static function staffProductivity(?string $dateFrom, ?string $dateTo): array
    {
        $isTestMode = self::isTestModeFlag();
        $pdo = Database::connection();

        $docWhere = ['o.is_test_data = :is_test_data'];
        $docParams = ['is_test_data' => $isTestMode];
        if ($dateFrom) {
            $docWhere[] = 'DATE(d.generated_at) >= :date_from';
            $docParams['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $docWhere[] = 'DATE(d.generated_at) <= :date_to';
            $docParams['date_to'] = $dateTo;
        }

        $stmt = $pdo->prepare(
            'SELECT u.id AS user_id, u.name AS user_name, dt.code AS type_code, COUNT(*) AS n
             FROM documents d
             JOIN document_types dt ON dt.id = d.document_type_id
             JOIN orders o ON o.id = d.order_id
             LEFT JOIN users u ON u.id = d.generated_by
             WHERE ' . implode(' AND ', $docWhere) . '
             GROUP BY u.id, u.name, dt.code
             ORDER BY u.name, dt.code'
        );
        $stmt->execute($docParams);

        $byUser = [];
        foreach ($stmt->fetchAll() as $r) {
            $uid = $r['user_id'] !== null ? (int) $r['user_id'] : 0;
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'user_id' => $uid,
                    'user_name' => $r['user_name'] ?? 'Unattributed (system-generated)',
                    'documents_total' => 0,
                    'by_type' => [],
                    'audit_actions' => 0,
                ];
            }
            $byUser[$uid]['by_type'][$r['type_code']] = (int) $r['n'];
            $byUser[$uid]['documents_total'] += (int) $r['n'];
        }

        // audit_log carries no test-data flag of its own — Test Mode
        // deliberately never writes audit_log rows for test records at all
        // (see docs/schema.sql Section V / README), so no is_test_data
        // filter is needed or even possible here.
        $auditWhere = [];
        $auditParams = [];
        if ($dateFrom) {
            $auditWhere[] = 'DATE(al.created_at) >= :date_from';
            $auditParams['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $auditWhere[] = 'DATE(al.created_at) <= :date_to';
            $auditParams['date_to'] = $dateTo;
        }
        $auditSql = 'SELECT al.user_id, u.name AS user_name, COUNT(*) AS n
                     FROM audit_log al LEFT JOIN users u ON u.id = al.user_id'
                  . ($auditWhere ? ' WHERE ' . implode(' AND ', $auditWhere) : '')
                  . ' GROUP BY al.user_id, u.name';
        $stmt = $pdo->prepare($auditSql);
        $stmt->execute($auditParams);
        foreach ($stmt->fetchAll() as $r) {
            $uid = $r['user_id'] !== null ? (int) $r['user_id'] : 0;
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'user_id' => $uid,
                    'user_name' => $r['user_name'] ?? 'System',
                    'documents_total' => 0,
                    'by_type' => [],
                    'audit_actions' => 0,
                ];
            }
            $byUser[$uid]['audit_actions'] = (int) $r['n'];
        }

        usort($byUser, static fn(array $a, array $b): int => strcmp((string) $a['user_name'], (string) $b['user_name']));
        return array_values($byUser);
    }
}
