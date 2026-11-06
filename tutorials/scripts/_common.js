'use strict';
// Shared helpers for the Group C (Associates) scripts. Not a video itself (starts with "_").

const ACTORS = {
  associate: (email) => ({ label: 'Associate', sub: email, initials: 'AS', color: '#013090' }),
  md: { label: 'Managing Director', sub: 'NexaCrest staff · md@email.com', initials: 'MD', color: '#C9962B' },
  ed: { label: 'Executive Director', sub: 'NexaCrest staff · ed@email.com', initials: 'ED', color: '#8A5A00' },
  client: (email) => ({ label: 'Buyer (client)', sub: email, initials: 'BY', color: '#1B7F4B' }),
};

// The associate menu link by its visible name ("Home (1 new)" matches "Home").
const nav = (d, name) => d.page.locator('nav.topbar-nav').getByRole('link', { name, exact: false }).first();

/**
 * Standard setup: one demo associate already logged in (off camera) plus MD cookies.
 * opts are passed to demo.associate(); withMd adds the Managing Director actor.
 */
async function associateSetup(demo, opts, { withMd = true, withEd = false } = {}) {
  const a = demo.associate(opts);
  const actors = { associate: { ...ACTORS.associate(a.email), cookies: await demo.associateCookies(a) } };
  if (withMd) actors.md = { ...ACTORS.md, cookies: await demo.staffCookies('md') };
  if (withEd) actors.ed = { ...ACTORS.ed, cookies: await demo.staffCookies('ed') };
  return { a, actors };
}

// Placeholder associate for --plan (no database).
const PLAN_A = { id: 0, email: 'associate.demo@email.com', password: 'x', code: 'NXA-DEMO-AB' };

module.exports = { ACTORS, nav, associateSetup, PLAN_A };
