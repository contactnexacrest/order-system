'use strict';
// Shared set-up for the Group A (Everyone) overview videos. Not a video itself (starts with "_").
// The dev database is full of automated-test records with odd names, so these videos put them
// aside (cancelled and archived, inside the snapshot the runner restores afterwards) and load the
// app's own Sample Data (Settings › Sample data): fictional [SAMPLE] buyers, orders at every stage.

const ACTORS = {
  md: { label: 'Managing Director', sub: 'NexaCrest staff · md@email.com', initials: 'MD', color: '#C9962B' },
  ed: { label: 'Executive Director', sub: 'NexaCrest staff · ed@email.com', initials: 'ED', color: '#8A5A00' },
  client: (email) => ({ label: 'Buyer (client)', sub: email, initials: 'BY', color: '#1B7F4B' }),
  associate: (email) => ({ label: 'Associate', sub: email, initials: 'AS', color: '#013090' }),
};

/** Clean stage: old test data aside, Sample Data loaded, role-style contact names. Needs restoreDb: true. */
function cleanStage(demo) {
  demo.sql("UPDATE orders SET status = 'cancelled', is_locked = 1, is_archived = 1, archived_at = NOW() WHERE is_sample_data = 0");
  demo.sql("DELETE FROM email_log WHERE status IN ('pending_approval', 'approved')");
  const md = Number(demo.sql("SELECT id FROM users WHERE email = 'md@email.com'"));
  demo.sql('UPDATE users SET ui_template_preference_id = NULL WHERE id = ' + md);
  demo.php(`App\\Services\\SampleDataService::load(${md}); echo 'ok';`);
  demo.sql("UPDATE clients SET contact_person = 'Purchasing Manager' WHERE is_sample_data = 1");
  const ids = demo.sql('SELECT GROUP_CONCAT(o.id ORDER BY o.id) FROM orders o WHERE o.is_sample_data = 1').split(',').map(Number);
  return { md, ids };
}

/** Gives the sample buyer that has a portal login (Meridian) the tutorial password; returns its e-mail. */
function clientLogin(demo) {
  const row = demo.sql("SELECT CONCAT(cl.id, '|', c.email) FROM client_logins cl JOIN clients c ON c.id = cl.client_id WHERE c.is_sample_data = 1 ORDER BY cl.id LIMIT 1");
  if (!row) return null;
  const [id, email] = row.split('|');
  const h = demo.php(`echo password_hash(${JSON.stringify(require('../lib/config').PASSWORD)}, PASSWORD_DEFAULT);`);
  demo.sql(`UPDATE client_logins SET password_hash = ${demo.q(h)}, is_active = 1, force_password_change = 0, failed_login_count = 0, locked_until = NULL WHERE id = ${Number(id)}`);
  return email;
}

async function clientCookies(demo, email) {
  const cfg = require('../lib/config');
  const { chromium } = require('playwright');
  const b = await chromium.launch({ executablePath: cfg.CHROMIUM });
  try {
    const ctx = await b.newContext({ baseURL: cfg.BASE_URL });
    const p = await ctx.newPage();
    await p.goto('/client/login');
    await p.getByLabel('Email').fill(email);
    await p.getByLabel('Password').fill(cfg.PASSWORD);
    await p.getByRole('button', { name: 'Sign in' }).click();
    await require('../lib/demo').enterLoginCode(p, '/client/login/code'); // D-265: the e-mailed code
    await p.waitForURL((u) => !/\/login/.test(u.pathname));
    return await ctx.cookies();
  } finally { await b.close(); }
}

module.exports = { ACTORS, cleanStage, clientLogin, clientCookies };
