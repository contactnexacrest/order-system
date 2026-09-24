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
            return ['client' => null, 'orders' => [], 'documents' => [], 'payments' => [], 'products' => [], 'total_fob_value' => 0.0, 'total_cleared' => 0.0];
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
        $totalFob = 0.0;
        $totalCleared = 0.0;

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
                "SELECT order_id, advance_amount, advance_cleared_at, balance_amount, balance_cleared_at,
                        freight_amount, freight_cleared_at
                 FROM order_payment_status WHERE order_id IN ($in)"
            );
            $stmt->execute($orderIds);
            $payments = $stmt->fetchAll();
            foreach ($payments as $p) {
                if ($p['advance_cleared_at']) {
                    $totalCleared += (float) $p['advance_amount'];
                }
                if ($p['balance_cleared_at']) {
                    $totalCleared += (float) $p['balance_amount'];
                }
            }

            $stmt = $pdo->prepare(
                "SELECT order_id, description, quantity, quantity_is_tbc, unit, unit_price, fob_value
                 FROM order_products WHERE order_id IN ($in) AND is_active = 1 ORDER BY order_id, line_no"
            );
            $stmt->execute($orderIds);
            $products = $stmt->fetchAll();
            foreach ($products as $prod) {
                $totalFob += (float) ($prod['fob_value'] ?? 0);
            }
        }

        return [
            'client' => $client,
            'orders' => $orders,
            'documents' => $documents,
            'payments' => $payments,
            'products' => $products,
            'total_fob_value' => $totalFob,
            'total_cleared' => $totalCleared,
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

    /** @return string[] distinct country_of_destination values seeded so far, for the aggregate filter dropdown */
    public static function distinctCountries(): array
    {
        $stmt = Database::connection()->query(
            "SELECT DISTINCT country_of_destination FROM clients WHERE country_of_destination IS NOT NULL AND country_of_destination != '' ORDER BY country_of_destination"
        );
        return array_column($stmt->fetchAll(), 'country_of_destination');
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
}
