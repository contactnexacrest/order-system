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
use App\Repositories\PermissionRepository;

/**
 * Spec Section 8 — PAYMENT TERMS AMENDMENT SYSTEM (SC/AMD). Orchestrates
 * AmendmentRepository + ReferenceNumberService + DocumentGenerationService
 * + OrderRepository's own order-level payment-term snapshot columns. See
 * AmendmentRepository's docblock for why original_terms_snapshot is
 * frozen once, at request time.
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
        // 90-item backlog "ports city field": same fix as
        // DocumentDataAssembler — Incoterms convention prints the CITY
        // alone, never the formal terminal name.
        $portOfLoading = $order['port_of_loading_city'] ?? $order['port_of_loading_name'] ?? 'Chennai';
        $portOfDischarge = $order['port_of_discharge_city'] ?? $order['port_of_discharge_name'] ?? $order['port_of_discharge_text'] ?? 'To Be Confirmed';

        $qtDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'QT');
        $piDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'PI');
        $ocDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'OC');
        $products = OrderProductRepository::forOrder($orderId);
        $productSummary = implode('; ', array_map(
            static fn(array $p): string => $p['description'] . ' — ' . ($p['quantity_is_tbc'] ? 'To Be Confirmed' : $p['quantity']) . ' ' . ($p['unit'] ?? ''),
            $products
        ));

        $snapshot = [
            'quotation_ref'       => $qtDoc['document_reference'] ?? null,
            // User-reported batch item 6: generated_at comes back from
            // MySQL already converted to IST (the connection's session
            // time zone — see Database::connection()), so truncating it
            // to its first 10 characters before formatDate() already
            // gave the correct IST calendar date; passing the full value
            // through is simply the more direct equivalent of the
            // Node port's own fix for the same field.
            'quotation_date'      => $qtDoc ? DocumentDataAssembler::formatDate($qtDoc['generated_at']) : null,
            'pi_ref'              => $piDoc['document_reference'] ?? null,
            'pi_date'             => $piDoc ? DocumentDataAssembler::formatDate($piDoc['generated_at']) : null,
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
            'port_of_loading'     => $portOfLoading,
            // Incoterms® 2020: FOB names the port of LOADING; CFR/CIF name the
            // port of DISCHARGE — same bug/fix as DocumentDataAssembler.
            'incoterm_label'      => $order['incoterm_code'] . ' ' . ($isFob ? $portOfLoading : $portOfDischarge) . ' — Incoterms® 2020',
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
            $effectiveFrom,
            $requestedByUserId
        );

        AuditLogRepository::log($requestedByUserId, 'AMENDMENT_REQUESTED', 'amendments', $amendmentId, 'reason', null, $reason);

        // Notify whoever can actually approve it (approve_documents — the
        // same permission /amendments/{id}/md-approve is gated on) so this
        // isn't stuck waiting on someone stumbling across it — mirrors the
        // reviewer-assignment pattern. Checked by permission, not a
        // hardcoded role name, since roles can be renamed via /admin/roles.
        foreach (PermissionRepository::usersWithPermission('approve_documents') as $userId) {
            NotificationRepository::create($userId, null, 'amendment_pending_md_approval', $orderId, "Amendment {$reference} needs MD approval.");
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
        // QA-5 maker-checker (AMD-05): whoever filed this amendment request
        // must not also be the one who MD-approves it, unless they're a
        // Super Admin or hold manage_permissions (owner decision).
        if ($amendment['created_by'] !== null
            && (int) $amendment['created_by'] === $mdUserId
            && !MakerCheckerGuard::selfApprovalAllowed($mdUserId)
        ) {
            throw new \RuntimeException('You requested this amendment — a different privileged user must MD-approve it.');
        }
        AmendmentRepository::approveByMd($amendmentId, $mdUserId);
        AuditLogRepository::log($mdUserId, 'AMENDMENT_MD_APPROVED', 'amendments', $amendmentId);
    }

    public static function rejectAmendment(int $amendmentId, int $userId): void
    {
        // QA-4 P0.5: every sibling transition here (approveByMd,
        // generateDocument, attachSignedCopyAndActivate) re-validates the
        // amendment's current status before acting — this one didn't, and
        // AmendmentRepository::reject() unconditionally overwrites status to
        // 'rejected' with no WHERE-clause guard of its own. Without this,
        // rejecting an already-'active' amendment (whose override was
        // already applied to the order's payment terms via
        // attachSignedCopyAndActivate) would silently relabel it 'rejected'
        // while the order kept the amended terms — or "rejecting" an
        // already-'rejected' one would just no-op loudly into the audit log.
        $amendment = AmendmentRepository::find($amendmentId);
        if (!$amendment) {
            throw new \RuntimeException("Amendment {$amendmentId} not found");
        }
        if ($amendment['status'] !== 'pending') {
            throw new \RuntimeException('Only a pending amendment can be rejected.');
        }
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
        // D-215 (5a): an order with its own schedule keeps its milestones — the balance is what is left after them.
        $balancePct = $advancePct !== null ? round(100 - $advancePct - \App\Repositories\PaymentMilestoneRepository::totalPct((int) $amendment['order_id']), 2) : (float) $order['balance_pct'];
        $finalAdvancePct = $advancePct ?? (float) $order['advance_pct'];

        // Structured balance-trigger fields drive what PI/CI templates
        // actually render — fall back to the order's current values when
        // this amendment didn't change that side of the terms.
        $balanceTriggerOption = $amendment['amended_balance_trigger_option'] ?? $order['balance_trigger_option'];
        $balanceDays = $amendment['amended_balance_days'] !== null
            ? (int) $amendment['amended_balance_days']
            : (int) $order['balance_days'];

        // The order's own snapshotted balance_trigger_wording is custom
        // free text written for whichever option the order started
        // with — it stays valid only while that option is unchanged. If
        // this amendment actually flips the option, keeping the old
        // wording would print text written for the OTHER option
        // alongside the new one, so it's cleared instead and
        // DocumentDataAssembler::balanceTriggerSentence() falls back to
        // its generic built-in sentence for the new option.
        $balanceTriggerWording = $balanceTriggerOption === $order['balance_trigger_option']
            ? $order['balance_trigger_wording']
            : null;

        OrderRepository::applyAmendmentOverride(
            (int) $amendment['order_id'],
            $finalAdvancePct,
            $balancePct,
            $balanceTriggerOption,
            $balanceDays,
            $balanceTriggerWording,
            $amendmentId
        );

        AuditLogRepository::log($userId, 'AMENDMENT_ACTIVATED', 'amendments', $amendmentId, 'payment_terms', null, $amendment['amendment_reference']);
    }
}
