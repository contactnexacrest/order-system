<?php
use App\Helpers\Csrf;
use App\Repositories\AssociateBankRepository;
use App\Repositories\AssociateDocumentRepository;

$__india = $me['based_in'] === 'india';
$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
$__pct = static fn ($r): string => rtrim(rtrim(number_format((float) $r, 2), '0'), '.') . '%';
$__usable = AssociateBankRepository::usable((int) $me['id']);
?>
<div class="card page-wide">
  <h1>My profile</h1>
  <table class="kv">
    <tr><th>Name</th><td><?= htmlspecialchars((string) $me['name']) ?></td></tr>
    <tr><th>E-mail (login)</th><td><?= htmlspecialchars((string) $me['email']) ?> <small class="muted">— ask NexaCrest to change it</small></td></tr>
    <tr><th>Agreement</th><td>signed <?= $__d($me['agreement_signed_on']) ?>, ends <?= $__d($me['agreement_ends_on']) ?><?php if (!empty($me['agreement_file_id'])): ?> · <a href="/associate/agreement">Download my agreement</a><?php endif; ?></td></tr>
    <tr><th>My commission</th><td>
      <?php if ((int) $me['no_commission'] === 1): ?>No commission under your agreement.<?php else: ?>
      <?= htmlspecialchars($commissionNow) ?><br><small class="muted">On the invoiced FOB of the goods of a client's first orders, at the level fixed on each order when its Proforma Invoice is issued; earned when the buyer has paid in full, due <?= (int) $commissionSettings['due_days'] ?> days later and paid in the payout run (<?= htmlspecialchars(\App\Services\AssociateCommission::runLabel($commissionSettings)) ?>).<?= trim((string) ($me['gstin'] ?? '')) !== '' ? ' GST is added on top once your tax invoice is on file.' : '' ?></small><?php endif; ?></td></tr>
  </table>

  <div class="section">
    <h2>Contact and tax details</h2>
    <form method="post" action="/associate/profile">
      <?= Csrf::field() ?>
      <div class="field-grid">
        <label>Phone<input type="tel" name="phone" maxlength="40" value="<?= htmlspecialchars((string) ($me['phone'] ?? '')) ?>" placeholder="e.g. +91 98765 43210"></label>
        <?php if ($__india): ?><label>PAN<input type="text" name="pan" maxlength="10" pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]" title="As on the card, e.g. ABCDE1234F" value="<?= htmlspecialchars((string) ($me['pan'] ?? '')) ?>" placeholder="e.g. ABCDE1234F"></label><?php endif; ?>
        <label>GSTIN (if registered)<input type="text" name="gstin" maxlength="15" pattern="[0-9]{2}[A-Za-z0-9]{13}" value="<?= htmlspecialchars((string) ($me['gstin'] ?? '')) ?>" placeholder="e.g. 29ABCDE1234F1Z5"></label>
        <label style="grid-column:1/-1">Address<textarea name="address" maxlength="1000" rows="2" placeholder="e.g. 12 MG Road, Bengaluru 560001"><?= htmlspecialchars((string) ($me['address'] ?? '')) ?></textarea></label>
      </div>
      <button type="submit" class="btn-sm">Save</button>
    </form>
  </div>

  <div class="section">
    <h2>Bank details</h2>
    <p>Payments go to: <b><?= $__usable ? htmlspecialchars(AssociateBankRepository::masked($__usable)) : 'no verified account yet' ?></b></p>
    <table class="list"><tr><th>Sent</th><th>Account</th><th>Status</th></tr>
      <?php foreach ($banks as $b): ?><tr><td><?= $__d($b['requested_at']) ?></td><td><?= htmlspecialchars(AssociateBankRepository::masked($b)) ?></td>
        <td><?= htmlspecialchars(['pending' => 'Being checked by NexaCrest', 'verified' => 'Verified' . ($b['usable_from'] > date('Y-m-d') ? ' — used from ' . $__d($b['usable_from']) : ''), 'rejected' => 'Not accepted: ' . $b['rejected_reason'], 'superseded' => 'Replaced'][$b['status']] ?? $b['status']) ?></td></tr><?php endforeach; ?>
      <?php if ($banks === []): ?><tr><td colspan="3" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
    <details><summary><?= $banks === [] ? 'Add my bank details' : 'Change my bank details' ?></summary>
      <p class="muted small">A change is checked by two people at NexaCrest and e-mailed to you. Until then, payments keep going to your verified account; a new account is used <?= (int) \App\Services\AssociateService::bankWaitDays() ?> days after it is verified.</p>
      <form method="post" action="/associate/bank" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <?php require __DIR__ . '/_bank_fields.php'; ?>
        <button type="submit" class="btn-sm">Send for checking</button>
      </form>
    </details>
  </div>

  <div class="section">
    <h2>My documents</h2>
    <table class="list"><tr><th>Added</th><th>Document</th><th>Reference</th></tr>
      <?php foreach ($documents as $d): ?><tr><td><?= $__d($d['created_at']) ?></td><td><a href="/associate/documents/<?= (int) $d['id'] ?>"><?= htmlspecialchars(AssociateDocumentRepository::KINDS[$d['kind']] ?? $d['kind']) ?></a></td><td><?= htmlspecialchars((string) ($d['reference'] ?? '')) ?></td></tr><?php endforeach; ?>
      <?php if ($documents === []): ?><tr><td colspan="3" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
    <form method="post" action="/associate/documents" enctype="multipart/form-data" class="inline-form">
      <?= Csrf::field() ?>
      <select name="kind" required aria-label="Document kind"><option value="">What is it?</option>
        <?php foreach (AssociateDocumentRepository::ASSOCIATE_KINDS as $k): if ($k === 'invoice') { continue; } ?><option value="<?= $k ?>"><?= htmlspecialchars(AssociateDocumentRepository::KINDS[$k]) ?></option><?php endforeach; ?>
      </select>
      <input type="file" name="document_file" required accept=".pdf,.jpg,.jpeg,.png" aria-label="File">
      <button type="submit" class="btn-sm">Upload</button>
    </form>
    <p class="muted small"><?= $__india ? 'India: PAN card, address proof, GST certificate if registered.' : 'Abroad: passport or company registration, tax residency certificate, Form 10F, no-PE declaration.' ?></p>
  </div>
</div>
