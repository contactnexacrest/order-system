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
// QA-5 RBAC-03/04: duty-specific split of manage_orders — see docs/seed.sql.
$canManagePayments = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_payments');
// External QA report Q-ROLE-1: clearing a buyer payment (confirming the
// bank actually received it) is split out of manage_payments (recording
// what the client says they sent) so the same person can't do both.
$canClearPayments = PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'clear_payments');
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
      <?php if ($rate !== null): ?>
        <span class="muted small">1 unit foreign currency = &#8377;<?= number_format((float) $rate, 4) ?> — set <?= htmlspecialchars((string) ($payment[$leg . '_exchange_rate_set_at'] ?? '')) ?><?= $setByName ? ' by ' . htmlspecialchars($setByName) : '' ?>. Used only to show forex gain/(loss) below once an INR actual is recorded — never to derive the INR actual itself.</span>
        <?php if ($lockMessage !== null && !$canOverrideFyLock): ?>
          <br><span class="muted small">&#128274; <?= htmlspecialchars($lockMessage) ?></span>
        <?php elseif ($canEditInrActual): ?>
          <?php if ($lockMessage !== null): ?>
            <p class="muted small" style="margin:6px 0 0;">&#9888; <?= htmlspecialchars($lockMessage) ?> You hold the override permission — submitting below will be logged.</p>
          <?php endif; ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/<?= $leg ?>/exchange-rate" style="margin-top:6px;">
            <?= Csrf::field() ?>
            <label>Update Rate<input type="text" name="<?= $leg ?>_exchange_rate" placeholder="e.g. 88.5000" required></label>
            <button type="submit" class="btn-sm"><?= $lockMessage !== null ? 'Override &amp; Update' : 'Update' ?></button>
          </form>
        <?php endif; ?>
      <?php elseif ($lockMessage !== null && !$canOverrideFyLock): ?>
        <span class="muted small">&#128274; <?= htmlspecialchars($lockMessage) ?></span>
      <?php elseif ($canEditInrActual): ?>
        <?php if ($lockMessage !== null): ?>
          <p class="muted small" style="margin:0 0 6px;">&#9888; <?= htmlspecialchars($lockMessage) ?> You hold the override permission — submitting below will be logged.</p>
        <?php endif; ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/<?= $leg ?>/exchange-rate">
          <?= Csrf::field() ?>
          <label>Rate (INR per unit foreign currency) *<input type="text" name="<?= $leg ?>_exchange_rate" placeholder="e.g. 88.5000" required></label>
          <button type="submit" class="btn-sm"><?= $lockMessage !== null ? 'Override &amp; Record' : 'Record Rate' ?></button>
        </form>
      <?php else: ?>
        <span class="muted small">Not yet recorded.</span>
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
$freightSkipped = $stage6 && $stage6['status'] === 'skipped';
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
$ledgerEvents = [];
foreach ($paymentLegs as $leg) {
    if ($leg['received_at'] !== null) {
        $ledgerEvents[] = ['date' => $leg['received_at'], 'event' => "{$leg['label']} Remittance Received", 'amount' => $leg['amount'], 'source' => 'Staff-recorded'];
    }
    if ($leg['cleared_at'] !== null) {
        $ledgerEvents[] = ['date' => $leg['cleared_at'], 'event' => "{$leg['label']} Cleared", 'amount' => $leg['amount'], 'source' => 'Staff-recorded'];
    }
}
foreach ($clientPaymentReports as $r) {
    $ledgerEvents[] = [
        'date' => $r['payment_date'] ?? $r['reported_at'],
        'event' => ucfirst($r['payment_type']) . ' Payment Reported by Client',
        'amount' => $r['amount'],
        'source' => $r['status'] === 'reviewed' ? 'Client-reported (reviewed)' : 'Client-reported (new)',
    ];
}
usort($ledgerEvents, static fn(array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));
$paymentLegsResolved = count(array_filter($paymentLegs, static fn(array $l): bool => $l['cleared_at'] !== null));
$paymentLegsApplicable = count(array_filter($paymentLegs, static fn(array $l): bool => $l['amount'] !== null || $l['received_at'] !== null));

