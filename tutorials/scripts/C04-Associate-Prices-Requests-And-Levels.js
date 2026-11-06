'use strict';
// C04 — joined from: old C06 + C07 + C08. The parts live in scripts/parts/.
const { merge } = require('../lib/merge');
module.exports = merge({
  code: 'C04',
  file: 'C04-Associate-Prices-Requests-And-Levels.mp4',
  group: 'Group C · Associates',
  title: 'Prices, special requests and access levels',
  subtitle: 'Get a price in seconds, ask for something special, and see how access levels work.',
  minutes: '9',
}, [require('./parts/C06-Associate-Get-Price'), require('./parts/C07-Associate-Price-Request-Other'), require('./parts/C08-Associate-Access-Levels')]);
