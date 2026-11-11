<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<div class="card page-wide">
  <h1>Product Catalog</h1>
  <p class="muted">The company's products — specifications, HS code, costs and suppliers. Products are picked on order lines and used for price checks. The prices here are internal and are never shown to buyers; an associate sees only their own price list.</p>

  <form method="get" action="/products" style="display:flex;gap:8px;margin-bottom:1rem">
    <input type="text" name="q" value="<?= View::e($query) ?>" placeholder="Search by name, HS code, or specification…" style="flex:1" aria-label="Search by name, HS code, or specification">
    <?php if ($status !== 'active'): ?><input type="hidden" name="status" value="<?= View::e($status) ?>"><?php endif; ?>
    <button type="submit" class="btn-sm">Search</button>
    <?php if ($searched): ?><a class="btn-sm" href="/products<?= $status !== 'active' ? '?status=' . View::e($status) : '' ?>">Clear</a><?php endif; ?>
  </form>
  <?php // Owner (12 Nov 2026): Active · Inactive · All — on the full list and on a search. ?>
  <?php if ($statusCounts !== null): ?>
    <div class="filter-chip-row" aria-label="Show products by status">
      <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $__k => $__label): ?>
        <a class="filter-chip<?= $status === $__k ? ' active' : '' ?>" href="/products?status=<?= $__k ?><?= $searched ? '&amp;q=' . View::e(rawurlencode($query)) : '' ?>"><?= $__label ?> (<?= (int) $statusCounts[$__k] ?>)</a>
      <?php endforeach; ?>
    </div>
    <?php if ($status !== 'active'): ?><p class="muted small">An inactive product cannot be picked on new order lines. "Used on" shows where it is still used: only a product that is not used anywhere can be deleted.</p><?php endif; ?>
  <?php endif; ?>

  <?php // D-304 (point 14): the pricing rate, explained — price build-up viewers only. ?>
  <?php if (!empty($canBuildup) && !empty($fx)): include __DIR__ . '/../pricing/_fx_rate_line.php'; endif; ?>
  <?php if (!empty($canBuildup)): ?><p class="muted small">Supplier prices are in ₹; every other price is in USD per the product's unit, FOB. Yellow badge = this product overrides a global pricing value; a supplier price older than the "stale" setting or past its valid-until date is marked.</p><?php endif; ?>
  <p>
    <?php if ($canManage): ?><a class="btn-sm btn-accent" href="/products/create">+ Add Product</a> <a class="btn-sm" href="/products/categories">Categories</a><?php endif; ?>
    <?php if (!empty($canPriceRules)): ?><a class="btn-sm" href="/products/price-rules">Price rules</a><?php endif; ?>
    <?php if (!empty($canPricingSettings)): ?><a class="btn-sm" href="/pricing/settings">Pricing settings</a><?php endif; ?>
    <?php if (!empty($canFixHs)): ?><a class="btn-sm btn-secondary" href="/products/fix-hs-codes">Fix HS codes</a><?php endif; ?>
    <?php if (!empty($canLinkSuppliers)): ?><a class="btn-sm btn-secondary" href="/products/link-suppliers">Link suppliers</a><?php endif; ?>
  </p>

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
        <?php if ($canViewPricing): ?><th>Supplier price<br>₹ / <?= View::e($__hu) ?></th><?php endif; ?>
        <?php // Owner decision D-245 (4 Nov 2026): the price build-up for MD/ED; the quote and lowest price for selling-price staff. ?>
        <?php if (!empty($canBuildup)): ?><th class="num">Cost<br>USD / <?= View::e($__hu) ?></th><th class="num">Min price<br>USD / <?= View::e($__hu) ?></th><th class="num">Staff lowest<br>USD / <?= View::e($__hu) ?></th><th class="num">Staff quote<br>USD / <?= View::e($__hu) ?></th><th class="num">Associate lowest<br>USD / <?= View::e($__hu) ?></th><th>Supplier price date</th><?php endif; ?>
        <?php // D-305 (point 19): staff see only the quote and their own lowest price. ?>
        <?php if (!empty($canStaffPrice)): ?><th class="num">Quote price<br>USD / <?= View::e($__hu) ?></th><th class="num">Your lowest<br>USD / <?= View::e($__hu) ?></th><?php endif; ?>
        <th>Status</th>
        <?php if ($status !== 'active'): ?><th>Used on</th><?php endif; ?>
        <th></th>
      </tr>
      <?php if (empty($products)): ?>
        <tr><td colspan="14" class="muted">No products found.</td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><a href="/products/<?= (int) $p['id'] ?>"><?= View::e($p['name']) ?></a><?= $__perRow ? ' <small class="muted">per ' . View::e((string) ($p['reference_unit'] ?? '') ?: 'unit') . '</small>' : '' ?><?php if (!empty($canBuildup) && ($p['ready_to_fix'] ?? 0) > 0): ?> <a class="badge badge-danger" href="/products/<?= (int) $p['id'] ?>#pricing-ready" title="Pricing ready? — something to fix before this product can be priced">Pricing: <?= (int) $p['ready_to_fix'] ?> to fix</a><?php endif; ?><?php if (!empty($canBuildup) && $p['pricing']['overrides'] !== []): ?> <span class="badge badge-warning" title="This product overrides a global pricing value"><?= View::e(implode(', ', $p['pricing']['overrides'])) ?></span><?php endif; ?></td>
          <td><?= View::e($p['hs_code']) ?></td>
          <td><?= View::e($p['origin'] ?? '—') ?></td>
          <?php if ($canViewPricing): ?>
            <td><?php if ($p['headline_fob'] === null): ?>To Be Confirmed<?php else: ?>₹<?= number_format((float) $p['headline_fob'], 2) ?>
              <?php if ($canManage && $__usdRate !== null && $__usdRate > 0): // D-304: product editors only ?><br><b class="usd-approx">≈ USD <?= number_format((float) $p['headline_fob'] / $__usdRate, 2) ?></b><?php endif; ?>
              <?php if (!empty($p['headline_basis'])): ?><br><small class="muted"><?= View::e((string) $p['headline_basis']) ?></small><?php endif; ?><?php endif; ?></td>
          <?php endif; ?>
          <?php if (!empty($canBuildup)): $__b = $p['pricing']['build']; ?>
            <?php if ($__b === null): ?>
              <td colspan="5" class="muted small"><?= View::e($p['pricing']['missing']) ?></td><td>—</td>
            <?php else: ?>
              <td class="num"><?= number_format($__b['cost'], 2) ?></td>
              <td class="num"><?= number_format($__b['minimum'], 2) ?></td>
              <td class="num"><?= number_format($__b['staff_floor'], 2) ?></td>
              <td class="num"><?= number_format($__b['staff_quote'], 2) ?></td>
              <td class="num"><?= $p['pricing']['lowest'] === null ? '—' : number_format($p['pricing']['lowest']['floor'], 2) ?></td>
              <td><?= View::e($p['pricing']['price_date'] ?? '—') ?><?php if ($p['pricing']['staleness'] !== null): ?> <span class="badge <?= $p['pricing']['staleness'] === 'expired' ? 'badge-danger' : 'badge-warning' ?>"><?= $p['pricing']['staleness'] ?></span><?php endif; ?></td>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!empty($canStaffPrice)): $__b = $p['pricing']['build']; ?>
            <td class="num"><?= $__b === null ? '—' : number_format($__b['staff_quote'], 2) ?></td>
            <td class="num"><?= $__b === null ? '—' : number_format($__b['staff_floor'], 2) ?></td>
          <?php endif; ?>
          <td><span class="badge <?= $p['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <?php if ($status !== 'active'): ?><td<?= $p['is_active'] || ($p['usage']['total'] ?? 0) === 0 ? ' class="muted"' : '' ?>><?= $p['is_active'] ? '—' : View::e($p['usage_text']) ?></td><?php endif; ?>
          <td><a class="btn-sm" href="/products/<?= (int) $p['id'] ?>">View</a>
            <?php if ($canManage && !$p['is_active']): ?>
              <form method="post" action="/products/<?= (int) $p['id'] ?>/reactivate" class="inline-form" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="return_to" value="list"><button type="submit" class="btn-sm">Reactivate</button></form>
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
  <?php endif; ?>
</div>
