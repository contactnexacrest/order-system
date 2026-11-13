'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');

/**
 * QA round 13 Nov 2026 — security, access, data-integrity and robustness
 * fixes. Each test names the bug id from the QA register. The PHP suite has
 * the same checks in QaSecurityBatchNov2026Test.php.
 */
describe('QA Nov 2026 security batch', () => {
  const PASSWORD = 'JestQaSec!2026';

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName, kanban = false) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-qasec-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest QA Sec User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    await userRepository.updateUiTemplatePreference(id, kanban ? (await uiTemplateRepository.findByKey('kanban')).id : null);
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return { id, agent, email };
  }

  describe('UI-001 reflected XSS on Kanban boards', () => {
    it('UI-001: the Complaints board URL-encodes ?status= in "Switch to list view"', async () => {
      const md = await user('Managing Director', true);
      const res = await md.agent.get('/complaints?status=' + encodeURIComponent('"><script>window.__x=1</script>'));
      expect(res.status).toBe(200);
      expect(res.text).not.toContain('<script>window.__x=1</script>');
      expect(res.text).toContain('/complaints?view=list&amp;status=%22%3E%3Cscript%3Ewindow.__x%3D1%3C%2Fscript%3E');
    });

    it('UI-001: no view concatenates a raw variable into markup and marks it | safe', () => {
      // Pattern: ('literal' + someVar) | safe — the variable must be urlencoded/escaped first.
      const offenders = [];
      const walk = (dir) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
          const full = path.join(dir, entry.name);
          if (entry.isDirectory()) walk(full);
          else if (entry.name.endsWith('.njk')) {
            fs.readFileSync(full, 'utf8').split('\n').forEach((line, i) => {
              for (const m of line.matchAll(/\(\s*'[^']*'\s*\+\s*([A-Za-z_][\w.]*)\s*\)\s*\|\s*safe/g)) {
                offenders.push(`${path.relative(dir, full)}:${i + 1} ${m[0]}`);
              }
            });
          }
        }
      };
      walk(path.join(__dirname, '../../views'));
      expect(offenders).toEqual([]);
    });
  });
});
