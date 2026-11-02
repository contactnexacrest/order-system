<?php
use App\Helpers\Csrf;
use App\Services\DocumentCopyCleanupService as Cleanup;

/** @var array{draft_days:int,superseded_days:int,cafin_mode:string} $settings */
$fmt = static fn (int $b): string => Cleanup::formatBytes($b);
$estimateLine = static function (array $e) use ($fmt): string {
    $text = 'about ' . number_format($e['files']) . ' file(s) / ' . $fmt($e['bytes']);
    return $e['hypothetical'] ? 'If switched on now: ' . $text : 'Would remove now: ' . $text;
};
?>
<div class="card">
  <h1>Document copies clean-up</h1>
  <p class="muted"><a href="/settings">&larr; Company Settings</a></p>
  <p>Every generated document keeps several copies: the <strong>DRAFT</strong> copy made when it is generated, the <strong>final</strong> copy made when it is approved (the one sent to the buyer), a <strong>SUPERSEDED</strong> copy when a newer revision is approved, an internal Word copy, and every <strong>CAFIN</strong> generated. Switch on the rules below to remove the copies nobody needs. Everything is <strong>off</strong> until you switch it on.</p>

  <div class="alert alert-info">
    <strong>Never removed, whatever is switched on:</strong>
    <ul>
      <li>anything sent to the client (e-mailed, counted as sent with e-mail off, or downloaded in the client portal);</li>
      <li>the current copy of every document (the approved / sent copy, or the latest draft still in review);</li>
      <li>the internal Word copy of a document that is still current;</li>
      <li>uploaded files — signed copies, bills, payment screenshots, the buyer's PO, BL scans …;</li>
      <li>every file of an order with an open dispute;</li>
      <li>documents generated in a financial year the CA has locked.</li>
    </ul>
    A removed copy cannot be restored. Each removal is recorded in the Audit Log (<code>DOCUMENT_COPY_REMOVED</code>, with the document, file name, size and reason).
  </div>

  <form method="post" action="/settings/document-cleanup">
    <?= Csrf::field() ?>
    <fieldset>
      <legend>Rules</legend>

      <label>Draft copies of approved documents
        <select name="draft_days" required>
          <?php foreach (Cleanup::DRAFT_OPTIONS as $d): ?>
            <option value="<?= $d ?>"<?= $settings['draft_days'] === $d ? ' selected' : '' ?>><?= $d === 0 ? 'Off — keep every draft' : "Remove {$d} days after approval" ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="muted small">Removes only the DRAFT-watermarked PDF once the final copy exists. Keeps the final copy, the Word copy and every sent copy. <?= htmlspecialchars($estimateLine($estimate['draft'])) ?>.</p>

      <label>Superseded revisions never sent to the client
        <select name="superseded_days" required>
          <?php foreach (Cleanup::SUPERSEDED_OPTIONS as $d): ?>
            <option value="<?= $d ?>"<?= $settings['superseded_days'] === $d ? ' selected' : '' ?>><?= $d === 0 ? 'Off — keep every copy' : "Remove {$d} days after it was superseded" ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="muted small">For an older revision the client never received: removes its draft, final and Word copies, and keeps the one SUPERSEDED-stamped copy so the revision history still opens. A revision that was sent is never touched. <?= htmlspecialchars($estimateLine($estimate['superseded'])) ?>.</p>

      <label>Old CAFIN copies
        <select name="cafin_mode" required>
          <option value="keep_all"<?= $settings['cafin_mode'] === 'keep_all' ? ' selected' : '' ?>>Keep all</option>
          <option value="latest_only"<?= $settings['cafin_mode'] === 'latest_only' ? ' selected' : '' ?>>Keep only the latest per order (older removed <?= Cleanup::CAFIN_DAYS ?> days after a newer one was made)</option>
        </select>
      </label>
      <p class="muted small">CAFIN is regenerated whenever the figures change; only the latest one is correct. <?= htmlspecialchars($estimateLine($estimate['cafin_old'])) ?>.</p>

      <label class="full">Reason for the change (needed when a rule changes)
        <textarea name="reason" rows="2" minlength="10" placeholder="At least 10 characters — e.g. Save storage: drafts are not needed once approved"></textarea>
      </label>
      <p class="muted small">A reason is needed when a rule changes; every change is in the Audit Log.</p>
      <button type="submit" class="btn">Save</button>
    </fieldset>
  </form>
</div>

<div class="card">
  <h2>Preview what would be removed</h2>
  <?php if (!Cleanup::anyOn($settings)): ?>
    <p class="muted">Every rule is off — nothing will be removed. The estimates above show what each rule would remove if you switched it on.</p>
  <?php elseif ($candidateCount === 0): ?>
    <p class="muted">Nothing to remove right now with the rules above.</p>
  <?php else: ?>
    <p><strong><?= number_format($candidateCount) ?> copy(ies), <?= htmlspecialchars($fmt($candidateBytes)) ?></strong> would be removed now<?= $candidateCount > $previewLimit ? ' — the first ' . $previewLimit . ' are listed' : '' ?>. The daily job removes them automatically; or run it now.</p>
    <details>
      <summary>Show the list</summary>
      <table class="table">
        <thead><tr><th>Order</th><th>Document</th><th>Rev.</th><th>File</th><th>Size</th><th>Why</th></tr></thead>
        <tbody>
        <?php foreach ($candidates as $c): ?>
          <tr>
            <td><?php if ($c['order_id']): ?><a href="/orders/<?= (int) $c['order_id'] ?>">#<?= (int) $c['order_id'] ?></a><?php else: ?>—<?php endif; ?></td>
            <td><?= htmlspecialchars((string) $c['document_type_code']) ?> <?= htmlspecialchars((string) ($c['document_reference'] ?? '—')) ?></td>
            <td><?= (int) $c['revision_number'] ?></td>
            <td><?= htmlspecialchars((string) $c['original_filename']) ?></td>
            <td><?= htmlspecialchars($fmt((int) $c['file_size_bytes'])) ?></td>
            <td><?= htmlspecialchars((string) $c['reason']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </details>
    <form method="post" action="/settings/document-cleanup/run" onsubmit="return confirm('Remove these <?= (int) $candidateCount ?> copies now? This cannot be undone.');">
      <?= Csrf::field() ?>
      <label><input type="checkbox" name="confirm_permanent" value="1" required> I understand removed copies cannot be restored *</label>
      <button type="submit" class="btn btn-danger">Run clean-up now</button>
    </form>
  <?php endif; ?>
</div>
