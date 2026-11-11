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
      <?php require __DIR__ . '/_my_commission.php'; // D-292: the current % only, and How it works ?><?php endif; ?></td></tr>
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
        <td><?= htmlspecialchars(\App\Services\AssociateBankConfirmation::associateStatus($b)) ?><?php if ($b['status'] === 'awaiting_confirmation'): ?> <a class="btn-sm" href="/associate/bank/confirm">Review now</a><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if ($banks === []): ?><tr><td colspan="3" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
    <details><summary><?= $banks === [] ? 'Add my bank details' : 'Change my bank details' ?></summary>
      <p class="muted small">You confirm new details with a code we e-mail you (within <?= \App\Services\BankDetailsCheck::CONFIRM_DAYS ?> days), then NexaCrest checks them. Until then, payments keep going to your verified account; a changed account is used <?= \App\Services\BankDetailsCheck::waitDays(false) ?> days after it is verified<?= \App\Services\BankDetailsCheck::waitDays(true) > 0 ? ', a first account ' . \App\Services\BankDetailsCheck::waitDays(true) . ' days after' : '' ?>.</p>
      <form method="post" action="/associate/bank" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <?php require __DIR__ . '/_bank_fields.php'; ?>
        <button type="submit" class="btn-sm">Send — then confirm with the e-mailed code</button>
      </form>
    </details>
  </div>

  <div class="section" id="kyc">
    <h2>My documents and KYC</h2>
    <?php // Owner decision D-252 (A3): the KYC checklist — NexaCrest checks each document; commission is paid once all are accepted. ?>
    <table class="list"><tr><th>Needed</th><th>Status</th><th>Document</th></tr>
      <?php foreach ($kyc as $__i): ?>
        <tr><td><?= htmlspecialchars($__i['label']) ?></td>
          <td><span class="badge <?= ['accepted' => 'badge-success', 'rejected' => 'badge-danger', 'pending' => 'badge-warning'][$__i['status']] ?? '' ?>"><?= htmlspecialchars(\App\Services\AssociateKyc::STATUS_LABELS[$__i['status']]) ?></span>
            <?php if ($__i['status'] === 'rejected' && !empty($__i['doc']['review_reason'])): ?><br><small><?= htmlspecialchars((string) $__i['doc']['review_reason']) ?> — please upload it again.</small><?php endif; ?></td>
          <td><?= $__i['doc'] !== null ? htmlspecialchars(AssociateDocumentRepository::KINDS[$__i['doc']['kind']] ?? $__i['doc']['kind']) . (!empty($__i['doc']['id_doc_type']) ? ' — ' . htmlspecialchars(\App\Services\AssociateKyc::ID_DOC_TYPES[$__i['doc']['id_doc_type']] ?? (string) $__i['doc']['id_doc_type']) : '') . (!empty($__i['doc']['reference']) ? ' · ' . htmlspecialchars((string) $__i['doc']['reference']) : '') : ($__i['by_nexacrest'] ? 'On file at NexaCrest' : '—') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted small">Commission is paid once every document above is accepted.</p>
    <?php if (($__grace = \App\Services\AssociateKyc::addressGraceNote($me)) !== null): // D-290 ?><p class="small" data-address-grace><?= htmlspecialchars($__grace) ?></p><?php endif; ?>
    <h3>All documents</h3>
    <table class="list"><tr><th>Added</th><th>Document</th><th>Number / reference</th><th>Status</th></tr>
      <?php foreach ($documents as $d): ?><tr><td><?= $__d($d['created_at']) ?></td><td><a href="/associate/documents/<?= (int) $d['id'] ?>"><?= htmlspecialchars(AssociateDocumentRepository::KINDS[$d['kind']] ?? $d['kind']) ?></a></td><td><?= htmlspecialchars((string) ($d['reference'] ?? '')) ?></td>
        <td><?= htmlspecialchars(['accepted' => 'Accepted', 'rejected' => 'Rejected', 'received' => 'Being checked'][$d['status']] ?? (string) $d['status']) ?></td></tr><?php endforeach; ?>
      <?php if ($documents === []): ?><tr><td colspan="4" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
    <form method="post" action="/associate/documents" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <div class="field-grid">
        <?php $__docKinds = array_values(array_filter(AssociateDocumentRepository::ASSOCIATE_KINDS, static fn (string $k): bool => $k !== 'invoice')); require dirname(__DIR__) . '/associates/_doc_upload_fields.php'; ?>
      </div>
      <button type="submit" class="btn-sm">Upload</button>
    </form>
    <p class="muted small"><?= $__india ? 'India: PAN card, ID proof (Aadhaar card — we keep only its last 4 digits — or an ID document), address proof, bank proof, the signed agreement, GST certificate if registered.' : 'Abroad: ID proof (passport, national ID card, residence permit or driving licence), address proof, bank proof, the signed agreement; also tax residency certificate, Form 10F, no-PE declaration.' ?></p>
    <p class="muted small">ID proof and address proof are two separate uploads. If one document shows both (e.g. a passport with your current address), upload it once as "ID document" and once as "Address proof" — we check each on its own.</p>
  </div>
</div>
