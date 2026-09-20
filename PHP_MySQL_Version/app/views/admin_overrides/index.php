<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Admin Field Overrides</h1>
  <p class="muted">Spec Section 13 — every edit below requires a reason and a confirmation click, and is logged (who, when, field, old value, new value, reason). Nothing here can be undone through this screen — only a further edit changes a value back.</p>
  <p class="muted small">&#128274; <strong>Protected</strong> items are locked against accidental edits — click <em>Unlock</em> before changing them. Protecting or unprotecting an item is a separate, peer-approved request: see <a href="/admin/field-protection">Field Protection</a>.</p>

  <div class="section">
    <h2>Document Reference Formats</h2>
    <p class="muted small">Changing a format only affects references minted <em>after</em> the change — already-generated documents keep the reference they were given.</p>
    <form method="post" action="/admin/overrides/document-types" onsubmit="return confirmOverride(this, 'document reference formats');">
      <?= Csrf::field() ?>
      <table class="list">
        <tr><th>Code</th><th>Name</th><th>Reference Format</th><th>Min Reviewers</th></tr>
        <?php foreach ($documentTypes as $dt): ?>
        <tr>
          <td><?= htmlspecialchars($dt['code']) ?></td>
          <td><?= htmlspecialchars($dt['name']) ?></td>
          <td><input type="text" name="ref_format[<?= (int) $dt['id'] ?>]" value="<?= htmlspecialchars($dt['ref_format'] ?? '') ?>" placeholder="(none)"></td>
          <td><input type="text" name="min_reviewers[<?= (int) $dt['id'] ?>]" value="<?= (int) $dt['min_reviewers_default'] ?>" style="width:4rem"></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <label>Reason for this change * <textarea name="reason" class="override-reason" rows="2" required></textarea></label>
      <button type="submit" class="btn-sm">Save Document Type Changes</button>
    </form>
  </div>

  <div class="section">
    <h2>Terms &amp; Conditions Clauses</h2>
    <form method="post" action="/admin/overrides/tc-clauses" onsubmit="return confirmOverride(this, 'T&amp;C clause text');">
      <?= Csrf::field() ?>
      <?php foreach ($tcClauses as $c): ?>
        <fieldset class="<?= $c['is_protected'] ? 'protected-row' : '' ?>">
          <legend>
            <?php if ($c['is_protected']): ?><span class="lock-icon" title="Protected — unlock to edit">&#128274;</span><?php endif; ?>
            <?= htmlspecialchars($c['clause_number'] ?? '—') ?>
            <?= $c['is_locked'] ? '<span class="badge badge-sensitive">mandatory clause</span>' : '' ?>
            <?php if ($c['is_protected']): ?><span class="badge badge-protected">protected</span><?php endif; ?>
          </legend>
          <label>Title <input type="text" name="clause_title[<?= (int) $c['id'] ?>]" value="<?= htmlspecialchars($c['clause_title']) ?>" data-protected="<?= $c['is_protected'] ? '1' : '0' ?>" <?= $c['is_protected'] ? 'readonly' : '' ?>></label>
          <label>Text <textarea name="clause_text[<?= (int) $c['id'] ?>]" rows="3" data-protected="<?= $c['is_protected'] ? '1' : '0' ?>" <?= $c['is_protected'] ? 'readonly' : '' ?>><?= htmlspecialchars($c['clause_text']) ?></textarea></label>
          <?php if ($c['is_protected']): ?>
            <input type="hidden" name="unlocked_clause[<?= (int) $c['id'] ?>]" value="0" class="unlock-flag">
            <button type="button" class="btn-sm unlock-btn" onclick="unlockFieldset(this)">Unlock this clause</button>
          <?php endif; ?>
        </fieldset>
      <?php endforeach; ?>
      <label>Reason for this change * <textarea name="reason" class="override-reason" rows="2" required></textarea></label>
      <button type="submit" class="btn-sm">Save T&amp;C Clause Changes</button>
    </form>
  </div>

  <div class="section">
    <h2>Payment Presets</h2>
    <form method="post" action="/admin/overrides/payment-presets" onsubmit="return confirmOverride(this, 'payment preset values');">
      <?= Csrf::field() ?>
      <?php foreach ($paymentPresets as $p): ?>
        <fieldset class="<?= $p['is_protected'] ? 'protected-row' : '' ?>">
          <legend>
            <?php if ($p['is_protected']): ?><span class="lock-icon" title="Protected — unlock to edit">&#128274;</span><?php endif; ?>
            <?= htmlspecialchars($p['preset_name']) ?> (<?= htmlspecialchars($p['currency_code']) ?>)
            <?php if ($p['is_protected']): ?><span class="badge badge-protected">protected</span><?php endif; ?>
          </legend>
          <div class="kv-grid">
            <label>Advance % <input type="text" name="advance_pct[<?= (int) $p['id'] ?>]" value="<?= htmlspecialchars($p['advance_pct']) ?>" data-protected="<?= $p['is_protected'] ? '1' : '0' ?>" <?= $p['is_protected'] ? 'readonly' : '' ?>></label>
            <label>Advance Trigger Text <input type="text" name="advance_trigger_text[<?= (int) $p['id'] ?>]" value="<?= htmlspecialchars($p['advance_trigger_text']) ?>" data-protected="<?= $p['is_protected'] ? '1' : '0' ?>" <?= $p['is_protected'] ? 'readonly' : '' ?>></label>
            <label>Balance % <input type="text" name="balance_pct[<?= (int) $p['id'] ?>]" value="<?= htmlspecialchars($p['balance_pct']) ?>" data-protected="<?= $p['is_protected'] ? '1' : '0' ?>" <?= $p['is_protected'] ? 'readonly' : '' ?>></label>
            <label>Balance Trigger
              <select name="balance_trigger_option[<?= (int) $p['id'] ?>]" <?= $p['is_protected'] ? 'disabled' : '' ?>>
                <option value="A_BEFORE_SHIPMENT" <?= $p['balance_trigger_option'] === 'A_BEFORE_SHIPMENT' ? 'selected' : '' ?>>Before Shipment</option>
                <option value="B_AGAINST_BL" <?= $p['balance_trigger_option'] === 'B_AGAINST_BL' ? 'selected' : '' ?>>Against BL</option>
              </select>
            </label>
            <label>Balance Days <input type="text" name="balance_days[<?= (int) $p['id'] ?>]" value="<?= (int) $p['balance_days'] ?>" data-protected="<?= $p['is_protected'] ? '1' : '0' ?>" <?= $p['is_protected'] ? 'readonly' : '' ?>></label>
          </div>
          <?php if ($p['is_protected']): ?>
            <input type="hidden" name="unlocked_preset[<?= (int) $p['id'] ?>]" value="0" class="unlock-flag">
            <button type="button" class="btn-sm unlock-btn" onclick="unlockFieldset(this)">Unlock this preset</button>
          <?php endif; ?>
        </fieldset>
      <?php endforeach; ?>
      <p class="muted small">Warning: every order still pointing at this preset (i.e. with no activated amendment overriding it) reads these values live at document-generation time — editing a preset here changes terms for every one of those orders immediately, not just new ones. To change ONE order's terms without touching this shared preset, use that order's Payment Terms Amendment (SC/AMD) workflow instead.</p>
      <label>Reason for this change * <textarea name="reason" class="override-reason" rows="2" required></textarea></label>
      <button type="submit" class="btn-sm">Save Payment Preset Changes</button>
    </form>
  </div>
