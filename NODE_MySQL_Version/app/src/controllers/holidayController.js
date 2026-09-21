'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const companyHolidayRepository = require('../repositories/companyHolidayRepository');

/**
 * Port of App\Controllers\HolidayController — admin screen for
 * company_holidays, the specific dated holidays workingDaysCalculator
 * skips when computing a "working days" deadline. Deliberately a flat
 * list of exact dates rather than a recurring-rule engine: a public
 * holiday's actual date changes every year (Diwali, Eid, etc.), so
 * re-entering each year's real dates is both simpler and more correct
 * than a "same day every year" rule that would drift wrong for lunar/
 * lunisolar holidays.
 */

async function index(req, res) {
  res.renderView('holidays/index', { holidays: await companyHolidayRepository.all() }, 'layout/base');
}

async function create(req, res) {
  const date = String(req.body.holiday_date || '').trim();
  const description = String(req.body.description || '').trim();

  if (date === '' || description === '') {
    flash.set(req, 'error', 'Both a date and a description are required.');
    res.redirect('/holidays');
    return;
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || Number.isNaN(new Date(`${date}T00:00:00Z`).getTime())) {
    flash.set(req, 'error', 'Invalid date.');
    res.redirect('/holidays');
    return;
  }

  let id;
  try {
    id = await companyHolidayRepository.create(date, description, req.user.id);
  } catch (err) {
    if (err && err.code === 'ER_DUP_ENTRY') {
      flash.set(req, 'error', 'That date is already on the holiday calendar.');
      res.redirect('/holidays');
      return;
    }
    throw err;
  }
  await auditLogRepository.log(req.user.id, 'HOLIDAY_ADDED', 'company_holidays', id, null, null, `${date}: ${description}`);
  flash.set(req, 'success', `Holiday added: ${date} — ${description}.`);
  res.redirect('/holidays');
}

async function remove(req, res) {
  const id = parseInt(req.params.id, 10);
  await companyHolidayRepository.remove(id);
  await auditLogRepository.log(req.user.id, 'HOLIDAY_REMOVED', 'company_holidays', id);
  flash.set(req, 'success', 'Holiday removed.');
  res.redirect('/holidays');
}

module.exports = { index, create, remove };
