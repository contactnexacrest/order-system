<?php
/**
 * Owner decision D-394: the request's e-mail is a prospect's — the tag and the
 * "Update this prospect" link. Shared by the list (index.php) and the board
 * (index_kanban.php), so both offer the same (owner hard rule 2026-11-01).
 * Expects $s (a pending submission with 'prospect_match').
 */
$__pm = $s['prospect_match'] ?? null;
if ($__pm):
?>
  <div class="prospect-match" data-testid="prospect-match">
    <span class="tag tag-gold">Matches prospect</span>
    <a href="/clients/<?= (int) $__pm['id'] ?>"><?= htmlspecialchars((string) $__pm['company_legal_name']) ?></a>
    <span class="muted small">(same e-mail <?= htmlspecialchars((string) $__pm['email']) ?><?= (int) $__pm['is_active'] === 1 ? '' : ' · deactivated' ?>)</span>
    <a class="btn-sm btn-secondary" href="/client-intake/<?= (int) $s['id'] ?>/update-prospect">Update this prospect</a>
  </div>
<?php endif; ?>
