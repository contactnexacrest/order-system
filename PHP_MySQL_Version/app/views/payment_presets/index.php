<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Payment Presets</h1>
  <p class="muted">Each order carries a payment preset — its advance/balance split, when the balance falls due, and the exact wording printed on the Quotation, Proforma Invoice, and Buyer PO all come from here. A new preset's percentages and wording are picked up automatically by every document; no other code change is needed.</p>

  <div class="section">
    <p><a href="/payment-presets/create" class="btn-sm btn-accent">Add Preset</a></p>

    <table class="list">
      <tr><th>Preset</th><th>Advance / Balance</th><th>Balance Trigger</th><th>Days</th><th>Currency</th><th>In Use</th><th>Status</th><th>Actions</th></tr>
      <?php if (empty($presets)): ?>
      <tr><td colspan="8" class="muted">No payment presets yet — add the first one above.</td></tr>
      <?php endif; ?>
      <?php foreach ($presets as $p): ?>
      <tr>
        <td>
          <?= htmlspecialchars($p['preset_name']) ?>
          <?php if ((int) $p['is_default'] === 1): ?><span class="badge badge-active">default</span><?php endif; ?>
          <?php if ((int) $p['is_protected'] === 1): ?><span class="badge" title="Protected — unlock via Field Protection before editing">&#128274; protected</span><?php endif; ?>
        </td>
        <td><?= rtrim(rtrim(number_format((float) $p['advance_pct'], 2), '0'), '.') ?>% / <?= rtrim(rtrim(number_format((float) $p['balance_pct'], 2), '0'), '.') ?>%</td>
        <td><?= $p['balance_trigger_option'] === 'A_BEFORE_SHIPMENT' ? 'Before Shipment' : 'Against Scanned BL Copy' ?></td>
        <td><?= (int) $p['balance_days'] ?></td>
        <td><?= htmlspecialchars($p['currency_code']) ?></td>
        <td><?= \App\Repositories\PaymentPresetRepository::isInUse((int) $p['id']) ? 'Yes' : 'No' ?></td>
        <td><span class="badge <?= $p['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td>
          <a href="/payment-presets/<?= (int) $p['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <?php if ((int) $p['is_protected'] !== 1): ?>
          <form method="post" action="/payment-presets/<?= (int) $p['id'] ?>/toggle" style="display:inline" onsubmit="return confirm('<?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars(addslashes($p['preset_name'])) ?>?');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm <?= $p['is_active'] ? 'btn-danger' : 'btn-success' ?>"><?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <p class="muted small">Payment presets are never hard-deleted — orders reference them by ID, so even an inactive preset stays readable on every document already generated against it. The two seeded presets ship protected; unlock them via <a href="/admin/field-protection">Field Protection</a> before editing.</p>
  </div>
</div>
