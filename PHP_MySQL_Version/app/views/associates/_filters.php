<?php
/** Owner hard rule 2026-11-01: the same filters on the list and on the board. */
$__q = $q !== '' ? '&amp;q=' . rawurlencode($q) : '';
$__view = !empty($listOfBoard) ? '&amp;view=list' : '';
?>
<div class="filter-chip-row">
  <a class="filter-chip<?= $status === 'all' ? ' active' : '' ?>" href="/associates?status=all<?= $__view . $__q ?>">All (<?= array_sum($counts) ?>)</a>
  <?php foreach (\App\Repositories\AssociateRepository::STATUSES as $key => $label): if ($key === 'inactive' && $counts['inactive'] === 0) { continue; } ?>
    <a class="filter-chip<?= $status === $key ? ' active' : '' ?>" href="/associates?status=<?= $key . $__view . $__q ?>"><?= htmlspecialchars($label) ?> (<?= (int) $counts[$key] ?>)</a>
  <?php endforeach; ?>
</div>
<form method="get" action="/associates" class="inline-form" style="margin:8px 0">
  <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
  <?php if (!empty($listOfBoard)): ?><input type="hidden" name="view" value="list"><?php endif; ?>
  <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" maxlength="100" placeholder="Name, e-mail, code or country" aria-label="Search associates">
  <button type="submit" class="btn-sm">Search</button>
</form>
<?php if ($canBank && $pendingBank > 0): ?><div class="alert alert-warning"><?= (int) $pendingBank ?> bank-detail change(s) wait for checking — open the associate, section "Bank details".</div><?php endif; ?>
