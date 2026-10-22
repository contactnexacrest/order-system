<?php
use App\Config\Env;
use App\Helpers\Csrf;
use App\Helpers\Dates;
use App\Helpers\LeadTimeDisplay;
use App\Repositories\CaFyLockRepository;
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
?>
<div class="card page-wide">
  <p class="muted small"><a href="/orders">&larr; Back to Orders</a></p>
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
    // Point 2 (2026-10-01): a quick-glance horizontal line-with-dots
    // indicator of where this order stands in the 9-stage pipeline, above
    // the existing detailed stage-chip grid below — that grid still carries
    // the full name/status-badge detail per stage; this is purely the
    // at-a-glance "how far along are we" read, the thing a fresh glance at
    // the top of the page was missing. The connecting line segment left of
    // a step is colored via CSS purely off that step's own status class
    // (in_progress/gate_passed/skipped all mean "the process has reached
    // this far"), so no extra per-segment markup is needed.
  ?>
  <div class="process-stepper" role="list" aria-label="Order stage sequence">
    <?php foreach ($stages as $s): ?>
      <div class="process-step <?= htmlspecialchars($s['status']) ?>" role="button" tabindex="0" data-stage="<?= (int) $s['stage_number'] ?>" aria-label="Jump to Stage <?= (int) $s['stage_number'] ?> — <?= htmlspecialchars($s['stage_name']) ?>" <?= $s['status'] === 'in_progress' ? 'aria-current="step"' : '' ?>>
        <div class="process-step-dot"><?= (int) $s['stage_number'] ?></div>
        <div class="process-step-label"><?= htmlspecialchars($s['stage_name']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="stage-track">
    <?php foreach ($stages as $s): ?>
      <div class="stage-chip <?= htmlspecialchars($s['status']) ?>">
        <div class="stage-chip-num">Stage <?= (int) $s['stage_number'] ?></div>
        <div class="stage-chip-name"><?= htmlspecialchars($s['stage_name']) ?></div>
        <span class="stage-chip-status"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $s['status']))) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="order-layout" id="order-layout">
    <nav class="order-sidebar" id="order-sidebar" aria-label="Order sections"></nav>
    <div class="order-panels" id="order-panels">


  <div class="section">
    <h2>Order Details</h2>
    <p class="muted small">At a glance — everything else on this page in one place, so you don't have to scroll through every section just to see where this order stands.</p>
    <div class="kv-grid">
      <div><span class="k">Current Stage</span><span class="v"><?= $currentStage ? 'Stage ' . (int) $currentStage['stage_number'] . ' — ' . htmlspecialchars($currentStage['stage_name']) : ($orderClosed ? 'Complete — all 9 stages resolved' : '—') ?></span></div>
      <div><span class="k">Products</span><span class="v"><?= count($products) ?> line<?= count($products) === 1 ? '' : 's' ?></span></div>
      <div><span class="k">Payment Legs</span><span class="v"><?= $paymentLegsResolved ?> of <?= $paymentLegsApplicable ?> cleared<?= $paymentLegsApplicable === 0 ? ' (nothing recorded yet)' : '' ?> — <a href="#payment-snapshot">see Payment Snapshot</a></span></div>
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
          <span class="muted">Not yet recorded &mdash; <?= count($buyerPoDocuments) ?> file<?= count($buyerPoDocuments) === 1 ? '' : 's' ?> attached (see Stage 2 below)</span>
        <?php else: ?>
          NIL
        <?php endif; ?>
      </span></div>
      <div><span class="k">Special Requirements/Instructions</span><span class="v"><?= htmlspecialchars($order['special_requirements'] ?? '—') ?></span></div>
    </div>
  </div>

  <div class="section">
    <h2>Products</h2>
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
  </div>

  <div class="section" id="order-updates">
    <h2>Order Communication</h2>
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
  </div>

  <div class="section" id="documents-approvals" data-stage="1">
    <h2>Documents &amp; Approvals</h2>
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

    <h3 style="font-size:0.95em; margin-top:18px;">Additional Documents <a href="/orders/<?= (int) $order['id'] ?>/additional-documents/removed" class="muted small" style="font-weight:normal;">(show removed)</a></h3>
    <p class="muted small">Free-form extras that don't fit any fixed document type above — e.g. a buyer-supplied certificate template, a special packing instruction sheet.</p>
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

    <?php
      // Batch 3 #5 — differentiate 'not yet generated' vs 'already generated, this regenerates
      // it' document buttons visually, instead of every visible button looking identical.
      $__genTypes = array_column($documents, 'document_type_code');
      $__docBtnClass = static fn (string $code): string => 'btn-sm ' . (in_array($code, $__genTypes, true) ? 'btn-doc-regenerate' : 'btn-doc-new');
    ?>
    <div class="btn-row">
      <?php if ($stage1 && $stage1['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="QT">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('1a') ?><button type="submit" class="<?= $__docBtnClass('QT') ?>" data-loading-text="Generating…"><?= $hasQt ? 'Regenerate Quotation (new revision)' : 'Generate Quotation' ?></button>
        </form>
      <?php endif; ?>

      <?php
        // 90-item backlog #80: this panel's Generate buttons now run in
        // stage order (QT/1, BUYERPO/2, PI/3, OC/4, SUPPO/5, FDN/6, CI/8) to
        // match the $stepBadge sequence below each one — previously
        // BUYERPO rendered after PI/OC despite being Stage 2's own action.
      ?>
      <?php if ($stage2 && $stage2['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="BUYERPO">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('2a') ?><button type="submit" class="<?= $__docBtnClass('BUYERPO') ?>" data-loading-text="Generating…">Generate Buyer PO</button>
        </form>
      <?php endif; ?>

      <?php if ($stage3 && $stage3['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="PI">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('3a') ?><button type="submit" class="<?= $__docBtnClass('PI') ?>" data-loading-text="Generating…">Generate Proforma Invoice</button>
        </form>
      <?php endif; ?>

      <?php if ($stage4 && $stage4['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="OC">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('4a') ?><button type="submit" class="<?= $__docBtnClass('OC') ?>" data-loading-text="Generating…">Generate Order Confirmation</button>
        </form>
      <?php endif; ?>

      <?php if ($stage5 && $stage5['status'] !== 'locked' && $supplierPo): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="SUPPO">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('5b') ?><button type="submit" class="<?= $__docBtnClass('SUPPO') ?>" data-loading-text="Generating…">Generate Supplier PO</button>
        </form>
      <?php endif; ?>

      <?php if (!$isFob && $stage6 && $stage6['status'] !== 'locked' && $freight): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="FDN">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('6b') ?><button type="submit" class="<?= $__docBtnClass('FDN') ?>" data-loading-text="Generating…">Generate Debit Note</button>
        </form>
      <?php endif; ?>

      <?php if ($stage7 && $stage7['status'] !== 'locked' && $packing): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="PL">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('7e') ?><button type="submit" class="<?= $__docBtnClass('PL') ?>" data-loading-text="Generating…">Generate Packing List</button>
        </form>
      <?php endif; ?>

      <?php // 90-item backlog #38/#55: BL Instruction Sheet's trigger used to
             // live here, indistinguishable from every other generic
             // "Generate X" button, with no indication of where its data
             // comes from. Moved to its own explained section under Stage 7
             // — Packing & BL Instruction — right next to the Shipping/
             // Packing data it actually reads (see below). ?>

      <?php if ($stage8 && $stage8['status'] !== 'locked'): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="document_type" value="CI">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
          <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
          <?= $stepBadge('8b') ?><button type="submit" class="<?= $__docBtnClass('CI') ?>" data-loading-text="Generating…">Generate Commercial Invoice</button>
        </form>
      <?php endif; ?>

      <?php // 90-item backlog #38/#55: COO Prep Sheet's trigger moved for the
             // same reason as BL Instruction Sheet above — its own explained
             // section now lives under Stage 9, Document Despatch & Closure,
             // where staff actually prepare the Chamber of Commerce filing. ?>

      <?php if ($order['include_annexure_a']): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
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
  </div>

  <div class="section" data-stage="2">
    <h2>Stage 2 — Buyer PO</h2>
    <?php if ($hasBuyerPo): ?>
      <p>Buyer PO recorded: <strong><?= htmlspecialchars($order['buyers_po_ref']) ?></strong></p>
    <?php elseif ($stage2 && $stage2['status'] !== 'locked'): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-po">
        <?= Csrf::field() ?>
        <label>Buyer's PO / Reference Number *<input type="text" name="buyers_po_ref" required></label>
        <?= $stepBadge('2b') ?><button type="submit" class="btn-sm">Confirm Buyer PO Received</button>
      </form>
    <?php else: ?>
      <p class="muted">Generate the Quotation first to unlock this gate.</p>
    <?php endif; ?>

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
        <?= $stepBadge('2c') ?><button type="submit" class="btn-sm">Attach Buyer PO Copy</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="section" data-stage="3">
    <h2>PI-Stage Intake</h2>
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
      <?= $stepBadge('3b') ?><button type="submit" class="btn-sm"><?= $piIntake ? 'Regenerate PI Form Link' : 'Generate PI Form Link' ?></button>
    </form>
    <?php endif; ?>
  </div>

  <div class="section" id="payment-snapshot">
    <h2>Payment Snapshot</h2>
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

  <div class="section">
    <h2>Payment Ledger</h2>
    <p class="muted">A single, read-only history of every payment-related event recorded against this order — consolidating what's otherwise scattered across Payment Status and Client-Reported Payments. Nothing here can be edited; use the forms in Payment Status below to record new events.</p>
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
  </div>

  <div class="section">
    <h2>Payment Status</h2>
    <?php if ($payment): ?>
    <div class="kv-grid">
      <div><span class="k">Advance Amount</span><span class="v"><?php if (!$canViewPaymentAmounts): ?>Only shown to Accounts/Logistics/Export staff<?php else: ?><?= $payment['advance_amount'] !== null ? number_format((float) $payment['advance_amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?><?php endif; ?></span></div>
      <div><span class="k">Advance T/T Received</span><span class="v"><?= htmlspecialchars($payment['advance_remittance_received_at'] ?? '—') ?></span></div>
      <div><span class="k">Advance Cleared</span><span class="v"><?= htmlspecialchars($payment['advance_cleared_at'] ?? '—') ?></span></div>
      <div><span class="k">Balance Amount</span><span class="v"><?php if (!$canViewPaymentAmounts): ?>Only shown to Accounts/Logistics/Export staff<?php else: ?><?= $payment['balance_amount'] !== null ? number_format((float) $payment['balance_amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?><?php endif; ?></span></div>
      <div><span class="k">Balance Due</span><span class="v"><?= htmlspecialchars($payment['balance_due_date'] ?? 'event-triggered') ?></span></div>
    </div>
    <?php endif; ?>

    <?php if ($stage3 && $stage3['status'] !== 'locked' && !$advanceCleared): ?>
      <?php if (!$canManagePayments): ?>
        <p class="muted">Only Accounts can record or clear this payment.</p>
      <?php elseif (!$payment || $payment['advance_remittance_received_at'] === null): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/advance">
          <?= Csrf::field() ?>
          <label>Advance Amount Received * <small class="muted">(in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="advance_amount" required></label>
          <label>Received On<input type="date" name="advance_received_at" value="<?= date('Y-m-d') ?>"></label>
          <?= $stepBadge('3c') ?><button type="submit" class="btn-sm">Record Advance Remittance</button>
        </form>
      <?php else: ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/advance/clear" onsubmit="return confirm('Mark the advance payment cleared? This unlocks Stage 4 and auto-provisions the client portal login — confirm the funds have actually landed first.');">
          <?= Csrf::field() ?>
          <label>Cleared On<input type="date" name="advance_cleared_at" value="<?= date('Y-m-d') ?>"></label>
          <?= $stepBadge('3d') ?><button type="submit" class="btn-sm btn-success">Mark Advance Cleared (unlocks Stage 4)</button>
        </form>
      <?php endif; ?>
    <?php elseif (!$stage3 || $stage3['status'] === 'locked'): ?>
      <p class="muted">Record the Buyer PO first to unlock this gate.</p>
    <?php endif; ?>

    <?php $renderInrActualWidget('advance', 'Advance Payment'); ?>
    <?php $renderExchangeRateWidget('advance', 'Advance Payment'); ?>

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

  <div class="section" data-stage="4">
    <h2>Stage 4 — Order Confirmation: Buyer Acknowledgement</h2>
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
          <?= $stepBadge('4b') ?><button type="submit" class="btn-sm btn-success">Record Buyer's Email Acknowledgement (unlocks Stage 5)</button>
        </form>
      <?php elseif ($ocDocGenerated): ?>
        <div class="review-banner info">
          ℹ <span>Order Confirmation has been generated but hasn't gone out by email yet (still awaiting send/approval, or mail sending isn't configured). If the buyer has confirmed by phone, in person, or any channel other than the portal/email, record it below — Stage 5 unlocks immediately.</span>
        </div>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/oc-acknowledgment">
          <?= Csrf::field() ?>
          <label>Describe how the buyer confirmed (quote their reply, or note the call/meeting) *<textarea name="acknowledged_note" rows="3" required></textarea></label>
          <?= $stepBadge('4b') ?><button type="submit" class="btn-sm btn-success">Record Buyer's Acknowledgement (unlocks Stage 5)</button>
        </form>
      <?php else: ?>
        <p class="muted">Generate the Order Confirmation above and send it to the buyer — once sent, they can acknowledge it from their portal (or reply by email, which you can record here), and it auto-confirms after 48 hours with no response.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Clear the advance payment first to unlock this gate.</p>
    <?php endif; ?>

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
    <div class="section" style="margin-top:14px;">
      <h3 style="font-size:0.95em; margin-top:0;">Buyer Action Link (no portal login required)</h3>
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
        <?= $stepBadge('4c') ?><button type="submit" class="btn-sm"><?= $buyerActionLinkValid ? 'Regenerate Buyer Action Link' : 'Generate Buyer Action Link' ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="section" data-stage="5">
    <h2>Stage 5 — Supplier Purchase Order (Material Procurement)</h2>
    <?php if (!$stage5 || $stage5['status'] === 'locked'): ?>
      <?php if ($supplierPo): ?>
        <div class="kv-grid">
          <div><span class="k">Supplier</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_legal_name']) ?></span></div>
          <div><span class="k">Supplier PO Ref</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_po_reference']) ?></span></div>
          <div><span class="k">Material</span><span class="v"><?= htmlspecialchars($supplierPo['material_stone_type'] ?? '—') ?></span></div>
          <div><span class="k">Total Payable (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['total_payable_inr'] ?? '—') ?></span></div>
          <div><span class="k">Status</span><span class="v"><?= htmlspecialchars($supplierPo['status']) ?></span></div>
        </div>
      <?php endif; ?>
      <p class="muted">Confirm buyer acknowledgement first to unlock this gate<?= $supplierPo ? ' before you can edit or progress this Supplier PO' : '' ?>.</p>
    <?php else: ?>
      <?php if ($supplierPo): ?>
        <div class="kv-grid">
          <div><span class="k">Supplier</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_legal_name']) ?></span></div>
          <div><span class="k">Supplier PO Ref</span><span class="v"><?= htmlspecialchars($supplierPo['supplier_po_reference']) ?></span></div>
          <div><span class="k">Material</span><span class="v"><?= htmlspecialchars($supplierPo['material_stone_type'] ?? '—') ?></span></div>
          <div><span class="k">Total Payable (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['total_payable_inr'] ?? '—') ?></span></div>
          <div><span class="k">Status</span><span class="v"><?= htmlspecialchars($supplierPo['status']) ?></span></div>
        </div>
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
          <?= $stepBadge('5a') ?><button type="submit" class="btn-sm">Save Supplier PO Terms</button>
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

      <?php if ($supplierPo && $supplierPo['status'] !== 'signed' && $suppoDocGenerated): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/signed">
          <?= Csrf::field() ?>
          <?= $stepBadge('5c') ?><button type="submit" class="btn-sm btn-success">Confirm Supplier Signed &amp; Returned PO (unlocks Stage 6)</button>
        </form>
      <?php elseif ($supplierPo && $supplierPo['status'] !== 'signed'): ?>
        <p class="muted small">Generate the Supplier PO document above before confirming the supplier's signature — they can't have signed and returned a document that was never sent.</p>
      <?php elseif ($supplierPo): ?>
        <p>Supplier has signed and returned the PO.</p>
      <?php endif; ?>

      <?php if ($supplierPo && $canManageOrderFinancials): ?>
        <h3 style="font-size:0.95em; margin-top:14px;">Supplier Payments</h3>
        <div class="kv-grid">
          <div><span class="k">Advance (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['advance_amount_inr'] ?? '—') ?><?= !empty($supplierPo['advance_paid_at']) ? ' — paid ' . htmlspecialchars($supplierPo['advance_paid_at']) : '' ?></span></div>
          <div><span class="k">Balance (INR)</span><span class="v"><?= htmlspecialchars($supplierPo['balance_amount_inr'] ?? '—') ?><?= !empty($supplierPo['balance_paid_at']) ? ' — paid ' . htmlspecialchars($supplierPo['balance_paid_at']) : '' ?></span></div>
        </div>
        <?php if (empty($supplierPo['advance_paid_at'])): ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/advance-paid" style="display:inline-block; margin-right:8px;">
            <?= Csrf::field() ?>
            <label>Date<input type="date" name="paid_date" value="<?= date('Y-m-d') ?>"></label>
            <button type="submit" class="btn-sm">Mark Advance Paid</button>
          </form>
        <?php endif; ?>
        <?php if (empty($supplierPo['balance_paid_at'])): ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/supplier-po/balance-paid" style="display:inline-block;">
            <?= Csrf::field() ?>
            <label>Date<input type="date" name="paid_date" value="<?= date('Y-m-d') ?>"></label>
            <button type="submit" class="btn-sm">Mark Balance Paid</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($supplierPo): ?>
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
          <?= $stepBadge('5d') ?><button type="submit" class="btn-sm">Attach Supplier PO Acknowledgment</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Production &amp; Estimated Shipment</h2>
    <div class="kv-grid">
      <div><span class="k">Production Status</span><span class="v"><?= htmlspecialchars($order['production_status_text'] ?? 'Not yet commenced') ?></span></div>
      <div><span class="k">Est. Shipment</span><span class="v"><?= htmlspecialchars($order['est_shipment_date_text'] ?? 'To Be Confirmed') ?></span></div>
    </div>
    <p class="muted small">Every update here is logged<?php if ($canViewAuditLog): ?> — <a href="/orders/<?= (int) $order['id'] ?>/audit-log">view update history</a><?php endif; ?>.</p>
    <?php if ($stage4 && $stage4['status'] !== 'locked'): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/production-status">
        <?= Csrf::field() ?>
        <label>Production Status<input type="text" name="production_status_text" placeholder="e.g. Cutting and finishing in progress — 30% complete"></label>
        <label>Estimated Shipment<input type="text" name="est_shipment_date_text" placeholder="e.g. Week of 15 October 2026"></label>
        <button type="submit" class="btn-sm">Update</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="section" data-stage="6">
    <h2>Stage 6 — Freight Payment<?= $isFob ? ' (skipped — FOB)' : '' ?></h2>
    <?php if ($isFob): ?>
      <p class="muted">FOB order — buyer arranges and pays freight directly. This stage is auto-skipped once Stage 5 passes.</p>
      <?php if ($freightSkipped): ?><p>Confirmed skipped: <?= htmlspecialchars($stage6['skip_reason']) ?></p><?php endif; ?>
    <?php elseif (!$stage6 || $stage6['status'] === 'locked'): ?>
      <p class="muted">Confirm the Supplier PO is signed first to unlock this gate.</p>
    <?php else: ?>
      <?php if (!$freight): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/freight-terms">
          <?= Csrf::field() ?>
          <label>Confirmed Freight Rate<input type="text" name="confirmed_freight_rate"></label>
          <label>Insurance Amount (CIF only)<input type="text" name="insurance_amount"></label>
          <label>Freight Forwarder Name<input type="text" name="freight_forwarder_name"></label>
          <label>Freight Forwarder Contact<input type="text" name="freight_forwarder_contact"></label>
          <?= $stepBadge('6a') ?><button type="submit" class="btn-sm">Save Freight Terms</button>
        </form>
      <?php else: ?>
        <div class="kv-grid">
          <div><span class="k">Confirmed Rate</span><span class="v"><?= $freight['confirmed_freight_rate'] !== null ? htmlspecialchars($freight['confirmed_freight_rate']) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?></span></div>
          <div><span class="k">Insurance</span><span class="v"><?= $freight['insurance_amount'] !== null ? htmlspecialchars($freight['insurance_amount']) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : 'NIL' ?></span></div>
          <div><span class="k">Forwarder</span><span class="v"><?= htmlspecialchars($freight['freight_forwarder_name'] ?? '—') ?></span></div>
        </div>
        <?php if (!$freightCleared): ?>
          <?php if (!$payment || $payment['freight_remittance_received_at'] === null): ?>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/freight">
              <?= Csrf::field() ?>
              <label>Freight Amount Received * <small class="muted">(in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="freight_amount" required></label>
              <label>Received On<input type="date" name="freight_received_at" value="<?= date('Y-m-d') ?>"></label>
              <?= $stepBadge('6c') ?><button type="submit" class="btn-sm">Record Freight Remittance</button>
            </form>
          <?php else: ?>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/freight/clear" onsubmit="return confirm('Mark the freight payment cleared? This unlocks Stage 7 — confirm the funds have actually landed first.');">
              <?= Csrf::field() ?>
              <label>Cleared On<input type="date" name="freight_cleared_at" value="<?= date('Y-m-d') ?>"></label>
              <?= $stepBadge('6d') ?><button type="submit" class="btn-sm btn-success">Mark Freight Cleared (unlocks Stage 7)</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <p>Freight payment cleared.</p>
          <?php $renderInrActualWidget('freight', 'Freight Payment'); ?>
        <?php endif; ?>
        <?php $renderExchangeRateWidget('freight', 'Freight Payment'); ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="section" data-stage="7">
    <h2>Stage 7 — Packing &amp; BL Instruction</h2>
    <?php if (!$stage7 || $stage7['status'] === 'locked'): ?>
      <p class="muted"><?= $isFob ? 'Confirm the Supplier PO is signed first' : 'Clear the freight payment first' ?> to unlock this gate.</p>
    <?php else: ?>
      <h3>Packing</h3>
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
        <?= $stepBadge('7a') ?><button type="submit" class="btn-sm">Save Packing &amp; Crates</button>
      </form>

      <h3>Shipping / Vessel Details</h3>
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
        <?= $stepBadge('7b') ?><button type="submit" class="btn-sm">Save Shipping Details</button>
      </form>

      <?php if ($shipping): ?>
      <h3 style="font-size:0.95em; margin-top:14px;">BL Instruction Sheet (for your CHA / shipping line)</h3>
      <p class="muted small">Generates the shipping-booking worksheet your CHA or shipping line needs before confirming the BL booking — shipper, consignee/notify party, shipment and container details from above, cargo description from the order's products, and the mandatory BL-type instruction from Settings. Review the Shipping and Packing details above first; anything left blank there shows as "To Be Confirmed" on the sheet.</p>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="BLI">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('7c') ?><button type="submit" class="<?= $__docBtnClass('BLI') ?>" data-loading-text="Generating…">Generate BL Instruction Sheet</button>
      </form>
      <?php endif; ?>

      <?php if (!$blIssued): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-issued" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <label>BL Number *<input type="text" name="bl_number" required></label>
          <label>BL Date<input type="date" name="bl_date" value="<?= date('Y-m-d') ?>"></label>
          <label>Scanned BL Copy <small class="muted">(optional — can be attached later)</small><input type="file" name="bl_copy" accept=".pdf,.jpg,.jpeg,.png"></label>
          <?= $stepBadge('7d') ?><button type="submit" class="btn-sm btn-success">Confirm BL Issued (unlocks Stage 8)</button>
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
    <?php endif; ?>
  </div>

  <div class="section" data-stage="8">
    <h2>Stage 8 — Commercial Invoice &amp; Balance Payment</h2>
    <?php
      // Batch 3 #11 — the Commercial Invoice is conventionally dated to match the recorded BL date;
      // nothing enforces this, so flag it as an advisory (not a hard block — a same-day match is the
      // norm but not the only valid business reason to differ) right where staff generate the CI.
      $__ciBlDateMismatch = $shipping && !empty($shipping['bl_date']) && $shipping['bl_date'] !== date('Y-m-d');
    ?>
    <?php if (!$stage8 || $stage8['status'] === 'locked'): ?>
      <p class="muted">Confirm BL issuance first to unlock this gate.</p>
    <?php else: ?>
      <?php if ($__ciBlDateMismatch): ?>
        <p class="alert alert-warning">&#9888; Recorded BL date is <strong><?= htmlspecialchars($shipping['bl_date']) ?></strong>, not today. The Commercial Invoice is conventionally dated to match the BL — double-check before generating it if that's not intentional here.</p>
      <?php endif; ?>
      <?php if ($shipping && !$shipping['scanned_bl_sent_to_buyer_at']): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/scanned-bl-sent">
          <?= Csrf::field() ?>
          <?= $stepBadge('8a') ?><button type="submit" class="btn-sm">Mark Scanned BL Copy Sent to Buyer</button>
        </form>
      <?php elseif ($shipping): ?>
        <p class="muted small">Scanned BL copy sent to buyer.</p>
      <?php endif; ?>

      <?php if (!$balanceCleared): ?>
        <?php if (!$canManagePayments): ?>
          <p class="muted">Only Accounts can record or clear this payment.</p>
        <?php elseif ((!$payment || $payment['balance_remittance_received_at'] === null) && !$ciDocGenerated): ?>
          <p class="muted small">Generate the Commercial Invoice above before recording the balance remittance — the buyer can't be remitting against an invoice they were never sent.</p>
        <?php elseif (!$payment || $payment['balance_remittance_received_at'] === null): ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/balance">
            <?= Csrf::field() ?>
            <label>Balance Amount Received * <small class="muted">(in <?= htmlspecialchars($order['currency_code'] ?? '') ?>)</small><input type="text" name="balance_amount" required value="<?= htmlspecialchars((string) ($payment['balance_amount'] ?? '')) ?>"></label>
            <label>Received On<input type="date" name="balance_received_at" value="<?= date('Y-m-d') ?>"></label>
            <?= $stepBadge('8c') ?><button type="submit" class="btn-sm">Record Balance Remittance</button>
          </form>
        <?php else: ?>
          <form method="post" action="/orders/<?= (int) $order['id'] ?>/payment/balance/clear" onsubmit="return confirm('Mark the balance payment cleared? This unlocks Stage 9 (final closure) — confirm the funds have actually landed first, and that the BL hasn\'t been released early if this is a post-BL preset.');">
            <?= Csrf::field() ?>
            <label>Cleared On<input type="date" name="balance_cleared_at" value="<?= date('Y-m-d') ?>"></label>
            <?= $stepBadge('8d') ?><button type="submit" class="btn-sm btn-success">Mark Balance Cleared (unlocks Stage 9)</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p>Balance payment cleared.</p>
        <?php $renderInrActualWidget('balance', 'Balance Payment'); ?>
      <?php endif; ?>
      <?php $renderExchangeRateWidget('balance', 'Balance Payment'); ?>
    <?php endif; ?>
  </div>

  <?php if ($canManageOrderFinancials): ?>
  <div class="section" id="order-financials">
    <h2>Order Financials — Government Benefits, Costs &amp; Profitability</h2>
    <p class="muted small">Everything financial about this specific order, in one place — visible only to Super Admin and roles specifically granted the "Manage order financials" permission.</p>

    <h3 style="font-size:0.95em;">Government Export Benefits Claimed</h3>
    <?php $govtBenefitsApplicable = $order['status'] === 'complete' && !empty($payment['balance_remittance_received_at']); ?>
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
  </div>
  <?php endif; ?>

  <div class="section" data-stage="9">
    <h2>Stage 9 — Document Despatch &amp; Closure</h2>
    <?php if ($orderClosed): ?>
      <p><strong>&#10003; Order closed.</strong> Courier tracking: <?= htmlspecialchars($shipping['courier_tracking_number'] ?? '—') ?></p>
    <?php elseif (!$stage9 || $stage9['status'] === 'locked'): ?>
      <p class="muted">Clear the balance payment first to unlock this gate.</p>
    <?php else: ?>
      <?php
        // 90-item backlog #57 / Phase 6 Item 3: computed once here and
        // reused below to warn staff before they try to close — this is
        // now a real server-side hard gate too (see
        // DocumentGenerationService::closureBlockedBy()'s docblock and
        // OrderController::closeOrder()), not just a display-only hint.
        $__closeBlockedBy = DocumentGenerationService::closureBlockedBy((int) $order['id']);
      ?>
      <h3 style="font-size:0.95em; margin-top:0;">COO Prep Sheet (internal — for the Chamber of Commerce application)</h3>
      <p class="muted small">Generates an internal worksheet summarizing everything needed to apply for the Certificate of Origin with the Chamber of Commerce — shipper, consignee, shipment, and cargo details, the same source data as the BL Instruction Sheet. Never shown to the client.</p>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" style="display:inline; margin-bottom:14px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="COOPREP">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_pdf" value="1" checked disabled> PDF</label><input type="hidden" name="generate_pdf" value="1">
        <label class="checkbox-row" style="display:inline-block; margin:0 6pt 0 0; font-weight:normal;"><input type="checkbox" name="generate_docx" value="1"> DOCX</label>
        <?= $stepBadge('9a') ?><button type="submit" class="<?= $__docBtnClass('COOPREP') ?>" data-loading-text="Generating…">Generate COO Prep Sheet</button>
      </form>

      <?php if (!$canManageShipping): ?>
        <p class="muted">Only Logistics can record the original BLs.</p>
      <?php elseif (!$shipping || !$shipping['bl_originals_received_at']): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-originals-received">
          <?= Csrf::field() ?>
          <label>No. of Original BL Copies Received<input type="number" name="bl_originals_count" value="3"></label>
          <?= $stepBadge('9b') ?><button type="submit" class="btn-sm">Record Original BLs Received from CHA</button>
        </form>
      <?php else: ?>
        <p class="muted small">Original BLs received (<?= (int) $shipping['bl_originals_received_count'] ?>).</p>
      <?php endif; ?>

      <?php if ($canManageShipping && $shipping && $shipping['bl_originals_received_at'] && !$shipping['bl_endorsed_at']): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-endorsed">
          <?= Csrf::field() ?>
          <?= $stepBadge('9c') ?><button type="submit" class="btn-sm">Mark Original BLs Endorsed by NexaCrest</button>
        </form>
      <?php elseif ($shipping && $shipping['bl_endorsed_at']): ?>
        <p class="muted small">Original BLs endorsed.</p>
      <?php endif; ?>

      <?php if ($canManageShipping && $shipping && $shipping['bl_originals_received_at']): ?>
      <h3 style="font-size:0.95em; margin-top:14px;">BL Endorsement (print &amp; physically stamp all 3 originals)</h3>
      <p class="muted small">Save these details, then generate the printable endorsement — it states who to endorse the BL to ("Pay to the order of"), the BL/vessel/voyage, both ports, the latest PI/CI references, and the authorised signatory, exactly as it should be written or stamped on the reverse of each original BL.</p>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-endorsement">
        <?= Csrf::field() ?>
        <label>BL Number<input type="text" name="bl_number" value="<?= htmlspecialchars($blEndorsement['bl_number'] ?? $shipping['bl_number'] ?? '') ?>"></label>
        <label>Vessel / Voyage<input type="text" name="vessel_voyage" placeholder="e.g. MSC MAYA / 012W" value="<?= htmlspecialchars($blEndorsement['vessel_voyage'] ?? (trim(($shipping['vessel_name'] ?? '') . ' / ' . ($shipping['voyage_number'] ?? '')) !== '/' ? trim(($shipping['vessel_name'] ?? '') . ' / ' . ($shipping['voyage_number'] ?? '')) : '')) ?>"></label>
        <label>Port of Loading<input type="text" name="port_of_loading" value="<?= htmlspecialchars($blEndorsement['port_of_loading'] ?? $order['port_of_loading_name'] ?? 'Chennai, India') ?>"></label>
        <label>Port of Discharge<input type="text" name="port_of_discharge" value="<?= htmlspecialchars($blEndorsement['port_of_discharge'] ?? $order['port_of_discharge_name'] ?? $order['port_of_discharge_text'] ?? '') ?>"></label>
        <label>Date of Endorsement<input type="date" name="date_of_endorsement" value="<?= htmlspecialchars($blEndorsement['date_of_endorsement'] ?? date('Y-m-d')) ?>"></label>
        <?= $stepBadge('9d') ?><button type="submit" class="btn-sm">Save BL Endorsement Details</button>
      </form>
      <?php if ($blEndorsement): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/bl-endorsement/generate" style="margin-top:8px;">
        <?= Csrf::field() ?>
        <?= $stepBadge('9e') ?><button type="submit" class="btn-sm btn-success">Generate / Print BL Endorsement</button>
      </form>
      <?php endif; ?>
      <?php if ($blEndorsementDoc): ?>
        <p class="small" style="margin-top:6px;">
          Latest: <a href="/documents/<?= (int) $blEndorsementDoc['id'] ?>/download?format=pdf"><?= htmlspecialchars($blEndorsementDoc['document_reference']) ?></a>
          (generated <?= htmlspecialchars((string) $blEndorsementDoc['generated_at']) ?>)
        </p>
      <?php endif; ?>
      <?php endif; ?>

      <?php if ($canCloseOrders): ?>
      <?php if (!empty($__closeBlockedBy)): ?>
        <p class="notice notice-error">⚠ Before closing: generate and get approval for <?= htmlspecialchars(implode(' and ', $__closeBlockedBy)) ?> above. Close Order is blocked until then<?= $canEditLockedData ? ' — or override below with a reason.' : '.' ?></p>
        <?php if ($canEditLockedData): ?>
        <form method="post" action="/orders/<?= (int) $order['id'] ?>/close" onsubmit="return confirmFieldOverride(this, 'closing this order without both required documents approved');" style="border:1px solid #D9822B; padding:10px; border-radius:4px;">
          <?= Csrf::field() ?>
          <input type="hidden" name="override_gate" value="1">
          <label>Courier Tracking Number *<input type="text" name="courier_tracking_number" required></label>
          <label>Reason for this override * <small class="muted">(minimum 10 characters — recorded in the audit log)</small>
            <textarea class="override-reason" name="override_reason" rows="2" required minlength="10"></textarea>
          </label>
          <?= $stepBadge('9f') ?><button type="submit" class="btn-sm btn-danger">Override &amp; Close Order</button>
        </form>
        <?php endif; ?>
      <?php else: ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/close">
        <?= Csrf::field() ?>
        <label>Courier Tracking Number *<input type="text" name="courier_tracking_number" required></label>
        <?= $stepBadge('9f') ?><button type="submit" class="btn-sm btn-success">Close Order — Complete Document Set Couriered to Buyer</button>
      </form>
      <?php endif; ?>
      <?php else: ?>
      <p class="muted">Only Export/Logistics can close this order.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($canCloseOrders): ?>
  <div class="section" id="compliance-checklist">
    <h2>Compliance Checklist</h2>
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
  </div>
  <?php endif; ?>

  <?php if ($canManageOrders): ?>
  <div class="section" id="amendments">
    <h2>Amendments</h2>
    <p class="muted">Mutually-agreed changes to this order's terms (payment terms, pricing, quantity, etc), each going through its own maker-checker approval — a different process from a buyer complaint or claim, which belongs under Disputes.</p>
    <p><a href="/orders/<?= (int) $order['id'] ?>/amendments">Payment Terms Amendments (<?= (int) $amendmentCount ?>)</a></p>
  </div>
  <?php endif; ?>

  <div class="section" id="disputes">
    <h2>Disputes</h2>
    <p class="muted">A buyer complaint or claim raised against this order or shipment — separate from an Amendment, which is a planned, mutually-agreed change to order terms.</p>
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
  </div>

  <?php if ($canEditLockedData): ?>
  <div class="section">
    <h2>Admin Override</h2>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/override-status-lock" onsubmit="return confirmFieldOverride(this, 'this order\'s status/lock');">
      <?= Csrf::field() ?>
      <label>Status
        <select name="status">
          <option value="active" <?= $order['status'] === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="complete" <?= $order['status'] === 'complete' ? 'selected' : '' ?>>Complete</option>
          <option value="disputed" <?= $order['status'] === 'disputed' ? 'selected' : '' ?>>Disputed</option>
          <option value="lost" <?= $order['status'] === 'lost' ? 'selected' : '' ?>>Lost</option>
        </select>
      </label>
      <label><input type="checkbox" name="is_locked" value="1" style="display:inline-block;width:auto;" <?= $order['is_locked'] ? 'checked' : '' ?>> Locked (normally set automatically once status = complete)</label>
      <label>Reason for this change * <textarea class="override-reason" name="reason" rows="2" required></textarea></label>
      <button type="submit" class="btn-sm btn-danger">Override Status/Lock</button>
    </form>
  </div>
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
  <div class="section" style="border-color:#D9822B;">
    <h2>Force-Generate a Document (privileged override)</h2>
    <p class="muted small">Bypasses the normal stage sequence and, if this order is locked/complete, the lock itself. Use only to correct a genuine mistake — every use is logged with your reason against this order's audit trail.</p>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/documents/generate" onsubmit="return confirmFieldOverride(this, 'generating this document out of sequence');">
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
  </div>
  <?php endif; ?>

    </div>
  </div>
  <script>
  (function () {
    var layout = document.getElementById('order-layout');
    var panelsContainer = document.getElementById('order-panels');
    var sidebar = document.getElementById('order-sidebar');
    if (!layout || !panelsContainer || !sidebar) { return; }
    var panels = Array.prototype.filter.call(panelsContainer.children, function (el) {
      return el.classList.contains('section');
    });
    if (!panels.length) { return; }

    // Remembers the active panel per-order in sessionStorage so that a
    // plain form submit inside a panel (e.g. "Regenerate PI Form Link"),
    // which redirects back to this same page with no #hash, still lands
    // the user back on the panel they were just working in, instead of
    // silently resetting to the first one. A #hash in the URL (e.g. from
    // a direct link or the "Order Updates" comment form) always wins over
    // the remembered panel, so existing deep links keep working.
    var storageKey = 'orderActivePanel:<?= (int) $order['id'] ?>';

    function slugify(text) {
      var s = text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
      return s || 'section';
    }
    var usedIds = {};
    panels.forEach(function (panel, idx) {
      if (panel.id) {
        usedIds[panel.id] = true;
        return;
      }
      var heading = panel.querySelector('h2');
      var label = heading ? heading.textContent.trim() : ('Section ' + (idx + 1));
      var base = 'panel-' + slugify(label);
      var id = base, n = 2;
      while (usedIds[id]) { id = base + '-' + n; n++; }
      usedIds[id] = true;
      panel.id = id;
    });

    panels.forEach(function (panel) {
      var heading = panel.querySelector('h2');
      var label = heading ? heading.textContent.trim() : panel.id;
      var link = document.createElement('button');
      link.type = 'button';
      link.className = 'order-sidebar-link';
      link.textContent = label;
      link.dataset.target = panel.id;
      link.addEventListener('click', function () { activate(panel.id, true); });
      sidebar.appendChild(link);
    });

    function activate(id, updateHash) {
      panels.forEach(function (p) { p.classList.toggle('active-panel', p.id === id); });
      Array.prototype.forEach.call(sidebar.children, function (link) {
        link.classList.toggle('active', link.dataset.target === id);
      });
      if (updateHash && window.history && window.history.pushState) {
        window.history.pushState(null, '', '#' + id);
      }
      try { sessionStorage.setItem(storageKey, id); } catch (e) { /* private browsing etc — fine to skip */ }
    }

    // Order Details' "see Payment Snapshot" / "see checklist" links (and any
    // other in-page #anchor pointing at a panel) only ever changed the URL
    // hash — since every non-active panel is display:none, the browser had
    // nothing visible to scroll to, so the click appeared to do nothing.
    // Intercepting the click lets us activate the right panel first, then
    // scroll to it.
    panelsContainer.addEventListener('click', function (e) {
      var link = e.target.closest ? e.target.closest('a[href^="#"]') : null;
      if (!link) { return; }
      var targetId = link.getAttribute('href').slice(1);
      var targetPanel = null;
      panels.forEach(function (p) { if (p.id === targetId) { targetPanel = p; } });
      if (!targetPanel) { return; }
      e.preventDefault();
      activate(targetPanel.id, true);
      targetPanel.scrollIntoView({ block: 'start' });
    });

    // Batch 3 #14 — the at-a-glance stepper at the top of the page was
    // purely visual; clicking a step now jumps straight to whichever
    // panel covers that stage, exactly like the Order Details "see →"
    // links above intercept and activate(). Matched via each panel's own
    // data-stage attribute (set on the Documents & Approvals panel for
    // Stage 1/Stage 3's PI-Stage Intake panel and on each "Stage N — ..."
    // panel) rather than by guessing the auto-slugified id, since several
    // panels share one stage and the slug depends on the heading text.
    var stepperSteps = document.querySelectorAll('.process-stepper .process-step');
    function jumpToStage(stageNumber) {
      var targetPanel = null;
      panels.forEach(function (p) { if (p.dataset.stage === String(stageNumber)) { targetPanel = p; } });
      if (!targetPanel) { return; }
      activate(targetPanel.id, true);
      targetPanel.scrollIntoView({ block: 'start' });
    }
    Array.prototype.forEach.call(stepperSteps, function (step) {
      var stageNumber = step.dataset.stage;
      if (!stageNumber) { return; }
      step.addEventListener('click', function () { jumpToStage(stageNumber); });
      step.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); jumpToStage(stageNumber); }
      });
    });

    var initialId = (window.location.hash || '').replace('#', '');
    var initialPanel = null;
    panels.forEach(function (p) { if (p.id === initialId) { initialPanel = p; } });
    if (!initialPanel) {
      var rememberedId = null;
      try { rememberedId = sessionStorage.getItem(storageKey); } catch (e) { /* ignore */ }
      if (rememberedId) {
        panels.forEach(function (p) { if (p.id === rememberedId) { initialPanel = p; } });
      }
    }
    activate(initialPanel ? initialPanel.id : panels[0].id, false);

    layout.classList.add('js-enabled');
  })();
  </script>
</div>
