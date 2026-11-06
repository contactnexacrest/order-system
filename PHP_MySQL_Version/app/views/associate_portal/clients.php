<?php
use App\Helpers\Csrf;
use App\Repositories\AssociateInvitationRepository;

$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
$__codeChosen = false;
?>
<div class="card page-wide">
  <h1>My clients</h1>
  <p class="muted">Your buyer registers with NexaCrest themselves, with their own details, through your buyer registration link or your quotation link (with your personal code), and confirms that you introduced them. Once NexaCrest accepts the registration, the company is your client for <?= (int) \App\Services\AssociateService::protectionMonths() ?> months — however they contact us later. The first completed registration of a company counts.</p>

  <?php if (!empty($newLink)): ?>
    <div class="alert alert-success">Your new buyer registration link — copy it and send it to your buyer (valid 30 days, for one registration):<br><code style="user-select:all"><?= htmlspecialchars($newLink) ?></code></div>
  <?php endif; ?>

  <?php if (!empty($access['registration_links'])): // D-261 ?>
  <div class="section">
    <h2>Invite a client</h2>
    <?php if ($me['status'] !== 'active'): ?><p class="muted">You can invite clients once your account is active.</p><?php else: ?>
    <div class="field-grid">
      <div>
        <h3>Buyer registration link — for one buyer</h3>
        <form method="post" action="/associate/invitations">
          <?= Csrf::field() ?>
          <label>Company name *<input type="text" name="company_name" required minlength="2" maxlength="255" placeholder="e.g. Rotterdam Stone Trading BV"></label>
          <label>Country *<input type="text" name="country" required maxlength="100" placeholder="e.g. Netherlands"></label>
          <button type="submit" class="btn-sm">Create buyer registration link</button>
        </form>
      </div>
      <div id="code">
        <h3>Your personal code and quotation link — for any buyer</h3>
        <?php if (!empty($me['personal_code'])): ?>
          <p>Code: <b style="font-size:1.2em;font-family:monospace"><?= htmlspecialchars((string) $me['personal_code']) ?></b><br>
            Quotation link (with your code): <code style="user-select:all"><?= htmlspecialchars((string) $codeLink) ?></code></p>
          <p class="muted small">Give the code or the quotation link to a buyer. When they register with it and confirm your name, they become your client. The code always belongs to you; its last letter catches typing mistakes.</p>
        <?php endif; ?>
        <?php // Owner decision D-254 (A5): change the code like a captcha refresh; old codes keep bringing buyers to you. ?>
        <details<?= !empty($proposal) ? ' open' : '' ?>><summary><?= empty($me['personal_code']) ? 'Choose my code' : 'Change my code' ?></summary>
          <form method="post" action="/associate/code/propose" class="inline-form">
            <?= Csrf::field() ?>
            <label>Readable part (3–12 letters or digits) *<input type="text" name="readable" required minlength="3" maxlength="12" pattern="[A-Za-z0-9 ]{3,12}" value="<?= htmlspecialchars((string) ($proposal['readable'] ?? '')) ?>" placeholder="e.g. RAJESHEXIM"></label>
            <button type="submit" class="btn-sm btn-secondary" title="Try another ending, like a captcha refresh">&#10227; New ending</button>
          </form>
          <?php if (!empty($proposal)): ?>
            <p>Proposed code: <b style="font-size:1.2em;font-family:monospace"><?= htmlspecialchars((string) $proposal['code']) ?></b></p>
            <form method="post" action="/associate/code" class="inline-form" data-confirm="Use <?= htmlspecialchars((string) $proposal['code']) ?> as your personal code?">
              <?= Csrf::field() ?>
              <input type="hidden" name="code" value="<?= htmlspecialchars((string) $proposal['code']) ?>">
              <button type="submit" class="btn-sm">Use this code</button>
            </form>
          <?php endif; ?>
          <p class="muted small">You can change it up to <?= (int) $codeChangesPerYear ?> times a year (used: <?= (int) $codeChangesUsed ?>).</p>
          <div class="alert alert-success small">&#10003; Your clients and orders stay yours — they are linked to you, not to the code. Links you already shared keep working: your old codes still bring the buyer to you.</div>
        </details>
        <?php if (!empty($codes)): ?>
          <h3>My codes</h3>
          <table class="list"><tr><th>Code</th><th>From</th><th>To</th><th>Status</th></tr>
            <?php foreach ($codes as $__c): ?><tr><td style="font-family:monospace"><?= htmlspecialchars((string) $__c['code']) ?></td><td><?= $__d($__c['issued_at']) ?></td><td><?= $__d($__c['retired_at']) ?></td>
              <td><?= !empty($__c['blocked_at']) ? 'Blocked by NexaCrest' : ($__c['code'] === ($me['personal_code'] ?? '') ? 'Current' : 'Still accepted (old links)') ?></td></tr><?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="section">
    <h2>Registered clients (<?= count($clients) ?>)</h2>
    <table class="list">
      <tr><th>Client</th><th>Country</th><th>Came via</th><th>Registered</th><th>Protected until</th><?php if (!empty($access['orders'])): // D-263 ?><th>Orders</th><?php endif; ?><th>10-day window ends</th><th></th></tr>
      <?php foreach ($clients as $c): ?>
        <tr><td><?= htmlspecialchars((string) $c['company_legal_name']) ?></td><td><?= htmlspecialchars((string) ($c['country_of_destination'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string) ($c['came_via'] ?? '')) ?></td><td><?= $__d($c['associate_registered_on']) ?></td><td><?= $__d($c['associate_locked_until']) ?></td>
          <?php if (!empty($access['orders'])): ?><td><?= $c['order_refs'] !== [] ? htmlspecialchars(implode(', ', $c['order_refs'])) : '<span class="muted">none yet</span>' ?></td><?php endif; ?><td><?= $__d($c['window_ends_on']) ?></td>
          <td><a class="btn-sm btn-secondary" style="white-space:nowrap" href="/associate/clients/<?= (int) $c['id'] ?>/certificate" target="_blank" rel="noopener">Registration certificate</a></td></tr>
      <?php endforeach; ?>
      <?php if ($clients === []): ?><tr><td colspan="8" class="muted">No clients yet.</td></tr><?php endif; ?>
    </table>
  </div>

  <?php if (!empty($access['registration_links'])): // D-261 ?>
  <div class="section">
    <h2>My buyer registration links and codes used</h2>
    <table class="list">
      <tr><th>Created</th><th>Company</th><th>Kind</th><th>Status</th><th>Expires</th><th></th></tr>
      <?php foreach ($invitations as $i): ?>
        <tr><td><?= $__d($i['created_at']) ?></td><td><?= htmlspecialchars(trim($i['company_name'] . ($i['country'] ? ' (' . $i['country'] . ')' : ''))) ?></td>
          <td><?= $i['kind'] === 'personal_code' ? 'Personal code' . (!empty($i['code_used']) ? ' ' . htmlspecialchars((string) $i['code_used']) : '') : 'Buyer registration link' ?></td>
          <td><?= htmlspecialchars(AssociateInvitationRepository::STATUSES[$i['status']] ?? $i['status']) ?></td><td><?= $__d($i['expires_at']) ?></td>
          <td><?php if (AssociateInvitationRepository::isUsable($i) && $i['kind'] === 'client_link'): ?><form method="post" action="/associate/invitations/<?= (int) $i['id'] ?>/cancel" data-confirm="Cancel this link?"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Cancel</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($invitations === []): ?><tr><td colspan="6" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
  </div>
  <?php endif; ?>
</div>
