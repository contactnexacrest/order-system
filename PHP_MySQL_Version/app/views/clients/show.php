<?php
use App\Helpers\Csrf;
use App\Helpers\Mask;
use App\Repositories\CompanySettingsRepository;
use App\Services\AuthService;
use App\Services\PermissionService;
$__u = AuthService::currentUser();
$canViewFullEmail = $__u && PermissionService::can((int) $__u['id'], $__u['role_id'] !== null ? (int) $__u['role_id'] : null, 'view_client_email_full');
$canEditLockedData = $__u && PermissionService::can((int) $__u['id'], $__u['role_id'] !== null ? (int) $__u['role_id'] : null, 'edit_locked_data');
// docs/schema.sql Section AV
$__canManageSettings = $__u && PermissionService::can((int) $__u['id'], $__u['role_id'] !== null ? (int) $__u['role_id'] : null, 'manage_company_settings');
$__canImpersonate = $__u && PermissionService::can((int) $__u['id'], $__u['role_id'] !== null ? (int) $__u['role_id'] : null, 'impersonate_client');
$__impersonationGloballyEnabled = CompanySettingsRepository::get('client_impersonation_enabled') === '1';
?>
<div class="card page-wide">
  <p class="muted small"><a href="/clients">&larr; Back to Clients</a></p>
  <h1><?= htmlspecialchars($client['company_legal_name']) ?></h1>
  <p class="muted">
    Buyer Inquiry Ref: <strong><?= htmlspecialchars($client['client_unique_number']) ?></strong>
    <?php if (PermissionService::can((int) $__u['id'], $__u['role_id'] !== null ? (int) $__u['role_id'] : null, 'view_reports')): ?>
      &nbsp;·&nbsp; <a href="/reports/client/<?= (int) $client['id'] ?>">Full Report</a>
    <?php endif; ?>
  </p>
  <?php if ((int) $client['is_active'] === 0): ?>
    <p class="muted small">Deactivated — hidden from the main Clients list. Nothing was deleted.</p>
  <?php endif; ?>
  <div class="btn-row">
    <a class="btn-sm btn-secondary" href="/clients/<?= (int) $client['id'] ?>/edit">Edit</a>
    <form method="post" action="/clients/<?= (int) $client['id'] ?>/toggle-active" style="display:inline" onsubmit="return confirm('<?= (int) $client['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars(addslashes($client['company_legal_name'])) ?>?<?= (int) $client['is_active'] === 1 ? ' It will no longer appear in the main Clients list, but nothing is deleted and it can be reactivated any time.' : '' ?>');">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-sm <?= (int) $client['is_active'] === 1 ? 'btn-danger' : 'btn-success' ?>"><?= (int) $client['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?></button>
    </form>
  </div>

  <div class="section">
    <h2>Buyer / Consignee Details</h2>
    <div class="kv-grid">
      <div><span class="k">Billing Address</span><span class="v"><?= nl2br(htmlspecialchars($client['billing_address'])) ?></span></div>
      <div><span class="k">Consignee</span><span class="v"><?= htmlspecialchars($client['consignee_name'] ?? 'SAME') ?></span></div>
      <div><span class="k">VAT / EORI</span><span class="v"><?= htmlspecialchars($client['vat_eori_tax_no'] ?? '—') ?></span></div>
      <div><span class="k">Country of Destination</span><span class="v"><?= htmlspecialchars($client['country_of_destination'] ?? '—') ?></span></div>
      <div><span class="k">Contact Person</span><span class="v"><?= htmlspecialchars($client['contact_person'] ?? '—') ?></span></div>
      <div><span class="k">Email</span><span class="v"><?= $canViewFullEmail ? htmlspecialchars($client['email'] ?? '—') : '<span class="masked-value">' . htmlspecialchars(Mask::email($client['email'] ?? null)) . '</span>' ?></span></div>
      <div><span class="k">Phone</span><span class="v"><?= $canViewFullEmail ? htmlspecialchars($client['phone'] ?? '—') : '<span class="masked-value">' . htmlspecialchars(Mask::phone($client['phone'] ?? null)) . '</span>' ?></span></div>
      <?php if (!$canViewFullEmail): ?><p class="muted small">Full contact details are masked — you don't have the "View full client email" permission.</p><?php endif; ?>
    </div>
  </div>

  <?php if (!empty($client['agreement_footer_text'])): ?>
  <div class="section">
    <h2>Agreement T&amp;C Footer</h2>
    <p class="muted small">Shown as an extra note on every document generated for this client, in addition to the standard terms. Edit it from the <a href="/clients/<?= (int) $client['id'] ?>/edit">Edit</a> page.</p>
    <div class="client-agreement-footer-preview"><?= nl2br(htmlspecialchars($client['agreement_footer_text'])) ?></div>
  </div>
  <?php endif; ?>

  <div class="section">
    <h2>Orders</h2>
    <div class="btn-row"><a class="btn btn-sm" href="/orders/create?client_id=<?= (int) $client['id'] ?>">+ New Order for this client</a></div>
    <?php if (empty($orders)): ?>
      <p class="muted">No orders yet.</p>
    <?php else: ?>
    <table class="list">
      <tr><th>Order Ref</th><th>Current Stage</th><th>Status</th><th></th></tr>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td><?= htmlspecialchars($o['order_reference']) ?></td>
        <td><?= htmlspecialchars($o['current_stage_name'] ?? 'Quotation') ?></td>
        <td><?= htmlspecialchars(ucfirst($o['status'])) ?></td>
        <td><a href="/orders/<?= (int) $o['id'] ?>">View</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($__canManageSettings || $__canImpersonate): ?>
  <div class="section">
    <h2>Client Portal Access</h2>
    <?php if ($__canManageSettings): ?>
      <form method="post" action="/clients/<?= (int) $client['id'] ?>/impersonation-allowed">
        <?= Csrf::field() ?>
        <label><input type="checkbox" name="allow_staff_impersonation" value="1" <?= !empty($client['allow_staff_impersonation']) ? 'checked' : '' ?> onchange="this.form.submit()"> Allow staff to log in as this client ("Log in as this client")</label>
      </form>
      <p class="muted small">
        Global switch is currently <strong><?= $__impersonationGloballyEnabled ? 'ON' : 'OFF' ?></strong> (<a href="/settings">Settings</a>).
        <?php if (!$__impersonationGloballyEnabled): ?>Turning this on alone won't allow impersonation until the global switch is also on.<?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if ($__canImpersonate): ?>
      <?php if ($__impersonationGloballyEnabled && !empty($client['allow_staff_impersonation']) && (int) $client['is_active'] === 1): ?>
        <form method="post" action="/clients/<?= (int) $client['id'] ?>/impersonate" onsubmit="return confirm('Log in as <?= htmlspecialchars(addslashes($client['company_legal_name'])) ?>? This opens their client-portal view in this session. This is logged.');">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm btn-secondary">Log in as this client</button>
        </form>
      <?php else: ?>
        <?php
          $__reasons = [];
          if (!$__impersonationGloballyEnabled) { $__reasons[] = 'the global switch is off'; }
          if (empty($client['allow_staff_impersonation'])) { $__reasons[] = 'it is not enabled for this client'; }
          if ((int) $client['is_active'] !== 1) { $__reasons[] = 'this client is deactivated'; }
        ?>
        <p class="muted small">"Log in as this client" isn't available right now — <?= htmlspecialchars(implode(', ', $__reasons)) ?>.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($canEditLockedData): ?>
  <div class="section">
    <h2>Admin Override</h2>
    <form method="post" action="/clients/<?= (int) $client['id'] ?>/override-unique-number" onsubmit="return confirmFieldOverride(this, 'the Buyer Inquiry Ref');">
      <?= Csrf::field() ?>
      <label>Buyer Inquiry Ref <input type="text" name="client_unique_number" value="<?= htmlspecialchars($client['client_unique_number']) ?>"></label>
      <label>Reason for this change * <textarea class="override-reason" name="reason" rows="2" required></textarea></label>
      <button type="submit" class="btn-sm btn-danger">Override Buyer Inquiry Ref</button>
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
</div>
