<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Company Settings</h1>
  <p class="muted">Every value here is what document templates actually read at generation time — nothing is hardcoded in a template. Fields marked <span class="badge badge-sensitive">sensitive</span> affect banking/RBI-facing documents; double-check before saving.</p>
  <p class="muted small">Spec Section 13 — every edit here is logged with who/when/field/old value/new value/reason, and cannot be undone through this UI; only a further edit can change a value back.</p>
  <p class="muted small">&#128274; <strong>Protected</strong> fields are locked against accidental edits — click <em>Unlock</em> on a row before you can change it. Protecting or unprotecting a field itself is a separate, peer-approved request: see <a href="/admin/field-protection">Field Protection</a>.</p>
  <p class="muted small"><strong>Super Admin only</strong> fields (the mail CC list) can only be changed by a Super Admin — everyone else sees them read-only, with no unlock option.</p>

  <form method="post" action="/settings/update" onsubmit="return confirmSettingsSave(this);">
    <?= Csrf::field() ?>
    <div class="settings-toolbar">
      <button type="button" class="btn-sm" onclick="toggleAllSettingsSections(true)">Expand all</button>
      <button type="button" class="btn-sm" onclick="toggleAllSettingsSections(false)">Collapse all</button>
    </div>
    <?php foreach ($grouped as $category => $rows): ?>
      <details class="collapsible-section settings-section" data-category="<?= htmlspecialchars($category) ?>" open>
      <summary><?= htmlspecialchars(ucfirst($category)) ?> <span class="muted small settings-section-count">(<?= count($rows) ?>)</span></summary>
      <fieldset>
        <?php foreach ($rows as $row): ?>
          <?php $lockedToSuperAdmin = $row['requires_super_admin'] && !$isSuperAdmin; ?>
          <label class="setting-row<?= $row['is_protected'] ? ' protected-row' : '' ?>">
            <span class="setting-label">
              <?php if ($row['is_protected']): ?><span class="lock-icon" title="Protected — unlock to edit">&#128274;</span><?php endif; ?>
              <?= htmlspecialchars($row['setting_key']) ?>
              <?php if ($row['setting_key'] === 'storage_base_path'): ?><span class="badge badge-superadmin" title="Editing this has no effect on the app — it always reads STORAGE_BASE_PATH from .env, not from this row">read-only</span><?php endif; ?>
              <?php if ($row['is_sensitive']): ?><span class="badge badge-sensitive">sensitive</span><?php endif; ?>
              <?php if ($row['is_protected']): ?><span class="badge badge-protected">protected</span><?php endif; ?>
              <?php if ($row['requires_super_admin']): ?><span class="badge badge-superadmin" title="Only a Super Admin can change this">Super Admin only</span><?php endif; ?>
              <?php if ($row['description']): ?><small class="muted"><?= htmlspecialchars($row['description']) ?></small><?php endif; ?>
            </span>
            <?php /* 90-item backlog #31: special-cased by setting_key rather than
               adding a generic value_type='select' — company_settings.value_type
               has no dropdown variant today and nothing else needs one yet, so a
               new generic field type would be speculative generalization for a
               fix that only ever applies to these 3 keys. */ ?>
            <?php if (in_array($row['setting_key'], ['rbi_purpose_code_advance', 'rbi_purpose_code_balance', 'rbi_purpose_code_freight'], true)): ?>
              <?php $currentValue = (string) ($row['setting_value'] ?? ''); $seenCurrent = false; ?>
              <input type="hidden" name="settings[<?= htmlspecialchars($row['setting_key']) ?>]" value="<?= htmlspecialchars($currentValue) ?>">
              <select
                data-key="<?= htmlspecialchars($row['setting_key']) ?>"
                data-initial-value="<?= htmlspecialchars($currentValue) ?>"
                data-sensitive="<?= $row['is_sensitive'] ? '1' : '0' ?>"
                data-protected="<?= $row['is_protected'] ? '1' : '0' ?>"
                <?= ($row['is_protected'] || $lockedToSuperAdmin) ? 'disabled' : '' ?>
                onchange="this.previousElementSibling.value = this.value;"
              >
                <?php foreach ($rbiPurposeCodes as $opt): ?>
                  <?php if ($opt['option_value'] === $currentValue) { $seenCurrent = true; } ?>
                  <option value="<?= htmlspecialchars($opt['option_value']) ?>" <?= $opt['option_value'] === $currentValue ? 'selected' : '' ?>><?= htmlspecialchars($opt['option_value']) ?></option>
                <?php endforeach; ?>
                <?php if (!$seenCurrent && $currentValue !== ''): ?>
                  <option value="<?= htmlspecialchars($currentValue) ?>" selected><?= htmlspecialchars($currentValue) ?> (not in admin list)</option>
                <?php endif; ?>
              </select>
            <?php elseif ($row['value_type'] === 'boolean'): ?>
              <input type="hidden" name="settings[<?= htmlspecialchars($row['setting_key']) ?>]" value="<?= $row['setting_value'] === '1' ? '1' : '0' ?>">
              <input
                type="checkbox"
                value="1"
                data-key="<?= htmlspecialchars($row['setting_key']) ?>"
                <?= $row['setting_value'] === '1' ? 'checked' : '' ?>
                data-sensitive="<?= $row['is_sensitive'] ? '1' : '0' ?>"
                data-protected="<?= $row['is_protected'] ? '1' : '0' ?>"
                <?= ($row['is_protected'] || $lockedToSuperAdmin) ? 'disabled' : '' ?>
                onchange="this.previousElementSibling.value = this.checked ? '1' : '0';"
              >
            <?php elseif (mb_strlen((string) ($row['setting_value'] ?? '')) > 100): ?>
              <textarea
                name="settings[<?= htmlspecialchars($row['setting_key']) ?>]"
                data-key="<?= htmlspecialchars($row['setting_key']) ?>"
                rows="3"
                data-sensitive="<?= $row['is_sensitive'] ? '1' : '0' ?>"
                data-protected="<?= $row['is_protected'] ? '1' : '0' ?>"
                <?= ($row['is_protected'] || $lockedToSuperAdmin) ? 'readonly' : '' ?>
              ><?= htmlspecialchars($row['setting_value'] ?? '') ?></textarea>
            <?php else: ?>
              <input
                type="text"
                name="settings[<?= htmlspecialchars($row['setting_key']) ?>]"
                data-key="<?= htmlspecialchars($row['setting_key']) ?>"
                value="<?= htmlspecialchars($row['setting_value'] ?? '') ?>"
                data-sensitive="<?= $row['is_sensitive'] ? '1' : '0' ?>"
                data-protected="<?= $row['is_protected'] ? '1' : '0' ?>"
                <?php /* 90-item backlog #33: storage_base_path is forced readonly here
                   regardless of is_protected/unlock — unlike a genuinely protected field,
                   there is no state in which saving a new value here would have any
                   effect (the app only ever reads STORAGE_BASE_PATH from .env), so
                   offering an Unlock button would be actively misleading. */ ?>
                <?= ($row['is_protected'] || $lockedToSuperAdmin || $row['setting_key'] === 'storage_base_path') ? 'readonly' : '' ?>
              >
            <?php endif; ?>
            <?php if ($row['is_protected']): ?>
              <input type="hidden" name="unlocked[<?= htmlspecialchars($row['setting_key']) ?>]" value="0" class="unlock-flag">
              <button type="button" class="btn-sm unlock-btn" onclick="unlockRow(this)">Unlock</button>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </fieldset>
      </details>
    <?php endforeach; ?>
    <label>Reason for this change * <small class="muted">(mandatory — recorded in the audit log against every field you change below)</small>
      <textarea name="reason" id="settings-reason" rows="2" required></textarea>
    </label>
    <button type="submit">Save changes</button>
  </form>

  <div class="section">
    <h2>Test SMTP Mail Connection</h2>
    <p class="muted">Sends a real email straight through the SMTP server configured in <code>.env</code> (SMTP_HOST etc. — not editable from this screen) so you can confirm those credentials actually work.</p>
    <form method="post" action="/settings/test-smtp-email" style="display:flex;gap:8px;align-items:center">
      <?= Csrf::field() ?>
      <input type="email" name="test_to" placeholder="you@example.com" required style="width:260px">
      <button type="submit" class="btn-sm">Send Test Email</button>
    </form>
  </div>

  <?php if (isset($grouped['zoho'])): ?>
  <div class="section">
    <h2>Test Zoho Mail Connection</h2>
    <p class="muted">Sends a real email straight through Zoho (bypassing the SMTP fallback) so you can confirm the credentials above actually work. Save your changes above first.</p>
    <form method="post" action="/settings/test-zoho-email" style="display:flex;gap:8px;align-items:center">
      <?= Csrf::field() ?>
      <input type="email" name="test_to" placeholder="you@example.com" required style="width:260px">
      <button type="submit" class="btn-sm">Send Test Email</button>
    </form>
  </div>
  <?php endif; ?>
