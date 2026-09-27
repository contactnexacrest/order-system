'use strict';

const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');
const clientPortalService = require('../../src/services/clientPortalService');
const { createTestClient } = require('../support/fixtures');

/**
 * QA-5 (CP-08 — external QA report cross-verification): clients.email has
 * no uniqueness constraint, so two different client records can end up
 * sharing the same address (a data-entry mistake, or an edit). Before this
 * fix, clientLoginRepository.findByEmail() picked one of the matches with
 * LIMIT 1 — an arbitrary tie-break that would silently authenticate
 * whoever logs in with that email as whichever client won the tie,
 * regardless of which client's own password was actually supplied.
 */
describe('Client login email ambiguity guard (QA-5 CP-08)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const PASSWORD_A = 'Client-A-Password-1!';
  const PASSWORD_B = 'Client-B-Password-2!';

  function fakeReq() {
    const session = {
      regenerate(cb) {
        for (const key of Object.keys(session)) {
          if (key !== 'regenerate') delete session[key];
        }
        cb(null);
      },
    };
    return { ip: 'test', session };
  }

  async function createClientWithPortalLogin(email, password) {
    const clientId = await createTestClient();
    await db.execute('UPDATE clients SET email = :email WHERE id = :id', { email, id: clientId });
    const hash = await passwordHash.hash(password);
    await db.execute(
      'INSERT INTO client_logins (client_id, password_hash, force_password_change) VALUES (:cid, :hash, 0)',
      { cid: clientId, hash }
    );
    return clientId;
  }

  it('a shared email refuses login for either client\'s password', async () => {
    const sharedEmail = `shared-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    await createClientWithPortalLogin(sharedEmail, PASSWORD_A);
    await createClientWithPortalLogin(sharedEmail, PASSWORD_B);

    const resultA = await clientPortalService.attemptLogin(fakeReq(), sharedEmail, PASSWORD_A);
    const resultB = await clientPortalService.attemptLogin(fakeReq(), sharedEmail, PASSWORD_B);

    expect(resultA.status).toBe('invalid_credentials');
    expect(resultB.status).toBe('invalid_credentials');
  });

  it('once the email is made unique that client can log in normally again', async () => {
    const sharedEmail = `shared-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    await createClientWithPortalLogin(sharedEmail, PASSWORD_A);
    const clientB = await createClientWithPortalLogin(sharedEmail, PASSWORD_B);

    const distinctEmail = `distinct-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    await db.execute('UPDATE clients SET email = :email WHERE id = :id', { email: distinctEmail, id: clientB });

    const result = await clientPortalService.attemptLogin(fakeReq(), distinctEmail, PASSWORD_B);

    expect(result.status).toBe('ok');
  });
});
