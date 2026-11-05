<?php
use App\Helpers\Csrf;
use App\Repositories\AssociateRegistrationRepository;

$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
$__linkState = static function (array $l): string {
    if (!empty($l['used_at'])) {
        return 'Used';
    }
    if (!empty($l['cancelled_at'])) {
        return 'Cancelled';
    }
    return strtotime((string) $l['expires_at']) < time() ? 'Expired' : 'Open';
};
?>
<div class="card page-wide">
  <h1>Associate registrations</h1>
  <p class="muted">Owner decision D-253: send a one-use <b>associate registration link</b> to a person who wants to become an associate. They fill in a short form; you accept (they become an associate, status Invited, and get their portal login link) or reject with a reason (e-mailed to them).</p>

  <?php if (!empty($newLink)): ?>
    <div class="alert alert-success">Associate registration link — copy it and send it to the person (valid <?= (int) $linkDays ?> days, one registration):<br><code style="user-select:all"><?= htmlspecialchars($newLink) ?></code></div>
  <?php endif; ?>

  <div class="section">
    <h2>Create an associate registration link</h2>
    <form method="post" action="/associates/registrations/links" class="inline-form">
      <?= Csrf::field() ?>
      <label>For whom (note, optional)<input type="text" name="note" maxlength="200" placeholder="e.g. Rajesh, met at Stona 2026"></label>
      <button type="submit" class="btn-sm">Create link</button>
    </form>
    <form method="post" action="/associates/registrations/settings" class="inline-form">
      <?= Csrf::field() ?>
      <label>New links are valid for (days) *<input type="number" name="link_days" required min="1" max="90" step="1" value="<?= (int) $linkDays ?>" placeholder="e.g. 7"></label>
      <button type="submit" class="btn-sm btn-secondary">Save</button>
    </form>
  </div>

  <div class="section">
    <h2>Registrations</h2>
    <p><?php foreach (['pending' => 'Waiting', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'all' => 'All'] as $__s => $__l): ?><a class="btn-sm <?= $status === $__s ? '' : 'btn-secondary' ?>" href="/associates/registrations?status=<?= $__s ?>"><?= $__l ?></a> <?php endforeach; ?></p>
    <table class="list">
      <tr><th>Received</th><th>Name</th><th>E-mail · phone</th><th>Based in · city</th><th>Status</th><th>Review</th></tr>
      <?php foreach ($registrations as $r): ?>
        <tr><td><?= $__d($r['created_at']) ?></td><td><?= htmlspecialchars((string) $r['name']) ?></td>
          <td><?= htmlspecialchars((string) $r['email']) ?><br><small><?= htmlspecialchars((string) $r['phone']) ?></small></td>
          <td><?= $r['based_in'] === 'india' ? 'India' : 'Abroad' ?> · <?= htmlspecialchars($r['city'] . ', ' . $r['country']) ?></td>
          <td><?= htmlspecialchars(AssociateRegistrationRepository::STATUSES[$r['status']] ?? $r['status']) ?>
            <?php if (!empty($r['reviewed_at'])): ?><br><small class="muted"><?= htmlspecialchars((string) ($r['reviewed_by_name'] ?? '')) ?>, <?= $__d($r['reviewed_at']) ?><?= !empty($r['reject_reason']) ? ' — ' . htmlspecialchars((string) $r['reject_reason']) : '' ?></small><?php endif; ?>
            <?php if (!empty($r['associate_id'])): ?><br><a href="/associates/<?= (int) $r['associate_id'] ?>">Open the associate</a><?php endif; ?></td>
          <td><?php if ($r['status'] === 'pending'): ?>
            <form method="post" action="/associates/registrations/<?= (int) $r['id'] ?>/accept" data-confirm="Accept <?= htmlspecialchars((string) $r['name']) ?> as an associate and e-mail the portal login link?" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm">Accept</button></form>
            <form method="post" action="/associates/registrations/<?= (int) $r['id'] ?>/reject" class="inline-form">
              <?= Csrf::field() ?>
              <input type="text" name="reason" required minlength="10" maxlength="300" placeholder="Reason (e-mailed to them)" aria-label="Reason for rejecting">
              <button type="submit" class="btn-sm btn-secondary">Reject</button>
            </form>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($registrations === []): ?><tr><td colspan="6" class="muted">None.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Links</h2>
    <table class="list">
      <tr><th>Created</th><th>By</th><th>For</th><th>Expires</th><th>State</th><th></th></tr>
      <?php foreach ($links as $l): ?>
        <tr><td><?= $__d($l['created_at']) ?></td><td><?= htmlspecialchars((string) $l['created_by_name']) ?></td><td><?= htmlspecialchars((string) ($l['note'] ?? '')) ?></td><td><?= $__d($l['expires_at']) ?></td><td><?= $__linkState($l) ?></td>
          <td><?php if ($__linkState($l) === 'Open'): ?><form method="post" action="/associates/registrations/links/<?= (int) $l['id'] ?>/cancel" data-confirm="Cancel this link?"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Cancel</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($links === []): ?><tr><td colspan="6" class="muted">None yet.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
