<?php
use App\Config\Env;
use App\Helpers\Csrf;
use App\Helpers\Dates;
use App\Helpers\LeadTimeDisplay;
use App\Repositories\CaFyLockRepository;
use App\Repositories\OrderCancellationRepository;
use App\Services\AuthService;
use App\Services\DocumentGenerationService;
use App\Services\OrderEditGuard;
use App\Services\PermissionService;

$currentUser = AuthService::currentUser();
$canViewReports = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'view_reports');
$canEditLockedData = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'edit_locked_data');
$canManageOrders = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_orders');
$canManageSuppliers = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_suppliers');
$canManageLogisticsPartners = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_logistics_partners');
// QA-5 RBAC-03/04: duty-specific split of manage_orders — see docs/seed.sql.
$canManagePayments = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_payments');
// External QA report Q-ROLE-1: clearing a buyer payment (confirming the
// bank actually received it) is split out of manage_payments (recording
// what the client says they sent) so the same person can't do both.
$canClearPayments = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'clear_payments');
// Owner decision 2026-10-30: each stage's gating button says, before the
// click, which of the stage's documents still need generating, approving or
// sending (the rule stageDocumentBlockedBy() enforces), and stays disabled
// until they are done.
$stageGate = [];
foreach ([2, 3, 4, 5, 6, 7, 8] as $__gs) {
    $stageGate[$__gs] = \App\Services\DocumentGenerationService::stageDocumentGate((int) $order['id'], $__gs);
}
$stageGateDisabled = static fn (int $stage): string => $stageGate[$stage] !== [] ? ' disabled title="See the note above: this stage\'s document is not finished yet."' : '';
$stageGateNotice = static function (int $stage, string $action) use ($stageGate): string {
    if ($stageGate[$stage] === []) {
        return '';
    }
    $mailOn = \App\Services\EmailDispatchService::mailSendingEnabled();
    $items = array_map(static function (array $d) use ($mailOn): string {
        $todo = match ($d['todo']) {
            'generate' => 'not generated yet — generate it, then get it approved' . ($d['buyer_facing'] && $mailOn ? ' and sent to the buyer' : ''),
            'approve' => 'waiting for approval',
            'approve_send' => 'waiting for approval, then sending to the buyer',
            default => 'approved — waiting to be sent to the buyer',
        };
        return '<strong>' . htmlspecialchars($d['name']) . '</strong>: ' . $todo;
    }, $stageGate[$stage]);
    return '<p class="review-banner info stage-gate">&#8505; <span>Before you can ' . htmlspecialchars($action) . ': ' . implode('; ', $items)
        . '. <a href="/reviews">Open Document Review &amp; Approval</a></span></p>';
};
// Owner decision 2026-10-30: the team incentive box (Step 12d) — manage_incentives only.
$canManageIncentives = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_incentives');
if ($canManageIncentives) {
    \App\Services\IncentiveService::lockIfDue((int) $order['id'], (int) $currentUser['id']);
    $incentiveRow = \App\Repositories\IncentiveRepository::forOrder((int) $order['id']);
    $incentiveCalc = \App\Services\IncentiveService::calculate((int) $order['id']);
    $incentivePayouts = \App\Repositories\IncentiveRepository::payoutsForOrder((int) $order['id']);
    $incentiveUsers = \App\Repositories\UserRepository::listActive();
}
// External QA report Q-ROLE-3: split out of manage_order_financials so
// Accounts can confirm supplier payments without gaining the broader
// financials permission's access to cost entries/export benefits/the
// profitability report.
$canManageSupplierPayments = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_supplier_payments');
$canManageShipping = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_shipping');
$canCloseOrders = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'close_orders');
// Payment Ledger / Payment Snapshot (new): "only able to see the needed things" —
// general staff see payment STATUS only (received/not received, dates), amounts
// are restricted to manage_payments/close_orders (and Super Admin, already
// covered transitively since PermissionService::can() bypasses for an
// effective Super Admin on either check above).
$canViewPaymentAmounts = $canManagePayments || $canCloseOrders;
$canViewAuditLog = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'view_audit_log');
// docs/schema.sql Section AO — split out of manage_orders so enabling the client-facing dispute button is privileged-only by default.
$canManageDisputes = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_disputes');
// External QA report U-01: the /orders/{id}/disputes route itself accepts
// either of these two (see public_html/index.php) — this page's own link
// to it must use the exact same check, not just $canManageDisputes alone,
// or a respond_to_disputes-only role (e.g. Export Executive) sees a link
// that 403s.
$canRespondToDisputes = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'respond_to_disputes');
$canViewOrderDisputes = $canManageDisputes || $canRespondToDisputes;
// docs/schema.sql Section BX — a Complaint is not a Dispute: same split, own permissions.
$canManageComplaints = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_complaints');
$canRespondToComplaints = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'respond_to_complaints');
$canViewOrderComplaints = $canManageComplaints || $canRespondToComplaints;
// External QA report U-01: /compliance-task-types is Admin-only (the
// close_orders permission that gates the section around this link below
// is a much broader grant).
$canManageComplianceTaskTypes = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_compliance_task_types');
// External QA report U-01: /orders/{id}/email/compose and .../email/send both require this (see public_html/index.php) — Accounts Executive and Viewer / Auditor both lack it.
$canGenerateDocuments = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'generate_documents');
// QA Round 5, R5-20: every form and link on this page is shown only to
// someone its route would let through (the route checks stay as well).
$canApproveDocuments = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'approve_documents');
$canCrossVerifyDocuments = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'cross_verify_documents');
$canDownloadPdf = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'download_pdf');
$canListOrders = \App\Helpers\PermittedLinks::currentUserMayOpen('/orders');
$canOpenClient = \App\Helpers\PermittedLinks::currentUserMayOpen('/clients');
// Phase 7: a narrow exception to a financial year lock — see CaFyLockGuard.
$canOverrideFyLock = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'ca_fy_lock_override');
// Order-Edit feature — product-row edits/duplication once Order Confirmation is reached — see OrderEditGuard.
$isPostConfirmationOrder = OrderEditGuard::isPostConfirmation($order['current_stage_number'] !== null ? (int) $order['current_stage_number'] : null);
$canOverrideOrderEdit = OrderEditGuard::canOverride((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null);
// 90-item backlog Phase 6 Item 4 — the stricter lock check sits in front of the post-confirmation one.
$isLockedOrder = (bool) $order['is_locked'];
$canOverrideLockEdit = OrderEditGuard::canOverrideLock((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null);
$canEditProducts = $canManageOrders && (!$isLockedOrder || $canOverrideLockEdit) && (!$isPostConfirmationOrder || $canOverrideOrderEdit);

// CA / Accounting module (Phase 1) — one small widget per settlement leg,
// rendered inline in the Payment Status section rather than a separate
// page, since the whole point is capturing the INR actual the same day
// the leg clears (see schema.sql comment on order_payment_status).
$usersById = [];
foreach ($activeUsers as $u) {
    $usersById[(int) $u['id']] = $u['name'];
}
// Owner request (2026-10-26): a remittance is recorded together with the
// day's exchange rate, and once recorded the card reads it back as one
// statement — "we received X at today's exchange rate of Y".
$remittanceRecordedNote = function (string $leg, string $nextStep) use ($payment, $order): string {
    $amount = $payment[$leg . '_amount'] ?? null;
    $rate = $payment[$leg . '_exchange_rate'] ?? null;
    $receivedAt = $payment[$leg . '_remittance_received_at'] ?? null;
    $currency = htmlspecialchars((string) ($order['currency_code'] ?? ''));
    $text = 'Received ' . $currency . ' ' . number_format((float) $amount, 2)
        . ($receivedAt ? ' on ' . htmlspecialchars(date('d M Y', strtotime((string) $receivedAt))) : '');
    $text .= $rate !== null
        ? ' at today\'s exchange rate of &#8377;' . number_format((float) $rate, 4) . ' (= &#8377;' . number_format((float) $amount * (float) $rate, 2) . ').'
        : ' — exchange rate not recorded yet (record it below).';
    $clearedAt = $payment[$leg . '_cleared_at'] ?? null;
    $after = $clearedAt ? 'Cleared on ' . date('d M Y', strtotime((string) $clearedAt)) . '.' : $nextStep;
    return '<p class="remit-recorded"><strong>&#10003; ' . $text . '</strong> <span class="muted">' . htmlspecialchars($after) . '</span></p>';
};

// Batch 3 #3 — each settlement leg carries its own exchange rate (advance/
// balance/freight each clear on their own date, at their own market
// rate), so the rate widget is rendered per-leg, right alongside that
// leg's own INR Actual widget, instead of one shared order-wide widget.
$renderExchangeRateWidget = function (string $leg, string $label) use ($payment, $order, $canViewInrActual, $canEditInrActual, $canOverrideFyLock, $usersById): void {
    // 90-item backlog "exchange-rate-at-clearing-time": this widget used
    // to only ever render from inside $renderInrActualWidget, which
    // itself returns early until the leg is marked CLEARED — but
    // ca-02-revenue-reports.md's own spec says the rate is "typically
    // entered once that leg is about to clear, whenever your accounts
    // team fixes a working rate for it" — i.e. BEFORE clearing, as an
    // anticipatory estimate, not after. Gating on cleared_at made that
    // impossible: the box simply didn't exist yet, so staff could never
    // record a rate ahead of the INR actual the way the SOP describes,
    // and OrderProfitabilityService's "uncleared leg estimated using its
    // own rate" branch could never have a rate to use in practice. Now
    // gated on the leg's amount being on record (received) instead of
    // cleared — the same point "about to clear" first becomes true.
    if (!$canViewInrActual || ($payment[$leg . '_amount'] ?? null) === null) {
        return;
    }
    $rate = $payment[$leg . '_exchange_rate'] ?? null;
    $setBy = $payment[$leg . '_exchange_rate_set_by'] ?? null;
    $setByName = $setBy !== null ? ($usersById[(int) $setBy] ?? ('User #' . $setBy)) : null;
    $lockMessage = CaFyLockRepository::lockMessageForDate($payment[$leg . '_cleared_at'] ?? null);
    ?>
    <div style="margin-top:8px; padding-top:8px; border-top:1px solid rgba(0,0,0,0.08);">
      <strong style="font-size:0.85em;"><?= htmlspecialchars($label) ?> Exchange Rate</strong><br>
      <?php // Owner decision (2026-10-26, t4): the rate is recorded with the remittance itself, so there is no separate "Record/Update Rate" box any more — only a collapsed, reasoned correction for a mistyped rate, for inr_actual_edit holders. ?>
      <?php if ($rate !== null): ?>
        <span class="muted small">Received @ &#8377;<?= number_format((float) $rate, 4) ?> per 1 <?= htmlspecialchars((string) ($order['currency_code'] ?? '')) ?> — set <?= htmlspecialchars((string) ($payment[$leg . '_exchange_rate_set_at'] ?? '')) ?><?= $setByName ? ' by ' . htmlspecialchars($setByName) : '' ?>. Used only to show forex gain/(loss) below once an INR actual is recorded — never to derive the INR actual itself.</span>
      <?php else: ?>
        <span class="muted small">No exchange rate on record for this older entry.</span>
      <?php endif; ?>
      <?php if ($canEditInrActual && $lockMessage !== null && !$canOverrideFyLock): ?>
        <br><span class="muted small">&#128274; <?= htmlspecialchars($lockMessage) ?></span>
      <?php elseif ($canEditInrActual): ?>
        <details class="rate-correction" style="margin-top:6px;">
          <summary class="muted small"><?= $rate !== null ? 'Correct a wrongly typed rate' : 'Add the missing rate' ?></summary>
          <?php if ($lockMessage !== null): ?>
            <p class="muted small" style="margin:6px 0 0;">&#9888; <?= htmlspecialchars($lockMessage) ?> You hold the override permission — submitting below will be logged.</p>
          <?php endif; ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/<?= $leg ?>/exchange-rate" style="margin-top:6px;">
            <?= Csrf::field() ?>
            <label>@ Exchange Rate * <small class="muted">(&#8377; per 1 <?= htmlspecialchars((string) ($order['currency_code'] ?? '')) ?>)</small><input type="number" step="0.0001" min="0.0001" name="<?= $leg ?>_exchange_rate" placeholder="e.g. 88.5000" required></label>
            <?php if ($rate !== null): ?>
            <label>Reason for the correction *<input type="text" name="correction_reason" required minlength="<?= \App\Helpers\ReasonValidator::MIN_LENGTH ?>" placeholder="e.g. typed 8.35 instead of 83.50"></label>
            <?php endif; ?>
            <button type="submit" class="btn-sm"><?= $rate !== null ? 'Correct Rate' : 'Save Rate' ?></button>
          </form>
        </details>
      <?php endif; ?>
    </div>
    <?php
};

$renderInrActualWidget = function (string $leg, string $label) use ($payment, $order, $canViewInrActual, $canEditInrActual, $canDeleteInrActual, $canOverrideFyLock, $usersById): void {
    $clearedAt = $payment[$leg . '_cleared_at'] ?? null;
    if ($clearedAt === null || !$canViewInrActual) {
        return;
    }
    $amount = $payment[$leg . '_inr_actual'] ?? null;
    $recordedAt = $payment[$leg . '_inr_actual_recorded_at'] ?? null;
    $recordedBy = $payment[$leg . '_inr_actual_recorded_by'] ?? null;
    $recordedByName = $recordedBy !== null ? ($usersById[(int) $recordedBy] ?? ('User #' . $recordedBy)) : null;
    $legRate = $payment[$leg . '_exchange_rate'] ?? null;
    $foreignAmount = $payment[$leg . '_amount'] ?? null;
    $forexGainLoss = ($amount !== null && $legRate !== null && $foreignAmount !== null)
        ? (float) $amount - ((float) $foreignAmount * (float) $legRate)
        : null;
    $fircReference = $payment[$leg . '_firc_reference'] ?? null;
    $fircReceivedAt = $payment[$leg . '_firc_received_at'] ?? null;
    $lockMessage = CaFyLockRepository::lockMessageForDate($clearedAt);
    ?>
    <div class="review-banner info" style="margin-top:10px;">
      <div style="width:100%;">
        <strong><?= htmlspecialchars($label) ?> — INR Actual (CA/Accounting)</strong><br>
        <?php if ($amount !== null): ?>
          <span>&#8377;<?= number_format((float) $amount, 2) ?> credited — recorded <?= htmlspecialchars((string) $recordedAt) ?><?= $recordedByName ? ' by ' . htmlspecialchars($recordedByName) : '' ?></span>
          <?php if (($lockMessage === null || $canOverrideFyLock) && $canDeleteInrActual): ?>
            <?php if ($lockMessage !== null): ?>
              <span class="muted small">(&#9888; override — logged)</span>
            <?php endif; ?>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/<?= $leg ?>/inr-actual/delete" style="display:inline; margin-left:8px;" onsubmit="return confirm('Remove the recorded INR actual amount for this leg? Only do this to correct a mis-entry.');">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm btn-danger">Remove</button>
            </form>
          <?php endif; ?>
          <?php if ($forexGainLoss !== null): ?>
            <br><span class="muted small">Forex <?= $forexGainLoss >= 0 ? 'gain' : 'loss' ?>: &#8377;<?= number_format(abs($forexGainLoss), 2) ?> vs. this leg's own recorded rate.</span>
          <?php endif; ?>
        <?php elseif ($lockMessage !== null && !$canOverrideFyLock): ?>
          <span class="muted">&#128274; <?= htmlspecialchars($lockMessage) ?></span>
        <?php elseif ($canEditInrActual): ?>
          <?php if ($lockMessage !== null): ?>
            <p class="muted small" style="margin:0 0 6px;">&#9888; <?= htmlspecialchars($lockMessage) ?> You hold the override permission — submitting below will be logged.</p>
          <?php endif; ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/<?= $leg ?>/inr-actual">
            <?= Csrf::field() ?>
            <label>INR Amount Actually Credited *<input type="text" name="<?= $leg ?>_inr_actual" required></label>
            <button type="submit" class="btn-sm"><?= $lockMessage !== null ? 'Override &amp; Record' : 'Record INR Actual' ?></button>
          </form>
        <?php else: ?>
          <span class="muted">Not yet recorded — needs someone with the "Add/edit INR actual" permission.</span>
        <?php endif; ?>

        <div style="margin-top:8px; padding-top:8px; border-top:1px solid rgba(0,0,0,0.08);">
          <strong style="font-size:0.85em;">FIRC / eBRC Reference</strong><br>
          <?php if ($fircReference !== null): ?>
            <span class="muted small"><?= htmlspecialchars($fircReference) ?> (received <?= htmlspecialchars((string) $fircReceivedAt) ?>)</span>
          <?php elseif ($lockMessage !== null && !$canOverrideFyLock): ?>
            <span class="muted small">&#128274; <?= htmlspecialchars($lockMessage) ?></span>
          <?php elseif ($canEditInrActual): ?>
            <?php if ($lockMessage !== null): ?>
              <p class="muted small" style="margin:0 0 6px;">&#9888; <?= htmlspecialchars($lockMessage) ?> You hold the override permission — submitting below will be logged.</p>
            <?php endif; ?>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/<?= $leg ?>/firc">
              <?= Csrf::field() ?>
              <label>FIRC/eBRC Reference *<input type="text" name="<?= $leg ?>_firc_reference" required></label>
              <label>Received On<input type="date" name="<?= $leg ?>_firc_received_at" value="<?= date('Y-m-d') ?>"></label>
              <button type="submit" class="btn-sm"><?= $lockMessage !== null ? 'Override &amp; Record' : 'Record FIRC/eBRC' ?></button>
            </form>
          <?php else: ?>
            <span class="muted small">Not yet recorded.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
};

// 90-item backlog #80: a small sequential sub-step badge (1a, 1b, 2a...)
// placed next to each stage's own primary staff actions — the literal
// order of operations within a stage — so staff can't skip or forget a
// step. Purely a display label: it carries no gating logic of its own,
// gating already lives in the controller actions these buttons post to.
$stepBadge = static fn(string $label): string => '<span class="step-badge" title="Step ' . htmlspecialchars($label) . '">' . htmlspecialchars($label) . '</span> ';

$stage1 = $stageByNumber[1] ?? null;
$stage2 = $stageByNumber[2] ?? null;
$stage3 = $stageByNumber[3] ?? null;
$stage4 = $stageByNumber[4] ?? null;
$stage5 = $stageByNumber[5] ?? null;
$stage6 = $stageByNumber[6] ?? null;
$stage7 = $stageByNumber[7] ?? null;
$stage8 = $stageByNumber[8] ?? null;
$stage9 = $stageByNumber[9] ?? null;

$hasQt = $stage1 && $stage1['status'] === 'gate_passed';
$hasBuyerPo = $stage2 && $stage2['status'] === 'gate_passed';
$advanceCleared = $stage3 && $stage3['status'] === 'gate_passed';
// Step 5 payment-method fork: when this order's preset set payment_method
// = 'LC' (snapshotted onto the order at creation, never Amendment-updatable
// — see docs/schema.sql), the LC leg (Record LC Details / Confirm LC
// Usable) replaces the cash-advance leg (Record Advance Remittance / Mark
// Advance Cleared) entirely — only one of the two ever renders.
$isLcPayment = ($order['payment_method'] ?? 'CASH_ADVANCE') === 'LC';
// Owner decision: with email sending off there is no "Send to Buyer"
// action — a document counts as sent once approved
// (EmailDispatchService::markSentIfMailDisabled()).
$mailSendingEnabled = \App\Services\EmailDispatchService::mailSendingEnabled();
$lcConfirmed = $orderLcDetails && $orderLcDetails['lc_confirmed_at'] !== null;
$buyerAcknowledged = $stage4 && $stage4['status'] === 'gate_passed';
$ocDocGenerated = (bool) array_filter($documents, static fn(array $d): bool => $d['document_type_code'] === 'OC');
// 90-item backlog #52/#53: confirmSupplierSigned()/recordBalancePayment() now
// require their commercial document to actually exist — these drive the
// matching UI hints (closest existing precedent: $ocDocGenerated above).
$suppoDocGenerated = (bool) array_filter($documents, static fn(array $d): bool => $d['document_type_code'] === 'SUPPO');
$ciDocGenerated = (bool) array_filter($documents, static fn(array $d): bool => $d['document_type_code'] === 'CI');
$supplierSigned = $stage5 && $stage5['status'] === 'gate_passed';
$isFob = strtoupper((string) $order['incoterm_code']) === 'FOB';
$freightCleared = $stage6 && $stage6['status'] === 'gate_passed';
$blIssued = $stage7 && $stage7['status'] === 'gate_passed';
$balanceCleared = $stage8 && $stage8['status'] === 'gate_passed';
$orderClosed = $order['status'] === 'complete';

// Payment Snapshot + Payment Ledger data, computed here (rather than down
// where they're displayed) so the Order Details at-a-glance summary below
// can also reference the same $paymentLegs without a second pass. Amounts
// are gated per $canViewPaymentAmounts (see above) — everyone who can view
// the order sees the STATUS of each leg; only manage_payments/close_orders
// (Super Admin already covered transitively) see the actual figures.
$paymentLegs = [
    ['label' => 'Advance', 'amount' => $payment['advance_amount'] ?? null, 'received_at' => $payment['advance_remittance_received_at'] ?? null, 'cleared_at' => $payment['advance_cleared_at'] ?? null],
    ['label' => 'Freight', 'amount' => $payment['freight_amount'] ?? null, 'received_at' => $payment['freight_remittance_received_at'] ?? null, 'cleared_at' => $payment['freight_cleared_at'] ?? null],
    ['label' => 'Balance', 'amount' => $payment['balance_amount'] ?? null, 'received_at' => $payment['balance_remittance_received_at'] ?? null, 'cleared_at' => $payment['balance_cleared_at'] ?? null],
];
// Owner decision (2026-10-26): the full ledger (supplier payments, every
// expense, export benefits, INR values and the net) only for
// manage_order_financials holders — see OrderLedgerService.
$ledger = \App\Services\OrderLedgerService::forOrder((int) $order['id'], (string) ($order['currency_code'] ?? ''), !empty($canManageOrderFinancials));
$ledgerEvents = $ledger['rows'];
$ledgerTotals = $ledger['totals'];
$paymentLegsResolved = count(array_filter($paymentLegs, static fn(array $l): bool => $l['cleared_at'] !== null));
$paymentLegsApplicable = count(array_filter($paymentLegs, static fn(array $l): bool => $l['amount'] !== null || $l['received_at'] !== null));

// Order Details at-a-glance summary (redesign) — quick counts reused from
// data already loaded for other sections, so no extra queries.
$complianceSummary = ['total' => 0, 'approved' => 0, 'outstanding' => 0];
foreach ($complianceTasks as $ct) {
    $complianceSummary['total']++;
    if ($ct['status'] === 'approved' || $ct['status'] === 'skipped') {
        $complianceSummary['approved']++;
    }
}
$complianceSummary['outstanding'] = $complianceSummary['total'] - $complianceSummary['approved'];
$currentStage = null;
foreach ($stages as $s) {
    if ($s['status'] !== 'gate_passed' && $s['status'] !== 'skipped') {
        $currentStage = $s;
        break;
    }
}

// Order detail page rebuild (approved design: "NexaCrest: Standard vs Kanban
// UI" canvas, KanbanDetail.dc.html/Main.dc.html) — the user's own numbered
// 0-17 export process sequence, shown as collapsible step-cards instead of
// a sidebar-nav-switched single-panel-at-a-time page. The lettered
// sub-steps ($stepBadge above) carry no DB-backed state of their own
// (confirmed in the rebuild's own research pass); $stepStatus below
// computes done/current/locked purely from the SAME signals the page
// already reads for each stage, centralized here once instead of
// scattered per-section, and used only to badge/auto-open a card — it
// never gates an action (every existing permission/stage-gate check below
// is completely unchanged).
$stepStatus = static function (?array $stage, bool $pastSignal = false) use ($orderClosed): string {
    if ($orderClosed) {
        return 'done';
    }
    if ($stage === null) {
        return $pastSignal ? 'done' : 'locked';
    }
    if ($stage['status'] === 'gate_passed' || $stage['status'] === 'skipped') {
        return 'done';
    }
    if ($stage['status'] === 'in_progress') {
        return 'current';
    }
    return 'locked';
};
// Steps 1/2/3 used to share stage1's single DB status (which auto-passes
// the instant a QT is generated — see StageGateService's own doc comment),
// so all three opened/closed together and flipped "done" together even
// though sending and client-acceptance hadn't actually happened yet. Each
// now gets its own real signal, independent of the DB stage gate: step 1
// from whether a QT document exists at all, step 2 from that QT's own
// 'sent' status, step 3 from whether work on the next real action (the
// Buyer PO) has actually started — the same thing its own card text already
// tells staff to treat as "client accepted."
$qtDoc = null;
foreach ($documents as $__d) {
    if ($__d['document_type_code'] === 'QT') {
        $qtDoc = $__d;
        break;
    }
}
$hasQtDoc = $qtDoc !== null;
$qtSent = $hasQtDoc && $qtDoc['status'] === 'sent';
$hasBuyerPoDoc = in_array('BUYERPO', array_column($documents, 'document_type_code'), true);
// Once the Buyer PO stage gate has passed, the quotation was evidently sent
// and accepted, even if it went out by email outside the app (QT left
// 'draft') or no BUYERPO document was generated. Without this, steps 2/3
// stayed current/locked on an order already far past them, and the
// "first step that isn't done" below (the card opened and scrolled to on
// every load) was stuck on Step 2.
$quotationAccepted = $hasBuyerPoDoc || $hasBuyerPo;
$step123Status = static function (bool $reached, bool $done) use ($orderClosed): string {
    if ($orderClosed) {
        return 'done';
    }
    if (!$reached) {
        return 'locked';
    }
    return $done ? 'done' : 'current';
};
// One entry per user-facing top-level step (0-19, after the former
// double-"9"/double-"11" collisions were split into distinct numbers; the
// Compliance Checklist runs alongside Steps 7 and 10 — see
// $renderComplianceCard) — 'status' drives the
// card's checkmark/current-highlight/locked styling and open state.
$stepStates = [
    '1'    => $step123Status(true, $hasQtDoc),
    '2'    => $step123Status($hasQtDoc, $qtSent || $quotationAccepted),
    '3'    => $step123Status($qtSent || $quotationAccepted, $quotationAccepted),
    '4'    => $stepStatus($stage2),
    '5'    => $stepStatus($stage3),
    '6'    => $stepStatus($stage4),
    '7'    => $stepStatus($stage5),
    '8'    => $stepStatus($stage5, $supplierSigned), // no own DB stage — unlocked once Stage 5 passes
    '9'    => $stepStatus($stage6), // Freight Payment — auto-skipped (status 'skipped' => 'done') for FOB orders
    '10'   => $stepStatus($stage7),
    '11'   => $stepStatus($stage8),
    // Owner request (2026-10-26, t4): Step 12 can also be skipped with a reason.
    // Owner request (2026-10-26): Step 12 is done once completed in 12c or skipped with a reason.
    '12'   => !empty($order['financials_skipped_at']) || !empty($order['financials_completed_at']) ? 'done' : $stepStatus($stage9),
    // Close Order passes Stage 9's gate, but Step 13 isn't finished until
    // the order is marked fully settled (13h) — owner report 2026-10-26.
    '13'   => $orderClosed ? 'done' : ($stage9 === null || $stage9['status'] === 'locked' ? 'locked' : 'current'),
    '14'   => 'done', // reference/upload card — never gated
    '15'   => 'done',
    '16'   => 'done',
    '17'   => 'done',
    '18'   => 'done',
    '19'   => $orderClosed ? 'done' : 'locked',
];
// Owner report (2026-10-27): confirming the supplier signed (7d) passes
// Stage 5's gate, which used to tick all of Step 7, tick Step 8 with
// nothing entered, and open Group C. Now Step 7 stays open until the
// supplier advance is paid (7e) when the Supplier PO plans one; Step 8
// opens after Step 7 and is done only once production status or the
// estimated shipment has actually been entered; and Group C (Steps 9-11)
// waits until Group B is finished — a step that would be current there, or
// Step 9 auto-skipped on an FOB order, shows waiting instead. Mirrors Node
// helpers/stepFlow.js applyProcurementOrder().
if (!$orderClosed) {
    $supplierAdvancePending = $supplierPo !== null && (float) ($supplierPo['advance_amount_inr'] ?? 0) > 0 && empty($supplierPo['advance_paid_at']);
    // 'Not yet commenced' is the column's default on every new order, not an entry.
    $productionUpdated = !in_array(trim((string) ($order['production_status_text'] ?? '')), ['', 'Not yet commenced'], true)
        || !empty($order['est_shipment_date_text']) || !empty($order['est_shipment_date']);
    if ($stepStates['7'] === 'done' && $supplierAdvancePending) {
        $stepStates['7'] = 'current';
    }
    if ($stepStates['8'] === 'done') {
        $stepStates['8'] = $stepStates['7'] !== 'done' ? 'locked' : ($productionUpdated ? 'done' : 'current');
    }
    if ($stepStates['7'] !== 'done' || $stepStates['8'] !== 'done') {
        foreach (['9', '10', '11'] as $__step) {
            if ($stepStates[$__step] === 'current' || ($__step === '9' && $stage6 !== null && $stage6['status'] === 'skipped')) {
                $stepStates[$__step] = 'locked';
            }
        }
    }
}
// Owner rule (2026-10-25): "when a later step is completed, the previous
// ones must be completed" — so once any later pipeline step is done, every
// earlier one shows done too. (The Compliance Checklist cards keep their
// own honest status — they're never part of this cascade.)
$pipelineSteps = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13'];
$laterDone = false;
foreach (array_reverse($pipelineSteps) as $__step) {
    if ($laterDone) {
        $stepStates[$__step] = 'done';
    } elseif ($stepStates[$__step] === 'done') {
        $laterDone = true;
    }
}
// The single step whose <details> should start open: the first one that
// isn't yet 'done' (the order's real current step), or '19' once the
// order is actually closed — same "first unresolved" logic $currentStage
// above already uses, just applied to the user-facing step numbers.
$currentUserStep = '19';
foreach ($stepStates as $__step => $__status) {
    // PHP casts a purely-numeric array key (e.g. '4') to an int, so
    // $__step here is int(4), not string('4') — cast back to string or
    // every $stepCardOpen('4') call below (string param) fails its strict
    // === against this int and never opens that card.
    $__step = (string) $__step;
    if ($__status !== 'done') {
        $currentUserStep = $__step;
        break;
    }
}
$stepCardOpen = static fn(string $step): string => ($step === $currentUserStep) ? ' open' : '';

// Sub-step progress (owner request, 2026-10-25: "open the lock of one door,
// enter the next room"): each lettered sub-step gets its own done signal,
// from data this page already loads, in the order the cards appear. null =
// no signal of its own (optional/reference) — it just follows its step.
// Within a step a later sub-step done means the earlier ones count as done
// too (the same owner rule as above).
// A "Generate …" sub-step is only done once its document is complete —
// approved, or sent while email sending is on (owner decision 2026-10-25,
// DocumentGenerationService::isDocumentComplete()) — not merely generated.
$docComplete = static function (string $code) use ($documents): bool {
    foreach ($documents as $__doc) {
        if ($__doc['document_type_code'] === $code && $__doc['status'] !== 'superseded') {
            return DocumentGenerationService::isDocumentComplete($__doc);
        }
    }
    return false;
};
$piFormSkipped = !empty($order['pi_form_skipped_at']);
$buyerPoCopyRequired = \App\Repositories\CompanySettingsRepository::get('wet_signature_required_buyer_po') === '1';
$supplierPoCopyRequired = \App\Repositories\CompanySettingsRepository::get('wet_signature_required_supplier_po') === '1';
// Owner decision (2026-10-26): staff can skip the PI-stage form when the
// client won't use it (reason required) — offered on 5a and 5b until the
// form is applied or skipped.
$renderPiFormSkip = static function () use ($order, $piIntake, $piFormSkipped, $canManageOrders): void {
    if ($piFormSkipped) {
        echo '<p class="muted small">PI-stage form skipped on ' . htmlspecialchars((string) $order['pi_form_skipped_at']) . ' — ' . htmlspecialchars((string) $order['pi_form_skip_reason']) . '</p>';
        return;
    }
    if (!$canManageOrders || ($piIntake !== null && $piIntake['status'] === 'applied')) {
        return;
    }
    ?>
    <details class="pi-form-skip" style="margin-top:8px;">
      <summary class="small">Client won't use the PI-stage form? Skip it</summary>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/pi-form/skip" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:6px;">
        <?= \App\Helpers\Csrf::field() ?>
        <label style="flex:1;min-width:240px;">Reason *<input type="text" name="skip_reason" required placeholder="e.g. Repeat buyer — details unchanged from the Quotation"></label>
        <button type="submit" class="btn-sm btn-secondary">Skip PI Form — go to Generate PI</button>
      </form>
    </details>
    <?php
};
// Owner decisions (2026-10-27): bill first, then pay. Whoever asks NexaCrest
// for money — the supplier (7e advance, 10a balance) or a logistics partner
// (CHA, transport, freight forwarder) — sends a bill; staff add it with the
// bill attached, then record the payment against it (date, amount, mode,
// UTR). One table + pay form for both kinds.
$vendorBillPayTo = static function (?array $bank, string $editUrl, bool $canEditVendor): string {
    $editLink = $canEditVendor ? ' <a href="' . htmlspecialchars($editUrl) . '#bank-details" target="_blank" rel="noopener">Open the directory entry</a>' : '';
    if ($bank === null || !$bank['has_bank']) {
        return '<p class="review-banner warn">&#9888; No bank details on file — add them in the directory, or send the Vendor Details Link.' . $editLink . '</p>';
    }
    return '<div class="pay-to"><strong>Pay to:</strong> ' . htmlspecialchars((string) $bank['bank_account_name']) . ' · ' . htmlspecialchars((string) $bank['bank_name'])
        . ($bank['bank_branch'] ? ' (' . htmlspecialchars((string) $bank['bank_branch']) . ')' : '')
        . ' · A/c <code>' . htmlspecialchars((string) $bank['bank_account_number']) . '</code> · IFSC <code>' . htmlspecialchars((string) $bank['bank_ifsc']) . '</code>'
        . ($bank['bank_upi_id'] ? ' · UPI ' . htmlspecialchars((string) $bank['bank_upi_id']) : '')
        . ($bank['bank_verified_at'] ? ' <span class="badge good">&#10003; Verified</span>' : ' <span class="badge warn">&#9888; Not verified — check before paying</span>' . $editLink)
        . '</div>';
};
// Owner decision (2026-10-28, Option B): each bill is a Proforma (an
// advance request — not a cost) or a Tax invoice (the cost); a tax invoice
// can replace an earlier proforma so what was paid on it counts towards the
// invoice; a bill takes as many payments as it needs. $leg is the supplier
// step a payment recorded here counts towards (7e advance, 10a balance);
// $carriedIds are 7e bills shown in 10a until fully paid.
$renderVendorBills = static function (array $bills, bool $showPartner, bool $canPay, ?string $payBlocked, callable $payToFor, ?string $leg = null, array $carriedIds = []) use ($order): void {
    if ($bills === []) {
        echo '<p class="muted small">No bills added yet.</p>';
        if ($canPay && $payBlocked !== null) {
            echo '<p class="review-banner warn">&#9888; ' . htmlspecialchars($payBlocked) . ' Bills can still be added now.</p>';
        }
        return;
    }
    $cost = $paid = $due = 0.0;
    $money = static fn ($v): string => number_format((float) $v, 2);
    ?>
    <table class="list vendor-bills">
      <tr><?php if ($showPartner): ?><th>Partner</th><?php endif; ?><th>For</th><?php if ($showPartner): ?><th>Payment</th><?php endif; ?><th>Type</th><th>Bill / invoice</th><th style="text-align:right">Taxable &#8377;</th><th style="text-align:right">GST &#8377;</th><th style="text-align:right">Bill total &#8377;</th><th>Paid</th></tr>
      <?php foreach ($bills as $b): ?>
        <?php $cost += $b['cost_inr']; $paid += $b['own_paid']; $due += $b['remaining']; ?>
        <?php $forLabel = $b['vendor_kind'] === 'supplier' ? (\App\Repositories\OrderVendorBillRepository::SUPPLIER_LEGS[$b['bill_for']] ?? $b['bill_for']) : (\App\Repositories\OrderVendorBillRepository::LOGISTICS_SERVICES[$b['bill_for']] ?? $b['bill_for']); ?>
        <?php $__isProforma = $b['doc_kind'] === 'proforma'; ?>
      <tr id="vendor-bill-<?= (int) $b['id'] ?>">
        <?php if ($showPartner): ?><td><?= htmlspecialchars((string) ($b['partner_name'] ?? '—')) ?></td><?php endif; ?>
        <td><?= htmlspecialchars($forLabel) ?></td>
        <?php
        // Owner decision (2026-10-27): a logistics Advance bill is never held back; only the Final bill waits.
        $__isAdvanceBill = $b['vendor_kind'] === 'logistics' && ($b['payment_kind'] ?? 'final') === 'advance';
        $__rowBlocked = $__isAdvanceBill ? null : $payBlocked;
        ?>
        <?php if ($showPartner): ?><td><span class="badge <?= $__isAdvanceBill ? 'badge-advance' : 'badge-final' ?>"><?= $__isAdvanceBill ? 'Advance' : 'Final' ?></span></td><?php endif; ?>
        <td><span class="badge <?= $__isProforma ? 'badge-proforma' : 'badge-invoice' ?>"><?= $__isProforma ? 'Proforma' : 'Tax invoice' ?></span></td>
        <td><?= htmlspecialchars((string) $b['bill_no']) ?> · <?= htmlspecialchars(\App\Helpers\Dates::short((string) $b['bill_date'])) ?><?php if (!empty($b['bill_file_id'])): ?> · <a href="/file-store/<?= (int) $b['bill_file_id'] ?>/download">bill</a><?php endif; ?>
          <?php if ($__isProforma && $b['requested_amount_inr'] !== null): ?><br><small class="muted">Asks for &#8377;<?= $money($b['requested_amount_inr']) ?> now</small><?php endif; ?>
          <?php if ($b['replaces_bill_no'] !== null): ?><br><small class="muted">Replaces proforma <?= htmlspecialchars($b['replaces_bill_no']) ?></small><?php endif; ?>
          <?php if ($b['replaced_by'] !== null): ?><br><small class="muted">Replaced by tax invoice <?= htmlspecialchars($b['replaced_by']) ?></small><?php endif; ?>
          <?php if (!empty($b['gst_reverse_charge'])): ?><br><small class="muted">Reverse charge — we pay the 5% GST</small><?php endif; ?>
          <?php if (in_array((int) $b['id'], $carriedIds, true)): ?><br><small class="muted">Added in Step 7e — shown here until fully paid</small><?php endif; ?>
          <?php if (!empty($b['note'])): ?><br><small class="muted"><?= htmlspecialchars((string) $b['note']) ?></small><?php endif; ?></td>
        <td style="text-align:right"><?= $money($b['taxable_inr']) ?></td>
        <td style="text-align:right"><?= $money($b['gst_inr']) ?></td>
        <td style="text-align:right"><?= $money($b['total_inr']) ?><?php if ($__isProforma): ?><br><small class="muted">not a cost<?= $b['replaced_by'] === null && $b['own_paid'] > 0 ? ' — what is paid counts until its tax invoice arrives' : '' ?></small><?php endif; ?></td>
        <td class="vendor-bill-payments">
          <?php foreach ($b['carried_payments'] as $__p): ?>
            <div><span class="vendor-bill-paid">&#10003; &#8377;<?= $money($__p['amount_inr']) ?> on <?= htmlspecialchars(\App\Helpers\Dates::short((string) $__p['paid_at'])) ?></span><br><small class="muted">advance, on proforma <?= htmlspecialchars((string) $b['replaces_bill_no']) ?></small></div>
          <?php endforeach; ?>
          <?php foreach ($b['payments'] as $__p): ?>
            <div><span class="vendor-bill-paid">&#10003; &#8377;<?= $money($__p['amount_inr']) ?> on <?= htmlspecialchars(\App\Helpers\Dates::short((string) $__p['paid_at'])) ?></span><br><small class="muted"><?= htmlspecialchars((string) $__p['payment_mode']) ?> · <?= htmlspecialchars((string) $__p['payment_ref']) ?></small></div>
          <?php endforeach; ?>
          <?php if ($b['settled']): ?>
            <?php if ($b['replaced_by'] !== null): ?><small class="muted">Nothing due — replaced</small><?php elseif ($b['payments'] === [] && $b['carried_payments'] === []): ?><small class="muted">Nothing due</small><?php endif; ?>
          <?php else: ?>
            <span class="vendor-bill-due"><?= $b['payments'] === [] && $b['carried_payments'] === [] ? 'Unpaid' : 'Due &#8377;' . $money($b['remaining']) ?></span>
            <?php if ($canPay && $__rowBlocked === null): ?>
            <details class="vendor-bill-pay">
              <summary class="btn-sm"><?= $b['payments'] === [] ? 'Mark Paid' : '&#8377; Record another payment' ?></summary>
              <?= $payToFor($b) ?>
              <form method="post" action="/orders/<?= (int) $order['id'] ?>/vendor-bills/<?= (int) $b['id'] ?>/pay" class="field-grid">
                <?= \App\Helpers\Csrf::field() ?>
                <?php if ($leg !== null): ?><input type="hidden" name="leg" value="<?= htmlspecialchars($leg) ?>"><?php endif; ?>
                <label>Amount paid (&#8377;) *<input type="number" step="0.01" min="0.01" max="<?= htmlspecialchars(number_format($b['remaining'], 2, '.', '')) ?>" name="paid_amount_inr" value="<?= htmlspecialchars(number_format($b['remaining'], 2, '.', '')) ?>" required></label>
                <label>Paid on *<input type="date" name="paid_at" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></label>
                <label>Mode *
                  <select name="payment_mode" required>
                    <?php foreach (\App\Repositories\OrderVendorBillRepository::PAYMENT_MODES as $__mode): ?><option><?= htmlspecialchars($__mode) ?></option><?php endforeach; ?>
                  </select>
                </label>
                <label>UTR / reference *<input type="text" name="payment_ref" required placeholder="e.g. SBIN1234567890"></label>
                <div><button type="submit" class="btn-sm">Save Payment</button></div>
              </form>
            </details>
            <?php if ($b['payments'] === []): ?>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/vendor-bills/<?= (int) $b['id'] ?>/delete" data-confirm="Remove the unpaid bill <?= htmlspecialchars((string) $b['bill_no'], ENT_QUOTES) ?>?" style="display:inline">
              <?= \App\Helpers\Csrf::field() ?>
              <button type="submit" class="btn-link small">Remove</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="<?= $showPartner ? 7 : 5 ?>"><strong>Total cost</strong> <small class="muted">(tax invoices; a proforma only for what is paid on it, until its tax invoice arrives)</small></td><td style="text-align:right"><strong><?= $money($cost) ?></strong></td><td><span class="vendor-bill-paid">Paid &#8377;<?= $money($paid) ?></span><br><?= $due > 0.004 ? '<span class="vendor-bill-due">Due &#8377;' . $money($due) . '</span>' : '<span class="vendor-bill-paid">Nothing due</span>' ?></td></tr>
    </table>
    <?php if ($canPay && $payBlocked !== null): ?>
      <p class="review-banner warn">&#9888; <?= htmlspecialchars($payBlocked) ?> Bills can still be added now.</p>
    <?php endif; ?>
    <?php
};
// The bill fields both kinds share (type, number, date, taxable, GST, the
// bill itself). Owner decision (2026-10-28, Option B): a Proforma may say
// how much it asks for now; a Tax invoice may replace an earlier proforma.
$vendorBillFields = static function (string $defaultKind = 'invoice', array $replaceable = [], ?string $askedHint = null) use ($gstRates): void {
    ?>
    <fieldset class="full radio-row">
      <legend>Bill type *</legend>
      <label><input type="radio" name="doc_kind" value="proforma" required<?= $defaultKind === 'proforma' ? ' checked' : '' ?>> <span><b>Proforma / advance request</b><br><small class="muted">Asks for money now. Not counted as a cost.</small></span></label>
      <label><input type="radio" name="doc_kind" value="invoice" required<?= $defaultKind === 'invoice' ? ' checked' : '' ?>> <span><b>Tax invoice / final bill</b><br><small class="muted">The real bill. Counted as the order's cost.</small></span></label>
    </fieldset>
    <label>Bill / invoice no. *<input type="text" name="bill_no" required maxlength="100" placeholder="e.g. INV-120"></label>
    <label>Bill date *<input type="date" name="bill_date" required max="<?= date('Y-m-d') ?>"></label>
    <label>Taxable value (&#8377;) * <small class="muted">(as printed on the bill)</small><input type="number" step="0.01" min="0.01" name="taxable_inr" required data-gst-base></label>
    <label>GST rate (%) *
      <select name="gst_rate_pct" required data-gst-rate data-zero-if="gst_charge=reverse" data-zero-if-message="Under reverse charge the transporter charges no GST — pick 0%. We pay the 5% to the government ourselves.">
        <?php foreach ($gstRates as $o): ?>
          <option value="<?= htmlspecialchars((string) $o['option_value']) ?>" <?= $o['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $o['option_value']) ?>%</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>GST (&#8377;) <small class="muted">(auto: Taxable &times; GST rate)</small><input type="text" readonly tabindex="-1" data-gst-amount value="0.00"></label>
    <label>Bill total (&#8377;) <small class="muted">(auto: Taxable + GST)</small><input type="text" readonly tabindex="-1" data-gst-total value="0.00"></label>
    <label data-show-when="doc_kind=proforma">Amount asked now (&#8377;) <small class="muted">(leave blank when the proforma asks for the whole bill<?= $askedHint !== null ? ' — ' . htmlspecialchars($askedHint) : '' ?>)</small><input type="number" step="0.01" min="0.01" name="requested_amount_inr" data-not-more-than="[data-gst-total]" data-not-more-than-message="The amount asked now cannot be more than the bill total."></label>
    <?php if ($replaceable !== []): ?>
    <label class="full" data-show-when="doc_kind=invoice">Advance already paid on a proforma from this vendor
      <select name="replaces_bill_id">
        <option value="">None — this invoice replaces no proforma</option>
        <?php foreach ($replaceable as $__pf): ?>
          <option value="<?= (int) $__pf['id'] ?>"<?= count($replaceable) === 1 ? ' selected' : '' ?>><?= htmlspecialchars(($__pf['partner_name'] ?? null) !== null ? $__pf['partner_name'] . ' — ' : '') ?><?= htmlspecialchars((string) $__pf['bill_no']) ?> — &#8377;<?= number_format($__pf['own_paid'], 2) ?> paid</option>
        <?php endforeach; ?>
      </select>
      <small class="muted">What was paid on the proforma counts towards this invoice; the proforma stops counting as a cost.</small>
    </label>
    <?php endif; ?>
    <label>Attach bill *<input type="file" name="bill_file" required accept=".pdf,.jpg,.jpeg,.png"></label>
    <label>Note<input type="text" name="note" maxlength="500" placeholder="optional"></label>
    <?php
};
// Owner request (2026-10-26): the supplier's advance (Step 7e) and balance
// (Step 10a — due before packing) are their own sub-steps; owner decisions
// (2026-10-27): each is now the supplier's bill, then the payment against it.
$renderSupplierPayment = static function (string $leg) use ($supplierPo, $supplierBank, $canManageSuppliers, $order, $canManageSupplierPayments, $vendorBills, $renderVendorBills, $vendorBillFields, $vendorBillPayTo): void {
    if (!$supplierPo) {
        echo '<p class="muted">Save the Supplier PO terms first (Step 7a).</p>';
        return;
    }
    $planned = $supplierPo[$leg . '_amount_inr'] ?? null;
    $paidAt = $supplierPo[$leg . '_paid_at'] ?? null;
    $label = $leg === 'advance' ? 'Advance' : 'Balance';
    $poBills = array_values(array_filter($vendorBills, static fn (array $b): bool => $b['vendor_kind'] === 'supplier' && (int) $b['supplier_po_id'] === (int) $supplierPo['id']));
    $legBills = array_values(array_filter($poBills, static fn (array $b): bool => $b['bill_for'] === $leg));
    // Owner decision (2026-10-28, Option B): a 7e tax invoice not yet fully
    // paid is shown in 10a as well, so its balance is paid from there.
    $carried = $leg === 'balance' ? array_values(array_filter($poBills, static fn (array $b): bool => $b['bill_for'] === 'advance' && $b['doc_kind'] === 'invoice' && !$b['settled'])) : [];
    $legBills = array_merge($carried, $legBills);
    $replaceable = array_values(array_filter($poBills, static fn (array $b): bool => $b['doc_kind'] === 'proforma' && $b['replaced_by'] === null));
    $inr = static fn ($v): string => is_numeric($v) ? '&#8377;' . number_format((float) $v, 2) : '—';
    echo '<div class="plan-bar"><div>Supplier PO<b>' . htmlspecialchars((string) $supplierPo['supplier_po_reference']) . '</b></div>'
        . '<div>PO total<b>' . $inr($supplierPo['total_payable_inr'] ?? null) . '</b></div>'
        . '<div>' . $label . ' planned<b>' . $inr($planned) . '</b></div>'
        . ($paidAt ? '<div>' . $label . ' paid<b class="vendor-bill-paid">' . $inr($supplierPo[$leg . '_paid_amount_inr'] ?? $planned) . ' on ' . htmlspecialchars(\App\Helpers\Dates::human((string) $paidAt)) . '</b></div>' : '')
        . '</div>';
    if ($paidAt && $legBills === []) {
        // Recorded before bills were kept — the payment stands as it is.
        echo '<p class="muted small">Paid before supplier bills were recorded here.</p>';
        return;
    }
    $editUrl = '/suppliers/' . (int) ($supplierPo['supplier_id'] ?? 0) . '/edit';
    $canEditSupplier = $canManageSuppliers && !empty($supplierPo['supplier_id']);
    $payBlocked = null;
    // Owner report (2026-10-27): the balance waits for the advance whenever the terms plan one.
    if ($leg === 'balance' && (float) ($supplierPo['advance_amount_inr'] ?? 0) > 0 && empty($supplierPo['advance_paid_at'])) {
        $payBlocked = 'Pay the supplier advance first (Step 7e) — the Supplier PO terms plan an advance of ₹' . number_format((float) $supplierPo['advance_amount_inr'], 2) . '.';
    }
    // Owner decision (2026-10-27): 0% advance — nothing to pay in 7e; the whole amount is the balance (10a).
    $noAdvance = $leg === 'advance' && !$paidAt && (float) ($planned ?? 0) <= 0;
    if ($noAdvance && $legBills === []) {
        echo '<p class="review-banner ok">&#10003; <span><strong>No advance on this order</strong> — the Supplier PO terms plan no advance, so there is nothing to pay here. The full amount is paid as the supplier balance in <a href="#step-10a-supplier-balance-paid">Step 10a</a>.</span></p>';
    } else {
        $renderVendorBills($legBills, false, $canManageSupplierPayments, $payBlocked, static fn (array $b): string => $vendorBillPayTo($supplierBank, $editUrl, $canEditSupplier), $leg, array_map(static fn (array $b): int => (int) $b['id'], $carried));
    }
    if (!$canManageSupplierPayments) {
        echo '<p class="muted small">Bills and payments are recorded by Accounts (needs the "Manage supplier payments" permission).</p>';
        return;
    }
    ?>
    <details class="vendor-bill-add"<?= $legBills === [] && !$noAdvance ? ' open' : '' ?>>
      <summary class="small"><strong>+ Add the supplier's <?= strtolower($label) ?> bill<?= $noAdvance ? ' anyway' : '' ?></strong> <span class="muted">(<?= $leg === 'advance' ? 'proforma or tax invoice' : 'tax invoice' ?>)</span></summary>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/vendor-bills/supplier" enctype="multipart/form-data" class="field-grid">
        <?= \App\Helpers\Csrf::field() ?>
        <input type="hidden" name="bill_for" value="<?= htmlspecialchars($leg) ?>">
        <?php $vendorBillFields($leg === 'advance' ? 'proforma' : 'invoice', $replaceable, $leg === 'advance' && (float) ($planned ?? 0) > 0 ? 'the planned advance is ₹' . number_format((float) $planned, 2) : null); ?>
        <div><button type="submit" class="btn-sm">Add Bill</button></div>
      </form>
    </details>
    <?php
};
// Owner request (2026-10-26): before generating a document that already
// exists, say so — so a new revision is a deliberate choice, not a mistake.
$latestDocByCode = [];
foreach ($documents as $__doc) {
    if ($__doc['status'] !== 'superseded' && !isset($latestDocByCode[$__doc['document_type_code']])) {
        $latestDocByCode[$__doc['document_type_code']] = $__doc;
    }
}
$__docAlreadyNote = static function (string $code) use ($latestDocByCode): string {
    $doc = $latestDocByCode[$code] ?? null;
    if ($doc === null) {
        return '';
    }
    return '<span class="doc-already-note">&#9888; Already generated: ' . htmlspecialchars((string) ($doc['document_reference'] ?? $code))
        . ' Rev.' . (int) $doc['revision_number'] . ' (' . htmlspecialchars($doc['status']) . ') — have a look in '
        . '<a href="#document-review-approval">Document Review &amp; Approval</a> before generating a new one.</span>';
};
// Owner request (2026-10-26): anything still waiting on someone in the
// document section is flagged on it, so nobody moves on and misses it.
$mailSendingOn = \App\Services\EmailDispatchService::mailSendingEnabled();
$docPendingActions = [];
foreach ($latestDocByCode as $__code => $__doc) {
    $__label = htmlspecialchars($__code . ' ' . ($__doc['document_reference'] ?? ''));
    if ($__doc['status'] === 'draft') {
        $docPendingActions[] = $__label . ' — generated, no reviewer assigned yet';
    } elseif ($__doc['status'] === 'in_review') {
        $docPendingActions[] = $__label . ' — waiting for approval';
    } elseif ($__doc['status'] === 'approved' && $mailSendingOn && empty($__doc['document_type_never_shown_to_buyer'])) {
        $docPendingActions[] = $__label . ' — approved, not yet sent to the buyer';
    }
}
$subStepDone = [
    '4' => [
        '4a' => $docComplete('BUYERPO'),
        // Owner request (2026-10-26): the Buyer PO Action Link is optional —
        // done once sent, and never locks 4c (staff can always attach).
        '4b' => !empty($order['buyer_action_token_hash']) ? true : null,
        // When the wet-signature setting makes the copy optional, it never locks 4d.
        '4c' => $buyerPoCopyRequired ? !empty($buyerPoDocuments) : null,
        '4d' => $hasBuyerPo,
    ],
    '5' => [
        // Skipping the PI-stage form (client won't use it) completes both.
        '5a' => $piIntake !== null || $piFormSkipped,
        '5b' => ($piIntake !== null && $piIntake['status'] === 'applied') || $piFormSkipped,
        '5c' => $docComplete('PI'),
        '5d' => $isLcPayment
            ? ($orderLcDetails !== null && $orderLcDetails['lc_amount'] !== null)
            : ($payment !== null && $payment['advance_remittance_received_at'] !== null),
        '5e' => $isLcPayment ? $lcConfirmed : $advanceCleared,
    ],
    '6' => [
        '6a' => $docComplete('OC'),
        // Owner order (2026-10-26): send the Buyer Action Link first, then the
        // acknowledgement — done whether the buyer confirms (link/portal) or
        // staff record it.
        // Owner report (2026-10-27): the link is the one already made in 4b,
        // so it only counts for 6b once the OC itself is issued — otherwise
        // 6b (and, through it, 6a) went green right after the OC draft.
        '6b' => $docComplete('OC') && !empty($order['buyer_action_token_hash']),
        '6c' => $buyerAcknowledged,
    ],
    '7' => [
        '7a' => $supplierPo !== null,
        '7b' => $docComplete('SUPPO'),
        // Owner request (2026-10-26, t4): done when the ack is on file, or staff recorded the supplier did not acknowledge.
        '7c' => $supplierPoCopyRequired ? (!empty($supplierPoDocuments) || !empty($supplierPo['ack_skipped_at'])) : null,
        '7d' => $supplierSigned,
        // Owner decision (2026-10-27): with no advance planned (0%), there is nothing to pay — 7e is done.
        '7e' => $supplierPo !== null && (!empty($supplierPo['advance_paid_at']) || (float) ($supplierPo['advance_amount_inr'] ?? 0) <= 0),
    ],
    '9' => [
        '9a' => $freight !== null,
        '9b' => $docComplete('FDN'),
        '9c' => $payment !== null && $payment['freight_remittance_received_at'] !== null,
        '9d' => $freightCleared,
    ],
    '10' => [
        '10a' => $supplierPo !== null && !empty($supplierPo['balance_paid_at']),
        '10b' => $packing !== null,
        '10c' => $docComplete('PL'),
        '10d' => $shipping !== null,
        '10e' => $docComplete('BLI'),
        '10f' => $blIssued,
    ],
    '11' => [
        '11a' => $docComplete('CI'),
        '11b' => $shipping !== null && !empty($shipping['scanned_bl_sent_to_buyer_at']),
        '11c' => $payment !== null && $payment['balance_remittance_received_at'] !== null,
        '11d' => $balanceCleared,
    ],
    '13' => [
        '13a' => $docComplete('COOPREP'),
        '13b' => $shipping !== null && !empty($shipping['bl_originals_received_at']),
        '13c' => $blEndorsement !== null,
        '13d' => $docComplete('BLE'),
        '13e' => $shipping !== null && !empty($shipping['bl_endorsed_at']),
        '13f' => $orderClosed || $order['status'] === 'shipped_docs_sent',
        // Owner decision (2026-10-27): government benefits at 13g — every
        // eligible scheme received or skipped — then 13h Mark Fully Settled.
        '13g' => $orderClosed || ($order['status'] === 'shipped_docs_sent' && $benefitSettlement['done']),
        '13h' => $orderClosed,
    ],
];
// Supplier payments (owner request 2026-10-26: their own sub-steps) are
// money that has or hasn't actually gone out — they always show their own
// real status, never take part in the "later done means earlier done"
// rule, and a finished step never paints them done.
$honestSubSteps = ['7e' => true, '10a' => true];
$currentSubStep = [];
foreach ($subStepDone as $__step => $__subs) {
    $__laterDone = false;
    foreach (array_reverse(array_keys($__subs)) as $__sub) {
        if (isset($honestSubSteps[$__sub])) {
            continue;
        }
        if ($__laterDone && $__subs[$__sub] !== null) {
            $__subs[$__sub] = true;
        } elseif ($__subs[$__sub] === true) {
            $__laterDone = true;
        }
    }
    $subStepDone[$__step] = $__subs;
    $currentSubStep[(string) $__step] = array_search(false, $__subs, true) ?: null;
}
// done/current/locked for one sub-step card: a done or locked step
// overrides every sub-step; inside the current step, done sub-steps show
// done, the first unfinished one is current, and the rest wait their turn.
$subStepStatus = static function (string $step, string $sub) use ($stepStates, $subStepDone, $currentSubStep, $honestSubSteps): string {
    $parent = $stepStates[$step];
    $done = $subStepDone[$step][$sub] ?? null;
    if (isset($honestSubSteps[$sub]) && $parent === 'done' && $done !== null) {
        return $done ? 'done' : 'current';
    }
    if ($parent !== 'current' || $done === null) {
        return $parent;
    }
    if ($done) {
        return 'done';
    }
    return $sub === $currentSubStep[$step] ? 'current' : 'locked';
};
// Inside the current step only its first unfinished sub-step starts open
// (the page scrolls to it), instead of every sub-card at once — so after
// any action the page lands on the card that's next. A step with no
// unfinished sub-step left keeps the old behaviour (all its cards open).
//
// Owner rule (2026-10-26): a sub-step can't be opened or used until the one
// before it is complete — e.g. the Buyer PO copy can't be attached until the
// generated Buyer PO is approved. A waiting sub-step (or every sub-step of a
// step not reached yet) renders data-locked: greyed, can't be expanded.
// Optional sub-steps (no done signal of their own) never lock.
$subCardOpen = static function (string $step, string $sub) use ($currentUserStep, $currentSubStep, $stepCardOpen, $subStepStatus, $stepStates, $subStepDone): string {
    if (($subStepDone[$step][$sub] ?? null) !== null || $stepStates[$step] === 'locked') {
        if ($subStepStatus($step, $sub) === 'locked') {
            $waitingOn = $stepStates[$step] === 'locked' ? 'the previous step' : ('Step ' . $currentSubStep[$step]);
            return ' data-locked="Complete ' . htmlspecialchars($waitingOn) . ' first"';
        }
    }
    if ($step !== $currentUserStep) {
        return '';
    }
    if (($currentSubStep[$step] ?? null) === null) {
        return $stepCardOpen($step);
    }
    return $sub === $currentSubStep[$step] ? ' open' : '';
};
$stepStatusIcon = static function (string $status): string {
    return match ($status) {
        'done' => '<span class="step-card-icon done" aria-hidden="true">&#10003;</span>',
        'current' => '<span class="step-card-icon current" aria-hidden="true">&#9679;</span>',
        default => '<span class="step-card-icon locked" aria-hidden="true">&#9675;</span>',
    };
};
// Owner request (2026-10-26): the 19 steps are grouped into five major
// sections, collapsed by default — only the section holding the order's
// current step opens on load. Steps 14-18 (after-sales records) are open
// to use at any time, so their section has no progress of its own.
$phases = [
    'booking'    => ['A', 'Order Booking', 'Quotation → Buyer PO → PI & advance → Order Confirmation', ['1', '2', '3', '4', '5', '6']],
    'production' => ['B', 'Procurement & Production', 'Supplier PO → production & estimated shipment', ['7', '8']],
    'shipment'   => ['C', 'Shipment & Balance', 'Freight → packing & BL → commercial invoice & balance', ['9', '10', '11']],
    'closure'    => ['D', 'Closure & Settlement', 'Financials & benefits → documents despatched → fully settled', ['12', '13', '19']],
    'aftersales' => ['E', 'After-Sales & Records', 'Additional documents · feedback · complaints · disputes · amendments', []],
];
$phaseState = static function (string $key) use ($phases, $stepStates, $currentUserStep): string {
    $steps = $phases[$key][3];
    if ($steps === []) {
        return 'any';
    }
    if (in_array($currentUserStep, $steps, true) && $stepStates[$currentUserStep] !== 'done') {
        return 'current';
    }
    $done = count(array_filter($steps, static fn (string $st): bool => $stepStates[$st] === 'done'));
    return $done === count($steps) ? 'done' : 'locked';
};
$phaseOpen = static fn (string $key): string => in_array($currentUserStep, $phases[$key][3], true) ? ' open' : '';
$phaseSummary = static function (string $key, string $badges = '') use ($phases, $stepStates, $phaseState, $stepStatusIcon): string {
    [$letter, $title, $sub, $steps] = $phases[$key];
    $state = $phaseState($key);
    $icon = $state === 'any' ? '<span class="step-card-icon any" aria-hidden="true">&#8634;</span>' : $stepStatusIcon($state);
    if ($steps === []) {
        $progress = '<span class="phase-progress">available any time</span>';
    } else {
        $done = count(array_filter($steps, static fn (string $st): bool => $stepStates[$st] === 'done'));
        $progress = '<span class="phase-progress">' . $done . ' of ' . count($steps) . ' steps done'
            . '<span class="phase-bar"><i style="width:' . (int) round(100 * $done / count($steps)) . '%"></i></span></span>';
    }
    return '<summary>' . $icon . '<span class="phase-tag">' . $letter . '</span>'
        . '<span class="phase-heading"><h2>' . htmlspecialchars($title) . $badges . '</h2><span class="phase-sub">' . htmlspecialchars($sub) . '</span></span>'
        . $progress . '</summary>';
};
// Owner request (2026-10-26): the Compliance Checklist is split by when
// each task is done — "order" tasks (Due Diligence, ECGC Cover...) sit at the
// start of Step 7 alongside the Supplier PO; "shipment" tasks (Fumigation,
// Phytosanitary, Pre-Shipment Inspection...) at the start of Step 10. Both
// run alongside the other cards and never lock them. Each row saves on its
// own without reloading the page.
$renderComplianceCard = static function (string $phase, string $step) use ($complianceTasks, $order, $canCloseOrders, $canManageComplianceTaskTypes, $stepStates, $currentUserStep, $stepStatusIcon): void {
    if (!$canCloseOrders) {
        return;
    }
    $tasks = array_values(array_filter($complianceTasks, static fn(array $ct): bool => ($ct['phase'] ?? 'shipment') === $phase));
    if ($tasks === []) {
        return;
    }
    $outstanding = count(array_filter($tasks, static fn(array $ct): bool => !in_array($ct['status'], ['approved', 'skipped'], true)));
    $status = $outstanding === 0 ? 'done' : ($stepStates[$step] === 'locked' ? 'locked' : 'current');
    $open = $outstanding > 0 && $step === $currentUserStep ? ' open' : '';
    $title = $phase === 'order' ? 'Compliance — with the Supplier PO' : 'Compliance — before shipment';
    ?>
  <details class="step-card step-card-parallel" id="<?= $phase === 'order' ? 'compliance-checklist' : 'compliance-checklist-shipment' ?>"<?= $open ?>>
    <summary><?= $stepStatusIcon($status) ?><h2><?= $title ?><?php if ($outstanding > 0): ?> <small class="muted">(<span data-compliance-outstanding><?= $outstanding ?></span> open)</small><?php endif; ?></h2></summary>
    <p class="muted small">Runs alongside the steps below — it never locks them. Every task must be resolved (approved or skipped with a reason) before the order can be marked fully settled. The task list is managed from <?= $canManageComplianceTaskTypes ? '<a href="/compliance-task-types">Compliance Task Types</a>' : 'Compliance Task Types' ?> (Admin-only).</p>
    <?php // Owner request (2026-10-26): one Save for the whole card — every row is posted together and each is stored on its own task. ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/compliance-tasks" class="compliance-card-form" data-no-draft>
      <?= \App\Helpers\Csrf::field() ?>
      <input type="hidden" name="phase" value="<?= htmlspecialchars($phase) ?>">
      <table class="list">
        <tr><th>Task</th><th>Status</th><th>Reason <small class="muted">(Not Applicable fills it in; Skipped needs one)</small></th></tr>
        <?php foreach ($tasks as $__i => $ct): ?>
        <tr data-compliance-row>
          <td><?= htmlspecialchars($ct['task_type_name']) ?>
            <input type="hidden" name="tasks[<?= (int) $__i ?>][task_type_id]" value="<?= (int) $ct['task_type_id'] ?>">
            <?php if ($ct['resolved_at']): ?><br><span class="muted small"><?= htmlspecialchars($ct['resolved_at']) ?><?php if ($ct['resolved_by_name']): ?> by <?= htmlspecialchars($ct['resolved_by_name']) ?><?php endif; ?></span><?php endif; ?>
          </td>
          <td>
            <select name="tasks[<?= (int) $__i ?>][status]" data-compliance-status aria-label="<?= htmlspecialchars($ct['task_type_name']) ?> status" style="min-width:170px;">
              <?php
              // Owner request (2026-10-27): "Not Applicable" fills its reason in;
              // "Skipped" needs one typed. Both are saved as 'skipped'.
              $__naReason = \App\Repositories\OrderComplianceTaskRepository::NOT_APPLICABLE_REASON;
              $__isNa = $ct['status'] === 'skipped' && trim((string) ($ct['skip_reason'] ?? '')) === $__naReason;
              ?>
              <?php foreach (\App\Repositories\OrderComplianceTaskRepository::STATUSES as $key => $label): ?>
              <?php if ($key === 'skipped'): ?>
              <option value="skipped" data-auto-reason="<?= htmlspecialchars($__naReason) ?>" <?= $__isNa ? 'selected' : '' ?>>Not Applicable</option>
              <option value="skipped" <?= $ct['status'] === 'skipped' && !$__isNa ? 'selected' : '' ?>>Skipped (give a reason)</option>
              <?php else: ?>
              <option value="<?= htmlspecialchars($key) ?>" <?= $ct['status'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="text" name="tasks[<?= (int) $__i ?>][skip_reason]" class="compliance-skip-reason" data-compliance-reason value="<?= htmlspecialchars($ct['skip_reason'] ?? '') ?>" placeholder="Why is it not applicable?"></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <button type="submit" class="btn-sm">Save Checklist</button>
      <span class="muted small">Saves every row at once — only the rows you changed are updated.</span>
    </form>
    <?php if ($outstanding > 0): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/compliance-tasks/mark-all-na" style="margin-top:10px;border-top:1px solid var(--line);padding-top:10px;" data-confirm="Mark every outstanding task in this checklist Not Applicable? Use this for a repeat order where these tasks genuinely do not apply.">
      <?= \App\Helpers\Csrf::field() ?>
      <input type="hidden" name="phase" value="<?= htmlspecialchars($phase) ?>">
      <label>Mark all remaining tasks here Not Applicable <small class="muted">(e.g. repeat order — reason required, applied to every outstanding task above)</small>
        <input type="text" name="skip_reason" placeholder="Reason (mandatory)" required style="min-width:260px;" value="<?= htmlspecialchars(\App\Repositories\OrderComplianceTaskRepository::NOT_APPLICABLE_REASON) ?>">
      </label>
      <button type="submit" class="btn-sm">Mark All N/A</button>
    </form>
    <?php endif; ?>
  </details>
    <?php
};
?>
<div class="card page-wide">
  <?php if (($_GET['from'] ?? '') === 'kanban'): ?>
  <?php if ($canListOrders): ?><p class="muted small"><a href="/orders">&larr; Back to Board</a></p><?php endif; ?>
  <?php else: ?>
  <?php if ($canListOrders): ?><p class="muted small"><a href="/orders">&larr; Back to Orders</a></p><?php endif; ?>
  <?php endif; ?>
  <h1><?= htmlspecialchars($order['order_reference']) ?>
    <?php if ($order['status'] !== 'active'): ?><span class="badge badge-<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars($order['status']) ?></span><?php endif; ?>
  </h1>
  <p class="muted">
    <?= htmlspecialchars($order['company_legal_name']) ?> &nbsp;·&nbsp;
    Buyer Inquiry Ref: <?= htmlspecialchars($order['buyer_inquiry_ref']) ?> &nbsp;·&nbsp;
    <?= htmlspecialchars($order['incoterm_code']) ?> <?= htmlspecialchars($order['port_of_loading_city'] ?? $order['port_of_loading_name'] ?? '') ?>
    <?php if ($canViewReports): ?>&nbsp;·&nbsp; <a href="/reports/order/<?= (int) $order['id'] ?>">Full Report</a><?php endif; ?>
    <?php if ($canManageOrders): ?>&nbsp;·&nbsp; <a href="/orders/<?= (int) $order['id'] ?>/edit">Edit Order Details</a><?php endif; ?>
    <?php if (!empty($seesAllOrders)): ?>&nbsp;·&nbsp; Assigned to:
      <strong><?= $assignees ? htmlspecialchars(implode(', ', array_column($assignees, 'name'))) : 'Unassigned' ?></strong>
    <?php endif; ?>
  </p>
  <?php if (!empty($canAssignOwner)): ?>
  <?php $__assignedUserIds = array_column($assignees, 'user_id'); ?>
  <div class="btn-row" style="margin-top:-6px;align-items:center;flex-wrap:wrap;">
    <?php foreach ($assignees as $__assignee): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/assignees/<?= (int) $__assignee['user_id'] ?>/remove" style="display:inline" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($__assignee['name'])) ?> as an assignee on this order?');">
        <?= Csrf::field() ?>
        <span class="tag tag-navy"><?= htmlspecialchars($__assignee['name']) ?> <button type="submit" class="btn-sm btn-danger" style="padding:0 4px;margin-left:4px;" title="Remove">&times;</button></span>
      </form>
    <?php endforeach; ?>
  </div>
  <form method="post" action="/orders/<?= (int) $order['id'] ?>/assignees" class="btn-row" style="margin-top:4px;margin-bottom:16px;align-items:center;">
    <?= Csrf::field() ?>
    <label class="muted small" style="margin:0;">Add assignee
      <select name="user_id" style="margin-left:4px;">
        <?php foreach ($activeUsers as $u): ?>
          <?php if (in_array((int) $u['id'], $__assignedUserIds, true)): continue; endif; ?>
          <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['role_name'] ?? '—') ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn-sm">Add</button>
  </form>
  <?php endif; ?>
  <?php if ($duplicatedFromOrder): ?>
    <p class="notice notice-info">This order was created as a repeat order from <a href="/orders/<?= (int) $duplicatedFromOrder['id'] ?>"><?= htmlspecialchars($duplicatedFromOrder['order_reference']) ?></a>.<?php if ($supplierPo && $supplierPo['status'] === 'draft'): ?> Its Supplier PO terms were carried over below as a draft — review and confirm once Stage 5 is reached.<?php endif; ?></p>
  <?php endif; ?>
  <?php if (!empty($duplicatedIntoOrders)): ?>
    <p class="notice notice-info">Repeat order<?= count($duplicatedIntoOrders) === 1 ? '' : 's' ?> placed from this order:
      <?php foreach ($duplicatedIntoOrders as $i => $d): ?><?= $i > 0 ? ', ' : '' ?><a href="/orders/<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['order_reference']) ?></a><?php endforeach; ?>.
    </p>
  <?php endif; ?>
  <?php if ($canManageOrders): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/duplicate" style="display:inline" onsubmit="return confirm('Create a new order copying this one\'s client, commercial terms, and product lines? Nothing about this order changes.');">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-sm btn-secondary" title="Repeat order — copies client, commercial terms, and product lines into a brand-new order">Duplicate Order</button>
    </form>
  <?php endif; ?>
  <?php if ($order['status'] === 'lost'): ?>
    <p class="muted small">Marked lost<?php if ($order['lost_at']): ?> on <?= htmlspecialchars($order['lost_at']) ?><?php endif; ?><?php if ($order['lost_reason']): ?> — reason: <?= htmlspecialchars($order['lost_reason']) ?><?php endif; ?></p>
  <?php endif; ?>
  <?php if ((int) ($order['is_archived'] ?? 0) === 1): ?>
    <p class="muted small">Archived<?php if ($order['archived_at']): ?> on <?= htmlspecialchars($order['archived_at']) ?><?php endif; ?> — hidden from the main Orders list. Nothing was deleted.</p>
    <?php if ($canManageOrders): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/unarchive" style="display:inline" onsubmit="return confirm('Restore <?= htmlspecialchars(addslashes($order['order_reference'])) ?> to the main Orders list?');">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-sm btn-success">Restore from Archive</button>
    </form>
    <?php endif; ?>
  <?php elseif ($canManageOrders): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/archive" style="display:inline" onsubmit="return confirm('Archive <?= htmlspecialchars(addslashes($order['order_reference'])) ?>? It will no longer appear in the main Orders list, but nothing is deleted and it can be restored any time.');">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-sm btn-secondary">Archive Order</button>
    </form>
  <?php endif; ?>
  <?php if ($order['status'] === 'active' && $canCloseOrders): ?>
  <form method="post" action="/orders/<?= (int) $order['id'] ?>/mark-lost" style="display:inline" onsubmit="return confirmMarkLost(this);">
    <?= Csrf::field() ?>
    <input type="hidden" name="reason" class="mark-lost-reason">
    <button type="button" class="btn-sm btn-danger" onclick="promptMarkLost(this)">Mark as Lost</button>
  </form>
  <script>
    function promptMarkLost(btn) {
      var form = btn.closest('form');
      var reason = promptForReason('Why is this order lost? (required — recorded in the audit log)');
      if (!reason) { return; }
      form.querySelector('.mark-lost-reason').value = reason.trim();
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }
    function confirmMarkLost(form) {
      var reason = form.querySelector('.mark-lost-reason').value;
      if (!reason) { return false; }
      return confirm('Mark <?= htmlspecialchars(addslashes($order['order_reference'])) ?> as LOST? This locks the order (same as closing it) and cannot be undone from this screen.');
    }
  </script>
  <?php endif; ?>

  <?php
    // External QA report M-09: formal cancellation workflow, distinct
    // from Mark as Lost above (which only ever applies pre-money, while
    // this order is still 'active' with no BL on file). A request here
    // never changes the order's own status; only an approval does.
    $__pendingCancellation = OrderCancellationRepository::findPendingForOrder((int) $order['id']);
  ?>
  <?php if ($order['status'] === 'cancelled'): ?>
    <?php $__cancellationHistory = OrderCancellationRepository::forOrder((int) $order['id']); $__approvedCancellation = $__cancellationHistory[0] ?? null; foreach ($__cancellationHistory as $__c) { if ($__c['status'] === 'approved') { $__approvedCancellation = $__c; break; } } ?>
    <p class="muted small">
      Cancelled<?php if ($__approvedCancellation && $__approvedCancellation['decided_at']): ?> on <?= htmlspecialchars($__approvedCancellation['decided_at']) ?> by <?= htmlspecialchars($__approvedCancellation['decided_by_name'] ?? 'unknown') ?><?php endif; ?>
      <?php if ($__approvedCancellation): ?> — reason: <?= htmlspecialchars($__approvedCancellation['reason']) ?><?php endif; ?>
      <?php if ($__approvedCancellation && $__approvedCancellation['refund_status'] !== 'not_applicable'): ?>
        <br>Refund: <?= htmlspecialchars(str_replace('_', ' ', $__approvedCancellation['refund_status'])) ?><?php if ($__approvedCancellation['refund_amount'] !== null): ?> — <?= htmlspecialchars($order['currency_code'] ?? '') ?> <?= number_format((float) $__approvedCancellation['refund_amount'], 2) ?><?php endif; ?>
        <?php if ($__approvedCancellation['refund_status'] === 'pending' && $canCloseOrders): ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/cancellation/<?= (int) $__approvedCancellation['id'] ?>/complete-refund" style="display:inline" onsubmit="return confirm('Mark this refund as completed? Record this only once the money has actually been sent back.');">
            <?= Csrf::field() ?>
            <input type="text" name="refund_notes" placeholder="Refund reference / notes (optional)" style="width:260px">
            <button type="submit" class="btn-sm btn-secondary">Mark Refund Completed</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </p>
  <?php elseif ($__pendingCancellation): ?>
    <div class="review-banner" style="flex-direction:column;align-items:stretch;gap:6px">
      <span>Cancellation requested by <?= htmlspecialchars($__pendingCancellation['requested_by_name'] ?? 'unknown') ?> on <?= htmlspecialchars($__pendingCancellation['requested_at']) ?> — awaiting approval.<br>Reason: <?= htmlspecialchars($__pendingCancellation['reason']) ?></span>
      <?php if ($canEditLockedData): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/cancellation/<?= (int) $__pendingCancellation['id'] ?>/approve" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap" onsubmit="return confirm('Approve this cancellation? The order will be locked as CANCELLED and this cannot be undone from this screen.');">
          <?= Csrf::field() ?>
          <select name="refund_status">
            <option value="not_applicable">No refund applicable</option>
            <option value="pending">Refund pending</option>
            <option value="completed">Refund already completed</option>
          </select>
          <input type="text" name="refund_amount" placeholder="Refund amount (optional)" style="width:160px">
          <input type="text" name="decision_notes" placeholder="Approval notes (optional)" style="width:220px">
          <button type="submit" class="btn-sm btn-success">Approve Cancellation</button>
        </form>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/cancellation/<?= (int) $__pendingCancellation['id'] ?>/reject" style="display:flex;gap:6px;align-items:center" onsubmit="return confirmRejectCancellation(this);">
          <?= Csrf::field() ?>
          <input type="hidden" name="decision_notes" class="reject-cancellation-notes">
          <button type="button" class="btn-sm btn-danger" onclick="promptRejectCancellation(this)">Reject Cancellation</button>
        </form>
        <script>
          function promptRejectCancellation(btn) {
            var form = btn.closest('form');
            var notes = promptForReason('Why is this cancellation request being rejected? (required — recorded in the audit log)');
            if (!notes) { return; }
            form.querySelector('.reject-cancellation-notes').value = notes.trim();
            form.requestSubmit ? form.requestSubmit() : form.submit();
          }
          function confirmRejectCancellation(form) {
            var notes = form.querySelector('.reject-cancellation-notes').value;
            if (!notes) { return false; }
            return confirm('Reject this cancellation request? The order is unaffected.');
          }
        </script>
      <?php endif; ?>
    </div>
  <?php elseif ($canCloseOrders && !in_array($order['status'], ['complete', 'lost'], true)): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/cancellation/request" style="display:inline" onsubmit="return confirmRequestCancellation(this);">
      <?= Csrf::field() ?>
      <input type="hidden" name="reason" class="request-cancellation-reason">
      <button type="button" class="btn-sm btn-danger" onclick="promptRequestCancellation(this)">Request Cancellation</button>
    </form>
    <script>
      function promptRequestCancellation(btn) {
        var form = btn.closest('form');
        var reason = promptForReason('Why is cancellation being requested? (required — recorded in the audit log; a privileged second person still has to approve this)');
        if (!reason) { return; }
        form.querySelector('.request-cancellation-reason').value = reason.trim();
        form.requestSubmit ? form.requestSubmit() : form.submit();
      }
      function confirmRequestCancellation(form) {
        var reason = form.querySelector('.request-cancellation-reason').value;
        if (!reason) { return false; }
        return confirm('Request cancellation for <?= htmlspecialchars(addslashes($order['order_reference'])) ?>? This does not cancel the order by itself — a privileged second person still has to approve it.');
      }
    </script>
  <?php endif; ?>

  <?php
    // Point 2 (2026-10-01): a quick-glance horizontal line-with-dots
    // indicator of where this order stands in the 9-stage pipeline. This
    // used to sit above a second, more detailed "Stage N" chip grid that
    // repeated the exact same 9-stage status in box form — removed (QA
    // feedback: showing the same status three times, once here and again
    // via every step-card's own status icon, reads as duplication rather
    // than detail) so the dots are now the only stage-level indicator.
    // The connecting line segment left of a step is colored via CSS
    // purely off that step's own status class (in_progress/gate_passed/
    // skipped all mean "the process has reached this far"), so no extra
    // per-segment markup is needed.
  ?>
  <div class="process-stepper" role="list" aria-label="Order stage sequence">
    <?php foreach ($stages as $s): ?>
      <div class="process-step <?= htmlspecialchars($s['status']) ?>" role="button" tabindex="0" data-stage="<?= (int) $s['stage_number'] ?>" aria-label="Jump to Stage <?= (int) $s['stage_number'] ?> — <?= htmlspecialchars($s['stage_name']) ?>" <?= $s['status'] === 'in_progress' ? 'aria-current="step"' : '' ?>>
        <div class="process-step-dot"><?= (int) $s['stage_number'] ?></div>
        <div class="process-step-label"><?= htmlspecialchars($s['stage_name']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <!-- External QA report U-12: on a phone the 1-9 progress line only
       shows stages 1-4 with no hint that it scrolls sideways for the
       rest. CSS-only, shown just at the same <=900px width everything
       else on this page already switches to a mobile layout at. -->
  <p class="muted small stepper-scroll-hint">&larr;&rarr; Swipe to see all stages</p>

  <div class="order-layout" id="order-layout">
    <div class="step-sequence" id="order-panels">

  <details class="step-card" id="step-products">
    <summary><h2>Products</h2></summary>
    <?php if (!$canEditProducts): ?>
      <table class="list">
        <tr><th>#</th><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Amount</th></tr>
        <?php foreach ($products as $i => $p): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= htmlspecialchars($p['description']) ?><?php if ($p['dimensions'] || $p['finish']): ?><br><span class="muted small"><?= htmlspecialchars($p['dimensions'] ?? '') ?> <?= htmlspecialchars($p['finish'] ?? '') ?></span><?php endif; ?></td>
          <td><?= $p['quantity_is_tbc'] ? 'To Be Confirmed' : htmlspecialchars((string) $p['quantity']) ?></td>
          <td><?= htmlspecialchars($p['unit'] ?? '') ?></td>
          <td><?= $p['unit_price'] !== null ? number_format((float) $p['unit_price'], 2) : 'To Be Confirmed' ?></td>
          <td><?= $p['fob_value'] !== null ? number_format((float) $p['fob_value'], 2) : 'To Be Confirmed' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php if ($isLockedOrder && $canManageOrders): ?>
        <p class="notice notice-error">This order is LOCKED (closed or marked lost) — editing or duplicating a product line now needs the "Override locked data" permission.</p>
      <?php elseif ($isPostConfirmationOrder && $canManageOrders): ?>
        <p class="notice notice-error">This order has already reached Order Confirmation — editing or duplicating a product line now needs the "Edit an order after confirmation" permission.</p>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($isLockedOrder): ?>
        <p class="notice notice-warning">This order is LOCKED (closed or marked lost). Adding, editing, duplicating, or removing a product line will be logged as a locked-order edit override — a reason is required below.</p>
      <?php elseif ($isPostConfirmationOrder): ?>
        <p class="notice notice-warning">This order has already reached Order Confirmation. Adding, editing, duplicating, or removing a product line will be logged as a post-confirmation edit — a reason is required below.</p>
      <?php endif; ?>
      <?php $__productReasonRequired = $isLockedOrder || $isPostConfirmationOrder; ?>
      <?php foreach ($products as $i => $p): ?>
        <div class="card-nested">
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/products/<?= (int) $p['id'] ?>">
            <?= Csrf::field() ?>
            <div class="kv-grid">
              <label>Description *<input type="text" name="description" value="<?= htmlspecialchars($p['description']) ?>" required></label>
              <label>Dimensions<input type="text" name="dimensions" value="<?= htmlspecialchars($p['dimensions'] ?? '') ?>"></label>
              <label>Finish
                <select name="finish">
                  <?php $__finishVal = (string) ($p['finish'] ?? ''); ?>
                  <option value="">—</option>
                  <?php foreach ($surfaceFinishes as $o): ?>
                    <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($__finishVal === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Qty<input type="text" name="quantity" data-max-decimals="3" data-max-value="99999999999.999" value="<?= htmlspecialchars((string) ($p['quantity'] ?? '')) ?>"></label>
              <label><input type="checkbox" name="quantity_is_tbc" value="1" style="display:inline-block;width:auto;" <?= $p['quantity_is_tbc'] ? 'checked' : '' ?>> Qty To Be Confirmed</label>
              <label>Unit
                <select name="unit">
                  <?php $__unitVal = (string) ($p['unit'] ?? ''); ?>
                  <?php foreach ($units as $o): ?>
                    <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($__unitVal === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Unit Price<input type="text" name="unit_price" data-max-decimals="2" data-max-value="999999999999.99" value="<?= htmlspecialchars((string) ($p['unit_price'] ?? '')) ?>"></label>
              <label>HS Code<input type="text" name="hs_code" value="<?= htmlspecialchars($p['hs_code']) ?>" list="hs_code_list_edit" required></label>
            </div>
            <?php if ($__productReasonRequired): ?>
              <label>Reason *<textarea name="reason" rows="2" required minlength="10" placeholder="Why is this line changing?"></textarea></label>
            <?php endif; ?>
            <button type="submit" class="btn-sm"><?= $__productReasonRequired ? 'Override & Save' : 'Save' ?></button>
          </form>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/products/<?= (int) $p['id'] ?>/duplicate" style="display:inline">
            <?= Csrf::field() ?>
            <?php if ($__productReasonRequired): ?><input type="text" name="reason" placeholder="Reason (required)" required minlength="10" style="display:inline-block;width:auto;"><?php endif; ?>
            <button type="submit" class="btn-sm btn-secondary" title="Clone this line — handy when only the name or dimensions differ">Duplicate</button>
          </form>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/products/<?= (int) $p['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this product line?');">
            <?= Csrf::field() ?>
            <?php if ($__productReasonRequired): ?><input type="text" name="reason" placeholder="Reason (required)" required minlength="10" style="display:inline-block;width:auto;"><?php endif; ?>
            <button type="submit" class="btn-sm btn-danger">Remove</button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (empty($products)): ?><p class="muted">No product lines yet.</p><?php endif; ?>

      <div class="card-nested">
        <strong>Add Product Line</strong>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/products">
          <?= Csrf::field() ?>
          <div class="kv-grid">
            <label>Description *<input type="text" name="description" required></label>
            <label>Dimensions<input type="text" name="dimensions"></label>
            <label>Finish
              <select name="finish">
                <option value="">—</option>
                <?php foreach ($surfaceFinishes as $o): ?>
                  <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Qty<input type="text" name="quantity" data-max-decimals="3" data-max-value="99999999999.999"></label>
            <label><input type="checkbox" name="quantity_is_tbc" value="1" style="display:inline-block;width:auto;"> Qty To Be Confirmed</label>
            <label>Unit
              <select name="unit">
                <?php foreach ($units as $o): ?>
                  <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Unit Price<input type="text" name="unit_price" data-max-decimals="2" data-max-value="999999999999.99"></label>
            <label>HS Code<input type="text" name="hs_code" list="hs_code_list_edit" required></label>
          </div>
          <?php if ($__productReasonRequired): ?>
            <label>Reason *<textarea name="reason" rows="2" required minlength="10" placeholder="Why is a new line being added?"></textarea></label>
          <?php endif; ?>
          <button type="submit"><?= $__productReasonRequired ? 'Override & Add' : 'Add Product Line' ?></button>
        </form>
      </div>
      <datalist id="hs_code_list_edit">
        <?php foreach ($hsCodes ?? [] as $hc): ?>
          <option value="<?= htmlspecialchars($hc['code']) ?>"><?= htmlspecialchars($hc['description'] ?? '') ?></option>
        <?php endforeach; ?>
      </datalist>
    <?php endif; ?>
  </details>

  <details class="step-card step-card-reference" id="payment-overview">
    <summary><h2>Payment Overview <small class="muted">(reference — every leg, always current)</small></h2></summary>
    <p class="muted small">Pinned here at the top rather than wherever it happened to sit in the sequence, since it's referenced from every payment step below and staff need it at a glance regardless of which step they're on.</p>

    <div class="tabs" data-tabs>
      <div class="tabs-nav" role="tablist">
        <button type="button" class="tab-btn" data-tab-target="payment-overview-snapshot" aria-selected="true">Snapshot</button>
        <button type="button" class="tab-btn" data-tab-target="payment-overview-ledger" aria-selected="false">Ledger</button>
        <button type="button" class="tab-btn" data-tab-target="payment-overview-full-status" aria-selected="false">Full Status</button>
      </div>

      <div class="tab-panel" id="payment-overview-snapshot" data-tab-panel>
        <h3>Snapshot</h3>
      <div class="kv-grid">
        <?php foreach ($paymentLegs as $leg): ?>
        <div>
          <span class="k"><?= htmlspecialchars($leg['label']) ?></span>
          <span class="v">
            <?php if ($leg['amount'] === null && $leg['received_at'] === null): ?>
              Not applicable / not yet recorded
            <?php elseif ($canViewPaymentAmounts): ?>
              <?= $leg['amount'] !== null ? number_format((float) $leg['amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?>
              — <?= $leg['cleared_at'] !== null ? 'Cleared' : ($leg['received_at'] !== null ? 'Received, pending clearance' : 'Not yet received') ?>
            <?php else: ?>
              <?= $leg['cleared_at'] !== null ? 'Cleared' : ($leg['received_at'] !== null ? 'Received, pending clearance' : 'Not yet received') ?>
            <?php endif; ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$canViewPaymentAmounts): ?>
      <p class="muted small">Amounts are only shown to Accounts/Logistics/Export staff who can manage or close this order.</p>
      <?php endif; ?>
      </div>

      <div class="tab-panel" id="payment-overview-ledger" data-tab-panel>
        <h3>Ledger</h3>
      <p class="muted">A single, read-only history of every money event recorded against this order<?= $ledgerTotals !== null ? ' — received from the buyer, paid to the supplier, every expense and any export benefit' : '' ?>. Nothing here can be edited; use the forms in each step to record new events.</p>
      <?php if (empty($ledgerEvents)): ?>
      <p class="muted small">No payment events recorded yet.</p>
      <?php else: ?>
      <?php $__showAmounts = $canViewPaymentAmounts || $ledgerTotals !== null; ?>
      <div class="table-scroll" style="overflow-x:auto;">
      <table class="list ledger-table">
        <tr><th>Date</th><th>Event</th><?php if ($__showAmounts): ?><th>Amount</th><?php endif; ?><?php if ($ledgerTotals !== null): ?><th>In (&#8377;)</th><th>Out (&#8377;)</th><?php endif; ?><th>Source</th></tr>
        <?php foreach ($ledgerEvents as $ev): ?>
        <tr class="ledger-<?= htmlspecialchars($ev['direction']) ?>">
          <td><?= htmlspecialchars(substr((string) $ev['date'], 0, 10)) ?></td>
          <td><?= htmlspecialchars($ev['event']) ?><?php if ($ledgerTotals !== null && $ev['rate'] !== null): ?><br><span class="muted small">at &#8377;<?= number_format((float) $ev['rate'], 4) ?> per <?= htmlspecialchars($ev['currency']) ?><?= $ev['inr_basis'] === 'actual' ? ' — INR actual recorded' : '' ?></span><?php endif; ?></td>
          <?php if ($__showAmounts): ?>
          <td><?= $ev['amount'] !== null ? number_format((float) $ev['amount'], 2) . ' ' . htmlspecialchars($ev['currency']) : '—' ?></td>
          <?php endif; ?>
          <?php if ($ledgerTotals !== null): ?>
          <td><?= $ev['direction'] === 'in' && $ev['inr'] !== null ? number_format((float) $ev['inr'], 2) : ($ev['direction'] === 'in' ? '<span class="muted small">no rate</span>' : '') ?></td>
          <td><?= $ev['direction'] === 'out' ? number_format((float) $ev['inr'], 2) : '' ?></td>
          <?php endif; ?>
          <td><?= htmlspecialchars($ev['source']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      </div>
      <?php endif; ?>
      <?php if ($ledgerTotals !== null): ?>
      <div class="kv-grid ledger-totals">
        <div><span class="k">Received from buyer</span><span class="v"><?= number_format($ledgerTotals['buyer_foreign'], 2) ?> <?= htmlspecialchars($order['currency_code'] ?? '') ?> = &#8377;<?= number_format($ledgerTotals['buyer_inr'], 2) ?></span></div>
        <div><span class="k">Export benefits received</span><span class="v">&#8377;<?= number_format($ledgerTotals['benefits_inr'], 2) ?></span></div>
        <div><span class="k">Paid to supplier</span><span class="v">&#8377;<?= number_format($ledgerTotals['supplier_inr'], 2) ?></span></div>
        <div><span class="k">Paid to logistics partners</span><span class="v">&#8377;<?= number_format($ledgerTotals['logistics_inr'], 2) ?></span></div>
        <div><span class="k">Expenses</span><span class="v">&#8377;<?= number_format($ledgerTotals['expenses_inr'], 2) ?></span></div>
        <div class="full"><span class="k">Net so far</span><span class="v"><strong>&#8377;<?= number_format($ledgerTotals['net_inr'], 2) ?></strong></span></div>
      </div>
      <p class="muted small">INR uses each remittance's INR actual when recorded, otherwise the exchange rate recorded with it<?= $ledgerTotals['buyer_without_rate'] > 0 ? ' — ' . (int) $ledgerTotals['buyer_without_rate'] . ' remittance(s) have no rate yet and are left out of the INR totals' : '' ?>. Shown only to staff with the Order Financials permission.</p>
      <?php endif; ?>
      </div>

      <div class="tab-panel" id="payment-overview-full-status" data-tab-panel>
        <h3>Full Status</h3>
      <?php if ($payment): ?>
      <div class="kv-grid">
        <div><span class="k">Advance Amount</span><span class="v"><?php if (!$canViewPaymentAmounts): ?>Only shown to Accounts/Logistics/Export staff<?php else: ?><?= $payment['advance_amount'] !== null ? number_format((float) $payment['advance_amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?><?php endif; ?></span></div>
        <div><span class="k">Advance T/T Received</span><span class="v"><?= htmlspecialchars($payment['advance_remittance_received_at'] ?? '—') ?></span></div>
        <div><span class="k">Advance Cleared</span><span class="v"><?= htmlspecialchars($payment['advance_cleared_at'] ?? '—') ?></span></div>
        <div><span class="k">Balance Amount</span><span class="v"><?php if (!$canViewPaymentAmounts): ?>Only shown to Accounts/Logistics/Export staff<?php else: ?><?= $payment['balance_amount'] !== null ? number_format((float) $payment['balance_amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?><?php endif; ?></span></div>
        <div><span class="k">Balance Due</span><span class="v"><?= htmlspecialchars($payment['balance_due_date'] ?? 'event-triggered') ?></span></div>
        <div><span class="k">Balance T/T Received</span><span class="v"><?= htmlspecialchars($payment['balance_remittance_received_at'] ?? '—') ?></span></div>
        <div><span class="k">Balance Cleared</span><span class="v"><?= htmlspecialchars($payment['balance_cleared_at'] ?? '—') ?></span></div>
        <?php if ($payment['freight_amount'] !== null || $payment['freight_remittance_received_at'] !== null): ?>
        <div><span class="k">Freight Amount</span><span class="v"><?php if (!$canViewPaymentAmounts): ?>Only shown to Accounts/Logistics/Export staff<?php else: ?><?= $payment['freight_amount'] !== null ? number_format((float) $payment['freight_amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?><?php endif; ?></span></div>
        <div><span class="k">Freight Cleared</span><span class="v"><?= htmlspecialchars($payment['freight_cleared_at'] ?? '—') ?></span></div>
        <?php endif; ?>
        <?php if ($canViewPaymentAmounts || $ledgerTotals !== null): ?>
        <?php foreach (['advance' => 'Advance', 'freight' => 'Freight', 'balance' => 'Balance'] as $__leg => $__label): ?>
        <?php if ($payment[$__leg . '_exchange_rate'] !== null): ?>
        <div><span class="k"><?= $__label ?> Exchange Rate</span><span class="v">&#8377;<?= number_format((float) $payment[$__leg . '_exchange_rate'], 4) ?> per <?= htmlspecialchars($order['currency_code'] ?? '') ?></span></div>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($ledgerTotals !== null): ?>
        <div><span class="k">Supplier Advance Paid</span><span class="v"><?= !empty($supplierPo['advance_paid_at']) ? '&#8377;' . number_format((float) ($supplierPo['advance_paid_amount_inr'] ?? $supplierPo['advance_amount_inr'] ?? 0), 2) . ' on ' . htmlspecialchars((string) $supplierPo['advance_paid_at']) : '—' ?></span></div>
        <div><span class="k">Supplier Balance Paid</span><span class="v"><?= !empty($supplierPo['balance_paid_at']) ? '&#8377;' . number_format((float) ($supplierPo['balance_paid_amount_inr'] ?? $supplierPo['balance_amount_inr'] ?? 0), 2) . ' on ' . htmlspecialchars((string) $supplierPo['balance_paid_at']) : '—' ?></span></div>
        <div><span class="k">Expenses Recorded</span><span class="v">&#8377;<?= number_format($ledgerTotals['expenses_inr'], 2) ?></span></div>
        <div><span class="k">Net So Far</span><span class="v">&#8377;<?= number_format($ledgerTotals['net_inr'], 2) ?></span></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <p class="muted small">Advance (or LC) is recorded from Step 5 above; Freight and Balance are recorded from their own steps below.</p>

      <?php if (!empty($clientPaymentReports)): ?>
        <h3 style="margin-top:16px">Client-Reported Payments</h3>
        <p class="muted small">Purely informational — the client submitted these themselves. Always verify against the actual bank statement before recording a payment above.</p>
        <table class="list">
          <tr><th>Payment</th><th>Transaction ID / UTR</th><th>Amount</th><th>Date</th><th>Bank / Payer Details</th><th>Attachment</th><th>Reported</th><th>Status</th><th></th></tr>
          <?php foreach ($clientPaymentReports as $r): ?>
          <tr>
            <td><?= htmlspecialchars(ucfirst($r['payment_type'])) ?></td>
            <td><?= htmlspecialchars($r['transaction_ref']) ?></td>
            <td><?= $canViewPaymentAmounts ? ($r['amount'] !== null ? number_format((float) $r['amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—') : '—' ?></td>
            <td><?= htmlspecialchars($r['payment_date'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['payer_bank_details'] ?? '—') ?></td>
            <td>
              <?php if (!empty($r['screenshot_file_id'])): ?>
                <?php $screenshotUrl = '/file-store/' . (int) $r['screenshot_file_id'] . '/download'; ?>
                <?php if (str_starts_with((string) $r['screenshot_mime_type'], 'image/')): ?>
                  <a href="<?= $screenshotUrl ?>" target="_blank"><img src="<?= $screenshotUrl ?>" alt="Payment screenshot" class="asset-preview" style="max-height:60px;"></a>
                <?php else: ?>
                  <a href="<?= $screenshotUrl ?>" target="_blank">View attachment</a>
                <?php endif; ?>
              <?php else: ?>
                <span class="muted small">—</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(Dates::human($r['reported_at'])) ?></td>
            <td><?= $r['status'] === 'reviewed' ? 'Reviewed by ' . htmlspecialchars($r['reviewed_by_name'] ?? '—') : 'New' ?></td>
            <td>
              <?php if ($r['status'] !== 'reviewed'): ?>
              <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment-reports/<?= (int) $r['id'] ?>/reviewed">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-sm">Mark Reviewed</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      </div>
    </div>
  </details>

  <?php // Owner decision (2026-10-26): opens by itself only when a document is waiting on someone; otherwise a slim bar with the count. ?>
  <details class="step-card step-card-reference" id="document-review-approval"<?= $docPendingActions ? ' open' : '' ?>>
    <summary><h2>Document Review &amp; Approval <small class="muted">(reference &mdash; every document, every step)</small><?php if ($docPendingActions): ?> <span class="nav-badge doc-pending-badge"><?= count($docPendingActions) ?> need<?= count($docPendingActions) === 1 ? 's' : '' ?> action</span><?php elseif ($documents): ?> <span class="phase-pill"><?= count($documents) ?> document<?= count($documents) === 1 ? '' : 's' ?></span><?php endif; ?></h2></summary>
    <?php if ($docPendingActions): ?>
    <div class="review-banner warn doc-pending-actions">
      <div><strong>Waiting on someone — don't move on without these:</strong>
        <ul style="margin:4px 0 0 18px;padding:0;">
          <?php foreach ($docPendingActions as $__item): ?><li><?= $__item ?></li><?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>
    <h3 style="font-size:0.95em;">All Documents</h3>
    <table class="list">
      <tr><th>Type</th><th>Reference</th><th>Rev. (internal / client-facing)</th><th>Generated</th><th>Status</th><th>Download</th><th></th></tr>
      <?php foreach ($documents as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['document_type_code']) ?></td>
        <td><?= htmlspecialchars($d['document_reference'] ?? '—') ?></td>
        <td><?= (int) $d['revision_number'] ?><?php if ($d['client_revision_number'] !== null): ?> <span class="muted small">(client: <?= (int) $d['client_revision_number'] ?>)</span><?php endif; ?></td>
        <td><?= htmlspecialchars($d['generated_at']) ?></td>
        <td>
          <?php if ($d['status'] === 'superseded'): ?>
          <span class="badge badge-danger" title="A newer revision of this document was approved — this copy is no longer valid and is watermarked INVALID DOCUMENT.">INVALID — SUPERSEDED</span>
          <?php else: ?>
          <?= htmlspecialchars(str_replace('_', ' ', $d['status'])) ?>
          <?php endif; ?>
          <?php if (!empty($d['drift_flagged_at'])): ?>
            <br><span class="badge badge-sensitive" title="<?= htmlspecialchars($d['drift_flagged_reason'] ?? '') ?>">&#9888; DATA MAY BE STALE</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($d['pdf_file_id'] && $canDownloadPdf): ?><a href="/documents/<?= (int) $d['id'] ?>/download?format=pdf">PDF</a><?php endif; ?>
          <?php if ($d['docx_file_id'] && $canDownloadPdf): ?> · <a href="/documents/<?= (int) $d['id'] ?>/download?format=docx">DOCX (internal)</a><?php endif; ?>
        </td>
        <td>
          <?php if ($d['status'] === 'draft'): ?>
          <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/<?= (int) $d['id'] ?>/delete" onsubmit="return confirmDeleteDraft(this);">
            <?= Csrf::field() ?>
            <input type="hidden" name="reason" class="delete-draft-reason">
            <button type="button" class="btn-sm btn-danger" onclick="promptDeleteDraft(this)">Delete Draft</button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($documents)): ?>
      <tr><td colspan="7" class="muted">No documents generated yet.</td></tr>
      <?php endif; ?>
    </table>
    <p class="muted small" style="margin-top:12px;">Free-form additional files (not one of the fixed document types above) are attached from <a href="#step-additional-documents">Step 14 &middot; Additional Documents</a>.</p>
    <script>
      function promptDeleteDraft(btn) {
        var form = btn.closest('form');
        var reason = promptForReason('Why is this draft document being deleted? (required, at least 10 characters — recorded in the audit log)');
        if (!reason) { return; }
        form.querySelector('.delete-draft-reason').value = reason.trim();
        form.requestSubmit ? form.requestSubmit() : form.submit();
      }
      function confirmDeleteDraft(form) {
        var reason = form.querySelector('.delete-draft-reason').value;
        if (!reason) { return false; }
        return confirm('Delete this draft document? This cannot be undone.');
      }
      // External QA report L-18: removing an additional document took no
      // reason at all — same prompt()-for-reason pattern as
      // promptDeleteDraft() above.
      function promptRemoveAdditionalDocument(btn) {
        var form = btn.closest('form');
        var reason = promptForReason('Why is this document being removed? (required, at least 10 characters — recorded in the audit log)');
        if (!reason) { return; }
        form.querySelector('.remove-additional-document-reason').value = reason.trim();
        form.requestSubmit ? form.requestSubmit() : form.submit();
      }
      function confirmRemoveAdditionalDocument(form) {
        var reason = form.querySelector('.remove-additional-document-reason').value;
        if (!reason) { return false; }
        return confirm('Remove this document? The file itself is never deleted from disk.');
      }
    </script>

    <h3 style="font-size:0.95em; margin-top:18px;">Review &amp; Approval</h3>
    <p class="muted">A document generated above starts life <strong>draft</strong> with the DRAFT watermark. Assign at least one reviewer to move it to review; once every assigned reviewer approves (minimum required per document type), it's re-watermarked as final and can be sent to the buyer. The buyer only ever receives that final watermarked PDF — never the internal DOCX, never an unwatermarked copy.</p>
    <?php foreach ($documents as $d):
      $reviews = $reviewsByDocument[(int) $d['id']] ?? [];
      $crossVerifications = $crossVerificationsByDocument[(int) $d['id']] ?? [];
      $myPendingReview = null;
      $anyRejected = false;
      foreach ($reviews as $r) {
          if ((int) $r['reviewer_id'] === (int) $currentUser['id'] && $r['status'] === 'pending') {
              $myPendingReview = $r;
          }
          if ($r['status'] === 'rejected') {
              $anyRejected = true;
          }
      }
      // Drives Step 2's banner/number styling — the whole point being that
      // a generating team member can never lose track of an unreviewed
      // document: it stays visibly flagged until a reviewer is assigned,
      // then shows exactly who has it and what they decided.
      if ($d['status'] === 'superseded') {
          // Point 1 (2026-10-01): a newer revision of this (order, type)
          // was approved after this one — this copy is now invalid
          // regardless of what its old reviews say, and must never be
          // offered a "Send to Buyer" action below.
          $reviewState = 'superseded';
      } elseif (empty($reviews)) {
          $reviewState = 'not_sent';
      } elseif ($anyRejected && $d['status'] === 'draft') {
          $reviewState = 'rejected';
      } elseif ($d['status'] === 'approved' || $d['status'] === 'sent') {
          $reviewState = 'approved';
      } else {
          $reviewState = 'under_review';
      }
      // 90-item backlog #12: "Send to Buyer" must apply to every document
      // type, generically — driven by document_types.category rather than
      // a hardcoded per-type list, so a type added later (or any existing
      // internal/procurement type not yet on a hand-maintained exclusion
      // list) is correctly included/excluded without a code change here.
      $sendApplies = ($d['document_type_category'] ?? null) === 'customer_facing';
      $emailLogs = $emailLogByDocument[(int) $d['id']] ?? [];
      $hasPendingSend = false;
      foreach ($emailLogs as $log) {
          if ($log['status'] === 'pending_approval' || $log['status'] === 'approved') {
              $hasPendingSend = true;
          }
      }
    ?>
      <details>
        <summary>
          <?= htmlspecialchars($d['document_type_code']) ?> <?= htmlspecialchars($d['document_reference'] ?? '—') ?> Rev.<?= (int) $d['revision_number'] ?> — <?= htmlspecialchars(str_replace('_', ' ', $d['status'])) ?>
          <?php if ($reviewState === 'superseded'): ?><span class="review-pill bad">⚠ INVALID — superseded by a newer revision</span>
          <?php elseif ($reviewState === 'not_sent'): ?><span class="review-pill warn">⚠ Not sent for review</span>
          <?php elseif ($reviewState === 'under_review'): ?><span class="review-pill info">Under review</span>
          <?php elseif ($reviewState === 'rejected'): ?><span class="review-pill bad">Rejected — back to draft</span>
          <?php elseif ($reviewState === 'approved'): ?><span class="review-pill good">✓ Approved</span>
          <?php endif; ?>
        </summary>

        <div class="review-steps">
          <!-- Step 1: Assign Reviewers -->
          <div class="review-step">
            <div class="review-step-num <?= empty($reviews) ? 'is-warn' : 'is-done' ?>">1</div>
            <div class="review-step-body">
              <h3>Assign Reviewers<?php if (!empty($reviews)): ?><span class="review-pill info">Assigned</span><?php endif; ?></h3>
              <?php if (empty($reviews)): ?>
                <?php if ($canApproveDocuments): // QA Round 5, R5-20 ?>
                <form method="post" action="/documents/<?= (int) $d['id'] ?>/reviewers">
                  <?= Csrf::field() ?>
                  <label>Assign reviewer(s) * (minimum required: <?= (int) ($d['min_reviewers_default'] ?? 1) ?>)
                    <select name="reviewer_ids[]" multiple size="4" required style="min-width:220px">
                      <?php foreach ($activeUsers as $u): ?>
                        <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['role_name'] ?? '—') ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <button type="submit" class="btn-sm">Assign Reviewers</button>
                </form>
                <?php endif; ?>
              <?php else: ?>
                <table class="list">
                  <tr><th>Reviewer</th><th>Status</th><th>Comments</th><th>Reviewed</th></tr>
                  <?php foreach ($reviews as $r): ?>
                    <tr>
                      <td><?= htmlspecialchars($r['reviewer_name']) ?></td>
                      <td><?= htmlspecialchars($r['status']) ?></td>
                      <td><?= htmlspecialchars($r['comments'] ?? '') ?></td>
                      <td><?= htmlspecialchars($r['reviewed_at'] ?? '—') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              <?php endif; ?>
            </div>
          </div>

          <!-- Step 2: Approval Status -->
          <div class="review-step">
            <div class="review-step-num <?= $reviewState === 'approved' ? 'is-done' : ($reviewState === 'rejected' ? 'is-bad' : ($reviewState === 'not_sent' ? 'is-warn' : '')) ?>">2</div>
            <div class="review-step-body">
              <h3>Approval Status</h3>
              <?php if ($reviewState === 'superseded'): ?>
                <div class="review-banner bad">✕ <span><strong>INVALID — superseded.</strong> A newer revision of this document was approved after this one, so this copy no longer counts as valid and has been re-watermarked INVALID DOCUMENT — SUPERSEDED. It can no longer be sent to the buyer; use the latest revision instead.</span></div>
              <?php elseif ($reviewState === 'not_sent'): ?>
                <div class="review-banner warn">⚠ <span><strong>Not yet sent for review.</strong> This document cannot be finalized or sent to the buyer until at least one reviewer is assigned above. Assign one now, before moving on to your next task.</span></div>
              <?php elseif ($reviewState === 'under_review'): ?>
                <div class="review-banner info">◔ <span><strong>Under review<?php $pendingNames = array_map(fn($r) => $r['reviewer_name'], array_filter($reviews, fn($r) => $r['status'] === 'pending')); if ($pendingNames): ?> — with <?= htmlspecialchars(implode(', ', $pendingNames)) ?><?php endif; ?>.</strong> If this sits too long, follow up with them directly.</span></div>
              <?php elseif ($reviewState === 'rejected'): ?>
                <div class="review-banner bad">✕ <span><strong>Rejected — back to draft.</strong> Fix the issue raised and regenerate this document.</span></div>
              <?php elseif ($reviewState === 'approved'): ?>
                <div class="review-banner good">✓ <span><strong>Approved.</strong> The FINAL watermarked PDF has been generated — clear to proceed to Send to Buyer below.</span></div>
              <?php endif; ?>

              <?php if ($myPendingReview): ?>
                <form method="post" action="/reviews/<?= (int) $myPendingReview['id'] ?>/approve" style="display:inline">
                  <?= Csrf::field() ?>
                  <input type="text" name="comments" placeholder="Comments (optional)">
                  <button type="submit" class="btn-sm btn-success">Approve</button>
                </form>
                <form method="post" action="/reviews/<?= (int) $myPendingReview['id'] ?>/reject" style="display:inline" onsubmit="return confirm('Reject this document? It goes back for rework.');">
                  <?= Csrf::field() ?>
                  <input type="text" name="comments" placeholder="Reason (mandatory)" required>
                  <button type="submit" class="btn-sm btn-danger">Reject</button>
                </form>
              <?php endif; ?>

              <?php if ($canCrossVerifyDocuments): // QA Round 5, R5-20 ?>
              <form method="post" action="/documents/<?= (int) $d['id'] ?>/cross-verify" style="margin-top:6px">
                <?= Csrf::field() ?>
                <select name="result" required>
                  <option value="pass">Cross-verify: Pass</option>
                  <option value="fail">Cross-verify: Fail</option>
                </select>
                <input type="text" name="comments" placeholder="Comments (optional)">
                <button type="submit" class="btn-sm">Record Cross-Verification</button>
              </form>
              <?php endif; ?>
              <?php if (!empty($crossVerifications)): ?>
                <p class="muted small">
                  <?php foreach ($crossVerifications as $cv): ?>
                    <?= htmlspecialchars($cv['verified_by_name']) ?>: <?= htmlspecialchars($cv['result']) ?> (<?= htmlspecialchars($cv['verified_at']) ?>)<br>
                  <?php endforeach; ?>
                </p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Step 3: Send to Buyer -->
          <?php if ($sendApplies): ?>
          <div class="review-step">
            <div class="review-step-num <?= $d['status'] === 'sent' ? 'is-done' : '' ?>">3</div>
            <div class="review-step-body">
              <h3>Send to Buyer</h3>
              <?php if (!empty($emailLogs)): ?>
                <table class="list" style="margin-top:6px">
                  <tr><th>Sent To</th><th>Status</th><th>Requested</th><th>Detail</th></tr>
                  <?php foreach ($emailLogs as $log): ?>
                    <tr>
                      <td><?= htmlspecialchars($log['recipient_email']) ?></td>
                      <td><?= htmlspecialchars(str_replace('_', ' ', $log['status'])) ?></td>
                      <td><?= htmlspecialchars($log['created_at']) ?></td>
                      <td>
                        <?php
                          $canCancelThis = $currentUser && (PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'approve_email_send') || (int) $log['requested_by'] === (int) $currentUser['id']);
                        ?>
                        <?php if ($log['status'] === 'sent'): ?>
                          Sent <?= htmlspecialchars($log['sent_at'] ?? '') ?>
                        <?php elseif ($log['status'] === 'rejected'): ?>
                          Rejected: <?= htmlspecialchars($log['rejection_reason'] ?? '') ?>
                        <?php elseif ($log['status'] === 'failed'): ?>
                          Dispatch failed — check mail server configuration and retry.
                        <?php elseif ($log['status'] === 'cancelled'): ?>
                          Cancelled: <?= htmlspecialchars($log['cancellation_reason'] ?? '') ?>
                        <?php elseif ($log['status'] === 'approved'): ?>
                          Approved — will send once its scheduled time arrives.
                          <?php if ($canCancelThis): ?>
                          <form method="post" action="/email-log/<?= (int) $log['id'] ?>/cancel" style="margin-top:4px" onsubmit="return confirm('Cancel this send? It will never go out.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="text" name="reason" placeholder="Cancel reason (mandatory)" required style="width:200px">
                            <button type="submit" class="btn-sm btn-warning">Cancel Send</button>
                          </form>
                          <?php endif; ?>
                        <?php else: ?>
                          Awaiting Level-2 approval.
                          <?php if ($canCancelThis): ?>
                          <form method="post" action="/email-log/<?= (int) $log['id'] ?>/cancel" style="margin-top:4px" onsubmit="return confirm('Cancel this send? It will never go out.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="text" name="reason" placeholder="Cancel reason (mandatory)" required style="width:200px">
                            <button type="submit" class="btn-sm btn-warning">Cancel Send</button>
                          </form>
                          <?php endif; ?>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              <?php endif; ?>

              <?php if ($d['status'] === 'approved' && !$mailSendingEnabled): ?>
                <p class="muted">Email sending is turned off (Settings), so there is no Send to Buyer — documents approved from now on count as sent automatically. Send this PDF to the buyer yourself.</p>
              <?php elseif ($d['status'] === 'approved'): ?>
                <?php if ($hasPendingSend): ?>
                  <p class="muted">A send to the buyer is already in progress for this document (see above) — no new send can be submitted until it's sent, rejected, or fails.</p>
                <?php else: ?>
                  <p><a href="/orders/<?= (int) $order['id'] ?>/documents/<?= (int) $d['id'] ?>/send" class="btn-sm btn-accent">Send to Buyer (deferred, 2-level approval)</a></p>
                <?php endif; ?>
              <?php elseif ($d['status'] === 'sent'): ?>
                <p class="muted"><?= $mailSendingEnabled || !empty($emailLogs) ? 'Already sent to buyer.' : 'Counted as sent on approval (email sending is off) — send the PDF to the buyer yourself.' ?></p>
              <?php elseif ($d['status'] === 'superseded'): ?>
                <p class="muted small">This revision is INVALID — superseded. It can no longer be sent; see the latest revision instead.</p>
              <?php else: ?>
                <p class="muted small">Waiting on approval above before this document can be sent.</p>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </details>

  <?php // Owner decision (2026-10-26, t4): pinned open above the five sections — the client conversation is needed at every step. ?>
  <details class="step-card step-card-reference" id="order-updates" open>
    <summary><h2>Order Communication <small class="muted">(with the client — any time)</small><?php if ($comments): ?> <span class="phase-pill"><?= count($comments) ?> message<?= count($comments) === 1 ? '' : 's' ?></span><?php endif; ?></h2></summary>
    <p class="muted">A running conversation with the client for this order. <?php if ($mailSendingEnabled): ?>Anything you post here is emailed to the client immediately, with any attached images/videos (a large file becomes a secure download link instead of a raw attachment).<?php else: ?><strong>Email sending is turned off</strong> — anything you post here appears in the client's portal only; the client is NOT emailed.<?php endif; ?> Anything the client posts appears here too and notifies staff — nothing from either side is ever deleted.<?php if ($canGenerateDocuments): ?> Need something more formal — a payment reminder, a one-off notice? <a href="/orders/<?= (int) $order['id'] ?>/email/compose">Compose an email from a template</a>.<?php endif; ?></p>
    <div class="chat-thread">
      <?php if (empty($comments)): ?>
        <p class="chat-empty">No updates posted yet.</p>
      <?php endif; ?>
      <?php foreach ($comments as $c): ?>
        <?php $isStaff = $c['author_type'] === 'staff'; ?>
        <div class="chat-bubble-row <?= $isStaff ? 'staff' : 'client' ?>">
          <div class="chat-bubble <?= $isStaff ? 'staff' : 'client' ?>">
            <div class="chat-bubble-meta">
              <span class="who"><?= $isStaff ? 'Staff' : 'Client' ?> — <?= htmlspecialchars($isStaff ? ($c['staff_name'] ?? 'Unknown') : ($c['client_name'] ?? 'Unknown')) ?></span>
              <span><?= htmlspecialchars(Dates::human($c['created_at'])) ?><?= $isStaff ? ($c['email_sent'] ? ' · emailed' : ' · not emailed (no client email on file)') : '' ?></span>
            </div>
            <?php if ($c['body']): ?><div class="chat-bubble-body"><?= htmlspecialchars($c['body']) ?></div><?php endif; ?>
            <?php if (!empty($c['attachments'])): ?>
              <div class="chat-attachments">
                <?php foreach ($c['attachments'] as $att): ?>
                  <?php
                    $mime = (string) ($att['mime_type'] ?? '');
                    $url = "/orders/{$order['id']}/comment-attachments/{$att['file_id']}/download";
                  ?>
                  <?php if (str_starts_with($mime, 'image/')): ?>
                    <a href="<?= $url ?>" target="_blank"><img class="chat-attachment-image" src="<?= $url ?>" alt="<?= htmlspecialchars($att['original_filename']) ?>"></a>
                  <?php elseif (str_starts_with($mime, 'video/')): ?>
                    <video class="chat-attachment-video" controls src="<?= $url ?>"></video>
                  <?php else: ?>
                    <a class="chat-attachment-file" href="<?= $url ?>"><?= htmlspecialchars($att['original_filename']) ?></a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($canManageOrders): // QA Round 5, R5-20 ?>
    <form class="chat-post-form" method="post" action="/orders/<?= (int) $order['id'] ?>/comments#order-updates" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <textarea name="body" data-required-unless="attachments[]" data-required-unless-message="Write a message or attach a file before posting." placeholder="Write an update for the client…"></textarea>
      <div class="chat-post-form-row">
        <input type="file" name="attachments[]" multiple accept="image/*,video/*,.pdf">
        <button type="submit" class="btn-sm" data-loading-text="Posting…"><?= $mailSendingEnabled ? 'Post &amp; email client' : 'Post to client portal' ?></button>
      </div>
    </form>
    <?php endif; ?>
  </details>

  <details class="phase-group" id="phase-booking"<?= $phaseOpen('booking') ?>>
    <?= $phaseSummary('booking') ?>

  <details class="step-card" id="step-1-quotation-prepare"<?= $stepCardOpen('1') ?>>
    <summary><?= $stepStatusIcon($stepStates['1']) ?><span class="step-group-num">STEP 1</span><h2>Prepare Quotation</h2></summary>
    <?php
      // Batch 3 #5 — differentiate 'not yet generated' vs 'already generated, this regenerates
      // it' document buttons visually, instead of every visible button looking identical.
      $__genTypes = array_column($documents, 'document_type_code');
      $__docBtnClass = static fn (string $code): string => 'btn-sm ' . (in_array($code, $__genTypes, true) ? 'btn-doc-regenerate' : 'btn-doc-new');
    ?>
    <?php
      // QA Round 5, R5-03 (owner decision 2026-10-28, Option A): terms on a
      // "Requires MD approval" preset are approved before the Quotation.
      $__termsPending = $paymentTermsApproval['required'] && !$paymentTermsApproval['approved'];
      $__pct = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    ?>
    <?php if ($paymentTermsApproval['required']): ?>
      <div id="payment-terms-approval">
      <?php if ($__termsPending): ?>
        <p class="review-banner warn">&#9888; <span><strong>Payment terms need MD/ED approval first</strong> — this order uses &ldquo;<?= htmlspecialchars((string) $paymentTermsApproval['preset_name']) ?>&rdquo; (advance <?= $__pct($order['advance_pct']) ?>% &middot; balance <?= $__pct($order['balance_pct']) ?>%). The Quotation can't be generated or sent until they are approved.<?= $canApprovePaymentTerms ? '' : ' Waiting for MD/ED approval.' ?></span></p>
        <?php if ($canApprovePaymentTerms): ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment-terms/approve" style="margin-bottom:10px;">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-success">Approve payment terms</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="review-banner ok">&#10003; <span><strong>Payment terms approved</strong> (&ldquo;<?= htmlspecialchars((string) $paymentTermsApproval['preset_name']) ?>&rdquo;)<?= $paymentTermsApproval['approved_by_name'] ? ' by ' . htmlspecialchars($paymentTermsApproval['approved_by_name']) . ' on ' . htmlspecialchars(date('d M Y', strtotime((string) $paymentTermsApproval['approved_at']))) : ' — quoted before approval was required' ?>.</span></p>
      <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="btn-row">
      <?php if ($stage1 && $stage1['status'] !== 'locked' && !$__termsPending): ?>
        <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="QT">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('1a') ?><button type="submit" class="<?= $__docBtnClass('QT') ?>" data-loading-text="Generating…"><?= $hasQt ? 'Regenerate Quotation (new revision)' : 'Generate Quotation' ?></button><?= $__docAlreadyNote('QT') ?>
        </form>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($order['include_annexure_a']): ?>
        <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="ANNEXA">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="<?= $__docBtnClass('ANNEXA') ?>" data-loading-text="Generating…">Generate Annexure A</button><?= $__docAlreadyNote('ANNEXA') ?>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if ($canManageOrders): ?>
    <p class="muted small"><a href="/orders/<?= (int) $order['id'] ?>/annexure"><?= $order['include_annexure_a'] ? 'Manage Annexure A product entries &amp; images' : 'Enable / manage Annexure A' ?></a></p>
    <?php endif; ?>
    <p class="muted small">Review, approval, and sending to the buyer happen in <a href="#document-review-approval">Document Review &amp; Approval</a> below, once generated.</p>
  </details>

  <details class="step-card step-card-reference" id="step-2-quotation-send"<?= $stepCardOpen('2') ?>>
    <summary><?= $stepStatusIcon($stepStates['2']) ?><span class="step-group-num">STEP 2</span><h2>Send Quotation to Client</h2></summary>
    <p class="muted small">Sending happens through the shared <a href="#document-review-approval">Document Review &amp; Approval</a> workflow below &mdash; every generated document (not just the Quotation) is assigned a reviewer, approved, and dispatched from that one place, regardless of which step generated it. <?= $mailSendingEnabled ? 'Once the Quotation is approved there, use its own "Send to Buyer" action.' : 'Email sending is turned off, so the Quotation counts as sent as soon as it is approved there &mdash; send the PDF to the client yourself.' ?></p>
  </details>

  <details class="step-card step-card-reference" id="step-3-quotation-accept"<?= $stepCardOpen('3') ?>>
    <summary><?= $stepStatusIcon($stepStates['3']) ?><span class="step-group-num">STEP 3</span><h2>Client Accepts Quotation</h2></summary>
    <p class="muted small">There's no separate "accepted" button to click &mdash; the buyer's acceptance happens outside the system (a reply email, a call, a signed copy). Once your team is satisfied the client has accepted, move on to <a href="#step-4a-buyer-po-generate">Step 4a &middot; Buyer's PO</a>.</p>
  </details>


<?php
// Owner request (2026-10-26): the Buyer Action Link is sent at Step 4b (the
// buyer downloads the Buyer PO, uploads it signed with their PO number and
// Step 4 completes) and again at 6b for the OC acknowledgement — one link,
// rendered by this one widget in both places.
$renderBuyerActionLink = function (string $badge, string $anchor) use ($order, $canManageOrders, $stepBadge): void {
    // QA-5 L-24: buyer_action_token_plain is encrypted at rest
    // (EncryptionService) — decrypt it here, the one place it's ever
    // rendered back to staff.
    $buyerActionTokenPlain = \App\Services\EncryptionService::decrypt($order['buyer_action_token_plain'] ?? null);
    $buyerActionLinkValid = !empty($buyerActionTokenPlain) && !empty($order['buyer_action_token_expires_at']) && strtotime((string) $order['buyer_action_token_expires_at']) > time();
    ?>
    <?php if ($buyerActionLinkValid): ?>
      <?php $buyerActionFullLink = rtrim(Env::get('APP_URL', ''), '/') . '/buyer-action/' . $buyerActionTokenPlain; ?>
      <div class="review-banner info" style="flex-direction:column;align-items:stretch;gap:6px">
        <span>Current link (valid until <?= htmlspecialchars(Dates::human($order['buyer_action_token_expires_at'])) ?>) — copy and send however you like, no need to regenerate:</span>
        <div style="display:flex;gap:6px;align-items:center">
          <input type="text" readonly value="<?= htmlspecialchars($buyerActionFullLink) ?>" onclick="this.select()" style="flex:1;font-family:monospace;font-size:12px" aria-label="Buyer Action Link">
          <button type="button" class="btn-sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($buyerActionFullLink, ENT_QUOTES) ?>'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy Link', 1500);">Copy Link</button>
        </div>
      </div>
    <?php else: ?>
      <p class="muted small">No active link — generate one below.</p>
    <?php endif; ?>
    <?php if ($canManageOrders): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-action-link" style="display:inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($anchor) ?>">
      <?= $stepBadge($badge) ?><button type="submit" class="btn-sm"><?= $buyerActionLinkValid ? 'Regenerate Buyer Action Link' : 'Generate Buyer Action Link' ?></button>
    </form>
    <?php endif; ?>
    <?php
};
// Owner request (2026-10-27): the Payment Action Link — optional, never
// locks a step. The client sends payment details there (screenshot,
// transaction ID, amount, date); staff still verify and record below.
$renderPaymentActionLink = function (string $leg, string $anchor) use ($order, $canManageOrders): void {
    $paymentTokenPlain = \App\Services\EncryptionService::decrypt($order['payment_action_token_plain'] ?? null);
    $paymentLinkValid = !empty($paymentTokenPlain) && !empty($order['payment_action_token_expires_at']) && strtotime((string) $order['payment_action_token_expires_at']) > time();
    $document = \App\Services\PaymentActionService::LEGS[$leg]['document'];
    ?>
    <div class="pay-link-box">
      <p class="small"><strong>Payment Action Link</strong> <span class="muted">(optional)</span> — the client uploads the payment screenshot and transaction details there, no login needed. It also goes out automatically inside the <?= htmlspecialchars($document) ?> email.</p>
      <?php if ($paymentLinkValid): ?>
        <?php $paymentFullLink = rtrim(Env::get('APP_URL', ''), '/') . '/payment-action/' . $paymentTokenPlain; ?>
        <div style="display:flex;gap:6px;align-items:center">
          <input type="text" readonly value="<?= htmlspecialchars($paymentFullLink) ?>" onclick="this.select()" style="flex:1;font-family:monospace;font-size:12px" aria-label="Payment Action Link">
          <button type="button" class="btn-sm btn-secondary" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($paymentFullLink, ENT_QUOTES) ?>'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy Link', 1500);">Copy Link</button>
        </div>
      <?php endif; ?>
      <?php if ($canManageOrders): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment-action-link" style="display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($anchor) ?>">
        <button type="submit" class="btn-sm btn-secondary"><?= $paymentLinkValid ? 'Regenerate &amp; Email Payment Link' : 'Generate &amp; Email Payment Link' ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php
};
?>
  <details class="step-group" id="step-group-stage-2"<?= $stepCardOpen('4') ?>>
    <summary><?= $stepStatusIcon($stepStates['4']) ?><span class="step-group-num">STEP 4</span><h3>Buyer's PO</h3></summary>

    <details class="step-card" id="step-4a-buyer-po-generate"<?= $subCardOpen('4', '4a') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('4', '4a')) ?><h2>Step 4a &middot; Generate Buyer's PO</h2></summary>
      <?php if ($stage2 && $stage2['status'] !== 'locked'): ?>
        <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="BUYERPO">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('4a') ?><button type="submit" class="<?= $__docBtnClass('BUYERPO') ?>" data-loading-text="Generating…">Generate Buyer PO</button><?= $__docAlreadyNote('BUYERPO') ?>
        </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">Generate the Quotation first to unlock this gate.</p>
      <?php endif; ?>
    </details>

    <div class="step-group-connector">send the buyer their PO to sign</div>

    <details class="step-card" id="step-4b-buyer-po-link"<?= $subCardOpen('4', '4b') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('4', '4b')) ?><h2>Step 4b &middot; Send Buyer PO Action Link <small class="muted">(optional)</small></h2></summary>
      <?php if ($stage2 && $stage2['status'] !== 'locked'): ?>
        <p class="muted small">A no-login link for the buyer: they <strong>download the Buyer PO</strong> generated in 4a, sign it, and <strong>upload the signed copy with their PO number</strong> — Step 4 then completes by itself and you're notified. Emailed to the client when they have an email on file. If the buyer can't, attach and confirm it yourself in 4c/4d as before.</p>
        <?php if (!$docComplete('BUYERPO')): ?>
          <p class="review-banner warn">&#9888; The Buyer PO (4a) isn't approved<?= $mailSendingOn ? '/sent' : '' ?> yet — the buyer can't download it until it is.</p>
        <?php endif; ?>
        <?php $renderBuyerActionLink('4b', 'step-4b-buyer-po-link'); ?>
      <?php else: ?>
        <p class="muted">Generate the Quotation first to unlock this gate.</p>
      <?php endif; ?>
    </details>

    <div class="step-group-connector">once the buyer sends their signed PO back</div>

    <?php
      // Owner decision (2026-10-25): attach the buyer's signed PO copy
      // BEFORE confirming receipt — the confirm gate already refuses
      // without one (WetSignatureGuardService::buyerPoBlocked(), on by
      // default), so the old order (confirm first, attach after) walked
      // staff straight into that error.
    ?>
    <details class="step-card" id="step-4c-buyer-po-attach"<?= $subCardOpen('4', '4c') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('4', '4c')) ?><h2>Step 4c &middot; Attach Buyer's PO Copy<?= $buyerPoCopyRequired ? '' : ' <small class="muted">(optional)</small>' ?></h2></summary>
      <?php if ($stage2 && $stage2['status'] !== 'locked'): ?>
        <p class="muted small">Upload the copy the buyer emailed you — or the buyer can upload it themselves through the <a href="#step-4b-buyer-po-link">Buyer Action Link (4b)</a> or their client portal; it then appears here.</p>
        <?php if (!empty($buyerPoDocuments)): ?>
          <table class="list" style="margin-top:8px">
            <tr><th>File</th><th>Uploaded</th><th>By</th><th></th></tr>
            <?php foreach ($buyerPoDocuments as $bd): ?>
              <tr>
                <td><?= htmlspecialchars($bd['original_filename']) ?></td>
                <td><?= htmlspecialchars($bd['uploaded_at']) ?></td>
                <td><?= $bd['uploaded_by'] === null ? 'The buyer (self-uploaded)' : 'Staff' ?></td>
                <td><a href="/file-store/<?= (int) $bd['file_id'] ?>/download">Download</a></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
        <?php if ($canManageOrders): // QA Round 5, R5-20 ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-po/documents" enctype="multipart/form-data" style="margin-top:6px">
          <?= Csrf::field() ?>
          <input type="file" name="document" required>
          <?= $stepBadge('4c') ?><button type="submit" class="btn-sm"><?= empty($buyerPoDocuments) ? 'Attach Buyer PO Copy' : 'Attach Another Copy' ?></button>
        </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">Generate the Quotation first to unlock this gate.</p>
      <?php endif; ?>
    </details>

    <div class="step-group-connector">then confirm it to complete Step 4</div>

    <details class="step-card" id="step-4d-buyer-po-confirm"<?= $subCardOpen('4', '4d') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('4', '4d')) ?><h2>Step 4d &middot; Confirm Buyer's PO Received</h2></summary>
      <?php if ($hasBuyerPo): ?>
        <p>Buyer PO recorded: <strong><?= htmlspecialchars($order['buyers_po_ref']) ?></strong><?php if (!empty($order['buyer_po_received_on'])): ?> &middot; received on <?= htmlspecialchars(\App\Helpers\Dates::short((string) $order['buyer_po_received_on'])) ?><?php endif; ?></p>
        <?php if ($canManageOrders && $order['buyers_po_ref'] !== 'NIL'): ?>
          <details class="rate-correction" id="buyer-po-date-correct" style="margin-top:6px;">
            <summary class="muted small"><?= empty($order['buyer_po_received_on']) ? 'Record the date the PO was received' : 'Correct the date received' ?></summary>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-po/received-date" class="field-grid">
              <?= Csrf::field() ?>
              <label>Date the buyer's PO was received *<input type="date" name="buyer_po_received_on" required min="<?= htmlspecialchars(substr((string) $order['created_at'], 0, 10)) ?>" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars((string) ($order['buyer_po_received_on'] ?? '')) ?>"></label>
              <label>Reason for the change *<input type="text" name="reason" required minlength="10" maxlength="500"></label>
              <button type="submit" class="btn-sm">Save date</button>
            </form>
          </details>
        <?php endif; ?>
      <?php elseif (!$stage2 || $stage2['status'] === 'locked'): ?>
        <p class="muted">Generate the Quotation first to unlock this gate.</p>
      <?php elseif ($buyerPoCopyRequired && empty($buyerPoDocuments)): ?>
        <p class="muted">Attach the buyer's signed PO copy in Step 4c first &mdash; this step completes Step 4 and needs it on file.</p>
      <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-po">
          <?= Csrf::field() ?>
          <label>Buyer's PO / Reference Number *<input type="text" name="buyers_po_ref" required></label>
          <label>Date the buyer's PO was received *<input type="date" name="buyer_po_received_on" required min="<?= htmlspecialchars(substr((string) $order['created_at'], 0, 10)) ?>" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
          <p class="muted small">The day it arrived by email or post, not the date printed on it. For an associate's client, this is Day 1 of the agreement's 10 days.</p>
          <?= $stageGateNotice(2, "confirm the buyer's PO received") ?>
          <?= $stepBadge('4d') ?><button type="submit"<?= $stageGateDisabled(2) ?> class="btn-sm">Confirm Buyer PO Received</button>
        </form>
      <?php endif; ?>
    </details>
  </details>

  <details class="step-group" id="step-group-stage-3"<?= $stepCardOpen('5') ?>>
    <summary><?= $stepStatusIcon($stepStates['5']) ?><span class="step-group-num">STEP 5</span><h3>Proforma Invoice &amp; Payment</h3></summary>

    <details class="step-card" id="step-5a-pi-form-link"<?= $subCardOpen('5', '5a') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('5', '5a')) ?><h2>Step 5a &middot; Generate PI Form Link</h2></summary>
      <p class="muted small">A separate form from the Quotation-stage request — sent to the client to confirm consignee/notify-party/payment-terms details before the PI is issued.</p>
      <?php if ($piIntake): ?>
        <p>
          Status: <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $piIntake['status']))) ?></strong>
          <?php if ($piIntake['status'] === 'pending_review'): ?>
            <?php if ($canManageOrders): ?>&nbsp;·&nbsp; <a href="/pi-intake-review">Review in PI Intake Review</a><?php endif; ?>
          <?php elseif ($piIntake['status'] === 'applied'): ?>
            &nbsp;·&nbsp; Applied by <?= htmlspecialchars($piIntake['reviewed_by_name'] ?? '—') ?> on <?= htmlspecialchars((string) $piIntake['reviewed_at']) ?>
          <?php endif; ?>
        </p>
        <?php if (in_array($piIntake['status'], ['awaiting_client', 'rejected'], true) && strtotime((string) $piIntake['access_token_expires_at']) > time()): ?>
          <?php $piFormFullLink = rtrim(Env::get('APP_URL', ''), '/') . '/pi-details/' . $piIntake['access_token_plain']; ?>
          <div class="review-banner info" style="flex-direction:column;align-items:stretch;gap:6px">
            <span>Current link (valid until <?= htmlspecialchars(Dates::human($piIntake['access_token_expires_at'])) ?>) — copy and send however you like, no need to regenerate:</span>
            <div style="display:flex;gap:6px;align-items:center">
              <input type="text" readonly value="<?= htmlspecialchars($piFormFullLink) ?>" onclick="this.select()" style="flex:1;font-family:monospace;font-size:12px">
              <button type="button" class="btn-sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($piFormFullLink, ENT_QUOTES) ?>'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy Link', 1500);">Copy Link</button>
            </div>
          </div>
        <?php elseif ($piIntake['status'] === 'awaiting_client'): ?>
          <p class="muted small">This link has expired — use Regenerate below to issue a fresh one.</p>
        <?php endif; ?>
        <?php if ($piIntake['status'] === 'applied'): ?>
          <div class="kv-grid">
            <div><span class="k">Confirmed Incoterm</span><span class="v"><?= htmlspecialchars($piIntake['incoterm_confirmed'] ?? '—') ?></span></div>
            <div><span class="k">Confirmed Port of Discharge</span><span class="v"><?= htmlspecialchars($piIntake['port_of_discharge_text'] ?? '—') ?></span></div>
            <div><span class="k">Confirmed COO Type</span><span class="v"><?= htmlspecialchars($piIntake['coo_type'] ?? '—') ?></span></div>
            <div><span class="k">Payment Terms Confirmation</span><span class="v"><?= htmlspecialchars($piIntake['payment_terms_confirmation'] ?? '—') ?></span></div>
            <div><span class="k">Quotation Acceptance Reference</span><span class="v"><?= htmlspecialchars($piIntake['quotation_acceptance_reference'] ?? '—') ?></span></div>
            <?php if ($piIntake['changes_from_quotation']): ?><div><span class="k">Changes from Quotation</span><span class="v"><?= htmlspecialchars($piIntake['changes_from_quotation']) ?></span></div><?php endif; ?>
            <?php if ($piIntake['special_document_requirements']): ?><div><span class="k">Special Document Requirements</span><span class="v"><?= htmlspecialchars($piIntake['special_document_requirements']) ?></span></div><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">No PI-stage form has been sent to this client yet.</p>
      <?php endif; ?>
      <?php if ($canManageOrders): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/pi-form-link" style="display:inline">
        <?= Csrf::field() ?>
        <?= $stepBadge('5a') ?><button type="submit" class="btn-sm"><?= $piIntake ? 'Regenerate PI Form Link' : 'Generate PI Form Link' ?></button>
      </form>
      <?php endif; ?>
      <?php $renderPiFormSkip(); ?>
    </details>

    <div class="step-group-connector">client replies</div>

    <details class="step-card step-card-reference" id="step-5b-pi-intake-apply"<?= $subCardOpen('5', '5b') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('5', '5b')) ?><h2>Step 5b &middot; Apply the Client's PI-Stage Confirmation</h2></summary>
      <p class="muted small">Once the client submits the PI-stage form, review and apply it at <?= $canManageOrders ? '<a href="/pi-intake-review">PI Intake Review</a>' : 'PI Intake Review' ?> — applying it overwrites the live client record with exactly what the buyer confirmed.</p>
      <?php $renderPiFormSkip(); ?>
    </details>

    <div class="step-group-connector">now the real PI can be issued</div>

    <details class="step-card" id="step-5c-pi-generate"<?= $subCardOpen('5', '5c') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('5', '5c')) ?><h2>Step 5c &middot; Generate Proforma Invoice</h2></summary>
      <?php if ($stage3 && $stage3['status'] !== 'locked'): ?>
        <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="PI">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('5c') ?><button type="submit" class="<?= $__docBtnClass('PI') ?>" data-loading-text="Generating…">Generate Proforma Invoice</button><?= $__docAlreadyNote('PI') ?>
        </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">Record the Buyer PO first to unlock this gate.</p>
      <?php endif; ?>
    </details>

    <div class="step-group-connector">payment method for this order: <strong><?= $isLcPayment ? 'Letter of Credit' : 'Cash Advance' ?></strong></div>

    <?php if ($isLcPayment): ?>
    <details class="step-card" id="step-5d-payment-record"<?= $subCardOpen('5', '5d') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('5', '5d')) ?><h2>Step 5d &middot; Record LC Details</h2></summary>
      <?php if ($stage3 && $stage3['status'] !== 'locked' && !$lcConfirmed && ($orderLcDetails === null || $orderLcDetails['lc_amount'] === null)): ?>
        <?php if (!$canManagePayments): ?>
          <p class="muted">Only Accounts/Export can record this payment.</p>
        <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/lc" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <label>Issuing Bank<input type="text" name="lc_bank_name"></label>
          <label>LC Number<input type="text" name="lc_number"></label>
          <label>LC Amount * <small class="muted">(in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="lc_amount" required></label>
          <label>LC Charges <small class="muted">(billed to the client, in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="lc_charges_amount"></label>
          <label>LC Document (optional)<input type="file" name="lc_file"></label>
          <?= $stepBadge('5d') ?><button type="submit" class="btn-sm">Record LC Details</button>
        </form>
        <?php endif; ?>
      <?php elseif (!$stage3 || $stage3['status'] === 'locked'): ?>
        <p class="muted">Record the Buyer PO first to unlock this gate.</p>
      <?php elseif ($orderLcDetails && $orderLcDetails['lc_amount'] !== null): ?>
        <p class="muted">Already recorded — see Step 5e to confirm it usable.</p>
      <?php endif; ?>
    </details>

    <div class="step-group-connector">once the advising bank confirms</div>

    <details class="step-card" id="step-5e-payment-confirm"<?= $subCardOpen('5', '5e') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('5', '5e')) ?><h2>Step 5e &middot; Confirm LC Usable</h2></summary>
      <?php if ($stage3 && $stage3['status'] !== 'locked' && !$lcConfirmed && $orderLcDetails && $orderLcDetails['lc_amount'] !== null): ?>
        <?php if (!$canClearPayments): ?>
          <p class="muted">LC details recorded — awaiting Accounts to confirm it usable.</p>
        <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/lc/confirm-usable" onsubmit="return confirm('Confirm this LC usable? This unlocks Stage 4 and auto-provisions the client portal login — confirm the advising bank has actually confirmed first.');">
          <?= Csrf::field() ?>
          <?= $stageGateNotice(3, "confirm the LC usable") ?>
          <?= $stepBadge('5e') ?><button type="submit"<?= $stageGateDisabled(3) ?> class="btn-sm btn-success">Confirm LC Usable (unlocks Stage 4)</button>
        </form>
        <?php endif; ?>
      <?php elseif (!$stage3 || $stage3['status'] === 'locked'): ?>
        <p class="muted">Record the Buyer PO first to unlock this gate.</p>
      <?php elseif ($lcConfirmed): ?>
        <p class="muted">LC already confirmed usable.</p>
      <?php else: ?>
        <p class="muted">Record the LC details first (Step 5d).</p>
      <?php endif; ?>
    </details>
    <?php else: ?>
    <details class="step-card" id="step-5d-payment-record"<?= $subCardOpen('5', '5d') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('5', '5d')) ?><h2>Step 5d &middot; Record Advance Remittance</h2></summary>
      <?php if (!$advanceCleared) { $renderPaymentActionLink('advance', 'step-5d-payment-record'); } ?>
      <?php if ($stage3 && $stage3['status'] !== 'locked' && !$advanceCleared && (!$payment || $payment['advance_remittance_received_at'] === null)): ?>
        <?php if (!$canManagePayments): ?>
          <p class="muted">Only Accounts/Export can record this payment.</p>
        <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/advance">
          <?= Csrf::field() ?>
          <?php $advanceDue = round((float) $fobTotal * (float) ($order['advance_pct'] ?? 0) / 100, 2); ?>
          <p class="advance-due-note">Advance due on this order: <strong><?= htmlspecialchars($order['currency_code'] ?? '') ?> <?= number_format($advanceDue, 2) ?></strong> (<?= htmlspecialchars(rtrim(rtrim(number_format((float) ($order['advance_pct'] ?? 0), 2), '0'), '.')) ?>% of the order value <?= htmlspecialchars($order['currency_code'] ?? '') ?> <?= number_format((float) $fobTotal, 2) ?>). Enter what the bank actually received.</p>
          <label>Advance Amount Received * <small class="muted">(in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="advance_amount" required data-remit-amount value="<?= $advanceDue > 0 ? number_format($advanceDue, 2, '.', '') : '' ?>"></label>
          <label class="checkbox-row small" style="font-weight:normal;"><input type="checkbox" name="confirm_amount_differs" value="1"> The amount received differs from the advance due (e.g. bank charges deducted) — record it anyway</label>
          <label>Received On<input type="date" name="advance_received_at" value="<?= date('Y-m-d') ?>"></label>
          <label>@ Exchange Rate * <small class="muted">(&#8377; per 1 <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="number" step="0.0001" min="0.0001" name="advance_exchange_rate" data-remit-rate required placeholder="e.g. 83.2500"></label>
          <p class="muted small remit-statement" data-remit-statement data-currency="<?= htmlspecialchars($order['currency_code'] ?? '') ?>"></p>
          <?= $stepBadge('5d') ?><button type="submit" class="btn-sm">Record Advance Remittance</button>
        </form>
        <?php endif; ?>
      <?php elseif (!$stage3 || $stage3['status'] === 'locked'): ?>
        <p class="muted">Record the Buyer PO first to unlock this gate.</p>
      <?php elseif ($payment && $payment['advance_remittance_received_at'] !== null): ?>
        <?= $remittanceRecordedNote('advance', 'Next: Step 5e — mark it cleared once the bank confirms.') ?>
      <?php endif; ?>

      <?php $renderInrActualWidget('advance', 'Advance Payment'); ?>
      <?php $renderExchangeRateWidget('advance', 'Advance Payment'); ?>
    </details>

    <div class="step-group-connector">once the bank confirms the funds landed</div>

    <details class="step-card" id="step-5e-payment-confirm"<?= $subCardOpen('5', '5e') ?>>
      <summary><?= $stepStatusIcon($subStepStatus('5', '5e')) ?><h2>Step 5e &middot; Mark Advance Cleared</h2></summary>
      <?php if ($stage3 && $stage3['status'] !== 'locked' && !$advanceCleared && $payment && $payment['advance_remittance_received_at'] !== null): ?>
        <?php if (!$canClearPayments): ?>
          <p class="muted">Advance remittance recorded — awaiting Accounts to confirm it cleared.</p>
        <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/advance/clear" onsubmit="return confirm('Mark the advance payment cleared? This unlocks Stage 4 and auto-provisions the client portal login — confirm the funds have actually landed first.');">
          <?= Csrf::field() ?>
          <label>Cleared On<input type="date" name="advance_cleared_at" value="<?= date('Y-m-d') ?>"></label>
          <?= $stageGateNotice(3, "mark the advance cleared") ?>
          <?= $stepBadge('5e') ?><button type="submit"<?= $stageGateDisabled(3) ?> class="btn-sm btn-success">Mark Advance Cleared (unlocks Stage 4)</button>
        </form>
        <?php endif; ?>
      <?php elseif (!$stage3 || $stage3['status'] === 'locked'): ?>
        <p class="muted">Record the Buyer PO first to unlock this gate.</p>
      <?php elseif ($advanceCleared): ?>
        <p class="muted">Advance already cleared.</p>
      <?php else: ?>
        <p class="muted">Record the advance remittance first (Step 5d).</p>
      <?php endif; ?>
    </details>
    <?php endif; ?>
  </details>

  <?php if ($isLcPayment): ?>
  <details class="step-card step-card-reference" id="step-5-lc-bank-loan">
    <summary><h2>Letter of Credit &amp; Bank Loan <small class="muted">(steps 5-1a/5-2a/5-3a &mdash; financing cost, not the LC leg above)</small></h2></summary>
    <p class="muted small">If the client financed their LC (Step 5d/5e above) with a bank loan, record that loan's amount/interest here — separate from the LC leg itself, since this is about the cost of financing it, not confirming it usable.</p>
    <?php if ($canManageOrderFinancials): ?>
    <p class="muted small">Recorded in full under <a href="#order-financials">Order Financials</a> (Step 12) alongside this order's other costs — the Bank Loan Interest you record there is automatically kept in sync as an order expense.</p>
    <?php else: ?>
    <p class="muted small">Recorded by Accounts/Export staff alongside this order's other costs.</p>
    <?php endif; ?>
  </details>
  <?php endif; ?>

  <details class="step-group" id="step-group-stage-4"<?= $stepCardOpen('6') ?>>
    <summary><?= $stepStatusIcon($stepStates['6']) ?><span class="step-group-num">STEP 6</span><h3>Order Confirmation</h3></summary>

  <details class="step-card" id="step-6a-oc-generate"<?= $subCardOpen('6', '6a') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('6', '6a')) ?><h2>Step 6a &middot; Generate Order Confirmation</h2></summary>
    <?php if ($canCloseOrders): ?>
    <p class="muted small">ECGC Cover is tracked on the <a href="#compliance-checklist">Compliance Checklist</a>.<?php if ($canManageOrderFinancials): ?> The premium paid is recorded under <a href="#order-financials">Order Financials</a> (Step 12) as an "ECGC insurance" cost entry — filed here, once the order is confirmed, so the fee is never paid before the order is certain to proceed.<?php endif; ?></p>
    <?php elseif ($canManageOrderFinancials): ?>
    <p class="muted small">The ECGC insurance premium paid is recorded under <a href="#order-financials">Order Financials</a> (Step 12) once the order is confirmed.</p>
    <?php endif; ?>
    <?php if ($stage4 && $stage4['status'] !== 'locked'): ?>
      <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="OC">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('6a') ?><button type="submit" class="<?= $__docBtnClass('OC') ?>" data-loading-text="Generating…">Generate Order Confirmation</button><?= $__docAlreadyNote('OC') ?>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Clear the advance payment first to unlock this gate.</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">send the buyer their action link</div>

  <details class="step-card" id="step-6b-buyer-action-link"<?= $subCardOpen('6', '6b') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('6', '6b')) ?><h2>Step 6b &middot; Send Buyer Action Link (OC acknowledgement)</h2></summary>
    <p class="muted small">The same Buyer Action Link the buyer used for their PO (Step 4b) also lets them acknowledge this Order Confirmation — send it again, or regenerate it if it has expired.</p>
    <?php $renderBuyerActionLink('6b', 'step-6b-buyer-action-link'); ?>
  </details>

  <div class="step-group-connector">the buyer acknowledges (link or portal) — or staff record it</div>

  <details class="step-card" id="step-6c-oc-acknowledge"<?= $subCardOpen('6', '6c') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('6', '6c')) ?><h2>Step 6c &middot; Record Buyer's Acknowledgement</h2></summary>
    <?php if ($buyerAcknowledged): ?>
      <?php if ($ocAcknowledgment && $ocAcknowledgment['acknowledged_via'] === 'client_portal'): ?>
        <p>Buyer acknowledged the Order Confirmation themselves in the client portal on <?= htmlspecialchars(Dates::human($ocAcknowledgment['acknowledged_at'])) ?>.</p>
      <?php elseif ($ocAcknowledgment && $ocAcknowledgment['acknowledged_via'] === 'staff_recorded_email'): ?>
        <p>Acknowledgement recorded from the buyer's email reply on <?= htmlspecialchars(Dates::human($ocAcknowledgment['acknowledged_at'])) ?>.</p>
        <p class="muted small">Evidence/note: <?= nl2br(htmlspecialchars($ocAcknowledgment['acknowledged_note'] ?? '')) ?></p>
      <?php elseif ($ocAcknowledgment && $ocAcknowledgment['acknowledged_via'] === 'auto_48h'): ?>
        <p>No response from the buyer within 48 hours of the Order Confirmation being emailed — auto-confirmed on <?= htmlspecialchars(Dates::human($ocAcknowledgment['acknowledged_at'])) ?>.</p>
      <?php else: ?>
        <p>Buyer has acknowledged the Order Confirmation.</p>
      <?php endif; ?>
    <?php elseif ($stage4 && $stage4['status'] !== 'locked'): ?>
      <?php if ($ocAcknowledgment && $ocAcknowledgment['acknowledged_at'] === null): ?>
        <div class="review-banner info">
          ℹ <span>Order Confirmation emailed to the buyer on <?= htmlspecialchars(Dates::human($ocAcknowledgment['sent_at'])) ?>. Awaiting their acknowledgement — the client can confirm it directly in their portal, or reply to the email (record that below). If there's no response by <?= htmlspecialchars(Dates::human($ocAcknowledgment['due_at'])) ?>, it auto-confirms and Step 7 (Supplier PO) unlocks on its own.</span>
        </div>
        <?php if ($canManageOrders): // QA Round 5, R5-20 ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/oc-acknowledgment">
          <?= Csrf::field() ?>
          <label>Buyer replied by email? Record the evidence here (quote the reply) *<textarea name="acknowledged_note" rows="3" required></textarea></label>
          <?= $stageGateNotice(4, "record the buyer's acknowledgement") ?>
          <?= $stepBadge('6c') ?><button type="submit"<?= $stageGateDisabled(4) ?> class="btn-sm btn-success">Record Buyer's Email Acknowledgement (unlocks Step 7)</button>
        </form>
        <?php endif; ?>
      <?php elseif ($ocDocGenerated): ?>
        <div class="review-banner info">
          ℹ <span>Order Confirmation has been generated but hasn't gone out by email yet (still awaiting send/approval, or mail sending isn't configured). If the buyer has confirmed by phone, in person, or any channel other than the portal/email, record it below once the Order Confirmation is approved (and sent to the buyer, when email is on); Step 7 (Supplier PO) then unlocks.</span>
        </div>
        <?php if ($canManageOrders): // QA Round 5, R5-20 ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/oc-acknowledgment">
          <?= Csrf::field() ?>
          <label>Describe how the buyer confirmed (quote their reply, or note the call/meeting) *<textarea name="acknowledged_note" rows="3" required></textarea></label>
          <?= $stageGateNotice(4, "record the buyer's acknowledgement") ?>
          <?= $stepBadge('6c') ?><button type="submit"<?= $stageGateDisabled(4) ?> class="btn-sm btn-success">Record Buyer's Acknowledgement (unlocks Step 7)</button>
        </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">Generate the Order Confirmation above and send it to the buyer — once sent, they can acknowledge it from their portal (or reply by email, which you can record here), and it auto-confirms after 48 hours with no response.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Clear the advance payment first to unlock this gate.</p>
    <?php endif; ?>
  </details>

  <?php $supplierPoLocked = !$stage5 || $stage5['status'] === 'locked'; ?>

  </details>

  </details>

  <details class="phase-group" id="phase-production"<?= $phaseOpen('production') ?>>
    <?= $phaseSummary('production') ?>

  <details class="step-group" id="step-group-stage-5"<?= $stepCardOpen('7') ?>>
    <summary><?= $stepStatusIcon($stepStates['7']) ?><span class="step-group-num">STEP 7</span><h3>Supplier PO</h3></summary>

  <?php $renderComplianceCard('order', '7'); ?>

  <details class="step-card" id="step-7a-supplier-po-terms"<?= $subCardOpen('7', '7a') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('7', '7a')) ?><h2>Step 7a &middot; Save Supplier PO Terms</h2></summary>
    <?php if ($supplierPoLocked): ?>
      <p class="muted">Confirm buyer acknowledgement first to unlock this gate.</p>
    <?php elseif ($supplierPo): ?>
      <div class="kv-grid">
        <div><span class="k">Supplier</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_legal_name']) ?></span></div>
        <div><span class="k">Supplier Type</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_type'] ?? '—') ?></span></div>
        <div><span class="k">Supplier PO Ref</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_po_reference']) ?></span></div>
        <div><span class="k">Material</span><span class="v"><?= htmlspecialchars($supplierPo['material_stone_type'] ?? '—') ?></span></div>
        <div><span class="k">Total Payable (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['total_payable_inr'] ?? '—') ?></span></div>
        <div><span class="k">Status</span><span class="v"><?= htmlspecialchars($supplierPo['status']) ?></span></div>
      </div>
      <p class="muted small">Terms already saved — see Step 7b to generate the document.</p>
    <?php else: ?>
      <p class="muted small">
        Suppliers come from the Suppliers directory<?php if ($canManageSuppliers): ?> — <a href="/suppliers" target="_blank" rel="noopener">add or manage suppliers</a> (opens in a new tab; reload this page afterwards)<?php else: ?> — ask an Admin/MD/ED to add a new supplier there<?php endif; ?>.
        The supplier's details (and factory addresses) are copied onto this order when you save the terms, so later edits in the directory never change this order. Factory addresses are added or removed only on the supplier's own page in the directory.
      </p>
      <?php if (empty($suppliers)): ?>
        <p class="small" style="color:var(--danger);font-weight:600;">No active suppliers yet. <?= $canManageSuppliers ? '<a href="/suppliers/create" target="_blank" rel="noopener">Add the first supplier</a>, then reload this page.' : 'Ask an Admin/MD/ED to add one in the Suppliers directory.' ?></p>
      <?php endif; ?>

      <?php if ($canManageOrders): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po" id="supplier-po-form">
        <?= Csrf::field() ?>
        <div class="field-grid">
        <label>Supplier *
          <select name="supplier_id" id="spo-supplier-select" required>
            <option value="">Select supplier…</option>
            <?php foreach ($suppliers as $sup): ?>
              <?php $supAddressesForOption = $supplierFactoryAddressesBySupplier[(int) $sup['id']] ?? []; ?>
              <option value="<?= (int) $sup['id'] ?>" data-addresses="<?= htmlspecialchars(json_encode(array_map(static fn(array $a): string => $a['address'], $supAddressesForOption)) ?: '[]') ?>" data-types="<?= htmlspecialchars(json_encode($sup['types']) ?: '[]') ?>"><?= htmlspecialchars($sup['supplier_legal_name']) ?><?= $sup['types'] ? ' — ' . htmlspecialchars(implode(', ', $sup['types'])) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label id="spo-supplier-type-field" style="display:none">Supplier Type for this order *
          <select name="supplier_type" id="spo-supplier-type-select"></select>
          <small class="muted">This supplier has more than one type — pick the one that applies to this order. It prints on the Supplier PO.</small>
        </label>
        <label>Material / Stone Type<input type="text" name="material_stone_type" placeholder="e.g., Black Granite"></label>
        <label>Grade<input type="text" name="grade" value="Grade A"></label>
        <label>Surface Finish
          <select name="surface_finish">
            <option value="">—</option>
            <?php foreach ($surfaceFinishes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Dimensions<input type="text" name="dimensions" placeholder="e.g., 600 x 600 x 20 mm"></label>
        <label>Dimensional Tolerance
          <select name="dimensional_tolerance">
            <option value="">—</option>
            <?php foreach ($dimensionalTolerances as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Quantity<input type="text" name="quantity" data-spo-calc="quantity" placeholder="e.g., 500"></label>
        <label>Unit
          <select name="unit">
            <?php foreach ($units as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Colour Reference<input type="text" name="colour_reference" placeholder="e.g., Jet Black"></label>
        <label>Special Requirements/Instructions<input type="text" name="special_requirements" placeholder="e.g., Polished finish, edge chamfer"></label>
        <label>Unit Price (INR) <small class="muted">(auto-calculates Basic Value when multiplied by Quantity)</small><input type="text" name="unit_price_inr" data-spo-calc="unit_price_inr" placeholder="e.g., 450.00"></label>
        <label>Basic Value (INR) <small class="muted">(auto: Unit Price &times; Quantity)</small><input type="text" name="basic_value_inr" data-spo-calc="basic_value_inr" placeholder="e.g., 225000.00"></label>
        <label>GST Rate (%)
          <select name="gst_rate_pct" data-spo-calc="gst_rate_pct">
            <option value="">—</option>
            <?php foreach ($gstRates as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?>%</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>GST Amount (INR) <small class="muted">(auto: Basic Value &times; GST Rate)</small><input type="text" name="gst_amount_inr" data-spo-calc="gst_amount_inr"></label>
        <label>Total Payable (INR) <small class="muted">(auto: Basic Value + GST Amount)</small><input type="text" name="total_payable_inr" data-spo-calc="total_payable_inr"></label>
        <label>Advance (%)<input type="text" name="advance_pct" data-spo-calc="advance_pct" placeholder="e.g., 30"></label>
        <label>Advance Amount (INR) <small class="muted">(auto: Total Payable &times; Advance %)</small><input type="text" name="advance_amount_inr" data-spo-calc="advance_amount_inr"></label>
        <label>Balance Amount (INR) <small class="muted">(auto: Total Payable &minus; Advance Amount)</small><input type="text" name="balance_amount_inr" data-spo-calc="balance_amount_inr"></label>
        <label>Delivery Location
          <select id="spo-delivery-location-select">
            <option value="">— Select —</option>
            <option value="__other__">Other (not listed) — specify</option>
          </select>
          <input type="text" id="spo-delivery-location-input" name="delivery_location" placeholder="Select a supplier first, or specify" style="display:none">
        </label>
        <label>Required Delivery Date<input type="date" name="required_delivery_date"></label>
        <label>Packing Requirement<input type="text" name="packing_requirement" placeholder="e.g., Wooden crates, export packing"></label>
        </div>
        <?= $stepBadge('7a') ?><button type="submit" class="btn-sm">Save Supplier PO Terms</button>
      </form>
      <?php endif; ?>
      <script>
      (function () {
        var form = document.getElementById('supplier-po-form');
        if (!form) { return; }
        var f = function (name) { return form.querySelector('[data-spo-calc="' + name + '"]'); };
        var num = function (el) { var v = parseFloat(el && el.value); return isNaN(v) ? 0 : v; };
        function recalc() {
          var unitPrice = num(f('unit_price_inr'));
          var quantity = num(f('quantity'));
          var basicValue = unitPrice * quantity;
          f('basic_value_inr').value = basicValue ? basicValue.toFixed(2) : '';

          var gstRatePct = num(f('gst_rate_pct'));
          var gstAmount = basicValue * (gstRatePct / 100);
          f('gst_amount_inr').value = basicValue ? gstAmount.toFixed(2) : '';

          var totalPayable = basicValue + gstAmount;
          f('total_payable_inr').value = basicValue ? totalPayable.toFixed(2) : '';

          // Owner decision (2026-10-27): 0% advance is a real choice — advance
          // 0.00 and the whole amount as the balance — not a blank field.
          var advancePct = num(f('advance_pct'));
          var pctEntered = !!f('advance_pct') && f('advance_pct').value.trim() !== '';
          var advanceAmount = totalPayable * (advancePct / 100);
          f('advance_amount_inr').value = totalPayable && pctEntered ? advanceAmount.toFixed(2) : '';
          f('balance_amount_inr').value = totalPayable && pctEntered ? (totalPayable - advanceAmount).toFixed(2) : '';
        }
        ['quantity', 'unit_price_inr', 'gst_rate_pct', 'advance_pct'].forEach(function (name) {
          var el = f(name);
          if (el) {
            el.addEventListener('input', recalc);
            el.addEventListener('change', recalc);
          }
        });

        // User-reported batch item 12: Delivery Location offers the
        // selected supplier's own factory addresses, repopulated here
        // whenever the Supplier changes, plus an "Other" free-text
        // fallback — same dropdown-with-other-fallback pattern as the
        // Incoterm fields on the client-facing intake forms.
        var supplierSelect = document.getElementById('spo-supplier-select');

        // A supplier can hold several types: when the selected one has more
        // than one, staff pick which applies to this order (it prints on the
        // Supplier PO). With one type it's recorded automatically.
        var typeField = document.getElementById('spo-supplier-type-field');
        var typeSelect = document.getElementById('spo-supplier-type-select');
        if (supplierSelect && typeField && typeSelect) {
          function repopulateTypeOptions() {
            var opt = supplierSelect.options[supplierSelect.selectedIndex];
            var types = [];
            try { types = opt && opt.dataset.types ? JSON.parse(opt.dataset.types) : []; } catch (e) { types = []; }
            typeSelect.innerHTML = '';
            if (types.length > 1) {
              var blank = document.createElement('option');
              blank.value = '';
              blank.textContent = '— Select —';
              typeSelect.appendChild(blank);
            }
            types.forEach(function (t) {
              var o = document.createElement('option');
              o.value = t;
              o.textContent = t;
              typeSelect.appendChild(o);
            });
            var multiple = types.length > 1;
            typeField.style.display = multiple ? '' : 'none';
            typeSelect.required = multiple;
          }
          supplierSelect.addEventListener('change', repopulateTypeOptions);
          repopulateTypeOptions();
        }

        var deliverySelect = document.getElementById('spo-delivery-location-select');
        var deliveryInput = document.getElementById('spo-delivery-location-input');
        if (supplierSelect && deliverySelect && deliveryInput) {
          function applyDeliveryInput() {
            var isOther = deliverySelect.value === '__other__';
            deliveryInput.style.display = isOther ? '' : 'none';
            deliveryInput.readOnly = !isOther;
            if (!isOther) { deliveryInput.value = deliverySelect.value; }
          }
          function repopulateDeliveryOptions() {
            var opt = supplierSelect.options[supplierSelect.selectedIndex];
            var addresses = [];
            try { addresses = opt && opt.dataset.addresses ? JSON.parse(opt.dataset.addresses) : []; } catch (e) { addresses = []; }
            deliverySelect.innerHTML = '';
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = '— Select —';
            deliverySelect.appendChild(blank);
            addresses.forEach(function (addr) {
              var o = document.createElement('option');
              o.value = addr;
              o.textContent = addr;
              deliverySelect.appendChild(o);
            });
            var other = document.createElement('option');
            other.value = '__other__';
            other.textContent = 'Other (not listed) — specify';
            deliverySelect.appendChild(other);
            applyDeliveryInput();
          }
          supplierSelect.addEventListener('change', repopulateDeliveryOptions);
          deliverySelect.addEventListener('change', applyDeliveryInput);
          repopulateDeliveryOptions();
        }
      })();
      </script>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">generate the actual Supplier PO document</div>

  <details class="step-card" id="step-7b-supplier-po-generate"<?= $subCardOpen('7', '7b') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('7', '7b')) ?><h2>Step 7b &middot; Generate Supplier PO</h2></summary>
    <?php if ($supplierPoLocked): ?>
      <p class="muted">Confirm buyer acknowledgement first to unlock this gate.</p>
    <?php elseif ($supplierPo): ?>
      <div class="kv-grid">
        <div><span class="k">Supplier</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_legal_name']) ?></span></div>
        <div><span class="k">Supplier Type</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_type'] ?? '—') ?></span></div>
        <div><span class="k">Supplier PO Ref</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_po_reference']) ?></span></div>
        <div><span class="k">Material</span><span class="v"><?= htmlspecialchars($supplierPo['material_stone_type'] ?? '—') ?></span></div>
        <div><span class="k">Total Payable (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['total_payable_inr'] ?? '—') ?></span></div>
        <div><span class="k">Status</span><span class="v"><?= htmlspecialchars($supplierPo['status']) ?></span></div>
      </div>
      <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="SUPPO">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('7b') ?><button type="submit" class="<?= $__docBtnClass('SUPPO') ?>" data-loading-text="Generating…">Generate Supplier PO</button><?= $__docAlreadyNote('SUPPO') ?>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Save Supplier PO Terms first (Step 7a).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once the supplier signs and returns it</div>

  <?php
    // Owner decision (2026-10-25), same as Step 4: attach the supplier's
    // signed acknowledgment BEFORE confirming the signature — the confirm
    // gate already refuses without one (WetSignatureGuardService::
    // supplierPoBlocked(), on by default).
  ?>
  <details class="step-card" id="step-7c-supplier-po-attach"<?= $subCardOpen('7', '7c') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('7', '7c')) ?><h2>Step 7c &middot; Attach Supplier PO Acknowledgment<?= $supplierPoCopyRequired ? '' : ' <small class="muted">(optional)</small>' ?></h2></summary>
    <?php if ($supplierPoLocked): ?>
      <p class="muted">Confirm buyer acknowledgement first to unlock this gate.</p>
    <?php elseif ($supplierPo): ?>
      <?php if (!empty($supplierPoDocuments)): ?>
        <table class="list" style="margin-top:8px">
          <tr><th>File</th><th>Uploaded</th><th></th></tr>
          <?php foreach ($supplierPoDocuments as $sd): ?>
            <tr>
              <td><?= htmlspecialchars($sd['original_filename']) ?></td>
              <td><?= htmlspecialchars($sd['uploaded_at']) ?></td>
              <td><a href="/file-store/<?= (int) $sd['file_id'] ?>/download">Download</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <?php if ($canManageOrders): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/documents" enctype="multipart/form-data" style="margin-top:6px">
        <?= Csrf::field() ?>
        <input type="file" name="document" required>
        <?= $stepBadge('7c') ?><button type="submit" class="btn-sm">Attach Supplier PO Acknowledgment</button>
      </form>
      <?php endif; ?>
      <?php
        // Owner request (2026-10-26, t4): the supplier can upload it themselves
        // through a no-login link (like the Buyer Action Link), and when they
        // never acknowledge, staff record that with a reason.
        $supplierActionTokenPlain = \App\Services\EncryptionService::decrypt($supplierPo['supplier_action_token_plain'] ?? null);
        $supplierActionLinkValid = !empty($supplierActionTokenPlain) && !empty($supplierPo['supplier_action_token_expires_at']) && strtotime((string) $supplierPo['supplier_action_token_expires_at']) > time();
      ?>
      <div class="supplier-action-link" style="margin-top:10px;border-top:1px solid var(--line);padding-top:8px;">
        <p class="muted small"><strong>Or let the supplier upload it:</strong> a direct link (no login) where the supplier uploads their signed PO / acknowledgment — emailed to them when the PO has their email<?= !empty($supplierPo['supplier_email']) ? ' (' . htmlspecialchars((string) $supplierPo['supplier_email']) . ')' : '' ?>. You'll be notified when they upload.</p>
        <?php if ($supplierActionLinkValid): ?>
          <?php $supplierActionFullLink = rtrim(Env::get('APP_URL', ''), '/') . '/supplier-action/' . $supplierActionTokenPlain; ?>
          <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px">
            <input type="text" readonly value="<?= htmlspecialchars($supplierActionFullLink) ?>" onclick="this.select()" style="flex:1;font-family:monospace;font-size:12px" aria-label="Supplier Action Link">
            <button type="button" class="btn-sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($supplierActionFullLink, ENT_QUOTES) ?>'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy Link', 1500);">Copy Link</button>
          </div>
          <p class="muted small">Valid until <?= htmlspecialchars(Dates::human($supplierPo['supplier_action_token_expires_at'])) ?>.</p>
        <?php endif; ?>
        <?php if ($canManageOrders): // QA Round 5, R5-20 ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/supplier-link" style="display:inline">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm btn-secondary"><?= $supplierActionLinkValid ? 'Regenerate Supplier Link' : 'Generate Supplier Link' ?></button>
        </form>
        <?php endif; ?>
      </div>
      <?php if (!empty($supplierPo['ack_skipped_at'])): ?>
        <p class="small" style="margin-top:8px;">&#10003; Recorded that the supplier did not acknowledge — <?= htmlspecialchars((string) $supplierPo['ack_skip_reason']) ?> <span class="muted">(<?= htmlspecialchars((string) $supplierPo['ack_skipped_at']) ?>)</span></p>
      <?php elseif (empty($supplierPoDocuments) && $supplierPo['status'] !== 'signed'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/ack-skip" style="margin-top:8px;" data-confirm="Record that the supplier did not acknowledge this PO? Step 7d then opens without an acknowledgment on file.">
          <?= Csrf::field() ?>
          <label>Skip — supplier did not acknowledge <small class="muted">(reason required)</small>
            <input type="text" name="skip_reason" required minlength="<?= \App\Helpers\ReasonValidator::MIN_LENGTH ?>" placeholder="e.g. supplier confirmed by phone, will not sign">
          </label>
          <button type="submit" class="btn-sm">Skip Acknowledgment</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Save Supplier PO Terms first (Step 7a).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">then confirm it to complete Step 7</div>

  <details class="step-card" id="step-7d-supplier-po-signed"<?= $subCardOpen('7', '7d') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('7', '7d')) ?><h2>Step 7d &middot; Confirm Supplier Signed &amp; Returned PO</h2></summary>
    <?php if ($supplierPoLocked): ?>
      <p class="muted">Confirm buyer acknowledgement first to unlock this gate.</p>
    <?php elseif ($supplierPo && $supplierPo['status'] !== 'signed' && $suppoDocGenerated && $supplierPoCopyRequired && empty($supplierPoDocuments) && empty($supplierPo['ack_skipped_at'])): ?>
      <p class="muted">Attach the supplier's signed acknowledgment in Step 7c first (or send them the Supplier Link, or record that they did not acknowledge) &mdash; this step completes Step 7 and needs it on file.</p>
    <?php elseif ($supplierPo && $supplierPo['status'] !== 'signed' && $suppoDocGenerated): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/signed">
        <?= Csrf::field() ?>
        <?= $stageGateNotice(5, "confirm the supplier signed the PO") ?>
        <?= $stepBadge('7d') ?><button type="submit"<?= $stageGateDisabled(5) ?> class="btn-sm btn-success">Confirm Supplier Signed &amp; Returned PO (unlocks Stage 6)</button>
      </form>
    <?php elseif ($supplierPo && $supplierPo['status'] !== 'signed'): ?>
      <p class="muted small">Generate the Supplier PO document above before confirming the supplier's signature — they can't have signed and returned a document that was never sent.</p>
    <?php elseif ($supplierPo): ?>
      <p>Supplier has signed and returned the PO.</p>
    <?php else: ?>
      <p class="muted">Save Supplier PO Terms first (Step 7a).</p>
    <?php endif; ?>

  </details>

  <div class="step-group-connector">pay the supplier's advance</div>

  <details class="step-card" id="step-7e-supplier-advance-paid"<?= $subCardOpen('7', '7e') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('7', '7e')) ?><h2>Step 7e &middot; Supplier Advance Paid</h2></summary>
    <?php $renderSupplierPayment('advance'); ?>
  </details>

  </details>

  <details class="step-group" id="step-group-stage-production"<?= $stepCardOpen('8') ?>>
    <summary><?= $stepStatusIcon($stepStates['8']) ?><span class="step-group-num">STEP 8</span><h3>Production &amp; Estimated Shipment</h3></summary>

  <details class="step-card" id="step-production"<?= $stepCardOpen('8') ?>>
    <summary><?= $stepStatusIcon($stepStates['8']) ?><h2>Step 8a &middot; Production &amp; Estimated Shipment</h2></summary>
    <div class="kv-grid">
      <div><span class="k">Production Status</span><span class="v"><?= htmlspecialchars($order['production_status_text'] ?? 'Not yet commenced') ?></span></div>
      <div><span class="k">Est. Shipment</span><span class="v"><?= htmlspecialchars($order['est_shipment_date_text'] ?? 'To Be Confirmed') ?></span></div>
    </div>
    <p class="muted small">Every update here is logged<?php if ($canViewAuditLog): ?> — <a href="/orders/<?= (int) $order['id'] ?>/audit-log">view update history</a><?php endif; ?>. Changing the estimated shipment date notifies the client (order update + portal); the production status is for the team.</p>
    <?php if ($stage4 && $stage4['status'] !== 'locked' && (!$isLockedOrder || $canOverrideLockEdit)): ?>
      <?php if ($canManageOrders): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/production-status">
        <?= Csrf::field() ?>
        <label>Production Status<input type="text" name="production_status_text" placeholder="e.g. Cutting and finishing in progress — 30% complete"></label>
        <label>Estimated Shipment<input type="text" name="est_shipment_date_text" placeholder="e.g. Week of 15 October 2026"></label>
        <label>Estimated Shipment Date <small class="muted">(drives the 7-day pre-shipment inspection reminder below)</small><input type="date" name="est_shipment_date" value="<?= htmlspecialchars($order['est_shipment_date'] ?? '') ?>"></label>
        <?php if ($isLockedOrder): ?>
          <?php // QA Round 5, R5-01: a locked order's production status is a locked-order edit. ?>
          <p class="notice notice-warning">This order is LOCKED (closed or marked lost). Changing its production status is logged as a locked-order edit override — a reason is required.</p>
          <label>Reason *<textarea name="reason" rows="2" required minlength="10" placeholder="Why is this changing after the order was closed?"></textarea></label>
        <?php endif; ?>
        <button type="submit" class="btn-sm"><?= $isLockedOrder ? 'Override &amp; Update' : 'Update' ?></button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($pendingInspectionTask && $canManageOrderFinancials): ?>
      <h3 style="font-size:0.95em; margin-top:14px;">3rd-Party Pre-Shipment Inspection (step 8a)</h3>
      <p class="muted small">This order has a Pre-Shipment Inspection on its Compliance Checklist (not marked not-applicable) — staff get reminded starting 7 days before the estimated shipment date above.</p>
      <p class="muted small">Recorded under <a href="#order-financials">Order Financials</a> (Step 12) → Costs, alongside this order's other costs.</p>
    <?php endif; ?>
  </details>

  </details>

  </details>

  <details class="phase-group" id="phase-shipment"<?= $phaseOpen('shipment') ?>>
    <?= $phaseSummary('shipment') ?>

  <?php
    // Owner decisions (2026-10-27): the logistics partners' bills — CHA,
    // transport, freight forwarder — on every incoterm. Runs alongside
    // Steps 9-11 and never locks them; on CFR/CIF orders a bill can be paid
    // only once the buyer's Debit Note payment has cleared (9d).
    $__logisticsBills = array_values(array_filter($vendorBills, static fn (array $b): bool => $b['vendor_kind'] === 'logistics'));
    $__logisticsUnpaid = count(array_filter($__logisticsBills, static fn (array $b): bool => !$b['settled']));
    $__logisticsStatus = $__logisticsBills !== [] && $__logisticsUnpaid === 0 ? 'done' : (in_array($currentUserStep, ['9', '10', '11'], true) ? 'current' : 'locked');
  ?>
  <?php if ($canManageSupplierPayments || $canManageOrderFinancials): ?>
  <details class="step-card step-card-parallel" id="logistics-bills"<?= in_array($currentUserStep, ['9', '10', '11'], true) && ($__logisticsBills === [] || $__logisticsUnpaid > 0) ? ' open' : '' ?>>
    <summary><?= $stepStatusIcon($__logisticsStatus) ?><h2>Logistics Partner Bills &amp; Payments — CHA · Transport · Freight Forwarder<?php if ($__logisticsUnpaid > 0): ?> <small class="muted">(<?= $__logisticsUnpaid ?> unpaid)</small><?php endif; ?></h2></summary>
    <p class="muted small">One row per bill from the partner — a partner doing both CHA and transport can send one combined bill or separate bills. Runs alongside Steps 9–11 and never locks them.<?= $isFob ? '' : ' On this ' . htmlspecialchars((string) $order['incoterm_code']) . ' order an Advance bill can be paid any time; the Final bill once the buyer\'s Debit Note payment has cleared (Step 9d).' ?> Bills go into the order's financials (Step 12) automatically.</p>
    <?php $renderVendorBills($__logisticsBills, true, $canManageSupplierPayments, $logisticsPaymentBlocked, static fn (array $b): string => $vendorBillPayTo($logisticsBanks[(int) $b['logistics_partner_id']] ?? null, '/logistics-partners/' . (int) $b['logistics_partner_id'] . '/edit', $canManageLogisticsPartners)); ?>
    <?php if ($canManageSupplierPayments): ?>
    <?php $__activePartners = array_values(array_filter($logisticsPartners, static fn (array $lp): bool => !empty($lp['is_active']))); ?>
    <details class="vendor-bill-add"<?= $__logisticsBills === [] ? ' open' : '' ?>>
      <summary class="small"><strong>+ Add a bill</strong></summary>
      <?php if ($__activePartners === []): ?>
        <p class="muted small">No logistics partners in the directory yet — <a href="/logistics-partners/create" target="_blank" rel="noopener">add one</a>.</p>
      <?php else: ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/vendor-bills/logistics" enctype="multipart/form-data" class="field-grid">
        <?= Csrf::field() ?>
        <label>Logistics partner *
          <select name="logistics_partner_id" required>
            <option value="">Select…</option>
            <?php foreach ($__activePartners as $lp): ?>
              <option value="<?= (int) $lp['id'] ?>"><?= htmlspecialchars((string) $lp['partner_name']) ?> (<?= htmlspecialchars(\App\Repositories\LogisticsPartnerRepository::SERVICE_TYPES[$lp['service_type']] ?? (string) $lp['service_type']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>For *
          <select name="bill_for" required>
            <?php foreach (\App\Repositories\OrderVendorBillRepository::LOGISTICS_SERVICES as $__key => $__label): ?><option value="<?= htmlspecialchars($__key) ?>"><?= htmlspecialchars($__label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <?php // Owner decision (2026-10-27): an Advance can be paid any time; the Final bill waits for Step 9d on CFR/CIF. ?>
        <label>Payment *
          <select name="payment_kind" required>
            <option value="advance">Advance — paid before the service</option>
            <option value="final" selected>Final — against the completed service</option>
          </select>
          <small class="muted"><?= $isFob ? 'Advance and Final bills can both be paid any time on an FOB order.' : 'An Advance bill can be paid any time; a Final bill waits for Step 9d.' ?></small>
        </label>
        <?php // Owner decision 2026-10-29 (CA advice): a road transporter (GTA) that charges no GST is booked under reverse charge. ?>
        <label data-show-when="bill_for=transport">GST on this bill *
          <select name="gst_charge" required>
            <option value="forward" selected>Charged on the bill</option>
            <option value="reverse">Reverse charge — no GST on the bill</option>
          </select>
          <small class="muted">Reverse charge: the bill shows no GST; we pay 5% to the government and claim it back. Pick GST rate 0% below.</small>
        </label>
        <?php $vendorBillFields('invoice', array_values(array_filter($__logisticsBills, static fn (array $b): bool => $b['doc_kind'] === 'proforma' && $b['replaced_by'] === null))); ?>
        <div><button type="submit" class="btn-sm">Add Bill</button></div>
      </form>
      <?php endif; ?>
    </details>
    <?php endif; ?>
  </details>
  <?php endif; ?>

  <?php if ($isFob): ?>
  <?php // FOB: the buyer arranges and pays freight, so this whole step never applies — shown greyed out, with nothing to expand. ?>
  <div class="step-group step-group-disabled" id="step-group-stage-6" aria-disabled="true" title="Not applicable — FOB order: the buyer arranges and pays freight directly.">
    <div class="step-group-head"><?= $stepStatusIcon($stepStates['9']) ?><span class="step-group-num">STEP 9</span><h3>Freight Payment</h3><span class="step-group-na">Not applicable — FOB</span></div>
  </div>
  <?php else: ?>
  <?php $freightLocked = !$stage6 || $stage6['status'] === 'locked'; ?>

  <details class="step-group" id="step-group-stage-6"<?= $stepCardOpen('9') ?>>
    <summary><?= $stepStatusIcon($stepStates['9']) ?><span class="step-group-num">STEP 9</span><h3>Freight Payment</h3></summary>

  <details class="step-card" id="step-9a-freight-terms"<?= $subCardOpen('9', '9a') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('9', '9a')) ?><h2>Step 9a &middot; Save Freight Terms</h2></summary>
    <?php if ($freightLocked): ?>
      <p class="muted">Confirm the Supplier PO is signed first to unlock this gate.</p>
    <?php elseif (!$freight): ?>
      <?php if ($canManageShipping): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/freight-terms">
        <?= Csrf::field() ?>
        <label>Confirmed Freight Rate<input type="text" name="confirmed_freight_rate"></label>
        <?php if (strtoupper((string) ($order['incoterm_code'] ?? '')) === 'CIF'): // Q-R5-2: only a CIF seller insures ?>
        <label>Insurance Amount (CIF only)<input type="text" name="insurance_amount"></label>
        <?php endif; ?>
        <?php // Owner request (2026-10-27): the forwarder comes from the Logistics Partners directory, like the supplier in 7a. ?>
        <label>Freight Forwarder (Logistics Partner) *
          <select name="freight_forwarder_partner_id" required>
            <option value="">Select logistics partner…</option>
            <?php foreach ($logisticsPartners as $lp): ?>
              <option value="<?= (int) $lp['id'] ?>"><?= htmlspecialchars($lp['partner_name']) ?> (<?= htmlspecialchars(\App\Repositories\LogisticsPartnerRepository::SERVICE_TYPES[$lp['service_type']] ?? $lp['service_type']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <p class="muted small">Partners come from the Logistics Partners directory<?php if ($canManageLogisticsPartners): ?> — <a href="/logistics-partners/create" target="_blank" rel="noopener">add one</a> (opens in a new tab; reload this page afterwards)<?php else: ?> — ask an Admin/MD/ED to add one there<?php endif; ?>. Their name and contact are copied onto this order.</p>
        <?= $stepBadge('9a') ?><button type="submit" class="btn-sm">Save Freight Terms</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <div class="kv-grid">
        <div><span class="k">Confirmed Rate</span><span class="v"><?= $freight['confirmed_freight_rate'] !== null ? htmlspecialchars($freight['confirmed_freight_rate']) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?></span></div>
        <div><span class="k">Insurance</span><span class="v"><?= strtoupper((string) ($order['incoterm_code'] ?? '')) !== 'CIF' ? 'NIL — buyer arranges insurance' : ($freight['insurance_amount'] !== null ? htmlspecialchars($freight['insurance_amount']) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : 'NIL') ?></span></div>
        <div><span class="k">Forwarder</span><span class="v"><?= htmlspecialchars($freight['freight_forwarder_name'] ?? '—') ?></span></div>
        <div><span class="k">Forwarder Contact</span><span class="v"><?= htmlspecialchars($freight['freight_forwarder_contact'] ?? '—') ?></span></div>
      </div>
      <p class="muted small">Terms already saved — see Step 9b to generate the debit note.</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">generates the debit note for the buyer</div>

  <details class="step-card" id="step-9b-freight-debit-note"<?= $subCardOpen('9', '9b') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('9', '9b')) ?><h2>Step 9b &middot; Generate Debit Note</h2></summary>
    <?php if ($freightLocked): ?>
      <p class="muted">Confirm the Supplier PO is signed first to unlock this gate.</p>
    <?php elseif (!$freight): ?>
      <p class="muted">Save Freight Terms first (Step 9a).</p>
    <?php else: ?>
      <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="FDN">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('9b') ?><button type="submit" class="<?= $__docBtnClass('FDN') ?>" data-loading-text="Generating…">Generate Debit Note</button><?= $__docAlreadyNote('FDN') ?>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once their payment lands</div>

  <details class="step-card" id="step-9c-freight-remittance"<?= $subCardOpen('9', '9c') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('9', '9c')) ?><h2>Step 9c &middot; Record Freight Remittance</h2></summary>
    <?php if (!$freightCleared) { $renderPaymentActionLink('freight', 'step-9c-freight-remittance'); } ?>
    <?php if ($freightLocked): ?>
      <p class="muted">Confirm the Supplier PO is signed first to unlock this gate.</p>
    <?php elseif (!$freight): ?>
      <p class="muted">Save Freight Terms first (Step 9a).</p>
    <?php elseif (!$freightCleared && (!$payment || $payment['freight_remittance_received_at'] === null)): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/freight">
        <?= Csrf::field() ?>
        <label>Freight Amount Received * <small class="muted">(in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="freight_amount" required data-remit-amount></label>
        <label>Received On<input type="date" name="freight_received_at" value="<?= date('Y-m-d') ?>"></label>
          <label>@ Exchange Rate * <small class="muted">(&#8377; per 1 <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="number" step="0.0001" min="0.0001" name="freight_exchange_rate" data-remit-rate required placeholder="e.g. 83.2500"></label>
          <p class="muted small remit-statement" data-remit-statement data-currency="<?= htmlspecialchars($order['currency_code'] ?? '') ?>"></p>
        <?= $stepBadge('9c') ?><button type="submit" class="btn-sm">Record Freight Remittance</button>
      </form>
    <?php else: ?>
      <?= $remittanceRecordedNote('freight', 'Next: Step 9d — mark it cleared once the bank confirms.') ?>
      <?php $renderInrActualWidget('freight', 'Freight Payment'); ?>
      <?php $renderExchangeRateWidget('freight', 'Freight Payment'); ?>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">after funds are confirmed cleared</div>

  <details class="step-card" id="step-9d-freight-clear"<?= $subCardOpen('9', '9d') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('9', '9d')) ?><h2>Step 9d &middot; Mark Freight Cleared</h2></summary>
    <?php if ($freightLocked): ?>
      <p class="muted">Confirm the Supplier PO is signed first to unlock this gate.</p>
    <?php elseif (!$freight): ?>
      <p class="muted">Save Freight Terms first (Step 9a).</p>
    <?php elseif ($freightCleared): ?>
      <p>Freight payment already cleared.</p>
    <?php elseif ($payment && $payment['freight_remittance_received_at'] !== null): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/freight/clear" onsubmit="return confirm('Mark the freight payment cleared? This unlocks Stage 7 — confirm the funds have actually landed first.');">
        <?= Csrf::field() ?>
        <label>Cleared On<input type="date" name="freight_cleared_at" value="<?= date('Y-m-d') ?>"></label>
        <?= $stageGateNotice(6, "mark the freight cleared") ?>
        <?= $stepBadge('9d') ?><button type="submit"<?= $stageGateDisabled(6) ?> class="btn-sm btn-success">Mark Freight Cleared (unlocks Stage 7)</button>
      </form>
    <?php else: ?>
      <p class="muted">Record the freight remittance first (Step 9c).</p>
    <?php endif; ?>
  </details>

  </details>
  <?php endif; ?>

  <?php $packingLocked = !$stage7 || $stage7['status'] === 'locked'; ?>

  <details class="step-group" id="step-group-stage-7"<?= $stepCardOpen('10') ?>>
    <summary><?= $stepStatusIcon($stepStates['10']) ?><span class="step-group-num">STEP 10</span><h3>Packing &amp; BL Instruction</h3></summary>

  <?php $renderComplianceCard('shipment', '10'); ?>

  <details class="step-card" id="step-10a-supplier-balance-paid"<?= $subCardOpen('10', '10a') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('10', '10a')) ?><h2>Step 10a &middot; Supplier Balance Paid <small class="muted">(before packing)</small></h2></summary>
    <?php $renderSupplierPayment('balance'); ?>
  </details>

  <div class="step-group-connector">material paid for — pack it</div>

  <details class="step-card" id="step-10b-packing"<?= $subCardOpen('10', '10b') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('10', '10b')) ?><h2>Step 10b &middot; Save Packing &amp; Crates</h2></summary>
    <?php if ($packingLocked): ?>
      <p class="muted"><?= $isFob ? 'Confirm the Supplier PO is signed first' : 'Clear the freight payment first' ?> to unlock this gate.</p>
    <?php else: ?>
      <?php
        $orderedSummary = \App\Repositories\OrderProductRepository::orderedQuantitySummary((int) $order['id']);
        $shortfallTolerance = \App\Repositories\CompanySettingsRepository::get('quantity_shortfall_tolerance_pct') ?? '5';
      ?>
      <?php if ($orderedSummary['comparable']): ?>
        <p class="muted small">Ordered quantity: <strong><?= htmlspecialchars(rtrim(rtrim(number_format((float) $orderedSummary['total'], 3), '0'), '.')) ?> <?= htmlspecialchars((string) $orderedSummary['unit']) ?></strong> &middot; Shortfall tolerance: <strong><?= htmlspecialchars($shortfallTolerance) ?>%</strong> — the shortfall below is computed automatically from this. A shortfall over tolerance is blocked until the buyer's written approval is uploaded.</p>
      <?php else: ?>
        <p class="muted small">Ordered quantity can't be automatically compared for this order (mixed units across product lines, or quantity not yet confirmed) — enter Shortfall % manually below.</p>
      <?php endif; ?>
      <?php if (!empty($packing['buyer_approval_file_id'])): ?>
        <p class="muted small">&#9989; Buyer's written approval of the quantity shortfall is on file (recorded <?= htmlspecialchars((string) ($packing['shortfall_notice_recorded_at'] ?? '')) ?>).</p>
      <?php endif; ?>
      <?php if ($canManageShipping): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/packing" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <div class="kv-grid">
          <label>Actual Quantity Packed<input type="text" name="actual_quantity_packed" value="<?= htmlspecialchars((string) ($packing['actual_quantity_packed'] ?? '')) ?>"></label>
          <label>Actual Quantity Unit
            <?php $__packingUnit = $packing['actual_quantity_unit'] ?? ($orderedSummary['comparable'] ? $orderedSummary['unit'] : ''); ?>
            <select name="actual_quantity_unit">
              <option value="">— Select —</option>
              <?php foreach ($units as $__u): ?>
                <option value="<?= htmlspecialchars($__u['option_value']) ?>" <?= ($__packingUnit === $__u['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($__u['option_value']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Crate Count<input type="text" name="crate_count" value="<?= htmlspecialchars((string) ($packing['crate_count'] ?? '')) ?>"></label>
          <label>Total Net Weight (kg)<input type="text" name="total_net_weight_kg" value="<?= htmlspecialchars((string) ($packing['total_net_weight_kg'] ?? '')) ?>"></label>
          <label>Total Gross Weight (kg)<input type="text" name="total_gross_weight_kg" value="<?= htmlspecialchars((string) ($packing['total_gross_weight_kg'] ?? '')) ?>"></label>
          <label>Total CBM (m&sup3;)<input type="text" name="total_cbm" value="<?= htmlspecialchars((string) ($packing['total_cbm'] ?? '')) ?>"></label>
          <label>Packing Date<input type="date" name="packing_date" value="<?= htmlspecialchars((string) ($packing['packing_date'] ?? '')) ?>"></label>
          <label>Shortfall % <?= $orderedSummary['comparable'] ? '(auto-computed — this field is ignored when the ordered quantity is comparable)' : '' ?><input type="text" name="shortfall_pct" value="<?= htmlspecialchars((string) ($packing['shortfall_pct'] ?? '')) ?>" <?= $orderedSummary['comparable'] ? 'readonly' : '' ?>></label>
          <label>Buyer's Written Approval of Shortfall <small class="muted">(required only if the shortfall exceeds tolerance)</small><input type="file" name="buyer_approval" accept=".pdf,.jpg,.jpeg,.png,.eml,.msg"></label>
        </div>

        <p class="muted small">Crate-level breakdown (add one row per physical crate). Pick the crate's product from this order — its HS code comes from the product record and cannot be typed here.</p>
        <table class="list">
          <tr><th>Crate No.</th><th>Marks &amp; Numbers</th><th>Product</th><th>L×W×H (cm)</th><th>Pcs</th><th>Net (kg)</th><th>Gross (kg)</th><th>CBM</th><th>HS Code</th></tr>
          <?php $existingCrates = $crates ?: [[], [], []]; foreach ($existingCrates as $c): ?>
          <tr>
            <td><input type="text" name="crate_no[]" value="<?= htmlspecialchars($c['crate_no'] ?? '') ?>" style="width:70px;"></td>
            <td><input type="text" name="crate_marks_numbers[]" value="<?= htmlspecialchars($c['marks_numbers'] ?? '') ?>" style="width:130px;"></td>
            <td><?php $__crateProduct = (string) ($c['product_description'] ?? ''); $__crateKnown = in_array($__crateProduct, array_map(static fn(array $p): string => (string) $p['description'], $products), true); ?>
              <select name="crate_product_description[]" data-crate-product style="width:160px;">
                <option value="" data-hs="">— Product —</option>
                <?php foreach ($products as $__p): ?>
                <option value="<?= htmlspecialchars((string) $__p['description']) ?>" data-hs="<?= htmlspecialchars((string) $__p['hs_code']) ?>" <?= $__crateProduct === (string) $__p['description'] ? 'selected' : '' ?>><?= htmlspecialchars(mb_strimwidth((string) $__p['description'], 0, 60, '…')) ?></option>
                <?php endforeach; ?>
                <?php if ($__crateProduct !== '' && !$__crateKnown): ?><option value="<?= htmlspecialchars($__crateProduct) ?>" data-hs="<?= htmlspecialchars((string) ($c['hs_code'] ?? '')) ?>" selected><?= htmlspecialchars($__crateProduct) ?> (earlier entry)</option><?php endif; ?>
              </select></td>
            <td><input type="text" name="crate_dimensions[]" value="<?= htmlspecialchars($c['dimensions_lwh_cm'] ?? '') ?>" style="width:80px;"></td>
            <td><input type="text" name="crate_pcs[]" value="<?= htmlspecialchars((string) ($c['pcs'] ?? '')) ?>" style="width:50px;"></td>
            <td><input type="text" name="crate_net_weight_kg[]" value="<?= htmlspecialchars((string) ($c['net_weight_kg'] ?? '')) ?>" style="width:70px;"></td>
            <td><input type="text" name="crate_gross_weight_kg[]" value="<?= htmlspecialchars((string) ($c['gross_weight_kg'] ?? '')) ?>" style="width:70px;"></td>
            <td><input type="text" name="crate_cbm[]" value="<?= htmlspecialchars((string) ($c['cbm'] ?? '')) ?>" style="width:60px;"></td>
            <td><input type="text" value="<?= htmlspecialchars($c['hs_code'] ?? '') ?>" data-crate-hs readonly tabindex="-1" title="Taken from the product's HS code" style="width:70px;"></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?= $stepBadge('10b') ?><button type="submit" class="btn-sm">Save Packing &amp; Crates</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">generate the Packing List from those figures</div>

  <details class="step-card" id="step-10c-packing-list"<?= $subCardOpen('10', '10c') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('10', '10c')) ?><h2>Step 10c &middot; Generate Packing List</h2></summary>
    <?php if ($packingLocked): ?>
      <p class="muted"><?= $isFob ? 'Confirm the Supplier PO is signed first' : 'Clear the freight payment first' ?> to unlock this gate.</p>
    <?php elseif ($packing): ?>
      <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="PL">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('10c') ?><button type="submit" class="<?= $__docBtnClass('PL') ?>" data-loading-text="Generating…">Generate Packing List</button><?= $__docAlreadyNote('PL') ?>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Save Packing &amp; Crates first (Step 10b).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once the vessel/sailing is confirmed</div>

  <details class="step-card" id="step-10d-shipping-details"<?= $subCardOpen('10', '10d') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('10', '10d')) ?><h2>Step 10d &middot; Save Shipping Details</h2></summary>
    <?php if ($packingLocked): ?>
      <p class="muted"><?= $isFob ? 'Confirm the Supplier PO is signed first' : 'Clear the freight payment first' ?> to unlock this gate.</p>
    <?php else: ?>
      <?php if ($canManageShipping): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/shipping">
        <?= Csrf::field() ?>
        <div class="kv-grid">
          <label>Shipping Line<input type="text" name="shipping_line" value="<?= htmlspecialchars($shipping['shipping_line'] ?? '') ?>"></label>
          <label>Vessel Name<input type="text" name="vessel_name" value="<?= htmlspecialchars($shipping['vessel_name'] ?? '') ?>"></label>
          <label>Voyage Number<input type="text" name="voyage_number" value="<?= htmlspecialchars($shipping['voyage_number'] ?? '') ?>"></label>
          <label>ETD <small class="muted">(Estimated Time of Departure)</small><input type="date" name="etd" value="<?= htmlspecialchars($shipping['etd'] ?? '') ?>"></label>
          <label>ETA <small class="muted">(Estimated Time of Arrival)</small><input type="date" name="eta" value="<?= htmlspecialchars($shipping['eta'] ?? '') ?>"></label>
          <label>Container Type
            <select name="ship_container_type">
              <option value="">— To Be Confirmed —</option>
              <?php $shipContainerType = $shipping['container_type'] ?? $order['container_type'] ?? ''; ?>
              <?php foreach ($containerTypes as $o): ?>
                <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($shipContainerType === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Number of Containers<input type="number" name="container_count" min="1" step="1" value="<?= htmlspecialchars((string) ($shipping['container_count'] ?? '')) ?>"></label>
          <label>Container No.<input type="text" name="container_no" value="<?= htmlspecialchars($shipping['container_no'] ?? '') ?>"></label>
          <label>Seal No.<input type="text" name="seal_no" value="<?= htmlspecialchars($shipping['seal_no'] ?? '') ?>"></label>
        </div>
        <?= $stepBadge('10d') ?><button type="submit" class="btn-sm">Save Shipping Details</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">tell the CHA / shipping line how to issue the BL</div>

  <details class="step-card" id="step-10e-bl-instruction"<?= $subCardOpen('10', '10e') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('10', '10e')) ?><h2>Step 10e &middot; Generate BL Instruction Sheet</h2></summary>
    <?php if ($packingLocked): ?>
      <p class="muted"><?= $isFob ? 'Confirm the Supplier PO is signed first' : 'Clear the freight payment first' ?> to unlock this gate.</p>
    <?php elseif ($shipping): ?>
      <h3 style="font-size:0.95em; margin-top:0;">BL Instruction Sheet (for your CHA / shipping line)</h3>
      <p class="muted small">Generates the shipping-booking worksheet your CHA or shipping line needs before confirming the BL booking — shipper, consignee/notify party, shipment and container details from above, cargo description from the order's products, and the mandatory BL-type instruction from Settings. Review the Shipping and Packing details above first; anything left blank there shows as "To Be Confirmed" on the sheet.</p>
      <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="BLI">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('10e') ?><button type="submit" class="<?= $__docBtnClass('BLI') ?>" data-loading-text="Generating…">Generate BL Instruction Sheet</button><?= $__docAlreadyNote('BLI') ?>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Save Shipping Details first (Step 10d).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">after the CHA draft-review loop below</div>

  <details class="step-card" id="step-10f-bl-issued"<?= $subCardOpen('10', '10f') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('10', '10f')) ?><h2>Step 10f &middot; Confirm BL Issued <small class="muted">(after the CHA draft-review loop)</small></h2></summary>
    <?php if ($packingLocked): ?>
      <p class="muted"><?= $isFob ? 'Confirm the Supplier PO is signed first' : 'Clear the freight payment first' ?> to unlock this gate.</p>
    <?php elseif (!$blIssued): ?>
      <p class="muted small">After sending the BL Instruction Sheet and packing list above to your CHA, log each draft BL they send back here. Approve it, or request a revision with notes — the CHA's corrected draft is then logged as the next round. At most one round can be awaiting your decision at a time.</p>
      <?php $latestDraftRound = $draftBlReviews[0] ?? null; ?>
      <?php if (empty($draftBlReviews)): ?>
        <p class="muted">No draft BL logged yet.</p>
      <?php else: ?>
        <table class="list">
          <tr><th>Round</th><th>Received</th><th>Draft</th><th>Status</th><th>Reviewed</th><th></th></tr>
          <?php foreach ($draftBlReviews as $round): ?>
          <tr>
            <td><?= (int) $round['round_number'] ?></td>
            <td><?= htmlspecialchars((string) $round['uploaded_at']) ?></td>
            <td><a href="/file-store/<?= (int) $round['draft_file_id'] ?>/download" target="_blank">View draft</a></td>
            <td>
              <?php if ($round['status'] === 'pending'): ?><span class="badge badge-inactive">Pending Review</span>
              <?php elseif ($round['status'] === 'approved'): ?><span class="badge badge-active">Approved</span>
              <?php else: ?><span class="badge">Revision Requested</span><?php endif; ?>
              <?php if (!empty($round['review_notes'])): ?><br><small class="muted"><?= htmlspecialchars($round['review_notes']) ?></small><?php endif; ?>
            </td>
            <td><?= $round['reviewed_by_name'] ? htmlspecialchars($round['reviewed_by_name']) . ' — ' . htmlspecialchars((string) $round['reviewed_at']) : '—' ?></td>
            <td>
              <?php if ($round['status'] === 'pending'): ?>
                <details>
                  <summary class="small">Review</summary>
                  <form method="post" action="/orders/<?= (int) $order['id'] ?>/draft-bl/<?= (int) $round['id'] ?>/review">
                    <?= Csrf::field() ?>
                    <label>Decision
                      <select name="decision" required>
                        <option value="approved">Approve</option>
                        <option value="revision_requested">Request Revision</option>
                      </select>
                    </label>
                    <label>Notes <small class="muted">(required if requesting a revision)</small><input type="text" name="notes"></label>
                    <button type="submit" class="btn-sm">Submit Review</button>
                  </form>
                </details>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <?php if (!$latestDraftRound || $latestDraftRound['status'] !== 'pending'): ?>
        <?php if ($canManageShipping): // QA Round 5, R5-20 ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/draft-bl" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <label>Draft BL from CHA *<input type="file" name="draft_bl" accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.rtf,.txt,.csv,.jpg,.jpeg,.png,.gif,.webp,.bmp,.tif,.tiff,.heic,.eml,.msg,.zip" required></label>
          <button type="submit" class="btn-sm">Log Draft BL for Review</button>
        </form>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($canManageShipping): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-issued" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <label>BL Number *<input type="text" name="bl_number" required></label>
        <label>BL Date<input type="date" name="bl_date" value="<?= date('Y-m-d') ?>"></label>
        <label>Scanned BL Copy <small class="muted">(optional — can be attached later)</small><input type="file" name="bl_copy" accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.rtf,.txt,.csv,.jpg,.jpeg,.png,.gif,.webp,.bmp,.tif,.tiff,.heic,.eml,.msg,.zip"></label>
        <?= $stageGateNotice(7, "confirm the BL issued") ?>
        <?= $stepBadge('10f') ?><button type="submit"<?= $stageGateDisabled(7) ?> class="btn-sm btn-success">Confirm BL Issued (unlocks Stage 8)</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p>Bill of Lading recorded: <strong><?= htmlspecialchars((string) ($shipping['bl_number'] ?? '')) ?></strong> (<?= htmlspecialchars((string) ($shipping['bl_date'] ?? '')) ?>)</p>
      <?php if (!empty($shipping['bl_copy_file_id'])): ?>
        <p class="muted small"><a href="/file-store/<?= (int) $shipping['bl_copy_file_id'] ?>/download">Download scanned BL copy</a></p>
      <?php else: ?>
        <?php if ($canManageShipping): // QA Round 5, R5-20 ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-copy" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <label>Scanned BL Copy<input type="file" name="bl_copy" accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.rtf,.txt,.csv,.jpg,.jpeg,.png,.gif,.webp,.bmp,.tif,.tiff,.heic,.eml,.msg,.zip" required></label>
          <button type="submit" class="btn-sm">Attach Scanned BL Copy</button>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>

    <?php // Owner decision 2026-10-29: the shipping bill and its customs exchange rate — the CI and Debit Note go to Zoho Books at that rate. ?>
    <div id="shipping-bill" class="section">
      <h3>Shipping Bill</h3>
      <?php if (!empty($shipping['shipping_bill_no'])): ?>
        <p>Shipping bill <strong><?= htmlspecialchars((string) $shipping['shipping_bill_no']) ?></strong> dated <?= htmlspecialchars(date('d M Y', strtotime((string) $shipping['shipping_bill_date']))) ?> &middot; customs exchange rate <strong>&#8377;<?= htmlspecialchars(rtrim(rtrim(number_format((float) $shipping['customs_exchange_rate'], 4, '.', ''), '0'), '.')) ?></strong> for 1 <?= htmlspecialchars((string) $order['currency_code']) ?></p>
      <?php else: ?>
        <p class="muted small">Not recorded yet. The Commercial Invoice and the Debit Note go to Zoho Books at the customs exchange rate printed on the shipping bill, so they wait for it.</p>
      <?php endif; ?>
      <?php if ($canManageShipping): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/shipping-bill" class="field-grid">
        <?= Csrf::field() ?>
        <label>Shipping Bill No. *<input type="text" name="shipping_bill_no" required maxlength="30" value="<?= htmlspecialchars((string) ($shipping['shipping_bill_no'] ?? '')) ?>"></label>
        <label>Shipping Bill Date *<input type="date" name="shipping_bill_date" required max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars((string) ($shipping['shipping_bill_date'] ?? '')) ?>"></label>
        <label>Customs Exchange Rate (&#8377; for 1 <?= htmlspecialchars((string) $order['currency_code']) ?>) *<input type="number" name="customs_exchange_rate" required min="0.0001" max="100000" step="0.0001" value="<?= htmlspecialchars((string) ($shipping['customs_exchange_rate'] ?? '')) ?>"><small class="muted">As printed on the shipping bill. An invoice already in Zoho Books keeps the rate it was sent at.</small></label>
        <div><button type="submit" class="btn-sm"><?= !empty($shipping['shipping_bill_no']) ? 'Update Shipping Bill' : 'Save Shipping Bill' ?></button></div>
      </form>
      <?php endif; ?>
    </div>
  </details>

  <?php $ciBalanceLocked = !$stage8 || $stage8['status'] === 'locked'; ?>

  </details>

  <details class="step-group" id="step-group-stage-8"<?= $stepCardOpen('11') ?>>
    <summary><?= $stepStatusIcon($stepStates['11']) ?><span class="step-group-num">STEP 11</span><h3>Commercial Invoice &amp; Balance</h3></summary>

  <details class="step-card" id="step-ci-generate"<?= $subCardOpen('11', '11a') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('11', '11a')) ?><h2>Step 11a &middot; Generate Commercial Invoice<?= $isFob ? ' (before shipment)' : ' (against BL)' ?></h2></summary>
    <?php if ($ciBalanceLocked): ?>
      <p class="muted">Confirm BL issuance first to unlock this gate.</p>
    <?php else: ?>
      <?php // Owner request (2026-10-26, t4): the CI is made against the BL, so the scanned BL copy goes in first (FOB raises its CI before shipment, so no BL yet). ?>
      <?php if ($isFob): ?>
      <?php elseif (empty($shipping['bl_copy_file_id'])): ?>
        <p class="review-banner warn bl-copy-first">&#9888; <strong>Upload the scanned BL copy first</strong> — attach it in <a href="#step-10f-bl-issued">Step 10f</a> (Attach Scanned BL Copy), then generate the Commercial Invoice against it.</p>
      <?php else: ?>
        <p class="muted small">Scanned BL copy is on file (<a href="/file-store/<?= (int) $shipping['bl_copy_file_id'] ?>/download">download</a>) — generate the Commercial Invoice against it.</p>
      <?php endif; ?>
      <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="CI">
        <?php // Owner request (2026-10-27): the CI can be dated today or earlier, e.g. to match the BL. ?>
        <label class="ci-date-field">Invoice date (printed on the CI) *<input type="date" name="document_date" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" min="<?= htmlspecialchars(substr((string) $order['created_at'], 0, 10)) ?>"><small class="muted">Today by default. To match the BL, pick the BL date here — today or any earlier date.</small></label>
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('11a') ?><button type="submit" class="<?= $__docBtnClass('CI') ?>" data-loading-text="Generating…">Generate Commercial Invoice</button><?= $__docAlreadyNote('CI') ?>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">timed with sending the scanned BL</div>

  <details class="step-card" id="step-ci-scanned-bl-sent"<?= $subCardOpen('11', '11b') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('11', '11b')) ?><h2>Step 11b &middot; Mark Scanned BL Copy Sent to Buyer</h2></summary>
    <?php if ($ciBalanceLocked): ?>
      <p class="muted">Confirm BL issuance first to unlock this gate.</p>
    <?php elseif ($shipping && !$shipping['scanned_bl_sent_to_buyer_at']): ?>
      <?php if ($canManageShipping): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/scanned-bl-sent">
        <?= Csrf::field() ?>
        <?= $stepBadge('11b') ?><button type="submit" class="btn-sm">Mark Scanned BL Copy Sent to Buyer</button>
      </form>
      <?php endif; ?>
    <?php elseif ($shipping): ?>
      <p class="muted small">Scanned BL copy sent to buyer.</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once the buyer's balance payment lands</div>

  <details class="step-card" id="step-balance-record"<?= $subCardOpen('11', '11c') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('11', '11c')) ?><h2>Step 11c &middot; Record Balance Remittance</h2></summary>
    <?php if (!$balanceCleared) { $renderPaymentActionLink('balance', 'step-balance-record'); } ?>
    <?php if ($ciBalanceLocked): ?>
      <p class="muted">Confirm BL issuance first to unlock this gate.</p>
    <?php elseif (!$balanceCleared && (!$payment || $payment['balance_remittance_received_at'] === null)): ?>
      <?php if (!$ciDocGenerated): ?>
        <p class="muted small">Generate the Commercial Invoice first — the buyer can't be remitting against an invoice they were never sent.</p>
      <?php elseif (!$canManagePayments): ?>
        <p class="muted">Only Accounts/Export can record this payment.</p>
      <?php else: ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/balance">
        <?= Csrf::field() ?>
        <label>Balance Amount Received * <small class="muted">(in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="balance_amount" required data-remit-amount value="<?= htmlspecialchars((string) ($payment['balance_amount'] ?? '')) ?>"></label>
        <label>Received On<input type="date" name="balance_received_at" value="<?= date('Y-m-d') ?>"></label>
          <label>@ Exchange Rate * <small class="muted">(&#8377; per 1 <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="number" step="0.0001" min="0.0001" name="balance_exchange_rate" data-remit-rate required placeholder="e.g. 83.2500"></label>
          <p class="muted small remit-statement" data-remit-statement data-currency="<?= htmlspecialchars($order['currency_code'] ?? '') ?>"></p>
        <?= $stepBadge('11c') ?><button type="submit" class="btn-sm">Record Balance Remittance</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <?= $remittanceRecordedNote('balance', 'Next: mark it cleared once the bank confirms.') ?>
    <?php endif; ?>
    <?php if (!$ciBalanceLocked): ?>
      <?php $renderInrActualWidget('balance', 'Balance Payment'); ?>
      <?php $renderExchangeRateWidget('balance', 'Balance Payment'); ?>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">after funds are confirmed cleared</div>

  <details class="step-card" id="step-balance-clear"<?= $subCardOpen('11', '11d') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('11', '11d')) ?><h2>Step 11d &middot; Mark Balance Cleared</h2></summary>
    <?php if ($ciBalanceLocked): ?>
      <p class="muted">Confirm BL issuance first to unlock this gate.</p>
    <?php elseif ($balanceCleared): ?>
      <p>Balance payment cleared.</p>
    <?php elseif ($payment && $payment['balance_remittance_received_at'] !== null): ?>
      <?php if (!$canClearPayments): ?>
        <p class="muted">Balance remittance recorded — awaiting Accounts to confirm it cleared.</p>
      <?php else: ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/balance/clear" onsubmit="return confirm('Mark the balance payment cleared? This unlocks Stage 9 (final closure) — confirm the funds have actually landed first, and that the BL hasn\'t been released early if this is a post-BL preset.');">
        <?= Csrf::field() ?>
        <label>Cleared On<input type="date" name="balance_cleared_at" value="<?= date('Y-m-d') ?>"></label>
        <?= $stageGateNotice(8, "mark the balance cleared") ?>
        <?= $stepBadge('11d') ?><button type="submit"<?= $stageGateDisabled(8) ?> class="btn-sm btn-success">Mark Balance Cleared (unlocks Stage 9)</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Record the balance remittance first.</p>
    <?php endif; ?>
  </details>

  </details>

  </details>

  <details class="phase-group" id="phase-closure"<?= $phaseOpen('closure') ?>>
    <?= $phaseSummary('closure') ?>

  <?php if ($canManageOrderFinancials): ?>
  <?php
    // Owner decision (2026-10-26): Step 12 as guided sub-steps (Option A) —
    // 12a costs, 12b scheme eligibility, 12c review & complete — with the
    // order's money picture on top.
    // Sub-steps here never lock each other (benefit money can arrive months
    // later); the next unfinished one is simply opened for you.
    $__finEligible = array_values(array_filter($exportBenefitEligibility, static fn($r) => $r['status'] === 'eligible'));
    $__finPending = count(array_filter($exportBenefitEligibility, static fn($r) => $r['status'] === 'pending'));
    $__finClaimedNames = array_column($orderBenefitClaims, 'scheme_name');
    $__finWaiting = count(array_filter($orderBenefitClaims, static fn($c) => $c['received_amount'] === null));
    $__finUnclaimed = count(array_filter($__finEligible, static fn($r) => !in_array($r['scheme_name'], $__finClaimedNames, true)));
    $__finSkipped = !empty($order['financials_skipped_at']);
    // Owner decision (2026-10-27): benefits claimed & received moved to
    // Step 13g (just before Mark Fully Settled) — Step 12 is now three parts.
    $__finDone = [
        '12a' => $__finSkipped || !empty($order['financials_costs_confirmed_at']),
        '12b' => $__finSkipped || $__finPending === 0,
        '12c' => $__finSkipped || !empty($order['financials_completed_at']),
    ];
    $__finCurrent = array_search(false, $__finDone, true) ?: null;
    $__finIcon = static fn(string $sub): string => $__finDone[$sub] ? 'done' : ($sub === $__finCurrent ? 'current' : 'locked');
    $__finOpen = static fn(string $sub): string => $sub === $__finCurrent ? ' open' : '';
    $__costTotal = array_sum(array_map(static fn($c) => (float) $c['amount_inr'], $orderCostEntries));
    $__benefitsApplicable = in_array($order['status'], ['shipped_docs_sent', 'complete'], true) && !empty($payment['balance_remittance_received_at']);
    $__benefitsDisabled = $__benefitsApplicable ? '' : ' disabled';
    $__receivedBenefits = array_sum(array_map(static fn($c) => (float) ($c['received_amount'] ?? 0), $orderBenefitClaims));
    $__claimedBenefits = array_sum(array_map(static fn($c) => (float) $c['claimed_amount'], $orderBenefitClaims));
  ?>
  <details class="step-group" id="order-financials"<?= $stepCardOpen('12') ?>>
    <summary><?= $stepStatusIcon($stepStates['12']) ?><span class="step-group-num">STEP 12</span><h3>Closure &mdash; Financials, Benefits &amp; Costs</h3><span class="fin-progress"><?= count(array_filter($__finDone)) ?> of 3 done</span></summary>
    <p class="muted small">Everything financial about this order, in three short parts (government benefits are recorded later, in Step 13g) — visible only to Super Admin and roles granted "Manage order financials". Work top to bottom; the next unfinished part opens by itself.</p>
    <?php if (!empty($canCheckZoho)): ?><p class="small"><a href="/orders/<?= (int) $order['id'] ?>/zoho-check">Check this order against Zoho Books &rarr;</a> <span class="muted">(reads only)</span></p><?php endif; ?>

    <div class="fin-kpis">
      <div class="fin-kpi"><span class="l">Received from buyer<?= $profitability && $profitability['revenue_is_estimated'] ? ' (estimated)' : '' ?></span><span class="v"><?= $profitability ? '&#8377;' . number_format($profitability['revenue_inr'], 2) : '—' ?></span></div>
      <div class="fin-kpi"><span class="l">Total costs</span><span class="v"><?= $profitability ? '&#8377;' . number_format($profitability['total_cost_inr'], 2) : '—' ?></span></div>
      <div class="fin-kpi"><span class="l">Govt benefits received</span><span class="v">&#8377;<?= number_format($__receivedBenefits, 2) ?><?php if ($__claimedBenefits > $__receivedBenefits): ?> <small class="muted">of &#8377;<?= number_format($__claimedBenefits, 2) ?> claimed</small><?php endif; ?></span></div>
      <div class="fin-kpi good"><span class="l">Net profit</span><span class="v"><?= $profitability ? '&#8377;' . number_format($profitability['profit_inr'], 2) . ($profitability['margin_pct'] !== null ? ' · ' . number_format($profitability['margin_pct'], 1) . '%' : '') : '—' ?></span></div>
    </div>

    <details class="step-card" id="financials-12a"<?= $__finOpen('12a') ?>>
      <summary><?= $stepStatusIcon($__finIcon('12a')) ?><h2>Step 12a &middot; Order Costs</h2><span class="fin-sub-summary"><?= count($orderCostEntries) ?> cost<?= count($orderCostEntries) === 1 ? '' : 's' ?> · &#8377;<?= number_format($__costTotal, 2) ?></span></summary>
      <p class="muted small">Every cost this order carried besides the supplier, freight and insurance (those come in automatically). All amounts in INR.</p>
        <?php if (empty($orderCostEntries)): ?>
          <p class="muted">No other costs recorded for this order yet.</p>
        <?php else: ?>
          <table class="list">
            <tr><th>Date</th><th>Category</th><th>Description</th><th>Amount (INR)</th><th>Paid from</th><th>Recorded By</th><th></th></tr>
            <?php foreach ($orderCostEntries as $c): ?>
            <tr>
              <td><?= htmlspecialchars((string) ($c['incurred_at'] ?? '—')) ?></td>
              <td><?= htmlspecialchars($costEntryCategories[$c['category']] ?? $c['category']) ?></td>
              <td><?= htmlspecialchars($c['description'] ?? '—') ?>
                <?php if (isset(\App\Repositories\OrderCostEntryRepository::NOT_IN_ZOHO[$c['category']])): ?><br><small class="muted"><?= htmlspecialchars(\App\Repositories\OrderCostEntryRepository::NOT_IN_ZOHO[$c['category']]) ?></small><?php elseif (!empty($c['zoho_expense_id'])): ?><br><small class="muted">In Zoho Books</small><?php endif; ?></td>
              <td><?= number_format((float) $c['amount_inr'], 2) ?></td>
              <td><?= htmlspecialchars(\App\Repositories\OrderCostEntryRepository::PAID_FROM[$c['paid_from'] ?? ''] ?? '—') ?></td>
              <td><?= htmlspecialchars($c['recorded_by_name']) ?></td>
              <td>
                <?php if (!empty($c['incentive_kind'])): ?>
                <small class="muted">Set by Team Incentive</small>
                <?php else: ?>
                <form method="post" action="/orders/<?= (int) $order['id'] ?>/cost-entries/<?= (int) $c['id'] ?>/delete" onsubmit="return confirm(<?= htmlspecialchars(json_encode(!empty($c['zoho_expense_id']) ? 'Remove this cost entry? It is in Zoho Books too, and is removed there as well.' : 'Remove this cost entry?')) ?>);">
                  <?= Csrf::field() ?>
                  <button type="submit" class="btn-sm btn-danger">Remove</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      <?php
        // Owner decisions (2026-10-27): the order's actual expenses, each with
        // its receipt (or a reason when no bill exists). The travel % of FOB
        // stays the provisional figure until real travel expenses are recorded.
        $__travelBudget = $orderTravelExpenses ? (float) $orderTravelExpenses['expense_amount'] : null;
        $__logisticsTotal = array_sum(array_map(static fn (array $b): float => $b['cost_inr'], array_filter($vendorBills, static fn (array $b): bool => $b['vendor_kind'] === 'logistics')));
      ?>
      <div class="order-expenses" id="order-expenses">
        <h3 style="font-size:0.95em; margin-top:10px;">Order Expenses — travel &amp; other</h3>
        <div class="plan-bar">
          <div>Travel budget<?= $orderTravelExpenses ? ' (' . number_format((float) $orderTravelExpenses['expense_pct'], 2) . '% of FOB)' : '' ?><b><?= $__travelBudget !== null ? '&#8377;' . number_format($__travelBudget, 2) : '—' ?></b></div>
          <div>Actual travel<b>&#8377;<?= number_format($expenseSummary['travel'], 2) ?></b></div>
          <div>All expenses<b>&#8377;<?= number_format($expenseSummary['total'], 2) ?></b></div>
          <div>Receipts attached<b><?= (int) $expenseSummary['with_receipt'] ?> of <?= (int) $expenseSummary['count'] ?></b></div>
          <?php if ($expenseSummary['to_reimburse'] > 0): ?><div>Paid by staff, to reimburse<b class="vendor-bill-due">&#8377;<?= number_format($expenseSummary['to_reimburse'], 2) ?></b></div><?php endif; ?>
        </div>
        <p class="muted small"><?= $expenseSummary['has_travel']
            ? 'Travel cost now uses the actual travel expenses below; the travel % stays only as the budget to compare against.'
            : 'Until real travel expenses are recorded, travel cost uses the travel % of FOB (provisional).' ?>
          A voucher can be added later with its real date — unless the CA has locked that financial year.
          <?php if ($__logisticsTotal > 0): ?>Logistics partner bills (&#8377;<?= number_format($__logisticsTotal, 2) ?>) come in automatically from <a href="#logistics-bills">Group C</a>.<?php endif; ?></p>
        <?php if ($orderExpenses !== []): ?>
        <table class="list">
          <tr><th>Date</th><th>Category</th><th>Details</th><th style="text-align:right">&#8377;</th><th>Paid by</th><th>Receipt</th><th></th></tr>
          <?php foreach ($orderExpenses as $e): ?>
          <tr>
            <td><?= htmlspecialchars(\App\Helpers\Dates::short((string) $e['expense_date'])) ?><?php if (!empty($e['added_later'])): ?><br><small class="muted">added later (entered <?= htmlspecialchars(\App\Helpers\Dates::short((string) $e['created_at'])) ?>)</small><?php endif; ?></td>
            <td><?= htmlspecialchars(\App\Repositories\OrderExpenseRepository::CATEGORIES[$e['category']] ?? (string) $e['category']) ?></td>
            <td><?= htmlspecialchars((string) $e['details']) ?></td>
            <td style="text-align:right"><?= number_format((float) $e['amount_inr'], 2) ?></td>
            <td><?= htmlspecialchars(\App\Repositories\OrderExpenseRepository::PAID_BY[$e['paid_by']] ?? (string) $e['paid_by']) ?><?= $e['paid_by'] === 'staff' && !empty($e['staff_name']) ? ' — ' . htmlspecialchars((string) $e['staff_name']) : '' ?></td>
            <td><?php if (!empty($e['receipt_file_id'])): ?><a href="/file-store/<?= (int) $e['receipt_file_id'] ?>/download">receipt</a><?php else: ?><span class="vendor-bill-due">No bill — <?= htmlspecialchars((string) $e['no_receipt_reason']) ?></span><?php endif; ?></td>
            <td>
              <form method="post" action="/orders/<?= (int) $order['id'] ?>/expenses/<?= (int) $e['id'] ?>/delete" data-confirm="<?= !empty($e['zoho_expense_id']) ? 'Remove this expense? It is in Zoho Books too, and is removed there as well.' : 'Remove this expense?' ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-sm btn-danger">Remove</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
        <details class="vendor-bill-add"<?= $orderExpenses === [] ? ' open' : '' ?>>
          <summary class="small"><strong>+ Add an expense</strong></summary>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/expenses" enctype="multipart/form-data" class="field-grid">
            <?= Csrf::field() ?>
            <label>Date *<input type="date" name="expense_date" required max="<?= date('Y-m-d') ?>"></label>
            <label>Category *
              <select name="category" required>
                <?php foreach (\App\Repositories\OrderExpenseRepository::CATEGORIES as $__key => $__label): ?><option value="<?= htmlspecialchars($__key) ?>"><?= htmlspecialchars($__label) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label>Amount (&#8377;) *<input type="number" step="0.01" min="0.01" name="amount_inr" required></label>
            <label>Paid by *
              <select name="paid_by" required>
                <?php foreach (\App\Repositories\OrderExpenseRepository::PAID_BY as $__key => $__label): ?><option value="<?= htmlspecialchars($__key) ?>"><?= htmlspecialchars($__label) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label>Staff member to reimburse<input type="text" name="staff_name" maxlength="150" data-required-if="paid_by=staff" placeholder="only when staff paid"></label>
            <label class="full">Details *<input type="text" name="details" required maxlength="255" placeholder="e.g. Factory visit 2 — Kota"></label>
            <label>Receipt / bill<input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png"></label>
            <label>No receipt? Reason<input type="text" name="no_receipt_reason" maxlength="255" minlength="<?= \App\Helpers\ReasonValidator::MIN_LENGTH ?>" data-required-unless="receipt" placeholder="only if no bill exists"></label>
            <div><button type="submit" class="btn-sm">Add Expense</button></div>
          </form>
        </details>
      </div>
      <details class="fin-zoho">
        <summary class="muted small">Booked in Zoho Books and linked to this order (<?= count($linkedCaExpenses) ?>) — counted in the order's cost</summary>
        <?php if (empty($linkedCaExpenses)): ?>
          <p class="muted">Nothing linked to this order yet — link an expense from <a href="/ca/expenses">the Expenses register</a> if one belongs here.</p>
        <?php else: ?>
          <table class="list">
            <tr><th>Date</th><th>Category</th><th>Vendor</th><th>Amount</th></tr>
            <?php foreach ($linkedCaExpenses as $e): ?>
            <tr>
              <td><?= htmlspecialchars((string) $e['expense_date']) ?></td>
              <td><?= htmlspecialchars($e['category']) ?><?php if (!empty($e['reason'])): ?><br><small class="muted"><?= htmlspecialchars((string) $e['reason']) ?></small><?php endif; ?></td>
              <td><?= htmlspecialchars((string) ($e['vendor_name'] ?? '—')) ?></td>
              <td><?= number_format((float) $e['amount'], 2) ?> <?= htmlspecialchars($e['currency_code']) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </details>
      <details class="fin-add-cost">
        <summary class="small"><strong>+ Add a cost</strong></summary>
        <label class="fin-add-cost-pick">Type of cost
          <select data-fin-cost-type aria-label="Type of cost">
            <option value="other">Other cost (CHA, port, courier, bank charges…)</option>
            <option value="travel">Travel budget — % of FOB (provisional until real travel expenses are recorded)</option>
            <?php if ($pendingInspectionTask): ?><option value="inspection">Pre-shipment inspection fee</option><?php endif; ?>
            <?php if ($isLcPayment): ?><option value="lc">LC / bank loan interest</option><?php endif; ?>
          </select>
        </label>
        <div data-fin-cost-form="other">
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/cost-entries">
            <?= Csrf::field() ?>
            <label>Category *
              <select name="category" required>
                <?php foreach ($costEntryCategories as $key => $label): ?>
                  <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Amount (INR) *<input type="text" name="amount_inr" required></label>
            <label>Date<input type="date" name="incurred_at" value="<?= date('Y-m-d') ?>"></label>
            <label>Description<input type="text" name="description"></label>
            <?php // Owner decision 2026-10-29: real paid costs go to Zoho Books as expenses, paid from here. ?>
            <label>Paid from *
              <select name="paid_from" required>
                <?php foreach (\App\Repositories\OrderCostEntryRepository::PAID_FROM as $__key => $__label): ?><option value="<?= htmlspecialchars($__key) ?>"><?= htmlspecialchars($__label) ?></option><?php endforeach; ?>
              </select>
            </label>
            <p class="muted small">CHA, inland transport and port charges belong on <a href="#logistics-bills">logistics partner bills</a>; bank charges and loan interest the CA books from the bank statement — those entries here are not sent to Zoho Books.</p>
            <button type="submit" class="btn-sm">Record Cost</button>
          </form>
        </div>
        <div data-fin-cost-form="travel">
        <h3 style="font-size:0.95em; margin-top:10px;">Travel Expenses — Factory Visits</h3>
        <p class="muted small">Every order needs 3-4 trips to the factory; record the distance (for reference) and the percentage of the order's FOB value to charge as travel expenses.</p>
        <div class="kv-grid">
          <div><span class="k">Factory Distance</span><span class="v"><?= $orderTravelExpenses && $orderTravelExpenses['factory_distance_km'] !== null ? number_format((float) $orderTravelExpenses['factory_distance_km'], 2) . ' km' : '—' ?></span></div>
          <div><span class="k">Percentage</span><span class="v"><?= $orderTravelExpenses ? number_format((float) $orderTravelExpenses['expense_pct'], 2) . '%' : 'Not yet recorded' ?></span></div>
          <div><span class="k">Amount</span><span class="v"><?= $orderTravelExpenses ? number_format((float) $orderTravelExpenses['expense_amount'], 2) : '—' ?></span></div>
        </div>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/travel-expenses">
            <?= Csrf::field() ?>
            <label>Factory Distance (km) <small class="muted">(min 175km — for reference only)</small><input type="number" min="0" step="any" name="factory_distance_km" value="<?= htmlspecialchars($orderTravelExpenses && $orderTravelExpenses['factory_distance_km'] !== null ? (string) $orderTravelExpenses['factory_distance_km'] : '') ?>"></label>
            <label>Travel Expense % of FOB Value *
              <select name="expense_pct" required>
                <?php $savedPct = $orderTravelExpenses ? (string) (float) $orderTravelExpenses['expense_pct'] : ''; ?>
                <?php foreach (['5', '7', '10', '15'] as $pct): ?>
                  <option value="<?= $pct ?>" <?= $savedPct === $pct ? 'selected' : '' ?>><?= $pct ?>%</option>
                <?php endforeach; ?>
                <option value="other" <?= !in_array($savedPct, ['5', '7', '10', '15'], true) && $savedPct !== '' ? 'selected' : '' ?>>Other — specify</option>
              </select>
            </label>
            <label>Custom % <small class="muted">(only used when "Other" is selected above)</small><input type="number" min="0" step="any" name="expense_pct_other" value="<?= !in_array($savedPct, ['5', '7', '10', '15', ''], true) ? htmlspecialchars($savedPct) : '' ?>"></label>
            <button type="submit" class="btn-sm">Save</button>
          </form>
        </div>
        <?php if ($pendingInspectionTask): ?>
        <div data-fin-cost-form="inspection">
        <h3 style="font-size:0.95em; margin-top:14px;">3rd-Party Pre-Shipment Inspection (step 8a)</h3>
        <p class="muted small">This order has a Pre-Shipment Inspection on its Compliance Checklist (not marked not-applicable) — staff get reminded starting 7 days before the estimated shipment date (Step 8a). If NexaCrest pays the inspection fee, record it below so it's included as an order expense; if the client pays it directly, there's nothing to record.</p>
        <div class="kv-grid">
          <div><span class="k">Fee Payer</span><span class="v"><?= $orderInspectionFee ? htmlspecialchars($orderInspectionFee['fee_payer'] === 'nexacrest' ? 'NexaCrest' : 'Client') : 'Not yet recorded' ?></span></div>
          <div><span class="k">Fee Amount</span><span class="v"><?= $orderInspectionFee && $orderInspectionFee['fee_amount'] !== null ? number_format((float) $orderInspectionFee['fee_amount'], 2) : '—' ?></span></div>
        </div>
        <details>
          <summary><?= $orderInspectionFee ? 'Edit inspection fee' : 'Record inspection fee' ?></summary>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/inspection-fee">
            <?= Csrf::field() ?>
            <label>Who pays the inspection fee? *
              <select name="fee_payer" required>
                <option value="nexacrest" <?= ($orderInspectionFee['fee_payer'] ?? '') === 'nexacrest' ? 'selected' : '' ?>>NexaCrest</option>
                <option value="client" <?= ($orderInspectionFee['fee_payer'] ?? '') === 'client' ? 'selected' : '' ?>>Client</option>
              </select>
            </label>
            <label>Fee Amount (INR) <small class="muted">(only recorded as an expense when NexaCrest pays)</small><input type="text" name="fee_amount" value="<?= htmlspecialchars($orderInspectionFee && $orderInspectionFee['fee_amount'] !== null ? (string) $orderInspectionFee['fee_amount'] : '') ?>"></label>
            <?php // Owner decision 2026-10-29: the fee goes to Zoho Books as an expense, paid from here. ?>
            <?php $__feePaidFrom = null; foreach ($orderCostEntries as $__c) { if ($orderInspectionFee && (int) $__c['id'] === (int) ($orderInspectionFee['cost_entry_id'] ?? 0)) { $__feePaidFrom = $__c['paid_from']; } } ?>
            <label data-show-when="fee_payer=nexacrest">Paid from *
              <select name="paid_from" required>
                <?php foreach (\App\Repositories\OrderCostEntryRepository::PAID_FROM as $__key => $__label): ?><option value="<?= htmlspecialchars($__key) ?>"<?= $__feePaidFrom === $__key ? ' selected' : '' ?>><?= htmlspecialchars($__label) ?></option><?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="btn-sm">Save</button>
          </form>
        </details>
        </div>
        <?php endif; ?>
        <?php if ($isLcPayment): ?>
        <div data-fin-cost-form="lc">
        <?php // Only an order whose payment preset is a Letter of Credit has an LC / bank loan to record. ?>
        <?php $lcDetails = $orderLcDetails ?: ['lc_number' => null, 'lc_file_id' => null, 'bank_loan_amount' => null, 'bank_loan_interest_rate' => null, 'bank_loan_interest_paid' => null]; ?>
        <h3 style="font-size:0.95em; margin-top:0;">Letter of Credit &amp; Bank Loan</h3>
        <p class="muted small">Export process steps 5-1a/5-2a/5-3a: only relevant if the client opted for payment by LC. Recording an interest-paid amount below automatically keeps a matching "Bank Loan Interest" row in Other Order Costs below in sync — editing it there directly isn't needed.</p>
        <div class="kv-grid">
          <div><span class="k">LC Number</span><span class="v"><?= htmlspecialchars($lcDetails['lc_number'] ?? '—') ?></span></div>
          <div><span class="k">LC Document</span><span class="v"><?php if (!empty($lcDetails['lc_file_id'])): ?><a href="/file-store/<?= (int) $lcDetails['lc_file_id'] ?>/download" target="_blank">View LC</a><?php else: ?>—<?php endif; ?></span></div>
          <div><span class="k">Bank Loan Amount</span><span class="v"><?= $lcDetails['bank_loan_amount'] !== null ? number_format((float) $lcDetails['bank_loan_amount'], 2) : '—' ?></span></div>
          <div><span class="k">Interest Rate</span><span class="v"><?= $lcDetails['bank_loan_interest_rate'] !== null ? number_format((float) $lcDetails['bank_loan_interest_rate'], 3) . '%' : '—' ?></span></div>
          <div><span class="k">Interest Paid</span><span class="v"><?= $lcDetails['bank_loan_interest_paid'] !== null ? number_format((float) $lcDetails['bank_loan_interest_paid'], 2) : '—' ?></span></div>
        </div>
        <details>
          <summary><?= $orderLcDetails ? 'Edit LC / bank loan details' : 'Record LC / bank loan details for this order' ?></summary>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/lc-details" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <label>LC Number<input type="text" name="lc_number" value="<?= htmlspecialchars($lcDetails['lc_number'] ?? '') ?>"></label>
            <label>Final LC Document<input type="file" name="lc_file" accept=".pdf,.jpg,.jpeg,.png"></label>
            <label>Bank Loan Amount<input type="text" name="bank_loan_amount" value="<?= htmlspecialchars($lcDetails['bank_loan_amount'] !== null ? (string) $lcDetails['bank_loan_amount'] : '') ?>"></label>
            <label>Interest Rate (%)<input type="text" name="bank_loan_interest_rate" value="<?= htmlspecialchars($lcDetails['bank_loan_interest_rate'] !== null ? (string) $lcDetails['bank_loan_interest_rate'] : '') ?>"></label>
            <label>Total Interest Paid<input type="text" name="bank_loan_interest_paid" value="<?= htmlspecialchars($lcDetails['bank_loan_interest_paid'] !== null ? (string) $lcDetails['bank_loan_interest_paid'] : '') ?>"></label>
            <button type="submit" class="btn-sm">Save LC / Loan Details</button>
          </form>
        </details>
        </div>
        <?php endif; ?>
      </details>
      <?php if ($isLcPayment): ?>
      <?php // Owner decision 2026-10-29: the money the bank actually paid under the LC — it goes to Zoho Books as a payment against the CI. ?>
      <div id="lc-received">
      <h3 style="font-size:0.95em; margin-top:12px;">LC Payment Received</h3>
      <?php $__cur = (string) ($order['currency_code'] ?? 'INR'); ?>
      <?php if (!empty($orderLcDetails['lc_received_at'])): ?>
        <p>Paid by the bank on <strong><?= htmlspecialchars(\App\Helpers\Dates::short((string) $orderLcDetails['lc_received_at'])) ?></strong>: <?= htmlspecialchars($__cur) ?> <?= number_format((float) $orderLcDetails['lc_received_amount'], 2) ?> &middot; &#8377;<?= number_format((float) $orderLcDetails['lc_inr_credited'], 2) ?> credited &middot; &#8377;<?= number_format((float) $orderLcDetails['lc_bank_charges_inr'], 2) ?> bank charges<?= !empty($orderLcDetails['lc_zoho_synced_at']) ? ' &middot; <span class="muted">in Zoho Books</span>' : '' ?></p>
      <?php else: ?>
        <p class="muted small">Not recorded yet. When the bank pays under the LC (after the documents are presented), record it here: it goes to Zoho Books as the buyer's payment against the Commercial Invoice.</p>
      <?php endif; ?>
      <?php if (!empty($orderLcDetails['lc_received_at'])): ?>
        <?php // Owner decision 2026-10-29 (CAFIN change confirmed twice): the LC payment's FIRC / eBRC, like the other legs'. ?>
        <?php if (!empty($orderLcDetails['lc_firc_reference'])): ?>
          <p class="small">FIRC/eBRC: <strong><?= htmlspecialchars((string) $orderLcDetails['lc_firc_reference']) ?></strong> (<?= htmlspecialchars(\App\Helpers\Dates::short((string) $orderLcDetails['lc_firc_received_at'])) ?>)</p>
        <?php elseif (!empty($canEditInrActual)): ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/lc/firc" class="field-grid">
            <?= Csrf::field() ?>
            <label>FIRC/eBRC reference *<input type="text" name="lc_firc_reference" required maxlength="100"></label>
            <label>FIRC/eBRC date<input type="date" name="lc_firc_received_at" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
            <div><button type="submit" class="btn-sm">Record FIRC/eBRC</button></div>
          </form>
        <?php else: ?>
          <p class="muted small">FIRC/eBRC not recorded yet.</p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (empty($orderLcDetails['lc_confirmed_at'])): ?>
        <p class="muted small">Confirm the LC usable (Step 5e) first.</p>
      <?php elseif (empty($orderLcDetails['lc_zoho_synced_at'])): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/lc-received" class="field-grid">
        <?= Csrf::field() ?>
        <label>Date the bank paid *<input type="date" name="lc_received_at" required max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars((string) ($orderLcDetails['lc_received_at'] ?? '')) ?>"></label>
        <label>Amount paid under the LC (<?= htmlspecialchars($__cur) ?>) *<input type="number" name="lc_received_amount" required min="0.01" step="0.01" value="<?= htmlspecialchars((string) ($orderLcDetails['lc_received_amount'] ?? '')) ?>"></label>
        <label>Rupees credited (&#8377;) *<input type="number" name="lc_inr_credited" required min="0.01" step="0.01" value="<?= htmlspecialchars((string) ($orderLcDetails['lc_inr_credited'] ?? '')) ?>"><small class="muted">As on the bank's credit advice.</small></label>
        <label>Bank charges deducted (&#8377;)<input type="number" name="lc_bank_charges_inr" min="0" step="0.01" value="<?= htmlspecialchars((string) ($orderLcDetails['lc_bank_charges_inr'] ?? '0')) ?>"></label>
        <div><button type="submit" class="btn-sm"><?= !empty($orderLcDetails['lc_received_at']) ? 'Update LC Payment' : 'Record LC Payment' ?></button></div>
      </form>
      <?php endif; ?>
      </div>
      <?php endif; ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/financials/costs-confirmed" class="fin-confirm">
        <?= Csrf::field() ?>
        <?php if (!empty($order['financials_costs_confirmed_at'])): ?>
          <span class="small">&#10003; Costs marked complete on <?= htmlspecialchars((string) $order['financials_costs_confirmed_at']) ?>.</span>
          <input type="hidden" name="action" value="reopen">
          <button type="submit" class="btn-sm btn-secondary">Reopen costs</button>
        <?php else: ?>
          <button type="submit" class="btn-sm">Costs are complete &rarr; next: 12b</button>
          <span class="muted small">You can still add a cost later.</span>
        <?php endif; ?>
      </form>
    </details>

    <div class="step-group-connector">then answer which government schemes apply</div>

    <details class="step-card" id="financials-12b"<?= $__finOpen('12b') ?>>
      <summary><?= $stepStatusIcon($__finIcon('12b')) ?><h2>Step 12b &middot; Export Benefit Eligibility</h2><span class="fin-sub-summary"><?= $__finPending > 0 ? $__finPending . ' still to answer' : count($__finEligible) . ' eligible' ?></span></summary>
        <p class="muted small">Not every order qualifies for every scheme — resolve each one explicitly (never leave it "pending") so a report never has to guess whether "no claim" meant ineligible or forgotten. Marking the order fully settled (Step 13h) is blocked while any scheme here is still Pending.</p>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/export-benefit-schemes">
          <?= Csrf::field() ?>
          <table class="list">
            <tr><th>Scheme</th><th>Eligibility</th><th>Reason <small class="muted">(filled in when Not Eligible — edit if needed)</small></th></tr>
            <?php foreach ($exportBenefitEligibility as $__i => $sch): ?>
            <tr>
              <td><?= htmlspecialchars($sch['scheme_name']) ?>
                <input type="hidden" name="schemes[<?= (int) $__i ?>][scheme_name]" value="<?= htmlspecialchars($sch['scheme_name']) ?>">
                <?php if ($sch['resolved_at']): ?>
                <br><span class="muted small"><?= htmlspecialchars($sch['resolved_at']) ?><?php if ($sch['resolved_by_name']): ?> by <?= htmlspecialchars($sch['resolved_by_name']) ?><?php endif; ?></span>
                <?php endif; ?>
              </td>
              <td>
                <select name="schemes[<?= (int) $__i ?>][status]" data-scheme-status style="min-width:140px;">
                  <?php foreach (\App\Repositories\OrderExportBenefitSchemeRepository::STATUSES as $key => $label): ?>
                  <option value="<?= htmlspecialchars($key) ?>" <?= $sch['status'] === $key ? 'selected' : '' ?><?= $key === 'not_eligible' ? ' data-auto-reason="' . htmlspecialchars(\App\Repositories\OrderExportBenefitSchemeRepository::NOT_ELIGIBLE_REASON) . '"' : '' ?>><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" name="schemes[<?= (int) $__i ?>][reason]" value="<?= htmlspecialchars($sch['reason'] ?? '') ?>" placeholder="Why it doesn't apply to this order" data-scheme-reason></td>
            </tr>
            <?php endforeach; ?>
          </table>
          <button type="submit" class="btn-sm">Save All Schemes</button>
          <span class="muted small">Saves every row at once — only the rows you changed are updated.</span>
        </form>
    </details>

    <div class="step-group-connector">then review the profit and complete Step 12</div>

    <details class="step-card" id="financials-12c"<?= $__finOpen('12c') ?>>
      <summary><?= $stepStatusIcon($__finIcon('12c')) ?><h2>Step 12c &middot; Review Profit &amp; Complete Step 12</h2><span class="fin-sub-summary"><?= $profitability ? 'Net profit &#8377;' . number_format($profitability['profit_inr'], 2) : '' ?></span></summary>
        <?php if ($profitability): ?>
          <h3 style="font-size:0.95em; margin-top:0;">Profitability Summary</h3>
          <div class="kv-grid">
            <div><span class="k">Revenue (INR)<?= $profitability['revenue_is_estimated'] ? ' — estimated' : '' ?></span><span class="v"><?= number_format($profitability['revenue_inr'], 2) ?></span></div>
            <div><span class="k">Govt. Export Benefits (INR)<?= $profitability['export_benefits_is_estimated'] ? ' — claimed, not yet received' : '' ?></span><span class="v"><?= number_format($profitability['export_benefits_inr'], 2) ?></span></div>
            <div><span class="k">Supplier Cost (INR)</span><span class="v"><?= number_format($profitability['supplier_cost_inr'], 2) ?></span></div>
            <div><span class="k">Ocean Freight (INR)<?= $profitability['freight_from_bills'] ? ' — from the forwarder\'s bills' : '' ?></span><span class="v"><?= number_format($profitability['freight_cost_inr'], 2) ?></span></div>
            <div><span class="k">Insurance (INR)</span><span class="v"><?= number_format($profitability['insurance_cost_inr'], 2) ?></span></div>
            <div><span class="k">Logistics Bills — CHA / Transport (INR)</span><span class="v"><?= number_format($profitability['logistics_cost_inr'], 2) ?></span></div>
            <div><span class="k">Order Expenses (INR)</span><span class="v"><?= number_format($profitability['expenses_inr'], 2) ?></span></div>
            <div><span class="k">Expenses Booked in Zoho Books (INR)</span><span class="v"><?= number_format($profitability['zoho_expenses_inr'], 2) ?></span></div>
            <div><span class="k">Other Costs (INR)<?= $profitability['travel_is_provisional'] ? '' : ' — travel % replaced by actual travel' ?></span><span class="v"><?= number_format($profitability['other_costs_inr'], 2) ?></span></div>
            <?php if ($profitability['team_incentive_inr'] > 0 || $profitability['associate_commission_inr'] > 0): ?>
            <div><span class="k">of which Team Incentive (INR)</span><span class="v"><?= number_format($profitability['team_incentive_inr'], 2) ?></span></div>
            <div><span class="k">of which Associate Commission (INR)</span><span class="v"><?= number_format($profitability['associate_commission_inr'], 2) ?></span></div>
            <?php endif; ?>
            <div><span class="k">Total Order Cost (INR)</span><span class="v"><?= number_format($profitability['total_cost_inr'], 2) ?></span></div>
            <div><span class="k">Order Profit (INR)</span><span class="v"><?= number_format($profitability['profit_inr'], 2) ?></span></div>
            <div><span class="k">Order Margin %</span><span class="v"><?= $profitability['margin_pct'] !== null ? number_format($profitability['margin_pct'], 2) . '%' : '—' ?></span></div>
          </div>
          <?php if ($profitability['revenue_is_estimated']): ?>
            <p class="muted small">Revenue is estimated using the order's assumed exchange rate — it becomes the exact figure once both the advance and balance legs clear with their INR actual recorded. See <a href="/reports/order-profitability">the Order Profitability report</a> for every order side by side.</p>
          <?php endif; ?>
        <?php else: ?>
          <p class="muted">Not enough data recorded yet to compute profitability for this order.</p>
        <?php endif; ?>
      <p class="muted small">The internal financial annexure (CAFIN) is generated from <a href="#order-cafin">the CAFIN box at the top of this page</a> — available at any stage.</p>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/financials/complete" class="fin-confirm">
        <?= Csrf::field() ?>
        <?php if (!empty($order['financials_completed_at'])): ?>
          <span class="small">&#10003; Step 12 completed on <?= htmlspecialchars((string) $order['financials_completed_at']) ?><?= !empty($order['financials_completed_by']) ? ' by ' . htmlspecialchars($usersById[(int) $order['financials_completed_by']] ?? ('User #' . (int) $order['financials_completed_by'])) : '' ?>.</span>
          <input type="hidden" name="action" value="reopen">
          <button type="submit" class="btn-sm btn-secondary">Reopen Step 12</button>
        <?php elseif (!$__finSkipped): ?>
          <button type="submit" class="btn-sm btn-success">Mark Step 12 complete</button>
          <span class="muted small">Needs 12a marked complete and every scheme answered in 12b. Government benefits are recorded later, in Step 13g — just before the order is marked fully settled.</span>
        <?php endif; ?>
      </form>
      <?php // Owner request (2026-10-26, t4): skip Step 12 with a reason — figures stay blank, never 0. ?>
      <div class="financials-skip" style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px;">
        <?php if (!empty($order['financials_skipped_at'])): ?>
          <p class="small">&#10003; <strong>Step 12 marked complete without financial figures</strong> on <?= htmlspecialchars((string) $order['financials_skipped_at']) ?><?= !empty($order['financials_skipped_by']) ? ' by ' . htmlspecialchars($usersById[(int) $order['financials_skipped_by']] ?? ('User #' . (int) $order['financials_skipped_by'])) : '' ?> — <?= htmlspecialchars((string) $order['financials_skip_reason']) ?>. Nothing was recorded as 0; you can still add figures above.</p>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/financials/skip/undo" style="display:inline">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-secondary">Reopen Step 12</button>
          </form>
        <?php else: ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/financials/skip" data-confirm="Mark Step 12 complete without recording these figures? Nothing will be saved as 0.">
            <?= Csrf::field() ?>
            <label>Skip financials — mark Step 12 complete <small class="muted">(nothing to record here, or figures will come later — reason required; nothing is written as 0, and export-benefit schemes still need answering before Mark Fully Settled)</small>
              <input type="text" name="skip_reason" required minlength="<?= \App\Helpers\ReasonValidator::MIN_LENGTH ?>" placeholder="Reason (mandatory)">
            </label>
            <button type="submit" class="btn-sm">Skip &amp; Mark Step 12 Complete</button>
          </form>
        <?php endif; ?>
      </div>
    </details>
    <?php if ($canManageIncentives): // Owner decision 2026-10-30: Step 12d, manage_incentives only. ?>
      <div class="step-group-connector">and the team's incentive</div>
      <?php require __DIR__ . '/_incentive.php'; ?>
    <?php endif; ?>
  </details>
  <?php endif; ?>

  <?php
    $despatchLocked = !$stage9 || $stage9['status'] === 'locked';
    // 90-item backlog #57 / Phase 6 Item 3: computed once here and
    // reused below to warn staff before they try to close — this is
    // now a real server-side hard gate too (see
    // DocumentGenerationService::closureBlockedBy()'s docblock and
    // OrderController::closeOrder()), not just a display-only hint.
    $__closeBlockedBy = $despatchLocked ? [] : DocumentGenerationService::closureBlockedBy((int) $order['id']);
    // Owner sequence (2026-10-26): 13c save the endorsement, 13d print it,
    // 13e mark the originals endorsed, 13f close, 13g government benefits
    // (owner decision 2026-10-27), 13h fully settled.
  ?>

  <details class="step-group" id="step-group-stage-9"<?= $stepCardOpen('13') ?>>
    <summary><?= $stepStatusIcon($stepStates['13']) ?><span class="step-group-num">STEP 13</span><h3>Closure &mdash; Documents &amp; Settlement</h3></summary>

  <details class="step-card" id="step-11a-coo-prep"<?= $subCardOpen('13', '13a') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('13', '13a')) ?><h2>Step 13a &middot; Generate COO Prep Sheet</h2></summary>
    <?php if ($despatchLocked): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php else: ?>
      <h3 style="font-size:0.95em; margin-top:0;">COO Prep Sheet (internal — for the Chamber of Commerce application)</h3>
      <p class="muted small">Generates an internal worksheet summarizing everything needed to apply for the Certificate of Origin with the Chamber of Commerce — shipper, consignee, shipment, and cargo details, the same source data as the BL Instruction Sheet. Never shown to the client.</p>
      <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="COOPREP">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('13a') ?><button type="submit" class="<?= $__docBtnClass('COOPREP') ?>" data-loading-text="Generating…">Generate COO Prep Sheet</button><?= $__docAlreadyNote('COOPREP') ?>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once the CHA delivers the original BLs</div>

  <details class="step-card" id="step-11b-bl-originals-received"<?= $subCardOpen('13', '13b') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('13', '13b')) ?><h2>Step 13b &middot; Record Original BLs Received from CHA</h2></summary>
    <?php if ($despatchLocked): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php elseif (!$canManageShipping): ?>
      <p class="muted">Only Logistics can record the original BLs.</p>
    <?php elseif (!$shipping || !$shipping['bl_originals_received_at']): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-originals-received">
        <?= Csrf::field() ?>
        <label>No. of Original BL Copies Received<input type="number" name="bl_originals_count" value="3"></label>
        <?= $stepBadge('13b') ?><button type="submit" class="btn-sm">Record Original BLs Received from CHA</button>
      </form>
    <?php else: ?>
      <p class="muted small">Original BLs received (<?= (int) $shipping['bl_originals_received_count'] ?>).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">write up the endorsement</div>

  <details class="step-card" id="step-11d-bl-endorsement-save"<?= $subCardOpen('13', '13c') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('13', '13c')) ?><h2>Step 13c &middot; Save BL Endorsement Details</h2></summary>
    <?php if ($despatchLocked): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php elseif ($canManageShipping && $shipping && $shipping['bl_originals_received_at']): ?>
      <p class="muted small">Save these details, then generate the printable endorsement — it states who to endorse the BL to ("Pay to the order of"), the BL/vessel/voyage, both ports, the latest PI/CI references, and the authorised signatory, exactly as it should be written or stamped on the reverse of each original BL.</p>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-endorsement">
        <?= Csrf::field() ?>
        <label>BL Number<input type="text" name="bl_number" value="<?= htmlspecialchars($blEndorsement['bl_number'] ?? $shipping['bl_number'] ?? '') ?>"></label>
        <label>Vessel / Voyage<input type="text" name="vessel_voyage" placeholder="e.g. MSC MAYA / 012W" value="<?= htmlspecialchars($blEndorsement['vessel_voyage'] ?? (trim(($shipping['vessel_name'] ?? '') . ' / ' . ($shipping['voyage_number'] ?? '')) !== '/' ? trim(($shipping['vessel_name'] ?? '') . ' / ' . ($shipping['voyage_number'] ?? '')) : '')) ?>"></label>
        <label>Port of Loading<input type="text" name="port_of_loading" value="<?= htmlspecialchars($blEndorsement['port_of_loading'] ?? $order['port_of_loading_name'] ?? 'Chennai, India') ?>"></label>
        <label>Port of Discharge<input type="text" name="port_of_discharge" value="<?= htmlspecialchars($blEndorsement['port_of_discharge'] ?? $order['port_of_discharge_name'] ?? $order['port_of_discharge_text'] ?? '') ?>"></label>
        <label>Date of Endorsement<input type="date" name="date_of_endorsement" value="<?= htmlspecialchars($blEndorsement['date_of_endorsement'] ?? date('Y-m-d')) ?>"></label>
        <?= $stepBadge('13c') ?><button type="submit" class="btn-sm">Save BL Endorsement Details</button>
      </form>
    <?php else: ?>
      <p class="muted">Record the original BLs received first (Step 13b).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">print the endorsement for the courier pack</div>

  <details class="step-card" id="step-11f-bl-endorsement-generate"<?= $subCardOpen('13', '13d') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('13', '13d')) ?><h2>Step 13d &middot; Generate / Print BL Endorsement</h2></summary>
    <?php if ($despatchLocked): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php elseif ($blEndorsement): ?>
      <?php if ($canManageShipping): // QA Round 5, R5-20 ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-endorsement/generate" style="margin-top:8px;">
        <?= Csrf::field() ?>
        <?= $stepBadge('13d') ?><button type="submit" class="btn-sm btn-success">Generate / Print BL Endorsement</button>
      </form>
      <?php endif; ?>
      <?php if ($blEndorsementDoc): ?>
        <p class="small" style="margin-top:6px;">
          Latest: <?php if ($canDownloadPdf): ?><a href="/documents/<?= (int) $blEndorsementDoc['id'] ?>/download?format=pdf"><?= htmlspecialchars($blEndorsementDoc['document_reference']) ?></a><?php else: ?><?= htmlspecialchars($blEndorsementDoc['document_reference']) ?><?php endif; ?>
          (generated <?= htmlspecialchars((string) $blEndorsementDoc['generated_at']) ?>)
        </p>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Save BL Endorsement Details first (Step 13c).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">endorse the original BLs over to the buyer's bank</div>

  <details class="step-card" id="step-11c-bl-endorsed-mark"<?= $subCardOpen('13', '13e') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('13', '13e')) ?><h2>Step 13e &middot; Mark Original BLs Endorsed by NexaCrest</h2></summary>
    <?php if ($despatchLocked): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php elseif ($canManageShipping && $shipping && $shipping['bl_originals_received_at'] && !$shipping['bl_endorsed_at']): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-endorsed">
        <?= Csrf::field() ?>
        <?= $stepBadge('13e') ?><button type="submit" class="btn-sm">Mark Original BLs Endorsed by NexaCrest</button>
      </form>
    <?php elseif ($shipping && $shipping['bl_endorsed_at']): ?>
      <p class="muted small">Original BLs endorsed.</p>
    <?php else: ?>
      <p class="muted">Record the original BLs received first (Step 13b).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once documents are despatched to the buyer</div>

  <details class="step-card" id="step-11g-close-order"<?= $subCardOpen('13', '13f') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('13', '13f')) ?><h2>Step 13f &middot; Close Order (Document Despatch)</h2></summary>
    <?php if ($orderClosed || $order['status'] === 'shipped_docs_sent'): ?>
      <p><strong>&#10003; Documents despatched.</strong> Courier tracking: <?= htmlspecialchars($shipping['courier_tracking_number'] ?? '—') ?> — see Step 13g (government benefits) and 13h to mark this order fully settled.</p>
    <?php elseif ($despatchLocked): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php elseif (!$canCloseOrders): ?>
      <p class="muted">Only Export/Logistics can close this order.</p>
    <?php elseif (!empty($__closeBlockedBy)): ?>
      <p class="notice notice-error">⚠ Before closing: generate and get approval for <?= htmlspecialchars(implode(' and ', $__closeBlockedBy)) ?> above. Close Order is blocked until then<?= $canEditLockedData ? ' — or override below with a reason.' : '.' ?></p>
      <?php if ($canEditLockedData): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/close" onsubmit="return confirmFieldOverride(this, 'closing this order without both required documents approved');" style="border:1px solid #D9822B; padding:10px; border-radius:4px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="override_gate" value="1">
        <label>Courier Tracking Number *<input type="text" name="courier_tracking_number" required></label>
        <label>Reason for this override * <small class="muted">(minimum 10 characters — recorded in the audit log)</small>
          <textarea class="override-reason" name="override_reason" rows="2" required minlength="10"></textarea>
        </label>
        <?= $stepBadge('13f') ?><button type="submit" class="btn-sm btn-danger">Override &amp; Close Order</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/close">
        <?= Csrf::field() ?>
        <label>Courier Tracking Number *<input type="text" name="courier_tracking_number" required></label>
        <?= $stepBadge('13f') ?><button type="submit" class="btn-sm btn-success">Close Order — Complete Document Set Couriered to Buyer</button>
      </form>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">then the government benefits — they usually arrive after the buyer has paid</div>

  <details class="step-card" id="step-13g-benefits"<?= $subCardOpen('13', '13g') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('13', '13g')) ?><h2>Step 13g &middot; Government Benefits &mdash; Claimed &amp; Received</h2><span class="fin-sub-summary"><?php if ($benefitSettlement['waiting'] !== []): ?><span class="phase-pill warn"><?= count($benefitSettlement['waiting']) ?> waiting for money</span><?php elseif ($benefitSettlement['unclaimed'] !== []): ?><?= count($benefitSettlement['unclaimed']) ?> not claimed yet<?php elseif ($benefitSettlement['undecided'] !== []): ?>eligibility not answered (12b)<?php else: ?>all received or skipped<?php endif; ?></span></summary>
    <?php if (!$orderClosed && $order['status'] !== 'shipped_docs_sent'): ?>
      <p class="muted">Not applicable until the order is complete — claims are recorded here once the documents are sent to the buyer (Step 13f) and the balance has landed.</p>
    <?php else: ?>
    <?php if ($benefitSettlement['undecided'] !== []): ?>
      <p class="review-banner warn">&#9888; Answer the scheme eligibility first (Step 12b): <?= htmlspecialchars(implode(', ', $benefitSettlement['undecided'])) ?>.</p>
    <?php endif; ?>
    <?php if (!$canManageOrderFinancials): ?>
      <table class="list">
        <tr><th>Scheme</th><th>Status</th></tr>
        <?php foreach ($benefitSettlement['schemes'] as $__s): ?>
          <?php if ($__s['settlement'] === 'not_eligible') { continue; } ?>
          <tr><td><?= htmlspecialchars($__s['scheme_name']) ?></td><td><?= htmlspecialchars(['undecided' => 'Eligibility not answered', 'skipped' => 'Skipped', 'received' => 'Received', 'waiting' => 'Claimed — waiting for money', 'unclaimed' => 'Not claimed yet'][$__s['settlement']]) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <p class="muted small">Recorded by users with the "Manage order financials" permission.</p>
    <?php else: ?>
      <p class="muted small">The government benefits usually arrive after the buyer has paid in full. One row per claim, plus an empty row for each eligible scheme not claimed yet: fill in the claim when you file it and the <strong>received amount when the money lands</strong>, then <strong>Save Benefits</strong> — or skip a scheme you will not claim (reason required). The order can be marked fully settled (13h) once every eligible scheme is received or skipped.</p>
      <?php if (!$__benefitsApplicable): ?>
        <p class="review-banner warn">&#9888; Not applicable until the order is complete and the CI (balance) remittance has been received — claims are recorded here once the documents are sent to the buyer (13f) and the balance has landed.</p>
      <?php elseif ($__finEligible === [] && $orderBenefitClaims === []): ?>
        <p class="muted"><?= $__finPending > 0 ? 'Answer the eligibility in 12b first — eligible schemes appear here.' : 'No scheme is eligible on this order — nothing to claim.' ?></p>
      <?php else: ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/export-benefits/save-all">
        <?= Csrf::field() ?>
        <table class="list fin-benefits">
          <tr><th>Scheme</th><th>Claimed (&#8377;)</th><th>Received (&#8377;)</th></tr>
          <?php $__row = 0; ?>
          <?php foreach ($orderBenefitClaims as $b): ?>
          <tr>
            <td><strong><?= htmlspecialchars($b['scheme_name']) ?></strong><input type="hidden" name="benefits[<?= $__row ?>][benefit_id]" value="<?= (int) $b['id'] ?>"><br><?= $b['received_amount'] !== null ? '<span class="badge good">Received</span>' : '<span class="badge">Claimed — waiting</span>' ?></td>
            <td><?= number_format((float) $b['claimed_amount'], 2) ?><br><span class="muted small"><?= htmlspecialchars($b['reference_number'] ?? 'no ref.') ?> · <?= htmlspecialchars((string) $b['claimed_at']) ?></span></td>
            <?php if ($b['received_amount'] !== null): ?>
            <td><?= number_format((float) $b['received_amount'], 2) ?><br><span class="muted small">on <?= htmlspecialchars((string) $b['received_at']) ?></span></td>
            <?php else: ?>
            <td class="fin-claim-cell">
              <input type="number" step="0.01" min="0" name="benefits[<?= $__row ?>][received_amount]" placeholder="Amount received" aria-label="<?= htmlspecialchars($b['scheme_name']) ?> received amount">
              <input type="date" name="benefits[<?= $__row ?>][received_at]" value="<?= date('Y-m-d') ?>" aria-label="<?= htmlspecialchars($b['scheme_name']) ?> received on">
            </td>
            <?php endif; ?>
          </tr>
          <?php $__row++; endforeach; ?>
          <?php foreach ($__finEligible as $sch): ?>
            <?php if (in_array($sch['scheme_name'], $__finClaimedNames, true)) { continue; } ?>
          <tr>
            <td><strong><?= htmlspecialchars($sch['scheme_name']) ?></strong><input type="hidden" name="benefits[<?= $__row ?>][scheme_name]" value="<?= htmlspecialchars($sch['scheme_name']) ?>"><br><span class="badge warn">Not claimed</span></td>
            <td class="fin-claim-cell">
              <input type="number" step="0.01" min="0" name="benefits[<?= $__row ?>][claimed_amount]" placeholder="Amount claimed" aria-label="<?= htmlspecialchars($sch['scheme_name']) ?> claimed amount"<?= $__benefitsDisabled ?>>
              <input type="text" name="benefits[<?= $__row ?>][reference_number]" placeholder="Shipping bill / scroll no." aria-label="<?= htmlspecialchars($sch['scheme_name']) ?> claim reference"<?= $__benefitsDisabled ?>>
              <input type="date" name="benefits[<?= $__row ?>][claimed_at]" value="<?= date('Y-m-d') ?>" aria-label="<?= htmlspecialchars($sch['scheme_name']) ?> claimed on"<?= $__benefitsDisabled ?>>
            </td>
            <td class="fin-claim-cell">
              <input type="number" step="0.01" min="0" name="benefits[<?= $__row ?>][received_amount]" placeholder="If already received" aria-label="<?= htmlspecialchars($sch['scheme_name']) ?> received amount"<?= $__benefitsDisabled ?>>
              <input type="date" name="benefits[<?= $__row ?>][received_at]" value="<?= date('Y-m-d') ?>" aria-label="<?= htmlspecialchars($sch['scheme_name']) ?> received on"<?= $__benefitsDisabled ?>>
            </td>
          </tr>
          <?php $__row++; endforeach; ?>
        </table>
        <button type="submit" class="btn-sm"<?= $__benefitsApplicable || $__finWaiting > 0 ? '' : ' disabled' ?>>Save Benefits</button>
      </form>
      <?php endif; ?>
      <?php // Owner request (2026-10-27): a wrongly typed claim can be corrected or deleted (reason required, audit-logged). ?>
      <?php if (!empty($orderBenefitClaims) && $__benefitsApplicable): ?>
      <details class="fin-more-claim" id="step-13g-corrections">
        <summary class="muted small">Correct or delete a recorded claim (typed a wrong amount? fix it here — a reason is required)</summary>
        <?php foreach ($orderBenefitClaims as $b): ?>
          <div class="card-nested fin-correct-claim">
            <strong><?= htmlspecialchars($b['scheme_name']) ?></strong> <span class="muted small">— claimed &#8377;<?= number_format((float) $b['claimed_amount'], 2) ?> on <?= htmlspecialchars((string) $b['claimed_at']) ?><?= $b['received_amount'] !== null ? ', received &#8377;' . number_format((float) $b['received_amount'], 2) : '' ?></span>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/export-benefits/<?= (int) $b['id'] ?>/correct" class="field-grid">
              <?= Csrf::field() ?>
              <label>Claimed (&#8377;) *<input type="number" step="0.01" min="0.01" name="claimed_amount" required value="<?= htmlspecialchars((string) $b['claimed_amount']) ?>"></label>
              <label>Claimed On *<input type="date" name="claimed_at" required value="<?= htmlspecialchars((string) $b['claimed_at']) ?>"></label>
              <label>Claim Ref.<input type="text" name="reference_number" value="<?= htmlspecialchars((string) ($b['reference_number'] ?? '')) ?>"></label>
              <label>Received (&#8377;) <small class="muted">(empty = not received yet)</small><input type="number" step="0.01" min="0" name="received_amount" value="<?= htmlspecialchars((string) ($b['received_amount'] ?? '')) ?>"></label>
              <label>Received On<input type="date" name="received_at" value="<?= htmlspecialchars((string) ($b['received_at'] ?? date('Y-m-d'))) ?>"></label>
              <label class="full">Reason for the correction *<input type="text" name="reason" required minlength="10" placeholder="e.g. Typed 50,000 instead of 5,000"></label>
              <div><button type="submit" class="btn-sm">Save Correction</button></div>
            </form>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/export-benefits/<?= (int) $b['id'] ?>/delete" class="fin-delete-claim" onsubmit="return confirm('Delete this <?= htmlspecialchars($b['scheme_name'], ENT_QUOTES) ?> claim? This cannot be undone (it stays in the audit log).');">
              <?= Csrf::field() ?>
              <input type="text" name="reason" required minlength="10" placeholder="Reason for deleting (e.g. recorded twice by mistake)" aria-label="Reason for deleting the <?= htmlspecialchars($b['scheme_name']) ?> claim">
              <button type="submit" class="btn-sm btn-danger">Delete Claim</button>
            </form>
          </div>
        <?php endforeach; ?>
      </details>
      <?php endif; ?>
      <?php if ($__benefitsApplicable): ?>
      <details class="fin-more-claim">
        <summary class="muted small">Record another claim (a second claim on the same scheme, or a scheme not listed above)</summary>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/export-benefits">
            <?= Csrf::field() ?>
            <label>Scheme *
              <select name="scheme_name" required>
                <?php foreach ($exportBenefitSchemes as $opt): ?>
                  <option value="<?= htmlspecialchars($opt['option_value']) ?>"><?= htmlspecialchars($opt['option_value']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Reference Number (shipping bill / scroll no.)<input type="text" name="reference_number"></label>
            <label>Claimed Amount *<input type="text" name="claimed_amount" required></label>
            <label>Claimed On *<input type="date" name="claimed_at" value="<?= date('Y-m-d') ?>" required></label>
            <label>Currency<input type="text" name="currency_code" value="INR"></label>
            <label>Notes<input type="text" name="notes"></label>
            <button type="submit" class="btn-sm">Record Claim</button>
          </form>
      </details>
      <?php endif; ?>

      <?php // Owner decision (2026-10-27): skip a scheme the company won't claim (or whose money will never come). ?>
      <?php $__skippable = array_merge($benefitSettlement['unclaimed'], $benefitSettlement['waiting']); ?>
      <?php $__skippedRows = array_values(array_filter($benefitSettlement['schemes'], static fn($r) => $r['settlement'] === 'skipped')); ?>
      <?php if ($__skippedRows !== []): ?>
        <table class="list">
          <tr><th>Skipped scheme</th><th>Reason</th><th></th></tr>
          <?php foreach ($__skippedRows as $__s): ?>
          <tr>
            <td><strong><?= htmlspecialchars($__s['scheme_name']) ?></strong><br><span class="badge">Skipped</span></td>
            <td class="small"><?= htmlspecialchars((string) $__s['claim_skip_reason']) ?></td>
            <td>
              <form method="post" action="/orders/<?= (int) $order['id'] ?>/export-benefits/skip">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="undo">
                <input type="hidden" name="scheme_name" value="<?= htmlspecialchars($__s['scheme_name']) ?>">
                <button type="submit" class="btn-sm btn-secondary">Undo skip</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <?php if ($__skippable !== []): ?>
      <details class="fin-more-claim" id="step-13g-skip">
        <summary class="muted small">Not claiming a scheme, or its money will never come? Skip it here (reason required)</summary>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/export-benefits/skip" class="field-grid">
          <?= Csrf::field() ?>
          <label>Scheme
            <select name="scheme_name">
              <?php foreach ($__skippable as $__name): ?>
                <option value="<?= htmlspecialchars($__name) ?>"><?= htmlspecialchars($__name) ?></option>
              <?php endforeach; ?>
              <?php if (count($__skippable) > 1): ?><option value="">All <?= count($__skippable) ?> still open</option><?php endif; ?>
            </select>
          </label>
          <label class="full">Reason *<input type="text" name="reason" required minlength="10" placeholder="e.g. Below the minimum claim amount — not worth filing"></label>
          <div><button type="submit" class="btn-sm btn-secondary">Skip — not claiming</button></div>
        </form>
      </details>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!$benefitSettlement['done'] && $order['status'] === 'shipped_docs_sent' && $canCloseOrders && $canEditLockedData): ?>
      <details>
        <summary class="small">Override &amp; fully settle anyway (a benefit genuinely never arriving)</summary>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/mark-fully-settled" onsubmit="return confirmFieldOverride(this, 'fully settling this order with a benefit claim still outstanding');" style="border:1px solid #D9822B; padding:10px; border-radius:4px;">
          <?= Csrf::field() ?>
          <input type="hidden" name="override_gate" value="1">
          <label>Reason for this override * <small class="muted">(minimum 10 characters — recorded in the audit log)</small>
            <textarea class="override-reason" name="override_reason" rows="2" required minlength="10"></textarea>
          </label>
          <button type="submit" class="btn-sm btn-danger">Override &amp; Mark Fully Settled</button>
        </form>
      </details>
    <?php endif; ?>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">last — once every benefit is received or skipped</div>

  <details class="step-card" id="step-despatch"<?= $subCardOpen('13', '13h') ?>>
    <summary><?= $stepStatusIcon($subStepStatus('13', '13h')) ?><h2>Step 13h &middot; Mark Fully Settled</h2></summary>
    <?php if ($orderClosed): ?>
      <p><strong>&#10003; Order fully settled and closed.</strong> Courier tracking: <?= htmlspecialchars($shipping['courier_tracking_number'] ?? '—') ?></p>
    <?php elseif ($order['status'] === 'shipped_docs_sent'): ?>
      <p><strong>&#10003; Documents sent to buyer.</strong> Courier tracking: <?= htmlspecialchars($shipping['courier_tracking_number'] ?? '—') ?> — this order now shows as done to the client, but stays open internally for government export benefits (see Order Financials above).</p>
      <?php if ($canCloseOrders): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/mark-fully-settled" onsubmit="return confirm('Mark this order fully settled and close it? This is the final, locked close.');">
        <?= Csrf::field() ?>
        <?= $stepBadge('13h') ?><button type="submit" class="btn-sm btn-success">Mark Fully Settled</button>
      </form>
      <p class="muted small">Blocked until Step 13g is done — every eligible government export benefit received, or skipped with a reason.</p>
      <?php
        // Owner decision (2026-10-27): unpaid supplier / logistics bills are a
        // warning here, not a block — accounts may settle vendor bills later.
        $__unpaidBills = array_values(array_filter($vendorBills, static fn (array $b): bool => !$b['settled']));
      ?>
      <?php if ($__unpaidBills !== []): ?>
        <p class="review-banner warn" id="unpaid-vendor-bills-warning">&#9888; <?= count($__unpaidBills) ?> vendor bill<?= count($__unpaidBills) === 1 ? '' : 's' ?> still unpaid: &#8377;<?= number_format(array_sum(array_map(static fn (array $b): float => $b['remaining'], $__unpaidBills)), 2) ?> due
          (<?= htmlspecialchars(implode(', ', array_map(static fn (array $b): string => ($b['vendor_kind'] === 'supplier' ? 'Supplier' : (string) ($b['partner_name'] ?? 'Logistics')) . ' ' . $b['bill_no'], $__unpaidBills))) ?>) — you can still mark the order fully settled.</p>
      <?php endif; ?>
      <?php if ($canEditLockedData): ?>
      <details>
        <summary class="small">Override &amp; fully settle anyway (benefit genuinely never arriving)</summary>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/mark-fully-settled" onsubmit="return confirmFieldOverride(this, 'fully settling this order with a benefit claim still outstanding');" style="border:1px solid #D9822B; padding:10px; border-radius:4px;">
          <?= Csrf::field() ?>
          <input type="hidden" name="override_gate" value="1">
          <label>Reason for this override * <small class="muted">(minimum 10 characters — recorded in the audit log)</small>
            <textarea class="override-reason" name="override_reason" rows="2" required minlength="10"></textarea>
          </label>
          <button type="submit" class="btn-sm btn-danger">Override &amp; Mark Fully Settled</button>
        </form>
      </details>
      <?php endif; ?>
      <?php else: ?>
      <p class="muted">Only Export/Logistics can mark this order fully settled.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Close the order first (Step 13f).</p>
    <?php endif; ?>
  </details>

  </details>

  <details class="step-card" id="step-complete"<?= $stepCardOpen('19') ?>>
    <summary><?= $stepStatusIcon($stepStates['19']) ?><span class="step-group-num">STEP 19</span><h2>Order Complete</h2></summary>
    <?php if ($orderClosed): ?>
      <p><strong>&#10003; This order is fully settled and closed.</strong> Nothing further is needed.</p>
    <?php else: ?>
      <p class="muted">Not yet complete — finish Step 13h above (Mark Fully Settled) to close this order.</p>
    <?php endif; ?>
  </details>

  </details>

  <details class="phase-group" id="phase-aftersales"<?= $phaseOpen('aftersales') ?>>
    <?= $phaseSummary('aftersales', ($openComplaintCount > 0 ? '<span class="phase-pill warn">' . (int) $openComplaintCount . ' open complaint' . ($openComplaintCount === 1 ? '' : 's') . '</span>' : '') . ($openDisputeCount > 0 ? '<span class="phase-pill warn">' . (int) $openDisputeCount . ' open dispute' . ($openDisputeCount === 1 ? '' : 's') . '</span>' : '')) ?>

  <?php if ($canManageOrders): ?>
  <details class="step-card step-card-reference" id="amendments">
    <summary><h2>Amendments <small class="muted">(reference — mutually-agreed term changes)</small></h2></summary>
    <p class="muted">Mutually-agreed changes to this order's terms (payment terms, pricing, quantity, etc), each going through its own maker-checker approval — a different process from a buyer complaint or claim, which belongs under Disputes.</p>
    <p><a href="/orders/<?= (int) $order['id'] ?>/amendments">Payment Terms Amendments (<?= (int) $amendmentCount ?>)</a></p>
  </details>
  <?php endif; ?>

  <details class="step-card" id="step-additional-documents"<?= $stepCardOpen('14') ?>>
    <summary><?= $stepStatusIcon($stepStates['14']) ?><span class="step-group-num">STEP 14</span><h2>Additional Documents</h2></summary>
    <p class="muted small">Free-form extras that don't fit any fixed document type — e.g. a buyer-supplied certificate template, a special packing instruction sheet. Every generated document for this order is listed in the sidebar; this is only for files that were never one of the fixed types above.</p>
    <table class="list">
      <tr><th>Title</th><th>Notes</th><th>File</th><th>Uploaded</th><th></th></tr>
      <?php foreach ($additionalDocuments as $ad): ?>
      <tr>
        <td><?= htmlspecialchars($ad['document_type_label'] ?? '') ?></td>
        <td class="muted small"><?= htmlspecialchars($ad['notes'] ?? '') ?></td>
        <td><a href="/file-store/<?= (int) $ad['id'] ?>/download"><?= htmlspecialchars($ad['original_filename']) ?></a></td>
        <td><?= htmlspecialchars((string) $ad['uploaded_at']) ?><?php if (!empty($ad['uploaded_by_name'])): ?> <span class="muted small">by <?= htmlspecialchars($ad['uploaded_by_name']) ?></span><?php endif; ?></td>
        <td>
          <?php if ($canManageOrders): ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/additional-documents/<?= (int) $ad['id'] ?>/delete" style="display:inline" onsubmit="return confirmRemoveAdditionalDocument(this);">
            <?= Csrf::field() ?>
            <input type="hidden" name="reason" class="remove-additional-document-reason">
            <button type="button" class="btn-sm btn-danger" onclick="promptRemoveAdditionalDocument(this)">Remove</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($additionalDocuments)): ?>
      <tr><td colspan="5" class="muted">No additional documents attached yet.</td></tr>
      <?php endif; ?>
    </table>
    <?php if ($canManageOrders): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/additional-documents" enctype="multipart/form-data" style="margin-top:6px;display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
      <?= Csrf::field() ?>
      <input type="text" name="title" placeholder="Title *" required style="width:220px">
      <input type="text" name="notes" placeholder="Notes (optional)" style="width:260px">
      <input type="file" name="document" required>
      <button type="submit" class="btn-sm">Attach Document</button>
    </form>
    <?php endif; ?>
    <?php if ($canManageOrders): ?><p class="muted small" style="margin-top:8px;"><a href="/orders/<?= (int) $order['id'] ?>/additional-documents/removed">Show removed</a></p><?php endif; ?>
  </details>

  <details class="step-card" id="order-feedback"<?= $stepCardOpen('16') ?>>
    <summary><?= $stepStatusIcon($stepStates['16']) ?><span class="step-group-num">STEP 16</span><h2>Order Feedback</h2></summary>
    <?php if (empty($orderFeedback)): ?>
      <p class="muted">The client hasn't submitted feedback for this order yet.</p>
      <?php if ($canManageOrders && in_array($order['status'], ['shipped_docs_sent', 'complete'], true)): ?>
        <p class="muted small">If the client told you how the order went over the phone or in person instead of using the portal, record it here on their behalf.</p>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/feedback">
          <?= Csrf::field() ?>
          <label>Rating
            <select name="rating" required>
              <option value="">Choose a rating</option>
              <?php for ($i = 5; $i >= 1; $i--): ?>
                <option value="<?= $i ?>"><?= str_repeat('★', $i) ?><?= str_repeat('☆', 5 - $i) ?> (<?= $i ?>/5)</option>
              <?php endfor; ?>
            </select>
          </label>
          <label>Feedback / testimonial
            <textarea name="comments" rows="4"><?= htmlspecialchars($testimonialSuggestion ?? '') ?></textarea>
          </label>
          <p class="muted small">A suggested testimonial written from this order's details — change it to what the client actually told you before recording it.</p>
          <button type="submit" class="btn-sm">Record Feedback</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <div class="kv-grid">
        <div><span class="k">Rating</span><span class="v"><?= str_repeat('★', (int) $orderFeedback['rating']) ?><?= str_repeat('☆', 5 - (int) $orderFeedback['rating']) ?> (<?= (int) $orderFeedback['rating'] ?>/5)</span></div>
        <div><span class="k">Submitted</span><span class="v"><?= htmlspecialchars(Dates::human($orderFeedback['submitted_at'])) ?></span></div>
        <div class="full"><span class="k">Comments</span><span class="v"><?= $orderFeedback['comments'] ? nl2br(htmlspecialchars($orderFeedback['comments'])) : '—' ?></span></div>
      </div>
    <?php endif; ?>
  </details>

  <details class="step-card" id="complaints"<?= $stepCardOpen('17') ?>>
    <summary><?= $stepStatusIcon($stepStates['17']) ?><span class="step-group-num">STEP 17</span><h2>Complaints</h2></summary>
    <p class="muted">A process issue or flaw the client flagged for us to fix next time — separate from Disputes below, and always available to the client regardless of the Dispute toggle.</p>
    <p><?= $canViewOrderComplaints ? '<a href="/orders/' . (int) $order['id'] . '/complaints">Complaints (' . (int) $openComplaintCount . ' open)</a>' : 'Complaints (' . (int) $openComplaintCount . ' open)' ?></p>
  </details>

  <details class="step-card" id="disputes"<?= $stepCardOpen('18') ?>>
    <summary><?= $stepStatusIcon($stepStates['18']) ?><span class="step-group-num">STEP 18</span><h2>Disputes</h2></summary>
    <p class="muted">A buyer wanting to cancel this order and not proceed with us — separate from a Complaint above (no cancel intent) and from an Amendment, which is a planned, mutually-agreed change to order terms.</p>
    <?php if ($canManageDisputes): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/dispute-visibility" style="margin-bottom:10px">
      <?= Csrf::field() ?>
      <label style="display:inline-flex;align-items:center;gap:0.5rem;font-weight:normal">
        <input type="checkbox" name="dispute_button_visible_to_client" value="1" onchange="this.form.submit()" <?= (int) $order['dispute_button_visible_to_client'] === 1 ? 'checked' : '' ?>>
        Show "Raise a Dispute" button to the client in their portal for this order
      </label>
    </form>
    <?php endif; ?>
    <p><?= $canViewOrderDisputes ? '<a href="/orders/' . (int) $order['id'] . '/disputes">Disputes (' . (int) $openDisputeCount . ' open)</a>' : 'Disputes (' . (int) $openDisputeCount . ' open)' ?>
       <?php if ($canViewAuditLog): ?>
       &nbsp;·&nbsp;
       <a href="/orders/<?= (int) $order['id'] ?>/audit-log">Audit Log</a>
       <?php endif; ?>
       <?php if ($canManageOrders): ?>
       &nbsp;·&nbsp;
       <a href="/orders/<?= (int) $order['id'] ?>/dossier" class="js-slow-download" data-loading-text="Building ZIP…">Download Full Dossier (ZIP)</a>
       <?php endif; ?>
    </p>
  </details>

  </details>

  <?php if ($canEditLockedData): ?>
  <details class="step-card step-card-reference">
    <summary><h2>Admin Override</h2></summary>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/override-status-lock" onsubmit="return confirmFieldOverride(this, 'this order\'s status/lock');">
      <?= Csrf::field() ?>
      <label>Status
        <select name="status">
          <option value="active" <?= $order['status'] === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="shipped_docs_sent" <?= $order['status'] === 'shipped_docs_sent' ? 'selected' : '' ?>>Shipped &mdash; Docs Sent</option>
          <option value="complete" <?= $order['status'] === 'complete' ? 'selected' : '' ?>>Complete</option>
          <option value="disputed" <?= $order['status'] === 'disputed' ? 'selected' : '' ?>>Disputed</option>
          <option value="lost" <?= $order['status'] === 'lost' ? 'selected' : '' ?>>Lost</option>
          <option value="cancelled" <?= $order['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
      </label>
      <label><input type="checkbox" name="is_locked" value="1" style="display:inline-block;width:auto;" <?= $order['is_locked'] ? 'checked' : '' ?>> Locked (normally set automatically once status = complete)</label>
      <label>Reason for this change * <textarea class="override-reason" name="reason" rows="2" required></textarea></label>
      <button type="submit" class="btn-sm btn-danger">Override Status/Lock</button>
    </form>
  </details>
  <script>
  function confirmFieldOverride(form, label) {
    var reasonEl = form.querySelector('.override-reason');
    if (!reasonEl || reasonEl.value.trim() === '') {
      alert('A reason is required before saving.');
      return false;
    }
    return confirm('Override ' + label + '? This is logged and cannot be undone through this screen.');
  }
  </script>
  <?php endif; ?>

  <?php if ($canEditLockedData): ?>
  <details class="step-card step-card-reference" style="border-color:#D9822B;">
    <summary><h2>Force-Generate a Document (privileged override)</h2></summary>
    <p class="muted small">Bypasses the normal stage sequence and, if this order is locked/complete, the lock itself. Use only to correct a genuine mistake — every use is logged with your reason against this order's audit trail.</p>
    <?php if ($canGenerateDocuments): // QA Round 5, R5-20 ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" onsubmit="return confirmDocumentRegeneration(this) && confirmFieldOverride(this, 'generating this document out of sequence');">
      <?= Csrf::field() ?>
      <input type="hidden" name="override_gate" value="1">
      <label>Document Type
        <select name="document_type" required>
          <?php foreach (['QT' => 'Quotation', 'BUYERPO' => 'Buyer PO', 'PI' => 'Proforma Invoice', 'OC' => 'Order Confirmation', 'SUPPO' => 'Supplier PO', 'FDN' => 'Debit Note', 'PL' => 'Packing List', 'BLI' => 'BL Instruction Sheet', 'CI' => 'Commercial Invoice', 'COOPREP' => 'COO Prep Sheet (internal)'] as $code => $label): ?>
            <option value="<?= $code ?>"><?= htmlspecialchars($label) ?> (<?= $code ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> Also generate DOCX</label>
      <label>Reason for this override * <small class="muted">(minimum 10 characters — recorded in the audit log)</small>
        <textarea class="override-reason" name="override_reason" rows="2" required minlength="10"></textarea>
      </label>
      <button type="submit" class="btn-sm btn-danger" data-loading-text="Generating…">Force-Generate</button>
    </form>
    <?php endif; ?>
  </details>
  <?php endif; ?>

    </div>

    <aside class="order-detail-sidebar" id="order-detail-sidebar">
      <div class="sidebar-panel sidebar-glance">
        <h3>At a Glance</h3>
        <div class="kv-grid">
          <div><span class="k">Current Stage</span><span class="v"><?= $currentStage ? 'Stage ' . (int) $currentStage['stage_number'] . ' — ' . htmlspecialchars($currentStage['stage_name']) : ($orderClosed ? 'Complete — all 9 stages resolved' : '—') ?></span></div>
          <div><span class="k">Products</span><span class="v"><?= count($products) ?> line<?= count($products) === 1 ? '' : 's' ?></span></div>
          <div><span class="k">Payment Legs</span><span class="v"><?= $paymentLegsResolved ?> of <?= $paymentLegsApplicable ?> cleared<?= $paymentLegsApplicable === 0 ? ' (nothing recorded yet)' : '' ?> — <a href="#payment-overview">see Payment Overview</a></span></div>
          <?php if ($canCloseOrders): ?>
          <div><span class="k">Compliance Checklist</span><span class="v"><?= $complianceSummary['total'] === 0 ? 'No task types configured' : $complianceSummary['approved'] . ' of ' . $complianceSummary['total'] . ' resolved' ?> — <a href="#compliance-checklist">see checklist</a></span></div>
          <?php endif; ?>
          <div><span class="k">Documents Generated</span><span class="v"><?= count($documents) ?></span></div>
          <div><span class="k">Client</span><span class="v"><?php if ($canOpenClient): ?><a href="/clients/<?= (int) $order['client_id'] ?>"><?= htmlspecialchars($order['company_legal_name']) ?></a><?php else: ?><?= htmlspecialchars($order['company_legal_name']) ?><?php endif; ?></span></div>
          <div><span class="k">Buyer Inquiry Ref</span><span class="v"><?= htmlspecialchars($order['buyer_inquiry_ref']) ?></span></div>
          <div><span class="k">Incoterm &amp; Port of Loading</span><span class="v"><?= htmlspecialchars($order['incoterm_code']) ?> <?= htmlspecialchars($order['port_of_loading_name'] ?? '—') ?></span></div>
          <div><span class="k">Quotation Date</span><span class="v"><?= htmlspecialchars($order['quotation_date'] ?? '—') ?></span></div>
          <div><span class="k">FOB Value</span><span class="v"><?= htmlspecialchars($order['currency_code']) ?> <?= number_format((float) $fobTotal, 2) ?></span></div>
          <div><span class="k">Port of Discharge</span><span class="v"><?= htmlspecialchars($order['port_of_discharge_name'] ?? $order['port_of_discharge_text'] ?? 'To Be Confirmed') ?></span></div>
          <div><span class="k">Container Type</span><span class="v"><?= htmlspecialchars($order['container_type'] ?? '—') ?></span></div>
          <div><span class="k">Certificate of Origin Type</span><span class="v"><?= htmlspecialchars($order['coo_type'] ?? 'To Be Confirmed') ?></span></div>
          <div><span class="k">Production Lead Time (<?= htmlspecialchars($leadTimeDisplayUnit) ?>)</span><span class="v"><?= htmlspecialchars(LeadTimeDisplay::formatRange($order['lead_time_min_days'] !== null ? (int) $order['lead_time_min_days'] : null, $order['lead_time_max_days'] !== null ? (int) $order['lead_time_max_days'] : null, $leadTimeDisplayUnit)) ?></span></div>
          <div><span class="k">Sailing Lead Time (<?= htmlspecialchars($leadTimeDisplayUnit) ?>)</span><span class="v"><?= htmlspecialchars(LeadTimeDisplay::formatRange($order['sailing_lead_time_min_days'] !== null ? (int) $order['sailing_lead_time_min_days'] : null, $order['sailing_lead_time_max_days'] !== null ? (int) $order['sailing_lead_time_max_days'] : null, $leadTimeDisplayUnit)) ?></span></div>
          <div><span class="k">Payment Preset</span><span class="v"><?= htmlspecialchars($order['preset_name']) ?></span></div>
          <div><span class="k">Buyer's PO Ref</span><span class="v">
            <?php if (!empty($order['buyers_po_ref']) && $order['buyers_po_ref'] !== 'NIL'): ?>
              <?= htmlspecialchars($order['buyers_po_ref']) ?>
            <?php elseif (!empty($buyerPoDocuments)): ?>
              <span class="muted">Not yet recorded &mdash; <?= count($buyerPoDocuments) ?> file<?= count($buyerPoDocuments) === 1 ? '' : 's' ?> attached (see Step 4b below)</span>
            <?php else: ?>
              NIL
            <?php endif; ?>
          </span></div>
          <div><span class="k">Special Requirements/Instructions</span><span class="v"><?= htmlspecialchars($order['special_requirements'] ?? '—') ?></span></div>
        </div>
      </div>

      <?php if ($canViewCafin): ?>
      <?php // Owner request (2026-10-27): CAFIN can be generated whenever it is wanted — at any stage, no "enable" step. Shown to view_cafin holders (owner decision 2026-10-28). ?>
      <div class="sidebar-panel sidebar-cafin" id="order-cafin">
        <h3>Internal Financial Annexure (CAFIN) <span class="badge badge-internal">INTERNAL ONLY — never shown to the client</span></h3>
        <div class="sidebar-cafin-row">
          <?php if ($canManageCaInternalDoc): ?>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/ca-internal-doc/generate" style="display:inline;">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate CAFIN now (PDF)</button>
            </form>
          <?php endif; ?>
          <?php if ($caInternalDoc): ?>
            <span class="small">Latest: <a href="/documents/<?= (int) $caInternalDoc['id'] ?>/download?format=pdf"><?= htmlspecialchars($caInternalDoc['document_reference']) ?></a> (generated <?= htmlspecialchars((string) $caInternalDoc['generated_at']) ?>)</span>
          <?php else: ?>
            <span class="muted small">None generated yet.</span>
          <?php endif; ?>
          <span class="muted small"><?= $canManageCaInternalDoc ? 'Available at any stage — it shows whatever is recorded so far.' : 'Only Admin/MD/ED (or someone specifically granted the permission) can generate it.' ?> Never sent, attached or shown to the client.</span>
        </div>
      </div>
      <?php endif; ?>

      <div class="sidebar-panel">
        <details class="sidebar-docs-toggle">
          <summary>All Documents (<?= count($documents) ?>) &mdash; quick links</summary>
        <?php if (empty($documents)): ?>
          <p class="muted small">No documents generated yet.</p>
        <?php else: ?>
          <ul class="sidebar-doc-list">
            <?php foreach ($documents as $d): ?>
              <li>
                <?php if ($d['pdf_file_id'] && $canDownloadPdf): ?><a href="/documents/<?= (int) $d['id'] ?>/download?format=pdf"><?= htmlspecialchars($d['document_type_code']) ?> <?= htmlspecialchars($d['document_reference'] ?? '—') ?></a><?php else: ?><?= htmlspecialchars($d['document_type_code']) ?> <?= htmlspecialchars($d['document_reference'] ?? '—') ?><?php endif; ?>
                <span class="muted small">Rev.<?= (int) $d['revision_number'] ?> &middot; <?= htmlspecialchars(str_replace('_', ' ', $d['status'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <p class="muted small"><a href="#document-review-approval">Full list with review/approval status &rarr;</a></p>
        </details>
      </div>
    </aside>
  </div>
  <script>
  // External QA report M-08: "Regenerating an earlier-stage document after
  // later stages passed: mandatory confirmation ... App only warns." Named,
  // specific confirm() before the POST goes out — not a server-side hard
  // block (same convention this page's own confirmFieldOverride() already
  // uses elsewhere), reading the submitting form's own document_type field
  // (a fixed hidden input on every button except the privileged
  // Force-Generate form, where it's a <select> the staff member just
  // chose — either way, this reads whatever value is about to be posted).
  var regenerationRisk = <?= json_encode($regenerationRisk) ?>;
  function confirmDocumentRegeneration(form) {
    var typeEl = form.querySelector('[name="document_type"]');
    var downstream = typeEl ? regenerationRisk[typeEl.value] : null;
    if (downstream && downstream.length) {
      return confirm(
        'Regenerating ' + typeEl.value + ' may leave ' + downstream.join(', ') +
        ' out of step with the new data — those documents were generated before this change. Continue?'
      );
    }
    return true;
  }
  </script>
  <script>
  // Order detail page rebuild: step-cards are native <details>/<summary> —
  // open/closed state, keyboard toggling, and the no-JS fallback (every
  // card simply stacked, all collapsed) all come from the browser for
  // free. This script only handles the parts <details> doesn't: opening
  // AND scrolling to a card when something links to it (an in-page
  // "see X" reference, the top stepper dots, or a direct #hash URL), and
  // mapping the stage-stepper's numeric stage to the right card id since
  // one DB stage can span two cards (Stage 2/3 -> Buyer PO + PI-Stage
  // Intake) — Stage 6 (Freight, user Step 9) and Stage 7 (Packing & BL,
  // user Step 10) are now separate numbered steps, each with its own card.
  (function () {
    var panelsContainer = document.getElementById('order-panels');
    if (!panelsContainer) { return; }

    function openAndScrollTo(id) {
      var el = document.getElementById(id);
      if (!el) { return false; }
      var details = el.tagName === 'DETAILS' ? el : el.closest('details');
      if (details) {
        // A card nested inside a .step-group (Step 7's compliance card, Step 10's 10a, etc.) is
        // invisible while that group itself is still closed — a closed
        // ancestor <details> hides every descendant regardless of its
        // own `open` state. Climb every ancestor <details>, not just
        // the nearest one, so a direct link into a nested card (the
        // "At a Glance" sidebar's "see checklist", the ECGC cross-
        // reference, etc.) actually reveals it rather than only
        // scrolling to a still-hidden element.
        for (var node = details; node; node = node.parentElement ? node.parentElement.closest('details') : null) {
          node.open = true;
        }
        // Opening a .step-group only reveals the group shell — every
        // one of its own step-cards needs opening too, not just
        // whichever one the server happened to mark current. Several
        // (Step 5a, 6c) are deliberately reachable before their stage
        // is actually current at all (optional, never-blocking
        // channels available from the very first render), so a direct
        // click always has to reach them regardless of server state.
        var children = details.querySelectorAll(':scope > details');
        Array.prototype.forEach.call(children, function (child) { if (!child.hasAttribute("data-locked")) { child.open = true; } });
      }
      el.scrollIntoView({ block: 'start' });
      return true;
    }

    // Any in-page link to a card's #id (the sidebar's "At a Glance" cross
    // references, "see Payment Overview", "see checklist", etc.) opens
    // the target card before the browser's default jump, since a closed
    // <details> has nothing visible to scroll to otherwise.
    document.addEventListener('click', function (e) {
      var link = e.target.closest ? e.target.closest('a[href^="#"]') : null;
      if (!link) { return; }
      var targetId = link.getAttribute('href').slice(1);
      if (!targetId || !openAndScrollTo(targetId)) { return; }
      e.preventDefault();
      if (window.history && window.history.pushState) {
        window.history.pushState(null, '', '#' + targetId);
      }
    });

    // The top-of-page stage stepper dots jump to the stage's own parent
    // group box (stages 2-9 each have exactly one — see the
    // .step-group <details> above) rather than diving into one of its
    // sub-items, so a click always lands on the same collapsible unit
    // staff would reach by scrolling and opening it themselves. Stage 1
    // has no group (standalone Quotation cards), so it targets the
    // first of those instead.
    var stageToGroupId = {
      1: 'step-1-quotation-prepare',
      2: 'step-group-stage-2',
      3: 'step-group-stage-3',
      4: 'step-group-stage-4',
      5: 'step-group-stage-5',
      6: 'step-group-stage-6',
      7: 'step-group-stage-7',
      8: 'step-group-stage-8',
      9: 'step-group-stage-9',
    };
    var stepperSteps = document.querySelectorAll('.process-stepper .process-step');
    function jumpToStage(stageNumber) {
      var id = stageToGroupId[String(stageNumber)];
      if (id) { openAndScrollTo(id); }
    }
    Array.prototype.forEach.call(stepperSteps, function (step) {
      var stageNumber = step.dataset.stage;
      if (!stageNumber) { return; }
      step.addEventListener('click', function () { jumpToStage(stageNumber); });
      step.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); jumpToStage(stageNumber); }
      });
    });

    // On load: a direct #hash URL wins (open + scroll to it); otherwise
    // scroll to whichever card the server already marked `open` (the
    // order's real current step) so staff land on it without hunting —
    // the innermost one, i.e. the current sub-step card inside its step
    // group rather than the top of the group.
    var initialId = (window.location.hash || '').replace('#', '');
    if (initialId && document.getElementById(initialId)) {
      openAndScrollTo(initialId);
    } else {
      // Inside the five sections first — a pinned reference card (Document
      // Review opens itself when something needs action) must not steal
      // the landing spot from the order's actual current step.
      var openCards = panelsContainer.querySelectorAll('.phase-group details[open]');
      if (!openCards.length) { openCards = panelsContainer.querySelectorAll('details[open]'); }
      var current = null;
      for (var i = 0; i < openCards.length; i++) {
        if (!openCards[i].querySelector('details[open]')) { current = openCards[i]; break; }
      }
      if (current) {
        // Show the step's own header and its already-ticked sub-steps
        // above the current one whenever that still fits on screen;
        // otherwise go straight to the current sub-step card.
        var group = current.parentElement ? current.parentElement.closest('details.step-group') : null;
        var fitsFromGroupTop = group && (current.getBoundingClientRect().bottom - group.getBoundingClientRect().top) < window.innerHeight - 56;
        (fitsFromGroupTop ? group : current).scrollIntoView({ block: 'start' });
      }
    }
  })();

  // Payment Overview's Snapshot/Ledger/Full Status: real single-pane
  // tabs, zero-JS-safe. No panel is ever marked `hidden` in the static
  // markup, so with JS disabled every panel shows stacked (degraded but
  // fully usable); here JS switches to true tab behavior, hiding every
  // panel but the one clicked, with the first active by default.
  (function () {
    var tabGroups = document.querySelectorAll('[data-tabs]');
    Array.prototype.forEach.call(tabGroups, function (group) {
      var buttons = group.querySelectorAll('.tab-btn');
      var panels = group.querySelectorAll('[data-tab-panel]');
      function activate(targetId) {
        Array.prototype.forEach.call(panels, function (panel) {
          panel.hidden = panel.id !== targetId;
        });
        Array.prototype.forEach.call(buttons, function (btn) {
          btn.setAttribute('aria-selected', String(btn.dataset.tabTarget === targetId));
        });
      }
      Array.prototype.forEach.call(buttons, function (btn) {
        btn.addEventListener('click', function () { activate(btn.dataset.tabTarget); });
      });
      if (buttons.length) { activate(buttons[0].dataset.tabTarget); }
    });
  })();
  </script>
</div>
