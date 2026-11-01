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
      <label>Name *<input type="text" name="name" required maxlength="150" value="<?= $__v('name') ?>"></label>
      <label>Based in *<select name="based_in" required><option value="india"<?= ($__a['based_in'] ?? 'india') === 'india' ? ' selected' : '' ?>>India</option><option value="abroad"<?= ($__a['based_in'] ?? '') === 'abroad' ? ' selected' : '' ?>>Abroad</option></select></label>
      <label>Country<input type="text" name="country" maxlength="80" value="<?= $__v('country') ?>"></label>
      <label>Paid in (currency) *<select name="currency_code" required>
        <?php foreach ($currencies as $cur): $__code = (string) ($cur['code'] ?? $cur['currency_code'] ?? ''); ?>
          <option value="<?= htmlspecialchars($__code) ?>"<?= ($__a['currency_code'] ?? 'INR') === $__code ? ' selected' : '' ?>><?= htmlspecialchars($__code) ?></option>
        <?php endforeach; ?>
      </select></label>
      <label>E-mail (portal login)<input type="email" name="email" maxlength="190" value="<?= $__v('email') ?>"></label>
      <label>Phone<input type="tel" name="phone" maxlength="40" value="<?= $__v('phone') ?>"></label>
      <label>PAN (India)<input type="text" name="pan" maxlength="10" pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]" title="As on the PAN card, e.g. ABCDE1234F" value="<?= $__v('pan') ?>"></label>
      <label>GSTIN<input type="text" name="gstin" maxlength="15" pattern="[0-9]{2}[A-Za-z0-9]{13}" title="15 characters" value="<?= $__v('gstin') ?>"></label>
      <label>Tax ID (abroad)<input type="text" name="tax_id" maxlength="50" value="<?= $__v('tax_id') ?>"></label>
      <label>Relationship manager<select name="relationship_manager_id"><option value="">— none —</option>
        <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) ($__a['relationship_manager_id'] ?? 0) === (int) $u['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $u['name']) ?></option><?php endforeach; ?>
      </select></label>
      <label style="grid-column:1/-1">Address<textarea name="address" maxlength="1000" rows="2"><?= $__v('address') ?></textarea></label>
      <label>Agreement signed on<input type="date" name="agreement_signed_on" max="<?= date('Y-m-d') ?>" value="<?= $__v('agreement_signed_on') ?>"<?= !empty($__a['agreement_file_id']) ? ' required' : ' data-required-if="agreement_file" data-required-if-message="Enter the date the agreement was signed."' ?>></label>
      <label>Agreement ends on<input type="date" name="agreement_ends_on" value="<?= $__v('agreement_ends_on') ?>"></label>
      <label>Signed agreement (PDF)<?= !empty($__a['agreement_file_id']) ? ' &middot; <a href="/associates/' . (int) $__a['id'] . '/agreement">copy on file</a>, choose a file only to replace it' : '' ?><input type="file" name="agreement_file" accept=".pdf,application/pdf"<?= !empty($__a['agreement_file_id']) ? '' : ' data-required-if="agreement_signed_on" data-required-if-message="Attach the signed agreement (PDF) with its signing date."' ?>></label>
    </div>
    <fieldset><legend>Commission (% of the product FOB value of the client's first orders, paid once the buyer has paid in full)</legend>
      <p class="muted small">Leave a rung empty (or 0) to use the standard rate set in <a href="/associates/ladder">Standard rates</a>. A personal rate applies to orders created after it is saved; each order keeps the rate fixed on it when it was created.</p>
      <div class="field-grid">
        <?php foreach (['rate_1_5' => 'Clients 1–5', 'rate_6_10' => 'Clients 6–10', 'rate_11_20' => 'Clients 11–20', 'rate_21_40' => 'Clients 21–40', 'rate_41_plus' => 'Client 41 onward'] as $f => $label): ?>
          <label><?= $label ?> <small class="muted">(standard <?= $__pct($ladder[$f]) ?>)</small><input type="number" name="<?= $f ?>" min="0" max="20" step="0.01" placeholder="standard" value="<?= isset($__a[$f]) && $__a[$f] !== null && (float) $__a[$f] > 0 ? $__v($f) : '' ?>"></label>
        <?php endforeach; ?>
      </div>
      <label class="checkbox"><input type="checkbox" name="no_commission" value="1"<?= (int) ($__a['no_commission'] ?? 0) === 1 ? ' checked' : '' ?>> No commission for this associate (introductions only)</label>
    </fieldset>
    <label>Notes<input type="text" name="notes" maxlength="500" value="<?= $__v('notes') ?>"></label>
    <div class="btn-row"><button type="submit"><?= $editing ? 'Save' : 'Add associate' ?></button> <a href="<?= $editing ? '/associates/' . (int) $editing['id'] : '/associates' ?>">Cancel</a></div>
  </form>
</div>
