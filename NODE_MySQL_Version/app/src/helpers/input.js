'use strict';

/**
 * External QA report R-06/R-15: a scalar form field read as
 * `String(req.body.name || '').trim()` never rejected array-shaped
 * input — posting `name[]=a&name[]=b` makes Express's own urlencoded
 * parser hand back a real array, and `String(['a', 'b'])` silently
 * joins it into `"a,b"`, which then sailed straight past the empty
 * check and got stored as real data (a role literally named "a,b", a
 * compliance task type literally named "a,b"). This helper rejects
 * array input explicitly instead, returning fallback so the caller's
 * own existing empty-value handling takes over — exactly as if the
 * field had been left blank.
 */
function scalarString(value, fallback = '') {
  if (Array.isArray(value)) {
    return fallback;
  }
  return String(value == null ? fallback : value).trim();
}

module.exports = { scalarString };
