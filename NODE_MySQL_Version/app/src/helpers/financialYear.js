'use strict';

// Port of App\Helpers\FinancialYear. India's financial year (1 April - 31
// March), as a small shared utility — NOT a shared reporting engine. Both
// the order-pipeline Reports module and the CA / Accounting module's own
// reports call this for FY bucketing, but their reports, tables, and
// queries stay completely independent; sharing this date-math avoids two
// copies of "which FY does this date fall in" silently drifting apart,
// nothing more.

/** 'YYYY-MM-DD...' -> '2026-27' (a date in April 2026 through March 2027). */
function label(date) {
  const dt = new Date(date);
  const year = dt.getUTCFullYear();
  const month = dt.getUTCMonth() + 1;
  const startYear = month >= 4 ? year : year - 1;
  return `${startYear}-${String(startYear + 1).slice(-2)}`;
}

function current() {
  return label(new Date().toISOString().slice(0, 10));
}

/** '2026-27' -> { start: '2026-04-01', end: '2027-03-31' }. */
function bounds(fyLabel) {
  const startYear = parseInt(fyLabel.slice(0, 4), 10);
  return {
    start: `${String(startYear).padStart(4, '0')}-04-01`,
    end: `${String(startYear + 1).padStart(4, '0')}-03-31`,
  };
}

/**
 * Every FY label with at least one entry in `dates`, newest first — for
 * populating a "which year" picker from real data instead of an
 * arbitrary fixed range.
 */
function labelsPresentIn(dates) {
  const set = new Set(dates.map((d) => label(d)));
  return Array.from(set).sort().reverse();
}

module.exports = { label, current, bounds, labelsPresentIn };
