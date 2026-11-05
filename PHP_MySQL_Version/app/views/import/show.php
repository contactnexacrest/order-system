<?php use App\Helpers\Csrf; use App\Services\ImportService as I; use App\Services\GstCalculator as G;
/* Owner decision D-257 Phase 3: one import — stages, import PO and lines, LC (NexaCrest as applicant), BL, customs clearance and duty, landed cost, stock, supplier payments. */
$id = (int) $s['id'];
$stage = (string) $s['stage'];
$h = static fn ($v): string => htmlspecialchars((string) $v);
$keys = array_keys(I::STAGES);
$pos = (int) array_search($stage, $keys, true);
$next = $stage !== 'closed' ? $keys[$pos + 1] : null;
$cur = (string) $s['currency_code'];
$frozen = $s['landed_total'] !== null;
$num = static fn (string $v): string => str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v; ?>
<div class="card page-wide" data-testid="imp-show">
  <p><a href="/import">&larr; Imports</a></p>
  <h1><?= $h($s['reference']) ?> <span class="badge"><?= $h(I::STAGES[$stage]) ?></span></h1>
  <p class="muted"><?= $h($s['supplier_name']) ?> · <?= $h($s['supplier_country']) ?> · in <?= $h($cur) ?><?= $s['incoterm'] ? ' · ' . $h($s['incoterm']) : '' ?></p>

  <ol class="stage-strip" data-testid="imp-stages" style="display:flex;flex-wrap:wrap;gap:6px 18px;padding-left:18px;">
    <?php foreach (I::STAGES as $k => $label): $i = (int) array_search($k, $keys, true); ?>
      <li<?= $k === $stage ? ' style="font-weight:700"' : ($i < $pos ? ' style="color:#2D7D56"' : ' style="color:#8A94A6"') ?>><?= $h($label) ?></li>
    <?php endforeach; ?>
  </ol>

  <?php if ($next !== null): ?>
  <div class="section" data-testid="imp-next">
    <?php if ($blockers !== []): ?>
      <p><strong>Before moving on to <?= $h(I::STAGES[$next]) ?>:</strong></p>
      <ul><?php foreach ($blockers as $b): ?><li><?= $h($b) ?></li><?php endforeach; ?></ul>
    <?php elseif ($canEdit): ?>
      <form method="post" action="/import/<?= $id ?>/advance"><?= Csrf::field() ?><input type="hidden" name="from_stage" value="<?= $h($stage) ?>">
        <button type="submit" class="btn">Move on to <?= $h(I::STAGES[$next]) ?></button>
        <?php if ($next === 'cleared'): ?><small class="muted">This works out the duty and the landed cost per item and freezes them.</small><?php endif; ?>
        <?php if ($next === 'received'): ?><small class="muted">This puts the goods into stock at their landed cost.</small><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="section" id="imp-po">
    <h2>Import PO</h2>
    <?php if ($canEdit && $stage === 'ordered'): ?>
    <form method="post" action="/import/<?= $id ?>/po" class="field-grid">
      <?= Csrf::field() ?>
      <label>Supplier *<input type="text" name="supplier_name" required maxlength="255" value="<?= $h($s['supplier_name']) ?>" placeholder="e.g. Xiamen Stone Industries Co., Ltd."></label>
      <label>Country *<input type="text" name="supplier_country" required maxlength="80" value="<?= $h($s['supplier_country']) ?>" placeholder="e.g. China" pattern="(?!\s*[Ii][Nn][Dd][Ii][Aa]\s*$).*" data-pattern-message="An import comes from a supplier abroad: the country cannot be India."></label>
      <label>Supplier address<textarea name="supplier_address" rows="2" maxlength="1000" placeholder="e.g. No. 8 Shuitou Road, Nan'an, Fujian 362342"><?= $h($s['supplier_address']) ?></textarea></label>
      <label>Currency *<select name="currency_code" required><option value="">Choose…</option><?php foreach (I::CURRENCIES as $c): ?><option value="<?= $c ?>" <?= $cur === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></label>
      <label>Incoterm<select name="incoterm"><option value="">—</option><?php foreach (['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'] as $t): ?><option value="<?= $t ?>" <?= $s['incoterm'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
      <label>PO date *<input type="date" name="po_date" required max="<?= date('Y-m-d') ?>" value="<?= $h($s['po_date']) ?>"></label>
      <label>Payment terms<input type="text" name="payment_terms" maxlength="255" value="<?= $h($s['payment_terms']) ?>" placeholder="e.g. 30% advance by TT, 70% by LC at sight"></label>
      <p><button type="submit" class="btn-secondary">Save</button></p>
    </form>
    <?php else: ?>
    <p>PO date <?= $h($s['po_date']) ?> · Payment <?= $h($s['payment_terms'] ?: '—') ?></p>
    <?php endif; ?>
    <form method="post" action="/import/<?= $id ?>/po-document" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-secondary">Download import PO (PDF)</button></form>
    <span class="muted"><?= $s['ipo_generated_at'] ? 'Generated ' . $h($s['ipo_generated_at']) . '.' : 'Not generated yet.' ?></span>
  </div>

  <div class="section" id="imp-lines">
    <h2>Lines and landed cost</h2>
    <table class="list" data-testid="imp-lines">
      <tr><th>#</th><th>Product</th><th>HSN</th><th>Qty</th><th>Unit</th><th>Price <?= $h($cur) ?></th><th>Amount <?= $h($cur) ?></th><th>Duty %</th>
        <th>Assessable ₹</th><th>Duty ₹</th><th>IGST ₹</th><th>Landed ₹</th><th>Per unit ₹</th><th>Suggested price ₹</th><th></th></tr>
      <?php foreach ($figures['lines'] as $i => $l): ?>
      <tr>
        <td><?= $i + 1 ?></td><td><?= $h($l['product_name']) ?><br><small class="muted"><?= $h($l['description']) ?></small></td><td><?= $h($l['hsn_code']) ?></td>
        <td><?= $h($num((string) $l['quantity'])) ?></td><td><?= $h($l['unit']) ?></td><td><?= $h(I::fc(G::scaled((string) $l['unit_price_fc'], 2))) ?></td><td><?= $h(I::fc(G::scaled($l['value_fc'], 2))) ?></td>
        <td><small>BCD <?= $h($num((string) $l['bcd_pct'])) ?> · SWS <?= $h($num((string) $l['sws_pct'])) ?> · IGST <?= $h($num((string) $l['igst_pct'])) ?></small></td>
        <?php if ($s['customs_fx_rate'] !== null): ?>
        <td><?= $h(G::inr($l['assessable_inr'])) ?></td><td><?= $h(G::inr(G::money(G::scaled($l['bcd_inr'], 2) + G::scaled($l['sws_inr'], 2)))) ?></td><td><?= $h(G::inr($l['igst_inr'])) ?></td>
        <td><?= $h(G::inr($l['landed_inr'])) ?></td><td><?= $h($l['landed_unit_inr']) ?></td><td><?= $h(G::inr(I::suggestedPrice($l['landed_unit_inr'], $margin))) ?></td>
        <?php else: ?><td colspan="6" class="muted">After customs clearance</td><?php endif; ?>
        <td><?php if ($canEdit && $stage === 'ordered'): ?><form method="post" action="/import/<?= $id ?>/lines/<?= (int) $l['id'] ?>/delete" data-confirm="Remove this line?"><?= Csrf::field() ?><button type="submit" class="btn-link">Remove</button></form><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if ($figures['lines'] === []): ?><tr><td colspan="15" class="muted">No lines yet.</td></tr><?php endif; ?>
    </table>
    <p data-testid="imp-totals">PO value <?= $h($cur) ?> <?= $h(I::fc(G::scaled($figures['value_total_fc'], 2))) ?>
      <?php if ($s['customs_fx_rate'] !== null): ?> · at ₹<?= $h($s['customs_fx_rate']) ?> = ₹<?= $h(G::inr($figures['value_total_inr'])) ?> · assessable ₹<?= $h(G::inr($figures['assessable_total'])) ?>
      · basic duty ₹<?= $h(G::inr($figures['bcd_total'])) ?> · SWS ₹<?= $h(G::inr($figures['sws_total'])) ?> · IGST ₹<?= $h(G::inr($figures['igst_total'])) ?> (input credit, not a cost)
      · charges ₹<?= $h(G::inr($figures['charges_total'])) ?> · <strong>landed cost ₹<?= $h(G::inr($figures['landed_total'])) ?></strong><?= $frozen ? ' (frozen at clearance)' : ' (estimate until cleared)' ?><?php endif; ?></p>
    <p class="muted">Suggested selling price = landed cost per unit ÷ (1 − <?= $h($margin) ?>% margin), before GST (Import &amp; Distribution setting <code>imp_margin_pct</code>).</p>
    <?php if ($canEdit && $stage === 'ordered'): ?>
    <form method="post" action="/import/<?= $id ?>/lines" class="field-grid" data-testid="imp-add-line">
      <?= Csrf::field() ?>
      <label>Product *<select name="product_id" required><option value="">Choose…</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= $h($p['name'] . ' (' . $p['product_code'] . ')') ?></option><?php endforeach; ?></select></label>
      <label>Description<input type="text" name="description" maxlength="500" placeholder="blank = the product's name"></label>
      <label>HSN<input type="text" name="hsn_code" minlength="4" maxlength="8" pattern="[0-9]{4,8}" placeholder="blank = the product's HS code"></label>
      <label>Quantity *<input type="number" name="quantity" required min="0.001" step="0.001" placeholder="e.g. 500"></label>
      <label>Unit *<input type="text" name="unit" required maxlength="20" placeholder="e.g. SQM"></label>
      <label>Unit price <?= $h($cur) ?> *<input type="number" name="unit_price_fc" required min="0.01" step="0.01" placeholder="e.g. 18.50"></label>
      <label>Basic duty %<input type="number" name="bcd_pct" min="0" max="150" step="0.01" placeholder="blank = from the duty rates"></label>
      <label>SWS %<input type="number" name="sws_pct" min="0" max="150" step="0.01" placeholder="blank = from the duty rates"></label>
      <label>IGST %<input type="number" name="igst_pct" min="0" max="150" step="0.01" placeholder="blank = from the duty rates"></label>
      <p><button type="submit" class="btn-secondary">Add line</button></p>
    </form>
    <?php endif; ?>
  </div>

  <div class="section" id="imp-lc">
    <h2>Letter of credit (NexaCrest as applicant)</h2>
    <?php if ($s['lc_no']): ?><p>LC <?= $h($s['lc_no']) ?> · <?= $h($s['lc_bank']) ?> · <?= $h($cur) ?> <?= $h(I::fc(G::scaled((string) $s['lc_amount_fc'], 2))) ?> · expires <?= $h($s['lc_expiry']) ?><?= $s['lc_margin_inr'] !== null ? ' · margin money ₹' . $h(G::inr((string) $s['lc_margin_inr'])) : '' ?></p><?php else: ?><p class="muted">No LC recorded (not every import uses one).</p><?php endif; ?>
    <?php if ($canEdit && $stage !== 'closed'): ?>
    <form method="post" action="/import/<?= $id ?>/lc" class="field-grid">
      <?= Csrf::field() ?>
      <label>LC number *<input type="text" name="lc_no" required maxlength="40" value="<?= $h($s['lc_no']) ?>" placeholder="e.g. 0123ILC000456"></label>
      <label>Opening bank *<input type="text" name="lc_bank" required maxlength="150" value="<?= $h($s['lc_bank'] ?: I::setting('imp_lc_bank')) ?>" placeholder="e.g. State Bank of India, Bengaluru"></label>
      <label>Amount <?= $h($cur) ?> *<input type="number" name="lc_amount_fc" required min="0.01" step="0.01" value="<?= $h($s['lc_amount_fc']) ?>" placeholder="e.g. 9250.00"></label>
      <label>Expiry *<input type="date" name="lc_expiry" required value="<?= $h($s['lc_expiry']) ?>"></label>
      <label>Margin money ₹<input type="number" name="lc_margin_inr" min="0" step="0.01" value="<?= $h($s['lc_margin_inr']) ?>" placeholder="e.g. 77000.00"></label>
      <p><button type="submit" class="btn-secondary">Save LC</button></p>
    </form>
    <?php endif; ?>
  </div>

  <div class="section" id="imp-shipment">
    <h2>Shipment</h2>
    <?php if ($canEdit && in_array($stage, ['ordered', 'shipped'], true)): ?>
    <form method="post" action="/import/<?= $id ?>/bl" class="field-grid">
      <?= Csrf::field() ?>
      <label>BL number *<input type="text" name="bl_no" required maxlength="40" value="<?= $h($s['bl_no']) ?>" placeholder="e.g. COSU6234567890"></label>
      <label>BL date *<input type="date" name="bl_date" required min="<?= $h($s['po_date']) ?>" value="<?= $h($s['bl_date']) ?>"></label>
      <label>Vessel<input type="text" name="vessel" maxlength="100" value="<?= $h($s['vessel']) ?>" placeholder="e.g. COSCO Shipping Leo / 041W"></label>
      <label>Port of loading<input type="text" name="port_of_loading" maxlength="100" value="<?= $h($s['port_of_loading']) ?>" placeholder="e.g. Xiamen, China"></label>
      <p><button type="submit" class="btn-secondary">Save BL</button></p>
    </form>
    <?php else: ?><p>BL <?= $h($s['bl_no'] ?: '—') ?> dated <?= $h($s['bl_date'] ?: '—') ?> · <?= $h($s['vessel'] ?: '—') ?> · from <?= $h($s['port_of_loading'] ?: '—') ?></p><?php endif; ?>
  </div>

  <?php if (in_array($stage, ['shipped', 'cleared', 'received', 'closed'], true)): ?>
  <div class="section" id="imp-clearance">
    <h2>Customs clearance</h2>
    <?php if ($canEdit && $stage === 'shipped'): ?>
    <form method="post" action="/import/<?= $id ?>/clearance" class="field-grid">
      <?= Csrf::field() ?>
      <label>Bill of entry number *<input type="text" name="boe_no" required minlength="6" maxlength="8" pattern="[0-9]{6,8}" value="<?= $h($s['boe_no']) ?>" placeholder="e.g. 4567891"></label>
      <label>Bill of entry date *<input type="date" name="boe_date" required min="<?= $h($s['bl_date']) ?>" value="<?= $h($s['boe_date']) ?>"></label>
      <label>Port code *<input type="text" name="port_code" required maxlength="6" pattern="[Ii][Nn][A-Za-z0-9]{4}" value="<?= $h($s['port_code']) ?>" placeholder="e.g. INNSA1"></label>
      <label>Customs agent (CHA) *<input type="text" name="cha_name" required maxlength="150" value="<?= $h($s['cha_name'] ?: I::setting('imp_default_cha')) ?>" placeholder="e.g. Speedways Clearing Agency"></label>
      <label>Customs exchange rate (₹ per <?= $h($cur) ?>) *<input type="number" name="customs_fx_rate" required min="0.0001" max="999.9999" step="0.0001" value="<?= $h($s['customs_fx_rate']) ?>" placeholder="e.g. 84.6500"></label>
      <label>Clearance date *<input type="date" name="clearance_date" required max="<?= date('Y-m-d') ?>" value="<?= $h($s['clearance_date']) ?>" data-not-before="[name=boe_date]" data-not-before-message="The goods clear on or after the bill of entry date."></label>
      <label>Freight ₹<input type="number" name="freight_inr" min="0" step="0.01" value="<?= $h($s['freight_inr']) ?>" placeholder="e.g. 85000.00"></label>
      <label>Insurance ₹<input type="number" name="insurance_inr" min="0" step="0.01" value="<?= $h($s['insurance_inr']) ?>" placeholder="e.g. 4200.00"></label>
      <label>CHA charges ₹<input type="number" name="cha_inr" min="0" step="0.01" value="<?= $h($s['cha_inr']) ?>" placeholder="e.g. 18000.00"></label>
      <label>Port and terminal charges ₹<input type="number" name="port_inr" min="0" step="0.01" value="<?= $h($s['port_inr']) ?>" placeholder="e.g. 22000.00"></label>
      <label>Other charges ₹<input type="number" name="other_inr" min="0" step="0.01" value="<?= $h($s['other_inr']) ?>" placeholder="e.g. 6500.00"></label>
      <p><button type="submit" class="btn-secondary">Save clearance</button></p>
    </form>
    <p class="muted">Freight and insurance make the assessable value with the goods; basic duty is on the assessable value, SWS on the duty, IGST on all three. Duty rates: Admin › Import duty rates (to be confirmed by the CA and the customs agent).</p>
    <?php else: ?>
    <p>Bill of entry <?= $h($s['boe_no'] ?: '—') ?> dated <?= $h($s['boe_date'] ?: '—') ?> at <?= $h($s['port_code'] ?: '—') ?> · CHA <?= $h($s['cha_name'] ?: '—') ?> · rate ₹<?= $h($s['customs_fx_rate'] ?: '—') ?> · cleared <?= $h($s['clearance_date'] ?: '—') ?><?= $s['received_on'] ? ' · in stock ' . $h($s['received_on']) : '' ?></p>
    <?php endif; ?>
    <?php if (in_array($stage, ['cleared', 'received', 'closed'], true)): ?>
      <p data-testid="imp-igst">IGST paid on import ₹<?= $h(G::inr((string) $s['igst_total'])) ?>: <?= $s['igst_credit_on'] ? 'taken as input credit on ' . $h($s['igst_credit_on']) . '.' : 'input credit not taken yet.' ?></p>
      <?php if ($canEdit && in_array($stage, ['cleared', 'received'], true) && !$s['igst_credit_on']): ?>
      <form method="post" action="/import/<?= $id ?>/igst-credit" class="field-grid"><?= Csrf::field() ?>
        <label>IGST credit taken on *<input type="date" name="igst_credit_on" required min="<?= $h($s['boe_date']) ?>" max="<?= date('Y-m-d') ?>"></label>
        <p><button type="submit" class="btn-secondary">Record IGST credit</button></p>
      </form>
      <?php endif; ?>
      <p data-testid="imp-duty-paid">Customs duty + IGST ₹<?= $h(G::inr((string) round((float) $s['bcd_total'] + (float) $s['sws_total'] + (float) $s['igst_total'], 2))) ?>: <?= $s['duty_paid_on'] ? 'paid on ' . $h($s['duty_paid_on']) . ', challan / bank ref ' . $h($s['duty_bank_reference']) . '.' : 'payment not recorded yet.' ?></p>
      <?php if ($canPay && !$s['duty_zoho_payment_id']): ?>
      <form method="post" action="/import/<?= $id ?>/duty-payment" class="field-grid"><?= Csrf::field() ?>
        <label>Duty paid on *<input type="date" name="duty_paid_on" required min="<?= $h($s['boe_date']) ?>" max="<?= date('Y-m-d') ?>" value="<?= $h($s['duty_paid_on']) ?>"></label>
        <label>Challan / bank reference *<input type="text" name="duty_bank_reference" required maxlength="60" value="<?= $h($s['duty_bank_reference']) ?>" placeholder="e.g. ICEGATE CIN 2611050012345"></label>
        <p><button type="submit" class="btn-secondary"><?= $s['duty_paid_on'] ? 'Save duty payment' : 'Record duty payment' ?></button></p>
      </form>
      <?php endif; ?>
      <?php if ($canZoho): ?>
      <form method="post" action="/import/<?= $id ?>/zoho"><?= Csrf::field() ?><button type="submit" class="btn-secondary">Send to Zoho Books</button> <span class="muted small">the bills, the payments to the supplier and to Customs, and the IGST credit journal — whatever is waiting</span></form>
      <p class="small"><a href="/import/<?= $id ?>/zoho-check">Check this import against Zoho Books &rarr;</a> <span class="muted">(reads only)</span></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="section" id="imp-payments">
    <h2>Payments to the supplier</h2>
    <table class="list" data-testid="imp-payments">
      <tr><th>Paid</th><th>What</th><th>Amount</th><th>Rate</th><th>₹</th><th>Bank reference</th><th>Description</th></tr>
      <?php foreach ($payments as $p): ?><tr><td><?= $h($p['paid_on']) ?></td><td><?= $h(I::PAYMENT_KINDS[$p['kind']] ?? $p['kind']) ?></td><td><?= $h($cur) ?> <?= $h(I::fc(G::scaled((string) $p['amount_fc'], 2))) ?></td><td><?= $h($p['fx_rate']) ?></td><td><?= $h(G::inr((string) $p['amount_inr'])) ?></td><td><?= $h($p['bank_reference']) ?></td><td><?= $h($p['description']) ?></td></tr><?php endforeach; ?>
      <?php if ($payments === []): ?><tr><td colspan="7" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
    <p>Paid <?= $h($cur) ?> <?= $h(I::fc($paidCents)) ?> of <?= $h(I::fc($poCents)) ?>.</p>
    <?php if ($canPay && $stage !== 'closed'): ?>
    <form method="post" action="/import/<?= $id ?>/payments" class="field-grid">
      <?= Csrf::field() ?>
      <label>What *<select name="kind" required><option value="">Choose…</option><?php foreach (I::PAYMENT_KINDS as $k => $label): ?><option value="<?= $h($k) ?>"><?= $h($label) ?></option><?php endforeach; ?></select></label>
      <label>Paid on *<input type="date" name="paid_on" required max="<?= date('Y-m-d') ?>"></label>
      <label>Amount <?= $h($cur) ?> *<input type="number" name="amount_fc" required min="0.01" step="0.01" placeholder="e.g. 2775.00"></label>
      <label>Bank's rate (₹ per <?= $h($cur) ?>) *<input type="number" name="fx_rate" required min="0.0001" max="999.9999" step="0.0001" placeholder="e.g. 84.9200"></label>
      <label>SWIFT / UTR reference *<input type="text" name="bank_reference" required maxlength="60" placeholder="e.g. SBININBB123 OUTWARD 556677"></label>
      <label>Note<input type="text" name="note" maxlength="255" placeholder="e.g. 30% advance against proforma"></label>
      <p><button type="submit" class="btn-secondary">Record payment</button></p>
    </form>
    <?php endif; ?>
  </div>
</div>
