'use strict';
// C03 — joined from: old C04 + C05. The parts live in scripts/parts/.
const { merge } = require('../lib/merge');
module.exports = merge({
  code: 'C03',
  file: 'C03-Associate-Starter-Level-And-First-Client.mp4',
  group: 'Group C · Associates',
  title: 'The Starter level and your first client',
  subtitle: 'Every Starter page explained, then your buyer registration link and personal code in action.',
  minutes: '9',
}, [require('./parts/C04-Associate-Starter-Tour'), require('./parts/C05-Associate-Inviting-Clients')]);
