<?php use App\Helpers\Csrf; use App\Services\DomesticOrderService as D; use App\Services\GstCalculator as G;
/* Owner decision D-257 Phase 2: a Domestic Trade (DOM) order — stages, buyer and GST, lines, purchase, dispatch and e-way bill, tax invoice, payments, documents. */
$id = (int) $order['id'];
$stage = (string) $dom['stage'];
$h = static fn ($v): string => htmlspecialchars((string) $v);
$stateName = static function (?string $code) use ($states): string {
    foreach ($states as $s) { if ($s['code'] === $code) { return $s['name'] . ' (' . $s['code'] . ')'; } }
    return '—';
};
$linesOpen = in_array($stage, ['enquiry', 'quotation', 'order_confirmation', 'purchase'], true);
$keys = array_keys(D::STAGES);
$next = $stage !== 'closed' ? $keys[array_search($stage, $keys, true) + 1] : null; ?>
<div class="card page-wide" data-testid="dom-order">
  <p><a href="/orders">&larr; Orders</a></p>
  <h1><?= $h($order['order_reference']) ?> <span class="badge"><?= $h(D::STAGES[$stage]) ?></span></h1>
  <p class="muted"><?= $h($order['company_legal_name']) ?> · <?= !empty($isImp) ? 'Local sale of imported stock in ₹' : 'Domestic sale in ₹' ?> · <?= $totals['intra_state'] ? 'Same state: CGST + SGST' : 'Another state: IGST' ?></p>

  <ol class="stage-strip" data-testid="dom-stages" style="display:flex;flex-wrap:wrap;gap:6px 18px;padding-left:18px;">
    <?php foreach (D::STAGES as $k => $label): ?>
      <li class="<?= $k === $stage ? 'current' : (D::atOrAfter($stage, $k) ? 'done' : '') ?>"<?= $k === $stage ? ' style="font-weight:700"' : (D::atOrAfter($stage, $k) ? ' style="color:#2D7D56"' : ' style="color:#8A94A6"') ?>><?= $h($label) ?></li>
    <?php endforeach; ?>
  </ol>

  <?php if ($next !== null): ?>
  <div class="section" data-testid="dom-next">
    <?php if ($blockers !== []): ?>
      <p><strong>Before moving on to <?= $h(D::STAGES[$next]) ?>:</strong></p>
      <ul><?php foreach ($blockers as $b): ?><li><?= $h($b) ?></li><?php endforeach; ?></ul>
    <?php elseif ($canEdit): ?>
      <form method="post" action="/domestic/orders/<?= $id ?>/advance"><?= Csrf::field() ?><input type="hidden" name="from_stage" value="<?= $h($stage) ?>">
        <button type="submit" class="btn">Move on to <?= $h(D::STAGES[$next]) ?></button>
        <?php if ($next === 'dispatch'): ?><small class="muted">This fixes the tax invoice number and its totals; the lines can no longer change.<?= !empty($isImp) ? ' The goods are taken out of stock now.' : '' ?></small><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="section" id="dom-party">
    <h2>Buyer and GST</h2>
    <?php if ($canEdit && $linesOpen): ?>
    <form method="post" action="/domestic/orders/<?= $id ?>/party" class="field-grid">
      <?= Csrf::field() ?>
      <label>Buyer's GSTIN<input type="text" name="buyer_gstin" value="<?= $h($dom['buyer_gstin']) ?>" maxlength="15" pattern="[0-9]{2}[A-Za-z]{5}[0-9]{4}[A-Za-z][1-9A-Za-z][Zz][0-9A-Za-z]" placeholder="e.g. 29ABCDE1234F1Z5"></label>
      <label>Buyer's state *<select name="buyer_state_code" required><option value="">Choose…</option><?php foreach ($states as $s): ?><option value="<?= $h($s['code']) ?>" <?= $dom['buyer_state_code'] === $s['code'] ? 'selected' : '' ?>><?= $h($s['name'] . ' (' . $s['code'] . ')') ?></option><?php endforeach; ?></select></label>
      <label>Place of supply *<select name="place_of_supply" required><option value="">Choose…</option><?php foreach ($states as $s): ?><option value="<?= $h($s['code']) ?>" <?= $dom['place_of_supply'] === $s['code'] ? 'selected' : '' ?>><?= $h($s['name'] . ' (' . $s['code'] . ')') ?></option><?php endforeach; ?></select></label>
      <label>Delivery address<textarea name="delivery_address" rows="2" maxlength="1000" placeholder="e.g. Plot 12, KIADB Industrial Area, Bengaluru 560099"><?= $h($dom['delivery_address']) ?></textarea></label>
      <label>Payment terms (days)<input type="number" name="payment_terms_days" min="0" max="365" step="1" value="<?= (int) $dom['payment_terms_days'] ?>" placeholder="e.g. 30"></label>
      <label>Buyer's PO number<input type="text" name="buyer_po_ref" maxlength="80" value="<?= $h($dom['buyer_po_ref']) ?>" placeholder="e.g. PO/2026/118"></label>
      <p><button type="submit" class="btn-secondary">Save</button></p>
    </form>
    <?php else: ?>
    <p>GSTIN <?= $h($dom['buyer_gstin'] ?: 'not given') ?> · State <?= $h($stateName($dom['buyer_state_code'])) ?> · Place of supply <?= $h($stateName($dom['place_of_supply'])) ?> · Payment <?= (int) $dom['payment_terms_days'] ?> days · PO <?= $h($dom['buyer_po_ref'] ?: '—') ?></p>
    <?php endif; ?>
    <p class="muted">NexaCrest's GST state: <?= $h($stateName($dom['seller_state_code'])) ?>.</p>
  </div>

  <div class="section" id="dom-lines">
    <h2>Lines</h2>
    <table class="list" data-testid="dom-lines">
      <tr><th>#</th><th>Description</th><th>HSN</th><th>Qty</th><th>Unit</th><?php if ($seesPrices): ?><th>Rate ₹</th><th>Taxable ₹</th><th>GST</th><th>Tax ₹</th><th>Total ₹</th><?php endif; ?><th></th></tr>
      <?php foreach ($lines as $i => $l): ?>
      <tr>
        <td><?= $i + 1 ?></td><td><?= $h($l['description']) ?></td><td><?= $h($l['hsn_code']) ?></td><td><?= $h($l['quantity']) ?></td><td><?= $h($l['unit']) ?></td>
        <?php if ($seesPrices): ?><td><?= $h(G::inr($l['rate_inr'])) ?></td><td><?= $h(G::inr($l['taxable'])) ?></td><td><?= $h($l['gst_rate_pct']) ?>%</td><td><?= $h(G::inr(G::money(G::scaled($l['cgst'], 2) + G::scaled($l['sgst'], 2) + G::scaled($l['igst'], 2)))) ?></td><td><?= $h(G::inr($l['line_total'])) ?></td><?php endif; ?>
        <td><?php if ($canEdit && $linesOpen): ?><form method="post" action="/domestic/orders/<?= $id ?>/lines/<?= (int) $l['id'] ?>/delete" data-confirm="Remove this line?"><?= Csrf::field() ?><button type="submit" class="btn-link">Remove</button></form><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if ($lines === []): ?><tr><td colspan="<?= $seesPrices ? 11 : 6 /* QA batch 2 (UI-14) */ ?>" class="muted">No lines yet.</td></tr><?php endif; ?>
    </table>
    <?php if ($seesPrices && $lines !== []): ?>
    <p data-testid="dom-totals">Taxable ₹<?= $h(G::inr($totals['taxable_total'])) ?>
      <?php if ($totals['intra_state']): ?> · CGST ₹<?= $h(G::inr($totals['cgst_total'])) ?> · SGST ₹<?= $h(G::inr($totals['sgst_total'])) ?><?php else: ?> · IGST ₹<?= $h(G::inr($totals['igst_total'])) ?><?php endif; ?>
      · Round off ₹<?= $h(G::inr($totals['round_off'])) ?> · <strong>Total ₹<?= $h(G::inr($totals['invoice_total'])) ?></strong></p>
    <?php endif; ?>
    <?php if ($canEdit && $linesOpen && $seesPrices): ?>
    <form method="post" action="/domestic/orders/<?= $id ?>/lines" class="field-grid" data-testid="dom-add-line">
      <?= Csrf::field() ?>
      <?php if (!empty($isImp)): ?>
      <label>Product (from stock) *<select name="product_id" required data-testid="imp-sale-product"><option value="">Choose…</option><?php foreach ($stockProducts as $sp): ?><option value="<?= (int) $sp['product_id'] ?>"><?= $h($sp['product_name'] . ' — on hand ' . rtrim(rtrim((string) $sp['qty_on_hand'], '0'), '.')) ?></option><?php endforeach; ?></select></label>
      <?php endif; ?>
      <label>Description *<input type="text" name="description" required maxlength="500" placeholder="e.g. Black granite slabs, polished, 18 mm"></label>
      <label>HSN *<input type="text" name="hsn_code" required minlength="4" maxlength="8" pattern="[0-9]{4,8}" placeholder="e.g. 68029300"></label>
      <?php // Owner decision item 10a: at most 10,00,000 in any unit; above about 20 containers of the unit only with "I confirm this quantity" (app.js and the server). ?>
      <label>Quantity *<input type="number" name="quantity" required min="0.001" max="1000000" data-max-value="1000000" step="0.001"<?= \App\Services\QuantityLimits::fieldAttributes('unit') ?> placeholder="e.g. 120.5"></label>
      <label>Unit *<input type="text" name="unit" required maxlength="20" placeholder="e.g. SQM"></label>
      <label class="checkbox-row" data-confirm-tick-box><input type="checkbox" name="confirm_large_quantity" value="1" data-confirm-tick=""> I confirm this quantity</label>
      <label>Rate ₹ *<input type="number" name="rate_inr" required min="0.01" max="99999999999.99" step="0.01" data-max-product="quantity" data-max-product-value="999999999999.99" data-max-product-message="The line value (quantity × rate) can be at most ₹9,99,99,99,99,999.99." placeholder="e.g. 1450.00"></label>
      <label>GST %<input type="number" name="gst_rate_pct" min="0" max="40" step="0.01" placeholder="blank = from the GST rates"></label>
      <p><button type="submit" class="btn-secondary">Add line</button></p>
    </form>
    <?php endif; ?>
  </div>

  <?php if (!empty($isImp) && D::atOrAfter($stage, 'order_confirmation')): ?>
  <div class="section" id="dom-purchase" data-testid="imp-sale-stock">
    <h2>Stock</h2>
    <?php if (D::atOrAfter($stage, 'dispatch')): ?>
      <p>The goods were taken out of stock when the tax invoice was fixed.</p>
    <?php elseif ($stockIssues === []): ?>
      <p>Every line is covered by the stock on hand. The goods are taken out of stock when the order moves on to Dispatch.</p>
    <?php else: ?>
      <ul><?php foreach ($stockIssues as $si): ?><li><?= $h($si) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
  <?php elseif (D::atOrAfter($stage, 'order_confirmation')): ?>
  <div class="section" id="dom-purchase">
    <h2>Purchase</h2>
    <?php if ($canEdit && in_array($stage, ['order_confirmation', 'purchase'], true)): ?>
    <form method="post" action="/domestic/orders/<?= $id ?>/purchase" class="field-grid">
      <?= Csrf::field() ?>
      <label>Supplier *<input type="text" name="supplier_name" required maxlength="255" value="<?= $h($dom['supplier_name']) ?>" placeholder="e.g. Sri Balaji Granites"></label>
      <label>Supplier PO number *<input type="text" name="supplier_po_ref" required maxlength="80" value="<?= $h($dom['supplier_po_ref']) ?>" placeholder="e.g. NC/SPO/0045"></label>
      <p><button type="submit" class="btn-secondary">Save purchase</button></p>
    </form>
    <?php else: ?><p>Supplier <?= $h($dom['supplier_name'] ?: '—') ?> · PO <?= $h($dom['supplier_po_ref'] ?: '—') ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (D::atOrAfter($stage, 'dispatch')): ?>
  <div class="section" id="dom-dispatch">
    <h2>Dispatch and e-way bill</h2>
    <p>Tax invoice <strong data-testid="dom-invoice-no"><?= $h($dom['tax_invoice_no']) ?></strong> dated <?= $h($dom['tax_invoice_date']) ?><?php if ($seesPrices): ?> · total ₹<?= $h(G::inr((string) $dom['invoice_total'])) ?><?php endif; ?>.
      <?= $needsEway ? 'Above the e-way bill threshold (₹' . $h(G::inr(D::setting('dom_eway_threshold_inr'))) . '): an e-way bill is needed.' : 'Below the e-way bill threshold.' ?></p>
    <?php if ($canEdit && $stage === 'dispatch'): ?>
    <form method="post" action="/domestic/orders/<?= $id ?>/dispatch" class="field-grid">
      <?= Csrf::field() ?>
      <label>Dispatch date *<input type="date" name="dispatch_date" required value="<?= $h($dom['dispatch_date']) ?>"></label>
      <label>Transporter *<input type="text" name="transporter_name" required maxlength="150" value="<?= $h($dom['transporter_name']) ?>" placeholder="e.g. VRL Logistics"></label>
      <label>Transporter ID<input type="text" name="transporter_id" maxlength="15" value="<?= $h($dom['transporter_id']) ?>" placeholder="e.g. 29AABCV1234D1Z5"></label>
      <label>Vehicle number *<input type="text" name="vehicle_no" required maxlength="20" value="<?= $h($dom['vehicle_no']) ?>" placeholder="e.g. KA01AB1234"></label>
      <label>LR number<input type="text" name="lr_no" maxlength="40" value="<?= $h($dom['lr_no']) ?>" placeholder="e.g. LR-55821"></label>
      <label>Distance (km)<input type="number" name="distance_km" min="0" max="4000" step="1" value="<?= $h($dom['distance_km']) ?>" placeholder="e.g. 350"></label>
      <label>E-way bill number<?= $needsEway ? ' *' : '' ?><input type="text" name="eway_bill_no" maxlength="12" pattern="[0-9]{12}" value="<?= $h($dom['eway_bill_no']) ?>" placeholder="e.g. 331012345678" <?= $needsEway ? 'required' : '' ?>></label>
      <label>E-way bill date<input type="date" name="eway_bill_date" value="<?= $h($dom['eway_bill_date']) ?>"></label>
      <p><button type="submit" class="btn-secondary">Save dispatch</button></p>
    </form>
    <?php else: ?>
    <p>Dispatched <?= $h($dom['dispatch_date'] ?: '—') ?> · <?= $h($dom['transporter_name'] ?: '—') ?> · vehicle <?= $h($dom['vehicle_no'] ?: '—') ?> · e-way bill <?= $h($dom['eway_bill_no'] ?: '—') ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (D::atOrAfter($stage, 'tax_invoice')): ?>
  <div class="section" id="dom-invoice">
    <h2>Tax invoice</h2>
    <?php if ($dom['invoice_sent_on']): ?><p>Sent to the buyer on <?= $h($dom['invoice_sent_on']) ?>.</p><?php endif; ?>
    <?php if ($canEdit && $stage === 'tax_invoice'): ?>
    <form method="post" action="/domestic/orders/<?= $id ?>/invoice-sent" class="field-grid"><?= Csrf::field() ?>
      <label>Sent to the buyer on *<input type="date" name="invoice_sent_on" required value="<?= $h($dom['invoice_sent_on'] ?: date('Y-m-d')) ?>"></label>
      <p><button type="submit" class="btn-secondary">Mark as sent</button></p>
    </form>
    <?php endif; ?>
    <?php if ($canZoho): ?>
    <form method="post" action="/domestic/orders/<?= $id ?>/zoho"><?= Csrf::field() ?><button type="submit" class="btn-secondary">Send to Zoho Books</button> <span class="muted small">the tax invoice and the buyer's receipts — whatever is waiting</span></form>
    <p class="small"><a href="/domestic/orders/<?= $id ?>/zoho-check">Check this order against Zoho Books &rarr;</a> <span class="muted">(reads only)</span></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($seesPayments && D::atOrAfter($stage, 'order_confirmation')): ?>
  <div class="section" id="dom-payments">
    <h2>Payments</h2>
    <table class="list" data-testid="dom-payments">
      <tr><th>Received</th><th>Amount ₹</th><th>Bank reference</th><th>Description</th></tr>
      <?php foreach ($payments as $p): ?><tr><td><?= $h($p['received_on']) ?></td><td><?= $h(G::inr($p['amount_inr'])) ?></td><td><?= $h($p['bank_reference']) ?></td><td><?= $h($p['description']) ?></td></tr><?php endforeach; ?>
      <?php if ($payments === []): ?><tr><td colspan="4" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
    <p>Received ₹<?= $h(G::inr($paid)) ?><?php if ($dom['invoice_total'] !== null): ?> of ₹<?= $h(G::inr((string) $dom['invoice_total'])) ?><?php endif; ?>.</p>
    <?php if ($canPay && $stage !== 'closed'): ?>
    <form method="post" action="/domestic/orders/<?= $id ?>/payments" class="field-grid">
      <?= Csrf::field() ?>
      <label>Received on *<input type="date" name="received_on" required max="<?= date('Y-m-d') ?>"></label>
      <label>Amount ₹ *<input type="number" name="amount_inr" required min="0.01" step="0.01" placeholder="e.g. 250000.00"></label>
      <label>Bank reference *<input type="text" name="bank_reference" required maxlength="60" placeholder="e.g. UTR SBIN226123456789"></label>
      <label>Note<input type="text" name="note" maxlength="255" placeholder="e.g. part payment, balance next week"></label>
      <p><button type="submit" class="btn-secondary">Record payment</button></p>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="section" id="dom-documents">
    <h2>Documents</h2>
    <?php if ($seesPrices): ?>
    <table class="list" data-testid="dom-documents">
      <tr><th>Document</th><th>Number</th><th>Generated</th><th></th></tr>
      <?php foreach ($documents as $d): ?><tr><td><?= $h(D::DOCUMENTS[$d['doc_code']] ?? $d['doc_code']) ?></td><td><?= $h($d['reference']) ?></td><td><?= $h($d['generated_at']) ?> · <?= $h($d['generated_by_name']) ?></td><td><a href="/file-store/<?= (int) $d['file_store_id'] ?>/download">PDF</a></td></tr><?php endforeach; ?>
      <?php if ($documents === []): ?><tr><td colspan="4" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
    <?php if ($canDocs): ?>
      <?php foreach (D::DOCUMENTS as $code => $label): if (!D::documentAllowed($dom, $code)) { continue; } ?>
      <form method="post" action="/domestic/orders/<?= $id ?>/documents" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="doc_code" value="<?= $h($code) ?>"><button type="submit" class="btn-secondary">Generate <?= $h(lcfirst($label)) ?></button></form>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php else: ?><p class="muted">The documents show prices.</p><?php endif; ?>
  </div>
</div>
