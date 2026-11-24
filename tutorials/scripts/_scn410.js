'use strict';
// Shared set-up for the F videos — the associate life cycle under the D-410 rules (owner request 24 Nov 2026:
// "create the video for the test scenario, for the 4 sections"). One story for all four videos:
//   TEST Scenario Associate (India) · 2 clients · 25 orders, Jan–Nov 2026 · demo product Black Galaxy Granite (demo).
// The story is built off camera by the scenario builder (scratchpad scn410tut/build.sh) on the clean tutorial
// database and saved as $TUT_WORK/scenario-ready.sql; load it before each recording (restoreDb puts it back after).
// IDs in that database: associate 1, product 1, order TEST-SCN-nn = id nn, payment advices 1–9.
const { ACTORS } = require('./_common');

const ASSOCIATE = { id: 1, email: 'scenario.associate@email.com', password: 'TutorialDemo#2026', no: 'A0001' };
const PRODUCT_ID = 1;
const order = (n) => n; // TEST-SCN-nn has id nn
const ROLE = {
  accounts: { label: 'Accounts Executive', sub: 'NexaCrest staff · accounts@email.com', initials: 'AC', color: '#1B7F4B' },
  exec: { label: 'Export Executive', sub: 'NexaCrest staff · export.executive@email.com', initials: 'EX', color: '#5A4FCF' },
};

/** Logs in the people a video needs (off camera) and returns { actors }. keys: associate, md, ed, accounts, exec. */
async function people(demo, keys) {
  const actors = {};
  for (const k of keys) {
    if (k === 'associate') actors.associate = { ...ACTORS.associate(ASSOCIATE.email), cookies: await demo.associateCookies(ASSOCIATE) };
    else if (k === 'md' || k === 'ed') actors[k] = { ...ACTORS[k], cookies: await demo.staffCookies(k) };
    else { demo.demoStaff(k); actors[k] = { ...ROLE[k], cookies: await demo.staffCookies(k) }; }
  }
  return { actors };
}

/** The story card every F video opens with (same facts each time). */
const STORY = {
  card: 'list', kicker: 'The story in this video', title: 'One associate, two clients, 25 orders',
  items: [
    'TEST Scenario Associate, based in India. Clients: TEST Scenario Buyer GmbH and TEST Second Buyer LLC.',
    '25 orders from January to November 2026. Each order is about USD 16,000 of Black Galaxy Granite (demo).',
    'Ladder: 2% (steps 1–5) · 5% (6–10) · 7% (11–20) · 10% (from 21). The client\'s price includes a fixed 5%.',
    'An order earns when its goods are worth ₹5,00,000 or more (FOB). A client\'s first order always earns.',
  ],
};

module.exports = { ASSOCIATE, PRODUCT_ID, order, people, STORY };