// Order Details at-a-glance summary (redesign) — quick counts reused from
// data already loaded for other sections, so no extra queries.
$complianceSummary = ['total' => 0, 'approved' => 0];
foreach ($complianceTasks as $ct) {
    $complianceSummary['total']++;
    if ($ct['status'] === 'approved' || $ct['status'] === 'skipped') {
        $complianceSummary['approved']++;
    }
}
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
// One entry per user-facing top-level step (0-17) — 'num' is the display
// label, 'status' drives the card's checkmark/current-highlight/locked
// styling, 'open' is whether its <details> starts expanded.
$stepStates = [
    '1-3'  => $stepStatus($stage1),
    '4'    => $stepStatus($stage2),
    '5'    => $stepStatus($stage3),
    '6'    => $stepStatus($stage4),
    '7'    => $stepStatus($stage5),
    '8'    => $stepStatus($stage5, $supplierSigned), // no own DB stage — unlocked once Stage 5 passes
    '9'    => $stepStatus($isFob ? $stage7 : $stage6),
    '9-10' => $stepStatus($stage8),
    '11'   => $stepStatus($stage9),
    '12'   => 'done', // reference/upload card — never gated
    '13'   => 'done',
    '14'   => 'done',
    '15'   => 'done',
    '16'   => 'done',
    '17'   => $orderClosed ? 'done' : 'locked',
];
// The single step whose <details> should start open: the first one that
// isn't yet 'done' (the order's real current step), or '17' once the
// order is actually closed — same "first unresolved" logic $currentStage
// above already uses, just applied to the user-facing step numbers.
$currentUserStep = '17';
foreach ($stepStates as $__step => $__status) {
    if ($__status !== 'done') {
        $currentUserStep = $__step;
        break;
    }
}
$stepCardOpen = static fn(string $step): string => ($step === $currentUserStep) ? ' open' : '';
$stepStatusIcon = static function (string $status): string {
    return match ($status) {
        'done' => '<span class="step-card-icon done" aria-hidden="true">&#10003;</span>',
        'current' => '<span class="step-card-icon current" aria-hidden="true">&#9679;</span>',
        default => '<span class="step-card-icon locked" aria-hidden="true">&#9675;</span>',
    };
};
?>
<div class="card page-wide">
  <?php if (($_GET['from'] ?? '') === 'kanban'): ?>
  <p class="muted small"><a href="/orders">&larr; Back to Board</a></p>
  <?php else: ?>
  <p class="muted small"><a href="/orders">&larr; Back to Orders</a></p>
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
  </p>
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
      var reason = prompt('Why is this order lost? (required — recorded in the audit log)');
      if (!reason || !reason.trim()) { return; }
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
            var notes = prompt('Why is this cancellation request being rejected? (required — recorded in the audit log)');
            if (!notes || !notes.trim()) { return; }
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
        var reason = prompt('Why is cancellation being requested? (required — recorded in the audit log; a privileged second person still has to approve this)');
        if (!reason || !reason.trim()) { return; }
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

  <details class="step-card" id="step-products" open>
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
              <label>Qty<input type="text" name="quantity" value="<?= htmlspecialchars((string) ($p['quantity'] ?? '')) ?>"></label>
              <label><input type="checkbox" name="quantity_is_tbc" value="1" style="display:inline-block;width:auto;" <?= $p['quantity_is_tbc'] ? 'checked' : '' ?>> Qty To Be Confirmed</label>
              <label>Unit
                <select name="unit">
                  <?php $__unitVal = (string) ($p['unit'] ?? ''); ?>
                  <?php foreach ($units as $o): ?>
                    <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($__unitVal === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Unit Price<input type="text" name="unit_price" value="<?= htmlspecialchars((string) ($p['unit_price'] ?? '')) ?>"></label>
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
            <label>Qty<input type="text" name="quantity"></label>
            <label><input type="checkbox" name="quantity_is_tbc" value="1" style="display:inline-block;width:auto;"> Qty To Be Confirmed</label>
            <label>Unit
              <select name="unit">
                <?php foreach ($units as $o): ?>
                  <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Unit Price<input type="text" name="unit_price"></label>
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

    <details open>
      <summary>Snapshot</summary>
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
    </details>

    <details>
      <summary>Ledger</summary>
      <p class="muted">A single, read-only history of every payment-related event recorded against this order — consolidating what's otherwise scattered across Payment Status and Client-Reported Payments. Nothing here can be edited; use the forms in each payment step to record new events.</p>
      <?php if (empty($ledgerEvents)): ?>
      <p class="muted small">No payment events recorded yet.</p>
      <?php else: ?>
      <table class="list">
        <tr><th>Date</th><th>Event</th><?php if ($canViewPaymentAmounts): ?><th>Amount</th><?php endif; ?><th>Source</th></tr>
        <?php foreach ($ledgerEvents as $ev): ?>
        <tr>
          <td><?= htmlspecialchars((string) $ev['date']) ?></td>
          <td><?= htmlspecialchars($ev['event']) ?></td>
          <?php if ($canViewPaymentAmounts): ?>
          <td><?= $ev['amount'] !== null ? number_format((float) $ev['amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?></td>
          <?php endif; ?>
          <td><?= htmlspecialchars($ev['source']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </details>

    <details>
      <summary>Full Status</summary>
      <?php if ($payment): ?>
      <div class="kv-grid">
        <div><span class="k">Advance Amount</span><span class="v"><?php if (!$canViewPaymentAmounts): ?>Only shown to Accounts/Logistics/Export staff<?php else: ?><?= $payment['advance_amount'] !== null ? number_format((float) $payment['advance_amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?><?php endif; ?></span></div>
        <div><span class="k">Advance T/T Received</span><span class="v"><?= htmlspecialchars($payment['advance_remittance_received_at'] ?? '—') ?></span></div>
        <div><span class="k">Advance Cleared</span><span class="v"><?= htmlspecialchars($payment['advance_cleared_at'] ?? '—') ?></span></div>
        <div><span class="k">Balance Amount</span><span class="v"><?php if (!$canViewPaymentAmounts): ?>Only shown to Accounts/Logistics/Export staff<?php else: ?><?= $payment['balance_amount'] !== null ? number_format((float) $payment['balance_amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?><?php endif; ?></span></div>
        <div><span class="k">Balance Due</span><span class="v"><?= htmlspecialchars($payment['balance_due_date'] ?? 'event-triggered') ?></span></div>
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
    </details>
  </details>

  <details class="step-card" id="step-1-quotation-prepare"<?= $stepCardOpen('1-3') ?>>
    <summary><?= $stepStatusIcon($stepStates['1-3']) ?><h2>Step 1 &middot; Prepare Quotation</h2></summary>
    <?php
      // Batch 3 #5 — differentiate 'not yet generated' vs 'already generated, this regenerates
      // it' document buttons visually, instead of every visible button looking identical.
      $__genTypes = array_column($documents, 'document_type_code');
      $__docBtnClass = static fn (string $code): string => 'btn-sm ' . (in_array($code, $__genTypes, true) ? 'btn-doc-regenerate' : 'btn-doc-new');
    ?>
    <div class="btn-row">
      <?php if ($stage1 && $stage1['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="QT">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('1a') ?><button type="submit" class="<?= $__docBtnClass('QT') ?>" data-loading-text="Generating…"><?= $hasQt ? 'Regenerate Quotation (new revision)' : 'Generate Quotation' ?></button>
        </form>
      <?php endif; ?>

      <?php if ($order['include_annexure_a']): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="ANNEXA">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <button type="submit" class="<?= $__docBtnClass('ANNEXA') ?>" data-loading-text="Generating…">Generate Annexure A</button>
        </form>
      <?php endif; ?>
    </div>
    <?php if ($canManageOrders): ?>
    <p class="muted small"><a href="/orders/<?= (int) $order['id'] ?>/annexure"><?= $order['include_annexure_a'] ? 'Manage Annexure A product entries &amp; images' : 'Enable / manage Annexure A' ?></a></p>
    <?php endif; ?>
    <p class="muted small">Review, approval, and sending to the buyer happen in <a href="#document-review-approval">Document Review &amp; Approval</a> below, once generated.</p>
  </details>

  <details class="step-card step-card-reference" id="step-2-quotation-send"<?= $stepCardOpen('1-3') ?>>
    <summary><?= $stepStatusIcon($stepStates['1-3']) ?><h2>Step 2 &middot; Send Quotation to Client</h2></summary>
    <p class="muted small">Sending happens through the shared <a href="#document-review-approval">Document Review &amp; Approval</a> workflow below &mdash; every generated document (not just the Quotation) is assigned a reviewer, approved, and dispatched from that one place, regardless of which step generated it. Once the Quotation is approved there, use its own "Send to Buyer" action.</p>
  </details>

  <details class="step-card step-card-reference" id="step-3-quotation-accept"<?= $stepCardOpen('1-3') ?>>
    <summary><?= $stepStatusIcon($stepStates['1-3']) ?><h2>Step 3 &middot; Client Accepts Quotation</h2></summary>
    <p class="muted small">There's no separate "accepted" button to click &mdash; the buyer's acceptance happens outside the system (a reply email, a call, a signed copy). Once your team is satisfied the client has accepted, move on to <a href="#step-4a-buyer-po-generate">Step 4a &middot; Buyer's PO</a>.</p>
  </details>

  <details class="step-card step-card-reference" id="document-review-approval" open>
    <summary><h2>Document Review &amp; Approval <small class="muted">(reference &mdash; every document, every step)</small></h2></summary>
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
          <?php if ($d['pdf_file_id']): ?><a href="/documents/<?= (int) $d['id'] ?>/download?format=pdf">PDF</a><?php endif; ?>
          <?php if ($d['docx_file_id']): ?> · <a href="/documents/<?= (int) $d['id'] ?>/download?format=docx">DOCX (internal)</a><?php endif; ?>
        </td>
        <td>
          <?php if ($d['status'] === 'draft'): ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/<?= (int) $d['id'] ?>/delete" onsubmit="return confirmDeleteDraft(this);">
            <?= Csrf::field() ?>
            <input type="hidden" name="reason" class="delete-draft-reason">
            <button type="button" class="btn-sm btn-danger" onclick="promptDeleteDraft(this)">Delete Draft</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($documents)): ?>
      <tr><td colspan="7" class="muted">No documents generated yet.</td></tr>
      <?php endif; ?>
    </table>
    <p class="muted small" style="margin-top:12px;">Free-form additional files (not one of the fixed document types above) are attached from <a href="#step-additional-documents">Step 12 &middot; Additional Documents</a>.</p>
    <script>
      function promptDeleteDraft(btn) {
        var form = btn.closest('form');
        var reason = prompt('Why is this draft document being deleted? (required, at least 10 characters — recorded in the audit log)');
        if (!reason || reason.trim().length < 10) { return; }
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
        var reason = prompt('Why is this document being removed? (required, at least 10 characters — recorded in the audit log)');
        if (!reason || reason.trim().length < 10) { return; }
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

              <form method="post" action="/documents/<?= (int) $d['id'] ?>/cross-verify" style="margin-top:6px">
                <?= Csrf::field() ?>
                <select name="result" required>
                  <option value="pass">Cross-verify: Pass</option>
                  <option value="fail">Cross-verify: Fail</option>
                </select>
                <input type="text" name="comments" placeholder="Comments (optional)">
                <button type="submit" class="btn-sm">Record Cross-Verification</button>
              </form>
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

              <?php if ($d['status'] === 'approved'): ?>
                <?php if ($hasPendingSend): ?>
                  <p class="muted">A send to the buyer is already in progress for this document (see above) — no new send can be submitted until it's sent, rejected, or fails.</p>
                <?php else: ?>
                  <p><a href="/orders/<?= (int) $order['id'] ?>/documents/<?= (int) $d['id'] ?>/send" class="btn-sm btn-accent">Send to Buyer (deferred, 2-level approval)</a></p>
                <?php endif; ?>
              <?php elseif ($d['status'] === 'sent'): ?>
                <p class="muted">Already sent to buyer.</p>
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

  <div class="step-group">
    <div class="step-group-header"><span class="step-group-num">STEP 4</span><h3>Buyer's PO</h3></div>

    <details class="step-card" id="step-4a-buyer-po-generate"<?= $stepCardOpen('4') ?>>
      <summary><?= $stepStatusIcon($stepStates['4']) ?><h2>Step 4a &middot; Generate Buyer's PO</h2></summary>
      <?php if ($stage2 && $stage2['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="BUYERPO">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('4a') ?><button type="submit" class="<?= $__docBtnClass('BUYERPO') ?>" data-loading-text="Generating…">Generate Buyer PO</button>
        </form>
      <?php else: ?>
        <p class="muted">Generate the Quotation first to unlock this gate.</p>
      <?php endif; ?>
    </details>

    <div class="step-group-connector">once the buyer sends their actual PO back</div>

    <details class="step-card" id="step-4b-buyer-po-confirm"<?= $stepCardOpen('4') ?>>
      <summary><?= $stepStatusIcon($stepStates['4']) ?><h2>Step 4b &middot; Confirm Buyer's PO Received</h2></summary>
      <?php if ($hasBuyerPo): ?>
        <p>Buyer PO recorded: <strong><?= htmlspecialchars($order['buyers_po_ref']) ?></strong></p>
      <?php elseif ($stage2 && $stage2['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-po">
          <?= Csrf::field() ?>
          <label>Buyer's PO / Reference Number *<input type="text" name="buyers_po_ref" required></label>
          <?= $stepBadge('4b') ?><button type="submit" class="btn-sm">Confirm Buyer PO Received</button>
        </form>
      <?php else: ?>
        <p class="muted">Generate the Quotation first to unlock this gate.</p>
      <?php endif; ?>
    </details>

    <div class="step-group-connector">attach their signed copy for the file</div>

    <details class="step-card" id="step-4c-buyer-po-attach"<?= $stepCardOpen('4') ?>>
      <summary><?= $stepStatusIcon($stepStates['4']) ?><h2>Step 4c &middot; Attach Buyer's PO Copy</h2></summary>
      <?php if ($stage2 && $stage2['status'] !== 'locked'): ?>
        <?php if (!empty($buyerPoDocuments)): ?>
          <table class="list" style="margin-top:8px">
            <tr><th>File</th><th>Uploaded</th><th></th></tr>
            <?php foreach ($buyerPoDocuments as $bd): ?>
              <tr>
                <td><?= htmlspecialchars($bd['original_filename']) ?></td>
                <td><?= htmlspecialchars($bd['uploaded_at']) ?></td>
                <td><a href="/file-store/<?= (int) $bd['file_id'] ?>/download">Download</a></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-po/documents" enctype="multipart/form-data" style="margin-top:6px">
          <?= Csrf::field() ?>
          <input type="file" name="document" required>
          <?= $stepBadge('4c') ?><button type="submit" class="btn-sm">Attach Buyer PO Copy</button>
        </form>
      <?php else: ?>
        <p class="muted">Generate the Quotation first to unlock this gate.</p>
      <?php endif; ?>
    </details>
  </div>

  <div class="step-group">
    <div class="step-group-header"><span class="step-group-num">STEP 5</span><h3>Proforma Invoice &amp; Payment</h3></div>

    <details class="step-card" id="step-5a-pi-form-link"<?= $stepCardOpen('5') ?>>
      <summary><?= $stepStatusIcon($stepStates['5']) ?><h2>Step 5a &middot; Generate PI Form Link</h2></summary>
      <p class="muted small">A separate form from the Quotation-stage request — sent to the client to confirm consignee/notify-party/payment-terms details before the PI is issued.</p>
      <?php if ($piIntake): ?>
        <p>
          Status: <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $piIntake['status']))) ?></strong>
          <?php if ($piIntake['status'] === 'pending_review'): ?>
            &nbsp;·&nbsp; <a href="/pi-intake-review">Review in PI Intake Review</a>
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
    </details>

    <div class="step-group-connector">client replies</div>

    <details class="step-card step-card-reference" id="step-5b-pi-intake-apply"<?= $stepCardOpen('5') ?>>
      <summary><?= $stepStatusIcon($stepStates['5']) ?><h2>Step 5b &middot; Apply the Client's PI-Stage Confirmation</h2></summary>
      <p class="muted small">Once the client submits the PI-stage form, review and apply it at <a href="/pi-intake-review">PI Intake Review</a> — applying it overwrites the live client record with exactly what the buyer confirmed.</p>
    </details>

    <div class="step-group-connector">now the real PI can be issued</div>

    <details class="step-card" id="step-5c-pi-generate"<?= $stepCardOpen('5') ?>>
      <summary><?= $stepStatusIcon($stepStates['5']) ?><h2>Step 5c &middot; Generate Proforma Invoice</h2></summary>
      <?php if ($stage3 && $stage3['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="PI">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('5c') ?><button type="submit" class="<?= $__docBtnClass('PI') ?>" data-loading-text="Generating…">Generate Proforma Invoice</button>
        </form>
      <?php else: ?>
        <p class="muted">Record the Buyer PO first to unlock this gate.</p>
      <?php endif; ?>
    </details>

    <div class="step-group-connector">payment method for this order: <strong><?= $isLcPayment ? 'Letter of Credit' : 'Cash Advance' ?></strong></div>

    <?php if ($isLcPayment): ?>
    <details class="step-card" id="step-5d-payment-record"<?= $stepCardOpen('5') ?>>
      <summary><?= $stepStatusIcon($stepStates['5']) ?><h2>Step 5d &middot; Record LC Details</h2></summary>
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

    <details class="step-card" id="step-5e-payment-confirm"<?= $stepCardOpen('5') ?>>
      <summary><?= $stepStatusIcon($stepStates['5']) ?><h2>Step 5e &middot; Confirm LC Usable</h2></summary>
      <?php if ($stage3 && $stage3['status'] !== 'locked' && !$lcConfirmed && $orderLcDetails && $orderLcDetails['lc_amount'] !== null): ?>
        <?php if (!$canClearPayments): ?>
          <p class="muted">LC details recorded — awaiting Accounts to confirm it usable.</p>
        <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/lc/confirm-usable" onsubmit="return confirm('Confirm this LC usable? This unlocks Stage 4 and auto-provisions the client portal login — confirm the advising bank has actually confirmed first.');">
          <?= Csrf::field() ?>
          <?= $stepBadge('5e') ?><button type="submit" class="btn-sm btn-success">Confirm LC Usable (unlocks Stage 4)</button>
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
    <details class="step-card" id="step-5d-payment-record"<?= $stepCardOpen('5') ?>>
      <summary><?= $stepStatusIcon($stepStates['5']) ?><h2>Step 5d &middot; Record Advance Remittance</h2></summary>
      <?php if ($stage3 && $stage3['status'] !== 'locked' && !$advanceCleared && (!$payment || $payment['advance_remittance_received_at'] === null)): ?>
        <?php if (!$canManagePayments): ?>
          <p class="muted">Only Accounts/Export can record this payment.</p>
        <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/advance">
          <?= Csrf::field() ?>
          <label>Advance Amount Received * <small class="muted">(in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="advance_amount" required></label>
          <label>Received On<input type="date" name="advance_received_at" value="<?= date('Y-m-d') ?>"></label>
          <?= $stepBadge('5d') ?><button type="submit" class="btn-sm">Record Advance Remittance</button>
        </form>
        <?php endif; ?>
      <?php elseif (!$stage3 || $stage3['status'] === 'locked'): ?>
        <p class="muted">Record the Buyer PO first to unlock this gate.</p>
      <?php elseif ($payment && $payment['advance_remittance_received_at'] !== null): ?>
        <p class="muted">Already recorded — see Step 5e to mark it cleared.</p>
      <?php endif; ?>

      <?php $renderInrActualWidget('advance', 'Advance Payment'); ?>
      <?php $renderExchangeRateWidget('advance', 'Advance Payment'); ?>
    </details>

    <div class="step-group-connector">once the bank confirms the funds landed</div>

    <details class="step-card" id="step-5e-payment-confirm"<?= $stepCardOpen('5') ?>>
      <summary><?= $stepStatusIcon($stepStates['5']) ?><h2>Step 5e &middot; Mark Advance Cleared</h2></summary>
      <?php if ($stage3 && $stage3['status'] !== 'locked' && !$advanceCleared && $payment && $payment['advance_remittance_received_at'] !== null): ?>
        <?php if (!$canClearPayments): ?>
          <p class="muted">Advance remittance recorded — awaiting Accounts to confirm it cleared.</p>
        <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/advance/clear" onsubmit="return confirm('Mark the advance payment cleared? This unlocks Stage 4 and auto-provisions the client portal login — confirm the funds have actually landed first.');">
          <?= Csrf::field() ?>
          <label>Cleared On<input type="date" name="advance_cleared_at" value="<?= date('Y-m-d') ?>"></label>
          <?= $stepBadge('5e') ?><button type="submit" class="btn-sm btn-success">Mark Advance Cleared (unlocks Stage 4)</button>
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
  </div>

  <details class="step-card step-card-reference" id="step-5-lc-bank-loan">
    <summary><h2>Letter of Credit &amp; Bank Loan <small class="muted">(steps 5-1a/5-2a/5-3a &mdash; financing cost, not the LC leg above)</small></h2></summary>
    <p class="muted small">If the client financed their LC (Step 5d/5e above) with a bank loan, record that loan's amount/interest here — separate from the LC leg itself, since this is about the cost of financing it, not confirming it usable.</p>
    <?php if ($canManageOrderFinancials): ?>
    <p class="muted small">Recorded in full under <a href="#order-financials">Order Financials</a> (Step 11) alongside this order's other costs — the Bank Loan Interest you record there is automatically kept in sync as an order expense.</p>
    <?php else: ?>
    <p class="muted small">Recorded by Accounts/Export staff alongside this order's other costs.</p>
    <?php endif; ?>
  </details>

  <div class="step-group">
    <div class="step-group-header"><span class="step-group-num">STEP 6</span><h3>Order Confirmation</h3></div>

  <details class="step-card" id="step-6a-oc-generate"<?= $stepCardOpen('6') ?>>
    <summary><?= $stepStatusIcon($stepStates['6']) ?><h2>Step 6a &middot; Generate Order Confirmation</h2></summary>
    <?php if ($canCloseOrders): ?>
    <p class="muted small">ECGC Cover is tracked on the <a href="#compliance-checklist">Compliance Checklist</a>.<?php if ($canManageOrderFinancials): ?> The premium paid is recorded under <a href="#order-financials">Order Financials</a> (Step 11) as an "ECGC insurance" cost entry — filed here, once the order is confirmed, so the fee is never paid before the order is certain to proceed.<?php endif; ?></p>
    <?php elseif ($canManageOrderFinancials): ?>
    <p class="muted small">The ECGC insurance premium paid is recorded under <a href="#order-financials">Order Financials</a> (Step 11) once the order is confirmed.</p>
    <?php endif; ?>
    <?php if ($stage4 && $stage4['status'] !== 'locked'): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="OC">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('6a') ?><button type="submit" class="<?= $__docBtnClass('OC') ?>" data-loading-text="Generating…">Generate Order Confirmation</button>
      </form>
    <?php else: ?>
      <p class="muted">Clear the advance payment first to unlock this gate.</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once the buyer acknowledges it</div>

  <details class="step-card" id="step-6b-oc-acknowledge"<?= $stepCardOpen('6') ?>>
    <summary><?= $stepStatusIcon($stepStates['6']) ?><h2>Step 6b &middot; Record Buyer's Acknowledgement</h2></summary>
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
          ℹ <span>Order Confirmation emailed to the buyer on <?= htmlspecialchars(Dates::human($ocAcknowledgment['sent_at'])) ?>. Awaiting their acknowledgement — the client can confirm it directly in their portal, or reply to the email (record that below). If there's no response by <?= htmlspecialchars(Dates::human($ocAcknowledgment['due_at'])) ?>, it auto-confirms and Stage 5 unlocks on its own.</span>
        </div>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/oc-acknowledgment">
          <?= Csrf::field() ?>
          <label>Buyer replied by email? Record the evidence here (quote the reply) *<textarea name="acknowledged_note" rows="3" required></textarea></label>
          <?= $stepBadge('6b') ?><button type="submit" class="btn-sm btn-success">Record Buyer's Email Acknowledgement (unlocks Stage 5)</button>
        </form>
      <?php elseif ($ocDocGenerated): ?>
        <div class="review-banner info">
          ℹ <span>Order Confirmation has been generated but hasn't gone out by email yet (still awaiting send/approval, or mail sending isn't configured). If the buyer has confirmed by phone, in person, or any channel other than the portal/email, record it below — Stage 5 unlocks immediately.</span>
        </div>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/oc-acknowledgment">
          <?= Csrf::field() ?>
          <label>Describe how the buyer confirmed (quote their reply, or note the call/meeting) *<textarea name="acknowledged_note" rows="3" required></textarea></label>
          <?= $stepBadge('6b') ?><button type="submit" class="btn-sm btn-success">Record Buyer's Acknowledgement (unlocks Stage 5)</button>
        </form>
      <?php else: ?>
        <p class="muted">Generate the Order Confirmation above and send it to the buyer — once sent, they can acknowledge it from their portal (or reply by email, which you can record here), and it auto-confirms after 48 hours with no response.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Clear the advance payment first to unlock this gate.</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">optional, any time — never blocks the above</div>

  <details class="step-card" id="step-6c-buyer-action-link"<?= $stepCardOpen('6') ?>>
    <summary><?= $stepStatusIcon($stepStates['6']) ?><h2>Step 6c &middot; Generate Buyer Action Link</h2></summary>
    <?php
      // 90-item backlog #75: a public, no-login link covering both this
      // stage's OC acknowledgement and the client-portal Buyer PO upload
      // — for a buyer who hasn't been provisioned a portal account yet
      // (or doesn't want to log in just for these two actions).
      // QA-5 L-24: buyer_action_token_plain is encrypted at rest
      // (EncryptionService) — decrypt it here, the one place it's ever
      // rendered back to staff.
      $buyerActionTokenPlain = \App\Services\EncryptionService::decrypt($order['buyer_action_token_plain'] ?? null);
      $buyerActionLinkValid = !empty($buyerActionTokenPlain) && !empty($order['buyer_action_token_expires_at']) && strtotime((string) $order['buyer_action_token_expires_at']) > time();
    ?>
    <p class="muted small">A direct link the buyer can use to acknowledge the Order Confirmation and/or upload their signed Buyer PO — without needing a client-portal account. Useful before portal access is provisioned (Stage 3 → 4), or for a buyer who'd rather not log in at all.</p>
    <?php if ($buyerActionLinkValid): ?>
      <?php $buyerActionFullLink = rtrim(Env::get('APP_URL', ''), '/') . '/buyer-action/' . $buyerActionTokenPlain; ?>
      <div class="review-banner info" style="flex-direction:column;align-items:stretch;gap:6px">
        <span>Current link (valid until <?= htmlspecialchars(Dates::human($order['buyer_action_token_expires_at'])) ?>) — copy and send however you like, no need to regenerate:</span>
        <div style="display:flex;gap:6px;align-items:center">
          <input type="text" readonly value="<?= htmlspecialchars($buyerActionFullLink) ?>" onclick="this.select()" style="flex:1;font-family:monospace;font-size:12px">
          <button type="button" class="btn-sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($buyerActionFullLink, ENT_QUOTES) ?>'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy Link', 1500);">Copy Link</button>
        </div>
      </div>
    <?php else: ?>
      <p class="muted small">No active link — generate one below.</p>
    <?php endif; ?>
    <?php if ($canManageOrders): ?>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-action-link" style="display:inline">
      <?= Csrf::field() ?>
      <?= $stepBadge('6c') ?><button type="submit" class="btn-sm"><?= $buyerActionLinkValid ? 'Regenerate Buyer Action Link' : 'Generate Buyer Action Link' ?></button>
    </form>
    <?php endif; ?>
  </details>

  <?php $supplierPoLocked = !$stage5 || $stage5['status'] === 'locked'; ?>

  </div>

  <div class="step-group">
    <div class="step-group-header"><span class="step-group-num">STEP 7</span><h3>Supplier PO</h3></div>

  <details class="step-card" id="step-7a-supplier-po-terms"<?= $stepCardOpen('7') ?>>
    <summary><?= $stepStatusIcon($stepStates['7']) ?><h2>Step 7a &middot; Save Supplier PO Terms</h2></summary>
    <?php if ($supplierPoLocked): ?>
      <p class="muted">Confirm buyer acknowledgement first to unlock this gate.</p>
    <?php elseif ($supplierPo): ?>
      <div class="kv-grid">
        <div><span class="k">Supplier</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_legal_name']) ?></span></div>
        <div><span class="k">Supplier PO Ref</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_po_reference']) ?></span></div>
        <div><span class="k">Material</span><span class="v"><?= htmlspecialchars($supplierPo['material_stone_type'] ?? '—') ?></span></div>
        <div><span class="k">Total Payable (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['total_payable_inr'] ?? '—') ?></span></div>
        <div><span class="k">Status</span><span class="v"><?= htmlspecialchars($supplierPo['status']) ?></span></div>
      </div>
      <p class="muted small">Terms already saved — see Step 7b to generate the document.</p>
    <?php else: ?>
      <details open>
        <summary>Add a new supplier</summary>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/suppliers">
          <?= Csrf::field() ?>
          <div class="field-grid">
            <label>Supplier Legal Name *<input type="text" name="supplier_legal_name" required></label>
            <label>Address<input type="text" name="address"></label>
            <label>GSTIN<input type="text" name="gstin"></label>
            <label>PAN<input type="text" name="pan"></label>
            <label>Contact Person<input type="text" name="contact_person"></label>
            <label>Phone<input type="text" name="phone"></label>
            <label>Supplier Type
              <select name="supplier_type">
                <option value="">—</option>
                <?php foreach ($supplierTypes as $opt): ?>
                  <option value="<?= htmlspecialchars($opt['option_value']) ?>"><?= htmlspecialchars($opt['option_value']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <button type="submit" class="btn-sm">Add Supplier</button>
        </form>
      </details>

      <?php if (!empty($suppliers)): ?>
      <details>
        <summary>Manage factory addresses</summary>
        <?php foreach ($suppliers as $sup): ?>
          <div class="card-nested">
            <strong><?= htmlspecialchars($sup['supplier_legal_name']) ?></strong>
            <?php $supAddresses = $supplierFactoryAddressesBySupplier[(int) $sup['id']] ?? []; ?>
            <?php if (!empty($supAddresses)): ?>
              <table class="list">
                <tr><th>Label</th><th>Address</th><th></th></tr>
                <?php foreach ($supAddresses as $fa): ?>
                  <tr>
                    <td><?= htmlspecialchars($fa['label'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($fa['address']) ?></td>
                    <td>
                      <form method="post" action="/orders/<?= (int) $order['id'] ?>/suppliers/factory-addresses/<?= (int) $fa['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this factory address?');">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn-sm btn-danger">&times;</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </table>
            <?php else: ?>
              <p class="muted small">No factory addresses added yet.</p>
            <?php endif; ?>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/suppliers/<?= (int) $sup['id'] ?>/factory-addresses" class="field-grid">
              <?= Csrf::field() ?>
              <label>Label <small class="muted">(optional)</small><input type="text" name="label" placeholder="e.g. Udaipur Factory"></label>
              <label>Address *<input type="text" name="address" required placeholder="e.g. Plot 12, Industrial Area, Udaipur, Rajasthan"></label>
              <button type="submit" class="btn-sm">Add Factory Address</button>
            </form>
          </div>
        <?php endforeach; ?>
      </details>
      <?php endif; ?>

      <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po" id="supplier-po-form">
        <?= Csrf::field() ?>
        <div class="field-grid">
        <label>Supplier *
          <select name="supplier_id" id="spo-supplier-select" required>
            <option value="">Select supplier…</option>
            <?php foreach ($suppliers as $sup): ?>
              <?php $supAddressesForOption = $supplierFactoryAddressesBySupplier[(int) $sup['id']] ?? []; ?>
              <option value="<?= (int) $sup['id'] ?>" data-addresses="<?= htmlspecialchars(json_encode(array_map(static fn(array $a): string => $a['address'], $supAddressesForOption)) ?: '[]') ?>"><?= htmlspecialchars($sup['supplier_legal_name']) ?></option>
            <?php endforeach; ?>
          </select>
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

          var advancePct = num(f('advance_pct'));
          var advanceAmount = totalPayable * (advancePct / 100);
          f('advance_amount_inr').value = totalPayable && advancePct ? advanceAmount.toFixed(2) : '';
          f('balance_amount_inr').value = totalPayable && advancePct ? (totalPayable - advanceAmount).toFixed(2) : '';
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

  <details class="step-card" id="step-7b-supplier-po-generate"<?= $stepCardOpen('7') ?>>
    <summary><?= $stepStatusIcon($stepStates['7']) ?><h2>Step 7b &middot; Generate Supplier PO</h2></summary>
    <?php if ($supplierPoLocked): ?>
      <p class="muted">Confirm buyer acknowledgement first to unlock this gate.</p>
    <?php elseif ($supplierPo): ?>
      <div class="kv-grid">
        <div><span class="k">Supplier</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_legal_name']) ?></span></div>
        <div><span class="k">Supplier PO Ref</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_po_reference']) ?></span></div>
        <div><span class="k">Material</span><span class="v"><?= htmlspecialchars($supplierPo['material_stone_type'] ?? '—') ?></span></div>
        <div><span class="k">Total Payable (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['total_payable_inr'] ?? '—') ?></span></div>
        <div><span class="k">Status</span><span class="v"><?= htmlspecialchars($supplierPo['status']) ?></span></div>
      </div>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="SUPPO">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('7b') ?><button type="submit" class="<?= $__docBtnClass('SUPPO') ?>" data-loading-text="Generating…">Generate Supplier PO</button>
      </form>
    <?php else: ?>
      <p class="muted">Save Supplier PO Terms first (Step 7a).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once the supplier signs and returns it</div>

  <details class="step-card" id="step-7c-supplier-po-signed"<?= $stepCardOpen('7') ?>>
    <summary><?= $stepStatusIcon($stepStates['7']) ?><h2>Step 7c &middot; Confirm Supplier Signed &amp; Returned PO</h2></summary>
    <?php if ($supplierPoLocked): ?>
      <p class="muted">Confirm buyer acknowledgement first to unlock this gate.</p>
    <?php elseif ($supplierPo && $supplierPo['status'] !== 'signed' && $suppoDocGenerated): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/signed">
        <?= Csrf::field() ?>
        <?= $stepBadge('7c') ?><button type="submit" class="btn-sm btn-success">Confirm Supplier Signed &amp; Returned PO (unlocks Stage 6)</button>
      </form>
    <?php elseif ($supplierPo && $supplierPo['status'] !== 'signed'): ?>
      <p class="muted small">Generate the Supplier PO document above before confirming the supplier's signature — they can't have signed and returned a document that was never sent.</p>
    <?php elseif ($supplierPo): ?>
      <p>Supplier has signed and returned the PO.</p>
    <?php else: ?>
      <p class="muted">Save Supplier PO Terms first (Step 7a).</p>
    <?php endif; ?>

    <?php if (!$supplierPoLocked && $supplierPo && $canManageSupplierPayments): ?>
      <h3 style="font-size:0.95em; margin-top:14px;">Supplier Payments</h3>
      <div class="kv-grid">
        <div><span class="k">Advance (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['advance_amount_inr'] ?? '—') ?><?= !empty($supplierPo['advance_paid_at']) ? ' — paid ' . htmlspecialchars((string) ($supplierPo['advance_paid_amount_inr'] ?? $supplierPo['advance_amount_inr'])) . ' on ' . htmlspecialchars($supplierPo['advance_paid_at']) : '' ?></span></div>
        <div><span class="k">Balance (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['balance_amount_inr'] ?? '—') ?><?= !empty($supplierPo['balance_paid_at']) ? ' — paid ' . htmlspecialchars((string) ($supplierPo['balance_paid_amount_inr'] ?? $supplierPo['balance_amount_inr'])) . ' on ' . htmlspecialchars($supplierPo['balance_paid_at']) : '' ?></span></div>
      </div>
      <?php if (empty($supplierPo['advance_paid_at'])): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/advance-paid" style="display:inline-block; margin-right:8px;">
          <?= Csrf::field() ?>
          <label>Date<input type="date" name="paid_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></label>
          <label>Amount Paid (INR)<input type="number" step="0.01" min="0.01" name="amount_paid_inr" value="<?= htmlspecialchars((string) ($supplierPo['advance_amount_inr'] ?? '')) ?>" required></label>
          <button type="submit" class="btn-sm">Mark Advance Paid</button>
        </form>
      <?php endif; ?>
      <?php if (empty($supplierPo['balance_paid_at'])): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/balance-paid" style="display:inline-block;">
          <?= Csrf::field() ?>
          <label>Date<input type="date" name="paid_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></label>
          <label>Amount Paid (INR)<input type="number" step="0.01" min="0.01" name="amount_paid_inr" value="<?= htmlspecialchars((string) ($supplierPo['balance_amount_inr'] ?? '')) ?>" required></label>
          <button type="submit" class="btn-sm">Mark Balance Paid</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">attach their signed copy for the file</div>

  <details class="step-card" id="step-7d-supplier-po-attach"<?= $stepCardOpen('7') ?>>
    <summary><?= $stepStatusIcon($stepStates['7']) ?><h2>Step 7d &middot; Attach Supplier PO Acknowledgment</h2></summary>
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
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/documents" enctype="multipart/form-data" style="margin-top:6px">
        <?= Csrf::field() ?>
        <input type="file" name="document" required>
        <?= $stepBadge('7d') ?><button type="submit" class="btn-sm">Attach Supplier PO Acknowledgment</button>
      </form>
    <?php else: ?>
      <p class="muted">Save Supplier PO Terms first (Step 7a).</p>
    <?php endif; ?>
  </details>

  </div>

  <details class="step-card" id="step-production"<?= $stepCardOpen('8') ?>>
    <summary><?= $stepStatusIcon($stepStates['8']) ?><h2>Step 8 &middot; Production &amp; Estimated Shipment</h2></summary>
    <div class="kv-grid">
      <div><span class="k">Production Status</span><span class="v"><?= htmlspecialchars($order['production_status_text'] ?? 'Not yet commenced') ?></span></div>
      <div><span class="k">Est. Shipment</span><span class="v"><?= htmlspecialchars($order['est_shipment_date_text'] ?? 'To Be Confirmed') ?></span></div>
    </div>
    <p class="muted small">Every update here is logged<?php if ($canViewAuditLog): ?> — <a href="/orders/<?= (int) $order['id'] ?>/audit-log">view update history</a><?php endif; ?>. Changing either field notifies the client (order update + portal).</p>
    <?php if ($stage4 && $stage4['status'] !== 'locked'): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/production-status">
        <?= Csrf::field() ?>
        <label>Production Status<input type="text" name="production_status_text" placeholder="e.g. Cutting and finishing in progress — 30% complete"></label>
        <label>Estimated Shipment<input type="text" name="est_shipment_date_text" placeholder="e.g. Week of 15 October 2026"></label>
        <label>Estimated Shipment Date <small class="muted">(drives the 7-day pre-shipment inspection reminder below)</small><input type="date" name="est_shipment_date" value="<?= htmlspecialchars($order['est_shipment_date'] ?? '') ?>"></label>
        <button type="submit" class="btn-sm">Update</button>
      </form>
    <?php endif; ?>

    <?php if ($pendingInspectionTask && $canManageOrderFinancials): ?>
      <h3 style="font-size:0.95em; margin-top:14px;">3rd-Party Pre-Shipment Inspection (step 8a)</h3>
      <p class="muted small">This order has a Pre-Shipment Inspection on its Compliance Checklist (not marked not-applicable) — staff get reminded starting 7 days before the estimated shipment date above. If NexaCrest pays the inspection fee, record it below so it's included as an order expense; if the client pays it directly, there's nothing to record.</p>
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
          <button type="submit" class="btn-sm">Save</button>
        </form>
      </details>
    <?php endif; ?>
  </details>

  <?php $freightLocked = !$isFob && (!$stage6 || $stage6['status'] === 'locked'); ?>

  <div class="step-group">
    <div class="step-group-header"><span class="step-group-num">STEP 9</span><h3>Freight Payment</h3></div>

  <details class="step-card" id="step-9a-freight-terms"<?= $stepCardOpen('9') ?>>
    <summary><?= $stepStatusIcon($stepStates['9']) ?><h2>Step 9a &middot; Save Freight Terms<?= $isFob ? ' (skipped — FOB)' : '' ?></h2></summary>
    <?php if ($isFob): ?>
      <p class="muted">FOB order — buyer arranges and pays freight directly. This stage is auto-skipped once Stage 5 passes.</p>
      <?php if ($freightSkipped): ?><p>Confirmed skipped: <?= htmlspecialchars($stage6['skip_reason']) ?></p><?php endif; ?>
    <?php elseif ($freightLocked): ?>
      <p class="muted">Confirm the Supplier PO is signed first to unlock this gate.</p>
    <?php elseif (!$freight): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/freight-terms">
        <?= Csrf::field() ?>
        <label>Confirmed Freight Rate<input type="text" name="confirmed_freight_rate"></label>
        <label>Insurance Amount (CIF only)<input type="text" name="insurance_amount"></label>
        <label>Freight Forwarder Name<input type="text" name="freight_forwarder_name"></label>
        <label>Freight Forwarder Contact<input type="text" name="freight_forwarder_contact"></label>
        <?= $stepBadge('9a') ?><button type="submit" class="btn-sm">Save Freight Terms</button>
      </form>
    <?php else: ?>
      <div class="kv-grid">
        <div><span class="k">Confirmed Rate</span><span class="v"><?= $freight['confirmed_freight_rate'] !== null ? htmlspecialchars($freight['confirmed_freight_rate']) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?></span></div>
        <div><span class="k">Insurance</span><span class="v"><?= $freight['insurance_amount'] !== null ? htmlspecialchars($freight['insurance_amount']) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : 'NIL' ?></span></div>
        <div><span class="k">Forwarder</span><span class="v"><?= htmlspecialchars($freight['freight_forwarder_name'] ?? '—') ?></span></div>
      </div>
      <p class="muted small">Terms already saved — see Step 9b to generate the debit note.</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">generates the debit note for the buyer</div>

  <details class="step-card" id="step-9b-freight-debit-note"<?= $stepCardOpen('9') ?>>
    <summary><?= $stepStatusIcon($stepStates['9']) ?><h2>Step 9b &middot; Generate Debit Note<?= $isFob ? ' (skipped — FOB)' : '' ?></h2></summary>
    <?php if ($isFob): ?>
      <p class="muted">FOB order — this stage is auto-skipped.</p>
    <?php elseif ($freightLocked): ?>
      <p class="muted">Confirm the Supplier PO is signed first to unlock this gate.</p>
    <?php elseif (!$freight): ?>
      <p class="muted">Save Freight Terms first (Step 9a).</p>
    <?php else: ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="FDN">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('9b') ?><button type="submit" class="<?= $__docBtnClass('FDN') ?>" data-loading-text="Generating…">Generate Debit Note</button>
      </form>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once their payment lands</div>

  <details class="step-card" id="step-9c-freight-remittance"<?= $stepCardOpen('9') ?>>
    <summary><?= $stepStatusIcon($stepStates['9']) ?><h2>Step 9c &middot; Record Freight Remittance<?= $isFob ? ' (skipped — FOB)' : '' ?></h2></summary>
    <?php if ($isFob): ?>
      <p class="muted">FOB order — this stage is auto-skipped.</p>
    <?php elseif ($freightLocked): ?>
      <p class="muted">Confirm the Supplier PO is signed first to unlock this gate.</p>
    <?php elseif (!$freight): ?>
      <p class="muted">Save Freight Terms first (Step 9a).</p>
    <?php elseif (!$freightCleared && (!$payment || $payment['freight_remittance_received_at'] === null)): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/freight">
        <?= Csrf::field() ?>
        <label>Freight Amount Received * <small class="muted">(in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="freight_amount" required></label>
        <label>Received On<input type="date" name="freight_received_at" value="<?= date('Y-m-d') ?>"></label>
        <?= $stepBadge('9c') ?><button type="submit" class="btn-sm">Record Freight Remittance</button>
      </form>
    <?php elseif ($freightCleared): ?>
      <p>Freight payment cleared.</p>
      <?php $renderInrActualWidget('freight', 'Freight Payment'); ?>
      <?php $renderExchangeRateWidget('freight', 'Freight Payment'); ?>
    <?php else: ?>
      <p class="muted">Already recorded — see Step 9d to mark it cleared.</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">after funds are confirmed cleared</div>

  <details class="step-card" id="step-9d-freight-clear"<?= $stepCardOpen('9') ?>>
    <summary><?= $stepStatusIcon($stepStates['9']) ?><h2>Step 9d &middot; Mark Freight Cleared<?= $isFob ? ' (skipped — FOB)' : '' ?></h2></summary>
    <?php if ($isFob): ?>
      <p class="muted">FOB order — this stage is auto-skipped.</p>
    <?php elseif ($freightLocked): ?>
      <p class="muted">Confirm the Supplier PO is signed first to unlock this gate.</p>
    <?php elseif (!$freight): ?>
      <p class="muted">Save Freight Terms first (Step 9a).</p>
    <?php elseif ($freightCleared): ?>
      <p>Freight payment already cleared.</p>
    <?php elseif ($payment && $payment['freight_remittance_received_at'] !== null): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/freight/clear" onsubmit="return confirm('Mark the freight payment cleared? This unlocks Stage 7 — confirm the funds have actually landed first.');">
        <?= Csrf::field() ?>
        <label>Cleared On<input type="date" name="freight_cleared_at" value="<?= date('Y-m-d') ?>"></label>
        <?= $stepBadge('9d') ?><button type="submit" class="btn-sm btn-success">Mark Freight Cleared (unlocks Stage 7)</button>
      </form>
    <?php else: ?>
      <p class="muted">Record the freight remittance first (Step 9c).</p>
    <?php endif; ?>
  </details>

  <?php $packingLocked = !$stage7 || $stage7['status'] === 'locked'; ?>

  </div>

  <div class="step-group">
    <div class="step-group-header"><span class="step-group-num">STEP 9</span><h3>Packing &amp; BL Instruction</h3></div>

  <details class="step-card" id="step-9e-packing"<?= $stepCardOpen('9') ?>>
    <summary><?= $stepStatusIcon($stepStates['9']) ?><h2>Step 9e &middot; Save Packing &amp; Crates</h2></summary>
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

        <p class="muted small">Crate-level breakdown (add one row per physical crate):</p>
        <table class="list">
          <tr><th>Crate No.</th><th>Marks &amp; Numbers</th><th>Product</th><th>L×W×H (cm)</th><th>Pcs</th><th>Net (kg)</th><th>Gross (kg)</th><th>CBM</th><th>HS Code</th></tr>
          <?php $existingCrates = $crates ?: [[], [], []]; foreach ($existingCrates as $c): ?>
          <tr>
            <td><input type="text" name="crate_no[]" value="<?= htmlspecialchars($c['crate_no'] ?? '') ?>" style="width:70px;"></td>
            <td><input type="text" name="crate_marks_numbers[]" value="<?= htmlspecialchars($c['marks_numbers'] ?? '') ?>" style="width:130px;"></td>
            <td><input type="text" name="crate_product_description[]" value="<?= htmlspecialchars($c['product_description'] ?? '') ?>" style="width:110px;"></td>
            <td><input type="text" name="crate_dimensions[]" value="<?= htmlspecialchars($c['dimensions_lwh_cm'] ?? '') ?>" style="width:80px;"></td>
            <td><input type="text" name="crate_pcs[]" value="<?= htmlspecialchars((string) ($c['pcs'] ?? '')) ?>" style="width:50px;"></td>
            <td><input type="text" name="crate_net_weight_kg[]" value="<?= htmlspecialchars((string) ($c['net_weight_kg'] ?? '')) ?>" style="width:70px;"></td>
            <td><input type="text" name="crate_gross_weight_kg[]" value="<?= htmlspecialchars((string) ($c['gross_weight_kg'] ?? '')) ?>" style="width:70px;"></td>
            <td><input type="text" name="crate_cbm[]" value="<?= htmlspecialchars((string) ($c['cbm'] ?? '')) ?>" style="width:60px;"></td>
            <td><input type="text" name="crate_hs_code[]" value="<?= htmlspecialchars($c['hs_code'] ?? '') ?>" style="width:70px;"></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?= $stepBadge('9e') ?><button type="submit" class="btn-sm">Save Packing &amp; Crates</button>
      </form>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">generate the Packing List from those figures</div>

  <details class="step-card" id="step-9f-packing-list"<?= $stepCardOpen('9') ?>>
    <summary><?= $stepStatusIcon($stepStates['9']) ?><h2>Step 9f &middot; Generate Packing List</h2></summary>
    <?php if ($packingLocked): ?>
      <p class="muted"><?= $isFob ? 'Confirm the Supplier PO is signed first' : 'Clear the freight payment first' ?> to unlock this gate.</p>
    <?php elseif ($packing): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="PL">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('9f') ?><button type="submit" class="<?= $__docBtnClass('PL') ?>" data-loading-text="Generating…">Generate Packing List</button>
      </form>
    <?php else: ?>
      <p class="muted">Save Packing &amp; Crates first (Step 9e).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once the vessel/sailing is confirmed</div>

  <details class="step-card" id="step-9g-shipping-details"<?= $stepCardOpen('9') ?>>
    <summary><?= $stepStatusIcon($stepStates['9']) ?><h2>Step 9g &middot; Save Shipping Details</h2></summary>
    <?php if ($packingLocked): ?>
      <p class="muted"><?= $isFob ? 'Confirm the Supplier PO is signed first' : 'Clear the freight payment first' ?> to unlock this gate.</p>
    <?php else: ?>
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
        <?= $stepBadge('9g') ?><button type="submit" class="btn-sm">Save Shipping Details</button>
      </form>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">tell the CHA / shipping line how to issue the BL</div>

  <details class="step-card" id="step-9h-bl-instruction"<?= $stepCardOpen('9') ?>>
    <summary><?= $stepStatusIcon($stepStates['9']) ?><h2>Step 9h &middot; Generate BL Instruction Sheet</h2></summary>
    <?php if ($packingLocked): ?>
      <p class="muted"><?= $isFob ? 'Confirm the Supplier PO is signed first' : 'Clear the freight payment first' ?> to unlock this gate.</p>
    <?php elseif ($shipping): ?>
      <h3 style="font-size:0.95em; margin-top:0;">BL Instruction Sheet (for your CHA / shipping line)</h3>
      <p class="muted small">Generates the shipping-booking worksheet your CHA or shipping line needs before confirming the BL booking — shipper, consignee/notify party, shipment and container details from above, cargo description from the order's products, and the mandatory BL-type instruction from Settings. Review the Shipping and Packing details above first; anything left blank there shows as "To Be Confirmed" on the sheet.</p>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="BLI">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('9h') ?><button type="submit" class="<?= $__docBtnClass('BLI') ?>" data-loading-text="Generating…">Generate BL Instruction Sheet</button>
      </form>
    <?php else: ?>
      <p class="muted">Save Shipping Details first (Step 9g).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">after the CHA draft-review loop below</div>

  <details class="step-card" id="step-9i-bl-issued"<?= $stepCardOpen('9') ?>>
    <summary><?= $stepStatusIcon($stepStates['9']) ?><h2>Step 9i &middot; Confirm BL Issued <small class="muted">(after the CHA draft-review loop)</small></h2></summary>
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
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/draft-bl" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <label>Draft BL from CHA *<input type="file" name="draft_bl" accept=".pdf" required></label>
          <button type="submit" class="btn-sm">Log Draft BL for Review</button>
        </form>
      <?php endif; ?>

      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-issued" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <label>BL Number *<input type="text" name="bl_number" required></label>
        <label>BL Date<input type="date" name="bl_date" value="<?= date('Y-m-d') ?>"></label>
        <label>Scanned BL Copy <small class="muted">(optional — can be attached later)</small><input type="file" name="bl_copy" accept=".pdf,.jpg,.jpeg,.png"></label>
        <?= $stepBadge('9i') ?><button type="submit" class="btn-sm btn-success">Confirm BL Issued (unlocks Stage 8)</button>
      </form>
    <?php else: ?>
      <p>Bill of Lading recorded: <strong><?= htmlspecialchars((string) ($shipping['bl_number'] ?? '')) ?></strong> (<?= htmlspecialchars((string) ($shipping['bl_date'] ?? '')) ?>)</p>
      <?php if (!empty($shipping['bl_copy_file_id'])): ?>
        <p class="muted small"><a href="/file-store/<?= (int) $shipping['bl_copy_file_id'] ?>/download">Download scanned BL copy</a></p>
      <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-copy" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <label>Scanned BL Copy<input type="file" name="bl_copy" accept=".pdf,.jpg,.jpeg,.png" required></label>
          <button type="submit" class="btn-sm">Attach Scanned BL Copy</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </details>

  <?php
    $ciBalanceLocked = !$stage8 || $stage8['status'] === 'locked';
    // Step lettering continues from Freight/Packing's 9a-9i for an FOB
    // order (balance settles before shipment, still within user step 9);
    // a non-FOB (post-BL) order uses its own Step 10 lettering instead,
    // matching the user's own 9/10 split for this leg.
    $ciBadge = static fn (string $fobLetter, string $postBlLetter): string => $isFob ? $fobLetter : $postBlLetter;
  ?>

  </div>

  <div class="step-group">
    <div class="step-group-header"><span class="step-group-num">STEP 9-10</span><h3>Commercial Invoice &amp; Balance</h3></div>

  <details class="step-card" id="step-ci-generate"<?= $stepCardOpen('9-10') ?>>
    <summary><?= $stepStatusIcon($stepStates['9-10']) ?><h2>Step <?= $ciBadge('9j', '10a') ?> &middot; Generate Commercial Invoice<?= $isFob ? ' (before shipment)' : ' (against BL)' ?></h2></summary>
    <?php
      // Batch 3 #11 — the Commercial Invoice is conventionally dated to match the recorded BL date;
      // nothing enforces this, so flag it as an advisory (not a hard block — a same-day match is the
      // norm but not the only valid business reason to differ) right where staff generate the CI.
      $__ciBlDateMismatch = $shipping && !empty($shipping['bl_date']) && $shipping['bl_date'] !== date('Y-m-d');
    ?>
    <?php if ($ciBalanceLocked): ?>
      <p class="muted">Confirm BL issuance first to unlock this gate.</p>
    <?php else: ?>
      <?php if ($__ciBlDateMismatch): ?>
        <p class="alert alert-warning">&#9888; Recorded BL date is <strong><?= htmlspecialchars($shipping['bl_date']) ?></strong>, not today. The Commercial Invoice is conventionally dated to match the BL — double-check before generating it if that's not intentional here.</p>
      <?php endif; ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="CI">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge($ciBadge('9j', '10a')) ?><button type="submit" class="<?= $__docBtnClass('CI') ?>" data-loading-text="Generating…">Generate Commercial Invoice</button>
      </form>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">timed with sending the scanned BL</div>

  <details class="step-card" id="step-ci-scanned-bl-sent"<?= $stepCardOpen('9-10') ?>>
    <summary><?= $stepStatusIcon($stepStates['9-10']) ?><h2>Step <?= $ciBadge('9k', '10b') ?> &middot; Mark Scanned BL Copy Sent to Buyer</h2></summary>
    <?php if ($ciBalanceLocked): ?>
      <p class="muted">Confirm BL issuance first to unlock this gate.</p>
    <?php elseif ($shipping && !$shipping['scanned_bl_sent_to_buyer_at']): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/scanned-bl-sent">
        <?= Csrf::field() ?>
        <?= $stepBadge($ciBadge('9k', '10b')) ?><button type="submit" class="btn-sm">Mark Scanned BL Copy Sent to Buyer</button>
      </form>
    <?php elseif ($shipping): ?>
      <p class="muted small">Scanned BL copy sent to buyer.</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once the buyer's balance payment lands</div>

  <details class="step-card" id="step-balance-record"<?= $stepCardOpen('9-10') ?>>
    <summary><?= $stepStatusIcon($stepStates['9-10']) ?><h2>Step <?= $ciBadge('9l', '10c') ?> &middot; Record Balance Remittance</h2></summary>
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
        <label>Balance Amount Received * <small class="muted">(in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="balance_amount" required value="<?= htmlspecialchars((string) ($payment['balance_amount'] ?? '')) ?>"></label>
        <label>Received On<input type="date" name="balance_received_at" value="<?= date('Y-m-d') ?>"></label>
        <?= $stepBadge($ciBadge('9l', '10c')) ?><button type="submit" class="btn-sm">Record Balance Remittance</button>
      </form>
      <?php endif; ?>
    <?php elseif ($balanceCleared): ?>
      <p>Balance payment already cleared.</p>
    <?php else: ?>
      <p class="muted">Already recorded — see the next step to mark it cleared.</p>
    <?php endif; ?>
    <?php if (!$ciBalanceLocked): ?>
      <?php $renderInrActualWidget('balance', 'Balance Payment'); ?>
      <?php $renderExchangeRateWidget('balance', 'Balance Payment'); ?>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">after funds are confirmed cleared</div>

  <details class="step-card" id="step-balance-clear"<?= $stepCardOpen('9-10') ?>>
    <summary><?= $stepStatusIcon($stepStates['9-10']) ?><h2>Step <?= $ciBadge('9m', '10d') ?> &middot; Mark Balance Cleared</h2></summary>
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
        <?= $stepBadge($ciBadge('9m', '10d')) ?><button type="submit" class="btn-sm btn-success">Mark Balance Cleared (unlocks Stage 9)</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Record the balance remittance first.</p>
    <?php endif; ?>
  </details>

  <?php if ($canManageOrderFinancials): ?>
  </div>

  <details class="step-card" id="order-financials"<?= $stepCardOpen('11') ?>>
    <summary><?= $stepStatusIcon($stepStates['11']) ?><h2>Step 11 &middot; Closure &mdash; Financials, Benefits &amp; Costs</h2></summary>
    <p class="muted small">Everything financial about this specific order, in one place — visible only to Super Admin and roles specifically granted the "Manage order financials" permission.</p>

    <h3 style="font-size:0.95em;">Government Export Benefits Claimed</h3>
    <?php $govtBenefitsApplicable = in_array($order['status'], ['shipped_docs_sent', 'complete'], true) && !empty($payment['balance_remittance_received_at']); ?>
    <?php if (!$govtBenefitsApplicable): ?>
      <p class="muted">Not applicable until the order is complete and the CI (balance) remittance has been received — government benefit schemes only come into play once the full proceeds have actually landed.</p>
    <?php else: ?>
    <?php if (empty($linkedExportBenefits)): ?>
      <p class="muted">Nothing claimed against this order yet.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Claimed</th><th>Scheme</th><th>Ref.</th><th>Claimed Amount</th><th>Received</th><th>Status</th><th></th></tr>
        <?php foreach ($linkedExportBenefits as $b): ?>
        <tr>
          <td><?= htmlspecialchars((string) $b['claimed_at']) ?></td>
          <td><?= htmlspecialchars($b['scheme_name']) ?></td>
          <td><?= htmlspecialchars($b['reference_number'] ?? '—') ?></td>
          <td><?= number_format((float) $b['claimed_amount'], 2) ?> <?= htmlspecialchars($b['currency_code']) ?></td>
          <td><?= $b['received_amount'] !== null ? number_format((float) $b['received_amount'], 2) . ' ' . htmlspecialchars($b['currency_code']) : '<span class="muted">—</span>' ?></td>
          <td><?= $b['received_amount'] !== null ? '<span class="badge good">Received</span>' : '<span class="badge">Claimed</span>' ?></td>
          <td>
            <?php if ($b['received_amount'] === null): ?>
              <details>
                <summary class="small">Mark received</summary>
                <form method="post" action="/orders/<?= (int) $order['id'] ?>/export-benefits/<?= (int) $b['id'] ?>/received">
                  <?= Csrf::field() ?>
                  <label>Received Amount *<input type="text" name="received_amount" required></label>
                  <label>Received On *<input type="date" name="received_at" value="<?= date('Y-m-d') ?>" required></label>
                  <button type="submit" class="btn-sm">Save</button>
                </form>
              </details>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <details>
      <summary>Record a new claim for this order</summary>
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

    <h3 style="font-size:0.95em; margin-top:14px;">Expenses Imported from Zoho Books</h3>
    <?php if (empty($linkedCaExpenses)): ?>
      <p class="muted">Nothing linked to this order yet — link an expense from <a href="/ca/expenses">the Expenses register</a> if one belongs here.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Date</th><th>Category</th><th>Vendor</th><th>Amount</th></tr>
        <?php foreach ($linkedCaExpenses as $e): ?>
        <tr>
          <td><?= htmlspecialchars((string) $e['expense_date']) ?></td>
          <td><?= htmlspecialchars($e['category']) ?></td>
          <td><?= htmlspecialchars((string) ($e['vendor_name'] ?? '—')) ?></td>
          <td><?= number_format((float) $e['amount'], 2) ?> <?= htmlspecialchars($e['currency_code']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php $lcDetails = $orderLcDetails ?: ['lc_number' => null, 'lc_file_id' => null, 'bank_loan_amount' => null, 'bank_loan_interest_rate' => null, 'bank_loan_interest_paid' => null]; ?>
    <h3 style="font-size:0.95em; margin-top:14px;">Letter of Credit &amp; Bank Loan</h3>
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

    <h3 style="font-size:0.95em; margin-top:14px;">Travel Expenses — Factory Visits (step 11a)</h3>
      <p class="muted small">Every order needs 3-4 trips to the factory; record the distance (for reference) and the percentage of the order's FOB value to charge as travel expenses.</p>
      <div class="kv-grid">
        <div><span class="k">Factory Distance</span><span class="v"><?= $orderTravelExpenses && $orderTravelExpenses['factory_distance_km'] !== null ? number_format((float) $orderTravelExpenses['factory_distance_km'], 2) . ' km' : '—' ?></span></div>
        <div><span class="k">Percentage</span><span class="v"><?= $orderTravelExpenses ? number_format((float) $orderTravelExpenses['expense_pct'], 2) . '%' : 'Not yet recorded' ?></span></div>
        <div><span class="k">Amount</span><span class="v"><?= $orderTravelExpenses ? number_format((float) $orderTravelExpenses['expense_amount'], 2) : '—' ?></span></div>
      </div>
      <details>
        <summary><?= $orderTravelExpenses ? 'Edit travel expenses' : 'Record travel expenses' ?></summary>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/travel-expenses">
          <?= Csrf::field() ?>
          <label>Factory Distance (km) <small class="muted">(min 175km — for reference only)</small><input type="text" name="factory_distance_km" value="<?= htmlspecialchars($orderTravelExpenses && $orderTravelExpenses['factory_distance_km'] !== null ? (string) $orderTravelExpenses['factory_distance_km'] : '') ?>"></label>
          <label>Travel Expense % of FOB Value *
            <select name="expense_pct" required>
              <?php $savedPct = $orderTravelExpenses ? (string) (float) $orderTravelExpenses['expense_pct'] : ''; ?>
              <?php foreach (['5', '7', '10', '15'] as $pct): ?>
                <option value="<?= $pct ?>" <?= $savedPct === $pct ? 'selected' : '' ?>><?= $pct ?>%</option>
              <?php endforeach; ?>
              <option value="other" <?= !in_array($savedPct, ['5', '7', '10', '15'], true) && $savedPct !== '' ? 'selected' : '' ?>>Other — specify</option>
            </select>
          </label>
          <label>Custom % <small class="muted">(only used when "Other" is selected above)</small><input type="text" name="expense_pct_other" value="<?= !in_array($savedPct, ['5', '7', '10', '15', ''], true) ? htmlspecialchars($savedPct) : '' ?>"></label>
          <button type="submit" class="btn-sm">Save</button>
        </form>
      </details>

    <h3 style="font-size:0.95em; margin-top:14px;">Other Order Costs</h3>
    <p class="muted small">Costs this order incurred that aren't tracked anywhere else in the system (ECGC insurance, buyer due diligence, CHA, documentation, port charges, inland transport, bank charges, commission, packing/crates, other) — all assumed INR. Supplier cost, ocean freight, and insurance are pulled automatically below and never entered twice here.</p>
    <?php if (empty($orderCostEntries)): ?>
      <p class="muted">No other costs recorded for this order yet.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Date</th><th>Category</th><th>Description</th><th>Amount (INR)</th><th>Recorded By</th><th></th></tr>
        <?php foreach ($orderCostEntries as $c): ?>
        <tr>
          <td><?= htmlspecialchars((string) ($c['incurred_at'] ?? '—')) ?></td>
          <td><?= htmlspecialchars($costEntryCategories[$c['category']] ?? $c['category']) ?></td>
          <td><?= htmlspecialchars($c['description'] ?? '—') ?></td>
          <td><?= number_format((float) $c['amount_inr'], 2) ?></td>
          <td><?= htmlspecialchars($c['recorded_by_name']) ?></td>
          <td>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/cost-entries/<?= (int) $c['id'] ?>/delete" onsubmit="return confirm('Remove this cost entry?');">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm btn-danger">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <details>
      <summary>Record a cost for this order</summary>
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
        <button type="submit" class="btn-sm">Record Cost</button>
      </form>
    </details>

    <?php if ($profitability): ?>
      <h3 style="font-size:0.95em; margin-top:14px;">Profitability Summary</h3>
      <div class="kv-grid">
        <div><span class="k">Revenue (INR)<?= $profitability['revenue_is_estimated'] ? ' — estimated' : '' ?></span><span class="v"><?= number_format($profitability['revenue_inr'], 2) ?></span></div>
        <div><span class="k">Govt. Export Benefits (INR)<?= $profitability['export_benefits_is_estimated'] ? ' — claimed, not yet received' : '' ?></span><span class="v"><?= number_format($profitability['export_benefits_inr'], 2) ?></span></div>
        <div><span class="k">Supplier Cost (INR)</span><span class="v"><?= number_format($profitability['supplier_cost_inr'], 2) ?></span></div>
        <div><span class="k">Ocean Freight (INR)</span><span class="v"><?= number_format($profitability['freight_cost_inr'], 2) ?></span></div>
        <div><span class="k">Insurance (INR)</span><span class="v"><?= number_format($profitability['insurance_cost_inr'], 2) ?></span></div>
        <div><span class="k">Other Costs (INR)</span><span class="v"><?= number_format($profitability['other_costs_inr'], 2) ?></span></div>
        <div><span class="k">Total Order Cost (INR)</span><span class="v"><?= number_format($profitability['total_cost_inr'], 2) ?></span></div>
        <div><span class="k">Order Profit (INR)</span><span class="v"><?= number_format($profitability['profit_inr'], 2) ?></span></div>
        <div><span class="k">Order Margin %</span><span class="v"><?= $profitability['margin_pct'] !== null ? number_format($profitability['margin_pct'], 2) . '%' : '—' ?></span></div>
      </div>
      <?php if ($profitability['revenue_is_estimated']): ?>
        <p class="muted small">Revenue is estimated using the order's assumed exchange rate — it becomes the exact figure once both the advance and balance legs clear with their INR actual recorded. See <a href="/reports/order-profitability">the Order Profitability report</a> for every order side by side.</p>
      <?php endif; ?>
    <?php endif; ?>

    <h3 style="font-size:0.95em; margin-top:14px;">Internal-Only Financial Annexure</h3>
    <p class="muted small">A single internal PDF combining everything above, for staff/CA use only. This is structurally excluded from the client portal and can never be attached to or sent alongside any client-facing document, enabled or not.</p>
    <?php if ($canManageCaInternalDoc): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/ca-internal-doc/toggle" style="margin-bottom:6px;">
        <?= Csrf::field() ?>
        <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal;">
          <input type="checkbox" name="enabled" value="1" <?= !empty($order['ca_internal_doc_enabled']) ? 'checked' : '' ?> onchange="this.form.submit()">
          Enable internal financial annexure for this order
        </label>
      </form>
      <?php if (!empty($order['ca_internal_doc_enabled'])): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/ca-internal-doc/generate" style="display:inline;">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm">Generate Internal Financial Annexure (PDF)</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted small">Internal financial annexure is currently <strong><?= !empty($order['ca_internal_doc_enabled']) ? 'enabled' : 'disabled' ?></strong> for this order — only Admin/MD/ED (or someone specifically granted the permission) can change this or generate it.</p>
    <?php endif; ?>
    <?php if ($caInternalDoc): ?>
      <p class="small" style="margin-top:6px;">
        Latest: <a href="/documents/<?= (int) $caInternalDoc['id'] ?>/download?format=pdf"><?= htmlspecialchars($caInternalDoc['document_reference']) ?></a>
        (generated <?= htmlspecialchars((string) $caInternalDoc['generated_at']) ?>) — <span class="badge">INTERNAL ONLY — never shown to client</span>
      </p>
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
    // Badge lettering here intentionally skips 'e' — Mark Fully Settled
    // (11e) was already established before this card was split further,
    // and it's the one action in this sequence that happens LAST
    // (after Close Order), so its letter doesn't sort before the rest.
  ?>

  <div class="step-group">
    <div class="step-group-header"><span class="step-group-num">STEP 11</span><h3>Closure &mdash; Documents &amp; Settlement</h3></div>

  <details class="step-card" id="step-11a-coo-prep"<?= $stepCardOpen('11') ?>>
    <summary><?= $stepStatusIcon($stepStates['11']) ?><h2>Step 11a &middot; Generate COO Prep Sheet</h2></summary>
    <?php if ($despatchLocked): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php else: ?>
      <h3 style="font-size:0.95em; margin-top:0;">COO Prep Sheet (internal — for the Chamber of Commerce application)</h3>
      <p class="muted small">Generates an internal worksheet summarizing everything needed to apply for the Certificate of Origin with the Chamber of Commerce — shipper, consignee, shipment, and cargo details, the same source data as the BL Instruction Sheet. Never shown to the client.</p>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline" onsubmit="return confirmDocumentRegeneration(this);">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="COOPREP">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('11a') ?><button type="submit" class="<?= $__docBtnClass('COOPREP') ?>" data-loading-text="Generating…">Generate COO Prep Sheet</button>
      </form>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once the CHA delivers the original BLs</div>

  <details class="step-card" id="step-11b-bl-originals-received"<?= $stepCardOpen('11') ?>>
    <summary><?= $stepStatusIcon($stepStates['11']) ?><h2>Step 11b &middot; Record Original BLs Received from CHA</h2></summary>
    <?php if ($despatchLocked): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php elseif (!$canManageShipping): ?>
      <p class="muted">Only Logistics can record the original BLs.</p>
    <?php elseif (!$shipping || !$shipping['bl_originals_received_at']): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-originals-received">
        <?= Csrf::field() ?>
        <label>No. of Original BL Copies Received<input type="number" name="bl_originals_count" value="3"></label>
        <?= $stepBadge('11b') ?><button type="submit" class="btn-sm">Record Original BLs Received from CHA</button>
      </form>
    <?php else: ?>
      <p class="muted small">Original BLs received (<?= (int) $shipping['bl_originals_received_count'] ?>).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">endorse them over to the buyer's bank</div>

  <details class="step-card" id="step-11c-bl-endorsed-mark"<?= $stepCardOpen('11') ?>>
    <summary><?= $stepStatusIcon($stepStates['11']) ?><h2>Step 11c &middot; Mark Original BLs Endorsed by NexaCrest</h2></summary>
    <?php if ($despatchLocked): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php elseif ($canManageShipping && $shipping && $shipping['bl_originals_received_at'] && !$shipping['bl_endorsed_at']): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-endorsed">
        <?= Csrf::field() ?>
        <?= $stepBadge('11c') ?><button type="submit" class="btn-sm">Mark Original BLs Endorsed by NexaCrest</button>
      </form>
    <?php elseif ($shipping && $shipping['bl_endorsed_at']): ?>
      <p class="muted small">Original BLs endorsed.</p>
    <?php else: ?>
      <p class="muted">Record the original BLs received first (Step 11b).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">save who endorsed it and when</div>

  <details class="step-card" id="step-11d-bl-endorsement-save"<?= $stepCardOpen('11') ?>>
    <summary><?= $stepStatusIcon($stepStates['11']) ?><h2>Step 11d &middot; Save BL Endorsement Details</h2></summary>
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
        <?= $stepBadge('11d') ?><button type="submit" class="btn-sm">Save BL Endorsement Details</button>
      </form>
    <?php else: ?>
      <p class="muted">Record the original BLs received first (Step 11b).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">print the endorsement for the courier pack</div>

  <details class="step-card" id="step-11f-bl-endorsement-generate"<?= $stepCardOpen('11') ?>>
    <summary><?= $stepStatusIcon($stepStates['11']) ?><h2>Step 11f &middot; Generate / Print BL Endorsement</h2></summary>
    <?php if ($despatchLocked): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php elseif ($blEndorsement): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-endorsement/generate" style="margin-top:8px;">
        <?= Csrf::field() ?>
        <?= $stepBadge('11f') ?><button type="submit" class="btn-sm btn-success">Generate / Print BL Endorsement</button>
      </form>
      <?php if ($blEndorsementDoc): ?>
        <p class="small" style="margin-top:6px;">
          Latest: <a href="/documents/<?= (int) $blEndorsementDoc['id'] ?>/download?format=pdf"><?= htmlspecialchars($blEndorsementDoc['document_reference']) ?></a>
          (generated <?= htmlspecialchars((string) $blEndorsementDoc['generated_at']) ?>)
        </p>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Save BL Endorsement Details first (Step 11d).</p>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">once documents are despatched to the buyer</div>

  <details class="step-card" id="step-11g-close-order"<?= $stepCardOpen('11') ?>>
    <summary><?= $stepStatusIcon($stepStates['11']) ?><h2>Step 11g &middot; Close Order (Document Despatch)</h2></summary>
    <?php if ($orderClosed || $order['status'] === 'shipped_docs_sent'): ?>
      <p><strong>&#10003; Documents despatched.</strong> Courier tracking: <?= htmlspecialchars($shipping['courier_tracking_number'] ?? '—') ?> — see Step 11e to mark this order fully settled.</p>
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
        <?= $stepBadge('11g') ?><button type="submit" class="btn-sm btn-danger">Override &amp; Close Order</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/close">
        <?= Csrf::field() ?>
        <label>Courier Tracking Number *<input type="text" name="courier_tracking_number" required></label>
        <?= $stepBadge('11g') ?><button type="submit" class="btn-sm btn-success">Close Order — Complete Document Set Couriered to Buyer</button>
      </form>
    <?php endif; ?>
  </details>

  <div class="step-group-connector">last — once the balance has actually cleared too</div>

  <details class="step-card" id="step-despatch"<?= $stepCardOpen('11') ?>>
    <summary><?= $stepStatusIcon($stepStates['11']) ?><h2>Step 11e &middot; Mark Fully Settled</h2></summary>
    <?php if ($orderClosed): ?>
      <p><strong>&#10003; Order fully settled and closed.</strong> Courier tracking: <?= htmlspecialchars($shipping['courier_tracking_number'] ?? '—') ?></p>
    <?php elseif ($order['status'] === 'shipped_docs_sent'): ?>
      <p><strong>&#10003; Documents sent to buyer.</strong> Courier tracking: <?= htmlspecialchars($shipping['courier_tracking_number'] ?? '—') ?> — this order now shows as done to the client, but stays open internally for government export benefits (see Order Financials above).</p>
      <?php if ($canCloseOrders): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/mark-fully-settled" onsubmit="return confirm('Mark this order fully settled and close it? This is the final, locked close.');">
        <?= Csrf::field() ?>
        <?= $stepBadge('11e') ?><button type="submit" class="btn-sm btn-success">Mark Fully Settled</button>
      </form>
      <p class="muted small">Blocked while a government export benefit claim on this order is still awaiting receipt — record it as received first (Order Financials above).</p>
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
      <p class="muted">Close the order first (Step 11g).</p>
    <?php endif; ?>
  </details>

  <?php if ($canCloseOrders): ?>
  </div>

  <details class="step-card step-card-reference" id="compliance-checklist">
    <summary><h2>Compliance Checklist <small class="muted">(reference — ECGC Cover, Pre-Shipment Inspection, etc.)</small></h2></summary>
    <p class="muted">Pre-closure compliance tasks (ECGC Cover, Pre-Shipment Inspection, etc.) — visible here because you have the close_orders permission. A missing entry below means "Not Started". The task-name list itself is managed from <?= $canManageComplianceTaskTypes ? '<a href="/compliance-task-types">Compliance Task Types</a>' : 'Compliance Task Types' ?> (Admin-only).</p>
    <?php if (empty($complianceTasks)): ?>
    <p class="muted small">No compliance task types have been configured yet.</p>
    <?php else: ?>
    <table class="list">
      <tr><th>Task</th><th>Status &amp; Details</th></tr>
      <?php foreach ($complianceTasks as $ct): ?>
      <tr>
        <td><?= htmlspecialchars($ct['task_type_name']) ?></td>
        <td>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/compliance-tasks" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <?= Csrf::field() ?>
            <input type="hidden" name="task_type_id" value="<?= (int) $ct['task_type_id'] ?>">
            <select name="status">
              <?php foreach (\App\Repositories\OrderComplianceTaskRepository::STATUSES as $key => $label): ?>
              <option value="<?= htmlspecialchars($key) ?>" <?= $ct['status'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="skip_reason" value="<?= htmlspecialchars($ct['skip_reason'] ?? '') ?>" placeholder="Reason — required if Skipped" style="flex:1;min-width:180px;">
            <button type="submit" class="btn-sm">Save</button>
            <?php if ($ct['resolved_at']): ?>
            <span class="muted small"><?= htmlspecialchars($ct['resolved_at']) ?><?php if ($ct['resolved_by_name']): ?> by <?= htmlspecialchars($ct['resolved_by_name']) ?><?php endif; ?></span>
            <?php endif; ?>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </details>
  <?php endif; ?>

  <?php if ($canManageOrders): ?>
  <details class="step-card step-card-reference" id="amendments">
    <summary><h2>Amendments <small class="muted">(reference — mutually-agreed term changes)</small></h2></summary>
    <p class="muted">Mutually-agreed changes to this order's terms (payment terms, pricing, quantity, etc), each going through its own maker-checker approval — a different process from a buyer complaint or claim, which belongs under Disputes.</p>
    <p><a href="/orders/<?= (int) $order['id'] ?>/amendments">Payment Terms Amendments (<?= (int) $amendmentCount ?>)</a></p>
  </details>
  <?php endif; ?>

  <details class="step-card" id="step-additional-documents"<?= $stepCardOpen('12') ?>>
    <summary><?= $stepStatusIcon($stepStates['12']) ?><h2>Step 12 &middot; Additional Documents</h2></summary>
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
    <p class="muted small" style="margin-top:8px;"><a href="/orders/<?= (int) $order['id'] ?>/additional-documents/removed">Show removed</a></p>
  </details>

  <details class="step-card" id="order-updates"<?= $stepCardOpen('13') ?>>
    <summary><?= $stepStatusIcon($stepStates['13']) ?><h2>Step 13 &middot; Order Communication</h2></summary>
    <p class="muted">A running conversation with the client for this order. Anything you post here is emailed to the client immediately, with any attached images/videos (a large file becomes a secure download link instead of a raw attachment). Anything the client posts appears here too and notifies staff — nothing from either side is ever deleted.<?php if ($canGenerateDocuments): ?> Need something more formal — a payment reminder, a one-off notice? <a href="/orders/<?= (int) $order['id'] ?>/email/compose">Compose an email from a template</a>.<?php endif; ?></p>
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
    <form class="chat-post-form" method="post" action="/orders/<?= (int) $order['id'] ?>/comments#order-updates" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <textarea name="body" placeholder="Write an update for the client…"></textarea>
      <div class="chat-post-form-row">
        <input type="file" name="attachments[]" multiple accept="image/*,video/*,.pdf">
        <button type="submit" class="btn-sm" data-loading-text="Posting…">Post &amp; email client</button>
      </div>
    </form>
  </details>

  <details class="step-card" id="order-feedback"<?= $stepCardOpen('14') ?>>
    <summary><?= $stepStatusIcon($stepStates['14']) ?><h2>Step 14 &middot; Order Feedback</h2></summary>
    <?php if (empty($orderFeedback)): ?>
      <p class="muted">The client hasn't submitted feedback for this order yet.</p>
    <?php else: ?>
      <div class="kv-grid">
        <div><span class="k">Rating</span><span class="v"><?= str_repeat('★', (int) $orderFeedback['rating']) ?><?= str_repeat('☆', 5 - (int) $orderFeedback['rating']) ?> (<?= (int) $orderFeedback['rating'] ?>/5)</span></div>
        <div><span class="k">Submitted</span><span class="v"><?= htmlspecialchars(Dates::human($orderFeedback['submitted_at'])) ?></span></div>
        <div class="full"><span class="k">Comments</span><span class="v"><?= $orderFeedback['comments'] ? nl2br(htmlspecialchars($orderFeedback['comments'])) : '—' ?></span></div>
      </div>
    <?php endif; ?>
  </details>

  <details class="step-card" id="complaints"<?= $stepCardOpen('15') ?>>
    <summary><?= $stepStatusIcon($stepStates['15']) ?><h2>Step 15 &middot; Complaints</h2></summary>
    <p class="muted">A process issue or flaw the client flagged for us to fix next time — separate from Disputes below, and always available to the client regardless of the Dispute toggle.</p>
    <p><?= $canViewOrderComplaints ? '<a href="/orders/' . (int) $order['id'] . '/complaints">Complaints (' . (int) $openComplaintCount . ' open)</a>' : 'Complaints (' . (int) $openComplaintCount . ' open)' ?></p>
  </details>

  <details class="step-card" id="disputes"<?= $stepCardOpen('16') ?>>
    <summary><?= $stepStatusIcon($stepStates['16']) ?><h2>Step 16 &middot; Disputes</h2></summary>
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
       &nbsp;·&nbsp;
       <a href="/orders/<?= (int) $order['id'] ?>/dossier" class="js-slow-download" data-loading-text="Building ZIP…">Download Full Dossier (ZIP)</a>
    </p>
  </details>

  <details class="step-card" id="step-complete"<?= $stepCardOpen('17') ?>>
    <summary><?= $stepStatusIcon($stepStates['17']) ?><h2>Step 17 &middot; Order Complete</h2></summary>
    <?php if ($orderClosed): ?>
      <p><strong>&#10003; This order is fully settled and closed.</strong> Nothing further is needed.</p>
    <?php else: ?>
      <p class="muted">Not yet complete — finish Step 11 above (Mark Fully Settled) to close this order.</p>
    <?php endif; ?>
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
  </details>
  <?php endif; ?>

    </div>

    <aside class="order-detail-sidebar" id="order-detail-sidebar">
      <div class="sidebar-panel">
        <h3>At a Glance</h3>
        <div class="kv-grid">
          <div><span class="k">Current Stage</span><span class="v"><?= $currentStage ? 'Stage ' . (int) $currentStage['stage_number'] . ' — ' . htmlspecialchars($currentStage['stage_name']) : ($orderClosed ? 'Complete — all 9 stages resolved' : '—') ?></span></div>
          <div><span class="k">Products</span><span class="v"><?= count($products) ?> line<?= count($products) === 1 ? '' : 's' ?></span></div>
          <div><span class="k">Payment Legs</span><span class="v"><?= $paymentLegsResolved ?> of <?= $paymentLegsApplicable ?> cleared<?= $paymentLegsApplicable === 0 ? ' (nothing recorded yet)' : '' ?> — <a href="#payment-overview">see Payment Overview</a></span></div>
          <?php if ($canCloseOrders): ?>
          <div><span class="k">Compliance Checklist</span><span class="v"><?= $complianceSummary['total'] === 0 ? 'No task types configured' : $complianceSummary['approved'] . ' of ' . $complianceSummary['total'] . ' resolved' ?> — <a href="#compliance-checklist">see checklist</a></span></div>
          <?php endif; ?>
          <div><span class="k">Documents Generated</span><span class="v"><?= count($documents) ?></span></div>
          <div><span class="k">Client</span><span class="v"><?= htmlspecialchars($order['company_legal_name']) ?></span></div>
          <div><span class="k">Buyer Inquiry Ref</span><span class="v"><?= htmlspecialchars($order['buyer_inquiry_ref']) ?></span></div>
          <div><span class="k">Incoterm &amp; Port of Loading</span><span class="v"><?= htmlspecialchars($order['incoterm_code']) ?> <?= htmlspecialchars($order['port_of_loading_name'] ?? '—') ?></span></div>
          <div><span class="k">Quotation Date</span><span class="v"><?= htmlspecialchars($order['quotation_date'] ?? '—') ?></span></div>
          <div><span class="k">FOB Value</span><span class="v"><?= htmlspecialchars($order['currency_code']) ?> <?= number_format((float) $fobTotal, 2) ?></span></div>
          <div><span class="k">Port of Discharge</span><span class="v"><?= htmlspecialchars($order['port_of_discharge_name'] ?? $order['port_of_discharge_text'] ?? 'To Be Confirmed') ?></span></div>
          <div><span class="k">Container Type</span><span class="v"><?= htmlspecialchars($order['container_type'] ?? '—') ?></span></div>
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

      <div class="sidebar-panel">
        <h3>All Documents <small class="muted">(step 12)</small></h3>
        <?php if (empty($documents)): ?>
          <p class="muted small">No documents generated yet.</p>
        <?php else: ?>
          <ul class="sidebar-doc-list">
            <?php foreach ($documents as $d): ?>
              <li>
                <?php if ($d['pdf_file_id']): ?><a href="/documents/<?= (int) $d['id'] ?>/download?format=pdf"><?= htmlspecialchars($d['document_type_code']) ?> <?= htmlspecialchars($d['document_reference'] ?? '—') ?></a><?php else: ?><?= htmlspecialchars($d['document_type_code']) ?> <?= htmlspecialchars($d['document_reference'] ?? '—') ?><?php endif; ?>
                <span class="muted small">Rev.<?= (int) $d['revision_number'] ?> &middot; <?= htmlspecialchars(str_replace('_', ' ', $d['status'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <p class="muted small"><a href="#document-review-approval">Full list with review/approval status &rarr;</a></p>
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
  // Intake) or share one card with another (Stage 6/7 both feed into the
  // single Step 9 packing/freight pair).
  (function () {
    var panelsContainer = document.getElementById('order-panels');
    if (!panelsContainer) { return; }

    function openAndScrollTo(id) {
      var el = document.getElementById(id);
      if (!el) { return false; }
      var details = el.tagName === 'DETAILS' ? el : el.closest('details');
      if (details) { details.open = true; }
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

    // The top-of-page stage stepper dots jump to whichever card(s) cover
    // that DB stage — each stage now maps to several single-action
    // step-cards (nothing is grouped under one heading any more), so
    // every entry is a list; the stepper scrolls to the first (primary)
    // one but opens every card in the list, so none of that stage's
    // cards are left unexpectedly collapsed.
    var stageToCardId = {
      1: ['step-1-quotation-prepare', 'step-2-quotation-send', 'step-3-quotation-accept', 'document-review-approval'],
      2: ['step-4a-buyer-po-generate', 'step-4b-buyer-po-confirm', 'step-4c-buyer-po-attach'],
      3: ['step-5a-pi-form-link', 'step-5b-pi-intake-apply', 'step-5c-pi-generate', 'step-5d-payment-record', 'step-5e-payment-confirm', 'step-5-lc-bank-loan'],
      4: ['step-6a-oc-generate', 'step-6b-oc-acknowledge', 'step-6c-buyer-action-link'],
      5: ['step-7a-supplier-po-terms', 'step-7b-supplier-po-generate', 'step-7c-supplier-po-signed', 'step-7d-supplier-po-attach'],
      6: ['step-9a-freight-terms', 'step-9b-freight-debit-note', 'step-9c-freight-remittance', 'step-9d-freight-clear'],
      7: ['step-9e-packing', 'step-9f-packing-list', 'step-9g-shipping-details', 'step-9h-bl-instruction', 'step-9i-bl-issued'],
      8: ['step-ci-generate', 'step-ci-scanned-bl-sent', 'step-balance-record', 'step-balance-clear'],
      9: ['step-11a-coo-prep', 'step-11b-bl-originals-received', 'step-11c-bl-endorsed-mark', 'step-11d-bl-endorsement-save', 'step-11f-bl-endorsement-generate', 'step-11g-close-order', 'step-despatch'],
    };
    var stepperSteps = document.querySelectorAll('.process-stepper .process-step');
    function jumpToStage(stageNumber) {
      var ids = stageToCardId[String(stageNumber)] || [];
      ids.forEach(function (id, idx) {
        if (idx === 0) {
          openAndScrollTo(id);
          return;
        }
        var el = document.getElementById(id);
        var details = el && (el.tagName === 'DETAILS' ? el : el.closest('details'));
        if (details) { details.open = true; }
      });
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
    // order's real current step) so staff land on it without hunting.
    var initialId = (window.location.hash || '').replace('#', '');
    if (initialId && document.getElementById(initialId)) {
      openAndScrollTo(initialId);
    } else {
      var current = panelsContainer.querySelector('details[open]');
      if (current) { current.scrollIntoView({ block: 'start' }); }
    }
  })();
  </script>
</div>
