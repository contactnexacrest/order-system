<?php
/**
 * Owner decision D-320 (point 26): the menu bar at the top of every page of a
 * section — one shared partial, drawn by layout/base.php for both the
 * Standard and the Kanban template (Node twin: partials/section_nav.njk).
 * Expects $__bar from App\Services\Navigation::build(). Items with sub-pages
 * open a small drop-down; a count shows where something waits.
 */
$__secCount = static function (array $item): string {
    return (int) ($item['count'] ?? 0) > 0 ? ' <span class="nav-badge">' . (int) $item['count'] . '</span>' : '';
};
?>
<nav class="section-nav" aria-label="<?= htmlspecialchars($__bar['label']) ?> menu" data-section="<?= htmlspecialchars($__bar['key']) ?>">
  <span class="section-nav-title"><?= htmlspecialchars($__bar['label']) ?></span>
  <ul class="section-nav-items">
    <?php foreach ($__bar['items'] as $__item): ?>
      <?php if (isset($__item['children'])): ?>
        <li class="section-nav-drop<?= $__item['active'] ? ' active' : '' ?>">
          <details>
            <summary><?= htmlspecialchars($__item['label']) ?><?= $__secCount($__item) ?> <span aria-hidden="true">&#9662;</span></summary>
            <ul class="section-nav-menu">
              <?php foreach ($__item['children'] as $__child): ?>
                <li><a href="<?= htmlspecialchars($__child['href']) ?>"<?= $__child['active'] ? ' class="active" aria-current="page"' : '' ?><?= !empty($__child['target']) ? ' target="' . htmlspecialchars((string) $__child['target']) . '" rel="noopener"' : '' ?>><?= htmlspecialchars($__child['label']) ?><?= $__secCount($__child) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </details>
        </li>
      <?php else: ?>
        <li><a href="<?= htmlspecialchars($__item['href']) ?>"<?= $__item['active'] ? ' class="active" aria-current="page"' : '' ?><?= !empty($__item['target']) ? ' target="' . htmlspecialchars((string) $__item['target']) . '" rel="noopener"' : '' ?>><?= htmlspecialchars($__item['label']) ?><?= $__secCount($__item) ?></a></li>
      <?php endif; ?>
    <?php endforeach; ?>
  </ul>
  <?php foreach ($__bar['crossLinks'] as $__cross): ?>
    <a class="btn-sm btn-secondary section-nav-cross" href="<?= htmlspecialchars($__cross['href']) ?>"><?= htmlspecialchars($__cross['label']) ?></a>
  <?php endforeach; ?>
</nav>
