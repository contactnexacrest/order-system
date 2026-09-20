<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Company Settings</h1>
  <p class="muted">Every value here is what document templates actually read at generation time — nothing is hardcoded in a template. Fields marked <span class="badge badge-sensitive">sensitive</span> affect banking/RBI-facing documents; double-check before saving.</p>
  <p class="muted small">Spec Section 13 — every edit here is logged with who/when/field/old value/new value/reason, and cannot be undone through this UI; only a further edit can change a value back.</p>
  <p class="muted small">&#128274; <strong>Protected</strong> fields are locked against accidental edits — click <em>Unlock</em> on a row before you can change it. Protecting or unprotecting a field itself is a separate, peer-approved request: see <a href="/admin/field-protection">Field Protection</a>.</p>

  <form method="post" action="/settings/update" onsubmit="return confirmSettingsSave(this);">
    <?= Csrf::field() ?>
    <?php foreach ($grouped as $category => $rows): ?>
      <fieldset>
        <legend><?= htmlspecialchars(ucfirst($category)) ?></legend>
        <?php foreach ($rows as $row): ?>
          <label class="setting-row<?= $row['is_protected'] ? ' protected-row' : '' ?>">
            <span class="setting-label">
              <?php if ($row['is_protected']): ?><span class="lock-icon" title="Protected — unlock to edit">&#128274;</span><?php endif; ?>
              <?= htmlspecialchars($row['setting_key']) ?>
              <?php if ($row['is_sensitive']): ?><span class="badge badge-sensitive">sensitive</span><?php endif; ?>
              <?php if ($row['is_protected']): ?><span class="badge badge-protected">protected</span><?php endif; ?>
              <?php if ($row['description']): ?><small class="muted"><?= htmlspecialchars($row['description']) ?></small><?php endif; ?>
            </span>
            <input
              type="text"
              name="settings[<?= htmlspecialchars($row['setting_key']) ?>]"
              value="<?= htmlspecialchars($row['setting_value'] ?? '') ?>"
              data-sensitive="<?= $row['is_sensitive'] ? '1' : '0' ?>"
              data-protected="<?= $row['is_protected'] ? '1' : '0' ?>"
              <?= $row['is_protected'] ? 'readonly' : '' ?>
            >
            <?php if ($row['is_protected']): ?>
              <input type="hidden" name="unlocked[<?= htmlspecialchars($row['setting_key']) ?>]" value="0" class="unlock-flag">
              <button type="button" class="btn-sm unlock-btn" onclick="unlockRow(this)">Unlock</button>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>
    <label>Reason for this change * <small class="muted">(mandatory — recorded in the audit log against every field you change below)</small>
      <textarea name="reason" id="settings-reason" rows="2" required></textarea>
    </label>
    <button type="submit">Save changes</button>
  </form>
</div>
<script>
function unlockRow(btn) {
  var label = btn.closest('.setting-row');
  var input = label.querySelector('input[type=text]');
  var flag = label.querySelector('.unlock-flag');
  var reason = prompt('Why are you unlocking this protected field? (required)');
  if (!reason || !reason.trim()) { return; }
  input.removeAttribute('readonly');
  input.focus();
  flag.value = '1';
  var icon = label.querySelector('.lock-icon');
  if (icon) { icon.textContent = '\u{1F513}'; icon.title = 'Unlocked for this edit'; }
  btn.textContent = 'Unlocked';
  btn.disabled = true;
}
function confirmSettingsSave(form) {
  var changedAny = false;
  var changedSensitive = false;
  var changedProtected = 0;
  var blockedProtected = [];
  form.querySelectorAll('input[data-sensitive]').forEach(function (el) {
    if (el.value !== el.defaultValue) {
      changedAny = true;
      if (el.dataset.sensitive === '1') changedSensitive = true;
      if (el.dataset.protected === '1') {
        changedProtected++;
        var label = el.closest('.setting-row');
        var flag = label ? label.querySelector('.unlock-flag') : null;
        if (!flag || flag.value !== '1') { blockedProtected.push(el.name); }
      }
    }
  });
  if (!changedAny) {
    return true; // nothing changed — let the controller say so, no reason needed for a no-op submit
  }
  if (blockedProtected.length > 0) {
    alert('Unlock every protected field you are changing before saving.');
    return false;
  }
  var reason = document.getElementById('settings-reason').value.trim();
  if (reason === '') {
    alert('A reason is required before saving a settings change.');
    return false;
  }
  var msg = changedProtected > 0
    ? ('You are changing ' + changedProtected + ' PROTECTED field(s) that require special authorization. Continue?')
    : (changedSensitive
      ? 'You are changing a banking/RBI-sensitive field. Continue?'
      : 'Save this settings change? This is logged and cannot be undone through this screen.');
  return confirm(msg);
}
</script>
