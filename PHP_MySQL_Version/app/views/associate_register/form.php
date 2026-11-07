<?php
use App\Helpers\Csrf;

$__v = static fn (string $f): string => htmlspecialchars((string) ($old[$f] ?? ''));
$__err = static fn (string $f): string => isset($errors[$f]) ? '<small class="form-field-error">' . htmlspecialchars($errors[$f]) . '</small>' : '';
?>
<div class="card card-narrow">
  <h1>Register as a NexaCrest associate</h1>
  <p class="muted">A few details to start. Once NexaCrest accepts your registration you get your own portal, where you add the rest (address, PAN, bank details and documents).</p>
  <?php if (!empty($errors['form'])): ?><div class="alert alert-error"><?= htmlspecialchars($errors['form']) ?></div><?php endif; ?>
  <form method="post" action="/associate-register/<?= htmlspecialchars($token) ?>">
    <?= Csrf::field() ?>
    <label>Full name *<input type="text" name="name" required minlength="2" maxlength="150" value="<?= $__v('name') ?>" placeholder="e.g. Full name as on PAN card" autocomplete="name"><?= $__err('name') ?></label>
    <label>E-mail * <small class="muted">(your portal login)</small><input type="email" name="email" required maxlength="190" value="<?= $__v('email') ?>" placeholder="e.g. name@email.com" autocomplete="email"><?= $__err('email') ?></label>
    <label>Phone *<input type="tel" name="phone" required minlength="6" maxlength="40" pattern="[0-9 +\(\)\-]{6,40}" value="<?= $__v('phone') ?>" placeholder="e.g. +91 98765 43210" autocomplete="tel"><?= $__err('phone') ?></label>
    <label>Based in *
      <select name="based_in" required>
        <option value="">Choose…</option>
        <option value="india"<?= ($old['based_in'] ?? '') === 'india' ? ' selected' : '' ?>>India</option>
        <option value="abroad"<?= ($old['based_in'] ?? '') === 'abroad' ? ' selected' : '' ?>>Outside India</option>
      </select><?= $__err('based_in') ?></label>
    <label>Country *<input type="text" name="country" required minlength="2" maxlength="80" value="<?= $__v('country') ?>" placeholder="e.g. India" autocomplete="country-name"><?= $__err('country') ?></label>
    <label>City *<input type="text" name="city" required minlength="2" maxlength="100" value="<?= $__v('city') ?>" placeholder="e.g. Bengaluru" autocomplete="address-level2"><?= $__err('city') ?></label>
    <label class="checkbox-label"><input type="checkbox" name="consent" value="1" required<?= ($old['consent'] ?? '') === '1' ? ' checked' : '' ?>> I agree that NexaCrest may use these details to review my registration and contact me. *<?= $__err('consent') ?></label>
    <button type="submit" class="btn">Send my registration</button>
  </form>
</div>
