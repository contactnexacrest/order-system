<?php use App\Helpers\Csrf; use App\Services\DivisionService; use App\Controllers\DivisionController;
/* Owner decision D-257: one division — name, code and number format (read-only), modules, its own settings, pre-flight and Activate. */
$planned = $division['status'] !== 'active'; ?>
<div class="card page-wide">
  <p><a href="/admin/divisions">&larr; All divisions</a></p>
  <h1><?= htmlspecialchars((string) $division['name']) ?> <span class="badge <?= $planned ? 'badge-inactive' : 'badge-success' ?>"><?= $planned ? 'Planned' : 'Active' ?></span></h1>
  <div class="field-grid">
    <label>Name<input type="text" value="<?= htmlspecialchars((string) $division['name']) ?>" placeholder="e.g. Export" readonly></label>
    <label>Code<input type="text" value="<?= htmlspecialchars((string) $division['code']) ?>" placeholder="e.g. EXP" readonly></label>
    <label>Order number format<input type="text" value="<?= htmlspecialchars($orderRefFormat) ?>" placeholder="e.g. NC/SC/ORD/{YY}/{DDMM}{NNN}" readonly data-testid="division-number-format"></label>
  </div>
  <p class="muted"><?= (int) $counts['orders'] ?> orders · <?= (int) $counts['staff'] ?> staff · <?= (int) $counts['departments'] ?> departments · <?= (int) $counts['clients'] ?> clients.
    <?php if ((int) $division['id'] === DivisionService::EXPORT_ID): ?>Export keeps the company's number formats and counters exactly as they are.<?php else: ?>This division counts its own numbers, starting with its code, unless its own format below says otherwise.<?php endif; ?></p>

  <?php if ($division['code'] === 'DOM'): ?><p data-testid="division-gst-link"><a href="/admin/gst-rates">GST rates</a> — the GST rate per HSN for domestic sales (to be confirmed by the CA).</p><?php endif; ?>
  <div class="section">
    <h2>Modules</h2>
    <form method="post" action="/admin/divisions/<?= (int) $division['id'] ?>/modules">
      <?= Csrf::field() ?>
      <?php foreach (DivisionService::MODULES as $key => [$label]): ?>
        <label class="checkbox"><input type="checkbox" name="modules[]" value="<?= htmlspecialchars($key) ?>" <?= !empty($modules[$key]) ? 'checked' : '' ?>> <?= htmlspecialchars($label) ?></label>
      <?php endforeach; ?>
      <p><button type="submit" class="btn">Save modules</button></p>
    </form>
  </div>

  <div class="section">
    <h2>This division's own settings</h2>
    <p class="muted">A value here replaces the company value, only while someone works in this division. Leave a setting out to use the company value. Facts that depend on the CA or the bank (GST rates, e-invoice and e-way bill thresholds, merchanting limits) start from standard Indian rules and are <strong>to be confirmed by the CA / bank</strong>.</p>
    <?php if ($settings === []): ?>
      <p class="muted" data-testid="division-settings-empty">None — every company, pricing and incentive setting applies as it is.</p>
    <?php else: ?>
    <table class="list" data-testid="division-settings">
      <tr><th>Kind</th><th>Setting</th><th>Value</th><th></th></tr>
      <?php foreach ($settings as $s): ?>
      <tr>
        <td><?= htmlspecialchars(DivisionController::SETTING_SCOPES[$s['scope']] ?? $s['scope']) ?></td>
        <td><code><?= htmlspecialchars((string) $s['setting_key']) ?></code></td>
        <td><?= htmlspecialchars((string) $s['setting_value']) ?></td>
        <td><form method="post" action="/admin/divisions/<?= (int) $division['id'] ?>/settings/delete"><?= Csrf::field() ?><input type="hidden" name="scope" value="<?= htmlspecialchars((string) $s['scope']) ?>"><input type="hidden" name="setting_key" value="<?= htmlspecialchars((string) $s['setting_key']) ?>"><button type="submit" class="btn-link">Remove</button></form></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
    <form method="post" action="/admin/divisions/<?= (int) $division['id'] ?>/settings" class="field-grid">
      <?= Csrf::field() ?>
      <label>Kind *
        <select name="scope" required>
          <option value="">Choose…</option>
          <?php foreach (DivisionController::SETTING_SCOPES as $k => $label): ?><option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Setting name *<input type="text" name="setting_key" required maxlength="100" pattern="[A-Za-z][A-Za-z0-9_:.\-]{1,99}" placeholder="e.g. order_ref_format"></label>
      <label>Value *<input type="text" name="setting_value" required maxlength="2000" placeholder="e.g. NC/DOM/{YY}/{DDMM}{NNN}"></label>
      <p><button type="submit" class="btn">Save setting</button></p>
    </form>
  </div>

  <?php if ($planned && (int) $division['id'] !== DivisionService::EXPORT_ID): ?>
  <div class="section" data-testid="division-setup">
    <h2>Set it up</h2>
    <?php if ($starterDepartments !== []): ?>
    <form method="post" action="/admin/divisions/<?= (int) $division['id'] ?>/starter-departments"><?= Csrf::field() ?>
      <button type="submit" class="btn-secondary">Create its departments</button>
      <small class="muted"><?= htmlspecialchars(implode(', ', array_column($starterDepartments, 1))) ?> (only those not there yet).</small>
    </form>
    <?php endif; ?>
    <form method="post" action="/admin/divisions/<?= (int) $division['id'] ?>/staff" class="field-grid"><?= Csrf::field() ?>
      <label>Staff member who will work in this division *
        <select name="user_id" required>
          <option value="">Choose…</option>
          <?php foreach ($staffChoices as $u): ?><option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name'] . ' — ' . $u['email']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <p><button type="submit" class="btn-secondary">Move to <?= htmlspecialchars((string) $division['name']) ?></button></p>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($planned): ?>
  <div class="section" data-testid="division-preflight">
    <h2>Before activating</h2>
    <ul class="checklist">
      <?php foreach ($preflight as $item): ?>
        <li class="<?= $item['ok'] ? 'ok' : 'missing' ?>"><?= $item['ok'] ? '&#10003;' : '&#9744;' ?> <?= htmlspecialchars($item['label']) ?><?= $item['ok'] ? '' : ' — <small class="muted">' . htmlspecialchars($item['help']) . '</small>' ?></li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="/admin/divisions/<?= (int) $division['id'] ?>/activate">
      <?= Csrf::field() ?>
      <label class="checkbox"><input type="checkbox" name="confirm" value="1" required> I have checked the list above. Activating shows the division switcher, "All divisions", the report Division filter and the Division field to company-level people.</label>
      <p><button type="submit" class="btn">Activate <?= htmlspecialchars((string) $division['name']) ?></button></p>
    </form>
  </div>
  <?php elseif ((int) $division['id'] !== DivisionService::EXPORT_ID): ?>
  <div class="section">
    <form method="post" action="/admin/divisions/<?= (int) $division['id'] ?>/deactivate"><?= Csrf::field() ?><button type="submit" class="btn-secondary">Back to planned</button> <small class="muted">Only while it has no orders.</small></form>
  </div>
  <?php endif; ?>
</div>
