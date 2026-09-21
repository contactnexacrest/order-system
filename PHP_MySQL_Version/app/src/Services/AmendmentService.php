<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AmendmentRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;

/**
 * Spec Section 8 — PAYMENT TERMS AMENDMENT SYSTEM (SC/AMD). Orchestrates
 * AmendmentRepository + ReferenceNumberService + DocumentGenerationService
 * + OrderRepository's override columns. See AmendmentRepository's docblock
 * for why original_terms_snapshot is frozen once, at request time.
 */
final class AmendmentService
{
    public static function createRequest(
        int $orderId,
        string $reason,
        string $requestedBy,
        ?float $amendedAdvancePct,
        ?float $amendedAdvanceAmount,
        ?string $amendedBalanceTerms,
        ?string $amendedBalanceTriggerOption,
        ?int $amendedBalanceDays,
        ?float $amendedBalanceAmount,
        ?string $effectiveFrom,
        int $requestedByUserId
    ): int {
        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }

        $reference = ReferenceNumberService::generateAmendmentRef();
        if ($reference === null) {
            throw new \RuntimeException('AMD document type has no ref_format configured — cannot mint an amendment reference.');
        }

        $fobValue = OrderProductRepository::totalFobValue($orderId);
        $advancePct = (float) $order['advance_pct'];
        $balancePct = (float) $order['balance_pct'];
        $advanceAmount = round($fobValue * $advancePct / 100, 2);
        $isFob = strtoupper((string) $order['incoterm_code']) === 'FOB';
        $portOfDischarge = $order['port_of_discharge_name'] ?? $order['port_of_discharge_text'] ?? 'TBC';