</div>
<script>
function unlockFieldset(btn) {
  var fieldset = btn.closest('fieldset');
  var reason = prompt('Why are you unlocking this protected item? (required)');
  if (!reason || !reason.trim()) { return; }
  fieldset.querySelectorAll('input[data-protected="1"], textarea[data-protected="1"]').forEach(function (el) {
    el.removeAttribute('readonly');
  });
  fieldset.querySelectorAll('select').forEach(function (el) { el.disabled = false; });
  var flag = fieldset.querySelector('.unlock-flag');
  if (flag) { flag.value = '1'; }
  var icon = fieldset.querySelector('.lock-icon');
  if (icon) { icon.textContent = '\u{1F513}'; icon.title = 'Unlocked for this edit'; }
  btn.textContent = 'Unlocked';
  btn.disabled = true;
}
function confirmOverride(form, label) {
  var reasonEl = form.querySelector('.override-reason');
  if (!reasonEl || reasonEl.value.trim() === '') {
    alert('A reason is required before saving.');
    return false;
  }
  var changedProtected = 0;
  var blocked = false;
  form.querySelectorAll('[data-protected="1"]').forEach(function (el) {
    if (el.value !== el.defaultValue) {
      var fieldset = el.closest('fieldset');
      var flag = fieldset ? fieldset.querySelector('.unlock-flag') : null;
      if (!flag || flag.value !== '1') { blocked = true; }
      else { changedProtected++; }
    }
  });
  if (blocked) {
    alert('Unlock every protected item you are changing before saving.');
    return false;
  }
  var msg = changedProtected > 0
    ? ('You are changing ' + changedProtected + ' PROTECTED item(s) that require special authorization. Continue?')
    : ('Save these changes to ' + label + '? This is logged and cannot be undone through this screen.');
  return confirm(msg);
}
</script>
