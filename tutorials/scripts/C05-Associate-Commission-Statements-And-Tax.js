'use strict';
// C05 — joined from: old C09 + C10 + C11. The parts live in scripts/parts/.
const { merge } = require('../lib/merge');
module.exports = merge({
  code: 'C05',
  file: 'C05-Associate-Commission-Statements-And-Tax.mp4',
  group: 'Group C · Associates',
  title: 'Commission, statements, TDS and GST',
  subtitle: 'How your commission is worked out and paid, your statements, and the tax on it, in plain words.',
  minutes: '10',
}, [require('./parts/C09-Associate-Commission-Life-Cycle'), require('./parts/C10-Associate-Statements'), require('./parts/C11-Associate-TDS-And-GST')]);
