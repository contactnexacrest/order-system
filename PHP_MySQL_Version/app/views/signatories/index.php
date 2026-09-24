<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Signatories &amp; Designations</h1>
  <p class="muted">Who signs a document, in what capacity, and with which seal — resolved in this order every time a document is generated: a one-off choice made at generation time, then a per-document-type default set here, then the global default below. The company seal itself is a single shared asset managed on the <a href="/company-assets">Assets</a> screen and is unaffected by anything on this page.</p>

  <div class="section">
    <h2>Designations</h2>
    <table class="list">
      <tr><th>Title</th><th>Status</th><th>Action</th></tr>
      <?php foreach ($designations as $d): ?>
      <tr>
        <td>
          <?php if ((int) $d['protected_usage_count'] > 0): ?>
            <?= htmlspecialchars($d['title']) ?> <span class="badge badge-protected">In use by a protected founder</span>
          <?php else: ?>
            <form method="post" action="/signatories/designations/<?= (int) $d['id'] ?>/update" style="display:flex;gap:6px;align-items:center">
              <?= Csrf::field() ?>
              <input type="text" name="title" value="<?= htmlspecialchars($d['title']) ?>" required style="width:220px">
              <button type="submit" class="btn-sm">Rename</button>
            </form>
          <?php endif; ?>
        </td>
        <td><?= $d['is_active'] ? 'Active' : 'Inactive' ?></td>
        <td>
          <form method="post" action="/signatories/designations/<?= (int) $d['id'] ?>/toggle" style="display:inline">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm"><?= $d['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
          <?php if ((int) $d['usage_count'] === 0): ?>
            <form method="post" action="/signatories/designations/<?= (int) $d['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete the designation \'<?= htmlspecialchars(addslashes($d['title'])) ?>\'?');">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm btn-danger">Delete</button>
            </form>
          <?php else: ?>
            <span class="muted small">In use by <?= (int) $d['usage_count'] ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" action="/signatories/designations" style="margin-top:8px">
      <?= Csrf::field() ?>
      <input type="text" name="title" placeholder="New designation (e.g. Director)" required style="width:240px">
      <button type="submit" class="btn-sm">Add Designation</button>
    </form>
  </div>

  <div class="section">
    <h2>Global Default Signatory</h2>
    <p class="muted small">Used whenever a document type has no specific default configured below.</p>
    <form method="post" action="/signatories/global-default">
      <?= Csrf::field() ?>
      <select name="user_id">
        <?php foreach ($eligible as $e): ?>
          <option value="<?= (int) $e['id'] ?>" <?= (int) $globalDefaultUserId === (int) $e['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($e['name']) ?><?= $e['designation_title'] ? ' — ' . htmlspecialchars($e['designation_title']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-sm btn-success">Save</button>
    </form>
    <?php if (empty($eligible)): ?>
      <p class="muted small">No signatory-eligible users yet — mark someone eligible below first.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Per-Document-Type Default</h2>
    <table class="list">
      <tr><th>Document Type</th><th>Signatory</th><th>Seal Used</th><th>Change</th></tr>
      <?php foreach ($documentTypeSignatories as $row): ?>
      <tr>
        <td><?= htmlspecialchars($row['code']) ?> — <?= htmlspecialchars($row['name']) ?></td>
        <td><?= $row['signatory_name'] ? htmlspecialchars($row['signatory_name']) : '(global default)' ?></td>
        <td><?= $row['user_id'] ? ((int) $row['use_designation_seal'] ? 'Designation seal' : 'Company seal') : '—' ?></td>
        <td>
          <form method="post" action="/signatories/document-types/<?= (int) $row['document_type_id'] ?>" style="display:flex;gap:4px;align-items:center">
            <?= Csrf::field() ?>
            <select name="user_id" style="max-width:160px">
              <option value="">(global default)</option>
              <?php foreach ($eligible as $e): ?>
                <option value="<?= (int) $e['id'] ?>" <?= (int) ($row['user_id'] ?? 0) === (int) $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="muted small"><input type="checkbox" name="use_designation_seal" value="1" <?= (int) $row['use_designation_seal'] ? 'checked' : '' ?>> Designation seal</label>
            <button type="submit" class="btn-sm">Save</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="section">
    <h2>Signatory-Eligible Users</h2>
    <table class="list">
      <tr><th>Name</th><th>Designation &amp; Eligibility</th><th>Signature / Designation Seal</th></tr>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['name']) ?><?php if ((int) $u['is_protected_account'] === 1): ?> <span class="badge badge-protected">Protected founder</span><?php endif; ?><br><span class="muted small"><?= htmlspecialchars($u['email']) ?></span></td>
        <td>
          <?php if ((int) $u['is_protected_account'] === 1): ?>
            <div><strong><?= $u['designation_title'] ? htmlspecialchars($u['designation_title']) : '(no designation)' ?></strong></div>
            <span class="muted small">Protected — designation locked.</span>
          <?php else: ?>
          <form method="post" action="/signatories/users/<?= (int) $u['id'] ?>/eligibility" style="display:flex;gap:4px;align-items:center">
            <?= Csrf::field() ?>
            <select name="designation_id">
              <option value="">(none)</option>
              <?php foreach ($designations as $d): if (!$d['is_active'] && (int) $d['id'] !== (int) $u['designation_id']) continue; ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $u['designation_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['title']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="eligible" value="<?= $u['is_signatory_eligible'] ? '0' : '1' ?>">
            <button type="submit" class="btn-sm"><?= $u['is_signatory_eligible'] ? 'Revoke' : 'Grant' ?></button>
          </form>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($u['is_signatory_eligible']): ?>
            <?php foreach (($userAssets[$u['id']] ?? []) as $a): if (!$a['is_active']) continue; ?>
              <div class="muted small" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                <img src="/signatories/user-assets/<?= (int) $a['id'] ?>/preview" alt="" style="width:48px;height:48px;object-fit:contain;border:1px solid var(--line);border-radius:6px;background:#fff;flex:0 0 auto;">
                <span>
                  <?= $a['asset_kind'] === 'signature' ? 'Signature' : 'Designation seal' ?> for <strong><?= htmlspecialchars($u['name']) ?></strong>: <?= htmlspecialchars($a['label']) ?>
                  <?php if ($a['is_default_for_kind']): ?><strong>(default)</strong><?php endif; ?>
                  <form method="post" action="/signatories/user-assets/<?= (int) $a['id'] ?>/deactivate" style="display:inline" onsubmit="return confirm('Remove this <?= $a['asset_kind'] === 'signature' ? 'signature' : 'designation seal' ?>? Documents already generated with it keep their existing image; this only stops it being used going forward.');">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn-sm btn-danger">Remove</button>
                  </form>
                </span>
              </div>
            <?php endforeach; ?>
            <details style="margin-top:4px">
              <summary class="muted small">Upload new</summary>
              <form method="post" action="/signatories/users/<?= (int) $u['id'] ?>/upload" enctype="multipart/form-data" style="margin-top:4px">
                <?= Csrf::field() ?>
                <select name="asset_kind">
                  <option value="signature">Signature</option>
                  <option value="designation_seal">Designation Seal</option>
                </select>
                <input type="text" name="label" placeholder="Label (e.g. Default)" style="width:120px">
                <input type="file" name="file" accept=".png,.jpg,.jpeg,.webp,.svg" required>
                <button type="submit" class="btn-sm">Upload</button>
              </form>
            </details>
          <?php else: ?>
            <span class="muted small">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
