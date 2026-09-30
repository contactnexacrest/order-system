<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Logistics Partners — CHA &amp; Transportation</h1>
  <p class="muted">The directory of Customs House Agent and transportation contacts staff use when placing or coordinating shipments. The same company sometimes provides both services — a partner is tagged CHA, Transportation, or both rather than entered twice.</p>

  <div class="section">
    <form method="get" action="/logistics-partners" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
      <label style="display:inline-block;width:auto;">Filter
        <select name="service_type" onchange="this.form.submit()">
          <option value="">All partners</option>
          <?php foreach ($serviceTypes as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>" <?= $filterServiceType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($filterServiceType !== ''): ?><a href="/logistics-partners" class="btn-sm btn-secondary">Clear filter</a><?php endif; ?>
      <a href="/logistics-partners/create" class="btn-sm btn-accent" style="margin-left:auto;">Add Partner</a>
    </form>

    <table class="list">
      <tr><th>Partner</th><th>Service</th><th>Location</th><th>Phone / WhatsApp</th><th>Contact Person</th><th>GSTIN</th><th>Status</th><th>Actions</th></tr>
      <?php if (empty($partners)): ?>
      <tr><td colspan="8" class="muted">No logistics partners yet — add the first one above.</td></tr>
      <?php endif; ?>
      <?php foreach ($partners as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['partner_name']) ?><?php if (!empty($p['email'])): ?><br><small class="muted"><?= htmlspecialchars($p['email']) ?></small><?php endif; ?></td>
        <td><?= htmlspecialchars($serviceTypes[$p['service_type']] ?? $p['service_type']) ?></td>
        <td><?= htmlspecialchars(trim(($p['city'] ?? '') . (!empty($p['city']) && !empty($p['state']) ? ', ' : '') . ($p['state'] ?? ''))) ?: '—' ?></td>
        <td>
          <?= $p['phone'] ? htmlspecialchars($p['phone']) : '—' ?>
          <?php if (!empty($p['whatsapp_number'])): ?><br><small class="muted">WhatsApp: <?= htmlspecialchars($p['whatsapp_number']) ?></small><?php endif; ?>
        </td>
        <td>
          <?= $p['contact_person_name'] ? htmlspecialchars($p['contact_person_name']) : '—' ?>
          <?php if (!empty($p['contact_person_phone'])): ?><br><small class="muted"><?= htmlspecialchars($p['contact_person_phone']) ?></small><?php endif; ?>
        </td>
        <td><?= $p['gstin'] ? htmlspecialchars($p['gstin']) : '—' ?></td>
        <td><span class="badge <?= $p['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td>
          <a href="/logistics-partners/<?= (int) $p['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <form method="post" action="/logistics-partners/<?= (int) $p['id'] ?>/toggle" style="display:inline" onsubmit="return confirm('<?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars(addslashes($p['partner_name'])) ?>?');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm <?= $p['is_active'] ? 'btn-danger' : 'btn-success' ?>"><?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
          <form method="post" action="/logistics-partners/<?= (int) $p['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($p['partner_name'])) ?> from the directory? This cannot be undone.');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-danger">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
