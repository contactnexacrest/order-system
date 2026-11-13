<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<?php
/**
 * Products list. Owner decision D-361 (13 Nov 2026, mockup products_now_vs_after.png): one row
 * per product, built from the suppliers' products — Suppliers (active), Lowest – highest
 * supplier price (each a link to that offer's Edit price), the configured Base price, the USD
 * columns as before, the latest supplier quote, and where the product stands (Configured / Not
 * configured / No active supplier / No supplier yet) with a filter for each. New products come
 * from Procurement › Suppliers › "+ Add product" ("+ Add Product" and "Link suppliers" are gone
 * from here). Node twin: views/products/index.njk.
 */
$__filters = ['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All', 'not_configured' => 'Not configured', 'no_active_supplier' => 'No active supplier', 'no_supplier_yet' => 'No supplier yet'];
$__money = static fn (float $v): string => '₹' . number_format($v, 2);
?>
<div class="card page-wide">
  <h1>Product Catalog</h1>
  <p class="muted">One row per product, built from the suppliers' products — specifications, HS code, suppliers and the configured base price. Products are picked on order lines and used for price checks. The prices here are internal and are never shown to buyers; an associate sees only their own price list.</p>

  <?php if ($everCount === 0 && !$searched): ?>
    <div class="empty-state" data-testid="products-empty">
      <h2>No products yet</h2>
      <p>1. Add a supplier · 2. Add the products they offer, with their price · 3. The products appear here — click <b>Configure</b> to set the selling price.</p>
      <?php if (!empty($canGoSuppliers)): ?><p><a class="btn-sm" href="/suppliers">Go to Suppliers</a></p><?php endif; ?>
    </div>
  <?php else: ?>

  <form method="get" action="/products" style="display:flex;gap:8px;margin-bottom:1rem">
    <input type="text" name="q" value="<?= View::e($query) ?>" placeholder="Search by name, HS code, specification or supplier…" style="flex:1" aria-label="Search by name, HS code, specification or supplier">
    <?php if ($status !== 'active'): ?><input type="hidden" name="status" value="<?= View::e($status) ?>"><?php endif; ?>
    <button type="submit" class="btn-sm">Search</button>
    <?php if ($searched): ?><a class="btn-sm" href="/products<?= $status !== 'active' ? '?status=' . View::e($status) : '' ?>">Clear</a><?php endif; ?>
  </form>
  <?php // Owner (12 Nov 2026): Active · Inactive · All; D-361: Not configured · No active supplier · No supplier yet. ?>
  <?php if ($statusCounts !== null): ?>
    <div class="filter-chip-row" aria-label="Show products by status">
      <?php foreach ($__filters as $__k => $__label): ?>
        <?php if (in_array($__k, ['active', 'inactive', 'all'], true) || (int) $statusCounts[$__k] > 0 || $status === $__k): ?>
        <a class="filter-chip<?= $status === $__k ? ' active' : '' ?>" href="/products?status=<?= $__k ?><?= $searched ? '&amp;q=' . View::e(rawurlencode($query)) : '' ?>" data-filter="<?= $__k ?>"><?= $__label ?> (<?= (int) $statusCounts[$__k] ?>)</a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php if ($status !== 'active'): ?><p class="muted small">An inactive product cannot be picked on new order lines. "Used on" shows where it is still used: only a product that is not used anywhere can be deleted.</p><?php endif; ?>
  <?php endif; ?>

  <?php // D-304 (point 14): the pricing rate, explained — price build-up viewers only. ?>
  <?php if (!empty($canBuildup) && !empty($fx)): include __DIR__ . '/../pricing/_fx_rate_line.php'; endif; ?>
  <?php if (!empty($canBuildup)): ?><p class="muted small">Supplier prices are in ₹; every other price is in USD per the product's unit, FOB, worked out from the configured base price. Yellow badge = this product overrides a pricing value; a supplier price older than the "stale" setting or past its valid-until date is marked.</p><?php endif; ?>
  <p>
    <?php if ($canManage): ?><a class="btn-sm" href="/products/categories">Categories</a><?php endif; ?>
    <?php if (!empty($canPriceRules)): ?><a class="btn-sm" href="/products/price-rules">Category price rules</a><?php endif; ?>
    <?php if (!empty($canPricingSettings)): ?><a class="btn-sm" href="/pricing/settings">Global pricing settings</a><?php endif; ?>
    <?php if (!empty($canFixHs)): ?><a class="btn-sm btn-secondary" href="/products/fix-hs-codes">Fix HS codes</a><?php endif; ?>
    <?php if (!empty($canLinkPrices) && $unlinkedCount > 0): ?><a class="btn-sm btn-secondary" href="/products/link-prices" data-testid="link-these-prices">Link these prices (<?= (int) $unlinkedCount ?>)</a><?php endif; ?>
    <?php if (!empty($canGoSuppliers)): ?><a class="btn-sm btn-secondary" href="/suppliers">Go to Suppliers</a><?php endif; ?>
  </p>
  <p class="muted small">New products are added from <b>Procurement › Suppliers › + Add product</b>; they then appear here to configure.</p>

  <?php if (!$canBrowse && !$searched): ?>
    <p class="muted">Enter a search term above to find a product. You do not have permission to browse the full catalog list.</p>
  <?php else: ?>
    <?php // D-304 (point 14): every column says its currency and unit — one unit in the header when all rows share it.
      $__units = array_values(array_unique(array_map(static fn (array $p): string => (string) ($p['reference_unit'] ?? '') ?: 'unit', $products)));
      $__hu = count($__units) === 1 ? $__units[0] : 'unit';
      $__perRow = count($__units) > 1;
      $__usdRate = !empty($fx) ? (float) $fx['rate'] : null; ?>
    <table class="list">
      <tr>
        <th>Name</th>
        <th>HS Code</th>
        <th>Origin</th>
        <?php if ($canViewPricing): ?><th>Suppliers</th><th>Lowest – highest<br>supplier price ₹</th><th>Base price ₹<br>(configured)</th><?php endif; ?>
        <?php // Owner decision D-245 (4 Nov 2026): the price build-up for MD/ED; the quote and lowest price for selling-price staff. ?>
        <?php if (!empty($canBuildup)): ?><th class="num">Cost<br>USD / <?= View::e($__hu) ?></th><th class="num">Min price<br>USD / <?= View::e($__hu) ?></th><th class="num">Staff lowest<br>USD / <?= View::e($__hu) ?></th><th class="num">Staff quote<br>USD / <?= View::e($__hu) ?></th><th class="num">Associate lowest<br>USD / <?= View::e($__hu) ?></th><?php endif; ?>
        <?php // D-305 (point 19): staff see only the quote and their own lowest price. ?>
        <?php if (!empty($canStaffPrice)): ?><th class="num">Quote price<br>USD / <?= View::e($__hu) ?></th><th class="num">Your lowest<br>USD / <?= View::e($__hu) ?></th><?php endif; ?>
        <?php if ($canViewPricing || !empty($canBuildup)): ?><th>Latest supplier quote</th><?php endif; ?>
        <th>Status</th>
        <?php if ($status !== 'active'): ?><th>Used on</th><?php endif; ?>
        <th></th>
      </tr>
      <?php if (empty($products)): ?>
        <tr><td colspan="16" class="muted">No products found.</td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p): $__st = $p['standing']; $__o = $p['offers_view'] ?? null; ?>
        <tr data-product-row="<?= (int) $p['id'] ?>">
          <td><a href="/products/<?= (int) $p['id'] ?>"><?= View::e($p['name']) ?></a><?= $__perRow ? ' <small class="muted">per ' . View::e((string) ($p['reference_unit'] ?? '') ?: 'unit') . '</small>' : '' ?><?php if (!empty($canBuildup) && ($p['ready_to_fix'] ?? 0) > 0): ?> <a class="badge badge-danger" href="/products/<?= (int) $p['id'] ?>#pricing-ready" title="Pricing ready? — something to fix before this product can be priced">Pricing: <?= (int) $p['ready_to_fix'] ?> to fix</a><?php endif; ?><?php if (!empty($canBuildup) && ($p['pricing']['overrides'] ?? []) !== []): ?> <span class="badge badge-warning" title="This product overrides a pricing value"><?= View::e(implode(', ', $p['pricing']['overrides'])) ?></span><?php endif; ?></td>
          <td><?= View::e($p['hs_code'] !== '' ? $p['hs_code'] : '—') ?></td>
          <td><?= View::e($p['origin'] ?? '—') ?></td>
          <?php if ($canViewPricing): ?>
            <td><?= (int) $__st['usable'] ?> active<?php if ((int) $__st['paused'] > 0): ?><br><small class="muted"><?= (int) $__st['paused'] ?> paused</small><?php endif; ?></td>
            <td><?php if ($__o['lowest'] === null): ?>—<?php else: ?>
              <?php $__pair = (int) $__o['lowest']['id'] === (int) $__o['highest']['id'] ? [[$__o['lowest'], '']] : [[$__o['lowest'], 'price-low'], [$__o['highest'], 'price-high']]; ?>
              <?php foreach ($__pair as $__i => [$__r, $__cls]): ?><?= $__i > 0 ? ' – ' : '' ?><?php if (!empty($canEditPrice)): ?><a href="/products/suppliers/<?= (int) $__r['id'] ?>/edit-price?from=products" class="<?= $__cls ?>" title="<?= View::e((string) $__r['supplier_name']) ?> — Edit price">₹<?= View::e(\App\Services\ThicknessPrices::amount((float) $__r['base']['inr'])) ?></a><?php else: ?><span class="<?= $__cls ?>">₹<?= View::e(\App\Services\ThicknessPrices::amount((float) $__r['base']['inr'])) ?></span><?php endif; ?><?php endforeach; ?>
              <br><small class="muted"><?= View::e((string) $__o['lowest']['base']['label']) ?></small><?php endif; ?></td>
            <td><?php if ($__o['base'] === null): ?><span class="muted">not set</span><?php else: ?><?= $__money((float) $__o['base']['inr']) ?>
              <?php if ($canManage && $__usdRate !== null && $__usdRate > 0): // D-304: product editors only ?><br><b class="usd-approx">≈ USD <?= number_format((float) $__o['base']['inr'] / $__usdRate, 2) ?></b><?php endif; ?>
              <br><small class="muted"><?= View::e((string) $__o['base']['label']) ?></small><?php endif; ?></td>
          <?php endif; ?>
          <?php if (!empty($canBuildup)): $__b = $p['pricing']['build']; ?>
            <?php if ($__b === null): ?>
              <td colspan="5" class="muted small"><?= View::e((string) $p['pricing']['missing']) ?></td>
            <?php else: ?>
              <td class="num"><?= number_format($__b['cost'], 2) ?></td>
              <td class="num"><?= number_format($__b['minimum'], 2) ?></td>
              <td class="num"><?= number_format($__b['staff_floor'], 2) ?></td>
              <td class="num"><?= number_format($__b['staff_quote'], 2) ?></td>
              <td class="num"><?= $p['pricing']['lowest'] === null ? '—' : number_format($p['pricing']['lowest']['floor'], 2) ?></td>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!empty($canStaffPrice)): $__b = $p['pricing']['build']; ?>
            <td class="num"><?= $__b === null ? '—' : number_format($__b['staff_quote'], 2) ?></td>
            <td class="num"><?= $__b === null ? '—' : number_format($__b['staff_floor'], 2) ?></td>
          <?php endif; ?>
          <?php if ($canViewPricing || !empty($canBuildup)): ?>
            <td><?= View::e((string) (($__o['latest_quote'] ?? null) ?? ($p['pricing']['price_date'] ?? null) ?? '—')) ?><?php if (!empty($canBuildup) && ($p['pricing']['staleness'] ?? null) !== null && $p['pricing']['build'] !== null): ?> <span class="badge <?= $p['pricing']['staleness'] === 'expired' ? 'badge-danger' : 'badge-warning' ?>"><?= $p['pricing']['staleness'] ?></span><?php endif; ?></td>
          <?php endif; ?>
          <td><span class="badge <?= $p['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span>
            <br><span class="badge <?= View::e($__st['badge']) ?>" data-standing="<?= View::e($__st['key']) ?>"><?= View::e($__st['label']) ?></span></td>
          <?php if ($status !== 'active'): ?><td<?= $p['is_active'] || ($p['usage']['total'] ?? 0) === 0 ? ' class="muted"' : '' ?>><?= $p['is_active'] ? '—' : View::e($p['usage_text']) ?></td><?php endif; ?>
          <td>
            <?php if ($canManage): // D-361: Configure (not configured) or Edit — manage_product_catalog only (owner decision 2) ?>
              <?php if ($__st['configured']): ?><a class="btn-sm" href="/products/<?= (int) $p['id'] ?>/configure">Edit</a>
              <?php else: ?><a class="btn-sm btn-primary" href="/products/<?= (int) $p['id'] ?>/configure">Configure</a><?php endif; ?>
            <?php endif; ?>
            <?php if ($__st['no_supplier_yet'] && !empty($canLinkSupplier)): // decision 1 part A ?><a class="btn-sm btn-primary" href="/products/<?= (int) $p['id'] ?>/link-supplier">Link a supplier</a><?php endif; ?>
            <br><a href="/products/<?= (int) $p['id'] ?>">View</a>
            <?php if ($canManage): // Owner decision D-358 (13 Nov 2026) ?> · <a href="/products/<?= (int) $p['id'] ?>/duplicate" data-duplicate-link>Duplicate</a><?php endif; ?>
            <?php if ($canManage && !$p['is_active']): ?>
              <?php if ($__st['can_activate']): ?>
                <form method="post" action="/products/<?= (int) $p['id'] ?>/reactivate" class="inline-form" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="return_to" value="list"><input type="hidden" name="status" value="<?= View::e($status) ?>"><?php if ($searched): ?><input type="hidden" name="q" value="<?= View::e($query) ?>"><?php endif; ?><button type="submit" class="btn-sm">Reactivate</button></form>
              <?php else: ?>
                <button type="button" class="btn-sm btn-secondary" disabled title="<?= View::e((string) $__st['reason']) ?>">Reactivate</button>
              <?php endif; ?>
              <?php if ($p['delete_refusal'] === null): ?>
                <form method="post" action="/products/<?= (int) $p['id'] ?>/delete" class="inline-form" style="display:inline" data-confirm="Delete &quot;<?= View::e($p['name']) ?>&quot;? It is not used anywhere. Its supplier prices, images and price history are deleted too. This cannot be undone."><?= Csrf::field() ?><button type="submit" class="btn-sm btn-danger">Delete…</button></form>
              <?php else: ?>
                <button type="button" class="btn-sm btn-secondary" disabled title="<?= View::e($p['delete_refusal']) ?>">Delete</button>
                <div class="muted small"><?= View::e($p['delete_refusal']) ?></div>
              <?php endif; ?>
            <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="muted small">"Lowest – highest" are the active suppliers' prices, quoted the same way as the base price. The <b>configured base price</b> is fixed when the product is configured (default = the highest supplier price) and never changes by itself; all USD columns are worked out from it. A product that is not configured, or has no active supplier, is not offered on orders. <span class="badge badge-danger">No supplier yet</span> = a product from before suppliers were linked: it keeps working until a supplier is linked.</p>
  <?php endif; ?>
  <?php endif; ?>
</div>
