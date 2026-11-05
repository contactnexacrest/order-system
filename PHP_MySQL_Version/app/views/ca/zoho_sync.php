<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>Zoho Books Sync</h1>
  <p class="muted">Two independent, one-way directions: approved Commercial Invoices and Debit Notes are sent to Zoho Books as export invoices, settlement legs with a recorded INR actual are pushed as customer payments and applied to them (the advance is held until the Commercial Invoice is there), suppliers' and logistics partners' tax invoices, the payments made to them, the order expenses and the real paid order costs are sent as bills, vendor payments and expenses, the money paid under an LC as a customer payment, received export benefits as journals, and expenses recorded in Zoho Books are imported here (see <a href="/ca/expenses">Expenses</a>). Runs on demand here, or hourly via the scheduled job (see README's cron setup). If Zoho Books isn't configured, this is a routine no-op — nothing else in the app depends on it.</p>

  <div class="section">
    <h2>Status</h2>
    <div class="kv-grid">
      <div><span class="k">Zoho Books</span><span class="v"><?= $isEnabled ? '<span style="color:var(--success)">Enabled</span>' : '<span class="muted">Not configured</span>' ?></span></div>
      <div><span class="k">Go-live Date</span><span class="v"><?= $startDate !== null ? htmlspecialchars(date('d M Y', strtotime($startDate))) : '<span class="muted">Not set</span>' ?></span></div>
      <div><span class="k">Legs Pending Sync</span><span class="v"><?= (int) $pendingCount ?></span></div>
      <div><span class="k">Payments Held (not yet applied to an invoice)</span><span class="v"><?= (int) $heldCount ?></span></div>
      <div><span class="k">Bills, Vendor Payments, Order Expenses &amp; Costs Pending</span><span class="v"><?= (int) $purchasesPendingCount ?></span></div>
      <div><span class="k">Export Benefits Pending</span><span class="v"><?= (int) $benefitsPendingCount ?></span></div>
      <?php if (!empty($apiUsage)): // D-239: this app's share of the Zoho API calls ?>
      <div><span class="k">Zoho API calls (this app)</span><span class="v zoho-api-usage">Zoho calls today: <?= (int) $apiUsage['today'] ?> of <?= (int) $apiUsage['daily_limit'] ?> · this minute: <?= (int) $apiUsage['minute'] ?> of <?= (int) $apiUsage['minute_limit'] ?><?php if ($apiUsage['exhausted']): ?> <span class="badge badge-danger">Paused: daily allowance used — resumes tomorrow</span><?php endif; ?> <span class="muted small">(<?= htmlspecialchars($apiUsage['environment']) ?> organisation; limits in Settings → Zoho Books; other Zoho apps of the company share Zoho's own limit)</span></span></div>
      <?php endif; ?>
      <?php if (!empty($waiting['enabled'])): // D-233 ?>
      <div><span class="k">Waiting for Zoho (all records)</span><span class="v"><span class="badge <?= (int) $waiting['total'] === 0 ? 'badge-success' : 'badge-warning' ?> zoho-waiting" data-zoho-waiting="<?= (int) $waiting['total'] ?>">Waiting for Zoho: <?= (int) $waiting['total'] ?></span><?php if ((int) $waiting['total'] > 0): ?> <span class="muted small"><?= htmlspecialchars(\App\Services\ZohoPendingService::summary($waiting['by_type'])) ?> — <?= \App\Services\ZohoPendingService::pausedText() ?? 'sent at the next sync (hourly, or Sync Now)' ?>.</span><?php endif; ?></span></div>
      <?php endif; ?>
    </div>
    <p class="muted small">Only records dated on or after the go-live date go to Zoho Books; the CA books everything earlier as the opening position. Set it under Admin &rarr; Settings (<code>zoho_books_sync_start_date</code>). On the live server nothing is sent until it is set.</p>
    <?php if (!$isEnabled): ?>
      <p class="muted small">Fill in every <code>zoho_books_*</code> setting and set <code>zoho_books_enabled</code> to on, under Admin &rarr; Settings, to start syncing.</p>
    <?php endif; ?>
    <form method="post" action="/ca/zoho-sync/run" style="margin-top:10px;">
      <?= \App\Helpers\Csrf::field() ?>
      <button type="submit" class="btn-sm">Sync Now</button>
    </form>
    <?php $__cOn = \App\Services\ZohoMasterDataSync::clientsOn(); $__pOn = \App\Services\ZohoMasterDataSync::productsOn(); ?>
    <p class="muted small" style="margin-top:10px;">Clients to Zoho: <strong><?= $__cOn ? 'on' : 'off' ?></strong> &middot; Products to Zoho: <strong><?= $__pOn ? 'on' : 'off' ?></strong> (Admin &rarr; Settings &rarr; Zoho Books: <code>zoho_sync_clients</code>, <code>zoho_sync_products</code>). One way, app &rarr; Zoho: new or changed clients and products go at the next sync; anything no longer active is marked inactive in Zoho, never deleted. A client goes once its first order has set its currency; products go with rate 0 &mdash; costs are never sent.</p>
    <?php if ($__cOn || $__pOn): ?>
    <form method="post" action="/ca/zoho-sync/repush-master-data" onsubmit="return confirm('Send every client and product to Zoho Books again at the next sync? Edits made directly in Zoho are overwritten with the app\'s values.');">
      <?= \App\Helpers\Csrf::field() ?>
      <button type="submit" class="btn-sm btn-secondary">Re-push all clients and products</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($apiUsage)): // D-239: the last 7 days of this app's Zoho API calls ?>
    <h3 style="margin-top:14px;">Zoho API calls — last 7 days (this app)</h3>
    <p class="muted small">Every call counts — reads, the login token and sends. The scheduled sync waits when this minute's limit is reached; Sync now and the order buttons stop and say so. When the day's allowance is used, syncing pauses until midnight (India time). Zoho's own limit is shared with the company's other Zoho apps, so keep the allowance below it.</p>
    <table class="list zoho-api-history">
      <thead><tr><th>Day</th><th>Calls</th><th>Allowance</th></tr></thead>
      <tbody>
      <?php if ($apiUsage['history'] === []): ?>
        <tr><td colspan="3" class="muted">No Zoho calls in the last 7 days.</td></tr>
      <?php else: foreach ($apiUsage['history'] as $__d): ?>
        <tr><td><?= htmlspecialchars($__d['day']) ?></td><td><?= (int) $__d['calls'] ?></td><td><?= (int) $apiUsage['daily_limit'] ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Sync Log</h2>
    <p class="muted small">Independent of the main system audit log — every sync attempt (manual or scheduled), success or failure, is one row here.</p>
    <?php if (empty($log)): ?>
      <p class="muted">No sync attempts yet.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>When</th><th>Trigger</th><th>Leg</th><th>Status</th><th>Zoho Reference</th><th>Message</th></tr>
        <?php foreach ($log as $row): ?>
        <tr>
          <td><?= htmlspecialchars((string) $row['created_at']) ?></td>
          <td><?= htmlspecialchars($row['triggered_by']) ?><?= $row['triggered_by_name'] ? ' (' . htmlspecialchars($row['triggered_by_name']) . ')' : '' ?></td>
          <?php // Only rows about an order link to it; an imported expense (entity ca_expenses) is an expense, not an order. ?>
          <td><?php if (!$row['entity_id']): ?><span class="muted">—</span><?php elseif ($row['entity_type'] === 'ca_expenses'): ?><a href="/ca/expenses">Expense #<?= (int) $row['entity_id'] ?></a><?php elseif ($row['entity_type'] === 'import_shipment'): ?><a href="/import/<?= (int) $row['entity_id'] ?>">Import #<?= (int) $row['entity_id'] ?></a><?php elseif ($row['entity_type'] === 'merchant_deal'): ?><a href="/merchant/<?= (int) $row['entity_id'] ?>">Deal #<?= (int) $row['entity_id'] ?></a><?php else: ?><?= (\App\Services\OrderAccessService::currentUserMayOpenOrderId((int) $row['entity_id']) ? '<a href="/orders/' . (int) $row['entity_id'] . '">Order #' . (int) $row['entity_id'] . '</a>' : 'Order #' . (int) $row['entity_id']) . ($row['leg'] ? ' — ' . htmlspecialchars(\App\Services\ZohoInvoiceData::KIND_LABELS[$row['leg']] ?? ucfirst($row['leg'])) : '') ?><?php endif; ?></td>
          <td>
            <?php if ($row['status'] === 'success'): ?>
              <span style="color:var(--success)">Success</span>
            <?php elseif ($row['status'] === 'error'): ?>
              <span style="color:var(--danger)">Error</span>
            <?php else: ?>
              <span class="muted">Skipped</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars((string) ($row['zoho_reference'] ?? '—')) ?></td>
          <td class="muted small"><?= htmlspecialchars((string) ($row['message'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
