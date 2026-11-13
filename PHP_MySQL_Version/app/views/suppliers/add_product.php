<?php
use App\Helpers\View;

/**
 * Owner decision D-360 (13 Nov 2026): "+ Add product" opened from the Suppliers list — the same
 * form as the Edit supplier page's panel (suppliers/_add_product.php). Saving goes back to the
 * list with a green message. Node twin: add_product.njk.
 */
$__sup = $addProduct['supplier'];
?>
<div class="card page-wide">
  <p class="muted small"><a href="/suppliers#supplier-<?= (int) $__sup['id'] ?>">&larr; Back to Suppliers</a></p>
  <div class="add-offer-panel">
    <h1 class="add-offer-title">+ Add product — <?= View::e((string) $__sup['supplier_legal_name']) ?></h1>
    <?php require __DIR__ . '/_add_product.php'; ?>
  </div>
</div>
