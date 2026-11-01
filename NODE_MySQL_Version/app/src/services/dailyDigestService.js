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
  return items;
}

function compose(name, items, date) {
  const tasks = items.filter((i) => i.kind === 'task');
  const reviews = items.filter((i) => i.kind === 'review');
  const count = (n, one) => `${n} ${one}${n === 1 ? '' : 's'}`;
  const subject = `NexaCrest — your day ${dayLabel(date)}: ${count(tasks.length, 'task')}, ${count(reviews.length, 'review')}`;
  let body = `Good morning ${name},\n\nThis is what waits on you today.\n`;
  if (tasks.length) body += `\nTasks (My tasks):\n${tasks.map((i) => `- ${i.text}`).join('\n')}\n`;
  if (reviews.length) body += `\nDocuments to review (My Reviews):\n${reviews.map((i) => `- ${i.text}`).join('\n')}\n`;
  body += '\nOpen the app to act on them. You get this e-mail once a day, only when something waits on you.\n';
  return { subject, body };
}

async function run(date = null) {
  const day = date || todayIst();
  const users = await db.query("SELECT id, name, email FROM users WHERE is_active = 1 AND email IS NOT NULL AND email <> '' ORDER BY id");
  let sent = 0;
  let skipped = 0;
  for (const u of users) {
    const items = await itemsFor(Number(u.id));
    if (!items.length) { skipped++; continue; }
    // Once a day per person, even if the job runs twice.
    const claim = await db.execute('INSERT IGNORE INTO daily_digest_log (user_id, digest_date, items) VALUES (:u, :d, :n)', { u: u.id, d: day, n: items.length });
    if (claim.affectedRows !== 1) { skipped++; continue; }
    const mail = compose(String(u.name), items, day);
    await mailSenderService.send(String(u.email), mail.subject, mail.body);
    sent++;
  }
  return { sent, skipped };
}

module.exports = { itemsFor, compose, run };
