<?php
use App\Helpers\Csrf;
/**
 * Owner decision D-394 (mockup 10): "Update this prospect" — the request's
 * details go onto the existing prospect (same id, Buyer Inquiry Ref and orders).
 * Every change is shown old → new before anything is saved.
 */
?>
<div class="card page-wide">
  <p class="muted small"><a href="/client-intake">&larr; Back to Quotation requests</a></p>
  <h1>Update this prospect</h1>
  <p>
    Request from <strong><?= htmlspecialchars((string) $submission['company_legal_name']) ?></strong> (<?= htmlspecialchars((string) $submission['email']) ?>)
    matches the prospect <a href="/clients/<?= (int) $client['id'] ?>"><?= htmlspecialchars((string) $client['company_legal_name']) ?></a>
    (Buyer Inquiry Ref <?= htmlspecialchars((string) $client['client_unique_number']) ?><?= (int) $client['is_active'] === 1 ? '' : ', deactivated' ?>).
  </p>
  <p class="muted">Saving fills in the prospect from the request. It keeps its Buyer Inquiry Ref and its orders, the Prospect badge goes (it then never expires on its own)<?= (int) $client['is_active'] === 1 ? '' : ', it is activated again' ?>, and the request is marked accepted and linked to it. Nothing is deleted.</p>

  <?php if ($refusal !== null): ?>
    <div class="review-banner warn">&#9888; <span><?= htmlspecialchars($refusal) ?></span></div>
  <?php endif; ?>

  <table class="list" data-testid="prospect-changes">
    <tr><th>Detail</th><th>Now</th><th></th><th>After saving</th></tr>
    <?php foreach ($changes as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['label']) ?></td>
        <td class="muted"><?= $c['old'] !== '' ? htmlspecialchars($c['old']) : '<em>empty</em>' ?></td>
        <td>&rarr;</td>
        <td><strong><?= $c['new'] !== '' ? htmlspecialchars($c['new']) : '<em>empty</em>' ?></strong></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($changes)): ?>
      <tr><td colspan="4" class="muted">No detail changes — the request holds the same details as the prospect.</td></tr>
    <?php endif; ?>
  </table>

  <div class="btn-row">
    <?php if ($refusal === null): ?>
    <form method="post" action="/client-intake/<?= (int) $submission['id'] ?>/update-prospect" style="display:inline" onsubmit="return confirm('Update <?= htmlspecialchars(addslashes((string) $client['company_legal_name'])) ?> with these details?');">
      <?= Csrf::field() ?>
      <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
      <button type="submit" class="btn-sm btn-success">Update this prospect</button>
    </form>
    <?php endif; ?>
    <a class="btn-sm btn-secondary" href="/client-intake">Cancel</a>
  </div>
</div>