        $qtDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'QT');
        $piDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'PI');
        $ocDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'OC');
        $products = OrderProductRepository::forOrder($orderId);
        $productSummary = implode('; ', array_map(
            static fn(array $p): string => $p['description'] . ' — ' . ($p['quantity_is_tbc'] ? 'TBC' : $p['quantity']) . ' ' . ($p['unit'] ?? ''),
            $products
        ));

        $snapshot = [
            'quotation_ref'       => $qtDoc['document_reference'] ?? null,
            'quotation_date'      => $qtDoc ? substr((string) $qtDoc['generated_at'], 0, 10) : null,
            'pi_ref'              => $piDoc['document_reference'] ?? null,
            'pi_date'             => $piDoc ? substr((string) $piDoc['generated_at'], 0, 10) : null,
            'oc_ref'              => $ocDoc['document_reference'] ?? null,
            'product_summary'     => $productSummary,
            'total_fob_value'     => number_format($fobValue, 2),
            'advance_trigger_text' => $order['advance_trigger_text'],
            'advance_terms_text'  => rtrim(rtrim(number_format($advancePct, 2), '0'), '.') . '% advance T/T on FOB Value ' . $order['advance_trigger_text'],
            'advance_amount'      => number_format($advanceAmount, 2),
            'balance_terms_text'  => rtrim(rtrim(number_format($balancePct, 2), '0'), '.') . '% balance T/T on FOB Value — ' . DocumentDataAssembler::balanceTriggerSentence($order['balance_trigger_option'] ?? null, isset($order['balance_days']) ? (int) $order['balance_days'] : null),
            'balance_amount'      => number_format($fobValue - $advanceAmount, 2),
            'freight_terms'       => $order['incoterm_code'],
            'currency'            => $order['currency_code'],
            'port_of_loading'     => $order['port_of_loading_name'] ?? 'Chennai, India',
            // Incoterms® 2020: FOB names the port of LOADING; CFR/CIF name the
            // port of DISCHARGE — same bug/fix as DocumentDataAssembler.
            'incoterm_label'      => $order['incoterm_code'] . ' ' . ($isFob ? ($order['port_of_loading_name'] ?? 'Chennai, India') : $portOfDischarge) . ' — Incoterms® 2020',
            'lut_number'          => CompanySettingsRepository::get('lut_number'),
            'gstin'               => CompanySettingsRepository::get('gstin'),
            'iec_pan'             => CompanySettingsRepository::get('iec_pan'),
        ];

        $amendmentId = AmendmentRepository::create(
            $reference,
            $orderId,
            $reason,
            $requestedBy,
            $snapshot,
            $amendedAdvancePct,
            $amendedAdvanceAmount,
            $amendedBalanceTerms,
            $amendedBalanceTriggerOption,
            $amendedBalanceDays,
            $amendedBalanceAmount,
            $effectiveFrom
        );

        AuditLogRepository::log($requestedByUserId, 'AMENDMENT_REQUESTED', 'amendments', $amendmentId, 'reason', null, $reason);

        // Notify every MD/Admin so approval isn't stuck waiting on someone
        // stumbling across it — mirrors the reviewer-assignment pattern.
        foreach (UserRepository::listActive() as $user) {
            if (in_array($user['role_name'], ['Admin', 'Managing Director'], true)) {
                NotificationRepository::create((int) $user['id'], null, 'amendment_pending_md_approval', $orderId, "Amendment {$reference} needs MD approval.");
            }
        }

        return $amendmentId;
    }

    public static function approveByMd(int $amendmentId, int $mdUserId): void
    {
        $amendment = AmendmentRepository::find($amendmentId);
        if (!$amendment) {
            throw new \RuntimeException("Amendment {$amendmentId} not found");
        }
        if ($amendment['status'] !== 'pending') {
            throw new \RuntimeException('Only a pending amendment can be MD-approved.');
        }
        AmendmentRepository::approveByMd($amendmentId, $mdUserId);
        AuditLogRepository::log($mdUserId, 'AMENDMENT_MD_APPROVED', 'amendments', $amendmentId);
    }

    public static function rejectAmendment(int $amendmentId, int $userId): void
    {
        AmendmentRepository::reject($amendmentId);
        AuditLogRepository::log($userId, 'AMENDMENT_REJECTED', 'amendments', $amendmentId);
    }

    public static function generateDocument(int $amendmentId, int $userId): array
    {
        $amendment = AmendmentRepository::find($amendmentId);
        if (!$amendment) {
            throw new \RuntimeException("Amendment {$amendmentId} not found");
        }
        if (!in_array($amendment['status'], ['md_approved', 'signed', 'active'], true)) {
            throw new \RuntimeException('An amendment must be MD-approved before its agreement document can be generated.');
        }
        return DocumentGenerationService::generateAmendment($amendmentId, $userId);
    }

    /**
     * Section 8: "Payment terms updated in system ONLY after signed copy
     * is uploaded." — this is that moment. Applies the override to the
     * order (OrderRepository::applyAmendmentOverride) so every future
     * document for it picks up the new terms, and marks the amendment
     * 'active'.
     */
    public static function attachSignedCopyAndActivate(int $amendmentId, int $signedCopyFileId, int $userId): void
    {
        $amendment = AmendmentRepository::find($amendmentId);
        if (!$amendment) {
            throw new \RuntimeException("Amendment {$amendmentId} not found");
        }
        if (!in_array($amendment['status'], ['md_approved', 'signed'], true)) {
            throw new \RuntimeException('This amendment is not awaiting a signed copy (must be MD-approved first, and not already active).');
        }
        if ($amendment['document_id'] === null) {
            throw new \RuntimeException('Generate the Amendment Agreement document before uploading the signed copy.');
        }

        AmendmentRepository::activate($amendmentId, $signedCopyFileId);

        $order = OrderRepository::find((int) $amendment['order_id']);

        $advancePct = $amendment['amended_advance_pct'] !== null ? (float) $amendment['amended_advance_pct'] : null;
        // Amended balance % isn't stored as its own column (only the
        // structured trigger option/days + free-text prose are) — the two
        // percentages must still sum to 100, so derive balance_pct from
        // whichever side actually changed rather than leaving the old
        // value in place alongside a new advance_pct.
        $balancePct = $advancePct !== null ? round(100 - $advancePct, 2) : (float) $order['balance_pct'];
        $finalAdvancePct = $advancePct ?? (float) $order['advance_pct'];

        // Structured balance-trigger fields drive what PI/CI templates
        // actually render (see OrderRepository::find()'s COALESCE) — fall
        // back to the order's current values when this amendment didn't
        // change that side of the terms.
        $balanceTriggerOption = $amendment['amended_balance_trigger_option'] ?? $order['balance_trigger_option'];
        $balanceDays = $amendment['amended_balance_days'] !== null
            ? (int) $amendment['amended_balance_days']
            : (int) $order['balance_days'];

        OrderRepository::applyAmendmentOverride(
            (int) $amendment['order_id'],
            $finalAdvancePct,
            $balancePct,
            $balanceTriggerOption,
            $balanceDays,
            $amendmentId
        );

        AuditLogRepository::log($userId, 'AMENDMENT_ACTIVATED', 'amendments', $amendmentId, 'payment_terms', null, $amendment['amendment_reference']);
    }
}
