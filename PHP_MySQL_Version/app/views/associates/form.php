<?php
use App\Helpers\Csrf;

$__a = $editing ?? [];
$__v = static fn (string $k, $default = '') => htmlspecialchars((string) ($__a[$k] ?? $default));
$__pct = static fn ($r): string => rtrim(rtrim(number_format((float) $r, 2), '0'), '.') . '%';
?>
<div class="card" id="associate-form">
  <h1><?= $editing ? 'Edit ' . $__v('name') : 'Add an associate' ?></h1>
  <p class="muted">The associate's e-mail is their portal login. A new associate starts as <b>Invited</b>; they become <b>Active</b> once Accounts have verified their bank details.</p>
  <form method="post" action="<?= $editing ? '/associates/' . (int) $editing['id'] . '/update' : '/associates' ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <div class="field-grid">
      <label>Name *<input type="text" name="name" required maxlength="150" value="<?= $__v('name') ?>" placeholder="e.g. Full name as on PAN card"></label>
      <label>Based in *<select name="based_in" required><option value="india"<?= ($__a['based_in'] ?? 'india') === 'india' ? ' selected' : '' ?>>India</option><option value="abroad"<?= ($__a['based_in'] ?? '') === 'abroad' ? ' selected' : '' ?>>Abroad</option></select></label>
      <label>Country<input type="text" name="country" maxlength="80" value="<?= $__v('country') ?>" placeholder="e.g. Netherlands"></label>
      <?php // D-264: India → always INR (no conversion, TDS in rupees); abroad → an active trading currency, USD by default. ?>
      <label data-show-when="based_in=india">Paid in (currency) *<select name="currency_code" required><option value="INR" selected>INR — Indian rupee</option></select><span class="help-line">Fixed for associates based in India: always INR, by NEFT / RTGS / IMPS to the verified account. No currency conversion, TDS in rupees.</span></label>
      <label data-show-when="based_in=abroad">Paid in (currency) *<select name="currency_code" required><option value="">Choose…</option>
        <?php $__cur = ($__a['based_in'] ?? 'india') === 'abroad' && ($__a['currency_code'] ?? '') !== 'INR' ? (string) $__a['currency_code'] : 'USD'; ?>
        <?php foreach ($currencies as $cur): $__code = (string) ($cur['code'] ?? $cur['currency_code'] ?? ''); if ($__code === '' || $__code === 'INR') { continue; } ?>
          <option value="<?= htmlspecialchars($__code) ?>"<?= $__cur === $__code ? ' selected' : '' ?>><?= htmlspecialchars($__code) ?></option>
        <?php endforeach; ?>
      </select><span class="help-line">Abroad: paid in this currency by SWIFT — match the associate's bank account. The amount is fixed in this currency when the buyer completes payment.</span></label>
      <label>E-mail (portal login)<input type="email" name="email" maxlength="190" value="<?= $__v('email') ?>" placeholder="e.g. name@company.com"></label>
      <label>Phone<input type="tel" name="phone" maxlength="40" value="<?= $__v('phone') ?>" placeholder="e.g. +91 98765 43210"></label>
      <label>PAN (India)<input type="text" name="pan" maxlength="10" pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]" title="As on the PAN card, e.g. ABCDE1234F" value="<?= $__v('pan') ?>" placeholder="e.g. ABCDE1234F"></label>
      <label>GSTIN<input type="text" name="gstin" maxlength="15" pattern="[0-9]{2}[A-Za-z0-9]{13}" title="15 characters" value="<?= $__v('gstin') ?>" placeholder="e.g. 29ABCDE1234F1Z5"></label>
      <label>Tax ID (abroad)<input type="text" name="tax_id" maxlength="50" value="<?= $__v('tax_id') ?>" placeholder="e.g. NL123456789B01"></label>
      <label>Reports to <small class="muted">(their sales manager — for information)</small><select name="relationship_manager_id"><option value="">— none —</option>
        <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) ($__a['relationship_manager_id'] ?? 0) === (int) $u['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $u['name']) ?></option><?php endforeach; ?>
      </select></label>
      <label style="grid-column:1/-1">Address<textarea name="address" maxlength="1000" rows="2" placeholder="e.g. 12 MG Road, Bengaluru 560001"><?= $__v('address') ?></textarea></label>
      <label>Agreement signed on<input type="date" name="agreement_signed_on" max="<?= date('Y-m-d') ?>" value="<?= $__v('agreement_signed_on') ?>"<?= !empty($__a['agreement_file_id']) ? ' required' : ' data-required-if="agreement_file" data-required-if-message="Enter the date the agreement was signed."' ?>></label>
      <label>Agreement ends on<input type="date" name="agreement_ends_on" value="<?= $__v('agreement_ends_on') ?>"></label>
      <label>Signed agreement (PDF)<?= !empty($__a['agreement_file_id']) ? ' &middot; <a href="/associates/' . (int) $__a['id'] . '/agreement">copy on file</a>, choose a file only to replace it' : '' ?><input type="file" name="agreement_file" accept=".pdf,application/pdf"<?= !empty($__a['agreement_file_id']) ? '' : ' data-required-if="agreement_signed_on" data-required-if-message="Attach the signed agreement (PDF) with its signing date."' ?>></label>
    </div>
    <fieldset><legend>Commission (on the invoiced FOB of the goods of the client's first orders, paid once the buyer has paid in full)</legend>
      <p class="muted small">D-245: the rate comes from the commission level below — a fixed level, or the ladder. When commission is paid, GST and the registration period: <a href="/associates/ladder">Commission settings</a>.</p>
      <label class="checkbox"><input type="checkbox" name="no_commission" value="1"<?= (int) ($__a['no_commission'] ?? 0) === 1 ? ' checked' : '' ?>> No commission for this associate (introductions only)</label>
    </fieldset>
    <?php // Owner decision D-245 (4 Nov 2026): a fixed commission level or the ladder (Admin → Pricing → Commission levels). Used for commission from phase P3. ?>
    <fieldset><legend>Commission level (D-245)</legend>
      <?php $__mode = (string) ($__a['commission_mode'] ?? 'ladder'); $__lid = (string) ($__a['commission_level_id'] ?? ''); ?>
      <?php if (!empty($canPricing)): ?>
        <div class="field-grid">
          <label>How the commission is set *
            <select name="commission_mode" required>
              <option value="ladder"<?= $__mode === 'ladder' ? ' selected' : '' ?>>Ladder (moves up with counting orders)</option>
              <option value="fixed"<?= $__mode === 'fixed' ? ' selected' : '' ?>>Fixed level (stays until you change it)</option>
            </select></label>
          <label>Level (for a fixed level)
            <select name="commission_level_id" data-required-if="commission_mode=fixed" data-required-if-message="Choose the commission level.">
              <option value="">Choose…</option>
              <?php foreach ($commissionLevels as $__l): if ((int) $__l['is_active'] !== 1 && (string) $__l['id'] !== $__lid) { continue; } ?>
                <option value="<?= (int) $__l['id'] ?>"<?= (string) $__l['id'] === $__lid ? ' selected' : '' ?>><?= htmlspecialchars($__l['name'] . ' — ' . \App\Services\PricingService::levelLabel($__l)) ?><?= (int) $__l['is_active'] === 1 ? '' : ' (inactive)' ?></option>
              <?php endforeach; ?>
            </select></label>
        </div>
        <p class="muted small">Commission is built into this associate's price. A change applies to new quotes only. <a href="/pricing/commission-levels">Commission levels and ladder</a></p>
      <?php else: ?>
        <p class="muted small">Set by the MD: <?= $__mode === 'fixed' ? 'fixed level' : 'ladder' ?>.</p>
      <?php endif; ?>
    </fieldset>
    <label>Notes<input type="text" name="notes" maxlength="500" value="<?= $__v('notes') ?>" placeholder="Optional notes"></label>
    <div class="btn-row"><button type="submit"><?= $editing ? 'Save' : 'Add associate' ?></button> <a href="<?= $editing ? '/associates/' . (int) $editing['id'] : '/associates' ?>">Cancel</a></div>
  </form>
</div>
