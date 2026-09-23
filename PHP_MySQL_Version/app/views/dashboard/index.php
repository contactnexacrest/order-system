<div class="card">
  <h1>Dashboard</h1>
  <p>Signed in as <strong><?= htmlspecialchars($user['name']) ?></strong> (<?= htmlspecialchars($user['email']) ?>).</p>
</div>

<div class="card page-wide">
  <h2 style="margin-top:0;">Search — client or order reference</h2>
  <form method="get" action="/" class="search-box">
    <input type="text" name="q" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Order ref, client no., or company name…">
    <button type="submit" class="btn-sm">Search</button>
  </form>
  <?php if ($searchTerm !== ''): ?>
    <?php if (empty($searchResults)): ?>
      <p class="muted">No matches for "<?= htmlspecialchars($searchTerm) ?>".</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Order Ref</th><th>Client No.</th><th>Client</th><th>Stage</th><th>Status</th><th></th></tr>
        <?php foreach ($searchResults as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['order_reference']) ?></td>
          <td><?= htmlspecialchars($r['client_unique_number']) ?></td>
          <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
          <td><?= htmlspecialchars($r['current_stage_name'] ?? 'Quotation') ?></td>
          <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
          <td><a href="/orders/<?= (int) $r['order_id'] ?>">View</a></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card page-wide">
  <h2 style="margin-top:0;">My Approvals</h2>
  <div class="stat-grid">
    <div class="stat-tile <?= $myPendingReviewCount > 0 ? 'warn' : '' ?>">
      <div class="icon-chip" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg></div>
      <div class="num"><?= (int) $myPendingReviewCount ?></div>
      <div class="label">My pending reviews</div>
    </div>
    <?php if ($pendingEmailApprovalCount !== null): ?>
    <div class="stat-tile <?= $pendingEmailApprovalCount > 0 ? 'warn' : '' ?>">
      <div class="icon-chip" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"></rect><polyline points="3 7 12 13 21 7"></polyline></svg></div>
      <div class="num"><?= (int) $pendingEmailApprovalCount ?></div>
      <div class="label">Emails awaiting Level-2 approval</div>
    </div>
    <?php endif; ?>
    <?php if ($pendingAmendmentApprovalCount !== null): ?>
    <div class="stat-tile <?= $pendingAmendmentApprovalCount > 0 ? 'warn' : '' ?>">
      <div class="icon-chip" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5z"></path><path d="M2 17l10 5 10-5"></path><path d="M2 12l10 5 10-5"></path></svg></div>
      <div class="num"><?= (int) $pendingAmendmentApprovalCount ?></div>
      <div class="label">Amendments awaiting MD approval</div>
    </div>
    <?php endif; ?>
  </div>
  <div class="btn-row">
    <a class="btn-sm btn-secondary" href="/reviews">My Reviews</a>
    <?php if ($pendingEmailApprovalCount !== null): ?><a class="btn-sm btn-secondary" href="/email-approvals">Email Approvals</a><?php endif; ?>
  </div>
</div>

<?php if ($canManageOrders): ?>
<div class="card page-wide">
  <h2 style="margin-top:0;">Active Orders by Stage (<?= (int) $activeOrderCount ?> active)</h2>
  <div class="stat-grid">
    <?php foreach ($stageBreakdown as $s): ?>
    <div class="stat-tile">
      <div class="num"><?= (int) $s['order_count'] ?></div>
      <div class="label"><?= htmlspecialchars($s['stage_name']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="btn-row"><a class="btn-sm" href="/clients">Clients</a> <a class="btn-sm btn-secondary" href="/orders">All Orders</a></div>
</div>

<div class="card page-wide">
  <h2 style="margin-top:0;">Overdue Payments</h2>
  <?php if (empty($overdueBalance) && empty($overdueFreight)): ?>
    <p class="muted">Nothing overdue.</p>
  <?php else: ?>
    <?php if (!empty($overdueBalance)): ?>
      <h3 style="font-size:0.9rem;color:var(--danger);">Balance payment overdue</h3>
      <table class="list">
        <tr><th>Order Ref</th><th>Client</th><th>Due Date</th><th>Amount</th><th></th></tr>
        <?php foreach ($overdueBalance as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['order_reference']) ?></td>
          <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
          <td><?= htmlspecialchars($r['balance_due_date']) ?></td>
          <td><?= $r['balance_amount'] !== null ? number_format((float) $r['balance_amount'], 2) : '—' ?></td>
          <td><a href="/orders/<?= (int) $r['order_id'] ?>">View</a></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <?php if (!empty($overdueFreight)): ?>
      <h3 style="font-size:0.9rem;color:var(--danger);">Freight payment overdue</h3>
      <table class="list">
        <tr><th>Order Ref</th><th>Client</th><th>FDN Issued</th><th></th></tr>
        <?php foreach ($overdueFreight as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['order_reference']) ?></td>
          <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
          <td><?= htmlspecialchars($r['fdn_generated_at']) ?></td>
          <td><a href="/orders/<?= (int) $r['order_id'] ?>">View</a></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($complianceWarnings['lut'] || $complianceWarnings['rcmc']): ?>
<div class="card page-wide">
  <h2 style="margin-top:0;">Compliance Expiry Warnings</h2>
  <div class="stat-grid">
    <?php if ($complianceWarnings['lut']): $l = $complianceWarnings['lut']; ?>
    <div class="stat-tile <?= $l['days_left'] < 0 ? 'danger' : 'warn' ?>">
      <div class="num"><?= (int) $l['days_left'] ?>d</div>
      <div class="label">LUT expires <?= htmlspecialchars($l['expiry_date']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($complianceWarnings['rcmc']): $r = $complianceWarnings['rcmc']; ?>
    <div class="stat-tile <?= $r['days_left'] < 0 ? 'danger' : 'warn' ?>">
      <div class="num"><?= (int) $r['days_left'] ?>d</div>
      <div class="label">RCMC expires <?= htmlspecialchars($r['expiry_date']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <p class="muted">Update these in <a href="/settings">Company Settings</a>.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($recentActivity)): ?>
<div class="card page-wide">
  <h2 style="margin-top:0;">Recent Activity</h2>
  <table class="list">
    <tr><th>When</th><th>User</th><th>Action</th><th>Entity</th></tr>
    <?php foreach ($recentActivity as $a): ?>
    <tr>
      <td><?= htmlspecialchars($a['created_at']) ?></td>
      <td><?= htmlspecialchars($a['user_name'] ?? 'System') ?></td>
      <td><?= htmlspecialchars($a['action_type']) ?></td>
      <td><?= htmlspecialchars(trim(($a['entity_type'] ?? '') . ' ' . ($a['entity_id'] ?? ''))) ?: '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <div class="btn-row"><a class="btn-sm btn-secondary" href="/audit-log">Full Audit Log</a> <a class="btn-sm btn-secondary" href="/reports">Reports</a></div>
</div>
<?php endif; ?>
