<?php use App\Helpers\Csrf; use App\Services\ImportService as I; use App\Services\MerchantTradeService as M;
/* Owner decision D-257 Phase 4: a new merchant trade deal — the supplier abroad, the buyer abroad, both currencies and the route of the goods. */
$o = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? '')); ?>
<div class="card page-wide">
  <p><a href="/merchant">&larr; Merchant trade deals</a></p>
  <h1>New merchant trade deal</h1>
  <?php if (!$active): ?>
    <p class="muted" data-testid="mtt-not-active">Merchant Trade is not active yet. The MD activates it in Admin › Divisions when it is ready.</p>
  <?php else: ?>
  <p class="muted">Buy from a supplier abroad and sell to a buyer abroad; the goods go straight from one to the other. The deal and PO numbers are given when you save.</p>
  <form method="post" action="/merchant" class="field-grid" data-testid="mtt-create-form">
    <?= Csrf::field() ?>
    <?php require __DIR__ . '/_deal_fields.php'; ?>
    <p><button type="submit" class="btn">Create deal</button></p>
  </form>
  <?php endif; ?>
</div>
