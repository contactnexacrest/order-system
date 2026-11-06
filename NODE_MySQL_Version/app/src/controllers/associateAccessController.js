'use strict';

const flash = require('../helpers/flash');
const associateRepository = require('../repositories/associateRepository');
const associateAccess = require('../services/associateAccess');
const incentiveService = require('../services/incentiveService');

/**
 * Port of App\Controllers\AssociateAccessController. D-261 (owner approval 6 Nov 2026,
 * mockups mock13): the staff side of associate portal access levels — an associate's
 * level (Portal access card), "Not yet" on the upgrade suggestion, and the Admin ›
 * Associates › Access levels matrix. Only the MD changes anything; every change needs a
 * reason and is logged.
 */
async function levels(req, res) {
  const matrix = await associateAccess.matrix();
  res.renderView('associates/access_levels', {
    matrix,
    levels: associateAccess.LEVELS,
    allLevels: associateAccess.ALL_LEVELS,
    features: Object.entries(associateAccess.FEATURES).map(([key, [label, help]]) => ({ key, label, help })),
    history: await associateAccess.history(null, 50),
    counts: await associateAccess.countsPerLevel(),
    isMd: await incentiveService.isMd(req.user),
    old: flash.pullOld(req),
  });
}

async function saveLevels(req, res) {
  const restore = (req.body.action || '') === 'restore';
  const error = await associateAccess.saveMatrix(req.body.tick || {}, String(req.body.reason ?? ''), req.user, restore);
  if (error !== null) {
    flash.set(req, 'error', error);
    flash.setOld(req, req.body);
  } else {
    flash.set(req, 'success', restore ? 'Recommended defaults restored.' : 'Level settings saved. They apply to every associate on that level at once.');
  }
  res.redirect('/associates/access-levels');
}

async function setLevel(req, res) {
  const a = await associateRepository.find(parseInt(req.params.id, 10));
  if (!a) {
    res.status(404).send('Associate not found.');
    return;
  }
  const ticks = [].concat(req.body.feature || req.body['feature[]'] || []).map(String);
  const error = await associateAccess.setLevel(a, String(req.body.access_level || ''), ticks, String(req.body.reason ?? ''), req.user);
  if (error !== null) {
    flash.set(req, 'error', error);
    flash.setOld(req, req.body);
  } else {
    flash.set(req, 'success', `Portal access changed. ${a.name} now has: ${await associateAccess.describe((await associateRepository.find(Number(a.id))) || a)}.`);
  }
  res.redirect(`/associates/${Number(a.id)}#portal-access`);
}

async function notYet(req, res) {
  const a = await associateRepository.find(parseInt(req.params.id, 10));
  if (!a) {
    res.status(404).send('Associate not found.');
    return;
  }
  const error = await associateAccess.dismissUpgrade(a, req.user);
  flash.set(req, error === null ? 'success' : 'error', error ?? 'Noted — you will be asked again after their next completed, fully paid order.');
  res.redirect(`/associates/${Number(a.id)}#portal-access`);
}

module.exports = { levels, saveLevels, setLevel, notYet };
