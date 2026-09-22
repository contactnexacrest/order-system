<?php use App\Helpers\View; ?>
<div class="card page-wide">
  <h1>Product Catalog</h1>
  <p class="muted">Internal reference only — specs, HS code, cost, and suppliers for staff to consult while preparing quotations manually. This catalog is completely independent of the order pipeline and is never shown to clients or buyers.</p>

  <form method="get" action="/products" style="display:flex;gap:8px;margin-bottom:1rem">
    <input type="text" name="q" value="<?= View::e($query) ?>" placeholder="Search by name, HS code, or specification…" style="flex:1">
    <button type="submit" class="btn-sm">Search</button>
    <?php if ($searched): ?><a class="btn-sm" href="/products">Clear</a><?php endif; ?>
  </form>

  <?php if ($canManage): ?>
    <p><a class="btn-sm btn-success" href="/products/create">+ Add Product</a></p>
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
        <th>Status</th>
        <th></th>
      </tr>
      <?php if (empty($products)): ?>
        <tr><td colspan="6" class="muted">No products found.</td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><a href="/products/<?= (int) $p['id'] ?>"><?= View::e($p['name']) ?></a></td>
          <td><?= View::e($p['hs_code']) ?></td>
          <td><?= View::e($p['origin'] ?? '—') ?></td>
          <?php if ($canViewPricing): ?>
            <td><?= $p['headline_fob'] === null ? 'TBC' : number_format((float) $p['headline_fob'], 2) ?></td>
          <?php endif; ?>
          <td><span class="badge <?= $p['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td><a class="btn-sm" href="/products/<?= (int) $p['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
