'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const backupStatusService = require('../../src/services/backupStatusService');
const dailyDigestService = require('../../src/services/dailyDigestService');

/**
 * Owner decisions D-260 (6 Nov 2026): the 10-year backup facility. Port of BackupFacilityTest.php.
 * scripts/tests/backup_scripts_test.sh proves the scripts in a temp folder with fake data; the app only
 * reads their status files (Admin › Backups and the daily-digest warning).
 */
describe('D-260 backups — scripts, status, digest warning, Admin › Backups', () => {
  let dir;
  const saved = {};

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(() => {
    dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nexacrest_backup_test_'));
    fs.mkdirSync(path.join(dir, 'status'), { mode: 0o700 });
    for (const k of ['BACKUP_DIR', 'APP_ENV', 'BACKUP_ENCRYPTION_KEY_FILE']) saved[k] = process.env[k];
    process.env.BACKUP_DIR = dir;
    backupStatusService.setNow(Math.floor(Date.parse('2026-11-06T09:00:00+05:30') / 1000));
  });

  afterEach(() => {
    for (const [k, v] of Object.entries(saved)) { if (v === undefined) delete process.env[k]; else process.env[k] = v; }
    backupStatusService.setNow(null);
    fs.rmSync(dir, { recursive: true, force: true });
  });

  function writeStatus(job, result, finished, encrypted = 'yes', offsite = 'not configured') {
    fs.writeFileSync(path.join(dir, 'status', `${job}.json`), JSON.stringify({
      job, result, finished_at: finished, message: `${job} message`,
      file: `nexacrest_${job}_2026-11-06.sql.gz.enc`, size_bytes: 1536000, encrypted, offsite,
    }));
  }

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-backup-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestBackup123!';
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id)
       VALUES ('Jest Backup User', :email, NULL, :hash, :role_id, 1, 0, 0, 1)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id }
    );
    return { id: r.insertId, email, password };
  }

  it('the backup scripts self-test passes (retention, thinning, dedup, guard, live untouched, encryption)', () => {
    const script = path.join(__dirname, '../../../scripts/tests/backup_scripts_test.sh');
    expect(fs.existsSync(script)).toBe(true);
    const out = execFileSync('bash', [script], { encoding: 'utf8' });
    expect(out).toContain('0 failed');
    expect(out).toContain('PASS unchanged file stored once');
    expect(out).toContain('PASS live documents untouched');
    expect(out).toContain('PASS backup dir inside storage is refused');
  }, 60000);

  it('reports each job state and the digest warnings', () => {
    writeStatus('db', 'ok', '2026-11-06T02:00:05+0530');
    writeStatus('storage', 'ok', '2026-11-04T02:30:00+0530', 'no');
    writeStatus('restore_test', 'failed', '2026-11-01T04:00:00+0530');
    const jobs = backupStatusService.jobs();
    expect(jobs.db.state).toBe('ok');
    expect(jobs.db.age_hours).toBe(6);
    expect(jobs.storage.state).toBe('stale');
    expect(jobs.storage.message).toBe('Last good run was 2 days ago — the job has stopped running.');
    expect(jobs.restore_test.state).toBe('failed');
    expect(backupStatusService.problems()).toEqual([
      'Documents copy (nightly): Last good run was 2 days ago — the job has stopped running.',
      'Test-restore (monthly): Last run FAILED: restore_test message',
    ]);

    writeStatus('storage', 'ok', '2026-11-06T02:30:00+0530', 'no', 'failed: rclone copy returned an error');
    writeStatus('restore_test', 'ok', '2026-11-01T04:00:00+0530');
    expect(backupStatusService.problems()).toEqual([
      'Documents copy (nightly): made, but NOT encrypted (set BACKUP_ENCRYPTION_KEY_FILE).',
      'Documents copy (nightly): off-site copy failed: rclone copy returned an error.',
    ]);

    fs.unlinkSync(path.join(dir, 'status', 'db.json'));
    expect(backupStatusService.jobs().db.state).toBe('missing');

    process.env.BACKUP_DIR = '';
    process.env.APP_ENV = 'local';
    expect(backupStatusService.problems()).toEqual([]);
    process.env.APP_ENV = 'production';
    expect(backupStatusService.problems()).toEqual(['Backups are not set up: BACKUP_DIR is empty in app/.env, so no backup copies are being made.']);
    expect(backupStatusService.jobs().db.state).toBe('not_set');
  });

  it('the digest warns only those who manage company settings', async () => {
    writeStatus('db', 'failed', '2026-11-06T02:00:05+0530');
    writeStatus('storage', 'ok', '2026-11-06T02:30:00+0530');
    writeStatus('restore_test', 'ok', '2026-11-01T04:00:00+0530');
    const md = await createUser('Managing Director');
    const exec = await createUser('Export Executive');
    const mdItems = (await dailyDigestService.itemsFor(md.id)).filter((i) => i.kind === 'backup');
    expect(mdItems).toEqual([{ kind: 'backup', text: 'Database copy (nightly): Last run FAILED: db message' }]);
    expect((await dailyDigestService.itemsFor(exec.id)).filter((i) => i.kind === 'backup')).toEqual([]);
    const mail = dailyDigestService.compose('Asha', mdItems, '2026-11-06');
    expect(mail.subject).toBe('NexaCrest — your day 6 Nov 2026: 0 tasks, 0 reviews — BACKUP WARNING');
    expect(mail.body).toContain('Backups need attention (Admin › Backups):\n- Database copy (nightly): Last run FAILED: db message');
  });

  it('Admin › Backups shows the runs and settings to the MD, and refuses staff without the permission', async () => {
    writeStatus('db', 'ok', '2026-11-06T02:00:05+0530');
    writeStatus('storage', 'ok', '2026-11-06T02:30:00+0530');
    process.env.BACKUP_ENCRYPTION_KEY_FILE = '/home/x/.backup_key';
    const md = await createUser('Managing Director');
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: md.email, password: md.password });
    const res = await agent.get('/admin/backups');
    expect(res.status).toBe(200);
    expect(res.text).toContain('<h1>Backups</h1>');
    expect(res.text).toContain('data-job="db"');
    expect(res.text).toContain('1.5 MB');
    expect(res.text).toContain('No run yet');
    expect(res.text).toContain(dir);
    expect(res.text).toContain('10 years');
    expect(res.text).toContain('On (key file set');
    expect(res.text).toContain('Not chosen yet — copies stay on this server only');
    expect(res.text).not.toContain('/home/x/.backup_key');
    expect(res.text).toContain('href="/admin/backups" class="active"');

    const exec = await createUser('Export Executive');
    const a2 = request.agent(app);
    const c2 = (await a2.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await a2.post('/login').type('form').send({ _csrf: c2, email: exec.email, password: exec.password });
    expect((await a2.get('/admin/backups')).status).toBe(403);
  });
});
