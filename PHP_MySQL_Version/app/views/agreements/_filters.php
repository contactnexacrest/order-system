<?php
/** Owner hard rule 2026-11-01: the same filters on the list and on the board. */
$__base = static function (array $over) use ($f, $listOfBoard): string {
    $p = array_filter(array_merge(['status' => $f['status'], 'party' => $f['party'], 'type' => $f['type'], 'within' => $f['within'], 'q' => $f['q']], $over, !empty($listOfBoard) ? ['view' => 'list'] : []), static fn ($v): bool => $v !== '' && $v !== null);
    return '/agreements?' . htmlspecialchars(http_build_query($p));
};
?>
<div class="filter-chip-row">
  <a class="filter-chip<?= $f['status'] === 'all' ? ' active' : '' ?>" href="<?= $__base(['status' => 'all']) ?>">All (<?= array_sum($counts) ?>)</a>
  <?php foreach (\App\Repositories\AgreementRepository::STATUSES as $key => $label): if ($key === 'renewed' && $counts['renewed'] === 0) { continue; } ?>
    <a class="filter-chip<?= $f['status'] === $key ? ' active' : '' ?>" href="<?= $__base(['status' => $key]) ?>"><?= htmlspecialchars($label) ?> (<?= (int) $counts[$key] ?>)</a>
  <?php endforeach; ?>
</div>
<form method="get" action="/agreements" class="inline-form" style="margin:8px 0;display:flex;flex-wrap:wrap;gap:8px 12px;align-items:flex-end">
  <input type="hidden" name="status" value="<?= htmlspecialchars($f['status']) ?>">
  <?php if (!empty($listOfBoard)): ?><input type="hidden" name="view" value="list"><?php endif; ?>
  <label>With
    <select name="party" style="width:auto">
      <option value="">Everyone</option>
      <?php foreach (\App\Repositories\AgreementRepository::PARTY_TYPES as $k => $label): if ($k === 'staff' && empty($canManage)) { continue; } ?>
        <option value="<?= $k ?>"<?= $f['party'] === $k ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Type
    <select name="type" style="width:auto">
      <option value="">All types</option>
      <?php foreach ($types as $t): ?><option value="<?= htmlspecialchars($t) ?>"<?= $f['type'] === $t ? ' selected' : '' ?>><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>Expiring within
    <select name="within" style="width:auto">
      <option value="">Any time</option>
      <?php foreach (['7', '30', '60', '90'] as $d): ?><option value="<?= $d ?>"<?= $f['within'] === $d ? ' selected' : '' ?>><?= $d ?> days</option><?php endforeach; ?>
    </select>
  </label>
  <input type="search" name="q" value="<?= htmlspecialchars($f['q']) ?>" maxlength="100" placeholder="Name, type, title or note" aria-label="Search agreements" style="width:auto;min-width:220px">
  <button type="submit" class="btn-sm">Filter</button>
</form>
