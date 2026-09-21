'use strict';

const companyHolidayRepository = require('../repositories/companyHolidayRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');

const WEEKDAY_NAMES = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

/**
 * A "working day" is any calendar day that is neither a configured weekly
 * off-day (company_settings.weekly_off_days — NexaCrest's Mon-Sat week
 * means Sunday only, by default) nor a specific date in company_holidays.
 * Used anywhere the business genuinely means "N working days" rather than
 * "N calendar days" — e.g. the Dispute Resolution clause's contractual
 * "ten (10) working days" response deadline. Counting a Sunday or a
 * national holiday as a working day would understate a legal deadline
 * that's printed, word-for-word, on buyer-facing documents.
 */

/**
 * The date `days` working days after `startDate` — `startDate` itself is
 * never counted (mirrors how a "10 working days from notice" deadline is
 * read in the seeded T&C clause: the notice day itself doesn't count as
 * day one of the response window). Dates are 'YYYY-MM-DD' strings.
 */
async function addWorkingDays(startDate, days) {
  const offDays = await weeklyOffDayNames();
  let cursor = new Date(`${startDate}T00:00:00Z`);

  // Pull the whole holiday window in one query instead of one query per
  // candidate day — a 10-working-day span is at most ~14 calendar days,
  // but this keeps the same shape correct even for a much longer span.
  const lookaheadDays = days * 3 + 30;
  const lookahead = new Date(cursor);
  lookahead.setUTCDate(lookahead.getUTCDate() + lookaheadDays);
  const holidaySet = new Set(await companyHolidayRepository.datesBetween(toDateString(cursor), toDateString(lookahead)));

  let remaining = days;
  while (remaining > 0) {
    cursor.setUTCDate(cursor.getUTCDate() + 1);
    const weekday = WEEKDAY_NAMES[cursor.getUTCDay()];
    if (offDays.includes(weekday)) {
      continue;
    }
    if (holidaySet.has(toDateString(cursor))) {
      continue;
    }
    remaining--;
  }

  return toDateString(cursor);
}

async function isWorkingDay(date) {
  const offDays = await weeklyOffDayNames();
  const d = new Date(`${date}T00:00:00Z`);
  if (offDays.includes(WEEKDAY_NAMES[d.getUTCDay()])) {
    return false;
  }
  const holidays = await companyHolidayRepository.datesBetween(date, date);
  return holidays.length === 0;
}

async function weeklyOffDayNames() {
  const raw = (await companySettingsRepository.get('weekly_off_days')) || 'sunday';
  return raw.toLowerCase().split(',').map((d) => d.trim()).filter((d) => d !== '');
}

function toDateString(date) {
  return date.toISOString().slice(0, 10);
}

module.exports = { addWorkingDays, isWorkingDay };
