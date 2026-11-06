'use strict';
// C06 — joined from: old C12 + C13 + C14 + C15. The parts live in scripts/parts/.
const { merge } = require('../lib/merge');
module.exports = merge({
  code: 'C06',
  file: 'C06-Associate-Messages-Changes-Privacy-And-FAQ.mp4',
  group: 'Group C · Associates',
  title: 'Messages, changes, privacy and your questions',
  subtitle: 'Talk to NexaCrest, know what happens when things change, what stays private, and quick answers.',
  minutes: '11',
}, [require('./parts/C12-Associate-Messages-And-Notices'), require('./parts/C13-Associate-When-Things-Change'), require('./parts/C14-Associate-What-You-Never-See'), require('./parts/C15-Associate-FAQ')]);
