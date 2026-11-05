<?php use App\Helpers\Csrf; use App\Services\ImportService as I;
/* Owner decision D-257 Phase 3: a new import — the foreign supplier and the import PO in the supplier's currency. */
$o = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? '')); ?>
<div class="card page-wide">
  <p><a href="/import">&larr; Imports</a></p>
  <h1>New import</h1>
  <?php if (!$active): ?>
    <p class="muted" data-testid="imp-not-active">Import &amp; Distribution is not active yet. The MD activates it in Admin › Divisions when it is ready.</p>
  <?php else: ?>
  <p class="muted">A purchase from a supplier abroad, in the supplier's currency. The PO number is given when you save.</p>
  <form method="post" action="/import" class="field-grid" data-testid="imp-create-form">
    <?= Csrf::field() ?>
    <label>Supplier *<input type="text" name="supplier_name" required maxlength="255" value="<?= $o('supplier_name') ?>" placeholder="e.g. Xiamen Stone Industries Co., Ltd."></label>
    <label>Country *<input type="text" name="supplier_country" required maxlength="80" value="<?= $o('supplier_country') ?>" placeholder="e.g. China" pattern="(?!\s*[Ii][Nn][Dd][Ii][Aa]\s*$).*" data-pattern-message="An import comes from a supplier abroad: the country cannot be India."></label>
    <label>Supplier address<textarea name="supplier_address" rows="3" maxlength="1000" placeholder="e.g. No. 8 Shuitou Road, Nan'an, Fujian 362342"><?= $o('supplier_address') ?></textarea></label>
    <label>Currency *
      <select name="currency_code" required>
        <option value="">Choose…</option>
        <?php foreach (I::CURRENCIES as $c): ?><option value="<?= $c ?>" <?= ($old['currency_code'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Incoterm
      <select name="incoterm">
        <option value="">—</option>
        <?php foreach (['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'] as $t): ?><option value="<?= $t ?>" <?= ($old['incoterm'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>PO date *<input type="date" name="po_date" required max="<?= date('Y-m-d') ?>" value="<?= $o('po_date') ?: date('Y-m-d') ?>"></label>
    <label>Payment terms<input type="text" name="payment_terms" maxlength="255" value="<?= $o('payment_terms') ?>" placeholder="e.g. 30% advance by TT, 70% by LC at sight"></label>
    <p><button type="submit" class="btn">Create import</button></p>
  </form>
  <?php endif; ?>
</div>