</div>
<script>
/* 90-item backlog #27: ~20 settings categories as plain <details>/<summary>
   sections (no library) so each one is collapsible independently; open/closed
   state is remembered per category (and per browser) via localStorage so a
   staff member who collapses the sections they never touch doesn't have to
   redo that every time they open this page. Inputs inside a closed <details>
   still submit normally, so collapsing a section never drops its values.
   The actual open/closed persistence now lives in layout/base.php's own
   sitewide script (90-item backlog "sitewide collapsible sections beyond
   Settings") — every .collapsible-section on every page gets it, this
   page's sections included, since they carry that class too alongside
   their original .settings-section. Only this page's own "Expand/Collapse
   all" toolbar helper stays here, since no other page has that toolbar. */
function toggleAllSettingsSections(open) {
  document.querySelectorAll('.settings-section[data-category]').forEach(function (details) {
    details.open = open;
    try { localStorage.setItem('settings-section-open:' + details.dataset.category, open ? '1' : '0'); } catch (e) { /* ignore */ }
  });
}
function unlockRow(btn) {
  var label = btn.closest('.setting-row');
  var field = label.querySelector('input[type=text][data-key], input[type=checkbox][data-key], textarea[data-key], select[data-key]');
  var flag = label.querySelector('.unlock-flag');
  var reason = prompt('Why are you unlocking this protected field? (required)');
  if (!reason || !reason.trim()) { return; }
  if (field.type === 'checkbox' || field.tagName === 'SELECT') { field.disabled = false; } else { field.removeAttribute('readonly'); }
  field.focus();
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
  form.querySelectorAll('input[data-key], textarea[data-key], select[data-key]').forEach(function (el) {
    var isChanged = el.type === 'checkbox'
      ? (el.checked !== el.defaultChecked)
      : (el.tagName === 'SELECT' ? (el.value !== el.dataset.initialValue) : (el.value !== el.defaultValue));
    if (isChanged) {
      changedAny = true;
      if (el.dataset.sensitive === '1') changedSensitive = true;
      if (el.dataset.protected === '1') {
        changedProtected++;
        var label = el.closest('.setting-row');
        var flag = label ? label.querySelector('.unlock-flag') : null;
        if (!flag || flag.value !== '1') { blockedProtected.push(el.dataset.key); }
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
