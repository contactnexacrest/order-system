<div class="card">
  <h1>Removed Additional Documents — <?= htmlspecialchars($client['company_legal_name']) ?></h1>
  <p class="muted small">Soft-deleted only — the file itself is never removed from disk and can still be downloaded below.</p>
  <table class="list">
    <tr><th>Title</th><th>Notes</th><th>File</th><th>Uploaded</th><th>Removed</th><th>Reason</th></tr>
    <?php foreach ($removedDocuments as $ad): ?>
    <tr>
      <td><?= htmlspecialchars($ad['document_type_label'] ?? '') ?></td>
      <td class="muted small"><?= htmlspecialchars($ad['notes'] ?? '') ?></td>
      <td><a href="/file-store/<?= (int) $ad['id'] ?>/download"><?= htmlspecialchars($ad['original_filename']) ?></a></td>
      <td><?= htmlspecialchars((string) $ad['uploaded_at']) ?><?php if (!empty($ad['uploaded_by_name'])): ?> <span class="muted small">by <?= htmlspecialchars($ad['uploaded_by_name']) ?></span><?php endif; ?></td>
      <td><?= htmlspecialchars((string) ($ad['removed_at'] ?? '—')) ?><?php if (!empty($ad['removed_by_name'])): ?> <span class="muted small">by <?= htmlspecialchars($ad['removed_by_name']) ?></span><?php endif; ?></td>
      <td class="muted small"><?= htmlspecialchars((string) ($ad['removal_reason'] ?? '—')) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($removedDocuments)): ?>
    <tr><td colspan="6" class="muted">No additional documents have been removed from this client.</td></tr>
    <?php endif; ?>
  </table>
  <p><a href="/clients/<?= (int) $client['id'] ?>">&larr; Back to client</a></p>
</div>
