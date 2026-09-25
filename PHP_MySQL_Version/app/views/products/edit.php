<div class="card">
  <p class="muted small"><a href="/products/<?= (int) $product['id'] ?>">&larr; Back to Product</a></p>
  <h1>Edit Product — <?= \App\Helpers\View::e($product['name']) ?></h1>
  <?php include __DIR__ . '/_form.php'; ?>
</div>
