<?php use App\Helpers\Csrf; use App\Services\MerchantTradeService as M; use App\Services\ImportService as I; use App\Services\GstCalculator as G;
/* Owner decision D-257 Phase 4: one merchant trade deal — stages, the deal, the goods (buy and sell), the BL / switch BL, money on both legs and the margin, the RBI checks and the AD bank's document checklist. */
$id = (int) $d['id'];
$stage = (string) $d['stage'];
$h = static fn ($val): string => htmlspecialchars((string) $val);
$v = static fn (string $k): string => htmlspecialchars((string) ($d[$k] ?? ''));
$keys = array_keys(M::STAGES);
$pos = (int) array_search($stage, $keys, true);
$next = $stage !== 'closed' ? $keys[$pos + 1] : null;
$f = $figures;
$num = static fn (string $x): string => str_contains($x, '.') ? rtrim(rtrim($x, '0'), '.') : $x;
// QA batch 2 (ACC-4): the selling side (sale prices, margin, sale invoice), the buyer's payments and the buyer's name only for those who may see them.
$seesSell = $money['selling'];
$seesBuyerPay = $money['buyer_payments'];
$buyerName = $money['client'] ? $h($d['buyer_name']) : 'the buyer';
$levelStyle = ['ok' => 'color:#2D7D56', 'info' => 'color:#5B6573', 'warn' => 'color:#8A5A00;font-weight:700', 'breach' => 'color:#B42318;font-weight:700']; ?>
<div class="card page-wide" data-testid="mtt-show">
  <p><a href="/merchant">&larr; Merchant trade deals</a></p>
  <h1><?= $h($d['reference']) ?> <span class="badge"><?= $h(M::STAGES[$stage]) ?></span></h1>
  <p class="muted"><?= $h($d['supplier_name']) ?> (<?= $h($d['supplier_country']) ?>, in <?= $h($d['purchase_currency']) ?>) → <?= $buyerName ?> (<?= $h($d['buyer_country']) ?>, in <?= $h($d['sale_currency']) ?>) · goods from <?= $h($d['origin_country']) ?> · PO <?= $h($d['po_no']) ?><?= $d['sale_invoice_no'] ? ' · invoice ' . $h($d['sale_invoice_no']) : '' ?></p>

  <ol class="stage-strip" data-testid="mtt-stages" style="display:flex;flex-wrap:wrap;gap:6px 18px;padding-left:18px;">
    <?php foreach (M::STAGES as $k => $label): $i = (int) array_search($k, $keys, true); ?>
      <li<?= $k === $stage ? ' style="font-weight:700"' : ($i < $pos ? ' style="color:#2D7D56"' : ' style="color:#8A94A6"') ?>><?= $h($label) ?></li>
    <?php endforeach; ?>
  </ol>

  <?php if ($next !== null): ?>
  <div class="section" data-testid="mtt-next">
    <?php if ($blockers !== []): ?>
      <p><strong>Before moving on to <?= $h(M::STAGES[$next]) ?>:</strong></p>
      <ul><?php foreach ($blockers as $b): ?><li><?= $h($b) ?></li><?php endforeach; ?></ul>
    <?php elseif ($canEdit): ?>
      <form method="post" action="/merchant/<?= $id ?>/advance"><?= Csrf::field() ?><input type="hidden" name="from_stage" value="<?= $h($stage) ?>">
        <button type="submit" class="btn">Move on to <?= $h(M::STAGES[$next]) ?></button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="section" id="mtt-rbi" data-testid="mtt-rbi">
    <h2>RBI checks</h2>
    <p class="muted">Limits from the Merchant Trade settings, to be confirmed with the AD bank and the CA.</p>
    <ul>
      <?php foreach ($checks as $c): ?><li data-check="<?= $h($c['key']) ?>" data-level="<?= $h($c['level']) ?>"><strong><?= $h($c['label']) ?>:</strong> <span style="<?= $levelStyle[$c['level']] ?? '' ?>"><?= $h($c['text']) ?></span></li><?php endforeach; ?>
    </ul>
  </div>

  <div class="section" id="mtt-deal">
    <h2>Deal</h2>
    <?php if ($canEdit && $money['client'] && $seesSell && $stage === 'contracted'): ?>
    <form method="post" action="/merchant/<?= $id ?>/deal" class="field-grid">
      <?= Csrf::field() ?>
      <?php require __DIR__ . '/_deal_fields.php'; ?>
      <p><button type="submit" class="btn-secondary">Save</button></p>
    </form>
    <?php else: ?>
    <p>Deal date <?= $v('deal_date') ?> · buy <?= $v('purchase_incoterm') ?: '—' ?> · sell <?= $v('sale_incoterm') ?: '—' ?> · <?= $v('port_of_loading') ?: '—' ?> → <?= $v('port_of_discharge') ?: '—' ?></p>
    <?php endif; ?>
    <form method="post" action="/merchant/<?= $id ?>/document" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="kind" value="po"><button type="submit" class="btn-secondary">Download purchase order (PDF)</button></form>
    <span class="muted"><?= $d['po_generated_at'] ? 'Generated ' . $v('po_generated_at') . '.' : 'Not generated yet.' ?></span>
  </div>

  <div class="section" id="mtt-lines">
    <h2>Goods</h2>
    <table class="list" data-testid="mtt-lines">
      <tr><th>#</th><th>Description</th><th>HS code</th><th>Qty</th><th>Unit</th><th>Buy <?= $h($d['purchase_currency']) ?></th><th>Buy amount</th><?php if ($seesSell): ?><th>Sell <?= $h($d['sale_currency']) ?></th><th>Sell amount</th><?php endif; ?><th></th></tr>
      <?php foreach ($lines as $i => $l): ?>
      <tr>
        <td><?= $i + 1 ?></td><td><?= $h($l['description']) ?></td><td><?= $h($l['hsn_code']) ?></td><td><?= $h($num((string) $l['quantity'])) ?></td><td><?= $h($l['unit']) ?></td>
        <td><?= $h(I::fc(G::scaled((string) $l['buy_price_fc'], 2))) ?></td><td><?= $h(I::fc(I::lineValueCents((string) $l['quantity'], (string) $l['buy_price_fc']))) ?></td>
        <?php if ($seesSell): ?><td><?= $h(I::fc(G::scaled((string) $l['sell_price_fc'], 2))) ?></td><td><?= $h(I::fc(I::lineValueCents((string) $l['quantity'], (string) $l['sell_price_fc']))) ?></td><?php endif; ?>
        <td><?php if ($canEdit && $stage === 'contracted'): ?><form method="post" action="/merchant/<?= $id ?>/lines/<?= (int) $l['id'] ?>/delete" data-confirm="Remove this line?"><?= Csrf::field() ?><button type="submit" class="btn-link">Remove</button></form><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if ($lines === []): ?><tr><td colspan="<?= $seesSell ? 10 : 8 ?>" class="muted">No goods yet.</td></tr><?php endif; ?>
    </table>
    <p data-testid="mtt-totals">Purchase <?= $h($d['purchase_currency']) ?> <?= $h(I::fc($f['purchase_cents'])) ?><?php if ($seesSell): ?> · sale <?= $h($d['sale_currency']) ?> <?= $h(I::fc($f['sale_cents'])) ?><?= $f['contract_margin_cents'] !== null ? ' · contract margin ' . $h($d['sale_currency']) . ' ' . $h(I::fc($f['contract_margin_cents'])) : '' ?><?php endif; ?></p>
    <?php if ($canEdit && $seesSell && $stage === 'contracted'): ?>
    <form method="post" action="/merchant/<?= $id ?>/lines" class="field-grid" data-testid="mtt-add-line">
      <?= Csrf::field() ?>
      <label>Description *<input type="text" name="description" required maxlength="500" placeholder="e.g. White marble slabs, 2 cm, polished"></label>
      <label>HS code *<input type="text" name="hsn_code" required minlength="4" maxlength="8" pattern="[0-9]{4,8}" placeholder="e.g. 68022100"></label>
      <label>Quantity *<input type="number" name="quantity" required min="0.001" step="0.001" placeholder="e.g. 500"></label>
      <label>Unit *<input type="text" name="unit" required maxlength="20" placeholder="e.g. SQM"></label>
      <label>Buy price per unit (<?= $h($d['purchase_currency']) ?>) *<input type="number" name="buy_price_fc" required min="0.01" step="0.01" placeholder="e.g. 32.00"></label>
      <label>Sell price per unit (<?= $h($d['sale_currency']) ?>) *<input type="number" name="sell_price_fc" required min="0.01" step="0.01" placeholder="e.g. 38.50"></label>
      <p><button type="submit" class="btn-secondary">Add line</button></p>
    </form>
    <?php endif; ?>
  </div>

  <div class="section" id="mtt-shipment">
    <h2>Shipment</h2>
    <?php if ($canEdit && in_array($stage, ['contracted', 'shipped'], true)): ?>
    <form method="post" action="/merchant/<?= $id ?>/bl" class="field-grid">
      <?= Csrf::field() ?>
      <label>BL number *<input type="text" name="bl_no" required maxlength="40" value="<?= $v('bl_no') ?>" placeholder="e.g. MSCUIZ1234567"></label>
      <label>BL date *<input type="date" name="bl_date" required min="<?= $v('deal_date') ?>" max="<?= date('Y-m-d') ?>" value="<?= $v('bl_date') ?>"></label>
      <label>Vessel<input type="text" name="vessel" maxlength="100" value="<?= $v('vessel') ?>" placeholder="e.g. MSC Aurora / 245E"></label>
      <label><input type="checkbox" name="switch_bl" value="1" <?= $d['switch_bl'] ? 'checked' : '' ?>> A switch BL is issued (only where the AD bank allows it)</label>
      <label>Switch BL number<input type="text" name="switch_bl_no" maxlength="40" value="<?= $v('switch_bl_no') ?>" placeholder="e.g. MSCUIZ1234567-S" data-required-when="switch_bl=1" data-required-when-message="Enter the switch BL number."></label>
      <label>Switch BL issued by / bank's consent<input type="text" name="switch_bl_ref" maxlength="255" value="<?= $v('switch_bl_ref') ?>" placeholder="e.g. MSC Dubai agent; SBI letter MT/2026/118" data-required-when="switch_bl=1" data-required-when-message="Enter who issued the switch BL and the bank's consent."></label>
      <p><button type="submit" class="btn-secondary">Save BL</button></p>
    </form>
    <?php else: ?><p>BL <?= $v('bl_no') ?: '—' ?> dated <?= $v('bl_date') ?: '—' ?> · <?= $v('vessel') ?: '—' ?><?= $d['switch_bl'] ? ' · switch BL ' . $v('switch_bl_no') . ' (' . $v('switch_bl_ref') . ')' : '' ?></p><?php endif; ?>
    <?php if ($seesSell && $canEdit): ?>
    <form method="post" action="/merchant/<?= $id ?>/document" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="kind" value="invoice"><button type="submit" class="btn-secondary">Download sale invoice (PDF)</button></form>
    <span class="muted"><?= $d['sale_invoice_no'] ? 'Invoice ' . $v('sale_invoice_no') . ' dated ' . $v('invoice_date') . '.' : 'The invoice number is fixed the first time it is generated (after the BL).' ?></span>
    <?php endif; ?>
  </div>

  <div class="section" id="mtt-payments">
    <h2>Money on both legs</h2>
    <table class="list" data-testid="mtt-payments">
      <tr><th>Date</th><th>Leg</th><th>Amount</th><th>Rate</th><th>₹</th><th>Bank reference</th><th>Description</th></tr>
      <?php $payments = $seesBuyerPay ? $payments : array_values(array_filter($payments, static fn (array $p): bool => $p['leg'] === 'buy')); ?>
      <?php foreach ($payments as $p): $cur = $p['leg'] === 'buy' ? $d['purchase_currency'] : $d['sale_currency']; ?><tr><td><?= $h($p['moved_on']) ?></td><td><?= $h(M::LEGS[$p['leg']]) ?></td><td><?= $h($cur) ?> <?= $h(I::fc(G::scaled((string) $p['amount_fc'], 2))) ?></td><td><?= $h($p['fx_rate']) ?></td><td><?= $h(G::inr((string) $p['amount_inr'])) ?></td><td><?= $h($p['bank_reference']) ?></td><td><?= $h($p['description']) ?></td></tr><?php endforeach; ?>
      <?php if ($payments === []): ?><tr><td colspan="7" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
    <?php if (!($seesSell && $seesBuyerPay)): ?>
    <p data-testid="mtt-margin">Paid to the supplier <?= $h($d['purchase_currency']) ?> <?= $h(I::fc($f['paid_cents'])) ?> of <?= $h(I::fc($f['purchase_cents'])) ?> (₹<?= $h(G::inr($f['paid_inr'])) ?><?= $f['buy_rate'] !== null ? ' at ' . $h($f['buy_rate']) : '' ?>).</p>
    <?php else: ?>
    <p data-testid="mtt-margin">Received from the buyer <?= $h($d['sale_currency']) ?> <?= $h(I::fc($f['received_cents'])) ?> of <?= $h(I::fc($f['sale_cents'])) ?> (₹<?= $h(G::inr($f['received_inr'])) ?><?= $f['sell_rate'] !== null ? ' at ' . $h($f['sell_rate']) : '' ?>)
      · paid to the supplier <?= $h($d['purchase_currency']) ?> <?= $h(I::fc($f['paid_cents'])) ?> of <?= $h(I::fc($f['purchase_cents'])) ?> (₹<?= $h(G::inr($f['paid_inr'])) ?><?= $f['buy_rate'] !== null ? ' at ' . $h($f['buy_rate']) : '' ?>)
      · <strong>margin ₹<?= $h(G::inr($f['margin_inr'])) ?> = USD <?= $h(I::fc($f['margin_usd_cents'])) ?></strong><?= $f['margin_pct'] !== null ? ' (' . $h($f['margin_pct']) . '%)' : '' ?> <?= $f['final'] ? '(final, at the realised rates)' : '(so far)' ?></p>
    <?php endif; ?>
    <p class="muted">Advance backing: <?= $h(M::BACKING[$d['advance_backing']]) ?><?= $d['backing_ref'] ? ' ' . $v('backing_ref') : '' ?>.</p>
    <?php if ($canEdit && $stage !== 'closed'): ?>
    <form method="post" action="/merchant/<?= $id ?>/backing" class="field-grid">
      <?= Csrf::field() ?>
      <label>Advance backed by *<select name="advance_backing" required><?php foreach (M::BACKING as $k => $label): ?><option value="<?= $h($k) ?>" <?= $d['advance_backing'] === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?></select></label>
      <label>LC / bank guarantee number<input type="text" name="backing_ref" maxlength="60" value="<?= $v('backing_ref') ?>" placeholder="e.g. 0123SBLC000789"></label>
      <p><button type="submit" class="btn-secondary">Save backing</button></p>
    </form>
    <?php endif; ?>
    <?php if ($canPay && $stage !== 'closed'): ?>
    <form method="post" action="/merchant/<?= $id ?>/payments" class="field-grid">
      <?= Csrf::field() ?>
      <label>Leg *<select name="leg" required><option value="">Choose…</option><?php foreach (M::LEGS as $k => $label): if ($k === 'sell' && !$seesBuyerPay) { continue; } ?><option value="<?= $h($k) ?>"><?= $h($label) ?></option><?php endforeach; ?></select></label>
      <label>Date *<input type="date" name="moved_on" required max="<?= date('Y-m-d') ?>"></label>
      <label>Amount (the leg's currency) *<input type="number" name="amount_fc" required min="0.01" step="0.01" placeholder="e.g. 16000.00"></label>
      <label>Bank's rate (₹ per unit of the leg's currency) *<input type="number" name="fx_rate" required min="0.0001" max="999.9999" step="0.0001" placeholder="e.g. 84.9200"></label>
      <label>SWIFT / UTR reference *<input type="text" name="bank_reference" required maxlength="60" placeholder="e.g. SBININBB OUTWARD 556677"></label>
      <label>Note<input type="text" name="note" maxlength="255" placeholder="e.g. 50% advance against proforma"></label>
      <p><button type="submit" class="btn-secondary">Record</button></p>
    </form>
    <?php endif; ?>
    <?php if ($canZoho && $d['sale_invoice_no']): ?>
    <form method="post" action="/merchant/<?= $id ?>/zoho"><?= Csrf::field() ?><button type="submit" class="btn-secondary">Send to Zoho Books</button> <span class="muted small">the sale invoice, the supplier's bill and both payment legs — whatever is waiting</span></form>
    <p class="small"><a href="/merchant/<?= $id ?>/zoho-check">Check this deal against Zoho Books &rarr;</a> <span class="muted">(reads only)</span></p>
    <?php endif; ?>
  </div>

  <div class="section" id="mtt-bank">
    <h2>AD bank's document checklist</h2>
    <table class="list" data-testid="mtt-bank">
      <tr><th>Document</th><th>With the bank</th><th></th></tr>
      <?php foreach ($docs as $key => $label): ?>
      <tr><td><?= $h($label) ?></td><td><?= isset($received[$key]) ? $h($received[$key]) : '—' ?></td>
        <td><?php if ($canEdit && $stage !== 'closed'): ?><form method="post" action="/merchant/<?= $id ?>/bank-documents" style="display:flex;gap:6px;align-items:center;"><?= Csrf::field() ?><input type="hidden" name="doc_key" value="<?= $h($key) ?>">
          <input type="date" name="received_on" aria-label="Received on" max="<?= date('Y-m-d') ?>" value="<?= $h($received[$key] ?? '') ?>"><button type="submit" class="btn-link">Save</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted">The list is the Merchant Trade setting <code>mtt_bank_documents</code> (to be confirmed with the AD bank). Clear the date to untick a document.</p>
  </div>
</div>
