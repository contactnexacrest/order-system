'use strict';
// C02 — joined from: old C02 + C03. The parts live in scripts/parts/.
const { merge } = require('../lib/merge');
module.exports = merge({
  code: 'C02',
  file: 'C02-Associate-Becoming-An-Associate.mp4',
  group: 'Group C · Associates',
  title: 'Becoming an associate',
  subtitle: 'From the registration link to an active account: profile, documents and bank, on both sides.',
  minutes: '10',
}, [require('./parts/C02-Associate-Onboarding'), require('./parts/C03-Associate-Profile-And-Bank')]);
