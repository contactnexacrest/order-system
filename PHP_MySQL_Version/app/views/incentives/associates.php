<?php
use App\Helpers\Csrf;

$__a = $editing ?? [];
$__v = static fn (string $k, $default = '') => htmlspecialchars((string) ($__a[$k] ?? $default));
?>
<div class="card">
  <h1>Associates</h1>
  <p class="muted">Outside people, in India or abroad, who introduce new clients under a signed agreement. They earn the agreement's rate on the product value of a client's first orders (the client's first 10 days count as one order), once the buyer has fully paid; nothing on repeat orders. Their commission is a cost of the order, deducted before the team incentive is worked out. Only people with "Manage team incentives" see this page.</p>

  <table class="list">
    <tr><th>Name</th><th>Based in</th><th>Paid in</th><th>Agreement signed</th><th>Rates (orders 1–5 / 6–10 / 11–20 / 21–40 / 41+)</th><th>Clients introduced</th><th>Status</th><th></th></tr>
    <?php foreach ($associates as $a): ?>
      <tr>
        <td><?= htmlspecialchars((string) $a['name']) ?></td>
        <td><?= $a['based_in'] === 'india' ? 'India' : htmlspecialchars('Abroad' . ($a['country'] ? ' — ' . $a['country'] : '')) ?></td>
        <td><?= htmlspecialchars((string) $a['currency_code']) ?></td>
        <td><?= $a['agreement_signed_on'] ? htmlspecialchars(\App\Helpers\Dates::short((string) $a['agreement_signed_on'])) : '—' ?></td>
        <td><?= htmlspecialchars(implode(' / ', array_map(static fn ($r): string => rtrim(rtrim(number_format((float) $r, 2), '0'), '.') . '%', [$a['rate_1_5'], $a['rate_6_10'], $a['rate_11_20'], $a['rate_21_40'], $a['rate_41_plus']]))) ?></td>
        <td>
          <?php foreach ($clientsByAssociate[(int) $a['id']] as $c): ?>
            <div><?= htmlspecialchars((string) $c['company_legal_name']) ?> <small class="muted">since <?= htmlspecialchars(\App\Helpers\Dates::short((string) $c['associate_registered_on'])) ?></small>
              <form method="post" action="/associates/<?= (int) $a['id'] ?>/clients/<?= (int) $c['id'] ?>/unlink" style="display:inline" data-confirm="Unlink this client from the associate?">
                <?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Unlink</button>
              </form></div>
          <?php endforeach; ?>
          <form method="post" action="/associates/<?= (int) $a['id'] ?>/clients" style="margin-top:6px">
            <?= Csrf::field() ?>
            <select name="client_id" required aria-label="Client introduced by <?= htmlspecialchars((string) $a['name']) ?>">
              <option value="">Link a client…</option>
              <?php foreach ($unlinkedClients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['company_legal_name'] . ' (' . $c['client_unique_number'] . ')') ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="registered_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" aria-label="Lead registered on">
            <button type="submit" class="btn-sm">Link</button>
          </form>
        </td>
        <td><?= $a['status'] === 'active' ? '<span class="badge badge-success">Active</span>' : '<span class="badge">Inactive</span>' ?></td>
        <td><a href="/associates/<?= (int) $a['id'] ?>/edit" class="btn-sm">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($associates === []): ?><tr><td colspan="8" class="muted">No associates yet.</td></tr><?php endif; ?>
  </table>
</div>

<div class="card" id="associate-form">
  <h2><?= $editing ? 'Edit ' . $__v('name') : 'Add an associate' ?></h2>
  <form method="post" action="<?= $editing ? '/associates/' . (int) $editing['id'] . '/update' : '/associates' ?>">
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
      <label>Email<input type="email" name="email" maxlength="190" value="<?= $__v('email') ?>"></label>
      <label>Phone<input type="tel" name="phone" maxlength="40" value="<?= $__v('phone') ?>"></label>
      <label>PAN / tax ID<input type="text" name="tax_id" maxlength="50" value="<?= $__v('tax_id') ?>"></label>
      <label>Agreement signed on<input type="date" name="agreement_signed_on" max="<?= date('Y-m-d') ?>" value="<?= $__v('agreement_signed_on') ?>"></label>
    </div>
    <fieldset><legend>Commission rates (% of product value, from the agreement)</legend>
      <div class="field-grid">
        <?php foreach (['rate_1_5' => ['Orders 1–5', '0.70'], 'rate_6_10' => ['Orders 6–10', '1.00'], 'rate_11_20' => ['Orders 11–20', '1.50'], 'rate_21_40' => ['Orders 21–40', '1.75'], 'rate_41_plus' => ['Order 41 onward', '2.00']] as $f => [$label, $default]): ?>
          <label><?= $label ?> *<input type="number" name="<?= $f ?>" required min="0" max="20" step="0.01" value="<?= $__v($f, $default) ?>"></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="field-grid">
      <label>Status<select name="status"><option value="active"<?= ($__a['status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= ($__a['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></label>
      <label>Notes<input type="text" name="notes" maxlength="500" value="<?= $__v('notes') ?>"></label>
    </div>
    <div class="btn-row"><button type="submit"><?= $editing ? 'Save' : 'Add associate' ?></button><?php if ($editing): ?> <a href="/associates">Cancel</a><?php endif; ?></div>
  </form>
</div>
