<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Suppliers</h1>
  <p class="muted">Every supplier with the products they offer and their quoted price. The directory of suppliers a Supplier PO is raised against. A supplier can hold more than one type (e.g. Quarry Owner and Factory Owner). Editing or deleting a supplier here never changes an order that has already saved its Supplier PO terms — the order keeps its own copy of the supplier's details.</p>

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
      <?php if ($filterType !== '' || $search !== '' || $show !== 'all'): ?><a href="/suppliers" class="btn-sm btn-secondary">Clear</a><?php endif; ?>
      <?php if ($show !== 'all'): ?><input type="hidden" name="show" value="<?= htmlspecialchars($show) ?>"><?php endif; ?>
      <a href="/suppliers/create" class="btn-sm btn-accent" style="margin-left:auto;">Add Supplier</a>
    </form>
    <?php // D-360: All / Active / Inactive / suppliers with no product price yet (kept with the type and search). ?>
    <?php $__qs = static fn (string $v): string => '/suppliers?' . http_build_query(array_filter(['type' => $filterType, 'q' => $search, 'show' => $v !== 'all' ? $v : ''], static fn ($x) => $x !== '')); ?>
    <nav class="filter-pills" aria-label="Show suppliers">
      <span class="muted small">Show:</span>
      <?php foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'no-prices' => 'No product prices yet (' . (int) $noPricesCount . ')'] as $__k => $__l): ?>
        <a href="<?= htmlspecialchars($__qs($__k)) ?>" class="filter-pill<?= $show === $__k ? ' is-current' : '' ?>"<?= $show === $__k ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($__l) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="table-scroll">
    <table class="list suppliers-table">
      <?php $__withOffers = $offers !== null; $__cols = $__withOffers ? 12 : 9; ?>
      <tr><th>Supplier / product</th><th>Type(s)</th><th>Location</th><th>Phone / WhatsApp</th><th>Contact Person</th><th>GSTIN</th><th>Bank</th><?php if ($__withOffers): ?><th>Base price quoted (₹)</th><th>Quoted on · valid until</th><th>Offer active</th><?php endif; ?><th>Status</th><th>Actions</th></tr>
      <?php if (empty($suppliers)): ?>
      <tr><td colspan="<?= $__cols ?>" class="muted"><?= ($filterType !== '' || $search !== '' || $show !== 'all') ? 'No suppliers match this filter.' : 'No suppliers yet — add the first one above.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($suppliers as $s): ?>
      <?php $used = isset($usedIds[(int) $s['id']]); $__mine = $__withOffers ? ($offers[(int) $s['id']] ?? []) : []; $__none = ($offerCounts[(int) $s['id']] ?? 0) === 0; ?>
      <tr class="supplier-row" id="supplier-<?= (int) $s['id'] ?>">
        <td><strong><?= htmlspecialchars($s['supplier_legal_name']) ?></strong><?= !empty($s['details_pending']) ? ' <span class="badge badge-warning">Details pending</span>' : '' ?><?= $__none ? ' <span class="badge badge-warning">No product prices yet</span>' : '' ?><?php if (!empty($s['email'])): ?><br><small class="muted"><?= htmlspecialchars($s['email']) ?></small><?php endif; ?></td>
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
        <td><?php if (empty($s['bank_account_number'])): ?><span class="muted">—</span><?php elseif (!empty($s['bank_verified_at'])): ?><span class="badge good">&#10003; Verified</span><?php else: ?><span class="badge warn">&#9888; Not verified</span><?php endif; ?></td>
        <?php if ($__withOffers): ?><td></td><td></td><td></td><?php endif; ?>
        <td><span class="badge <?= $s['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td><div class="row-actions">
          <a href="/suppliers/<?= (int) $s['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
          <?php if (!empty($canEditPrice) && $s['is_active']): ?><a href="/suppliers/<?= (int) $s['id'] ?>/add-product" class="btn-sm">+ Add product</a><?php endif; ?>
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
        </div></td>
      </tr>
      <?php if ($__mine !== [] && !$s['is_active']): ?>
      <tr class="offer-row offer-row-off"><td colspan="<?= $__cols ?>" class="muted small offer-indent">Inactive: none of their prices are used anywhere.</td></tr>
      <?php endif; ?>
      <?php foreach ($__mine as $__off): ?>
      <tr class="offer-row<?= !$s['is_active'] || !$__off['offer_active'] ? ' offer-row-off' : '' ?>">
        <td class="offer-indent"><a href="/products/<?= (int) $__off['product_id'] ?>"><?= htmlspecialchars($__off['product_name']) ?></a><?= !$__off['product_active'] ? ' <span class="badge badge-inactive">Product inactive</span>' : '' ?></td>
        <td colspan="6"></td>
        <td><?= htmlspecialchars($__off['price']) ?></td>
        <td><?= htmlspecialchars($__off['dates']) ?></td>
        <td><?php $__from = 'suppliers'; $__canSwitch = !empty($canEditPrice); require __DIR__ . '/_offer_switch.php'; ?></td>
        <td></td>
        <td><?php if (!empty($canEditPrice) && $__off['editable']): ?><a href="/products/suppliers/<?= (int) $__off['id'] ?>/edit-price?from=suppliers" class="btn-sm btn-secondary">Edit price</a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </table>
    </div>
  </div>
</div>
