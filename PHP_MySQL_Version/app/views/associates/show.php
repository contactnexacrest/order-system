<?php
use App\Helpers\Csrf;
use App\Helpers\Dates;
use App\Repositories\AssociateBankRepository;
use App\Repositories\AssociateDocumentRepository;
use App\Repositories\AssociateRepository;

$id = (int) $a['id'];
$full = $canManage || $canBank;
$__pct = static fn ($r): string => rtrim(rtrim(number_format((float) $r, 2), '0'), '.') . '%';
$__money = static fn ($v): string => number_format((float) $v, 2);
$__d = static fn ($v): string => $v ? Dates::short(substr((string) $v, 0, 10)) : '—';
$__sourceLabel = ['link' => 'buyer registration link', 'code' => 'personal code', 'staff' => 'linked by NexaCrest', 'md_change' => 'changed by the MD'];
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1><?= htmlspecialchars((string) $a['name']) ?> <?php $__st = (string) $a['status']; require __DIR__ . '/_status_badge.php'; ?></h1>
      <p class="muted" style="margin:0"><?= $a['based_in'] === 'india' ? 'India' : htmlspecialchars('Abroad' . ($a['country'] ? ' — ' . $a['country'] : '')) ?>
        · paid in <?= htmlspecialchars((string) $a['currency_code']) ?><?php if (!empty($a['personal_code'])): ?> · personal code <b><?= htmlspecialchars((string) $a['personal_code']) ?></b><?php endif; ?>
        <?php if (!empty($a['status_reason'])): ?> · <?= htmlspecialchars((string) $a['status_reason']) ?><?php endif; ?></p>
    </div>
    <div class="btn-row" style="margin-top:0">
      <a class="btn-sm btn-secondary" href="/associates">All associates</a>
      <?php if ($canManage): ?><a class="btn-sm" href="/associates/<?= $id ?>/edit">Edit</a> <a class="btn-sm btn-secondary" href="/associates/<?= $id ?>/view-as">View their portal (read-only)</a><?php endif; ?>
    </div>
  </div>

  <?php if ($canManage): ?>
  <div class="section" id="profile">
    <h2>Profile and agreement</h2>
    <table class="kv">
      <tr><th>E-mail / phone</th><td><?= htmlspecialchars((string) ($a['email'] ?? '—')) ?> · <?= htmlspecialchars((string) ($a['phone'] ?? '—')) ?></td></tr>
      <tr><th>PAN / GSTIN / tax ID</th><td><?= htmlspecialchars(implode(' · ', array_filter([(string) $a['pan'], (string) $a['gstin'], (string) $a['tax_id']])) ?: '—') ?></td></tr>
      <tr><th>Address</th><td><?= nl2br(htmlspecialchars((string) ($a['address'] ?? '—'))) ?></td></tr>
      <tr><th>Reports to</th><td><?= htmlspecialchars((string) ($rm['name'] ?? '—')) ?></td></tr>
      <tr><th>GST on commission</th><td><?= trim((string) ($a['gstin'] ?? '')) !== '' ? 'GST-registered — GST ' . htmlspecialchars(rtrim(rtrim(number_format((float) $commissionSettings['gst_pct'], 2), '0'), '.')) . '% is added on top of each commission once their tax invoice is on file' : 'Not GST-registered — no GST on commission' ?></td></tr>
      <tr><th>Agreement</th><td>signed <?= $__d($a['agreement_signed_on']) ?>, ends <?= $__d($a['agreement_ends_on']) ?><?php if (!empty($a['agreement_file_id'])): ?> · <a href="/associates/<?= $id ?>/agreement" class="small">Signed copy</a><?php endif; ?></td></tr>
      <tr><th>Commission</th><td>
        <?php if ((int) $a['no_commission'] === 1): ?><b>No commission</b> (introductions only)<?php else: ?>
          On the invoiced FOB of the goods, line by line, at the level fixed on each order when its Proforma Invoice is issued; earned once the buyer has paid in full,
          due <?= (int) $commissionSettings['due_days'] ?> days later and paid in the payout run (<?= htmlspecialchars(\App\Services\AssociateCommission::runLabel($commissionSettings)) ?>).
        <?php endif; ?></td></tr>
      <?php // Owner decision D-245 (4 Nov 2026): fixed level or the ladder (used for commission from phase P3). ?>
      <tr><th>Commission level</th><td>
        <?php if (($a['commission_mode'] ?? 'ladder') === 'fixed' && !empty($a['commission_level_id']) && ($__lvl = \App\Repositories\PricingRepository::findLevel((int) $a['commission_level_id'])) !== null): ?>
          Fixed level: <b><?= htmlspecialchars($__lvl['name']) ?></b> — <?= htmlspecialchars(\App\Services\PricingService::levelLabel($__lvl)) ?><?= (int) $__lvl['is_active'] === 1 ? '' : ' <span class="badge badge-inactive">inactive</span>' ?>
        <?php else: ?>
          Ladder: <?= (int) ($commissionNow[1] ?? 0) ?> counting order(s) so far — next order <?= $commissionNow[0] !== null ? '<b>' . htmlspecialchars(\App\Services\AssociateCommission::levelLabel($commissionNow[0])) . '</b>' : 'no level' ?>
          <span class="muted small">(a counting order has invoiced FOB of ₹<?= number_format((float) $commissionSettings['threshold_inr'], 0) ?> or more on lines within range — <a href="/pricing/commission-levels#ladder">see the ladder</a>)</span>
        <?php endif; ?></td></tr>
    </table>
    <form method="post" action="/associates/<?= $id ?>/status" class="inline-form">
      <?= Csrf::field() ?>
      <label>Change status <select name="status" required><option value="">Choose…</option>
        <?php foreach (AssociateRepository::STATUSES as $k => $l): if ($k === 'inactive' || $k === $a['status']) { continue; } ?><option value="<?= $k ?>"><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
      </select></label>
      <label>Reason <input type="text" name="reason" required minlength="10" maxlength="300" placeholder="At least 10 characters — say why"></label>
      <button type="submit" class="btn-sm">Change status</button>
    </form>
  </div>

  <div class="section" id="portal">
    <h2>Portal login link and personal code</h2>
    <p>Portal: <b><?= (int) $a['portal_enabled'] === 1 ? 'on' : 'off' ?></b><?php if (!empty($a['last_login_at'])): ?> · last login <?= $__d($a['last_login_at']) ?><?php endif; ?>
      <?php if ($codeLink): ?><br>Quotation link (with their code): <code><?= htmlspecialchars($codeLink) ?></code><?php endif; ?></p>
    <div class="btn-row">
      <form method="post" action="/associates/<?= $id ?>/portal/invite" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm"><?= (int) $a['portal_enabled'] === 1 ? 'Resend portal login link' : 'Send portal login link' ?></button></form>
      <?php if ((int) $a['portal_enabled'] === 1): ?><form method="post" action="/associates/<?= $id ?>/portal/disable" style="display:inline" data-confirm="Switch off this associate's portal?"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Switch portal off</button></form><?php endif; ?>
    </div>
    <?php // Owner decision D-254: old codes keep bringing buyers to the associate unless blocked here (e.g. after a leak). ?>
    <form method="post" action="/associates/<?= $id ?>/code" class="inline-form" data-confirm="Set a new personal code for this associate?">
      <?= Csrf::field() ?>
      <label>New personal code — readable part *<input type="text" name="readable" required minlength="3" maxlength="12" pattern="[A-Za-z0-9 ]{3,12}" placeholder="e.g. RAJESH"></label>
      <label class="checkbox-label"><input type="checkbox" name="block_old" value="1"> Block the old code (e.g. it leaked)</label>
      <input type="text" name="block_reason" maxlength="300" minlength="10" placeholder="Why it is blocked (needed when blocking)" aria-label="Why the old code is blocked" data-required-when="block_old=1" data-required-when-message="Say why the old code is blocked (at least 10 characters).">
      <button type="submit" class="btn-sm btn-secondary">Set code</button>
    </form>
    <?php if (!empty($codes)): ?>
      <table class="list"><tr><th>Code</th><th>From</th><th>To</th><th>Status</th></tr>
        <?php foreach ($codes as $__c): ?><tr><td style="font-family:monospace"><?= htmlspecialchars((string) $__c['code']) ?></td><td><?= $__d($__c['issued_at']) ?></td><td><?= $__d($__c['retired_at']) ?></td>
          <td><?= !empty($__c['blocked_at']) ? 'Blocked — ' . htmlspecialchars((string) $__c['blocked_reason']) : ($__c['code'] === ($a['personal_code'] ?? '') ? 'Current' : 'Still accepted (old links)') ?></td></tr><?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="section" id="clients">
    <h2>Clients introduced (<?= count($clients) ?>)</h2>
    <table class="list">
      <tr><th>Client</th><th>Country</th><th>Registered</th><th>Came via</th><th>Protected until</th><th>Orders</th><?php if ($canChangeLink): ?><th>Change (MD only)</th><?php endif; ?></tr>
      <?php foreach ($clients as $c): ?>
        <tr>
          <td><a href="/clients/<?= (int) $c['id'] ?>"><?= htmlspecialchars((string) $c['company_legal_name']) ?></a> <small class="muted"><?= htmlspecialchars((string) $c['client_unique_number']) ?></small></td>
          <td><?= htmlspecialchars((string) ($c['country_of_destination'] ?? '')) ?></td>
          <td><?= $__d($c['associate_registered_on']) ?></td>
          <td><?= htmlspecialchars((string) ($c['came_via'] ?? ($__sourceLabel[$c['associate_source'] ?? ''] ?? 'linked earlier'))) ?></td>
          <td><?= $__d($c['associate_locked_until']) ?></td>
          <td><?= $c['order_refs'] !== [] ? htmlspecialchars(implode(', ', $c['order_refs'])) : '<span class="muted">none yet</span>' ?></td>
          <?php if ($canChangeLink): ?><td>
            <form method="post" action="/associates/<?= $id ?>/clients/<?= (int) $c['id'] ?>/unlink" class="inline-form" data-confirm="Change this client's associate? The associate is told at once.">
              <?= Csrf::field() ?>
              <select name="move_to" aria-label="Move to another associate"><option value="">Remove the link</option>
                <?php foreach (AssociateRepository::all() as $o): if ((int) $o['id'] === $id) { continue; } ?><option value="<?= (int) $o['id'] ?>">Move to <?= htmlspecialchars((string) $o['name']) ?></option><?php endforeach; ?>
              </select>
              <input type="text" name="reason" required minlength="10" maxlength="300" placeholder="Reason (shown to the associate)" aria-label="Reason">
              <button type="submit" class="btn-sm btn-secondary">Change</button>
            </form></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($clients === []): ?><tr><td colspan="7" class="muted">No clients yet.</td></tr><?php endif; ?>
    </table>
    <?php if ($canManage): ?>
      <form method="post" action="/associates/<?= $id ?>/clients" class="inline-form">
        <?= Csrf::field() ?>
        <select name="client_id" required aria-label="Client introduced by <?= htmlspecialchars((string) $a['name']) ?>">
          <option value="">Link a client already on file…</option>
          <?php foreach ($unlinkedClients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['company_legal_name'] . ' (' . $c['client_unique_number'] . ')') ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="registered_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" aria-label="Introduced on">
        <input type="text" name="reason" required minlength="10" maxlength="300" placeholder="How they introduced the client" aria-label="How they introduced the client">
        <button type="submit" class="btn-sm">Link</button>
      </form>
      <p class="muted small">Normally the buyer registers through the associate's buyer registration link or quotation link (personal code) and confirms the introduction themselves; this is for clients already on file. Once linked, only the MD can change it.</p>
    <?php endif; ?>
  </div>

  <?php if ($full): ?>
  <?php if ($canManage): ?>
  <div class="section" id="invitations">
    <h2>Buyer registration links and codes used</h2>
    <table class="list">
      <tr><th>Created</th><th>For</th><th>Kind</th><th>Status</th><th>Expires</th></tr>
      <?php foreach ($invitations as $i): ?>
        <tr><td><?= $__d($i['created_at']) ?></td><td><?= htmlspecialchars(trim($i['company_name'] . ' ' . ($i['country'] ? '(' . $i['country'] . ')' : ''))) ?></td>
          <td><?= $i['kind'] === 'personal_code' ? 'Personal code' . (!empty($i['code_used']) ? ' ' . htmlspecialchars((string) $i['code_used']) : '') : 'Buyer registration link (one buyer)' ?></td><td><?= htmlspecialchars(\App\Repositories\AssociateInvitationRepository::STATUSES[$i['status']] ?? $i['status']) ?></td><td><?= $__d($i['expires_at']) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($invitations === []): ?><tr><td colspan="5" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="section" id="commission">
    <h2>Orders and commission</h2>
    <table class="list">
      <tr><th>Order</th><th>Client</th><th>Stage</th><th>FOB value</th><th>Buyer paid in full</th><th>Level</th><th>Commission</th></tr>
      <?php foreach ($orders as $o): $cm = $o['commission']; ?>
        <tr><td><a href="/orders/<?= (int) $o['id'] ?>"><?= htmlspecialchars((string) $o['order_reference']) ?></a></td><td><?= htmlspecialchars((string) $o['company_legal_name']) ?></td><td><?= htmlspecialchars((string) $o['stage']) ?></td>
          <td><?= htmlspecialchars((string) $o['currency_code']) ?> <?= $__money($o['fob_value']) ?></td><td><?= $o['buyer_paid_in_full'] ? 'Yes' : 'No' ?></td>
          <td><?= htmlspecialchars((string) ($cm['level'] ?? '—')) ?></td>
          <td><?= $cm['amount'] !== null ? htmlspecialchars((string) $o['currency_code']) . ' ' . $__money($cm['amount']) . '<br>' : '' ?><small><?= htmlspecialchars($cm['label']) ?></small></td></tr>
      <?php endforeach; ?>
      <?php if ($orders === []): ?><tr><td colspan="7" class="muted">No orders yet.</td></tr><?php endif; ?>
    </table>
    <h3>Payouts</h3>
    <table class="list">
      <tr><th>Due / payout run</th><th>Order</th><th>Amount INR</th><th>Status</th><th>Paid</th><th>Invoice</th><th>Action</th></tr>
      <?php foreach ($payouts as $p): $inv = AssociateDocumentRepository::invoiceForPayout((int) $p['id']); ?>
        <tr><td><?= !empty($p['due_on']) ? 'due ' . $__d($p['due_on']) . '<br><small class="muted">run ' . $__d($p['payout_run_on']) . '</small>' : htmlspecialchars((string) $p['incentive_month']) ?></td><td><?= htmlspecialchars((string) $p['order_reference']) ?><?= $p['note'] ? '<br><small class="muted">' . htmlspecialchars((string) $p['note']) . '</small>' : '' ?></td>
          <td><?= $__money($p['amount_inr']) ?><?= (float) ($p['gst_inr'] ?? 0) > 0 ? '<br><small class="muted">+ GST ₹' . $__money($p['gst_inr']) . '</small>' : '' ?></td><td><?= htmlspecialchars(ucfirst((string) $p['status'])) ?><?= $p['status'] === 'due' && ($__blk = \App\Services\AssociateCommission::payBlockReason($p, date('Y-m-d'))) !== null ? '<br><small class="muted">' . htmlspecialchars($__blk) . '</small>' : '' ?><?= $p['hold_reason'] ? '<br><small>' . htmlspecialchars((string) $p['hold_reason']) . '</small>' : '' ?></td>
          <td><?= $p['status'] === 'paid' ? $__d($p['paid_on']) . ' · ₹' . $__money($p['paid_inr']) . ((float) $p['tds_inr'] > 0 ? ' (TDS ₹' . $__money($p['tds_inr']) . ')' : '') . ($p['paid_reference'] ? '<br><small>' . htmlspecialchars((string) $p['paid_reference']) . '</small>' : '') : '—' ?></td>
          <td><?= $inv ? '<a href="/associates/' . $id . '/documents/' . (int) $inv['id'] . '">' . htmlspecialchars((string) ($inv['reference'] ?? 'Invoice')) . '</a>' : '—' ?></td>
          <td>
            <?php if ($p['status'] === 'due' && (float) $p['amount_inr'] > 0): ?>
              <form method="post" action="/associates/payouts/<?= (int) $p['id'] ?>/hold" class="inline-form"><?= Csrf::field() ?><input type="text" name="reason" required minlength="10" maxlength="300" placeholder="Reason" aria-label="Reason for the hold"><button type="submit" class="btn-sm btn-secondary">Hold</button></form>
            <?php elseif ($p['status'] === 'held'): ?>
              <form method="post" action="/associates/payouts/<?= (int) $p['id'] ?>/release" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm">Release</button></form>
            <?php elseif ($p['status'] === 'paid' && (float) $p['amount_inr'] > 0): ?>
              <?php // Owner decision D-245 P3: taken back only for the associate's fraud or breach, with a reason — never for a later buyer claim or refund. ?>
              <form method="post" action="/associates/payouts/<?= (int) $p['id'] ?>/clawback" class="inline-form" data-confirm="Take this amount back from the associate's next payout?"><?= Csrf::field() ?>
                <select name="takeback_kind" required aria-label="Why it is taken back"><option value="">Choose…</option><option value="fraud">Fraud</option><option value="breach">Breach of the agreement</option></select>
                <input type="number" name="amount_inr" required min="0.01" step="0.01" max="<?= htmlspecialchars((string) $p['paid_inr']) ?>" placeholder="₹" aria-label="Amount to take back">
                <input type="text" name="reason" required minlength="10" maxlength="300" placeholder="What happened" aria-label="What the fraud or breach was"><button type="submit" class="btn-sm btn-secondary">Take back</button></form>
            <?php endif; ?>
          </td></tr>
      <?php endforeach; ?>
      <?php if ($payouts === []): ?><tr><td colspan="7" class="muted">No payouts yet. Commission is earned once the buyer has paid an order in full.</td></tr><?php endif; ?>
    </table>
    <p class="muted small">Accounts pay due payouts on <a href="/incentives/payouts">Incentive payouts</a>, only into a verified bank account. TDS (India, section 194H): <?= $__pct($tdsPct) ?> unless the CA advises otherwise; abroad: Form 15CA/15CB.</p>
  </div>
  <?php endif; ?>

  <div class="section" id="bank">
    <h2>Bank details</h2>
    <p>Payments go to: <b><?= $usableBank ? htmlspecialchars(AssociateBankRepository::masked($usableBank)) . ' (verified)' : 'no verified account yet' ?></b></p>
    <table class="list">
      <tr><th>Requested</th><th>Account</th><th>Holder</th><th>IFSC / SWIFT</th><th>Status</th><th>Checks</th><th>Action</th></tr>
      <?php foreach ($banks as $b): ?>
        <tr><td><?= $__d($b['requested_at']) ?><br><small class="muted"><?= (int) $b['requested_by_associate'] === 1 ? 'by the associate' : 'by NexaCrest' ?></small></td>
          <td><?= htmlspecialchars(AssociateBankRepository::masked($b)) ?> <small class="muted"><?= htmlspecialchars((string) $b['account_currency']) ?></small><?php if ($canBank): ?><br><a class="small" href="/associates/<?= $id ?>/bank/<?= (int) $b['id'] ?>/number">Show full number</a><?php endif; ?></td>
          <td><?= htmlspecialchars((string) $b['holder_name']) ?></td><td><?= htmlspecialchars(trim((string) $b['ifsc'] . ' ' . (string) $b['swift'])) ?></td>
          <td><?= htmlspecialchars(ucfirst((string) $b['status'])) ?><?= $b['status'] === 'verified' && $b['usable_from'] ? '<br><small>usable from ' . $__d($b['usable_from']) . '</small>' : '' ?><?= $b['rejected_reason'] ? '<br><small>' . htmlspecialchars((string) $b['rejected_reason']) . '</small>' : '' ?></td>
          <td><small><?= $b['first_checked_by_name'] ? '1: ' . htmlspecialchars((string) $b['first_checked_by_name']) . ' — ' . htmlspecialchars((string) $b['check_method']) : '' ?><?= $b['verified_by_name'] ? '<br>2: ' . htmlspecialchars((string) $b['verified_by_name']) : '' ?></small></td>
          <td><?php if ($canBank && $b['status'] === 'pending'): ?>
            <?php if (!$b['first_checked_by']): ?>
              <form method="post" action="/associates/<?= $id ?>/bank/<?= (int) $b['id'] ?>/check" class="inline-form"><?= Csrf::field() ?><input type="text" name="check_method" required minlength="10" maxlength="300" placeholder="How you checked it (call-back, test credit)" aria-label="How you checked it"><button type="submit" class="btn-sm">First check</button></form>
            <?php else: ?>
              <form method="post" action="/associates/<?= $id ?>/bank/<?= (int) $b['id'] ?>/verify" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm">Verify (second person)</button></form>
            <?php endif; ?>
            <form method="post" action="/associates/<?= $id ?>/bank/<?= (int) $b['id'] ?>/reject" class="inline-form"><?= Csrf::field() ?><input type="text" name="reason" required minlength="10" maxlength="300" placeholder="Reason" aria-label="Reason for rejecting"><button type="submit" class="btn-sm btn-secondary">Reject</button></form>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($banks === []): ?><tr><td colspan="7" class="muted">No bank details yet — the associate adds them in their portal, or Accounts enter them below.</td></tr><?php endif; ?>
    </table>
    <?php if ($canBank): ?>
    <details><summary>Enter bank details from the associate's signed form</summary>
      <form method="post" action="/associates/<?= $id ?>/bank" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <?php $__india = $a['based_in'] === 'india'; require dirname(__DIR__) . '/associate_portal/_bank_fields.php'; ?>
        <button type="submit" class="btn-sm">Record — two people then check it</button>
      </form>
    </details>
    <?php endif; ?>
  </div>

  <div class="section" id="documents">
    <h2>KYC and documents</h2>
    <?php // Owner decision D-252 (A3): the KYC checklist; commission cannot be marked paid until every item is accepted. ?>
    <table class="list"><tr><th>KYC item</th><th>Status</th><th>Document</th></tr>
      <?php foreach ($kyc as $__i): ?>
        <tr><td><?= htmlspecialchars($__i['label']) ?></td>
          <td><span class="badge <?= ['accepted' => 'badge-success', 'rejected' => 'badge-danger', 'pending' => 'badge-warning'][$__i['status']] ?? '' ?>"><?= htmlspecialchars(\App\Services\AssociateKyc::STATUS_LABELS[$__i['status']]) ?></span></td>
          <td><?= $__i['doc'] !== null ? htmlspecialchars(AssociateDocumentRepository::KINDS[$__i['doc']['kind']] ?? $__i['doc']['kind']) . (!empty($__i['doc']['reference']) ? ' · ' . htmlspecialchars((string) $__i['doc']['reference']) : '') : ($__i['by_nexacrest'] ? 'Agreement on the associate record' : '—') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php $__missing = array_filter($kyc, static fn (array $i): bool => $i['status'] !== 'accepted'); ?>
    <p class="<?= $__missing === [] ? 'muted' : 'review-banner bad' ?> small"><?= $__missing === [] ? 'KYC complete — commission can be paid.' : 'KYC not complete — commission cannot be marked paid until every item is accepted.' ?></p>
    <table class="list">
      <tr><th>Added</th><th>Document</th><th>Number / reference</th><th>From</th><th>Status</th><?php if ($canManage): ?><th>Check</th><?php endif; ?></tr>
      <?php foreach ($documents as $d): ?>
        <tr><td><?= $__d($d['created_at']) ?></td><td><a href="/associates/<?= $id ?>/documents/<?= (int) $d['id'] ?>"><?= htmlspecialchars(AssociateDocumentRepository::KINDS[$d['kind']] ?? $d['kind']) ?></a></td><td><?= htmlspecialchars((string) ($d['reference'] ?? '')) ?></td><td><?= (int) $d['uploaded_by_associate'] === 1 ? 'Associate' : 'NexaCrest' ?></td>
          <td><?= htmlspecialchars(['accepted' => 'Accepted', 'rejected' => 'Rejected', 'received' => 'Not checked yet'][$d['status']] ?? (string) $d['status']) ?><?php if (!empty($d['reviewed_at'])): ?><br><small class="muted"><?= htmlspecialchars((string) ($d['reviewed_by_name'] ?? '')) ?>, <?= $__d($d['reviewed_at']) ?><?= !empty($d['review_reason']) ? ' — ' . htmlspecialchars((string) $d['review_reason']) : '' ?></small><?php endif; ?></td>
          <?php if ($canManage): ?><td>
            <form method="post" action="/associates/<?= $id ?>/documents/<?= (int) $d['id'] ?>/review" class="inline-form">
              <?= Csrf::field() ?>
              <select name="decision" required aria-label="Accept or reject"><option value="">Choose…</option><option value="accept">Accept</option><option value="reject">Reject</option></select>
              <input type="text" name="reason" maxlength="300" placeholder="Reason (needed to reject)" aria-label="Reason" data-required-when="decision=reject" data-required-when-message="Say why it is rejected (at least 10 characters) — the associate sees it." minlength="10">
              <button type="submit" class="btn-sm btn-secondary">Save</button>
            </form></td><?php endif; ?></tr>
      <?php endforeach; ?>
      <?php if ($documents === []): ?><tr><td colspan="6" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
    <form method="post" action="/associates/<?= $id ?>/documents" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <div class="field-grid">
        <?php $__docKinds = array_keys(AssociateDocumentRepository::KINDS); require __DIR__ . '/_doc_upload_fields.php'; ?>
      </div>
      <button type="submit" class="btn-sm">Upload</button>
    </form>
  </div>

  <?php if ($canManage): ?>
  <div class="section" id="messages">
    <h2>Messages</h2>
    <?php foreach ($messages as $m): ?>
      <div class="comment"><b><?= (int) $m['from_associate'] === 1 ? htmlspecialchars((string) $a['name']) : htmlspecialchars((string) ($m['user_name'] ?? 'NexaCrest')) ?></b> <small class="muted"><?= $__d($m['created_at']) ?></small><br><?= nl2br(htmlspecialchars((string) $m['body'])) ?></div>
    <?php endforeach; ?>
    <form method="post" action="/associates/<?= $id ?>/messages">
      <?= Csrf::field() ?>
      <textarea name="body" required maxlength="4000" rows="3" aria-label="Reply" placeholder="Type your message"></textarea>
      <button type="submit" class="btn-sm">Send</button>
    </form>
  </div>

  <div class="section" id="history">
    <h2>History <small class="muted">(nobody can edit it)</small></h2>
    <table class="list">
      <?php foreach ($events as $e): ?><tr><td><?= htmlspecialchars((string) $e['created_at']) ?></td><td><?= htmlspecialchars((string) $e['message']) ?></td></tr><?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php $__partyType = "associate"; $__partyId = (int) $a["id"]; require __DIR__ . "/../agreements/_party_card.php"; ?>
