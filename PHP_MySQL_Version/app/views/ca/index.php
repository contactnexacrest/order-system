<?php
// External QA report U-01: "CA: order pages" — the CA / Chartered
// Accountant role (ca_module_view, inr_actual_view only — "No
// order-management access" per its own seeded description) sees order
// references throughout this module; each one used to link straight to
// /orders/{id}, which requires manage_orders or view_orders (neither of
// which the CA role holds) and so 403'd. Computed inline here (rather
// than threaded through every CaController method) since it's the exact
// same self-contained pattern views/orders/show.php already uses for
// its own permission checks.
$__caViewOrdersUser = \App\Services\AuthService::currentUser();
$__caViewOrdersRoleId = $__caViewOrdersUser['role_id'] !== null ? (int) $__caViewOrdersUser['role_id'] : null;
$canViewOrders = \App\Services\PermissionService::can((int) $__caViewOrdersUser['id'], $__caViewOrdersRoleId, 'manage_orders')
    || \App\Services\PermissionService::can((int) $__caViewOrdersUser['id'], $__caViewOrdersRoleId, 'view_orders');
?>
<div class="card page-wide">
  <h1>CA / Accounting</h1>
  <p class="muted">The accounting side of the business — separate from order stages. Nothing here moves an order forward, and nothing in the order reports feeds this.</p>

  <?php // D-395 (20 Nov 2026, Option B): what needs doing first. Each box shows only to a person who may open the page it leads to. ?>
  <div class="ca-attention" data-ca-attention>
    <h2>Needs attention</h2>
    <div class="ca-attention-grid">
      <?php foreach ($attention as $box): ?>
      <div class="ca-att tone-<?= htmlspecialchars($box['tone']) ?>" data-ca-box="<?= htmlspecialchars($box['key']) ?>">
        <span class="ca-att-value"><?= htmlspecialchars($box['value']) ?></span>
        <span class="ca-att-label"><?= htmlspecialchars($box['label']) ?></span>
        <a class="btn-sm btn-secondary" href="<?= htmlspecialchars($box['href']) ?>"><?= htmlspecialchars($box['button']) ?> &rsaquo;</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="ca-cards">
    <div class="ca-card">
      <div class="ca-card-head"><span class="ca-ico" aria-hidden="true">&#8599;</span><h2>Revenue &amp; benefits</h2></div>
      <p class="muted small">Revenue by financial or calendar year, and the export-benefit claims (RoDTEP etc.) per order.</p>
      <div class="btn-row">
        <a class="btn btn-sm" href="/ca/reports">Revenue reports</a>
        <a class="btn-sm btn-secondary" href="/ca/export-benefits">Export benefits</a>
        <a class="btn-sm btn-secondary" href="/ca/gst-refunds">GST refunds (LUT)</a>
        <?php $__cu = \App\Services\AuthService::currentUser(); if ($__cu && \App\Services\PermissionService::can((int) $__cu['id'], $__cu['role_id'] !== null ? (int) $__cu['role_id'] : null, 'view_cafin')): ?>
          <a class="btn-sm btn-secondary" href="/ca/cafin-export">CAFIN export (CSV, all orders) <span class="badge badge-internal">management</span></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="ca-card">
      <div class="ca-card-head"><span class="ca-ico" aria-hidden="true">%</span><h2>Expenses &amp; TDS</h2></div>
      <p class="muted small">Every recorded expense (ECGC, inspection, CHA, transport …) with its TDS, and the TDS payable by month.</p>
      <div class="btn-row">
        <a class="btn btn-sm" href="/ca/expenses">Expenses</a>
        <a class="btn-sm btn-secondary" href="/ca/tds-summary">TDS payable summary</a>
      </div>
    </div>

    <div class="ca-card">
      <div class="ca-card-head"><span class="ca-ico" aria-hidden="true">&#8644;</span><h2>Bank &amp; reconciliation</h2></div>
      <p class="muted small">Upload a bank statement and match its lines to receipts and expenses.</p>
      <div class="btn-row">
        <a class="btn btn-sm" href="/ca/bank-statement">Bank statement</a>
        <a class="btn-sm btn-secondary" href="/ca/reconciliation">Reconciliation</a>
      </div>
    </div>

    <?php if ($canManageCa): ?>
    <div class="ca-card">
      <div class="ca-card-head"><span class="ca-ico" aria-hidden="true">&#10227;</span><h2>Zoho Books &amp; year lock</h2></div>
      <p class="muted small">Send settlements and expenses to Zoho Books; lock a financial year once it is reconciled. Needs the CA Manage permission.</p>
      <div class="btn-row">
        <a class="btn btn-sm" href="/ca/zoho-sync">Zoho Books sync</a>
        <a class="btn-sm btn-secondary" href="/ca/fy-locks">Financial year lock</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="section" id="register">
    <h2>INR Settlement Register</h2>
    <p class="muted small">Every advance/balance/freight leg that has been marked cleared in the order pipeline: the actual INR amount credited, the forex gain/(loss) against the order's assumed exchange rate, and its FIRC/eBRC realization proof. Recorded from the order's own Payment Status section by whoever holds the "Add/edit INR actual" permission.</p>
    <div class="ca-summary" data-ca-summary>
      <div><strong><?= (int) $summary['legs'] ?></strong>legs cleared</div>
      <div><strong>&#8377;<?= \App\Helpers\MoneyFormat::indian($summary['inr_credited'], 2) ?></strong>INR credited</div>
      <div><strong class="<?= $summary['forex_net'] < 0 ? 'loss' : 'gain' ?>"><?= $summary['forex_known'] ? '&#8377;' . \App\Helpers\MoneyFormat::indian(abs($summary['forex_net']), 2) . ($summary['forex_net'] < 0 ? ' loss' : ' gain') : '—' ?></strong>forex, net</div>
      <div><strong class="<?= $summary['firc_pending'] > 0 ? 'bad' : '' ?>"><?= (int) $summary['firc_pending'] ?></strong>FIRC / eBRC pending</div>
      <div><strong><?= (int) $summary['not_in_zoho'] ?></strong>not yet in Zoho</div>
    </div>
    <div class="btn-row ca-filters">
      <?php foreach (['all' => 'All', 'firc' => 'FIRC pending (' . $summary['firc_pending'] . ')', 'zoho' => 'Not in Zoho (' . $summary['not_in_zoho'] . ')', 'estimated' => 'Estimated only (' . $summary['estimated'] . ')'] as $__k => $__label): ?>
        <a class="btn-sm btn-secondary<?= $show === $__k ? ' is-active' : '' ?>" href="<?= $__k === 'all' ? '/ca' : '/ca?show=' . $__k ?>#register"<?= $show === $__k ? ' aria-current="true"' : '' ?>><?= htmlspecialchars($__label) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (empty($settlements)): ?>
      <p class="muted"><?= $show === 'all' ? 'No settlement legs have been cleared yet.' : 'No leg matches this filter.' ?></p>
    <?php else: ?>
      <table class="list">
        <tr><th>Order Ref</th><th>Client</th><th>Leg</th><th>Foreign Amount</th><th>Currency</th><th>Exchange Rate</th><th>Cleared On</th><th>INR Actual</th><th>Forex Gain/(Loss)</th><th>FIRC/eBRC</th><th>Zoho Books</th></tr>
        <?php foreach ($settlements as $s): ?>
        <tr>
          <td><?= \App\Services\OrderAccessService::currentUserMayOpenOrderId((int) $s['order_id']) ? '<a href="/orders/' . (int) $s['order_id'] . '">' . htmlspecialchars($s['buyer_inquiry_ref']) . '</a>' : htmlspecialchars($s['buyer_inquiry_ref']) ?></td>
          <td><?= htmlspecialchars($s['company_legal_name']) ?></td>
          <td><?= htmlspecialchars(\App\Repositories\CaRepository::legLabel($s['leg'])) ?></td>
          <td><?= $s['foreign_amount'] !== null ? number_format((float) $s['foreign_amount'], 2) : '—' ?></td>
          <td><?= htmlspecialchars($s['currency_code']) ?></td>
          <td><?= $s['exchange_rate'] !== null ? number_format((float) $s['exchange_rate'], 4) : '—' ?></td>
          <td><?= htmlspecialchars((string) $s['cleared_at']) ?></td>
          <td>
            <?php if ($s['inr_actual'] !== null): ?>
              &#8377;<?= \App\Helpers\MoneyFormat::indian((float) $s['inr_actual'], 2) ?>
              <div class="muted small">
                <?php if ($s['inr_actual_recorded_at'] !== null): ?>
                  <?= htmlspecialchars((string) $s['inr_actual_recorded_at']) ?><?php if ($s['inr_actual_recorded_by'] !== null): ?> by <?= htmlspecialchars($usersById[$s['inr_actual_recorded_by']] ?? ('User #' . $s['inr_actual_recorded_by'])) ?><?php endif; ?>
                <?php endif; ?>
              </div>
            <?php elseif (($s['inr_estimated'] ?? null) !== null): // D-244: INR not known yet — the amount at the recorded rate ?>
              &#8377;<?= \App\Helpers\MoneyFormat::indian((float) $s['inr_estimated'], 2) ?>
              <div class="muted small">estimated at recorded rate — INR credited not recorded yet</div>
            <?php else: ?>
              <span class="muted">Not yet recorded</span>
            <?php endif; ?>
          </td>
          <td>
            <?php $bankCharges = $s['bank_charges_inr'] ?? null; // D-244: rupees the bank kept, booked as Bank Charges ?>
            <?php if ($s['forex_gain_loss'] !== null && ($bankCharges === null || abs($s['forex_gain_loss']) >= 0.005)): ?>
              <span class="<?= $s['forex_gain_loss'] >= 0 ? '' : 'muted' ?>">&#8377;<?= \App\Helpers\MoneyFormat::indian(abs($s['forex_gain_loss']), 2) ?> <?= $s['forex_gain_loss'] >= 0 ? 'gain' : 'loss' ?></span>
            <?php endif; ?>
            <?php if ($bankCharges !== null): ?>
              <div>&#8377;<?= \App\Helpers\MoneyFormat::indian((float) $bankCharges, 2) ?> bank charges</div>
            <?php elseif ($s['forex_gain_loss'] === null): ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($s['firc_reference'] !== null): ?>
              <?= htmlspecialchars($s['firc_reference']) ?><div class="muted small"><?= htmlspecialchars((string) $s['firc_received_at']) ?></div>
            <?php elseif ($s['firc_pending']): ?>
              <span class="review-banner bad" style="display:inline-flex; padding:3px 8px;">Pending — flag for follow-up</span>
            <?php else: ?>
              <span class="muted">Not yet recorded</span>
            <?php endif; ?>
          </td>
          <td class="muted small">
            <?php if ($s['zoho_synced_at'] !== null): ?>
              Synced <?= htmlspecialchars((string) $s['zoho_synced_at']) ?>
            <?php elseif ($s['inr_actual'] !== null): ?>
              Pending
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
