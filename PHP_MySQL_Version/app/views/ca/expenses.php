<?php
// External QA report U-01: "CA: order pages, Zoho sync" — see
// views/ca/index.php's own comment on $canViewOrders for the order-link
// half. The Zoho sync page itself needs ca_module_manage, a step up from
// the plain ca_module_view this page only needs — views/ca/index.php
// already hides its own Zoho Books card for a view-only CA user for the
// exact same reason; this page's own inline link never got the same
// treatment.
$__caExpensesUser = \App\Services\AuthService::currentUser();
$__caExpensesRoleId = $__caExpensesUser['role_id'] !== null ? (int) $__caExpensesUser['role_id'] : null;
$canViewOrders = \App\Services\PermissionService::can((int) $__caExpensesUser['id'], $__caExpensesRoleId, 'manage_orders')
    || \App\Services\PermissionService::can((int) $__caExpensesUser['id'], $__caExpensesRoleId, 'view_orders');
$canManageCa = \App\Services\PermissionService::can((int) $__caExpensesUser['id'], $__caExpensesRoleId, 'ca_module_manage');
?>
<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>Expenses</h1>
  <p class="muted">Imported one-way from Zoho Books — salary, supplier payments, travel, and anything else entered as an expense there. This is a read-only mirror for CA/reconciliation reporting; expenses are still entered in Zoho Books itself, never here.<?php if ($canManageCa): ?> <a href="/ca/zoho-sync">Zoho Books sync &rarr;</a><?php endif; ?></p>

  <div class="section">
    <h2>Imported Expenses</h2>
    <p class="muted small">The TDS column is a local-only annotation — setting it never changes anything in Zoho Books.</p>
    <p><a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/ca/expenses?format=csv">Export CSV</a></p>
    <?php if (empty($expenses)): ?>
      <p class="muted">No expenses imported yet — run a Zoho Books sync once it's configured.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Date</th><th>Category</th><th>Vendor</th><th>Description</th><th>Amount</th><th>Order</th><th>TDS</th></tr>
        <?php foreach ($expenses as $e): ?>
        <tr>
          <td><?= htmlspecialchars((string) $e['expense_date']) ?></td>
          <td><?= htmlspecialchars($e['category']) ?></td>
          <td><?= htmlspecialchars((string) ($e['vendor_name'] ?? '—')) ?></td>
          <td class="muted small"><?php if (trim((string) ($e['description'] ?? '')) === ''): ?><span class="badge badge-danger" title="Rule D-212: every expense says what it was for. Add the description in Zoho Books (Purchases → Expenses), then run the Zoho sync.">No description in Zoho</span><?php else: ?><?= htmlspecialchars((string) $e['description']) ?><?php endif; ?></td>
          <td><?= number_format((float) $e['amount'], 2) ?> <?= htmlspecialchars($e['currency_code']) ?></td>
          <td>
            <?php if ($e['order_id'] !== null): ?>
              <?= \App\Services\OrderAccessService::currentUserMayOpenOrderId((int) $e['order_id']) ? '<a href="/orders/' . (int) $e['order_id'] . '">' . htmlspecialchars($e['order_reference']) . '</a>' : htmlspecialchars($e['order_reference']) ?>
              <?php if ($canEditTds): ?>
                <form method="post" action="/ca/expenses/<?= (int) $e['id'] ?>/link-order" style="display:inline" onsubmit="return confirm('Unlink this expense from its order?');">
                  <?= \App\Helpers\Csrf::field() ?>
                  <button type="submit" class="btn-sm btn-danger" title="Unlink">&times;</button>
                </form>
              <?php endif; ?>
            <?php elseif ($canEditTds): ?>
              <form method="post" action="/ca/expenses/<?= (int) $e['id'] ?>/link-order" style="display:flex;gap:4px;">
                <?= \App\Helpers\Csrf::field() ?>
                <input type="text" name="order_reference" placeholder="Order ref" style="width:110px;">
                <button type="submit" class="btn-sm">Link</button>
              </form>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php $expenseLockMessage = \App\Repositories\CaFyLockRepository::lockMessageForDate($e['expense_date']); ?>
            <?php if ($canEditTds && ($expenseLockMessage === null || $canOverrideFyLock)): ?>
              <?php if ($expenseLockMessage !== null): ?>
                <span class="muted small" style="display:block;">&#9888; <?= htmlspecialchars($expenseLockMessage) ?> Saving will log an override.</span>
              <?php endif; ?>
              <form method="post" action="/ca/expenses/<?= (int) $e['id'] ?>/tds" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                <?= \App\Helpers\Csrf::field() ?>
                <label style="display:flex; gap:4px; align-items:center;">
                  <input type="checkbox" name="is_tds_applicable" value="1" <?= $e['is_tds_applicable'] ? 'checked' : '' ?>> TDS
                </label>
                <input type="text" name="tds_amount" placeholder="Amount" value="<?= htmlspecialchars((string) ($e['tds_amount'] ?? '')) ?>" style="width:90px;">
                <button type="submit" class="btn-sm"><?= $expenseLockMessage !== null ? 'Override &amp; Save' : 'Save' ?></button>
              </form>
            <?php else: ?>
              <?php if ($e['is_tds_applicable']): ?>
                Yes<?= $e['tds_amount'] !== null ? ' (' . number_format((float) $e['tds_amount'], 2) . ')' : '' ?>
              <?php else: ?>
                <span class="muted">No</span>
              <?php endif; ?>
              <?php if ($expenseLockMessage !== null): ?>
                <br><span class="muted small">&#128274; <?= htmlspecialchars($expenseLockMessage) ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
