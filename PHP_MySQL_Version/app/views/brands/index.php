<?php
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\AssociateBrands;

/** D-431 (owner 25–26 Nov 2026): Admin › Brands — the division names associates see (name only, no logo). */
?>
<div class="card page-wide">
  <h1>Brands</h1>
  <p class="muted" data-brands-intro>The division name an associate sees on their login page, portal and e-mails, with the line under it. Buyers never see a brand: their form, e-mails, documents and portal stay NexaCrest. Agreements, invoices and payments always come from NexaCrest International Private Limited. An associate with no brand sees NexaCrest, as before.</p>
  <div class="table-scroll">
  <table class="list" data-testid="brands-table">
    <tr><th>Brand</th><th>Products it covers</th><th>Login address</th><th>Website</th><th>E-mail shown to associates</th><th>Associates</th><th>Status</th><th></th></tr>
    <?php foreach ($brands as $b): ?>
    <tr>
      <td><strong><?= View::e((string) $b['name']) ?></strong><br><small class="muted"><?= View::e((string) $b['tagline']) ?></small></td>
      <td><?= View::e((string) ($b['products'] ?? '—')) ?></td>
      <td><code>/associate/login/<?= View::e((string) $b['code']) ?></code></td>
      <td><?= View::e((string) ($b['website'] ?? '—')) ?></td>
      <td><?= View::e((string) ($b['email'] ?? '—')) ?></td>
      <td><a href="/associates?view=list&amp;brand=<?= (int) $b['id'] ?>"><?= (int) $b['associate_count'] ?></a></td>
      <td><span class="badge <?= $b['status'] === 'active' ? 'badge-success' : 'badge-inactive' ?>"><?= View::e(AssociateBrands::STATUSES[$b['status']] ?? (string) $b['status']) ?></span></td>
      <td><a href="/admin/brands/<?= (int) $b['id'] ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($brands === []): ?><tr><td colspan="8" class="muted">No brands yet — every associate sees NexaCrest.</td></tr><?php endif; ?>
  </table>
  </div>
  <?php if ($canEdit): ?>
  <details class="section"<?= $brands === [] ? ' open' : '' ?>>
    <summary><strong>Add brand</strong></summary>
    <form method="post" action="/admin/brands" data-brand-form>
      <?= Csrf::field() ?>
      <?php require __DIR__ . '/_details_fields.php'; ?>
      <button type="submit">Add brand</button>
    </form>
  </details>
  <?php endif; ?>
</div>
