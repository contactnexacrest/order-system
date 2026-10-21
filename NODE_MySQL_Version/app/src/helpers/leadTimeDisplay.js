'use strict';

/**
 * Port of App\Helpers\LeadTimeDisplay. 90-item backlog item 16: formats a
 * lead-time day-count range for display as either days (the stored
 * unit, unchanged) or weeks, per the `lead_time_display_unit` company
 * setting. Display only — every stored value and every order form's
 * input field stays in days regardless of this setting; only the Order
 * Detail page's rendered text changes.
 */

function daysToWeeks(days) {
  const weeks = (Math.round((days / 7) * 10) / 10).toFixed(1);
  return weeks.endsWith('.0') ? weeks.slice(0, -2) : weeks;
}

function formatRange(minDays, maxDays, unit) {
  const min = minDays === null || minDays === undefined ? null : minDays;
  const max = maxDays === null || maxDays === undefined ? null : maxDays;
  if (min === null && max === null) {
    return '—';
  }

  if (unit === 'weeks') {
    const minStr = min !== null ? daysToWeeks(min) : '?';
    const maxStr = max !== null ? daysToWeeks(max) : '?';
    return `${minStr}–${maxStr}`;
  }

  const minStr = min !== null ? String(min) : '?';
  const maxStr = max !== null ? String(max) : '?';
  return `${minStr}–${maxStr}`;
}

module.exports = { formatRange };
