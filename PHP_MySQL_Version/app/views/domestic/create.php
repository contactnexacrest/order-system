<?php use App\Helpers\Csrf;
/* Owner decision D-257 Phase 2: a new Domestic Trade (DOM) order — the buyer, its GST registration and the place of supply. */
$o = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? '')); ?>
<div class="card page-wide">
  <h1>New domestic order</h1>
  <?php if (!$active): ?>
    <p class="muted" data-testid="dom-not-active">Domestic Trade is not active yet. The MD activates it in Admin › Divisions when it is ready.</p>
  <?php else: ?>
  <p class="muted">A sale to a reseller in India, in rupees, with GST. Same state as NexaCrest: CGST + SGST; another state: IGST.</p>
  <form method="post" action="/domestic/orders" class="field-grid" data-testid="dom-create-form">
    <?= Csrf::field() ?>
    <label>Client *
      <select name="client_id" required>
        <option value="">Choose…</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (string) ($old['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $c['company_legal_name']) ?><?= !empty($c['gstin']) ? ' — ' . htmlspecialchars((string) $c['gstin']) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Buyer's GSTIN<input type="text" name="buyer_gstin" value="<?= $o('buyer_gstin') ?>" maxlength="15" pattern="[0-9]{2}[A-Za-z]{5}[0-9]{4}[A-Za-z][1-9A-Za-z][Zz][0-9A-Za-z]" placeholder="e.g. 29ABCDE1234F1Z5"><small class="muted">Needed before the order confirmation.</small></label>
    <label>Buyer's state *
      <select name="buyer_state_code" required>
        <option value="">Choose…</option>
        <?php foreach ($states as $s): ?><option value="<?= htmlspecialchars((string) $s['code']) ?>" <?= ($old['buyer_state_code'] ?? '') === $s['code'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name'] . ' (' . $s['code'] . ')') ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Place of supply *
      <select name="place_of_supply" required>
        <option value="">Choose…</option>
        <?php foreach ($states as $s): ?><option value="<?= htmlspecialchars((string) $s['code']) ?>" <?= ($old['place_of_supply'] ?? '') === $s['code'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name'] . ' (' . $s['code'] . ')') ?></option><?php endforeach; ?>
      </select>
      <small class="muted">The state the goods are delivered to.</small>
    </label>
    <label>Delivery address<textarea name="delivery_address" rows="3" maxlength="1000" placeholder="e.g. Plot 12, KIADB Industrial Area, Hosur Road, Bengaluru 560099"><?= $o('delivery_address') ?></textarea></label>
    <label>Payment terms (days)<input type="number" name="payment_terms_days" min="0" max="365" step="1" value="<?= $o('payment_terms_days') ?>" placeholder="e.g. <?= htmlspecialchars((string) $defaultTerms) ?>"></label>
    <label>Buyer's PO number<input type="text" name="buyer_po_ref" maxlength="80" value="<?= $o('buyer_po_ref') ?>" placeholder="e.g. PO/2026/118"></label>
    <p><button type="submit" class="btn">Create domestic order</button></p>
  </form>
  <?php endif; ?>
</div>
