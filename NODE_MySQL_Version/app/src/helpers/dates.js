'use strict';

/**
 * Port of App\Helpers\Dates. Real gap this closes: the client portal (a
 * real buyer's own login, not an internal staff screen) printed raw
 * MySQL DATETIME strings verbatim — "2026-09-21 03:26:42" — right next
 * to documents whose own PDFs render every date as "21 September 2026"
 * (documentDataAssembler's formatDate()). A client comparing their order
 * list against the document they just downloaded would see two
 * different date styles for the same system.
 */

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/**
 * 'YYYY-MM-DD' -> '21 September 2026'; a full DATETIME also gets a
 * ', HH:MM AM/PM' suffix, since a "when did this happen" timestamp
 * (order created, document generated) is more useful with a time than a
 * bare date is.
 */
function human(value) {
  if (!value) {
    return '—';
  }
  const str = String(value);
  const match = str.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (!match) {
    return str;
  }
  const [, year, month, day, hour, minute] = match;
  const datePart = `${parseInt(day, 10)} ${MONTHS[parseInt(month, 10) - 1]} ${year}`;
  if (hour === undefined) {
    return datePart;
  }
  const h = parseInt(hour, 10);
  const ampm = h >= 12 ? 'PM' : 'AM';
  const h12 = ((h + 11) % 12) + 1;
  return `${datePart}, ${String(h12).padStart(2, '0')}:${minute} ${ampm}`;
}

module.exports = { human };
