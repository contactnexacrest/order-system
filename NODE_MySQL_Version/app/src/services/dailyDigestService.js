'use strict';

const db = require('../config/db');
const { todayIst } = require('../helpers/dbDateTime');
const departmentRepository = require('../repositories/departmentRepository');
const departmentTaskRepository = require('../repositories/departmentTaskRepository');
const documentReviewRepository = require('../repositories/documentReviewRepository');
const mailSenderService = require('./mailSenderService');

/**
 * Port of DailyDigestService.php. Owner decision 1 Nov 2026 (C-7b, O-7):
 * one e-mail per person per day listing what waits on them — the
 * department tasks they own or that sit unowned with their department
 * (overdue and escalated first), and the documents waiting for their
 * review. Review requests still arrive at once (C-7c); the digest only
 * repeats them. Nobody with nothing waiting gets an e-mail.
 */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const dayLabel = (ymd) => { const [y, m, d] = String(ymd).slice(0, 10).split('-').map(Number); return `${d} ${MONTHS[m - 1]} ${y}`; };

async function itemsFor(userId) {
  const departmentIds = (await departmentRepository.activeMembershipsForUser(userId)).map((m) => Number(m.department_id));
  const tasks = (await departmentTaskRepository.open()).filter((t) => Number(t.owner_id || 0) === Number(userId)
    || ((t.owner_id === null || t.owner_id === undefined) && departmentIds.includes(Number(t.department_id || 0))));
  tasks.sort((a, b) => (Number(b.escalated) - Number(a.escalated)) || (Number(b.overdue) - Number(a.overdue)) || String(a.due_on).localeCompare(String(b.due_on)));
  const items = tasks.map((t) => {
    const state = Number(t.escalated) === 1 ? 'ESCALATED' : (Number(t.overdue) === 1 ? 'OVERDUE' : 'due');
    return { kind: 'task', text: `${t.order_reference} — ${t.title} (${state} ${dayLabel(t.due_on)})${t.owner_id === null || t.owner_id === undefined ? ' — no owner yet' : ''}` };
  });
  for (const r of await documentReviewRepository.pendingForReviewer(userId)) {
    items.push({ kind: 'review', text: `${r.document_reference} (${r.document_type_name}) — waiting for your review` });
  }
  // D-260: a missing, failed or too-old backup goes to whoever manages company settings (MD / Admin).
  const u = await db.queryOne('SELECT role_id FROM users WHERE id = :id', { id: userId });
  const perms = await require('./permissionService').load(userId, u && u.role_id !== null ? Number(u.role_id) : null);
  if (perms.manage_company_settings) {
    for (const problem of require('./backupStatusService').problems()) items.push({ kind: 'backup', text: problem });
  }
  return items;
}

function compose(name, items, date) {
  const tasks = items.filter((i) => i.kind === 'task');
  const reviews = items.filter((i) => i.kind === 'review');
  const count = (n, one) => `${n} ${one}${n === 1 ? '' : 's'}`;
  const backups = items.filter((i) => i.kind === 'backup');
  const subject = `NexaCrest — your day ${dayLabel(date)}: ${count(tasks.length, 'task')}, ${count(reviews.length, 'review')}${backups.length ? ' — BACKUP WARNING' : ''}`;
  let body = `Good morning ${name},\n\nThis is what waits on you today.\n`;
  if (tasks.length) body += `\nTasks (My tasks):\n${tasks.map((i) => `- ${i.text}`).join('\n')}\n`;
  if (reviews.length) body += `\nDocuments to review (My Reviews):\n${reviews.map((i) => `- ${i.text}`).join('\n')}\n`;
  if (backups.length) body += `\nBackups need attention (Admin › Backups):\n${backups.map((i) => `- ${i.text}`).join('\n')}\n`;
  body += '\nOpen the app to act on them. You get this e-mail once a day, only when something waits on you.\n';
  return { subject, body };
}

/**
 * QA Round 8, R8-N02: the line the cron job prints — plain about mail being
 * off, and about digests that could not be sent.
 */
function summary(result) {
  if (result.mailOff) return 'Daily digest: e-mail sending is off — nothing sent.';
  return `Daily digest: ${result.sent} sent, ${result.skipped} with nothing waiting or already sent`
    + (result.failed > 0 ? `, ${result.failed} not sent (will be retried on the next run)` : '') + '.';
}

// Tests only: replaces the real mail sender (null restores it).
let sender = null;
function setSender(fn) {
  sender = fn;
}

/**
 * Sends today's digest to every active staff member with something waiting,
 * once per day. QA Round 8, R8-N02: only a digest that really left counts as
 * sent and uses up the day — with e-mail sending off nothing is claimed, and
 * a failed send is released so the next run tries again.
 */
async function run(date = null) {
  const day = date || todayIst();
  if (!sender && (await require('../repositories/companySettingsRepository').get('mail_sending_enabled')) === '0') {
    return { sent: 0, skipped: 0, failed: 0, mailOff: true };
  }
  const users = await db.query("SELECT id, name, email FROM users WHERE is_active = 1 AND email IS NOT NULL AND email <> '' ORDER BY id");
  let sent = 0;
  let skipped = 0;
  let failed = 0;
  for (const u of users) {
    const items = await itemsFor(Number(u.id));
    if (!items.length) { skipped++; continue; }
    // Once a day per person, even if the job runs twice.
    const claim = await db.execute('INSERT IGNORE INTO daily_digest_log (user_id, digest_date, items) VALUES (:u, :d, :n)', { u: u.id, d: day, n: items.length });
    if (claim.affectedRows !== 1) { skipped++; continue; }
    const mail = compose(String(u.name), items, day);
    const ok = sender ? await sender(String(u.email), mail.subject, mail.body) : await mailSenderService.send(String(u.email), mail.subject, mail.body);
    if (ok) {
      sent++;
    } else {
      await db.execute('DELETE FROM daily_digest_log WHERE user_id = :u AND digest_date = :d', { u: u.id, d: day });
      failed++;
    }
  }
  return { sent, skipped, failed, mailOff: false };
}

module.exports = { itemsFor, compose, run, setSender, summary };
