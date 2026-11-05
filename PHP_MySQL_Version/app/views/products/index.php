<?php use App\Helpers\View; ?>
<div class="card page-wide">
  <h1>Product Catalog</h1>
  <p class="muted">Internal reference only — specs, HS code, cost, and suppliers for staff to consult while preparing quotations manually. This catalog is completely independent of the order pipeline and is never shown to clients or buyers.</p>

  <form method="get" action="/products" style="display:flex;gap:8px;margin-bottom:1rem">
    <input type="text" name="q" value="<?= View::e($query) ?>" placeholder="Search by name, HS code, or specification…" style="flex:1" aria-label="Search by name, HS code, or specification">
    <button type="submit" class="btn-sm">Search</button>
    <?php if ($searched): ?><a class="btn-sm" href="/products">Clear</a><?php endif; ?>
  </form>

  <?php if (!empty($canBuildup)): ?><p class="muted small">Prices in USD per unit, FOB. Yellow badge = this product overrides a global pricing value; a supplier price older than the "stale" setting or past its valid-until date is marked. <a href="/pricing/settings">Pricing settings</a></p><?php endif; ?>
  <?php if ($canManage): ?>
    <p><a class="btn-sm btn-accent" href="/products/create">+ Add Product</a> <a class="btn-sm" href="/products/categories">Categories &amp; attributes</a></p>
  <?php endif; ?>

  <?php if (!$canBrowse && !$searched): ?>
    <p class="muted">Enter a search term above to find a product. You do not have permission to browse the full catalog list.</p>
  <?php else: ?>
    <table class="list">
      <tr>
        <th>Name</th>
        <th>HS Code</th>
        <th>Origin</th>
        <?php if ($canViewPricing): ?><th>Headline FOB</th><?php endif; ?>
        <?php // Owner decision D-245 (4 Nov 2026): the price build-up for MD/ED; the quote and lowest price for selling-price staff. ?>
        <?php if (!empty($canBuildup)): ?><th class="num">Cost</th><th class="num">Min price</th><th class="num">Staff floor</th><th class="num">Staff quote</th><th class="num">Assoc. floor (lowest level)</th><th>Supplier price date</th><?php endif; ?>
        <?php if (!empty($canStaffPrice)): ?><th class="num">Quote (USD)</th><th class="num">Lowest (USD)</th><?php endif; ?>
        <th>Status</th>
        <th></th>
      </tr>
      <?php if (empty($products)): ?>
        <tr><td colspan="14" class="muted">No products found.</td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><a href="/products/<?= (int) $p['id'] ?>"><?= View::e($p['name']) ?></a><?php if (!empty($canBuildup) && ($p['ready_to_fix'] ?? 0) > 0): ?> <a class="badge badge-danger" href="/products/<?= (int) $p['id'] ?>#pricing-ready" title="Pricing ready? — something to fix before this product can be priced">Pricing: <?= (int) $p['ready_to_fix'] ?> to fix</a><?php endif; ?><?php if (!empty($canBuildup) && $p['pricing']['overrides'] !== []): ?> <span class="badge badge-warning" title="This product overrides a global pricing value"><?= View::e(implode(', ', $p['pricing']['overrides'])) ?></span><?php endif; ?></td>
          <td><?= View::e($p['hs_code']) ?></td>
          <td><?= View::e($p['origin'] ?? '—') ?></td>
          <?php if ($canViewPricing): ?>
            <td><?= $p['headline_fob'] === null ? 'To Be Confirmed' : number_format((float) $p['headline_fob'], 2) ?></td>
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
          <td><a class="btn-sm" href="/products/<?= (int) $p['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
