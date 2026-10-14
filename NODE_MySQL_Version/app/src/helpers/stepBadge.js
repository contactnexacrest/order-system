'use strict';

/**
 * Port of orders/show.php's $stepBadge closure. 90-item backlog #80: a
 * small sequential sub-step badge (1a, 1b, 2a...) placed next to each
 * stage's own primary staff actions — the literal order of operations
 * within a stage — so staff can't skip or forget a step. Purely a
 * display label: it carries no gating logic of its own, gating already
 * lives in the controller actions these buttons post to.
 */
function escapeHtmlAttr(value) {
  return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function stepBadge(label) {
  const escaped = escapeHtmlAttr(label);
  return `<span class="step-badge" title="Step ${escaped}">${escaped}</span> `;
}

module.exports = { stepBadge };
