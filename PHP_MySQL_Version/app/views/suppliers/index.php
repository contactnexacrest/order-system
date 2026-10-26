<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Suppliers</h1>
  <p class="muted">The directory of suppliers a Supplier PO is raised against. A supplier can hold more than one type (e.g. Quarry Owner and Factory Owner). Editing or deleting a supplier here never changes an order that has already saved its Supplier PO terms — the order keeps its own copy of the supplier's details.</p>

  <div class="section">
    <form method="get" action="/suppliers" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:8px;">
      <label style="display:inline-block;width:auto;">Type
        <select name="type" onchange="this.form.submit()">
          <option value="">All types</option>
          <?php foreach ($supplierTypes as $type): ?>
            <option value="<?= htmlspecialchars($type) ?>" <?= $filterType === $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="display:inline-block;width:auto;">Search
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Supplier name">
      </label>
      <button type="submit" class="btn-sm btn-secondary">Search</button>
      <?php if ($filterType !== '' || $search !== ''): ?><a href="/suppliers" class="btn-sm btn-secondary">Clear</a><?php endif; ?>
      <a href="/suppliers/create" class="btn-sm btn-accent" style="margin-left:auto;">Add Supplier</a>
    </form>

    <table class="list">
      <tr><th>Supplier</th><th>Type(s)</th><th>Location</th><th>Phone / WhatsApp</th><th>Contact Person</th><th>GSTIN</th><th>Status</th><th>Actions</th></tr>
      <?php if (empty($suppliers)): ?>
      <tr><td colspan="8" class="muted"><?= ($filterType !== '' || $search !== '') ? 'No suppliers match this filter.' : 'No suppliers yet — add the first one above.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($suppliers as $s): ?>
      <?php $used = isset($usedIds[(int) $s['id']]); ?>
      <tr>
        <td><?= htmlspecialchars($s['supplier_legal_name']) ?><?php if (!empty($s['email'])): ?><br><small class="muted"><?= htmlspecialchars($s['email']) ?></small><?php endif; ?></td>
        <td><?= $s['types'] ? htmlspecialchars(implode(', ', $s['types'])) : '—' ?></td>
        <td><?= htmlspecialchars(trim(($s['city'] ?? '') . (!empty($s['city']) && !empty($s['state']) ? ', ' : '') . ($s['state'] ?? ''))) ?: '—' ?></td>
        <td>
          <?= $s['phone'] ? htmlspecialchars($s['phone']) : '—' ?>
          <?php if (!empty($s['whatsapp_number'])): ?><br><small class="muted">WhatsApp: <?= htmlspecialchars($s['whatsapp_number']) ?></small><?php endif; ?>
        </td>
        <td>
          <?= $s['contact_person'] ? htmlspecialchars($s['contact_person']) : '—' ?>
          <?php if (!empty($s['contact_person_phone'])): ?><br><small class="muted"><?= htmlspecialchars($s['contact_person_phone']) ?></small><?php endif; ?>
        </td>
        <td><?= $s['gstin'] ? htmlspecialchars($s['gstin']) : '—' ?></td>
        <td><span class="badge <?= $s['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td>
          <a href="/suppliers/<?= (int) $s['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <form method="post" action="/suppliers/<?= (int) $s['id'] ?>/toggle" style="display:inline" onsubmit="return confirm('<?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars(addslashes($s['supplier_legal_name'])) ?>?<?= $s['is_active'] ? ' It will no longer appear in the Supplier PO supplier list.' : '' ?>');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm <?= $s['is_active'] ? 'btn-danger' : 'btn-success' ?>"><?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
          <?php if ($used): ?>
            <small class="muted" title="Used on an order — deactivate it instead.">Used on orders</small>
          <?php else: ?>
          <form method="post" action="/suppliers/<?= (int) $s['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($s['supplier_legal_name'])) ?> and its factory addresses from the directory? This cannot be undone.');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-danger">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
